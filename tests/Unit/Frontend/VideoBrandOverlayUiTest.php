<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Services\PageGuideVideoUi;
use CRM\Services\VideoBrandOverlayUi;
use PHPUnit\Framework\TestCase;

class VideoBrandOverlayUiTest extends TestCase
{
    public function testPageGuideVideoModalRendersWebxpanseOverlay(): void
    {
        $modal = PageGuideVideoUi::modal('customer-care', 'How to use Customer Care', 'assets/videos/customer-care.mp4');

        $this->assertStringContainsString('page-guide-video-frame', $modal);
        $this->assertStringContainsString('video-brand-overlay-frame', $modal);
        $this->assertStringContainsString('video-brand-overlay-logo', $modal);
        $this->assertStringContainsString('webxpanse-video-watermark.png', $modal);
        $this->assertStringContainsString('data-page-guide-video', $modal);
    }

    public function testOverlayAssetsKeepControlsClickableAndLogoShadowed(): void
    {
        $assets = VideoBrandOverlayUi::assets();

        $this->assertFileExists($this->repoPath('public/assets/images/webxpanse-video-watermark.png'));
        $this->assertStringContainsString('.video-brand-overlay-frame', $assets);
        $this->assertStringContainsString('.video-brand-overlay-logo', $assets);
        $this->assertStringContainsString('bottom: clamp(0.12rem, 0.45vw, 0.36rem)', $assets);
        $this->assertStringContainsString('width: clamp(5.15rem, 7vw, 7.75rem)', $assets);
        $this->assertStringContainsString('background: linear-gradient(180deg', $assets);
        $this->assertStringContainsString('#020617 100%', $assets);
        $this->assertStringContainsString('box-shadow:', $assets);
        $this->assertStringContainsString('pointer-events: none', $assets);
    }

    public function testMarketplaceExplainerAndSetupVideosUseOverlay(): void
    {
        $marketplace = $this->source('public/workspace_skills.php');
        $marketplaceCss = $this->source('public/assets/css/marketplace.css');

        $this->assertStringContainsString('use CRM\Services\VideoBrandOverlayUi;', $marketplace);
        $this->assertGreaterThanOrEqual(4, substr_count($marketplace, 'VideoBrandOverlayUi::frame('));
        $this->assertStringContainsString('data-marketplace-setup-video', $marketplace);
        $this->assertStringContainsString('appendWatermarkedMedia', $marketplace);
        $this->assertStringContainsString('VideoBrandOverlayUi::logoUrl()', $marketplace);
        $this->assertStringContainsString('data-marketplace-card-video-frame', $marketplace);
        $this->assertStringContainsString('.marketplace-detail-media .video-brand-overlay-frame', $marketplaceCss);
        $this->assertStringContainsString('.marketplace-overview-media .video-brand-overlay-frame', $marketplaceCss);
        $this->assertStringContainsString('.marketplace-card-video-frame .video-brand-overlay-frame', $marketplaceCss);
    }

    public function testCustomGuideModalsUseOverlay(): void
    {
        $dashboard = $this->source('public/dashboard.php');
        $journey = $this->source('public/startup_journey.php');
        $founderLoop = $this->source('public/founder_operating_loop.php');

        $this->assertStringContainsString('VideoBrandOverlayUi::assets()', $dashboard);
        $this->assertGreaterThanOrEqual(3, substr_count($dashboard, 'VideoBrandOverlayUi::frame('));
        $this->assertStringContainsString('data-dashboard-video', $dashboard);
        $this->assertStringContainsString('data-ai-coach-brief-video', $dashboard);
        $this->assertStringContainsString('data-ai-coach-brief-video-iframe', $dashboard);

        $this->assertStringContainsString('VideoBrandOverlayUi::assets()', $journey);
        $this->assertStringContainsString('VideoBrandOverlayUi::frame(', $journey);
        $this->assertStringContainsString('data-startup-journey-video', $journey);

        $this->assertStringContainsString('VideoBrandOverlayUi::assets()', $founderLoop);
        $this->assertStringContainsString('VideoBrandOverlayUi::frame(', $founderLoop);
        $this->assertStringContainsString('data-founder-loop-video', $founderLoop);
    }

    public function testNonTargetMediaSurfacesAreNotWiredToWatermarkOverlay(): void
    {
        $conversation = $this->source('public/conversation.php');
        $marketingContent = $this->source('public/marketing_content_view.php');

        $this->assertStringNotContainsString('VideoBrandOverlayUi', $conversation);
        $this->assertStringNotContainsString('video-brand-overlay-frame', $conversation);
        $this->assertStringNotContainsString('VideoBrandOverlayUi', $marketingContent);
        $this->assertStringNotContainsString('video-brand-overlay-frame', $marketingContent);
    }

    private function source(string $relativePath): string
    {
        $contents = file_get_contents($this->repoPath($relativePath));

        $this->assertNotFalse($contents);

        return (string) $contents;
    }

    private function repoPath(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . ltrim($relativePath, '/');
    }
}
