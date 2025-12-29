<?php

namespace MediaWiki\Extension\GiantBombRouter;

use CategoryPage;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\MediaWikiServices;
use MediaWiki\Context\RequestContext;

class Router implements BeforePageDisplayHook {

    public function onBeforePageDisplay( $out, $skin ): void {
        
        $context = RequestContext::getMain();
        $action = $context->getRequest()->getVal( 'action', 'view' );

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $baseStylePath = $config->get('StyleDirectory');
        $skinName = 'GiantBomb';
        $skinPath = $baseStylePath . '/' . $skinName;        

        $title = $out->getTitle();
        $categories = $title->getParentCategories();
        
        $routeName = $title;
        foreach ( $categories as $categoryName => $sortKey ) {
            $catTitle = \MediaWiki\Title\Title::newFromText( $categoryName );
            
            $catTitle->getText();
            
            if( strpos($title, $catTitle->getText() . '/') === 0 && substr_count($title, '/') === 1 )
                $routeName = $catTitle->getText();
        }

        if( $action && $action !== 'view' )
            $routeName = $action;

        error_log("ROUTE " . $routeName);

        switch( $routeName ) {
            case 'Main Page':
                include $skinPath . '/includes/views/landing-page.php'; break;
            case 'Games':
                include $skinPath . '/includes/views/game-page.php'; break;
            case 'Platforms':
                include $skinPath . '/includes/views/platform-page.php'; break;
            case 'Characters':
                include $skinPath . '/includes/views/character-page.php'; break;
            case 'Concepts':
                include $skinPath . '/includes/views/concept-page.php'; break;
            case 'Companies':
                include $skinPath . '/includes/views/company-page.php'; break;
            case 'Franchises':
                include $skinPath . '/includes/views/franchise-page.php'; break;
            case 'People':
                include $skinPath . '/includes/views/person-page.php'; break;
            case 'Objects':
                include $skinPath . '/includes/views/object-page.php'; break;
            case 'Locations':
                include $skinPath . '/includes/views/location-page.php'; break;
            case 'Accessories':
                include $skinPath . '/includes/views/accessory-page.php'; break;
            default:                
        }
    }
}
?>