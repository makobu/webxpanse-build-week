<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\AITaskCompletionDecisionService;
use CRM\Services\AITaskCompletionService;
use CRM\Services\TaskCompletionScanQueueService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceTaskAutomationSettingsService;
use CRM\Tests\DatabaseTestCase;

class TaskCompletionScanQueueServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new WorkspaceTaskAutomationSettingsService())->save(1, [
            'rollout_mode' => 'full_auto',
            'allow_user_task_opt_in' => true,
            'min_confidence' => 0.96,
            'low_risk_only' => true,
        ], 0);
    }

    public function testQueueDedupesFreshCompletedScanAndProcessesQueuedJob(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'queuescan@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, 'Queue', 'Buyer', 'queue@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, description, status, contact_id, created_by, assigned_to, metadata_json, origin_type, completion_mode, created_at)
             VALUES (1, ?, ?, 'pending', ?, ?, ?, ?, 'ai', 'auto', NOW())",
            [
                'Follow up when client replies',
                '[AI-COACH][AUTO] Wait for reply',
                $contactId,
                $userId,
                $userId,
                json_encode([
                    'auto_complete_allowed' => true,
                    'source_surface' => 'ai_coach',
                ]),
            ]
        );
        $taskId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Re: Queue', 'Approved', 'sent', NOW())",
            [uniqid('comm_', true), $contactId]
        );

        $service = $this->queueService();

        $queued = $service->enqueueForUser($userId, $userId, 'manual', true);
        $this->assertSame('queued', $queued['status']);

        $processed = $service->processQueuedScans(5);
        $this->assertSame(1, $processed['processed_jobs']);
        $this->assertSame(1, $processed['completed_jobs']);
        $this->assertSame(1, $processed['completed_tasks']);

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);

        $freshStatus = $service->enqueueForUser($userId, $userId, 'tasks_page', false);
        $this->assertSame('completed', $freshStatus['status']);
        $this->assertTrue($freshStatus['is_fresh']);

        $queueCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM task_completion_scan_queue WHERE user_id = ?",
            [$userId]
        )['c'] ?? 0);
        $this->assertSame(1, $queueCount);
    }

    public function testProcessQueuedScansForUserOnlyClaimsCurrentUsersJobs(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW()), (?, ?, ?, 'user', NOW())",
            [
                uniqid('user_', true),
                'owner1@example.com',
                password_hash('secret', PASSWORD_DEFAULT),
                uniqid('user_', true),
                'owner2@example.com',
                password_hash('secret', PASSWORD_DEFAULT),
            ]
        );

        $userId = (int) (Database::queryOne("SELECT id FROM users WHERE email = ?", ['owner1@example.com'])['id'] ?? 0);
        $otherUserId = (int) (Database::queryOne("SELECT id FROM users WHERE email = ?", ['owner2@example.com'])['id'] ?? 0);

        $service = $this->queueService();

        Database::execute(
            "INSERT INTO task_completion_scan_queue (workspace_id, user_id, status, requested_by, request_source, active_dedupe_key, queued_at)
             VALUES (1, ?, 'queued', ?, 'manual', ?, NOW()), (1, ?, 'queued', ?, 'manual', ?, NOW())",
            [$userId, $userId, '1:' . $userId, $otherUserId, $otherUserId, '1:' . $otherUserId]
        );

        $summary = $service->processQueuedScansForUser($userId, 5);
        $this->assertSame(1, $summary['processed_jobs']);

        $ownJob = Database::queryOne(
            "SELECT status FROM task_completion_scan_queue WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        $otherJob = Database::queryOne(
            "SELECT status FROM task_completion_scan_queue WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$otherUserId]
        );

        $this->assertSame('completed', $ownJob['status']);
        $this->assertSame('queued', $otherJob['status']);
    }

    public function testQueueDedupesAndCountsPerWorkspace(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'queueworkspace@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Queue Workspace Two', 'queue-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $userId]
        );
        (new WorkspaceTaskAutomationSettingsService())->save($workspaceTwoId, [
            'rollout_mode' => 'full_auto',
            'allow_user_task_opt_in' => true,
            'min_confidence' => 0.96,
            'low_risk_only' => true,
        ], $userId);

        foreach ([1, $workspaceTwoId] as $workspaceId) {
            Database::execute(
                "INSERT INTO tasks (workspace_id, title, description, status, created_by, assigned_to, metadata_json, origin_type, completion_mode, created_at)
                 VALUES (?, ?, 'Scoped queue task', 'pending', ?, ?, ?, 'ai', 'auto', NOW())",
                [
                    $workspaceId,
                    'Auto task workspace ' . $workspaceId,
                    $userId,
                    $userId,
                    json_encode(['auto_complete_allowed' => true, 'source_surface' => 'ai_coach']),
                ]
            );
        }

        $service = $this->queueService();
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');
        $workspaceOneQueue = $service->enqueueForUser($userId, $userId, 'manual', true, 1);
        $workspaceTwoQueue = $service->enqueueForUser($userId, $userId, 'manual', true, $workspaceTwoId);

        $this->assertSame('queued', $workspaceOneQueue['status']);
        $this->assertSame('queued', $workspaceTwoQueue['status']);
        $this->assertSame(1, $workspaceOneQueue['eligible_task_count']);
        $this->assertSame(1, $workspaceTwoQueue['eligible_task_count']);

        $queueCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM task_completion_scan_queue WHERE user_id = ? AND status = 'queued'",
            [$userId]
        )['c'] ?? 0);
        $this->assertSame(2, $queueCount);

        $statusOne = $service->getUserScanStatus($userId, 1);
        $statusTwo = $service->getUserScanStatus($userId, $workspaceTwoId);
        $this->assertSame(1, $statusOne['eligible_task_count']);
        $this->assertSame(1, $statusTwo['eligible_task_count']);
        $this->assertSame(1, $statusOne['workspace_id']);
        $this->assertSame($workspaceTwoId, $statusTwo['workspace_id']);
    }

    public function testQueuedScanActivatesJobWorkspaceBeforeProcessing(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'queueworkspaceprocess@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Queue Process Workspace Two', 'queue-process-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $userId]
        );
        (new WorkspaceTaskAutomationSettingsService())->save($workspaceTwoId, [
            'rollout_mode' => 'full_auto',
            'allow_user_task_opt_in' => true,
            'min_confidence' => 0.96,
            'low_risk_only' => true,
        ], $userId);
        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at)
             VALUES (?, 'Queue', 'Workspace', 'queue-workspace-process@example.com', NOW())",
            [$workspaceTwoId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, description, status, contact_id, created_by, assigned_to, metadata_json, origin_type, completion_mode, created_at)
             VALUES (?, 'Follow up when client replies', '[AI-COACH][AUTO] Wait for reply', 'pending', ?, ?, ?, ?, 'ai', 'auto', NOW())",
            [
                $workspaceTwoId,
                $contactId,
                $userId,
                $userId,
                json_encode(['auto_complete_allowed' => true, 'source_surface' => 'ai_coach']),
            ]
        );
        $taskId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (?, ?, ?, 'email', 'inbound', 'Re: Workspace Process', 'Approved', 'sent', NOW())",
            [$workspaceTwoId, uniqid('comm_', true), $contactId]
        );

        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');
        $service = $this->queueService();
        $queued = $service->enqueueForUser($userId, $userId, 'manual', true, $workspaceTwoId);
        $this->assertSame('queued', $queued['status']);

        $processed = $service->processQueuedScans(5);
        $this->assertSame(1, $processed['processed_jobs']);
        $this->assertSame(1, $processed['completed_tasks']);

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);
    }

    public function testMoreThanTwoHundredTasksContinueUntilTheCursorIsExhausted(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'queue-pagination@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$userId]
        );
        for ($index = 1; $index <= 205; $index++) {
            Database::execute(
                "INSERT INTO tasks
                    (workspace_id, title, status, created_by, assigned_to, origin_type, completion_mode, metadata_json, created_at)
                 VALUES (1, ?, 'pending', ?, ?, 'automation', 'auto', ?, NOW())",
                [
                    'Cursor task ' . $index,
                    $userId,
                    $userId,
                    json_encode(['auto_complete_allowed' => true, 'source_surface' => 'workflow']),
                ]
            );
        }

        $service = $this->queueService();
        $queued = $service->enqueueForUser($userId, $userId, 'manual', true, 1);
        $this->assertSame(205, $queued['eligible_task_count']);

        $first = $service->processQueuedScansForUser($userId, 1, 1);
        $second = $service->processQueuedScansForUser($userId, 1, 1);
        $third = $service->processQueuedScansForUser($userId, 1, 1);

        $this->assertSame(1, $first['continued_jobs']);
        $this->assertSame(1, $second['continued_jobs']);
        $this->assertSame(1, $third['completed_jobs']);
        $status = $service->getUserScanStatus($userId, 1);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(2, $status['continuation_count']);
        $this->assertGreaterThan(0, (int) $status['cursor_task_id']);
    }

    private function assignTaskCapableRole(int $userId): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1")
            ?: Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        if ($role) {
            Authorization::assignUserRole($userId, (int) $role['id'], $userId);
        }
    }

    private function queueService(): TaskCompletionScanQueueService
    {
        $judge = new class extends AITaskCompletionDecisionService {
            public function decide(array $task, array $evidence, array $settings = []): array
            {
                return [
                    'decision' => 'complete',
                    'confidence' => (float) ($evidence['confidence_score'] ?? 0.98),
                    'risk' => 'low',
                    'explanation' => 'Test judge accepted the structured evidence.',
                    'evidence_fingerprints' => [(string) ($evidence['evidence_fingerprint'] ?? '')],
                    'missing_evidence' => [],
                    'conflicts' => [],
                    'judge_source' => 'test',
                ];
            }
        };
        return new TaskCompletionScanQueueService(new AITaskCompletionService($judge));
    }
}
