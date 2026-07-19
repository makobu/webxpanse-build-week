<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIQualificationPolicyService;
use CRM\Services\AIUserWorkContextService;
use CRM\Tests\DatabaseTestCase;

class AIQualificationPolicyServiceTest extends DatabaseTestCase
{
    public function testAdviceEligibilityIncludesRoleAwareDiagnostics(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['policy-role@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $context = [
            'identity' => ['user_id' => $userId],
            'surface' => ['name' => 'clarity_chat'],
            'goal_state' => ['active_goals' => [['title' => 'Close deals']], 'goal_relevance_score' => 0.8],
            'feature_state' => ['company_profile_ready' => true, 'products_priced' => true, 'invoicing_ready' => true],
            'qualification_state' => ['effective_mode' => '1'],
            'ai_settings' => ['goal_relevance_min_score' => 0.7, 'missing_context_behavior' => 'warn'],
            'missing_context_flags' => [],
            'assistant_confidence' => 0.9,
            'role_profile' => [
                'role_profile' => 'founder',
                'summary' => 'Founder profile',
                'priority_focus' => ['revenue clarity'],
                'prompt_bias' => ['prioritize' => ['strategy']],
                'ownership_focus' => 'business_direction_and_revenue_readiness',
                'threshold_posture' => 'balanced',
            ],
            'user_work_context' => (new AIUserWorkContextService())->buildContext($userId, 'clarity_chat'),
        ];

        $decision = (new AIQualificationPolicyService())->evaluateAdviceEligibility($context);

        $this->assertSame('allow', $decision['decision']);
        $this->assertIsArray($decision['role_profile']);
        $this->assertArrayHasKey('role_threshold_recommendations', $decision);
        $this->assertArrayHasKey('user_work_context_summary', $decision);
        $this->assertArrayHasKey('role_decision_slice', $decision);
    }
}
