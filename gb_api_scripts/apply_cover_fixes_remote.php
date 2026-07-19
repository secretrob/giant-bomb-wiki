<?php
// apply_cover_fixes_remote.php — point infobox covers at renditions that
// actually serve, over api.php. Input = tsv of "title\tverified_url" exported
// from the locally swept + HEAD-verified wiki (rendition size lists lie; only
// http is truth). Surgical param swap, and ONLY when prod's current cover is
// the same image (same bucket+file, differing in rendition/encoding) -- a
// cover a human changed on prod is skipped, never clobbered.
//
//   BOT_PASSWORD=x php apply_cover_fixes_remote.php --api https://.../wiki/api.php \
//     --user "Giantbomb@repair" --file cover-fixes.tsv [--dry-run] [--limit N] [--throttle 500]

$opts = getopt("", ["api:", "user:", "file:", "dry-run", "limit:", "throttle:"]);
$api = $opts["api"] ?? "https://www.giantbomb.com/wiki/api.php";
$botUser = $opts["user"] ?? "Giantbomb@repair";
$file = $opts["file"] ?? "/tmp/cover-fixes.tsv";
$dryRun = isset($opts["dry-run"]);
$limit = (int) ($opts["limit"] ?? 0);
$throttleMs = (int) ($opts["throttle"] ?? 500);
$botPass = getenv("BOT_PASSWORD");
if (!$botPass && !$dryRun) {
    fwrite(STDERR, "BOT_PASSWORD env is required (except --dry-run)\n");
    exit(1);
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => "", // in-handle cookie jar
    CURLOPT_USERAGENT => "gb-wiki-repair/1.0 (cover rendition fixes)",
    CURLOPT_TIMEOUT => 120,
]);
// cloudflare blocks api.php; MW_SK doubles as the waf bypass token
if (getenv("MW_SK")) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Repair-Token: " . getenv("MW_SK")]);
}

function apiCall($ch, string $api, array $params, bool $post = false): array
{
    $params["format"] = "json";
    for ($try = 0; $try < 4; $try++) {
        if ($post) {
            curl_setopt($ch, CURLOPT_URL, $api);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $api . "?" . http_build_query($params));
            curl_setopt($ch, CURLOPT_POST, false);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            fwrite(STDERR, "curl: " . curl_error($ch) . " (retry)\n");
            sleep(2 << $try);
            continue;
        }
        $data = json_decode($raw, true);
        if (isset($data["error"]["code"]) && $data["error"]["code"] === "maxlag") {
            sleep(5);
            continue;
        }
        return $data ?? [];
    }
    return [];
}

// ---- login (bot password) ----------------------------------------------------
if (!$dryRun) {
    $tok = apiCall($ch, $api, ["action" => "query", "meta" => "tokens", "type" => "login"]);
    $loginToken = $tok["query"]["tokens"]["logintoken"] ?? null;
    if (!$loginToken) {
        fwrite(STDERR, "no login token — is $api reachable?\n");
        exit(1);
    }
    $login = apiCall($ch, $api, [
        "action" => "login",
        "lgname" => $botUser,
        "lgpassword" => $botPass,
        "lgtoken" => $loginToken,
    ], true);
    if (($login["login"]["result"] ?? "") !== "Success") {
        fwrite(STDERR, "login failed: " . json_encode($login) . "\n");
        exit(1);
    }
    fwrite(STDERR, "logged in as {$botUser}\n");
}

$csrf = null;
$freshCsrf = function () use ($ch, $api, &$csrf) {
    $t = apiCall($ch, $api, ["action" => "query", "meta" => "tokens"]);
    $csrf = $t["query"]["tokens"]["csrftoken"] ?? null;
    return $csrf;
};

// image id = leading digits of the last path segment; stable across every
// url scheme era (a/uploads, api/image, media host)
function imageId(string $url): ?int
{
    $base = basename((string) parse_url(trim($url), PHP_URL_PATH));
    return preg_match('/^(\d+)-/', rawurldecode($base), $m) ? (int) $m[1] : null;
}

// the page's own imageData div names its infobox file -> the anchor identity
function anchorId(string $text): ?int
{
    if (!preg_match('/<div[^>]*id=([\'"])imageData\1[^>]*data-json=([\'"])(.*?)\2/si', $text, $m)) {
        return null;
    }
    $data = json_decode(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5), true);
    $file = $data["infobox"]["file"] ?? "";
    return preg_match('/^(\d+)-/', (string) $file, $fm) ? (int) $fm[1] : null;
}

// swap the Image value, or insert the param when the march-4 run ate it
function setCover(string $text, string $url): string
{
    if (!preg_match('/(\{\{[A-Za-z]+\b[^}]*)/s', $text, $m)) {
        return $text;
    }
    $body = $m[1];
    if (preg_match("/\|\s*Image\s*=/i", $body)) {
        $newBody = preg_replace(
            "/(\|\s*Image\s*=[ \t]*)([^|}]*?)(\s*)(?=\||\}|$)/i",
            "\${1}{$url}\${3}",
            $body,
        );
    } else {
        $newBody = rtrim($body) . "\n| Image=" . $url . "\n";
    }
    return $newBody === null ? $text : str_replace($body, $newBody, $text);
}

// ---- apply --------------------------------------------------------------------
$n = ["applied" => 0, "already_ok" => 0, "different_image" => 0, "anchor_mismatch" => 0, "missing" => 0, "errors" => 0];
$done = 0;

foreach (file($file) as $line) {
    $parts = explode("\t", trim($line));
    if (count($parts) !== 2) {
        continue;
    }
    [$title, $url] = $parts;

    $q = apiCall($ch, $api, [
        "action" => "query", "titles" => $title, "prop" => "revisions",
        "rvslots" => "main", "rvprop" => "content|timestamp", "curtimestamp" => 1,
    ]);
    $page = null;
    foreach (($q["query"]["pages"] ?? []) as $p) {
        $page = $p;
    }
    $rev = $page["revisions"][0] ?? null;
    $current = $rev["slots"]["main"]["*"] ?? ($rev["slots"]["main"]["content"] ?? null);
    if ($current === null) {
        $n["missing"]++;
        echo "MISSING  $title\n";
        continue;
    }

    // prod's own imageData is the anchor: our url must be that image, and a
    // current param pointing at some OTHER image is a human choice we keep
    $anchor = anchorId($current);
    $answer = imageId($url);
    if ($anchor === null || $answer === null || $anchor !== $answer) {
        $n["anchor_mismatch"]++;
        echo "SKIPPED  $title (imageData anchor mismatch)\n";
        continue;
    }
    $prodUrl = null;
    if (preg_match('#\|\s*Image\s*=[ \t]*([^|}\s]+)#', $current, $cm)) {
        $prodUrl = rtrim($cm[1], "}");
    }
    if ($prodUrl === $url) {
        $n["already_ok"]++;
        continue;
    }
    $currentId = $prodUrl !== null ? imageId($prodUrl) : null;
    if ($currentId !== null && $currentId !== $anchor) {
        $n["different_image"]++;
        echo "SKIPPED  $title (different image on prod)\n";
        continue;
    }

    $newText = setCover($current, $url);
    if ($newText === $current) {
        $n["already_ok"]++;
        continue;
    }

    if ($dryRun) {
        $n["applied"]++;
        echo "WOULD FIX $title -> $url\n";
    } else {
        if ($csrf === null && !$freshCsrf()) {
            fwrite(STDERR, "no csrf token\n");
            exit(1);
        }
        $edit = apiCall($ch, $api, [
            "action" => "edit", "title" => $title, "text" => $newText,
            "summary" => "Point cover at a rendition that actually serves",
            "bot" => 1, "basetimestamp" => $rev["timestamp"],
            "starttimestamp" => $q["curtimestamp"] ?? $rev["timestamp"],
            "maxlag" => 5, "assert" => "user", "token" => $csrf,
        ], true);
        $code = $edit["error"]["code"] ?? null;
        if ($code === "badtoken") {
            $freshCsrf();
            $edit = apiCall($ch, $api, [
                "action" => "edit", "title" => $title, "text" => $newText,
                "summary" => "Point cover at a rendition that actually serves",
                "bot" => 1, "basetimestamp" => $rev["timestamp"],
                "starttimestamp" => $q["curtimestamp"] ?? $rev["timestamp"],
                "maxlag" => 5, "assert" => "user", "token" => $csrf,
            ], true);
        }
        if (($edit["edit"]["result"] ?? "") === "Success") {
            $n["applied"]++;
            echo "FIXED    $title\n";
        } else {
            $n["errors"]++;
            echo "ERROR    $title: " . json_encode($edit["error"] ?? $edit) . "\n";
        }
        usleep($throttleMs * 1000);
    }

    $done++;
    if ($limit > 0 && $done >= $limit) {
        break;
    }
}

echo json_encode($n) . "\n";
