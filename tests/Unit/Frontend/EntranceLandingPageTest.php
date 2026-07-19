<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class EntranceLandingPageTest extends TestCase
{
    private function projectFile(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . ltrim($path, '/'));

        $this->assertNotFalse($contents, 'Expected project file to be readable: ' . $path);

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }

    public function testEntrancePageHasSearchAndSocialMetadata(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('<meta name="description"', $page);
        $this->assertStringContainsString('<meta name="robots" content="index, follow', $page);
        $this->assertStringContainsString('<link rel="canonical"', $page);
        $this->assertStringContainsString('<meta property="og:image"', $page);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $page);
        $this->assertStringContainsString('<script type="application/ld+json">', $page);
        $this->assertStringContainsString("'@type' => 'SoftwareApplication'", $page);
        $this->assertStringContainsString("'@type' => 'VideoObject'", $page);
        $this->assertStringContainsString("'@type' => 'FAQPage'", $page);
    }

    public function testOnePersonCompanySeoExtendsRatherThanReplacesCorePositioning(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('AI-native Business Operating System', $page);
        $this->assertStringContainsString('One system that learns your business', $page);
        $this->assertStringContainsString('for founders and lean teams, including the new generation of one-person companies', $page);
        $this->assertStringContainsString('The one-person company era', $page);
        $this->assertStringContainsString('Small human core. Enterprise-level coordination.', $page);
        $this->assertStringContainsString('A one-person company (OPC) is not one person doing every job.', $page);
        $this->assertStringContainsString('sometimes called a one-man company or one-person business', $page);
        $this->assertStringContainsString('not using it as a particular legal registration label', $page);
        $this->assertStringContainsString('One-person company and founder-led lean-team operations', $page);
        $this->assertStringContainsString('Start curious. Operate clearly.', $page);
    }

    public function testEntrancePageIsACompleteProductLandingPage(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('One system that learns your business', $page);
        $this->assertStringContainsString('Clarity earns the right', $page);
        $this->assertStringContainsString('Understand how Clarity keeps', $page);
        $this->assertStringContainsString('Remember the customer.', $page);
        $this->assertStringContainsString('Start curious. Operate clearly.', $page);
        $this->assertStringContainsString('Questions curious teams', $page);
        $this->assertStringContainsString('Start with your business', $page);
        $this->assertStringContainsString('$signupUrl = $basePath . \'/public/signup.php\';', $page);
        $this->assertStringContainsString('$loginUrl = $basePath . \'/public/login.php\';', $page);
    }

    public function testExplainerVideoIsPerformanceConsciousAndHasATextAlternative(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('public/assets/videos/clarity-demo.mp4', $page);
        $this->assertStringContainsString('public/assets/videos/clarity-demo-poster-landscape.webp', $page);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_ENTRANCE', $page);
        $this->assertStringContainsString("str_starts_with(\$configuredVideoPath, 'uploads/marketplace/page_video_library/')", $page);
        $this->assertStringContainsString('video_asset_mime_type', $page);
        $this->assertStringContainsString('type="<?php echo htmlspecialchars($demoVideoMime); ?>"', $page);
        $this->assertStringContainsString('width="1280" height="720"', $page);
        $this->assertStringContainsString('playsinline preload="none"', $page);
        $this->assertStringContainsString('data-poster="<?php echo htmlspecialchars($demoPosterAssetUrl); ?>"', $page);
        $this->assertStringContainsString('data-src="<?php echo htmlspecialchars($demoVideoAssetUrl); ?>"', $page);
        $this->assertStringContainsString('prepareDemoMedia();', $page);
        $this->assertStringContainsString('demoVideo.controls = true;', $page);
        $this->assertStringContainsString("rootMargin: '600px 0px'", $page);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com/ajax/libs/gsap', $page);
        $this->assertStringContainsString('VideoBrandOverlayUi::assets()', $page);
        $this->assertStringContainsString('VideoBrandOverlayUi::logo()', $page);
        $this->assertStringContainsString('Watch the explainer', $page);
        $this->assertStringContainsString('Play explainer', $page);
        $this->assertStringContainsString('What the explainer covers', $page);
        $this->assertStringContainsString('Understand how Clarity keeps', $page);
        $this->assertStringContainsString('This short explainer introduces how Clarity brings', $page);
        $this->assertStringContainsString('rather than presenting a live product demonstration', $page);
        $this->assertStringNotContainsString('Watch the demo', $page);
        $this->assertStringNotContainsString('Play demo', $page);
        $this->assertStringNotContainsString('What the demo covers', $page);
        $this->assertStringNotContainsString('See a message become', $page);
        $this->assertStringNotContainsString('Watch Clarity connect', $page);
        $this->assertStringContainsString('id="demo-overview-copy"', $page);
        $this->assertFileExists(__DIR__ . '/../../../public/assets/videos/clarity-demo.mp4');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/videos/clarity-demo-poster-landscape.webp');
    }

    public function testEntrancePageRespectsReducedMotion(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $page);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)').matches", $page);
        $this->assertStringContainsString('IntersectionObserver', $page);
    }

    public function testInquiryFormEmbedsWithoutRedundantWorkspaceHeader(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString("'&embed=1'", $page);
        $this->assertStringContainsString('id="landing-inquiry-frame"', $page);
        $this->assertStringNotContainsString('Connected to our Forms workspace', $page);
        $this->assertStringNotContainsString('Open full form', $page);
        $this->assertStringNotContainsString('$landingInquiryFormStandaloneUrl', $page);
    }

    public function testMobileEntranceLayoutAndHeroMotionStayRestrained(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertStringContainsString('@media (max-width: 820px)', $page);
        $this->assertStringContainsString('--clarity-shell: calc(100% - 2rem)', $page);
        $this->assertStringContainsString('fieldScale = w <= 820', $page);
        $this->assertStringContainsString('Math.min(0.9, Math.max(0.22, dotSw * 0.12))', $page);
        $this->assertStringContainsString('var trailLength = 84;', $page);
        $this->assertStringNotContainsString('Math.min(2.2, Math.max(0.45, dotSw * 0.24))', $page);
    }

    public function testEntrancePageUsesLazyLoadedEditorialImagery(): void
    {
        $page = $this->projectFile('entrance.php');
        $images = [
            'founder-learning-studio.webp',
            'customer-conversation-team-multiethnic.webp',
        ];

        foreach ($images as $image) {
            $this->assertFileExists(__DIR__ . '/../../../public/assets/images/landing/' . $image);
            $this->assertStringContainsString($image, $page);
        }

        $this->assertSame(2, substr_count($page, 'class="editorial-image'));
        $this->assertSame(2, substr_count($page, 'class="editorial-image editorial-wide'));
        $this->assertStringNotContainsString('context-editorial', $page);
        $this->assertStringNotContainsString('connected-workspace-still-life.webp', $page);
    }

    public function testContextDomainsUseColorImageryAndOutcomeUsesSystemTheming(): void
    {
        $page = $this->projectFile('entrance.php');

        $this->assertSame(7, substr_count($page, 'class="domain-node domain-'));
        $this->assertSame(7, substr_count($page, '<div class="domain-node domain-'));
        $this->assertSame(7, substr_count($page, 'loading="lazy" decoding="async" alt=""><span>'));
        $this->assertStringContainsString('/public/assets/images/marketplace/professional-marketer.webp', $page);
        $this->assertStringContainsString('/public/assets/images/marketplace/whatsapp-assistant.webp', $page);
        $this->assertSame(2, substr_count($page, 'customer-example customer-example--incoming'));
        $this->assertSame(1, substr_count($page, 'customer-example customer-example--outgoing'));
        $this->assertSame(1, substr_count($page, 'customer-example customer-example--system'));
        $this->assertStringContainsString('.customer-example::after', $page);
        $this->assertStringContainsString('.customer-example--outgoing::after', $page);
        $this->assertStringContainsString('.customer-example--system::after', $page);
        $this->assertStringContainsString('aria-label="Completed follow-through status"', $page);
    }

    public function testRootServesTheCanonicalEntranceWithoutTemporaryRedirect(): void
    {
        $index = $this->projectFile('index.php');

        $this->assertStringContainsString("require __DIR__ . '/entrance.php';", $index);
        $this->assertStringNotContainsString('header(\'Location: \' . $basePath . \'/entrance.php\');', $index);
    }

    public function testCrawlerDiscoveryFilesExist(): void
    {
        $robots = $this->projectFile('robots.txt');
        $sitemap = $this->projectFile('sitemap.xml');

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Allow: /public/assets/images/clarity-landing-og.png', $robots);
        $this->assertStringContainsString('Allow: /public/assets/videos/clarity-demo-poster-landscape.webp', $robots);
        $this->assertStringContainsString('Allow: /public/assets/videos/clarity-demo.mp4', $robots);
        $this->assertStringContainsString('Sitemap: https://crm.makdennis.dev/sitemap.xml', $robots);
        $this->assertStringContainsString('<loc>https://crm.makdennis.dev/</loc>', $sitemap);
    }
}
