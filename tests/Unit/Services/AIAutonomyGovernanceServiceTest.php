<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIAutonomyGovernanceService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyGovernanceServiceTest extends DatabaseTestCase
{
    public function testBlockedWhenActionOutsideAllowedEnvelope(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:44', 'commercial_mvp', [
            'autonomy_mode' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['create_draft'],
            ],
        ]);

        $result = (new AIAutonomyGovernanceService())->evaluate('contact:44', 'commercial_mvp', 'send_document', [
            'recipient' => 'buyer@example.com',
        ], [
            'decision' => 'auto_apply',
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('action_outside_envelope', $result['reason_codes']);
    }

    public function testApprovalRequiredWhenCustomerFacingRiskCapExceeded(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:55', 'commercial_mvp', [
            'autonomy_mode' => 'full_auto',
            'metadata' => [
                'max_customer_facing_risk' => 0.5,
            ],
        ]);

        $result = (new AIAutonomyGovernanceService())->evaluate('contact:55', 'commercial_mvp', 'send_document', [
            'recipient' => 'buyer@example.com',
        ], [
            'decision' => 'auto_apply',
        ]);

        $this->assertSame('approval_required', $result['decision']);
        $this->assertContains('customer_facing_risk_cap_exceeded', $result['reason_codes']);
    }

    public function testBlockedWhenDomainIsPaused(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:77', 'workflow_execution', [
            'autonomy_mode' => 'full_auto',
            'metadata' => [
                'paused' => true,
            ],
        ]);

        $result = (new AIAutonomyGovernanceService())->evaluate('contact:77', 'workflow_execution', 'create_task', [], [
            'decision' => 'auto_apply',
        ]);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('domain_paused', $result['reason_codes']);
    }

    public function testApprovalRequiredWhenCustomerFacingPauseIsActive(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:78', 'customer_thread', [
            'autonomy_mode' => 'full_auto',
            'metadata' => [
                'pause_customer_facing_only' => true,
            ],
        ]);

        $result = (new AIAutonomyGovernanceService())->evaluate('contact:78', 'customer_thread', 'send_customer_reply', [
            'recipient' => 'buyer@example.com',
            'requires_customer_send' => true,
        ], [
            'decision' => 'auto_apply',
        ]);

        $this->assertSame('approval_required', $result['decision']);
        $this->assertContains('customer_facing_paused', $result['reason_codes']);
    }

    public function testCustomerCareFullAutoStillRequiresApprovalForCustomerFacingDrafts(): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'customer_care', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
        ]);

        $result = (new AIAutonomyGovernanceService())->evaluate('workspace:1', 'customer_care', 'draft_customer_reply', [
            'recipient' => 'customer@example.com',
            'requires_customer_send' => true,
        ], [
            'decision' => 'auto_apply',
        ]);

        $this->assertSame('approval_required', $result['decision']);
        $this->assertContains('human_checkpoint_required', $result['reason_codes']);
    }
}
