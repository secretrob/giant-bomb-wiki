<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;

class GiantBombTemplate extends BaseTemplate {
    public function execute() {
        // Handle API requests first
        $request = RequestContext::getMain()->getRequest();
        $action = $request->getText('action', '');

        if ($action === 'get-releases') {
            require_once __DIR__ . '/api/releases-api.php';
            return;
        }

        if ($action === 'get-games') {
            require_once __DIR__ . '/api/games-api.php';
            return;
        }
        
        if ($action === 'get-platforms') {
            require_once __DIR__ . '/api/platforms-api.php';
            return;
        }
        
        
        if ($action === 'get-concepts') {
            require_once __DIR__ . '/api/concepts-api.php';
            return;
        }

        if ($action === 'get-people') {
            require_once __DIR__ . '/api/peoples-api.php';
            return;
        }

        $context = RequestContext::getMain();
        $action = $context->getRequest()->getVal( 'action', 'view' );

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $baseStylePath = $config->get('StyleDirectory');
        $skinName = 'GiantBomb';
        $skinPath = $baseStylePath . '/' . $skinName;        

        $title = $this->getSkin()->getTitle();
        $categories = $title->getParentCategories();

        $isNewPage = false;
        $newPage = Title::newFromText( $title->getText() );
        if ( $newPage && $newPage->exists() ) {
            $isNewPage=false;
        }
        else {
            $isNewPage=true;
        }
        
        $routeName = $title;
        foreach ( $categories as $categoryName => $sortKey ) {
            $catTitle = Title::newFromText( $categoryName );
            
            $catTitle->getText();
            
            if( strpos($title, $catTitle->getText() . '/') === 0 && substr_count($title, '/') === 1 )
                $routeName = $catTitle->getText();
        }

        if( $action && $action !== 'view' )
            $routeName = $action;
        if( $isNewPage )
            $routeName = 'edit';

        switch( $routeName ) {
            case 'Main Page':
                include $skinPath . '/includes/views/landing-page.php'; return;
            case 'Games':
                include $skinPath . '/includes/views/game-page.php'; return;
            case 'Platforms':
                include $skinPath . '/includes/views/platform-page.php'; return;
            case 'Characters':
                include $skinPath . '/includes/views/character-page.php'; return;
            case 'Concepts':
                include $skinPath . '/includes/views/concept-page.php'; return;
            case 'Companies':
                include $skinPath . '/includes/views/company-page.php'; return;
            case 'Franchises':
                include $skinPath . '/includes/views/franchise-page.php'; return;
            case 'People':
                include $skinPath . '/includes/views/person-page.php'; return;
            case 'Objects':
                include $skinPath . '/includes/views/object-page.php'; return;
            case 'Locations':
                include $skinPath . '/includes/views/location-page.php'; return;
            case 'Accessories':
                include $skinPath . '/includes/views/accessory-page.php'; return;
        }
        
        ?>
        <div id="content" class="mw-body" role="main">
            <a id="top"></a>
            <div id="siteNotice"><?php $this->html( 'sitenotice' ) ?></div>
            <h1 id="firstHeading" class="firstHeading"><?php $this->html( 'title' ) ?></h1>
            <div id="bodyContent" class="mw-body-content">
                <div id="siteSub"><?php $this->msg( 'tagline' ) ?></div>
                <div id="contentSub"><?php $this->html( 'subtitle' ) ?></div>
                <?php if ( $this->data['undelete'] ) { ?>
                    <div id="contentSub2"><?php $this->html( 'undelete' ) ?></div>
                <?php } ?>
                <?php if ( $this->data['newtalk'] ) { ?>
                    <div class="usermessage"><?php $this->html( 'newtalk' ) ?></div>
                <?php } ?>
                <div id="jump-to-nav" class="mw-jump">
                    <?php $this->msg( 'jumpto' ) ?>
                    <a href="#mw-navigation"><?php $this->msg( 'jumptonavigation' ) ?></a>,
                    <a href="#p-search"><?php $this->msg( 'jumptosearch' ) ?></a>
                </div>
                <?php $this->html( 'bodytext' ) ?>
                <?php $this->html( 'catlinks' ) ?>
                <?php $this->html( 'dataAfterContent' ) ?>
            </div>
        </div>
<?php
        
    }
}
