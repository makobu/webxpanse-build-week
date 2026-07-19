<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIThresholdUpdateService;
use CRM\Tests\DatabaseTestCase;

class AIThresholdUpdateServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['thresholds@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );

        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (1, 'ai_action_min_confidence', '0.92')"
        );
    }

    public function testGetCurrentThresholdFallsBackThenUsesAppliedTune(): void
    {
        $service = new AIThresholdUpdateService();

        $this->assertSame(0.92, $service->getCurrentThreshold('ai_action_min_confidence', 'assistant', 'draft_customer_reply', 1));

        $service->applyThreshold('ai_action_min_confidence', 0.9, [
            'surface' => 'assistant',
            'action_type' => 'draft_customer_reply',
            'scope_type' => 'surface_action',
            'previous_value' => 0.92,
            'recommended_value' => 0.90,
            'change_reason_summary' => 'test change',
            'applied_automatically' => true,
        ]);

        $this->assertSame(0.9, $service->getCurrentThreshold('ai_action_min_confidence', 'assistant', 'draft_customer_reply', 1));
    }

    public function testRollbackRestoresPreviousThreshold(): void
    {
        $service = new AIThresholdUpdateService();

        $service->applyThreshold('ai_action_min_confidence', 0.9, [
            'surface' => 'assistant',
            'action_type' => 'draft_customer_reply',
            'scope_type' => 'surface_action',
            'previous_value' => 0.92,
            'recommended_value' => 0.90,
            'change_reason_summary' => 'test change',
            'applied_automatically' => true,
        ]);

        $latest = Database::queryOne("SELECT id FROM ai_threshold_tuning_log ORDER BY id DESC LIMIT 1");
        $rollback = $service->rollback((int) $latest['id']);

        $this->assertNotNull($rollback);
        $this->assertSame('rollback', $rollback['change_reason_summary']);
        $this->assertSame(0.92, $service->getCurrentThreshold('ai_action_min_confidence', 'assistant', 'draft_customer_reply', 1));
    }

    public function testBuildsRoleAwareThresholdRecommendationWithoutChangingEnforcement(): void
    {
        $service = new AIThresholdUpdateService();

        $recommendation = $service->getRoleAwareThresholdRecommendation('coach', 'advice', 1, [
            'role_profile' => 'founder',
            'threshold_posture' => 'balanced',
        ]);

        $this->assertSame('ai_advice_min_confidence', $recommendation['threshold_key']);
        $this->assertSame(0.88, $recommendation['current_threshold']);
        $this->assertArrayHasKey('recommended_threshold', $recommendation);
        $this->assertArrayHasKey('rationale', $recommendation);
    }
}
