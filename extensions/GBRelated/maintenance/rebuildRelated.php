<?php

// backfill gb_related for existing pages (the save hook only covers edits).
// scope with --page for testing, then --all off-peak:
//   php maintenance/run.php .../rebuildRelated.php --page="Companies/Sega" --dry-run
//   php maintenance/run.php .../rebuildRelated.php --all --limit=100
//   php maintenance/run.php .../rebuildRelated.php --all

use MediaWiki\Extension\GBRelated\RelatedStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

if (!class_exists("MediaWiki\\Maintenance\\Maintenance")) {
    require_once __DIR__ . "/../../../maintenance/Maintenance.php";
}

class RebuildRelated extends Maintenance
{
    public function __construct()
    {
        parent::__construct();
        $this->addDescription(
            "Rebuild precomputed related content (gb_related)",
        );
        $this->addOption("page", "Comma-separated page titles", false, true);
        $this->addOption("all", "All company pages");
        $this->addOption("limit", "Max pages to process", false, true);
        $this->addOption("start-after", "Resume after this title", false, true);
        $this->addOption("dry-run", "Compute and print, do not write");
        $this->requireExtension("GBRelated");
    }

    public function execute()
    {
        $titles = $this->collectTitles();
        if (!$titles) {
            $this->fatalError("Nothing to do: pass --page=... or --all");
        }

        $dryRun = $this->hasOption("dry-run");
        $done = 0;
        $start = microtime(true);
        foreach ($titles as $title) {
            $t = microtime(true);
            $groups = $dryRun
                ? RelatedStore::compute($title)
                : RelatedStore::rebuild($title);
            $ms = (int) round((microtime(true) - $t) * 1000);
            $counts = [];
            foreach ($groups as $group => $items) {
                $counts[] = $group . "=" . count($items);
            }
            $this->output(
                sprintf(
                    "%s [%dms] %s\n",
                    $title->getPrefixedText(),
                    $ms,
                    implode(" ", $counts),
                ),
            );
            if ($dryRun) {
                foreach ($groups as $group => $items) {
                    $top = array_slice($items, 0, 5);
                    $names = array_map(static function ($i) {
                        return $i["title"] . "(" . $i["score"] . ")";
                    }, $top);
                    $this->output(
                        "  $group: " . implode(", ", $names) . "\n",
                    );
                }
            }
            $done++;
            if ($done % 500 === 0) {
                $this->output(
                    sprintf(
                        "-- %d pages, %.1f min elapsed\n",
                        $done,
                        (microtime(true) - $start) / 60,
                    ),
                );
                $this->waitForReplication();
            }
        }
        $this->output(
            sprintf(
                "Done: %d pages in %.1f min\n",
                $done,
                (microtime(true) - $start) / 60,
            ),
        );
    }

    /** @return Title[] */
    private function collectTitles(): array
    {
        if ($this->hasOption("page")) {
            $out = [];
            foreach (explode(",", $this->getOption("page")) as $name) {
                $title = Title::newFromText(trim($name));
                if (!$title || !$title->exists()) {
                    $this->fatalError("No such page: $name");
                }
                $out[] = $title;
            }
            return $out;
        }
        if (!$this->hasOption("all")) {
            return [];
        }
        $dbr = $this->getReplicaDB();
        $conds = [
            "page_namespace" => NS_MAIN,
            "page_is_redirect" => 0,
            $dbr->expr(
                "page_title",
                \Wikimedia\Rdbms\IExpression::LIKE,
                new \Wikimedia\Rdbms\LikeValue(
                    "Companies/",
                    $dbr->anyString(),
                ),
            ),
        ];
        if ($this->hasOption("start-after")) {
            $conds[] = $dbr->expr(
                "page_title",
                ">",
                str_replace(" ", "_", $this->getOption("start-after")),
            );
        }
        $qb = $dbr
            ->newSelectQueryBuilder()
            ->select(["page_id", "page_title", "page_namespace"])
            ->from("page")
            ->where($conds)
            ->orderBy("page_title")
            ->caller(__METHOD__);
        if ($this->hasOption("limit")) {
            $qb->limit((int) $this->getOption("limit"));
        }
        $out = [];
        foreach ($qb->fetchResultSet() as $row) {
            $out[] = Title::newFromRow($row);
        }
        return $out;
    }
}

$maintClass = RebuildRelated::class;
require_once RUN_MAINTENANCE_IF_MAIN;
