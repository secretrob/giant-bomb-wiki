<?php
// verify swept cover urls against the cdn: metadata size lists lie, only http
// is truth. 404s walk a candidate ladder; original always exists for live rows.
//   php verifycovers.php --titles=<file> --offset=N --limit=N [--dry-run]
use MediaWiki\MediaWikiServices;

require_once "/var/www/html/maintenance/Maintenance.php";

class VerifyCovers extends Maintenance
{
    public function __construct()
    {
        parent::__construct();
        $this->addOption("titles", "title list file", true, true);
        $this->addOption("offset", "start line", false, true);
        $this->addOption("limit", "max titles this run", false, true);
        $this->addOption("dry-run", "probe + report only", false, false);
    }

    private $curl;

    private function head(string $url): int
    {
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($this->curl);
        return (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
    }

    public function execute()
    {
        require_once "/var/www/html/import_templates/parseImageDivToTag.php";
        $titles = array_values(array_filter(array_map("trim", file($this->getOption("titles")))));
        $offset = (int) $this->getOption("offset", 0);
        $limit = (int) $this->getOption("limit", count($titles));
        $titles = array_slice($titles, $offset, $limit);
        $dryRun = $this->hasOption("dry-run");

        $services = MediaWikiServices::getInstance();
        $wpf = $services->getWikiPageFactory();
        $user = $services->getUserFactory()->newFromName("Maintenance script");
        $this->curl = curl_init();

        $rc = new ReflectionClass("UpdateTemplateImages");
        $inst = $rc->newInstanceWithoutConstructor();
        $upd = $rc->getMethod("updateTemplate");
        $upd->setAccessible(true);

        $ladder = ["scale_super", "ignore_jpg_scale_super", "screen_kubrick", "scale_large", "ignore_jpg_scale_large", "scale_medium", "ignore_jpg_scale_medium", "original"];
        $n = ["ok" => 0, "fixed" => 0, "dead" => 0, "noimage" => 0, "err" => 0];

        foreach ($titles as $t) {
            $title = \Title::newFromText($t);
            if (!$title || !$title->exists()) { $n["err"]++; continue; }
            $page = $wpf->newFromTitle($title);
            $text = $page->getContent()->getText();
            if (!preg_match('#\|\s*Image=(https://www\.giantbomb\.com/a/uploads/([a-z_0-9]+)/([0-9]+/[0-9]+/)([^\s|}]+))#', $text, $m)) {
                $n["noimage"]++;
                continue;
            }
            [, $url, $rendition, $bucket, $file] = $m;
            if ($this->head($url) === 200) { $n["ok"]++; continue; }

            $winner = null;
            foreach ($ladder as $cand) {
                if ($cand === $rendition) { continue; }
                $u = "https://www.giantbomb.com/a/uploads/$cand/$bucket$file";
                if ($this->head($u) === 200) { $winner = $u; break; }
            }
            if ($winner === null) {
                $n["dead"]++;
                $this->output("DEAD    $t ($url)\n");
                continue;
            }
            $n["fixed"]++;
            $this->output(($dryRun ? "WOULD FIX" : "FIXED") . "   $t -> $winner\n");
            if (!$dryRun) {
                // template name = first {{Word after page-type; reuse regex on any template
                preg_match('/\{\{([A-Za-z]+)/', $text, $tm);
                $new = $upd->invoke($inst, $text, $winner, $tm[1]);
                if ($new !== $text) {
                    $content = \ContentHandler::makeContent($new, $title);
                    $page->doUserEditContent($content, $user, "Point cover at a rendition that actually serves", EDIT_UPDATE | EDIT_FORCE_BOT);
                }
            }
        }
        $this->output(json_encode($n) . "\n");
    }
}

$maintClass = VerifyCovers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
