<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Authorization;
use CRM\Services\AutomationCatalogService;
use CRM\Services\AutomationReviewQueueService;
use CRM\Tests\DatabaseTestCase;

class AutomationReviewQueueServiceTest extends DatabaseTestCase
{
    public function testDashboardSummarizesOpenCriticalPreparedAndSkippedRuns(): void
    {
        $catalog = new AutomationCatalogService();
        $failedMigrationPayload = [
            'run_status' => 'suggested',
            'severity' => 'critical',
            'evidence' => ['pending_count' => 1],
            'recommendation' => ['action' => 'run_database_migrations'],
            'source' => 'phpunit',
        ];
        $catalog->recordRun('failed_migration_detector', $failedMigrationPayload);
        $catalog->recordRun('failed_migration_detector', $failedMigrationPayload);
        $catalog->recordRun('daily_superadmin_health_digest', [
            'run_status' => 'prepared',
            'severity' => 'warning',
            'evidence' => ['critical_count' => 1, 'warning_count' => 0],
            'recommendation' => ['summary' => '1 critical and 0 warning detector finding(s).'],
            'source' => 'phpunit',
        ]);
        $catalog->recordRun('broken_template_detector', [
            'run_status' => 'skipped',
            'severity' => 'info',
            'review_status' => 'none',
            'evidence' => ['reason' => 'paused'],
            'source' => 'phpunit',
        ]);

        $queue = new AutomationReviewQueueService();
        $dashboard = $queue->dashboard();
        $summary = (array) ($dashboard['summary'] ?? []);

        $this->assertTrue($queue->schemaReady());
        $this->assertSame(3, (int) ($summary['total_runs'] ?? 0));
        $this->assertSame(2, (int) ($summary['open_review_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['critical_open_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['prepared_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['skipped_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['duplicate_refresh_count'] ?? 0));
        $this->assertCount(2, (array) ($dashboard['open_findings'] ?? []));
        $this->assertCount(1, (array) ($dashboard['critical_findings'] ?? []));
        $this->assertCount(1, (array) ($dashboard['prepared_digests'] ?? []));
        $this->assertCount(1, (array) ($dashboard['skipped_runs'] ?? []));
    }

    public function testReviewRunUpdatesOnlyReviewMetadataAndAuditPayload(): void
    {
        $catalog = new AutomationCatalogService();
        $runId = $catalog->recordRun('missing_production_settings_detector', [
            'run_status' => 'suggested',
            'severity' => 'warning',
            'evidence' => ['warnings' => ['app_url_missing']],
            'recommendation' => ['action' => 'set_live_environment_values'],
            'source' => 'phpunit',
        ]);

        $queue = new AutomationReviewQueueService();
        $updated = $queue->reviewRun($runId, 'resolve', 1, 'APP_URL was configured on live host.');

        $this->assertSame('resolved', (string) ($updated['review_status'] ?? ''));
        $this->assertSame('APP_URL was configured on live host.', (string) ($updated['review_note'] ?? ''));
        $this->assertSame(1, (int) ($updated['reviewed_by_user_id'] ?? 0));
        $this->assertNotEmpty($updated['reviewed_at'] ?? null);
        $this->assertSame(['warnings' => ['app_url_missing']], (array) ($updated['evidence'] ?? []));
        $this->assertSame(['action' => 'set_live_environment_values'], (array) ($updated['recommendation'] ?? []));

        $actionTaken = (array) ($updated['action_taken'] ?? []);
        $review = (array) ($actionTaken['review'] ?? []);
        $this->assertSame('resolve', (string) ($review['decision'] ?? ''));
        $this->assertSame('open', (string) ($review['previous_review_status'] ?? ''));
        $this->assertSame('resolved', (string) ($review['new_review_status'] ?? ''));
        $this->assertFalse((bool) ($review['system_mutation'] ?? true));
        $this->assertFalse((bool) ($review['customer_facing'] ?? true));

        $summary = $queue->summary();
        $this->assertSame(0, (int) ($summary['open_review_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['reviewed_count'] ?? 0));
        $this->assertSame(0, (int) ($summary['duplicate_refresh_count'] ?? -1));
    }

    public function testEscalationPolicyMarksCriticalAndStaleOpenFindings(): void
    {
        $catalog = new AutomationCatalogService();
        $criticalRunId = $catalog->recordRun('failed_migration_detector', [
            'run_status' => 'suggested',
            'severity' => 'critical',
            'evidence' => ['pending_count' => 2],
            'recommendation' => ['action' => 'run_database_migrations'],
            'source' => 'phpunit',
        ]);
        $staleRunId = $catalog->recordRun('missing_production_settings_detector', [
            'run_status' => 'suggested',
            'severity' => 'warning',
            'evidence' => ['warnings' => ['app_url_missing']],
            'recommendation' => ['action' => 'set_live_environment_values'],
            'source' => 'phpunit',
        ]);
        $freshRunId = $catalog->recordRun('broken_template_detector', [
            'run_status' => 'suggested',
            'severity' => 'warning',
            'evidence' => ['stale_email_templates' => 1],
            'recommendation' => ['action' => 'open_template_review'],
            'source' => 'phpunit',
        ]);

        Database::execute(
            "UPDATE automation_catalog_runs SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id IN (?, ?)",
            [$criticalRunId, $staleRunId]
        );

        $queue = new AutomationReviewQueueService();
        $result = $queue->applyEscalationPolicy(1);

        $this->assertSame(3, (int) ($result['evaluated'] ?? 0));
        $this->assertSame(2, (int) ($result['candidates'] ?? 0));
        $this->assertSame(2, (int) ($result['newly_escalated'] ?? 0));
        $this->assertSame(1, (int) ($result['urgent'] ?? 0));
        $this->assertSame(1, (int) ($result['high'] ?? 0));

        $critical = $queue->getRun($criticalRunId);
        $stale = $queue->getRun($staleRunId);
        $fresh = $queue->getRun($freshRunId);

        $this->assertSame('escalated', (string) ($critical['escalation_status'] ?? ''));
        $this->assertSame('urgent', (string) ($critical['escalation_priority'] ?? ''));
        $this->assertSame('critical_stale_open', (string) ($critical['escalation_reason'] ?? ''));
        $this->assertSame('escalated', (string) ($stale['escalation_status'] ?? ''));
        $this->assertSame('high', (string) ($stale['escalation_priority'] ?? ''));
        $this->assertSame('stale_open', (string) ($stale['escalation_reason'] ?? ''));
        $this->assertSame('none', (string) ($fresh['escalation_status'] ?? ''));

        $summary = $queue->summary();
        $this->assertSame(2, (int) ($summary['escalated_open_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['urgent_escalation_count'] ?? 0));

        $again = $queue->applyEscalationPolicy(1);
        $this->assertSame(0, (int) ($again['newly_escalated'] ?? -1));
        $this->assertSame(2, (int) ($again['already_escalated'] ?? 0));
    }

    public function testReviewRunAcknowledgesEscalatedFinding(): void
    {
        $catalog = new AutomationCatalogService();
        $runId = $catalog->recordRun('failed_migration_detector', [
            'run_status' => 'suggested',
            'severity' => 'critical',
            'evidence' => ['pending_count' => 1],
            'recommendation' => ['action' => 'run_database_migrations'],
            'source' => 'phpunit',
        ]);

        $queue = new AutomationReviewQueueService();
        $queue->applyEscalationPolicy(1);
        $updated = $queue->reviewRun($runId, 'resolve', 1, 'Migration issue handled.');

        $this->assertSame('resolved', (string) ($updated['review_status'] ?? ''));
        $this->assertSame('acknowledged', (string) ($updated['escalation_status'] ?? ''));
        $this->assertSame(1, (int) ($updated['escalation_acknowledged_by_user_id'] ?? 0));
        $this->assertNotEmpty($updated['escalation_acknowledged_at'] ?? null);
        $this->assertSame('Migration issue handled.', (string) ($updated['escalation_note'] ?? ''));

        $summary = $queue->summary();
        $this->assertSame(0, (int) ($summary['escalated_open_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['reviewed_count'] ?? 0));
    }

    public function testDispatchEscalationNotificationsAlertsSuperAdminsOnce(): void
    {
        $superAdminId = $this->createSuperAdminUser('automation-escalation-notify@example.test');
        $catalog = new AutomationCatalogService();
        $runId = $catalog->recordRun('failed_migration_detector', [
            'run_status' => 'suggested',
            'severity' => 'critical',
            'evidence' => ['pending_count' => 1],
            'recommendation' => ['action' => 'run_database_migrations'],
            'source' => 'phpunit',
        ]);

        Database::execute(
            "UPDATE automation_catalog_runs SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?",
            [$runId]
        );

        $queue = new AutomationReviewQueueService();
        $queue->applyEscalationPolicy($superAdminId);
        $result = $queue->dispatchEscalationNotifications($superAdminId);

        $this->assertSame(1, (int) ($result['evaluated'] ?? 0));
        $this->assertSame(1, (int) ($result['notified_runs'] ?? 0));
        $this->assertSame(1, (int) ($result['notifications_created'] ?? 0));
        $this->assertSame(1, (int) ($result['recipient_count'] ?? 0));

        $notification = Database::queryOne(
            "SELECT *
             FROM notifications
             WHERE user_id = ?
               AND type = 'automation_escalation'
               AND entity_type = 'automation_catalog_run'
               AND entity_id = ?
             LIMIT 1",
            [$superAdminId, $runId]
        );
        $this->assertNotNull($notification);
        $this->assertSame('critical', (string) ($notification['severity'] ?? ''));

        $run = $queue->getRun($runId);
        $this->assertNotEmpty($run['escalation_notified_at'] ?? null);
        $this->assertSame(1, (int) ($run['escalation_notification_count'] ?? 0));
        $this->assertSame('urgent', (string) ($run['escalation_last_notification_reason'] ?? ''));

        $again = $queue->dispatchEscalationNotifications($superAdminId);
        $this->assertSame(0, (int) ($again['notifications_created'] ?? -1));

        $count = Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE user_id = ?
               AND type = 'automation_escalation'
               AND entity_id = ?",
            [$superAdminId, $runId]
        );
        $this->assertSame(1, (int) ($count['total'] ?? 0));

        $summary = $queue->summary();
        $this->assertSame(0, (int) ($summary['escalation_notification_pending_count'] ?? -1));
        $this->assertSame(1, (int) ($summary['escalation_notification_sent_count'] ?? 0));
    }

    private function createSuperAdminUser(string $email): int
    {
        Database::execute(
            'INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, NOW())',
            [$email, password_hash('secret', PASSWORD_DEFAULT), 'superadmin']
        );
        $userId = (int) Database::lastInsertId();
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        return $userId;
    }
}
