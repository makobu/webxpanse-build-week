<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIConfidenceCalibrationService;
use CRM\Tests\DatabaseTestCase;

class AIConfidenceCalibrationServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['calibration@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );

        $preferences = [
            'ai_action_min_confidence' => '0.92',
            'ai_autonomous_threshold_tuning_enabled' => '1',
            'ai_calibration_min_sample_size' => '30',
            'ai_calibration_daily_change_cap' => '0.02',
            'ai_calibration_rolling_change_cap' => '0.05',
            'ai_mode_lock' => 'auto',
        ];

        foreach ($preferences as $key => $value) {
            Database::execute(
                "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (1, ?, ?)",
                [$key, $value]
            );
        }

        $measuredAt = date('Y-m-d H:i:s', strtotime('-8 days'));
        for ($i = 0; $i < 30; $i++) {
            Database::execute(
                "INSERT INTO ai_decision_outcomes
                    (workspace_id, assistant_run_id, user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score,
                     policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at)
                 VALUES (?, ?, 1, 'assistant', 'draft', 'draft_customer_reply', 0.94, 0.85, 0.80, 'allow', '{}', 'accepted', 1.0, '{}', ?)",
                [$this->workspaceId, $i + 1, $measuredAt]
            );
        }

        for ($i = 0; $i < 5; $i++) {
            Database::execute(
                "INSERT INTO ai_decision_outcomes
                    (workspace_id, assistant_run_id, user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score,
                     policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at)
                 VALUES (?, ?, 1, 'assistant', 'draft', 'draft_customer_reply', 0.90, 0.82, 0.78, 'blocked', '{}', 'accepted', 1.0, '{}', ?)",
                [$this->workspaceId, 100 + $i, $measuredAt]
            );
        }
    }

    public function testCalibrationSummaryComputesRatesForAssistantDrafts(): void
    {
        $service = new AIConfidenceCalibrationService();

        $metrics = $service->getSurfaceMetrics('assistant')['draft_customer_reply'] ?? null;

        $this->assertNotNull($metrics);
        $this->assertSame(35, $metrics['sample_size']);
        $this->assertSame(1.0, $metrics['acceptance_rate']);
        $this->assertSame(0.1429, $metrics['false_block_indicator']);
        $this->assertSame(1.0, $metrics['high_confidence_success_rate']);
    }

    public function testAutonomousTuningLowersThresholdWhenEvidenceSupportsRelaxation(): void
    {
        $service = new AIConfidenceCalibrationService();

        $result = $service->applyAutonomousTuning();

        $this->assertCount(1, $result['applied']);
        $change = $result['applied'][0];
        $this->assertSame('assistant', $change['surface']);
        $this->assertSame('draft_customer_reply', $change['action_type']);
        $this->assertSame(0.9, $change['applied_value']);

        $log = Database::queryOne("SELECT * FROM ai_threshold_tuning_log ORDER BY id DESC LIMIT 1");
        $this->assertSame('ai_action_min_confidence', $log['threshold_key']);
        $this->assertSame('1', (string) $log['applied_automatically']);
        $this->assertStringContainsString('Lowered threshold', $log['change_reason_summary']);
    }

    public function testAutonomousTuningSkipsWhenRuntimeControlPausesSurface(): void
    {
        Database::execute(
            "INSERT INTO ai_runtime_controls (workspace_id, surface, control_mode, reason, set_by, set_at)
             VALUES (?, 'autonomous_tuning', 'paused', 'Incident', 1, NOW())",
            [$this->workspaceId]
        );

        $service = new AIConfidenceCalibrationService();
        $result = $service->applyAutonomousTuning();

        $this->assertSame([], $result['applied']);
        $this->assertContains('runtime_control_paused', $result['skipped']);
    }
}
