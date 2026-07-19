<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceSetupJourneyService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceSetupJourneyServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('setup-journey-', true), 'setup-journey@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testSetupJourneyTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_steps'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_steps'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'skill_key', 'step_key', 'label', 'status', 'source', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_steps'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['uniq_marketplace_setup_step', 'idx_marketplace_setup_workspace', 'idx_marketplace_setup_user', 'idx_marketplace_setup_skill', 'idx_marketplace_setup_status'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testUninstalledRecommendationStartsWithInstallThenCatalogSteps(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyService();
        $journeys = $service->journeysBySkill(1, $this->userId, [
            $this->recommendation(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, false),
        ], [], []);

        $this->assertArrayHasKey(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, $journeys);
        $steps = (array) ($journeys[WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]['steps'] ?? []);

        $this->assertSame('install_module', (string) ($steps[0]['step_key'] ?? ''));
        $this->assertSame('Install WhatsApp Assistant', (string) ($steps[0]['label'] ?? ''));
        $this->assertFalse((bool) ($steps[0]['manual'] ?? true));
        $this->assertContains('Connect WhatsApp and configure the assistant phone number settings.', array_column($steps, 'label'));
        $this->assertContains('Confirm WhatsApp Business number', array_column($steps, 'label'));
        $this->assertSame(count($steps), (int) ($journeys[WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]['progress']['total'] ?? 0));
    }

    public function testInstalledIncompletePluginStartsWithReadinessBlocker(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyService();
        $journeys = $service->journeysBySkill(1, $this->userId, [
            $this->recommendation(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, true),
        ], [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => ['key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT],
        ], [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => [
                'ready' => false,
                'status' => 'needs_setup',
                'message' => 'Email Assistant needs SMTP identity settings before it can send safely.',
            ],
        ]);

        $steps = (array) ($journeys[WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT]['steps'] ?? []);
        $this->assertSame('readiness_blocker', (string) ($steps[0]['step_key'] ?? ''));
        $this->assertSame('Email Assistant needs SMTP identity settings before it can send safely.', (string) ($steps[0]['label'] ?? ''));
        $this->assertFalse((bool) ($steps[0]['manual'] ?? true));
        $this->assertNotContains('Install Email Assistant', array_column($steps, 'label'));
    }

    public function testPersistedStepStatusMergesAndCanReset(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyService();
        $skillKey = WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT;
        $stepLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $stepKey = 'setup_' . substr(hash('sha1', $stepLabel), 0, 16);

        $service->updateStepStatus(1, $this->userId, $skillKey, $stepKey, 'completed', $stepLabel, 'catalog');
        $journeys = $service->journeysBySkill(1, $this->userId, [$this->recommendation($skillKey, false)], [], []);
        $steps = $this->stepsByKey((array) ($journeys[$skillKey]['steps'] ?? []));
        $this->assertSame('completed', (string) ($steps[$stepKey]['status'] ?? ''));

        $service->updateStepStatus(1, $this->userId, $skillKey, $stepKey, 'skipped', $stepLabel, 'catalog');
        $journeys = $service->journeysBySkill(1, $this->userId, [$this->recommendation($skillKey, false)], [], []);
        $steps = $this->stepsByKey((array) ($journeys[$skillKey]['steps'] ?? []));
        $this->assertSame('skipped', (string) ($steps[$stepKey]['status'] ?? ''));

        $service->updateStepStatus(1, $this->userId, $skillKey, $stepKey, 'pending', $stepLabel, 'catalog');
        $journeys = $service->journeysBySkill(1, $this->userId, [$this->recommendation($skillKey, false)], [], []);
        $steps = $this->stepsByKey((array) ($journeys[$skillKey]['steps'] ?? []));
        $this->assertSame('pending', (string) ($steps[$stepKey]['status'] ?? ''));
    }

    public function testContinuityPayloadIsSafeCompactAndUsesNextPendingStep(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyService();
        $skillKey = WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT;
        $firstManualLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $firstManualKey = 'setup_' . substr(hash('sha1', $firstManualLabel), 0, 16);

        $service->updateStepStatus(1, $this->userId, $skillKey, $firstManualKey, 'completed', $firstManualLabel, 'catalog', [
            'secret' => 'not exposed',
        ]);

        $continuity = $service->continuityBySkill(1, $this->userId, [$this->recommendation($skillKey, false)]);
        $payload = (array) ($continuity[$skillKey] ?? []);

        $this->assertSame($skillKey, $payload['skill_key'] ?? null);
        $this->assertArrayHasKey('progress', $payload);
        $this->assertArrayHasKey('next_step', $payload);
        $this->assertCount(3, (array) ($payload['steps_preview'] ?? []));
        $this->assertSame('install_module', (string) ($payload['next_step']['step_key'] ?? ''));
        $this->assertArrayNotHasKey('metadata', (array) (($payload['steps_preview'] ?? [])[0] ?? []));
        $this->assertStringNotContainsString('secret', strtolower(json_encode($payload) ?: ''));
    }

    public function testUnknownSkillStepUpdateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new WorkspaceMarketplaceSetupJourneyService())->updateStepStatus(
            1,
            $this->userId,
            'missing_skill',
            'setup_missing',
            'completed',
            'Missing step',
            'catalog'
        );
    }

    private function recommendation(string $skillKey, bool $installed): array
    {
        $module = (new WorkspaceSkillCatalogService())->find($skillKey) ?? [];

        return [
            'skill_key' => $skillKey,
            'label' => (string) ($module['label'] ?? $skillKey),
            'setup_url' => (string) ($module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php'),
            'is_installed' => $installed,
            'readiness' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<string,array<string,mixed>>
     */
    private function stepsByKey(array $steps): array
    {
        $byKey = [];
        foreach ($steps as $step) {
            $byKey[(string) ($step['step_key'] ?? '')] = $step;
        }

        return $byKey;
    }
}
