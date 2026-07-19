<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Services\EmailAssistantPolicyBridge;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantPolicyBridgeTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['assistant-policy@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?)",
            [$this->userId, 'ai_mode_lock', 'operations']
        );

        Database::execute(
            "UPDATE company_profile SET company_name = ?, company_description = ?, is_active = 1 WHERE id = 1",
            ['Assistant Policy Co', 'Commercial automation ready']
        );

        Database::execute(
            "INSERT INTO products (name, description, category, pricing_info, unit_price, is_active, display_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())",
            ['Qualified Service', 'Primary service', 'Service', 'Standard pricing', 120]
        );

        (new CompanyProfile())->update([
            'company_legal_name' => 'Assistant Policy Co Ltd',
            'company_email' => 'billing@example.com',
        ]);

        (new InvoiceSettings())->save([
            'enabled' => true,
        ], $this->userId);

        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'auto_send_enabled' => true,
            'stage_entry_enabled' => true,
            'negotiation_revisions_enabled' => true,
            'send_channels' => ['email' => true, 'whatsapp' => false],
            'require_recipient_for_send' => true,
            'require_nonzero_total_for_send' => true,
        ]);
    }

    public function testQualificationBlockOverridesCommercialAllow(): void
    {
        $decision = (new EmailAssistantPolicyBridge())->evaluateCommercialAssistantAction('send_document', [
            'surface' => 'admin_command',
            'assistant_confidence' => 0.10,
            'deal' => ['stage' => 'proposal'],
            'invoice' => ['document_type' => 'quote', 'grand_total' => 500],
            'document_type' => 'quote',
            'recipient' => 'buyer@example.com',
            'channel' => 'email',
        ], $this->userId);

        $this->assertSame('blocked', $decision['decision']);
        $this->assertContains('confidence_below_threshold', $decision['reasons']);
        $this->assertFalse($decision['can_execute']);
    }

    public function testCommercialHardBlockStillAppliesAfterQualificationPasses(): void
    {
        $decision = (new EmailAssistantPolicyBridge())->evaluateCommercialAssistantAction('send_document', [
            'surface' => 'admin_command',
            'assistant_confidence' => 0.99,
            'deal' => ['stage' => 'proposal'],
            'invoice' => ['document_type' => 'quote', 'grand_total' => 500],
            'document_type' => 'quote',
            'recipient' => '',
            'channel' => 'email',
        ], $this->userId);

        $this->assertSame('blocked', $decision['decision']);
        $this->assertFalse($decision['approval_required']);
        $this->assertContains('missing_recipient', $decision['reasons']);
    }
}
