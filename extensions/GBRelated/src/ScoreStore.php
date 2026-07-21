<?php

namespace MediaWiki\Extension\GBRelated;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

// wiki-quality score for game pages, backing the "Popular" listing sort.
// length + images update on save; review counts come from the public api
// via rebuildScores.php --reviews and survive save-hook refreshes.
class ScoreStore
{
    // component weights -- tune freely, then rerun rebuildScores.php
    public const LENGTH_CAP = 60000; // chars; caps at LENGTH_CAP/LENGTH_DIVISOR pts
    public const LENGTH_DIVISOR = 60;
    public const IMAGE_PTS = 300;
    public const BACKGROUND_PTS = 100;
    public const REVIEW_PTS = 100; // per review
    public const REVIEW_COUNT_CAP = 10;

    public static function handles(Title $title): bool
    {
        // top-level game pages only, not /Credits, /Images, ...
        return $title->getNamespace() === NS_MAIN &&
            preg_match('#^Games/[^/]+$#', $title->getDBkey()) === 1;
    }

    public static function lengthScore(int $len): int
    {
        return intdiv(min($len, self::LENGTH_CAP), self::LENGTH_DIVISOR);
    }

    public static function updateOnSave(Title $title): void
    {
        $pageId = $title->getArticleID();
        if (!$pageId) {
            return;
        }
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();

        $len = self::lengthScore($title->getLength());
        $image = 0;
        $smwId = self::smwId($dbr, NS_MAIN, $title->getDBkey());
        if ($smwId) {
            if (self::hasBlobValue($dbr, $smwId, "Has_image")) {
                $image += self::IMAGE_PTS;
            }
            if (self::hasBlobValue($dbr, $smwId, "Has_background_image")) {
                $image += self::BACKGROUND_PTS;
            }
        }

        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();
        $dbw->upsert(
            "gb_page_score",
            [
                "gps_page" => $pageId,
                "gps_length" => $len,
                "gps_image" => $image,
                "gps_reviews" => 0,
                "gps_score" => $len + $image,
            ],
            "gps_page",
            [
                "gps_length" => $len,
                "gps_image" => $image,
                "gps_score = gps_reviews + " . ($len + $image),
            ],
            __METHOD__,
        );
    }

    public static function setReviews(int $pageId, int $count): void
    {
        $pts = min($count, self::REVIEW_COUNT_CAP) * self::REVIEW_PTS;
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()
            ->getPrimaryDatabase();
        $dbw->newUpdateQueryBuilder()
            ->update("gb_page_score")
            ->set([
                "gps_reviews" => $pts,
                "gps_score = gps_length + gps_image + " . $pts,
            ])
            ->where(["gps_page" => $pageId])
            ->caller(__METHOD__)
            ->execute();
    }

    /**
     * top-scored pages, optionally restricted to one platform.
     * @return array{rows: array<array{title:string,display:string}>, total:int}
     */
    public static function popular(
        int $limit,
        int $offset,
        ?string $platform = null,
    ): array {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()
            ->getReplicaDatabase();

        $conds = [];
        $joins = static function ($qb) {
            return $qb;
        };
        if ($platform !== null && $platform !== "") {
            $platProp = self::smwId($dbr, 102, "Has_platforms");
            $platObj = self::smwId(
                $dbr,
                NS_MAIN,
                str_replace(" ", "_", $platform),
            );
            if (!$platProp || !$platObj) {
                return ["rows" => [], "total" => 0];
            }
            $joins = static function ($qb) use ($platProp, $platObj) {
                return $qb
                    ->join("smw_object_ids", "so", [
                        "so.smw_title = page_title",
                        "so.smw_namespace" => NS_MAIN,
                        "so.smw_iw" => "",
                        "so.smw_subobject" => "",
                    ])
                    ->join("smw_di_wikipage", "dw", [
                        "dw.s_id = so.smw_id",
                        "dw.p_id" => $platProp,
                        "dw.o_id" => $platObj,
                    ]);
            };
        }

        $total = (int) $joins(
            $dbr
                ->newSelectQueryBuilder()
                ->select("COUNT(*)")
                ->from("gb_page_score")
                ->join("page", null, "page_id = gps_page")
                ->where($conds),
        )
            ->caller(__METHOD__)
            ->fetchField();

        $res = $joins(
            $dbr
                ->newSelectQueryBuilder()
                ->select(["page_title", "pp_value"])
                ->from("gb_page_score")
                ->join("page", null, "page_id = gps_page")
                ->leftJoin("page_props", null, [
                    "pp_page = gps_page",
                    "pp_propname" => "displaytitle",
                ])
                ->where($conds),
        )
            ->orderBy(["gps_score DESC", "gps_page ASC"])
            ->limit($limit)
            ->offset($offset)
            ->caller(__METHOD__)
            ->fetchResultSet();

        $rows = [];
        foreach ($res as $row) {
            $title = str_replace("_", " ", $row->page_title);
            $display = $row->pp_value;
            if (!is_string($display) || trim($display) === "") {
                $slash = strrpos($title, "/");
                $display =
                    $slash === false ? $title : substr($title, $slash + 1);
            } else {
                $display = trim(strip_tags($display));
            }
            $rows[] = ["title" => $title, "display" => $display];
        }
        return ["rows" => $rows, "total" => $total];
    }

    private static function smwId($dbr, int $namespace, string $title): int
    {
        return (int) $dbr
            ->newSelectQueryBuilder()
            ->select("smw_id")
            ->from("smw_object_ids")
            ->where([
                "smw_namespace" => $namespace,
                "smw_title" => $title,
                "smw_iw" => "",
                "smw_subobject" => "",
            ])
            ->caller(__METHOD__)
            ->fetchField();
    }

    private static function hasBlobValue(
        $dbr,
        int $subjectId,
        string $property,
    ): bool {
        $propId = self::smwId($dbr, 102, $property);
        if (!$propId) {
            return false;
        }
        return (bool) $dbr
            ->newSelectQueryBuilder()
            ->select("1")
            ->from("smw_di_blob")
            ->where(["s_id" => $subjectId, "p_id" => $propId])
            ->limit(1)
            ->caller(__METHOD__)
            ->fetchField();
    }
}
