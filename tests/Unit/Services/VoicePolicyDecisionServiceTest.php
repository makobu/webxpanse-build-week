<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\VoicePolicyDecisionService;
use PHPUnit\Framework\TestCase;

class VoicePolicyDecisionServiceTest extends TestCase
{
    public function testPolicyNormalizesEachCapabilityIndependently(): void
    {
        $policy = (new VoicePolicyDecisionService())->normalize([
            'minimum_confidence' => 0.9,
            'call_summary' => 'off',
            'contact_context' => 'automatic_safe',
            'follow_up_tasks' => 'invalid',
            'deal_stage' => 'automatic_safe',
        ]);

        $this->assertSame('off', $policy['call_summary']);
        $this->assertSame('automatic_safe', $policy['contact_context']);
        $this->assertSame('approval_required', $policy['follow_up_tasks']);
        $this->assertSame('suggest', $policy['deal_stage']);
        $this->assertSame(0.9, $policy['minimum_confidence']);
    }

    public function testAutomaticSafeRequiresConfidenceAndApprovalModeRequiresReview(): void
    {
        $service = new VoicePolicyDecisionService();
        $config = [
            'ai_application_enabled' => true,
            'customer_voice_enabled' => true,
            'automation_policy' => [
                'minimum_confidence' => 0.8,
                'contact_context' => 'automatic_safe',
                'follow_up_tasks' => 'approval_required',
            ],
        ];

        $this->assertSame('require_approval', $service->decide($config, 'contact_context', false, 0.75)['decision']);
        $this->assertSame('allow', $service->decide($config, 'contact_context', true, 0.75)['decision']);
        $this->assertSame('allow', $service->decide($config, 'contact_context', false, 0.91)['decision']);
        $this->assertSame('require_approval', $service->decide($config, 'follow_up_tasks', false, 0.91)['decision']);
        $this->assertSame('allow', $service->decide($config, 'follow_up_tasks', true, 0.91)['decision']);
    }

    public function testMasterAiSwitchAlwaysWins(): void
    {
        $decision = (new VoicePolicyDecisionService())->decide([
            'ai_application_enabled' => false,
            'automation_policy' => ['contact_context' => 'automatic_safe'],
        ], 'contact_context', true, 1.0);

        $this->assertFalse($decision['apply']);
        $this->assertSame('workspace_ai_application_disabled', $decision['reason']);
    }
}
