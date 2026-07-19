<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceRecommendationEventServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['marketplace-events@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testEventTableIncludesExpectedColumnsAndIndexes(): void
    {
        $table = Database::queryOne(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_events'"
        );
        $this->assertSame(1, (int) ($table['c'] ?? 0));

        $columns = Database::query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_events'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['workspace_id', 'user_id', 'skill_key', 'surface', 'event_type', 'recommendation_score', 'priority', 'reason_codes_json', 'metadata_json', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $indexes = Database::query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_marketplace_recommendation_events'"
        );
        $indexNames = array_unique(array_column($indexes, 'INDEX_NAME'));
        foreach (['idx_marketplace_rec_events_workspace', 'idx_marketplace_rec_events_user', 'idx_marketplace_rec_events_skill', 'idx_marketplace_rec_events_surface_type', 'idx_marketplace_rec_events_created_at', 'idx_marketplace_rec_events_workspace_created', 'idx_marketplace_rec_events_workspace_skill_created'] as $index) {
            $this->assertContains($index, $indexNames);
        }
    }

    public function testRecordsAndAggregatesRecommendationEventsDeterministically(): void
    {
        $service = new WorkspaceMarketplaceRecommendationEventService();

        $service->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'impression', [
            'recommendation_score' => 92,
            'priority' => 'high',
            'reason_codes' => ['selected_channel_whatsapp'],
            'metadata' => ['label' => 'WhatsApp Assistant', 'source' => 'unit'],
        ]);
        $service->recordEvent(1, $this->userId, 'whatsapp_assistant', 'marketplace', 'cta_clicked');
        $service->recordEvent(1, $this->userId, 'email_assistant', 'clarity_chat', 'impression');
        $service->recordEvent(1, $this->userId, 'email_assistant', 'coach', 'task_created', [
            'metadata' => ['task_id' => 123],
        ]);

        $summary = $service->getSummary(['workspace_id' => 1]);

        $this->assertSame(2, (int) $summary['counts']['impression']);
        $this->assertSame(1, (int) $summary['counts']['cta_clicked']);
        $this->assertSame(1, (int) $summary['counts']['task_created']);
        $this->assertSame(0.5, (float) $summary['click_through_rate']);
        $this->assertSame('email_assistant', (string) ($summary['top_skills'][0]['skill_key'] ?? ''));
        $this->assertSame('whatsapp_assistant', (string) ($summary['top_skills'][1]['skill_key'] ?? ''));

        $rows = $service->getEvents(['workspace_id' => 1, 'surface' => 'marketplace', 'event_type' => 'cta_clicked']);
        $this->assertCount(1, $rows);
        $this->assertSame('whatsapp_assistant', (string) ($rows[0]['skill_key'] ?? ''));
    }

    public function testDateAndSkillFilteringLimitSummaryScope(): void
    {
        $service = new WorkspaceMarketplaceRecommendationEventService();
        $service->recordEvent(1, $this->userId, 'lean_canvas', 'marketplace', 'impression');
        $service->recordEvent(1, $this->userId, 'professional_marketer', 'marketplace', 'impression');

        Database::execute(
            "UPDATE workspace_marketplace_recommendation_events
             SET created_at = DATE_SUB(NOW(), INTERVAL 10 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 10 DAY)
             WHERE skill_key = 'professional_marketer'"
        );

        $summary = $service->getSummary([
            'workspace_id' => 1,
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) $summary['counts']['impression']);
        $this->assertSame('lean_canvas', (string) ($summary['top_skills'][0]['skill_key'] ?? ''));

        $skillSummary = $service->getSummary(['workspace_id' => 1, 'skill_key' => 'professional_marketer']);
        $this->assertSame(1, (int) $skillSummary['counts']['impression']);
    }
}
