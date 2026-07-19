<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Reports;
use CRM\Modules\ScheduledReports;
use CRM\Services\PlatformRuntimeOperationsService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class PlatformRuntimeOperationsServiceTest extends DatabaseTestCase
{
    public function testWorkspaceHealthDataIsWorkspaceBounded(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Runtime Health One',
            'workspace_slug' => 'runtime-health-one',
            'first_name' => 'Health',
            'last_name' => 'Owner',
            'email' => 'runtime.health.one@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceOneId = (int) ($provisioned['workspace_id'] ?? 0);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Runtime Health Two', 'runtime-health-two', 'active', 'inactive', NOW(), NOW())",
            [uniqid('workspace-two-', true)]
        );
        $workspaceTwoId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO email_fetch_log (workspace_id, status, error_message, source, created_at, updated_at)
             VALUES (?, 'failed', 'Workspace one fetch failed', 'worker', NOW(), NOW())",
            [$workspaceOneId]
        );
        Database::execute(
            "INSERT INTO email_fetch_log (workspace_id, status, error_message, source, created_at, updated_at)
             VALUES (?, 'failed', 'Workspace two fetch failed', 'worker', NOW(), NOW())",
            [$workspaceTwoId]
        );

        $service = new PlatformRuntimeOperationsService();
        $data = $service->getWorkspaceHealthData($workspaceOneId);
        $emailFetch = (array) (($data['subsystems']['email_fetch'] ?? []));
        $failures = (array) ($emailFetch['recent_failures'] ?? []);

        $this->assertSame(1, (int) ($emailFetch['failed_count'] ?? 0));
        $this->assertCount(1, $failures);
        $this->assertSame('Workspace one fetch failed', (string) ($failures[0]['failure_reason'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['open_failure_count'] ?? 0));
    }

    public function testReplayFailedEmailQueueUsesStoredWorkspaceAndWritesAudit(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Runtime Email Replay',
            'workspace_slug' => 'runtime-email-replay',
            'first_name' => 'Replay',
            'last_name' => 'Owner',
            'email' => 'runtime.email.replay@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        Session::set('user_id', $actorUserId);

        Database::execute(
            "UPDATE demo_mode_state
             SET is_enabled = 1, simulation_only = 1, updated_by = ?
             WHERE id = 1",
            [$actorUserId]
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, created_at, updated_at)
             VALUES (?, ?, 'Email Replay Contact', ?, 'import', 'new', NOW(), NOW())",
            [$workspaceId, uniqid('contact-', true), 'email.replay.contact@example.com']
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO emails
             (workspace_id, uuid, contact_id, user_id, to_email, from_email, from_name, subject, body, body_html, status, error_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', 'Initial send failed', NOW(), NOW())",
            [
                $workspaceId,
                uniqid('email-', true),
                $contactId,
                $actorUserId,
                'customer@example.com',
                'noreply@example.com',
                'CRM',
                'Replay Subject',
                'Replay body',
                '<p>Replay body</p>',
            ]
        );
        $emailId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO email_queue (workspace_id, email_id, status, error_message, attempts, max_attempts, created_at)
             VALUES (?, ?, 'failed', 'Initial queue failure', 3, 3, NOW())",
            [$workspaceId, $emailId]
        );
        $queueId = (int) Database::lastInsertId();

        WorkspaceContext::activateRuntimeWorkspace(1);
        $service = new PlatformRuntimeOperationsService();
        $result = $service->replayFailure($workspaceId, 'email_queue', $queueId, $actorUserId, 'Replay after support inspection');

        $queueRow = Database::queryOne(
            "SELECT status, error_message
             FROM email_queue
             WHERE id = ?
               AND workspace_id = ?",
            [$queueId, $workspaceId]
        );
        $emailRow = Database::queryOne(
            "SELECT status
             FROM emails
             WHERE id = ?
               AND workspace_id = ?",
            [$emailId, $workspaceId]
        );
        $audit = Database::queryOne(
            "SELECT action_type, reason
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'runtime_failure_replay'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('completed', (string) ($queueRow['status'] ?? ''));
        $this->assertSame('sent', (string) ($emailRow['status'] ?? ''));
        $this->assertSame('runtime_failure_replay', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Replay after support inspection', (string) ($audit['reason'] ?? ''));
    }

    public function testReplayFailedScheduledReportRunUsesStoredWorkspaceAndWritesAudit(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Runtime Report Replay',
            'workspace_slug' => 'runtime-report-replay',
            'first_name' => 'Report',
            'last_name' => 'Owner',
            'email' => 'runtime.report.replay@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        Session::set('user_id', $actorUserId);

        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
        $reports = new Reports();
        $scheduledReports = new ScheduledReports();

        $reportId = $reports->create([
            'name' => 'Runtime Replay Report',
            'description' => 'Runtime replay report',
            'report_type' => 'contacts',
            'query_config' => json_encode(['fields' => ['first_name', 'email']]),
            'created_by' => $actorUserId,
        ]);

        $scheduleId = $scheduledReports->create([
            'report_id' => $reportId,
            'schedule_name' => 'Runtime Replay Schedule',
            'schedule_type' => 'daily',
            'schedule_config' => ['time' => '09:00'],
            'recipients' => [],
            'format' => 'csv',
            'created_by' => $actorUserId,
        ]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, created_at, updated_at)
             VALUES (?, ?, 'Report Contact', ?, 'import', 'new', NOW(), NOW())",
            [$workspaceId, uniqid('report-contact-', true), 'report.contact@example.com']
        );

        Database::execute(
            "INSERT INTO scheduled_report_runs
             (workspace_id, scheduled_report_id, executed_at, status, result_count, error_message, execution_time)
             VALUES (?, ?, NOW(), 'failed', 0, 'Initial report failure', 0.21)",
            [$workspaceId, $scheduleId]
        );
        $runId = (int) Database::lastInsertId();

        WorkspaceContext::activateRuntimeWorkspace(1);
        $service = new PlatformRuntimeOperationsService();
        $result = $service->replayFailure($workspaceId, 'scheduled_report_run', $runId, $actorUserId, 'Replay scheduled report after support review');

        $latestRun = Database::queryOne(
            "SELECT status, workspace_id
             FROM scheduled_report_runs
             WHERE scheduled_report_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$scheduleId]
        );
        $audit = Database::queryOne(
            "SELECT action_type, reason
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'runtime_failure_replay'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertTrue((bool) ($result['success'] ?? false), (string) ($result['error_message'] ?? ''));
        $this->assertSame('success', (string) ($latestRun['status'] ?? ''));
        $this->assertSame($workspaceId, (int) ($latestRun['workspace_id'] ?? 0));
        $this->assertSame('runtime_failure_replay', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Replay scheduled report after support review', (string) ($audit['reason'] ?? ''));
    }
}
