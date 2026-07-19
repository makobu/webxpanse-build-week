<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\Invoices;
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Tests\DatabaseTestCase;

class CommercialAutomationOrchestratorTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['commercial-auto@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Auto', 'Contact', 'auto-contact@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', ?, 'USD', NOW())",
            ['Commercial Auto Deal', $this->contactId, $this->userId, $this->userId, 0]
        );
        $this->dealId = (int) Database::lastInsertId();

        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'stage_entry_enabled' => true,
            'auto_send_enabled' => false,
        ]);
    }

    public function testProposalRunCreatesDraftQuote(): void
    {
        $result = (new CommercialAutomationOrchestrator())->runForDeal($this->dealId, 'manual');

        $invoice = Database::queryOne("SELECT * FROM invoices WHERE deal_id = ? ORDER BY id DESC LIMIT 1", [$this->dealId]);
        $demonstration = Database::queryOne(
            "SELECT * FROM ai_operator_demonstrations WHERE action_key = 'create_draft' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($invoice);
        $this->assertNotNull($demonstration);
        $this->assertSame('quote', $invoice['document_type']);
        $this->assertSame('auto_apply', $result['decision']);
    }

    public function testAssistantPlannedCommercialActionHonorsQualificationGate(): void
    {
        $invoiceId = (new Invoices())->createDraftForStage($this->dealId, 'proposal', 'quote', 'ai', $this->userId);
        $invoice = (new Invoices())->getById($invoiceId);
        $deal = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$this->dealId]);

        $result = (new CommercialAutomationOrchestrator())->runAssistantPlannedCommercialAction(
            ['action' => 'send_document', 'document_type' => 'quote'],
            [
                'deal' => $deal,
                'invoice' => $invoice,
                'surface' => 'admin_command',
                'assistant_confidence' => 0.10,
                'channel' => 'email',
            ],
            $this->userId
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('blocked', $result['policy']['decision']);
    }

    public function testPreviewApprovedActionReturnsStructuredSummary(): void
    {
        $invoiceId = (new Invoices())->createDraftForStage($this->dealId, 'proposal', 'quote', 'system', $this->userId);
        Database::execute(
            "INSERT INTO commercial_automation_approvals
                (workspace_id, deal_id, invoice_id, action_key, status, reason, requested_by_type, payload_json)
             VALUES (1, ?, ?, 'send_document', 'pending', ?, 'system', ?)",
            [
                $this->dealId,
                $invoiceId,
                'Recipient is required before sending.',
                json_encode([
                    'action' => ['action' => 'send_document', 'document_type' => 'quote'],
                    'context' => [
                        'document_type' => 'quote',
                        'channel' => 'email',
                        'recipient' => '',
                        'deal' => ['id' => $this->dealId, 'stage' => 'proposal', 'contact_id' => $this->contactId],
                        'invoice' => ['id' => $invoiceId, 'document_type' => 'quote'],
                    ],
                ]),
            ]
        );
        $approvalId = (int) Database::lastInsertId();

        $preview = (new CommercialAutomationOrchestrator())->previewApprovedAction($approvalId);

        $this->assertNotNull($preview);
        $this->assertSame('send_document', $preview['preview']['action']);
        $this->assertContains('missing_recipient', $preview['diagnostics']['reason_codes']);
    }
}
