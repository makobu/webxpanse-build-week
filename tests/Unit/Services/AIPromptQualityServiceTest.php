<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIPromptQualityService;
use CRM\Services\AIPromptRegistryService;
use CRM\Tests\DatabaseTestCase;

class AIPromptQualityServiceTest extends DatabaseTestCase
{
    public function testCanComparePromptVersionsUsingDiagnosticsAndOutcomes(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['prompt-quality@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $registry = new AIPromptRegistryService();
        $versionTwoId = $registry->registerPrompt([
            'surface' => 'coach',
            'prompt_key' => 'coach_recommendations',
            'status' => 'draft',
            'system_prompt_text' => 'Prompt v2',
            'instruction_text' => 'Instructions v2',
            'output_contract_json' => ['type' => 'json'],
            'created_by' => $userId,
        ]);
        $this->assertGreaterThan(0, $versionTwoId);
        $versionTwo = $registry->getPromptHistory('coach', 'coach_recommendations')[0]['version'];

        Database::execute(
            "INSERT INTO ai_guidance_runs
                (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json, created_at)
             VALUES (1, ?, 'coach', 'operations', 'allow', 0.95, 0.91, 0.88, ?, '{}', ?, NOW())",
            [
                $userId,
                json_encode(['prompt_key' => 'coach_recommendations', 'prompt_version' => 1]),
                json_encode([
                    'summary' => 'Coach response v1',
                    'prompt_key' => 'coach_recommendations',
                    'prompt_version' => 1,
                    'context_bundle_quality' => ['context_quality_score' => 0.92, 'warnings' => []],
                ]),
            ]
        );
        $runOne = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO ai_guidance_runs
                (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json, created_at)
             VALUES (1, ?, 'coach', 'guardian', 'blocked', 0.82, 0.70, 0.60, ?, '{}', ?, NOW())",
            [
                $userId,
                json_encode(['prompt_key' => 'coach_recommendations', 'prompt_version' => (int) $versionTwo]),
                json_encode([
                    'summary' => 'Coach response v2',
                    'prompt_key' => 'coach_recommendations',
                    'prompt_version' => (int) $versionTwo,
                    'context_bundle_quality' => ['context_quality_score' => 0.61, 'warnings' => ['stale_context_present', 'bundle_trimmed']],
                ]),
            ]
        );
        $runTwo = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO ai_decision_outcomes
                (workspace_id, guidance_run_id, user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score, policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at)
             VALUES (1, ?, ?, 'coach', 'advice', 'coach_recommendation', 0.95, 0.91, 0.88, 'allow', '{}', 'accepted', 1.0, '{}', NOW())",
            [$runOne, $userId]
        );
        Database::execute(
            "INSERT INTO ai_decision_outcomes
                (workspace_id, guidance_run_id, user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score, policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at)
             VALUES (1, ?, ?, 'coach', 'advice', 'coach_recommendation', 0.82, 0.70, 0.60, 'blocked', '{}', 'rejected', 0.0, '{}', NOW())",
            [$runTwo, $userId]
        );

        $service = new AIPromptQualityService();
        $comparison = $service->getPromptVersionComparison('coach', 'coach_recommendations', 1, (int) $versionTwo);

        $this->assertSame('coach', $comparison['surface']);
        $this->assertSame('coach_recommendations', $comparison['prompt_key']);
        $this->assertSame(1, $comparison['primary_version']);
        $this->assertSame((int) $versionTwo, $comparison['compare_version']);
        $this->assertGreaterThan((float) $comparison['compare']['recent_quality_score'], (float) $comparison['primary']['recent_quality_score']);
        $this->assertGreaterThan((float) $comparison['compare']['accepted_rate'], (float) $comparison['primary']['accepted_rate']);
        $this->assertGreaterThan(0.0, (float) $comparison['deltas']['quality_delta']);
        $this->assertGreaterThan(0.0, (float) $comparison['deltas']['accepted_rate_delta']);
        $this->assertContains($comparison['regression_risk'], ['low', 'medium', 'high']);
    }
}
