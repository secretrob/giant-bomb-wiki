<?php
/**
 * Backfill smw_fpt_mdat from revision timestamps -- the $smwgFixedProperties
 * misconfig stopped _MDAT writes (2026-05..07), breaking every mod-date sort.
 * Run once after the config fix; safe to re-run. Supports --dry-run.
 */

require_once dirname(__DIR__, 4) . "/maintenance/Maintenance.php";

class FixSmwModificationDates extends Maintenance
{
    public function __construct()
    {
        parent::__construct();
        $this->addDescription(
            "Backfill SMW modification dates from revision timestamps",
        );
        $this->addOption("dry-run", "Count affected rows, change nothing");
    }

    public function execute()
    {
        $dbw = $this->getPrimaryDB();
        // mw timestamps are utc; keep the conversion functions honest
        $dbw->query("SET SESSION time_zone = '+00:00'", __METHOD__);

        // smw serializes time values as 1/Y/M/D/H/MM/SS/0 with a julian-day
        // sortkey -- mirror that from the latest revision timestamp

        if ($this->hasOption("dry-run")) {
            $missing = $dbw->query(
                "SELECT COUNT(*) AS c FROM smw_object_ids o
                 JOIN page p ON p.page_namespace = o.smw_namespace
                     AND p.page_title = o.smw_title
                 LEFT JOIN smw_fpt_mdat m ON m.s_id = o.smw_id
                 WHERE o.smw_iw = '' AND o.smw_subobject = ''
                     AND m.s_id IS NULL",
                __METHOD__,
            )->fetchObject()->c;
            $stale = $dbw->query(
                "SELECT COUNT(*) AS c FROM smw_object_ids o
                 JOIN page p ON p.page_namespace = o.smw_namespace
                     AND p.page_title = o.smw_title
                 JOIN revision r ON r.rev_id = p.page_latest
                 JOIN smw_fpt_mdat m ON m.s_id = o.smw_id
                 WHERE o.smw_iw = '' AND o.smw_subobject = ''
                     AND m.o_sortkey <
                        (UNIX_TIMESTAMP(CONVERT(r.rev_timestamp USING latin1))
                            / 86400) + 2440587.5 - 0.0001",
                __METHOD__,
            )->fetchObject()->c;
            $this->output("Would insert $missing, update $stale rows\n");
            return;
        }

        $start = microtime(true);
        $dbw->query(
            "INSERT INTO smw_fpt_mdat (s_id, o_serialized, o_sortkey)
             SELECT o.smw_id,
                 CONCAT('1/', YEAR(ts), '/', MONTH(ts), '/', DAY(ts), '/',
                     HOUR(ts), '/', MINUTE(ts), '/', SECOND(ts), '/0'),
                 (UNIX_TIMESTAMP(ts) / 86400) + 2440587.5
             FROM (
                 SELECT o.smw_id,
                     CONVERT(r.rev_timestamp USING latin1) AS ts
                 FROM smw_object_ids o
                 JOIN page p ON p.page_namespace = o.smw_namespace
                     AND p.page_title = o.smw_title
                 JOIN revision r ON r.rev_id = p.page_latest
                 LEFT JOIN smw_fpt_mdat m ON m.s_id = o.smw_id
                 WHERE o.smw_iw = '' AND o.smw_subobject = ''
                     AND m.s_id IS NULL
             ) AS o",
            __METHOD__,
        );
        $inserted = $dbw->affectedRows();

        $dbw->query(
            "UPDATE smw_fpt_mdat m
             JOIN smw_object_ids o ON o.smw_id = m.s_id
             JOIN page p ON p.page_namespace = o.smw_namespace
                 AND p.page_title = o.smw_title
             JOIN revision r ON r.rev_id = p.page_latest
             SET m.o_serialized = CONCAT('1/',
                     YEAR(CONVERT(r.rev_timestamp USING latin1)), '/',
                     MONTH(CONVERT(r.rev_timestamp USING latin1)), '/',
                     DAY(CONVERT(r.rev_timestamp USING latin1)), '/',
                     HOUR(CONVERT(r.rev_timestamp USING latin1)), '/',
                     MINUTE(CONVERT(r.rev_timestamp USING latin1)), '/',
                     SECOND(CONVERT(r.rev_timestamp USING latin1)), '/0'),
                 m.o_sortkey =
                     (UNIX_TIMESTAMP(CONVERT(r.rev_timestamp USING latin1))
                         / 86400) + 2440587.5
             WHERE o.smw_iw = '' AND o.smw_subobject = ''
                 AND m.o_sortkey <
                     (UNIX_TIMESTAMP(CONVERT(r.rev_timestamp USING latin1))
                         / 86400) + 2440587.5 - 0.0001",
            __METHOD__,
        );
        $updated = $dbw->affectedRows();

        $this->output(
            sprintf(
                "Done: %d inserted, %d updated in %.1fs\n",
                $inserted,
                $updated,
                microtime(true) - $start,
            ),
        );
    }
}

$maintClass = FixSmwModificationDates::class;
require_once RUN_MAINTENANCE_IF_MAIN;
