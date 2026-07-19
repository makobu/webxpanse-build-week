<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AICoach;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Services\WorkspaceMarketplaceRecommendationControlService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceRecommendationServiceTest extends DatabaseTestCase
{
    public function testRecommendationControlTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_controls'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_controls'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'skill_key', 'control_type', 'surface', 'enabled', 'reason_code', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_controls'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['uniq_marketplace_rec_control', 'idx_marketplace_rec_controls_workspace', 'idx_marketplace_rec_controls_skill', 'idx_marketplace_rec_controls_type_surface'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testActivationBundleStateTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_state'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_state'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'bundle_key', 'status', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_state'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['uniq_marketplace_activation_bundle_state', 'idx_marketplace_activation_bundle_workspace', 'idx_marketplace_activation_bundle_key', 'idx_marketplace_activation_bundle_status'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testActivationBundleEventTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_events'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_events'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'bundle_key', 'surface', 'event_type', 'bundle_status', 'priority', 'included_skill_keys_json', 'recommended_skill_keys_json', 'progress_json', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_activation_bundle_events'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['idx_marketplace_bundle_events_workspace', 'idx_marketplace_bundle_events_user', 'idx_marketplace_bundle_events_key', 'idx_marketplace_bundle_events_surface_type', 'idx_marketplace_bundle_events_created_at', 'idx_marketplace_bundle_events_workspace_created', 'idx_marketplace_bundle_events_workspace_bundle_created'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testWhatsAppSelectedWorkspaceGetsWhatsAppAssistantRecommendationFirst(): void
    {
        $seed = $this->seedWorkspace('market-rules-whatsapp');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            3
        );

        $this->assertNotEmpty($recommendations);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $recommendations[0]['skill_key'] ?? null);
        $this->assertContains('selected_channel_whatsapp', $recommendations[0]['reason_codes'] ?? []);
        $this->assertGreaterThanOrEqual(60, (int) ($recommendations[0]['score'] ?? 0));
    }

    public function testInstalledReadySkillIsSuppressedButInstalledPluginNeedingSetupIsReturned(): void
    {
        $seed = $this->seedWorkspace('market-rules-installed');
        $this->activateSession($seed, 'owner');
        $this->completeFinanceSetup($seed);
        $this->completeCommunicationSetup($seed);

        $installer = new WorkspaceSkillInstallService();
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id']);
        $this->completeStartupJourneySetup($seed);
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, (int) $seed['user_id']);

        $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id']
        );
        $byKey = [];
        foreach ($recommendations as $recommendation) {
            $byKey[(string) ($recommendation['skill_key'] ?? '')] = $recommendation;
        }

        $this->assertArrayNotHasKey(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $byKey);
        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, $byKey);
        $this->assertTrue((bool) ($byKey[WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]['is_installed'] ?? false));
        $this->assertNotEmpty($byKey[WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]['setup_blockers'] ?? []);
    }

    public function testDismissAndSnoozeSuppressRecommendations(): void
    {
        $seed = $this->seedWorkspace('market-rules-feedback');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $service = new WorkspaceMarketplaceRecommendationService();
        $service->recordFeedback(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'snoozed',
            'test',
            date('Y-m-d H:i:s', strtotime('+7 days'))
        );

        $recommendations = $service->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        $keys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), $recommendations);
        $this->assertNotContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $keys);

        $service->recordFeedback(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
            'dismissed',
            'test'
        );
        $recommendations = $service->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        $keys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), $recommendations);
        $this->assertNotContains(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, $keys);
    }

    public function testRecommendationsAreSortedDeterministicallyByScoreThenLabel(): void
    {
        $seed = $this->seedWorkspace('market-rules-order');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'both');

        $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id']
        );
        $scores = array_map(static fn(array $item): int => (int) ($item['score'] ?? 0), $recommendations);
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function testPinnedMutedSurfaceAndSuppressedControlsShapeRecommendations(): void
    {
        $seed = $this->seedWorkspace('market-rules-controls');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'both');

        $controls = new WorkspaceMarketplaceRecommendationControlService();
        $controls->setControl((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'pinned', 'all', true, 'test');
        $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, $recommendations[0]['skill_key'] ?? null);
        $this->assertTrue((bool) ($recommendations[0]['is_pinned'] ?? false));
        $this->assertContains('admin_pinned', (array) ($recommendations[0]['reason_codes'] ?? []));

        $controls->setControl((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'muted', 'all', true, 'test');
        $keys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']));
        $this->assertNotContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $keys);

        $controls->setControl((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'surface_disabled', 'clarity_chat', true, 'test');
        $marketplaceKeys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'));
        $clarityKeys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'clarity_chat'));
        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $marketplaceKeys);
        $this->assertNotContains(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $clarityKeys);

        (new WorkspaceMarketplaceRecommendationService())->recordFeedback(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
            'dismissed',
            'test'
        );
        $keys = array_map(static fn(array $item): string => (string) ($item['skill_key'] ?? ''), (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']));
        $this->assertNotContains(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, $keys);
    }

    public function testAdaptiveSignalsBoostRecommendationScoreAndExposeFields(): void
    {
        $seed = $this->seedWorkspace('market-rules-adaptive-boost');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'setup_opened',
            ['label' => 'WhatsApp Assistant']
        );
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
            'marketplace',
            'cta_clicked',
            ['metadata' => ['label' => 'WhatsApp Assistant']]
        );

        $recommendation = $this->recommendationByKey(
            (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']),
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT
        );

        $this->assertNotNull($recommendation);
        $this->assertSame(10, (int) ($recommendation['adaptive_score_delta'] ?? 0));
        $this->assertSame((int) ($recommendation['base_score'] ?? 0) + 10, (int) ($recommendation['score'] ?? 0));
        $this->assertContains('adaptive_recent_setup_momentum', (array) ($recommendation['reason_codes'] ?? []));
        $this->assertContains('adaptive_interest_needs_setup', (array) ($recommendation['adaptive_reason_codes'] ?? []));
        $this->assertNotEmpty($recommendation['adaptive_guidance'] ?? '');
        $this->assertNotSame('none', (string) ($recommendation['adaptive_confidence'] ?? 'none'));
    }

    public function testAdaptiveSignalsDampenHighDismissalAndLowEngagement(): void
    {
        $seed = $this->seedWorkspace('market-rules-adaptive-dampen');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'both');
        $events = new WorkspaceMarketplaceRecommendationEventService();
        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'impression');
        }
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'dismissed');
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, 'marketplace', 'snoozed');

        $recommendation = $this->recommendationByKey(
            (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']),
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER
        );

        $this->assertNotNull($recommendation);
        $this->assertSame(-8, (int) ($recommendation['adaptive_score_delta'] ?? 0));
        $this->assertSame((int) ($recommendation['base_score'] ?? 0) - 8, (int) ($recommendation['score'] ?? 0));
        $this->assertContains('adaptive_high_dismissal_rate', (array) ($recommendation['adaptive_reason_codes'] ?? []));
    }

    public function testActivationBundlesGenerateProgressAndPersistState(): void
    {
        $seed = $this->seedWorkspace('market-rules-bundles');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $service = new WorkspaceMarketplaceActivationBundleService();
        $bundles = $service->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace');
        $bundle = $this->bundleByKey($bundles, 'whatsapp_led_growth');

        $this->assertNotNull($bundle);
        $this->assertSame('WhatsApp-led growth', $bundle['label'] ?? null);
        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (array) ($bundle['recommended_skill_keys'] ?? []));
        $this->assertArrayHasKey('progress', $bundle);
        $this->assertArrayHasKey('next_action', $bundle);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($bundle) ?: ''));

        $service->updateState((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'selected', [
            'source' => 'test',
            'secret_token' => 'do-not-store',
        ]);
        $selected = $this->bundleByKey(
            $service->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'),
            'whatsapp_led_growth'
        );
        $this->assertNotNull($selected);
        $this->assertSame('selected', $selected['status'] ?? null);

        $service->updateState((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'dismissed');
        $keys = array_map(static fn(array $item): string => (string) ($item['bundle_key'] ?? ''), $service->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'));
        $this->assertNotContains('whatsapp_led_growth', $keys);

        $summary = $service->summary(['workspace_id' => (int) $seed['workspace_id'], 'user_id' => (int) $seed['user_id']]);
        $this->assertSame(1, (int) ($summary['counts']['dismissed'] ?? 0));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($summary) ?: ''));
    }

    public function testActivationBundlesIncludeAdaptiveFieldsAndRespectSuppression(): void
    {
        $seed = $this->seedWorkspace('market-rules-bundles-adaptive');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'whatsapp_led_growth',
            'cta_clicked'
        );

        $service = new WorkspaceMarketplaceActivationBundleService();
        $bundle = $this->bundleByKey(
            $service->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'),
            'whatsapp_led_growth'
        );

        $this->assertNotNull($bundle);
        $this->assertArrayHasKey('base_score', $bundle);
        $this->assertArrayHasKey('adaptive_score_delta', $bundle);
        $this->assertSame(10, (int) ($bundle['adaptive_score_delta'] ?? 0));
        $this->assertSame((int) ($bundle['base_score'] ?? 0) + 10, (int) ($bundle['score'] ?? 0));
        $this->assertContains('adaptive_bundle_activation_momentum', (array) ($bundle['adaptive_reason_codes'] ?? []));
        $this->assertNotEmpty($bundle['adaptive_guidance'] ?? '');

        $service->updateState((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'completed');
        $keys = array_map(static fn(array $item): string => (string) ($item['bundle_key'] ?? ''), $service->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'));
        $this->assertNotContains('whatsapp_led_growth', $keys);
    }

    public function testActivationBundleContextShapeIsSafeForCrossSurfaceUse(): void
    {
        $seed = $this->seedWorkspace('market-rules-bundle-context');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $events = new WorkspaceMarketplaceActivationBundleEventService();
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'cta_clicked');

        $service = new WorkspaceMarketplaceActivationBundleService();
        $bundle = $this->bundleByKey(
            $service->contextBundlesForSurface((int) $seed['workspace_id'], (int) $seed['user_id'], 'coach', 3),
            'whatsapp_led_growth'
        );

        $this->assertNotNull($bundle);
        foreach (['bundle_key', 'label', 'summary', 'priority', 'status', 'progress', 'next_action', 'recommended_skill_keys', 'installed_skill_keys', 'adaptive_guidance', 'insight_label'] as $key) {
            $this->assertArrayHasKey($key, $bundle);
        }
        foreach (['score', 'base_score', 'adaptive_score_delta', 'adaptive_reason_codes', 'adaptive_confidence', 'included_skill_keys', 'blocked_skill_keys', 'setup_journeys', 'metric_snapshot', 'setup_url'] as $key) {
            $this->assertArrayNotHasKey($key, $bundle);
        }
        $this->assertArrayHasKey('url', (array) ($bundle['next_action'] ?? []));
        $this->assertStringNotContainsString('secret', strtolower(json_encode($bundle) ?: ''));

        $service->updateState((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'completed');
        $keys = array_map(
            static fn(array $item): string => (string) ($item['bundle_key'] ?? ''),
            $service->contextBundlesForSurface((int) $seed['workspace_id'], (int) $seed['user_id'], 'coach', 3)
        );
        $this->assertNotContains('whatsapp_led_growth', $keys);
    }

    public function testActivationBundleAdaptiveSignalsDampenHighDismissal(): void
    {
        $seed = $this->seedWorkspace('market-rules-bundles-dampen');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $events = new WorkspaceMarketplaceActivationBundleEventService();
        for ($i = 0; $i < 3; $i++) {
            $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'bundle_impression');
        }
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'dismissed');
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'dismissed');

        $bundle = $this->bundleByKey(
            (new WorkspaceMarketplaceActivationBundleService())->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'),
            'whatsapp_led_growth'
        );

        $this->assertNotNull($bundle);
        $this->assertSame(-8, (int) ($bundle['adaptive_score_delta'] ?? 0));
        $this->assertSame((int) ($bundle['base_score'] ?? 0) - 8, (int) ($bundle['score'] ?? 0));
        $this->assertContains('adaptive_bundle_high_dismissal_rate', (array) ($bundle['adaptive_reason_codes'] ?? []));
    }

    public function testActivationBundleEventsNormalizeMetadataAndSummaries(): void
    {
        $seed = $this->seedWorkspace('market-rules-bundle-events');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $bundleService = new WorkspaceMarketplaceActivationBundleService();
        $bundle = $this->bundleByKey(
            $bundleService->bundlesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 0, 'marketplace'),
            'whatsapp_led_growth'
        );
        $this->assertNotNull($bundle);

        $events = new WorkspaceMarketplaceActivationBundleEventService();
        $events->recordBundleImpressions((int) $seed['workspace_id'], (int) $seed['user_id'], [$bundle], [
            'source' => 'unit_test',
            'secret_token' => 'do-not-store',
        ]);
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'cta_clicked', [
            'bundle' => $bundle,
            'metadata' => [
                'source' => 'unit_test',
                'target_url' => 'workspace_skills.php?activation_bundle_key=whatsapp_led_growth',
                'secret_token' => 'do-not-store',
            ],
        ]);
        $events->recordEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'whatsapp_led_growth', 'selected', [
            'bundle' => $bundle,
            'bundle_status' => 'selected',
        ]);

        $summary = $events->getSummary(['workspace_id' => (int) $seed['workspace_id']]);
        $this->assertSame(1, (int) ($summary['counts']['bundle_impression'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['cta_clicked'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['selected'] ?? 0));
        $this->assertSame(1.0, (float) ($summary['click_through_rate'] ?? 0));
        $this->assertSame('whatsapp_led_growth', (string) ($summary['top_bundles'][0]['bundle_key'] ?? ''));

        $rows = $events->getEvents(['workspace_id' => (int) $seed['workspace_id']], 10);
        $this->assertNotEmpty($rows);
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($rows) ?: ''));
        $this->assertSame([], $events->getEvents([
            'workspace_id' => (int) $seed['workspace_id'],
            'date_from' => date('Y-m-d', strtotime('+1 day')),
        ]));
    }

    public function testClarityNudgesExposeOneSafeMarketplacePayload(): void
    {
        $seed = $this->seedWorkspace('market-rules-clarity-nudge');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $nudges = (new WorkspaceMarketplaceRecommendationService())->clarityNudgesForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            true,
            1
        );

        $this->assertCount(1, $nudges);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $nudges[0]['skill_key'] ?? null);
        $this->assertSame('WhatsApp Assistant', $nudges[0]['label'] ?? null);
        $this->assertSame('Open Marketplace', $nudges[0]['cta_label'] ?? null);
        $this->assertTrue((bool) ($nudges[0]['feedback_enabled'] ?? false));
        $this->assertArrayNotHasKey('score', $nudges[0]);
        $this->assertArrayHasKey('why_now', $nudges[0]);
        $this->assertArrayHasKey('expected_benefit', $nudges[0]);
        $this->assertArrayHasKey('setup_blockers', $nudges[0]);

        $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            1
        );
        $journeys = (new \CRM\Services\WorkspaceMarketplaceSetupJourneyService())->continuityBySkill(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            $recommendations
        );
        $recommendations[0]['setup_journey'] = $journeys[$recommendations[0]['skill_key']] ?? [];
        $nudgesWithJourney = (new WorkspaceMarketplaceRecommendationService())->clarityNudgesFromRecommendations($recommendations, true, 1);
        $this->assertSame('install_module', (string) ($nudgesWithJourney[0]['setup_journey']['next_step']['step_key'] ?? ''));
    }

    public function testClarityNudgesRespectFeedbackSuppressionAndFeedbackFlag(): void
    {
        $seed = $this->seedWorkspace('market-rules-clarity-feedback');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $service = new WorkspaceMarketplaceRecommendationService();
        $viewerNudges = $service->clarityNudgesForWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            false,
            1
        );
        $this->assertCount(1, $viewerNudges);
        $this->assertFalse((bool) ($viewerNudges[0]['feedback_enabled'] ?? true));

        $service->recordFeedback(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            (string) ($viewerNudges[0]['skill_key'] ?? ''),
            'dismissed',
            'test'
        );

        $nudges = $service->clarityNudgesForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], true, 1);
        $keys = array_map(static fn(array $nudge): string => (string) ($nudge['skill_key'] ?? ''), $nudges);
        $this->assertNotContains((string) ($viewerNudges[0]['skill_key'] ?? ''), $keys);
    }

    public function testCoachStarterRecommendationsIncludeMarketplaceRecommendations(): void
    {
        $seed = $this->seedWorkspace('market-rules-coach');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $recommendations = (new AICoach())->generateStarterTaskRecommendations((int) $seed['user_id'], '2');
        $marketplaceKeys = array_values(array_filter(array_map(
            static fn(array $item): string => (string) ($item['marketplace_skill_key'] ?? ''),
            (array) ($recommendations['missing_features'] ?? [])
        )));

        $this->assertContains(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $marketplaceKeys);
        $marketplaceItem = null;
        foreach ((array) ($recommendations['missing_features'] ?? []) as $item) {
            if (($item['marketplace_skill_key'] ?? '') === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT) {
                $marketplaceItem = $item;
                break;
            }
        }

        $this->assertIsArray($marketplaceItem);
        $this->assertSame('Open Marketplace', $marketplaceItem['marketplace_cta_label'] ?? null);
        $this->assertNotEmpty($marketplaceItem['marketplace_setup_url'] ?? '');
        $this->assertTrue((bool) ($marketplaceItem['marketplace_feedback_enabled'] ?? false));
        $this->assertSame('marketplace_module', $marketplaceItem['source_recommendation_type'] ?? null);
        $this->assertSame('install_module', (string) ($marketplaceItem['marketplace_next_setup_step']['step_key'] ?? ''));
        $this->assertArrayHasKey('marketplace_setup_progress', $marketplaceItem);
    }

    private function setCommunicationChannel(int $workspaceId, string $channel): void
    {
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = ? WHERE workspace_id = ?",
            [$channel, $workspaceId]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeFinanceSetup(array $seed): void
    {
        (new WorkspaceFinanceGateService(new WorkspaceSkillInstallService()))->saveInitialSetup((int) $seed['workspace_id'], [
            'currency' => 'USD',
            'opening_date' => date('Y-m-d'),
            'opening_cash' => '1000.00',
            'opening_receivables' => '0.00',
            'opening_payables' => '0.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '0.00',
            'opening_equity' => '1000.00',
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                    'opening_owner_draws' => '0.00',
                ],
            ],
        ], (int) $seed['user_id']);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeCommunicationSetup(array $seed): void
    {
        (new WorkspaceAssistantConfigService())->save((int) $seed['workspace_id'], 'email', [
            'system_email' => 'assistant@example.test',
            'from_email' => 'assistant@example.test',
            'from_name' => 'Workspace Assistant',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'smtp_username' => 'assistant@example.test',
            'smtp_password' => 'smtp-secret',
            'smtp_encryption' => 'tls',
        ], true, (int) $seed['user_id']);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeStartupJourneySetup(array $seed): void
    {
        $service = new StartupJourneyService();
        $journey = $service->getJourney((int) $seed['workspace_id'], (int) $seed['user_id']);
        foreach (array_keys((array) ($journey['stages'] ?? [])) as $stageKey) {
            $service->saveStage((int) $seed['workspace_id'], (int) $seed['user_id'], (string) $stageKey, [], '', true);
        }
    }

    private function recommendationByKey(array $recommendations, string $skillKey): ?array
    {
        foreach ($recommendations as $recommendation) {
            if ((string) ($recommendation['skill_key'] ?? '') === $skillKey) {
                return $recommendation;
            }
        }

        return null;
    }

    private function bundleByKey(array $bundles, string $bundleKey): ?array
    {
        foreach ($bundles as $bundle) {
            if ((string) ($bundle['bundle_key'] ?? '') === $bundleKey) {
                return $bundle;
            }
        }

        return null;
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Marketplace Rules ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Market',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $this->grantRolePermissions('owner', ['workspace.skills.view', 'workspace.skills.manage']);
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
        ];
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function grantRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 1
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$permissionKey, $roleSlug]
            );
        }
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed, string $role): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('__remember_restore_attempted', true);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', $role);
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], $role);
    }
}
