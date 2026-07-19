<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Services\CommercialAutomationPolicyService;
use CRM\Tests\DatabaseTestCase;

class CommercialAutomationPolicyServiceTest extends DatabaseTestCase
{
    private CommercialAutomationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommercialAutomationPolicyService();
    }

    public function testDisabledCommercialAutomationRejectsActions(): void
    {
        (new CommercialAutomationConfig())->save(['enabled' => false]);

        $decision = $this->service->evaluateAction('create_draft', ['deal' => ['stage' => 'proposal'], 'document_type' => 'quote']);

        $this->assertSame('reject', $decision['decision']);
    }

    public function testMissingRecipientRejectsSendInFullAuto(): void
    {
        (new CommercialAutomationConfig())->save(['enabled' => true, 'mode' => 'full_auto']);
        (new InvoiceSettings())->save([
            'ai_allowed_channels' => ['email' => true, 'whatsapp' => true],
            'ai_send_documents' => true,
        ], 1);

        $decision = $this->service->evaluateAction('send_document', [
            'deal' => ['stage' => 'proposal'],
            'invoice' => ['document_type' => 'quote', 'grand_total' => 100],
            'document_type' => 'quote',
            'channel' => 'email',
            'recipient' => '',
        ]);

        $this->assertSame('reject', $decision['decision']);
        $this->assertContains('missing_recipient', $decision['reasons']);
    }

    public function testBelowThresholdRequiresApprovalInAutoSafe(): void
    {
        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'auto_safe',
            'action_confidence_thresholds' => ['send_document' => 0.95],
        ]);
        (new InvoiceSettings())->save([
            'ai_allowed_channels' => ['email' => true, 'whatsapp' => true],
            'ai_send_documents' => true,
        ], 1);

        $decision = $this->service->evaluateAction('send_document', [
            'deal' => ['stage' => 'proposal'],
            'invoice' => ['document_type' => 'quote', 'grand_total' => 100],
            'document_type' => 'quote',
            'channel' => 'email',
            'recipient' => 'buyer@example.com',
            'assistant_confidence' => 0.90,
        ]);

        $this->assertSame('approval_required', $decision['decision']);
        $this->assertContains('confidence_below_threshold', $decision['reasons']);
    }

    public function testFullAutoAllowsSendWhenThresholdPasses(): void
    {
        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'action_confidence_thresholds' => ['send_document' => 0.80],
        ]);
        (new InvoiceSettings())->save([
            'ai_allowed_channels' => ['email' => true, 'whatsapp' => true],
            'ai_send_documents' => true,
        ], 1);

        $decision = $this->service->evaluateAction('send_document', [
            'deal' => ['stage' => 'proposal'],
            'invoice' => ['document_type' => 'quote', 'grand_total' => 100],
            'document_type' => 'quote',
            'channel' => 'email',
            'recipient' => 'buyer@example.com',
            'assistant_confidence' => 0.92,
        ]);

        $this->assertSame('auto_apply', $decision['decision']);
    }
}
