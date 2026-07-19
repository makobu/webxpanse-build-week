<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Tasks;
use CRM\Services\FinanceLedgerService;
use CRM\Services\FinanceOwnerEquityService;
use CRM\Services\TaskCompletionReviewService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class TaskCompletionReviewServiceTest extends DatabaseTestCase
{
    public function testFinanceSetupTaskReportsFinanceGateBlockersInsteadOfChecklistOnly(): void
    {
        $userId = $this->createOwnerUser('finance-review-missing@example.test');
        $task = $this->createFinanceSetupTask($userId);

        $review = (new TaskCompletionReviewService())->evaluateCompletion($task, $userId);

        $this->assertSame('insufficient_evidence', $review['decision']);
        $this->assertContains('finance_setup_incomplete', $this->reviewCodes($review, 'evidence_missing'));
        $this->assertNotContains('checklist_incomplete', $this->reviewCodes($review, 'evidence_missing'));
        $this->assertStringContainsString('Install Finance', (string) ($review['evidence_missing'][0]['detail'] ?? ''));
    }

    public function testFinanceSetupTaskCanBeConfirmedFromFinanceGateEvidence(): void
    {
        $userId = $this->createOwnerUser('finance-review-ready@example.test');
        $this->completeFinanceSetup($userId);
        $task = $this->createFinanceSetupTask($userId);

        $review = (new TaskCompletionReviewService())->evaluateCompletion($task, $userId);

        $this->assertSame('confirm', $review['decision']);
        $this->assertGreaterThanOrEqual(0.85, (float) ($review['confidence_score'] ?? 0));
        $this->assertContains('finance_setup_ready', $this->reviewCodes($review, 'evidence_found'));
        $this->assertNotContains('checklist_incomplete', $this->reviewCodes($review, 'evidence_missing'));
    }

    public function testDetectedReplyEvidenceConfirmsTaskDespiteIncompleteChecklist(): void
    {
        $userId = $this->createOwnerUser('reply-review-ready@example.test');
        $contactId = $this->createContact('Reply', 'Buyer', 'reply-buyer@example.test');
        $task = $this->createReplyTask($userId, $contactId);

        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Re: Follow up', 'Ready to proceed', 'sent', NOW())",
            [uniqid('review-reply-', true), $contactId]
        );

        $review = (new TaskCompletionReviewService())->evaluateCompletion($task, $userId);

        $this->assertSame('confirm', $review['decision']);
        $this->assertGreaterThanOrEqual(0.85, (float) ($review['confidence_score'] ?? 0));
        $this->assertContains('email_reply_received', $this->reviewCodes($review, 'evidence_found'));
        $this->assertContains('checklist_incomplete', $this->reviewCodes($review, 'evidence_missing'));
    }

    public function testReviewIgnoresInboundRepliesOlderThanTheTask(): void
    {
        $userId = $this->createOwnerUser('reply-review-stale@example.test');
        $contactId = $this->createContact('Old', 'Reply', 'old-reply@example.test');
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, ?, ?, 'email', 'inbound', 'Old reply', 'This happened first', 'sent', DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [uniqid('review-old-reply-', true), $contactId]
        );
        $task = $this->createReplyTask($userId, $contactId);

        $review = (new TaskCompletionReviewService())->evaluateCompletion($task, $userId);

        $this->assertSame('insufficient_evidence', $review['decision']);
        $this->assertNotContains('email_reply_received', $this->reviewCodes($review, 'evidence_found'));
        $this->assertContains('email_reply_missing', $this->reviewCodes($review, 'evidence_missing'));
    }

    public function testDetectorEvidenceConfirmsBillingTaskDespiteIncompleteChecklist(): void
    {
        $userId = $this->createOwnerUser('billing-review-ready@example.test');
        $this->completeBillingEvidence($userId);
        $task = $this->createBillingTask($userId);

        $review = (new TaskCompletionReviewService())->evaluateCompletion($task, $userId);

        $this->assertSame('confirm', $review['decision']);
        $this->assertGreaterThanOrEqual(0.85, (float) ($review['confidence_score'] ?? 0));
        $this->assertContains('workflow_step_completed', $this->reviewCodes($review, 'evidence_found'));
        $this->assertContains('checklist_incomplete', $this->reviewCodes($review, 'evidence_missing'));
    }

    private function createOwnerUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('finance-review-owner-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE role_slug = 'owner', membership_status = 'active', is_owner = 1",
            [$userId]
        );

        return $userId;
    }

    private function createFinanceSetupTask(int $userId): array
    {
        $taskId = (new Tasks())->createWithSubtasks([
            'title' => 'Finish Finance setup',
            'description' => "[AI-COACH][AUTO]\nReason: Complete Finance setup before opening Finance.",
            'created_by' => $userId,
            'status' => 'in_progress',
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'completion_evidence_types' => ['finance_setup_ready'],
            ],
        ], [
            ['title' => 'Open Finance setup'],
            ['title' => 'Save opening balances'],
            ['title' => 'Add owner equity'],
        ]);

        return (new Tasks())->getById($taskId) ?: [];
    }

    private function createReplyTask(int $userId, int $contactId): array
    {
        $taskId = (new Tasks())->createWithSubtasks([
            'title' => 'Follow up when client replies',
            'description' => "[AI-COACH][AUTO]\nReason: Wait for a fresh reply.",
            'contact_id' => $contactId,
            'created_by' => $userId,
            'status' => 'in_progress',
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'completion_evidence_types' => ['email_reply_received'],
                'task_intent' => 'follow_up',
            ],
        ], [
            ['title' => 'Send follow-up'],
            ['title' => 'Record response'],
        ]);

        return (new Tasks())->getById($taskId) ?: [];
    }

    private function createBillingTask(int $userId): array
    {
        $taskId = (new Tasks())->createWithSubtasks([
            'title' => 'Invoicing Configuration',
            'description' => "[AI-COACH][AUTO]\nReason: Configure invoicing.",
            'created_by' => $userId,
            'status' => 'in_progress',
            'metadata_json' => [
                'auto_complete_allowed' => true,
                'source_surface' => 'ai_coach',
                'completion_evidence_types' => ['workflow_step_completed'],
                'task_intent' => 'billing',
            ],
        ], [
            ['title' => 'Complete invoice design'],
            ['title' => 'Save billing and company details'],
            ['title' => 'Send a test invoice successfully'],
        ]);

        return (new Tasks())->getById($taskId) ?: [];
    }

    private function createContact(string $firstName, string $lastName, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (1, ?, ?, ?, ?, NOW())",
            [uniqid('review-contact-', true), $firstName, $lastName, $email]
        );

        return (int) Database::lastInsertId();
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

    private function completeBillingEvidence(int $userId): void
    {
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
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, revision_number, assigned_to, created_by,
                currency, issue_date, due_date, valid_until, payment_terms_days, tax_mode, tax_rate, title
            ) VALUES (1, 'proforma', 'sent', 'PF-REVIEW-001', 1, ?, ?, 'KES', CURDATE(), CURDATE(), CURDATE(), 14, 'exclusive', 0, 'Review Proforma')",
            [$userId, $userId]
        );
    }

    private function reviewCodes(array $review, string $bucket): array
    {
        return array_values(array_map(
            static fn(array $item): string => (string) ($item['code'] ?? ''),
            (array) ($review[$bucket] ?? [])
        ));
    }
}
