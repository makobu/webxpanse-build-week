<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;
use CRM\Services\AITaskCompletionDecisionService;
use CRM\Services\AITaskCompletionService;
use CRM\Services\FinanceLedgerService;
use CRM\Services\TaskCompletionCoordinator;
use CRM\Services\WorkspaceContext;
use CRM\Services\FinanceOwnerEquityService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceTaskAutomationSettingsService;
use CRM\Tests\DatabaseTestCase;

class AITaskCompletionServiceTest extends DatabaseTestCase
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

    public function testAutoCompletesReplyTaskOnlyWithExplicitEvidence(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['taskauto@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, 'Jane', 'Buyer', 'jane@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );

        $taskId = (new Tasks())->create([
            'title' => 'Follow up when client replies',
            'description' => '[AI-COACH][AUTO] Wait for reply',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
            ],
        ]);

        $service = $this->completionService();
        $before = $service->scanForCompletionEvidence($userId, $taskId);
        $this->assertSame([], $before);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Re: Quote', 'Thanks, let us proceed', 'sent', NOW())",
            [uniqid('comm_', true), $contactId]
        );

        $results = $service->scanForCompletionEvidence($userId, $taskId);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['completed']);

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);
        $evidence = Database::queryOne("SELECT evidence_type FROM ai_task_evidence WHERE task_id = ?", [$taskId]);
        $this->assertSame('email_reply_received', $evidence['evidence_type']);
    }

    public function testReviewWorkspaceRecordsRecommendationWithoutCompleting(): void
    {
        (new WorkspaceTaskAutomationSettingsService())->save(1, [
            'rollout_mode' => 'review',
            'allow_user_task_opt_in' => true,
            'min_confidence' => 0.96,
            'low_risk_only' => true,
        ], 0);
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('review-rollout@example.com');

        $results = ($this->completionService())->scanForCompletionEvidence($ownerId, $taskId);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['completed']);
        $this->assertSame('review', $results[0]['workspace_mode']);
        $task = Database::queryOne("SELECT status, metadata_json FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
        $metadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('complete', (string) ($metadata['completion_review']['decision'] ?? ''));
    }

    public function testManualTaskWithoutOptInNeverAutoCompletes(): void
    {
        $userId = $this->createWorkspaceUser('manual-no-opt-in@example.com');
        $this->assignTaskCapableRole($userId);
        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at)
             VALUES (1, 'Manual', 'Task', 'manual-task@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();
        $taskId = (new Tasks())->create([
            'title' => 'Follow up when the client replies',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'assigned_to' => $userId,
        ]);
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Re: Manual', 'Ready', 'sent', NOW())",
            [uniqid('comm_', true), $contactId]
        );

        $results = ($this->completionService())->scanForCompletionEvidence($userId, $taskId);

        $this->assertSame([], $results);
        $task = Database::queryOne("SELECT status, completion_mode FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
        $this->assertSame('manual', $task['completion_mode']);
    }

    public function testReopenRejectsAcceptedFingerprintUntilEvidenceChanges(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('reopen-evidence@example.com');
        $service = $this->completionService();
        $completed = $service->scanForCompletionEvidence($ownerId, $taskId);
        $this->assertTrue($completed[0]['completed']);

        $this->assertTrue((new TaskCompletionCoordinator())->reopen($taskId, [
            'actor_user_id' => $ownerId,
            'reason' => 'The reply did not resolve the task.',
        ]));
        $sameEvidence = $service->scanForCompletionEvidence($ownerId, $taskId);

        $this->assertCount(1, $sameEvidence);
        $this->assertFalse($sameEvidence[0]['completed']);
        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('in_progress', $task['status']);
        $evidence = Database::queryOne(
            "SELECT decision_status FROM ai_task_evidence WHERE task_id = ? ORDER BY id DESC LIMIT 1",
            [$taskId]
        );
        $this->assertSame('rejected', $evidence['decision_status']);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             SELECT workspace_id, ?, contact_id, 'email', 'inbound', 'New reply', 'Resolved now', 'sent', DATE_ADD(NOW(), INTERVAL 1 SECOND)
             FROM tasks WHERE id = ?",
            [uniqid('comm_', true), $taskId]
        );
        $newEvidence = $service->scanForCompletionEvidence($ownerId, $taskId);
        $this->assertCount(1, $newEvidence);
        $this->assertTrue($newEvidence[0]['completed']);
    }

    public function testUnavailableAiJudgeKeepsEligibleTaskOpen(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('provider-unavailable@example.com');

        $results = (new AITaskCompletionService())->scanForCompletionEvidence($ownerId, $taskId);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['completed']);
        $this->assertSame('review', $results[0]['decision']['decision']);
        $this->assertContains('valid_ai_judgment', $results[0]['decision']['missing_evidence']);
        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
    }

    public function testReplyTaskIgnoresInboundMessagesOlderThanTask(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['taskoldreply@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, 'Old', 'Reply', 'oldreply@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Old reply', 'This happened first', 'sent', DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [uniqid('comm_', true), $contactId]
        );

        $taskId = (new Tasks())->create([
            'title' => 'Follow up when client replies',
            'description' => 'Reason: Waiting for a fresh reply',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'assigned_to' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
            ],
        ]);

        $service = $this->completionService();
        $this->assertSame([], $service->scanForCompletionEvidence($userId, $taskId));

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
    }

    public function testScansTasksCreatedByUserWhenNoAssigneeIsSet(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['taskcreatorauto@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$userId]
        );
        $this->assignTaskCapableRole($userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, 'Creator', 'Buyer', 'creatorbuyer@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );

        $taskId = (new Tasks())->create([
            'title' => 'Follow up when client replies',
            'description' => 'Reason: Waiting for reply',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
            ],
        ]);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Fresh reply', 'Ready to proceed', 'sent', NOW())",
            [uniqid('comm_', true), $contactId]
        );

        $results = ($this->completionService())->scanForCompletionEvidence($userId);
        $this->assertCount(1, $results);
        $this->assertSame($taskId, (int) ($results[0]['task_id'] ?? 0));
        $this->assertTrue($results[0]['completed']);
    }

    public function testBillingTaskSyncsMeasurableSubtasksFromSavedInvoiceEvidence(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['billingauto@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );

        Database::execute(
            "UPDATE invoice_settings
             SET enabled = 1,
                 company_legal_name = 'Pick and Go Limited',
                 company_address = 'Kiserian',
                 company_email = 'pickandgoshopping@gmail.com',
                 company_phone = '0791399545',
                 logo_asset_path = 'uploads/invoices/logo.png',
                 footer_text = 'Configured footer',
                 updated_by = ?",
            [$userId]
        );

        $taskId = (new Tasks())->createWithSubtasks([
            'title' => 'Invoicing Configuration',
            'description' => '[AI-COACH][AUTO] Configure invoicing',
            'created_by' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'task_intent' => 'billing',
                'completion_evidence_types' => ['workflow_step_completed'],
            ],
        ], [
            ['title' => 'Complete invoice design'],
            ['title' => 'Save billing and company details'],
            ['title' => 'Send a test invoice successfully'],
        ]);

        $service = $this->completionService();
        $before = $service->scanForCompletionEvidence($userId, $taskId);
        $this->assertSame([], $before);

        $subtasks = Database::query("SELECT title, completed FROM task_subtasks WHERE task_id = ? ORDER BY `order` ASC, id ASC", [$taskId]);
        $this->assertSame(1, (int) ($subtasks[0]['completed'] ?? 0));
        $this->assertSame(1, (int) ($subtasks[1]['completed'] ?? 0));
        $this->assertSame(0, (int) ($subtasks[2]['completed'] ?? 0));

        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, revision_number, assigned_to, created_by,
                currency, issue_date, due_date, valid_until, payment_terms_days, tax_mode, tax_rate, title
            ) VALUES (1, 'proforma', 'sent', 'PF-TEST-001', 1, ?, ?, 'KES', CURDATE(), CURDATE(), CURDATE(), 14, 'exclusive', 0, 'Test Proforma')",
            [$userId, $userId]
        );

        $after = $service->scanForCompletionEvidence($userId, $taskId);
        $this->assertCount(1, $after);
        $this->assertTrue($after[0]['completed']);

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);
    }

    public function testAutoCompletesFinanceSetupTaskWhenFinanceGateIsReady(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'owner', NOW())",
            ['financeautodone@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );
        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );
        $this->completeFinanceSetup($userId);

        $taskId = (new Tasks())->createWithSubtasks([
            'title' => 'Finish Finance setup',
            'description' => "[AI-COACH][AUTO]\nReason: Complete Finance setup before opening Finance.",
            'created_by' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'completion_evidence_types' => ['finance_setup_ready'],
                'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
            ],
        ], [
            ['title' => 'Open Finance setup'],
            ['title' => 'Save opening balances'],
            ['title' => 'Add owner equity'],
        ]);

        $results = ($this->completionService())->scanForCompletionEvidence($userId, $taskId);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['completed']);
        $this->assertSame('finance_setup_ready', (string) ($results[0]['evidence']['evidence_type'] ?? ''));

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);
        $evidence = Database::queryOne("SELECT evidence_type FROM ai_task_evidence WHERE task_id = ?", [$taskId]);
        $this->assertSame('finance_setup_ready', $evidence['evidence_type']);
    }

    public function testSpecificTaskScanRequiresOwnerAssigneeOrTaskWritePermission(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('scan-owner@example.com');
        $peerId = $this->createWorkspaceUser('scan-peer@example.com');

        try {
            ($this->completionService())->scanForCompletionEvidence($peerId, $taskId, [
                'actor_user_id' => $peerId,
                'enforce_task_access' => true,
            ]);
            $this->fail('Expected unauthorized peer scan to be rejected.');
        } catch (\DomainException $e) {
            $this->assertSame('Task scan is not allowed for this user.', $e->getMessage());
        }

        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
    }

    public function testTaskWriterCanScanAnotherUsersTaskInWorkspace(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('scan-managed-owner@example.com');
        $managerId = $this->createWorkspaceUser('scan-manager@example.com');
        $this->assignTaskCapableRole($managerId);

        $results = ($this->completionService())->scanForCompletionEvidence($ownerId, $taskId, [
            'actor_user_id' => $managerId,
            'enforce_task_access' => true,
        ]);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['completed']);
        $task = Database::queryOne("SELECT status, metadata_json FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('completed', $task['status']);
        $metadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame($managerId, (int) ($metadata['completion_scanned_by'] ?? 0));
    }

    public function testPausedRuntimeControlPreventsAutoCompletion(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('scan-paused@example.com');
        $this->setTaskAutomationMode('paused', $ownerId);

        $results = ($this->completionService())->scanForCompletionEvidence($ownerId, $taskId, [
            'actor_user_id' => $ownerId,
            'enforce_task_access' => true,
        ]);

        $this->assertSame([], $results);
        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
    }

    public function testSuggestOnlyRuntimeControlPersistsReviewWithoutCompleting(): void
    {
        [$ownerId, $taskId] = $this->seedReplyTaskWithEvidence('scan-suggest@example.com');
        $this->setTaskAutomationMode('suggest_only', $ownerId);

        $results = ($this->completionService())->scanForCompletionEvidence($ownerId, $taskId, [
            'actor_user_id' => $ownerId,
            'enforce_task_access' => true,
        ]);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['completed']);
        $this->assertSame('suggest_only', $results[0]['control_mode']);
        $task = Database::queryOne("SELECT status, metadata_json FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
        $metadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('warn', (string) ($metadata['completion_review']['decision'] ?? ''));
    }

    public function testBillingEvidenceDoesNotCrossWorkspaceBoundary(): void
    {
        $userId = $this->createWorkspaceUser('billing-cross-workspace@example.com');
        $this->assignTaskCapableRole($userId);
        $workspaceTwoId = $this->createWorkspace('billing-cross-workspace-two', $userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $userId]
        );
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );
        Database::execute(
            "UPDATE invoice_settings
             SET enabled = 1,
                 company_legal_name = 'Scoped Billing Limited',
                 company_address = 'Nairobi',
                 company_email = 'billing@example.com',
                 company_phone = '0700000000',
                 logo_asset_path = 'uploads/invoices/logo.png',
                 footer_text = 'Configured footer',
                 updated_by = ?",
            [$userId]
        );

        $taskId = (new Tasks())->create([
            'title' => 'Finish billing setup',
            'description' => '[AI-COACH][AUTO] Configure billing',
            'created_by' => $userId,
            'assigned_to' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'task_intent' => 'billing',
                'completion_evidence_types' => ['workflow_step_completed'],
            ],
        ]);

        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, revision_number, assigned_to, created_by,
                currency, issue_date, due_date, valid_until, payment_terms_days, tax_mode, tax_rate, title
            ) VALUES (?, 'proforma', 'sent', 'PF-OTHER-001', 1, ?, ?, 'KES', CURDATE(), CURDATE(), CURDATE(), 14, 'exclusive', 0, 'Other Workspace Proforma')",
            [$workspaceTwoId, $userId, $userId]
        );

        $results = ($this->completionService())->scanForCompletionEvidence($userId, $taskId);
        $this->assertSame([], $results);
        $task = Database::queryOne("SELECT status FROM tasks WHERE id = ?", [$taskId]);
        $this->assertSame('pending', $task['status']);
    }

    private function assignTaskCapableRole(int $userId): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1")
            ?: Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        if ($role) {
            Authorization::assignUserRole($userId, (int) ($role['id'] ?? 0), $userId);
        }
    }

    private function completionService(): AITaskCompletionService
    {
        $decisionService = new class extends AITaskCompletionDecisionService {
            public function decide(array $task, array $evidence, array $settings = []): array
            {
                $fingerprint = (string) ($evidence['evidence_fingerprint'] ?? '');
                return [
                    'decision' => 'complete',
                    'confidence' => (float) ($evidence['confidence_score'] ?? 0.98),
                    'risk' => 'low',
                    'explanation' => 'Test judge accepted the structured evidence.',
                    'evidence_fingerprints' => [$fingerprint],
                    'missing_evidence' => [],
                    'conflicts' => [],
                    'judge_source' => 'test',
                ];
            }
        };
        return new AITaskCompletionService($decisionService);
    }

    private function createWorkspaceUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('user_', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'member', 'active', 0, NOW())",
            [$userId]
        );
        return $userId;
    }

    private function createWorkspace(string $slug, int $createdBy): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, ?, ?, 'active', 'active', ?)",
            [uniqid('workspace-', true), ucfirst(str_replace('-', ' ', $slug)), $slug, $createdBy]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * @return array{0:int,1:int}
     */
    private function seedReplyTaskWithEvidence(string $email): array
    {
        $userId = $this->createWorkspaceUser($email);
        $this->assignTaskCapableRole($userId);
        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$userId, 'ai_auto_task_completion_enabled', '1']
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at)
             VALUES (1, 'Scan', 'Buyer', ?, NOW())",
            ['contact-' . $email]
        );
        $contactId = (int) Database::lastInsertId();

        $taskId = (new Tasks())->create([
            'title' => 'Follow up when client replies',
            'description' => '[AI-COACH][AUTO] Wait for reply',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'assigned_to' => $userId,
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
            ],
        ]);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Re: Scan', 'Ready', 'sent', NOW())",
            [uniqid('comm_', true), $contactId]
        );

        return [$userId, $taskId];
    }

    private function setTaskAutomationMode(string $mode, int $userId): void
    {
        Database::execute(
            "INSERT INTO ai_runtime_controls (workspace_id, surface, control_mode, reason, set_by, set_at)
             VALUES (1, 'task_automation', ?, 'Test runtime control', ?, NOW())",
            [$mode, $userId]
        );
    }

    private function completeFinanceSetup(int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
             ) VALUES (1, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL, updated_at = NOW()",
            [WorkspaceSkillCatalogService::PLUGIN_FINANCE, $userId, $userId]
        );
        (new FinanceLedgerService())->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => date('Y-m-d'),
            'amount' => 0,
            'currency' => 'USD',
            'opening_cash' => '1000.00',
            'opening_receivables' => '0.00',
            'opening_payables' => '0.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '0.00',
            'opening_equity' => '1000.00',
            'opening_setup_complete' => 1,
        ], $userId);
        (new FinanceOwnerEquityService())->saveProfiles(1, [[
            'user_id' => $userId,
            'ownership_percent' => 100,
            'opening_owner_capital' => 1000,
            'opening_owner_draws' => 0,
            'currency' => 'USD',
        ]], $userId);
    }
}
