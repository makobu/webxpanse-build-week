<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceSetupJourneyEventServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('setup-journey-events-', true), 'setup-journey-events@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('setup-journey-events-other-', true), 'setup-journey-events-other@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();
    }

    public function testEventTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_events'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_events'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'skill_key', 'step_key', 'label', 'surface', 'event_type', 'step_status', 'source', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_setup_journey_events'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['idx_marketplace_setup_events_workspace', 'idx_marketplace_setup_events_user', 'idx_marketplace_setup_events_skill', 'idx_marketplace_setup_events_step', 'idx_marketplace_setup_events_type', 'idx_marketplace_setup_events_created_at', 'idx_marketplace_setup_events_workspace_created', 'idx_marketplace_setup_events_workspace_skill_created'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testRecordsAndAggregatesSetupJourneyEvents(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyEventService();
        $stepLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $stepKey = 'setup_' . substr(hash('sha1', $stepLabel), 0, 16);

        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'journey_impression', [
            'label' => 'WhatsApp Assistant',
            'metadata' => ['label' => 'WhatsApp Assistant', 'secret_token' => 'do-not-store'],
        ]);
        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'setup_opened', [
            'label' => 'WhatsApp Assistant',
            'metadata' => ['target_url' => 'settings.php?tab=whatsapp_assistant'],
        ]);
        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'step_completed', [
            'step_key' => $stepKey,
            'label' => $stepLabel,
            'step_status' => 'completed',
        ]);
        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'step_skipped', [
            'step_key' => $stepKey,
            'label' => $stepLabel,
            'step_status' => 'skipped',
        ]);
        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'step_reset', [
            'step_key' => 'setup_email',
            'label' => 'Connect Assistant Gmail or configure assistant SMTP/IMAP.',
            'step_status' => 'pending',
        ]);

        $summary = $service->getSummary(['workspace_id' => 1]);

        $this->assertSame(1, (int) $summary['counts']['journey_impression']);
        $this->assertSame(1, (int) $summary['counts']['setup_opened']);
        $this->assertSame(1, (int) $summary['counts']['step_completed']);
        $this->assertSame(1.0, (float) $summary['setup_open_rate']);
        $this->assertSame(0.3333, (float) $summary['manual_step_completion_rate']);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, (string) ($summary['top_skills'][0]['skill_key'] ?? ''));
        $this->assertSame($stepKey, (string) ($summary['top_steps'][0]['step_key'] ?? ''));

        $rows = $service->getEvents(['workspace_id' => 1, 'event_type' => 'journey_impression']);
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('do-not-store', (string) ($rows[0]['metadata_json'] ?? ''));
    }

    public function testDateAndUserFilteringLimitSetupJourneyEvents(): void
    {
        $service = new WorkspaceMarketplaceSetupJourneyEventService();
        $service->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'journey_impression', [
            'label' => 'Lean Canvas',
        ]);
        $service->recordEvent(1, $this->otherUserId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'install_completed', [
            'label' => 'Email Assistant',
        ]);

        Database::execute(
            "UPDATE workspace_marketplace_setup_journey_events
             SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 10 DAY)
             WHERE skill_key = ?",
            [WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );

        $recent = $service->getSummary([
            'workspace_id' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) $recent['counts']['install_completed']);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, (string) ($recent['top_skills'][0]['skill_key'] ?? ''));

        $ownUser = $service->getSummary([
            'workspace_id' => 1,
            'user_id' => $this->userId,
        ]);
        $this->assertSame(1, (int) $ownUser['counts']['journey_impression']);
        $this->assertSame(0, (int) $ownUser['counts']['install_completed']);
    }
}
