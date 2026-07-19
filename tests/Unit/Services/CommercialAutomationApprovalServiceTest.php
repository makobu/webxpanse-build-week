<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Services\CommercialAutomationApprovalService;
use CRM\Tests\DatabaseTestCase;

class CommercialAutomationApprovalServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'max_auto_discount_percent' => 20,
            'max_auto_total_change_percent' => 25,
            'max_revision_count_before_approval' => 2,
        ]);

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['approvals@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Approval', 'Contact', 'buyer@example.com']
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 0, 'USD', NOW())",
            ['Approval Deal', $contactId, $userId, $userId]
        );
        $dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO invoices
                (workspace_id, document_type, status, invoice_number, revision_number, deal_id, contact_id, assigned_to, created_by, currency, issue_date, subtotal, discount_total, tax_total, grand_total, balance_due, tax_mode, tax_rate, billing_email, created_at, updated_at)
             VALUES (1, 'quote', 'draft', 'Q-1001', 1, ?, ?, ?, ?, 'USD', CURDATE(), 100, 0, 0, 100, 100, 'none', 0, '', NOW(), NOW())",
            [$dealId, $contactId, $userId, $userId]
        );
        $invoiceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO commercial_automation_approvals
                (workspace_id, deal_id, invoice_id, action_key, status, reason, requested_by_type, requested_by_id, payload_json)
             VALUES (1, ?, ?, 'send_document', 'pending', ?, 'ai', ?, ?)",
            [
                $dealId,
                $invoiceId,
                'Recipient is required before sending.',
                $userId,
                json_encode([
                    'action' => ['action' => 'send_document', 'document_type' => 'quote'],
                    'context' => [
                        'surface' => 'admin_command',
                        'assistant_confidence' => 0.91,
                        'document_type' => 'quote',
                        'channel' => 'email',
                        'recipient' => '',
                        'deal' => ['id' => $dealId, 'stage' => 'proposal', 'contact_id' => $contactId],
                        'invoice' => ['id' => $invoiceId, 'document_type' => 'quote'],
                        'requested_discount_percent' => 0,
                        'requested_total_change_percent' => 0,
                    ],
                ]),
            ]
        );
    }

    public function testDetailedApprovalIncludesDiagnosticsAndPreview(): void
    {
        $service = new CommercialAutomationApprovalService();
        $approval = $service->listAll(['status' => 'pending', 'limit' => 10])[0] ?? null;

        $this->assertNotNull($approval);
        $this->assertSame('missing_data', $approval['diagnostics']['classification']);
        $this->assertContains('missing_recipient', $approval['diagnostics']['reason_codes']);
        $this->assertSame('email', $approval['diagnostics']['channel']);
        $this->assertSame('quote', $approval['preview']['document_type']);
        $this->assertStringContainsString('If approved, the system will send', $approval['preview']['summary']);
        $this->assertIsArray($approval['diagnostics']['learning_review']);
    }

    public function testSummaryMetricsCountAssistantAndCustomerSendApprovals(): void
    {
        $service = new CommercialAutomationApprovalService();
        $approvals = $service->listAll(['status' => 'pending', 'limit' => 10]);
        $metrics = $service->buildSummaryMetrics($approvals);

        $this->assertSame(1, $metrics['pending_count']);
        $this->assertSame(1, $metrics['assistant_origin_count']);
        $this->assertSame(1, $metrics['customer_send_count']);
        $this->assertSame('missing_data', $metrics['top_reason_category']);
    }
}
