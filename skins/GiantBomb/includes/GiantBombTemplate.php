<?php
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
