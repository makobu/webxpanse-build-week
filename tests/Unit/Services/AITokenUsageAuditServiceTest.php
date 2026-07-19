<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AITokenUsageAuditService;
use CRM\Tests\DatabaseTestCase;

class AITokenUsageAuditServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::execute('DELETE FROM workspace_ai_usage');
    }

    public function testAuditReturnsZeroTotalsWhenUsageLedgerIsEmpty(): void
    {
        $audit = (new AITokenUsageAuditService())->audit([
            'workspace_id' => $this->workspaceId(),
            'window_days' => 30,
        ]);

        $this->assertSame(0, (int) $audit['windows']['all_time']['request_count']);
        $this->assertSame(0, (int) $audit['windows']['today']['billable_tokens']);
        $this->assertSame(0, (int) $audit['usage_buckets']['setup']['billable_tokens']);
        $this->assertSame(0, (int) $audit['usage_buckets']['daily']['billable_tokens']);
        $this->assertSame([], $audit['by_feature']);
    }

    public function testAuditBucketsSetupAndDailyUsageAndSortsTopFeaturesByBillableTokens(): void
    {
        $workspaceId = $this->workspaceId();

        $this->insertUsage($workspaceId, 'onboarding_clarity_draft', 100, 50, 150, 0);
        $this->insertUsage($workspaceId, 'startup_journey_field_draft', 40, 10, 50, 0);
        $this->insertUsage($workspaceId, 'ai_coach_recommendations', 300, 100, 400, 0);
        $this->insertUsage($workspaceId, 'meeting_note_analysis', 2000, 300, 2300, 1);
        $this->insertUsage($workspaceId, 'document_extraction', 1200, 200, 1400, 1);
        $this->insertUsage($workspaceId, 'email_assistant_parse_task', 80, 20, 100, 1);

        $audit = (new AITokenUsageAuditService())->audit([
            'workspace_id' => $workspaceId,
            'window_days' => 30,
            'top_limit' => 10,
        ]);

        $this->assertSame(6, (int) $audit['windows']['custom_window']['request_count']);
        $this->assertSame(4400, (int) $audit['windows']['custom_window']['billable_tokens']);
        $this->assertSame(600, (int) $audit['usage_buckets']['setup']['billable_tokens']);
        $this->assertSame(3800, (int) $audit['usage_buckets']['daily']['billable_tokens']);
        $this->assertSame('meeting_note_analysis', (string) $audit['by_feature'][0]['feature_key']);
        $this->assertSame(2300, (int) $audit['by_feature'][0]['billable_tokens']);
        $this->assertSame('high', (string) $audit['by_feature'][0]['risk_tier']);
        $this->assertSame(100, (int) $audit['by_risk_tier']['low']['billable_tokens']);
    }

    public function testFeatureClassificationDocumentsSetupDailyAndRiskDefaults(): void
    {
        $service = new AITokenUsageAuditService();

        $setup = $service->classifyFeature('startup_journey_field_draft');
        $lowDaily = $service->classifyFeature('email_assistant_parse_task');
        $mediumDaily = $service->classifyFeature('whatsapp_draft');

        $this->assertSame('setup', $setup['usage_bucket']);
        $this->assertSame('high', $setup['risk_tier']);
        $this->assertSame('daily', $lowDaily['usage_bucket']);
        $this->assertSame('low', $lowDaily['risk_tier']);
        $this->assertSame('daily', $mediumDaily['usage_bucket']);
        $this->assertSame('medium', $mediumDaily['risk_tier']);
    }

    public function testAuditNotesDocumentUnrecordedFailureAndFallbackPaths(): void
    {
        $audit = (new AITokenUsageAuditService())->audit([
            'workspace_id' => $this->workspaceId(),
            'window_days' => 30,
        ]);

        $notes = implode(' ', (array) $audit['notes']);

        $this->assertStringContainsString('Cache hits', $notes);
        $this->assertStringContainsString('deterministic fallbacks', $notes);
        $this->assertStringContainsString('failed provider calls', $notes);
    }

    private function workspaceId(): int
    {
        $row = Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1') ?? [];

        return (int) ($row['id'] ?? 1);
    }

    private function insertUsage(
        int $workspaceId,
        string $featureKey,
        int $inputTokens,
        int $outputTokens,
        int $billableTokens,
        int $daysAgo
    ): void {
        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, NULL, 'openai', 'gpt-test', ?, ?, ?, ?, ?, 0, ?)",
            [
                $workspaceId,
                $featureKey,
                'audit-test-' . bin2hex(random_bytes(6)),
                $inputTokens,
                $outputTokens,
                $billableTokens,
                date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days')),
            ]
        );
    }
}
