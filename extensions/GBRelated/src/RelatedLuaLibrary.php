<?php

namespace MediaWiki\Extension\GBRelated;

use Scribunto_LuaLibraryBase;

class RelatedLuaLibrary extends Scribunto_LuaLibraryBase
{
    public static function onScribuntoExternalLibraries(
        $engine,
        array &$extraLibraries
    ) {
        if ($engine === "lua") {
            $extraLibraries["mw.ext.gbrelated"] = self::class;
        }
        return true;
    }

    public function register()
    {
        return $this->getEngine()->registerInterface(
            __DIR__ . "/../gbrelated.lua",
            ["get" => [$this, "get"]],
        );
    }

    /** ranked related items for the page being parsed */
    public function get()
    {
        $title = $this->getParser()->getTitle();
        $data = $title ? RelatedStore::read($title->getArticleID()) : [];
        // 1-based arrays for lua
        $out = [];
        foreach ($data as $group => $items) {
            $list = [];
            foreach ($items as $i => $item) {
                $list[$i + 1] = $item;
            }
            $out[$group] = $list;
        }
        return [$out];
    }
}
