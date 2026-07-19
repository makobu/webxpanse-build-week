<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use PHPUnit\Framework\TestCase;

class MarketingMarketplaceGateServiceTest extends TestCase
{
    public function testFeatureForPageMapsSplitPluginFamilies(): void
    {
        $gate = new MarketingMarketplaceGateService();

        $this->assertSame(MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA, $gate->featureForPage('marketing_content.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA, $gate->featureForPage('social_media.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA, $gate->featureForPage('marketing_calendar.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_DESIGN, $gate->featureForPage('design.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_DESIGN, $gate->featureForPage('marketing_landing_pages.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_DESIGN, $gate->featureForPage('forms.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $gate->featureForPage('marketing_performance.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $gate->featureForPage('attribution_reports.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $gate->featureForPage('marketing.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $gate->featureForPage('marketing_campaign_kit.php'));
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $gate->featureForPage('marketing_assistants.php'));
    }

    public function testSocialRuntimeRequiresTheDedicatedPluginInsteadOfTheLegacyMarketingSkill(): void
    {
        $keys = (new \ReflectionClass(MarketingMarketplaceGateService::class))->getConstant('FEATURE_KEYS');

        $this->assertSame(
            [WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA],
            $keys[MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA] ?? null
        );
    }

    public function testDesignRuntimeRequiresTheDedicatedPluginInsteadOfTheLegacyMarketingSkill(): void
    {
        $keys = (new \ReflectionClass(MarketingMarketplaceGateService::class))->getConstant('FEATURE_KEYS');

        $this->assertSame(
            [WorkspaceSkillCatalogService::PLUGIN_DESIGN],
            $keys[MarketingMarketplaceGateService::FEATURE_DESIGN] ?? null
        );
    }

    public function testNavigationStateIncludesSetupTargetsForEachSplitPlugin(): void
    {
        $state = (new MarketingMarketplaceGateService())->navigationStateForUser(['id' => 0]);

        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO, $state[MarketingMarketplaceGateService::FEATURE_MARKETING_PRO]['skill_key'] ?? null);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA, $state[MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA]['skill_key'] ?? null);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_DESIGN, $state[MarketingMarketplaceGateService::FEATURE_DESIGN]['skill_key'] ?? null);
        $this->assertSame('workspace_skills.php?module=marketing_pro#setup', $state[MarketingMarketplaceGateService::FEATURE_MARKETING_PRO]['setup_url'] ?? null);
        $this->assertSame('workspace_skills.php?module=social_media#setup', $state[MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA]['setup_url'] ?? null);
        $this->assertSame('workspace_skills.php?module=design#setup', $state[MarketingMarketplaceGateService::FEATURE_DESIGN]['setup_url'] ?? null);
        $this->assertFalse((bool) ($state[MarketingMarketplaceGateService::FEATURE_MARKETING_PRO]['menu_visible'] ?? true));
    }

    public function testInstalledVisibleMenuPluginCheckIgnoresLegacyHiddenSkill(): void
    {
        $gate = $this->gateWithInstalledKeys([WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER]);

        $this->assertFalse($gate->hasInstalledVisibleMenuPlugin(0));
        $this->assertFalse($gate->hasInstalledVisibleMenuPlugin(123));
    }

    public function testInstalledVisibleMenuPluginCheckAcceptsSplitMarketingPlugins(): void
    {
        foreach ([
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            WorkspaceSkillCatalogService::PLUGIN_DESIGN,
        ] as $pluginKey) {
            $this->assertTrue(
                $this->gateWithInstalledKeys([$pluginKey])->hasInstalledVisibleMenuPlugin(123),
                $pluginKey
            );
        }
    }

    public function testFeaturePluginVisibilityIsIndependentPerTopLevelProduct(): void
    {
        $gate = $this->gateWithInstalledKeys([WorkspaceSkillCatalogService::PLUGIN_DESIGN]);

        $this->assertTrue($gate->hasInstalledFeaturePlugin(123, MarketingMarketplaceGateService::FEATURE_DESIGN));
        $this->assertFalse($gate->hasInstalledFeaturePlugin(123, MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA));
        $this->assertFalse($gate->hasInstalledFeaturePlugin(123, MarketingMarketplaceGateService::FEATURE_MARKETING_PRO));
        $this->assertTrue($gate->hasInstalledFeaturePlugin(123, MarketingMarketplaceGateService::FEATURE_OVERVIEW));
    }

    public function testMarketingProRunsDuringSetupWhileProfessionalMarketerKeepsTheAiCoachBoundary(): void
    {
        $definitions = (new WorkspaceSkillCatalogService())->definitions();
        $marketingPro = (array) ($definitions[WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO] ?? []);
        $professionalMarketer = (array) ($definitions[WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER] ?? []);

        $this->assertTrue((bool) ($marketingPro['capabilities']['runtime_during_setup'] ?? false));
        $this->assertSame([], (array) ($marketingPro['access_requirements'] ?? []));
        $this->assertSame(
            WorkspaceSkillCatalogService::SKILL_AI_COACH,
            $professionalMarketer['access_requirements'][0]['requires_skill'] ?? null
        );
    }

    /**
     * @param array<int,string> $installedKeys
     */
    private function gateWithInstalledKeys(array $installedKeys): MarketingMarketplaceGateService
    {
        $installer = new class($installedKeys) extends WorkspaceSkillInstallService {
            /** @var array<string,bool> */
            private array $installedKeys;

            /**
             * @param array<int,string> $installedKeys
             */
            public function __construct(array $installedKeys)
            {
                $this->installedKeys = array_fill_keys($installedKeys, true);
            }

            public function isInstalled(int $workspaceId, string $skillKey): bool
            {
                return $workspaceId > 0 && !empty($this->installedKeys[$skillKey]);
            }
        };

        return new MarketingMarketplaceGateService($installer);
    }
}
