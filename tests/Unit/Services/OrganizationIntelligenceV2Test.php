<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Services\ContactStageHistoryService;
use CRM\Services\HRAnalyticsAiService;
use CRM\Services\HRAnalyticsService;
use CRM\Services\OrganizationIntelligenceConversationService;
use CRM\Services\OrganizationIntelligenceSnapshotService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class OrganizationIntelligenceV2Test extends DatabaseTestCase
{
    public function testNoEvidencePeopleRemainUnscored(): void
    {
        $userId = $this->createWorkspaceUser('oi-no-evidence@example.test', 'viewer');
        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))
            ->buildDashboard(['timeframe' => 'month'], false);
        $employee = array_values(array_filter(
            $dashboard['employees'],
            static fn(array $row): bool => (int) ($row['id'] ?? 0) === $userId
        ))[0] ?? [];

        $this->assertNull($employee['score'] ?? null);
        $this->assertSame('insufficient_evidence', $employee['score_status'] ?? '');
        $this->assertSame([], $employee['evidence_families'] ?? []);
        $this->assertArrayHasKey('structural_readiness', $dashboard);
        $this->assertSame(2, $dashboard['schema_version']);
    }

    public function testStageDistributionMatchesCurrentRowsAndBaselinesNeverQualify(): void
    {
        $userId = $this->createWorkspaceUser('oi-stage-owner@example.test', 'sales');
        $contactIds = [];
        foreach (['new', 'qualified', 'qualified', 'won', 'lost'] as $index => $stage) {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_at, updated_at)
                 VALUES (1, ?, 'Stage', ?, ?, ?, ?, NOW(), NOW())",
                [uniqid('oi-stage-', true), (string) $index, "stage{$index}@example.test", $stage, $userId]
            );
            $contactIds[] = (int) Database::lastInsertId();
        }
        foreach ($contactIds as $contactId) {
            $stage = (string) (Database::queryOne('SELECT stage FROM contacts WHERE id = ?', [$contactId])['stage'] ?? 'new');
            Database::execute(
                "INSERT INTO contact_stage_transitions
                    (workspace_id, contact_id, from_stage, to_stage, source, is_baseline, occurred_at)
                 VALUES (1, ?, NULL, ?, 'test_baseline', 1, NOW())",
                [$contactId, $stage]
            );
        }

        $history = new ContactStageHistoryService();
        $distribution = $history->distribution(1, '2000-01-01', '2000-01-02', $userId);
        $directCount = (int) (Database::queryOne('SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = 1 AND assigned_to = ?', [$userId])['c'] ?? 0);

        $this->assertSame($directCount, $distribution['total_count']);
        $this->assertSame(2, $distribution['stages']['qualified']['count']);
        $this->assertSame('point_in_time', $distribution['measurement_type']);
        $this->assertNull($distribution['stages']['qualified']['conversion_rate']);
        $this->assertSame(0, $history->journey(1, '2000-01-01', date('Y-m-d 23:59:59'))['sample_size']);
    }

    public function testDurableTransitionRecordingDeduplicatesOneMutationPath(): void
    {
        $userId = $this->createWorkspaceUser('oi-stage-dedupe@example.test', 'sales');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_at, updated_at)
             VALUES (1, ?, 'Dedupe', 'Stage', ?, 'qualified', ?, NOW(), NOW())",
            [uniqid('oi-dedupe-', true), 'oi-stage-dedupe-contact@example.test', $userId]
        );
        $contactId = (int) Database::lastInsertId();
        $service = new ContactStageHistoryService();
        $first = $service->record(1, $contactId, 'contacted', 'qualified', $userId, 'test_path');
        $second = $service->record(1, $contactId, 'contacted', 'qualified', $userId, 'test_path');

        $this->assertSame($first, $second);
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM contact_stage_transitions WHERE workspace_id = 1 AND contact_id = ? AND is_baseline = 0',
            [$contactId]
        )['c'] ?? 0));
    }

    public function testDailySnapshotCaptureIsIdempotent(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1, null, 'system');
        $service = new OrganizationIntelligenceSnapshotService();
        $first = $service->capture(1, null, 'test');
        $second = $service->capture(1, null, 'test_rerun');

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM organization_intelligence_snapshots WHERE workspace_id = 1 AND snapshot_date = ?',
            [date('Y-m-d')]
        )['c'] ?? 0));
    }

    public function testConversationsArePrivatePerWorkspaceUserAndRetainSubRoom(): void
    {
        $firstUser = $this->createWorkspaceUser('oi-chat-one@example.test', 'viewer');
        $secondUser = $this->createWorkspaceUser('oi-chat-two@example.test', 'viewer');
        $service = new OrganizationIntelligenceConversationService();
        $first = $service->currentOrCreate(1, $firstUser);
        $second = $service->currentOrCreate(1, $secondUser);
        $service->append((int) $first['id'], 1, $firstUser, 'user', 'Review diagnostics', 'priorities', 'diagnostics', ['timeframe' => 'month']);

        $this->assertNotSame((int) $first['id'], (int) $second['id']);
        $history = $service->history((int) $first['id'], 1, $firstUser);
        $this->assertSame('diagnostics', $history[0]['sub_room']);
        $this->assertSame([], $service->history((int) $first['id'], 1, $secondUser));
    }

    public function testConversationHistoryAndSummaryExcludeMetadataOnlyAssistantMessages(): void
    {
        $userId = $this->createWorkspaceUser('oi-chat-normalization@example.test', 'viewer');
        $service = new OrganizationIntelligenceConversationService();
        $conversation = $service->currentOrCreate(1, $userId);
        $conversationId = (int) $conversation['id'];

        $service->append($conversationId, 1, $userId, 'user', 'Why is the risk high?', 'brief', '', []);
        $service->append($conversationId, 1, $userId, 'assistant', '{"type":"text"}', 'brief', '', []);
        $service->append($conversationId, 1, $userId, 'assistant', '{"answer":"Evidence is still forming."}', 'brief', '', []);

        $history = $service->history($conversationId, 1, $userId);
        $summary = (string) (Database::queryOne(
            'SELECT rolling_summary FROM organization_intelligence_conversations WHERE id = ?',
            [$conversationId]
        )['rolling_summary'] ?? '');

        $this->assertSame(['Why is the risk high?', 'Evidence is still forming.'], array_column($history, 'text'));
        $this->assertStringNotContainsString('{"type":"text"}', $summary);
        $this->assertStringContainsString('Evidence is still forming.', $summary);
    }

    public function testHundredMemberDashboardAvoidsColdPathRegression(): void
    {
        $departmentId = (int) (Database::queryOne("SELECT id FROM departments WHERE workspace_id = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        for ($index = 0; $index < 100; $index++) {
            Database::execute(
                "INSERT INTO users (uuid, email, password_hash, role, department_id, first_name, last_name, created_at)
                 VALUES (?, ?, ?, 'viewer', ?, 'Scale', ?, NOW())",
                [uniqid('oi-scale-', true), "oi-scale-{$index}@example.test", $hash, $departmentId ?: null, (string) $index]
            );
            $userId = (int) Database::lastInsertId();
            Database::execute(
                "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status, joined_at)
                 VALUES (1, ?, 'viewer', ?, 'active', NOW())",
                [$userId, $departmentId ?: null]
            );
        }

        $startedAt = microtime(true);
        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))
            ->buildDashboard(['timeframe' => 'month'], false, [
                'swot' => false,
                'manager_tips' => false,
                'strategic_pointers' => false,
            ]);
        $duration = microtime(true) - $startedAt;

        $this->assertGreaterThanOrEqual(100, count($dashboard['employees']));
        $this->assertLessThan(2.5, $duration, 'The 100-member cold local dashboard should remain under 2.5 seconds.');
    }

    private function createWorkspaceUser(string $email, string $role): int
    {
        $departmentId = (int) (Database::queryOne("SELECT id FROM departments WHERE workspace_id = 1 ORDER BY id LIMIT 1")['id'] ?? 0);
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, department_id, first_name, last_name, created_at)
             VALUES (?, ?, ?, ?, ?, 'OI', 'Tester', NOW())",
            [uniqid('oi-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $role, $departmentId ?: null]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status, is_owner, joined_at)
             VALUES (1, ?, ?, ?, 'active', ?, NOW())",
            [$userId, $role, $departmentId ?: null, $role === 'owner' ? 1 : 0]
        );
        return $userId;
    }
}
