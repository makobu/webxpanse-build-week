<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class InboxPageGuideVideoTest extends TestCase
{
    public function testInboxPageGuideButtonIsRenderedBesideInboxComposeActions(): void
    {
        $inbox = file_get_contents(__DIR__ . '/../../../public/inbox.php');

        $this->assertNotFalse($inbox);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_INBOX', (string) $inbox);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $inbox);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $inbox);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_INBOX', (string) $inbox);

        $headerActionsPosition = strpos((string) $inbox, '<div class="page-header-actions inbox-header-actions"');
        $guidePosition = strpos((string) $inbox, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_INBOX");
        $whatsAppPosition = strpos((string) $inbox, 'href="whatsapp_compose.php"');
        $filterActionsPosition = strpos((string) $inbox, '<div class="filter-actions">');

        $this->assertIsInt($headerActionsPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($whatsAppPosition);
        $this->assertGreaterThan($headerActionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $whatsAppPosition);
        $this->assertLessThan($filterActionsPosition, $guidePosition);
    }

    public function testGuidedDemoReplyGetsVisibleInboxCueAndSpotlightTarget(): void
    {
        $inbox = file_get_contents(__DIR__ . '/../../../public/inbox.php');
        $guidedDemo = file_get_contents(__DIR__ . '/../../../public/assets/js/guided-demo.js');

        $this->assertNotFalse($inbox);
        $this->assertNotFalse($guidedDemo);

        $this->assertStringContainsString("\$activeGuidedDemoStepKey === 'simulate_reply_received'", (string) $inbox);
        $this->assertStringContainsString('data-guided-demo-inbox-card="1"', (string) $inbox);
        $this->assertStringContainsString('$hasGuidedDemoReplyCommunication ? \'\' : \' data-guided-demo-target="inbox-founder-thread"\'', (string) $inbox);
        $this->assertStringContainsString('inbox-row-guided-demo-reply', (string) $inbox);
        $this->assertStringContainsString('inbox-demo-reply-cue', (string) $inbox);
        $this->assertStringContainsString('New buyer reply', (string) $inbox);
        $this->assertStringContainsString('syncGuidedDemoInboxTarget();', (string) $inbox);

        $refreshPosition = strpos((string) $guidedDemo, 'window.refreshInboxListAsync');
        $retargetPosition = strpos((string) $guidedDemo, 'target = findTarget();', (int) $refreshPosition);

        $this->assertIsInt($refreshPosition);
        $this->assertIsInt($retargetPosition);
        $this->assertGreaterThan($refreshPosition, $retargetPosition);
    }
}
