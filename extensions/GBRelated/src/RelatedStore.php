<?php

namespace MediaWiki\Extension\GBRelated;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

// ranked related content for company pages, from the wiki link graph.
// games (smw developed/published relations) rank by inbound links from
// other games/franchises -- sequel and series references, immune to the
// character-page fan-out that inflates global counts. everything else
// ranks by affinity: how many of the company's games link to it.
class RelatedStore
{
    private const LINK_GROUPS = [
        "franchises" => "Franchises/",
        "people" => "People/",
        "characters" => "Characters/",
        "concepts" => "Concepts/",
        "locations" => "Locations/",
        "objects" => "Objects/",
    ];

    private const SMW_GROUPS = [
        "developed" => "Has_developers",
        "published" => "Has_publishers",
    ];

    private const GROUP_CAPS = ["developed" => 100, "published" => 100];
    private const DEFAULT_CAP = 20;
    private const CANDIDATE_CAP = 5000;
    private const CHUNK = 500;

    public static function handles(Title $title): bool
    {
        return $title->getNamespace() === NS_MAIN &&
            str_starts_with($title->getDBkey(), "Companies/");
    }

    /**
     * @return array<string, array<array{title:string,pageId:int,score:int}>>
     */
    public static function compute(Title $title): array
    {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()
            ->getReplicaDatabase();

        // [group => ["all" => [title => pageId], "exclusive" => ...]]
        $games = [];
        foreach (self::SMW_GROUPS as $group => $property) {
            $games[$group] = self::smwSubjects(
                $dbr,
                $property,
                $title->getDBkey(),
            );
        }

        // affinity uses every game (co-developed titles still vouch for
        // their franchises/people); exclusivity only gates the games lists
        $gameIds = [];
        foreach ($games as $sets) {
            foreach ($sets["all"] as $id) {
                $gameIds[$id] = true;
            }
        }
        $gameIds = array_keys($gameIds);

        // affinity: distinct company games linking each prefixed target
        $affinity = self::gameOutboundCounts($dbr, $gameIds);

        $out = [];
        foreach (self::LINK_GROUPS as $group => $prefix) {
            $rows = [];
            foreach ($affinity[$group] ?? [] as $t => $score) {
                $rows[] = ["title" => $t, "pageId" => 0, "score" => $score];
            }
            usort($rows, static function ($a, $b) {
                return $b["score"] <=> $a["score"] ?:
                    strcmp($a["title"], $b["title"]);
            });
            $rows = array_slice($rows, 0, self::DEFAULT_CAP);
            self::fillPageIds($dbr, $rows);
            $out[$group] = $rows;
        }

        // games: notability from game/franchise inbound links
        $gameScores = self::gameInboundCounts(
            $dbr,
            array_merge(
                ...array_map(static function ($sets) {
                    return array_keys($sets["exclusive"]);
                }, array_values($games)),
            ),
        );
        foreach ($games as $group => $sets) {
            $rows = [];
            foreach ($sets["exclusive"] as $t => $pageId) {
                $rows[] = [
                    "title" => $t,
                    "pageId" => $pageId,
                    "score" => $gameScores[$t] ?? 0,
                ];
            }
            usort($rows, static function ($a, $b) {
                return $b["score"] <=> $a["score"] ?:
                    $a["pageId"] <=> $b["pageId"];
            });
            $cap = self::GROUP_CAPS[$group] ?? self::DEFAULT_CAP;
            $out[$group] = array_slice($rows, 0, $cap);
        }
        return $out;
    }

    public static function rebuild(Title $title): array
    {
        $groups = self::compute($title);
        self::write($title->getArticleID(), $groups);
        return $groups;
    }

    public static function write(int $pageId, array $groups): void
    {
        if (!$pageId) {
            return;
        }
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()
            ->getPrimaryDatabase();
        $rows = [];
        foreach ($groups as $group => $items) {
            $rank = 0;
            foreach ($items as $item) {
                $rows[] = [
                    "rel_page" => $pageId,
                    "rel_group" => $group,
                    "rel_rank" => ++$rank,
                    "rel_target" => $item["title"],
                    "rel_target_id" => $item["pageId"],
                ];
            }
        }
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom("gb_related")
            ->where(["rel_page" => $pageId])
            ->caller(__METHOD__)
            ->execute();
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $dbw->newInsertQueryBuilder()
                ->insertInto("gb_related")
                ->rows($chunk)
                ->caller(__METHOD__)
                ->execute();
        }
    }

    /**
     * @return array<string, array<array{title:string,display:string}>>
     */
    public static function read(int $pageId): array
    {
        if (!$pageId) {
            return [];
        }
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()
            ->getReplicaDatabase();
        $res = $dbr
            ->newSelectQueryBuilder()
            ->select(["rel_group", "rel_target", "pp_value"])
            ->from("gb_related")
            ->leftJoin("page_props", null, [
                "pp_page = rel_target_id",
                "pp_propname" => "displaytitle",
            ])
            ->where(["rel_page" => $pageId])
            ->orderBy(["rel_group", "rel_rank"])
            ->caller(__METHOD__)
            ->fetchResultSet();
        $out = [];
        foreach ($res as $row) {
            $title = str_replace("_", " ", $row->rel_target);
            $display = $row->pp_value;
            if (!is_string($display) || trim($display) === "") {
                $slash = strrpos($title, "/");
                $display =
                    $slash === false ? $title : substr($title, $slash + 1);
            } else {
                $display = trim(strip_tags($display));
            }
            $out[$row->rel_group][] = [
                "title" => $title,
                "display" => $display,
            ];
        }
        return $out;
    }

    /**
     * smw subjects of [[property::page]]:
     * ["all" => [title => pageId], "exclusive" => same, sole-value only]
     */
    private static function smwSubjects(
        $dbr,
        string $property,
        string $dbkey,
    ): array {
        $propId = $dbr
            ->newSelectQueryBuilder()
            ->select("smw_id")
            ->from("smw_object_ids")
            ->where([
                "smw_namespace" => 102,
                "smw_title" => $property,
                "smw_iw" => "",
                "smw_subobject" => "",
            ])
            ->caller(__METHOD__)
            ->fetchField();
        $objId = $dbr
            ->newSelectQueryBuilder()
            ->select("smw_id")
            ->from("smw_object_ids")
            ->where([
                "smw_namespace" => NS_MAIN,
                "smw_title" => $dbkey,
                "smw_iw" => "",
                "smw_subobject" => "",
            ])
            ->caller(__METHOD__)
            ->fetchField();
        if (!$propId || !$objId) {
            return ["all" => [], "exclusive" => []];
        }
        $res = $dbr
            ->newSelectQueryBuilder()
            ->select(["smw_title", "s_id"])
            ->from("smw_di_wikipage")
            ->join("smw_object_ids", null, "smw_id = s_id")
            ->where([
                "p_id" => (int) $propId,
                "o_id" => (int) $objId,
                "smw_namespace" => NS_MAIN,
                "smw_iw" => "",
                "smw_subobject" => "",
            ])
            ->limit(self::CANDIDATE_CAP)
            ->caller(__METHOD__)
            ->fetchResultSet();
        $bySubject = [];
        foreach ($res as $row) {
            if (str_starts_with($row->smw_title, "Games/")) {
                $bySubject[(int) $row->s_id] = $row->smw_title;
            }
        }

        // only games where this company is the sole developer/publisher
        $exclusiveTitles = [];
        foreach (array_chunk(array_keys($bySubject), self::CHUNK) as $chunk) {
            $counts = $dbr
                ->newSelectQueryBuilder()
                ->select(["s_id", "cnt" => "COUNT(*)"])
                ->from("smw_di_wikipage")
                ->where(["p_id" => (int) $propId, "s_id" => $chunk])
                ->groupBy("s_id")
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($counts as $c) {
                if ((int) $c->cnt === 1) {
                    $exclusiveTitles[$bySubject[(int) $c->s_id]] = true;
                }
            }
        }

        $all = [];
        foreach (array_chunk(array_values($bySubject), self::CHUNK) as $chunk) {
            $pages = $dbr
                ->newSelectQueryBuilder()
                ->select(["page_id", "page_title"])
                ->from("page")
                ->where([
                    "page_namespace" => NS_MAIN,
                    "page_title" => $chunk,
                ])
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($pages as $p) {
                $all[$p->page_title] = (int) $p->page_id;
            }
        }
        $exclusive = array_intersect_key($all, $exclusiveTitles);
        return ["all" => $all, "exclusive" => $exclusive];
    }

    /**
     * distinct-game link counts to prefixed targets, bucketed by group:
     * [group => [title => count]]
     */
    private static function gameOutboundCounts($dbr, array $gameIds): array
    {
        $out = [];
        if (!$gameIds) {
            return $out;
        }
        $prefixConds = [];
        foreach (self::LINK_GROUPS as $prefix) {
            $prefixConds[] = $dbr
                ->expr(
                    "lt_title",
                    \Wikimedia\Rdbms\IExpression::LIKE,
                    new \Wikimedia\Rdbms\LikeValue($prefix, $dbr->anyString()),
                )
                ->toSql($dbr);
        }
        foreach (array_chunk($gameIds, self::CHUNK) as $chunk) {
            $res = $dbr
                ->newSelectQueryBuilder()
                ->select(["lt_title", "cnt" => "COUNT(DISTINCT pl_from)"])
                ->from("pagelinks")
                ->join("linktarget", null, "lt_id = pl_target_id")
                ->where([
                    "pl_from" => $chunk,
                    "lt_namespace" => NS_MAIN,
                    $dbr->makeList($prefixConds, $dbr::LIST_OR),
                ])
                ->groupBy("lt_title")
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($res as $row) {
                foreach (self::LINK_GROUPS as $group => $prefix) {
                    if (str_starts_with($row->lt_title, $prefix)) {
                        $out[$group][$row->lt_title] =
                            ($out[$group][$row->lt_title] ?? 0) +
                            (int) $row->cnt;
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /** inbound counts to games, from game/franchise pages only */
    private static function gameInboundCounts($dbr, array $titles): array
    {
        $scores = [];
        foreach (array_chunk($titles, self::CHUNK) as $chunk) {
            $res = $dbr
                ->newSelectQueryBuilder()
                ->select(["lt_title", "cnt" => "COUNT(*)"])
                ->from("linktarget")
                ->join("pagelinks", null, "pl_target_id = lt_id")
                ->join("page", null, "page_id = pl_from")
                ->where([
                    "lt_namespace" => NS_MAIN,
                    "lt_title" => $chunk,
                    "page_namespace" => NS_MAIN,
                    $dbr->makeList(
                        [
                            $dbr
                                ->expr(
                                    "page_title",
                                    \Wikimedia\Rdbms\IExpression::LIKE,
                                    new \Wikimedia\Rdbms\LikeValue(
                                        "Games/",
                                        $dbr->anyString(),
                                    ),
                                )
                                ->toSql($dbr),
                            $dbr
                                ->expr(
                                    "page_title",
                                    \Wikimedia\Rdbms\IExpression::LIKE,
                                    new \Wikimedia\Rdbms\LikeValue(
                                        "Franchises/",
                                        $dbr->anyString(),
                                    ),
                                )
                                ->toSql($dbr),
                        ],
                        $dbr::LIST_OR,
                    ),
                ])
                ->groupBy("lt_title")
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($res as $row) {
                $scores[$row->lt_title] = (int) $row->cnt;
            }
        }
        return $scores;
    }

    /** page ids for ranked rows (tiebreak already applied; ids feed display lookups) */
    private static function fillPageIds($dbr, array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $titles = array_column($rows, "title");
        $ids = [];
        $res = $dbr
            ->newSelectQueryBuilder()
            ->select(["page_id", "page_title"])
            ->from("page")
            ->where(["page_namespace" => NS_MAIN, "page_title" => $titles])
            ->caller(__METHOD__)
            ->fetchResultSet();
        foreach ($res as $p) {
            $ids[$p->page_title] = (int) $p->page_id;
        }
        foreach ($rows as &$row) {
            $row["pageId"] = $ids[$row["title"]] ?? 0;
        }
    }
}
