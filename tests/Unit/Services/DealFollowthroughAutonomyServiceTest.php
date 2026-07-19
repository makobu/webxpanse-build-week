<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\DealFollowthroughAutonomyService;
use CRM\Tests\DatabaseTestCase;

class DealFollowthroughAutonomyServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['deal-auto@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Deal', 'Auto', 'deal-auto@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, 'qualification', 1000, 'USD', NOW(), NOW())",
            ['Deal Autonomy', $this->contactId, $this->userId, $this->userId]
        );
        $this->dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO invoices
                (workspace_id, document_type, status, invoice_number, revision_number, deal_id, contact_id, assigned_to, created_by, currency, issue_date, subtotal, discount_total, tax_total, grand_total, balance_due, tax_mode, tax_rate, billing_email, created_at, updated_at)
             VALUES (1, 'quote', 'draft', 'QT-4001', 1, ?, ?, ?, ?, 'USD', CURDATE(), 100, 0, 0, 100, 100, 'none', 0, ?, NOW(), NOW())",
            [$this->dealId, $this->contactId, $this->userId, $this->userId, 'deal-auto@example.com']
        );

        (new AIAutonomyDomainControlService())->save('contact:' . $this->contactId, 'deal_followthrough', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['progress_stage'],
            ],
        ]);
    }

    public function testRunForDealProgressesStageWhenProposalEvidenceExists(): void
    {
        $result = (new DealFollowthroughAutonomyService())->runForDeal($this->dealId, 'manual', $this->userId);
        $deal = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$this->dealId]);

        $this->assertSame('auto_apply', $result['decision']);
        $this->assertSame('proposal', $deal['stage']);
    }
}
