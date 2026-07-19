<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyEvaluationService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyEvaluationServiceTest extends DatabaseTestCase
{
    private int $userId;
    private AIAutonomyEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['eval-service@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $this->service = new AIAutonomyEvaluationService();
    }

    public function testComputeMetricsCalculatesRoadmapSignals(): void
    {
        $metrics = $this->service->computeMetrics([
            [
                'autonomous' => true,
                'was_successful' => true,
                'completed' => true,
                'time_to_outcome_minutes' => 10,
                'human_baseline_minutes' => 20,
            ],
            [
                'autonomous' => true,
                'was_successful' => false,
                'was_reversed' => true,
                'approval_override' => true,
                'duplicate_action' => true,
                'completed' => false,
                'time_to_outcome_minutes' => 14,
                'human_baseline_minutes' => 20,
            ],
        ]);

        $this->assertSame(2, $metrics['sample_size']);
        $this->assertSame(0.5, $metrics['precision_at_threshold']);
        $this->assertSame(0.5, $metrics['reversal_rate']);
        $this->assertSame(0.5, $metrics['approval_override_rate']);
        $this->assertSame(0.5, $metrics['duplicate_action_rate']);
        $this->assertSame(0.5, $metrics['business_completion_rate']);
        $this->assertSame(12.0, $metrics['avg_time_to_outcome_minutes']);
        $this->assertSame(8.0, $metrics['time_to_outcome_delta_minutes']);
    }

    public function testStartAndCompleteRunPersistEvaluation(): void
    {
        $runId = $this->service->startRun([
            'tenant_key' => 'contact:72',
            'domain_key' => 'commercial_mvp',
            'autonomy_mode' => 'auto_safe',
            'scenario_count' => 5,
            'created_by' => $this->userId,
        ]);

        $completed = $this->service->completeRun($runId, [
            'precision_at_threshold' => 0.91,
            'reversal_rate' => 0.03,
        ], [
            'promotion_recommended' => true,
        ]);

        $row = Database::queryOne("SELECT * FROM ai_autonomy_eval_runs WHERE id = ?", [$runId]);

        $this->assertTrue($completed);
        $this->assertSame('completed', $row['run_status']);
        $this->assertSame(5, (int) $row['scenario_count']);
    }
}
