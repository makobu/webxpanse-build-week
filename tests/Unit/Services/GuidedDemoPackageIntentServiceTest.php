<?php

declare(strict_types=1);

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\GuidedDemoPackageIntentService;
use CRM\Tests\DatabaseTestCase;

class GuidedDemoPackageIntentServiceTest extends DatabaseTestCase
{
    public function testLaunchCatalogReturnsFiveCanonicalPackages(): void
    {
        $packages = $this->packagesByCode();

        $this->assertSame([
            GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE,
            GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH,
            GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS,
            GuidedDemoPackageIntentService::PACKAGE_GROWTH_STUDIO,
            GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM,
        ], array_keys($packages));

        $this->assertSame('KES 0', $packages[GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE]['price_short']);
        $this->assertSame('KES 1,990/mo or KES 19,900/yr', $packages[GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH]['price_short']);
        $this->assertSame('KES 4,990/mo or KES 49,900/yr', $packages[GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS]['price_short']);
        $this->assertSame('KES 11,900/mo or KES 119,000/yr', $packages[GuidedDemoPackageIntentService::PACKAGE_GROWTH_STUDIO]['price_short']);
        $this->assertSame('From KES 39,000', $packages[GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM]['price_short']);
    }

    public function testPaidPackagesExposeMonthlyAndAnnualCheckoutOptions(): void
    {
        $packages = $this->packagesByCode();
        $solo = $packages[GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH];
        $options = (array) ($solo['checkout_options'] ?? []);

        $this->assertTrue((bool) ($solo['checkout_available'] ?? false));
        $this->assertCount(2, $options);
        $this->assertSame('monthly', (string) ($options[0]['cadence'] ?? ''));
        $this->assertSame('annual', (string) ($options[1]['cadence'] ?? ''));
        $this->assertSame(1990.0, (float) ($options[0]['amount'] ?? 0));
        $this->assertSame(19900.0, (float) ($options[1]['amount'] ?? 0));
    }

    public function testGuidedPackagesAreGroupedOncePerCanonicalPackage(): void
    {
        $packages = (new GuidedDemoPackageIntentService())->packages(1);
        $codes = array_map(static fn(array $package): string => (string) ($package['code'] ?? ''), $packages);

        $this->assertCount(5, $packages);
        $this->assertSame(array_values(array_unique($codes)), $codes);
        $this->assertContains(GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM, $codes);

        foreach ($packages as $package) {
            if (!empty($package['is_custom'])) {
                $this->assertSame([], (array) ($package['checkout_options'] ?? []));
                continue;
            }

            $this->assertNotEmpty($package['checkout_options'] ?? [], (string) ($package['code'] ?? 'package') . ' should expose checkout options.');
        }
    }

    public function testCompassFreeAndScaleCustomDoNotStartCheckout(): void
    {
        $packages = $this->packagesByCode();

        $this->assertFalse((bool) ($packages[GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE]['checkout_available'] ?? true));
        $this->assertTrue((bool) ($packages[GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE]['is_free'] ?? false));
        $this->assertFalse((bool) ($packages[GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM]['checkout_available'] ?? true));
        $this->assertTrue((bool) ($packages[GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM]['is_custom'] ?? false));
    }

    public function testLaunchPackagesExposeFeatureDifferentiationMetadata(): void
    {
        $packages = $this->packagesByCode();

        foreach ($packages as $package) {
            $this->assertNotEmpty($package['feature_highlights'] ?? [], (string) ($package['code'] ?? 'package') . ' needs highlights.');
            $this->assertNotEmpty($package['feature_groups'] ?? [], (string) ($package['code'] ?? 'package') . ' needs feature groups.');
            $this->assertNotEmpty($package['best_for'] ?? '', (string) ($package['code'] ?? 'package') . ' needs best fit copy.');
            $this->assertNotEmpty($package['upgrade_reason'] ?? '', (string) ($package['code'] ?? 'package') . ' needs upgrade copy.');
        }

        $this->assertNull($packages[GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE]['inherits_from']);
        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_COMPASS_FREE, $packages[GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH]['inherits_from']);
        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH, $packages[GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS]['inherits_from']);
        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS, $packages[GuidedDemoPackageIntentService::PACKAGE_GROWTH_STUDIO]['inherits_from']);
        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_GROWTH_STUDIO, $packages[GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM]['inherits_from']);
        $this->assertStringContainsString('Everything in Solo Launch', (string) ($packages[GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS]['tier_intro'] ?? ''));
    }

    public function testRecommendationMovesCompletedDemoToFounderPlus(): void
    {
        $recommendation = (new GuidedDemoPackageIntentService())->recommendationForSession([
            'status' => 'completed',
            'cleanup_status' => 'succeeded',
        ], ['add_demo_contact', 'preview_outreach_message']);

        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_FOUNDER_PLUS, $recommendation['package_code']);
        $this->assertStringContainsString('Founder Plus', (string) $recommendation['label']);
    }

    public function testRecommendationDefaultsToSoloLaunchAfterEarlyExit(): void
    {
        $recommendation = (new GuidedDemoPackageIntentService())->recommendationForSession([
            'status' => 'exited',
            'cleanup_status' => 'succeeded',
        ], []);

        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH, $recommendation['package_code']);
        $this->assertStringContainsString('Solo Launch', (string) $recommendation['label']);
    }

    public function testRecordIntentAcceptsLaunchPackageCodes(): void
    {
        $intentId = (new GuidedDemoPackageIntentService())->recordIntent(
            1,
            1,
            null,
            GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM,
            'requested',
            'email',
            'Need a partner channel plan',
            ['source' => 'test']
        );

        $row = Database::queryOne("SELECT package_code, status FROM guided_demo_package_intents WHERE id = ?", [$intentId]);

        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM, (string) ($row['package_code'] ?? ''));
        $this->assertSame('requested', (string) ($row['status'] ?? ''));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function packagesByCode(): array
    {
        $packages = [];
        foreach ((new GuidedDemoPackageIntentService())->packages(1) as $package) {
            $packages[(string) $package['code']] = $package;
        }

        return $packages;
    }
}
