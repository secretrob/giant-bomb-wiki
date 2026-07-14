<?php

namespace MediaWiki\Extension\GBRelated;

use MediaWiki\Deferred\DeferredUpdates;

class Hooks
{
    // recompute on save so related lists track edits; postsend keeps the
    // editor's request fast
    public static function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ) {
        $title = $wikiPage->getTitle();
        if (!$title || !RelatedStore::handles($title)) {
            return;
        }
        DeferredUpdates::addCallableUpdate(static function () use ($title) {
            RelatedStore::rebuild($title);
            // saved parse ran before the rebuild -> next view picks it up
            $title->invalidateCache();
        }, DeferredUpdates::POSTSEND);
    }

    public static function onLoadExtensionSchemaUpdates($updater)
    {
        $updater->addExtensionTable(
            "gb_related",
            __DIR__ . "/../sql/tables.sql",
        );
    }
}
