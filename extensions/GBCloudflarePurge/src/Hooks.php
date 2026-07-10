<?php

namespace MediaWiki\Extension\GBCloudflarePurge;

use MediaWiki\MainConfigNames;

// cdn ttl hooks -- purging itself lives in CloudflareEventRelayer, wired to
// core's cdn-url-purges channel via $wgEventRelayerConfig in LocalSettings
class Hooks
{
    // fulltext search is 1-10s on the small cloud sql instance -> let the
    // edge absorb anon search traffic. logged-in requests stay uncached via
    // the session check in sendCacheControl() + the cookie bypass cache rule.
    public static function onSpecialPageBeforeExecute($special, $subPage)
    {
        if ($special->getName() !== "Search") {
            return;
        }
        $special->getOutput()->setCdnMaxage(1800);
    }

    // file-cache hits return before ActionEntryPoint calls setCdnMaxage(),
    // leaving mCdnMaxage at 0 -> sendCacheControl() emits `private` and the
    // edge never caches the pages that matter. set the ttl here instead.
    public static function onHTMLFileCacheUseFileCache($context)
    {
        $context
            ->getOutput()
            ->setCdnMaxage(
                $context->getConfig()->get(MainConfigNames::CdnMaxAge)
            );
        return true;
    }
}
