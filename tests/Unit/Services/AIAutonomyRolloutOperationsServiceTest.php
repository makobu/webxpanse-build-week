<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIAutonomyRolloutOperationsService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyRolloutOperationsServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['ops@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testPauseAndResumeDomainUpdatesOperationalMetadata(): void
    {
        $service = new AIAutonomyRolloutOperationsService();

        $paused = $service->applyOperatorAction('workspace:1', 'customer_thread', 'pause_domain', [
            'reason' => 'Containment during instability',
        ], $this->userId);
        $this->assertTrue($paused['metadata']['paused']);

        $resumed = $service->applyOperatorAction('workspace:1', 'customer_thread', 'resume_domain', [
            'reason' => 'Recovered',
        ], $this->userId);
        $this->assertFalse($resumed['metadata']['paused']);
    }

    public function testApprovePromotionUsesPromotionRecommendation(): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'commercial_mvp', [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
        ], $this->userId);

        Database::execute(
            "INSERT INTO ai_autonomy_eval_runs
                (workspace_id, tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json, started_at, completed_at)
             VALUES (?, ?, ?, ?, 'completed', 5, ?, ?, NOW(), NOW())",
            [
                $this->workspaceId,
                'workspace:1',
                'commercial_mvp',
                'suggest_only',
                json_encode([
                    'sample_size' => 25,
                    'precision_at_threshold' => 0.96,
                    'reversal_rate' => 0.01,
                    'edit_after_autonomy_rate' => 0.02,
                    'duplicate_action_rate' => 0.0,
                    'approval_override_rate' => 0.0,
                ]),
                json_encode(['summary' => 'ready']),
            ]
        );

        $service = new AIAutonomyRolloutOperationsService();
        $updated = $service->applyOperatorAction('workspace:1', 'commercial_mvp', 'approve_promotion', [
            'reason' => 'Metrics cleared',
        ], $this->userId);

        $this->assertSame('auto_safe', $updated['autonomy_mode']);
        $this->assertSame('auto_safe', $updated['promotion_status']);
    }
}
