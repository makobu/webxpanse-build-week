<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\MLOutcomeTracker;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class MLOutcomeTrackerTest extends DatabaseTestCase
{
    private MLOutcomeTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new MLOutcomeTracker();
        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');
    }

    public function testRecordConversionStoresWorkspaceScopedOutcome(): void
    {
        $contactId = $this->createContact(1, 'workspace-one@example.test');
        $modelId = $this->createModel(1, 'workspace-one-v1');
        $predictionId = $this->createPrediction(1, $contactId, $modelId, 0.82);

        $this->switchWorkspace(1);
        $this->tracker->recordConversion($contactId, ['conversion_date' => '2026-04-01']);

        $outcome = Database::queryOne(
            "SELECT workspace_id, prediction_id, model_id, outcome_type, actual_outcome
             FROM ml_prediction_outcomes
             WHERE workspace_id = 1 AND prediction_id = ?
             LIMIT 1",
            [$predictionId]
        );

        $this->assertSame(1, (int) ($outcome['workspace_id'] ?? 0));
        $this->assertSame('conversion', (string) ($outcome['outcome_type'] ?? ''));
        $this->assertSame(1, (int) ($outcome['actual_outcome'] ?? 0));
    }

    public function testPredictionAccuracyOnlyUsesActiveWorkspaceRows(): void
    {
        $contactOne = $this->createContact(1, 'workspace-one@example.test');
        $contactTwo = $this->createContact(2, 'workspace-two@example.test');
        $modelOne = $this->createModel(1, 'workspace-one-v1');
        $modelTwo = $this->createModel(2, 'workspace-two-v1');
        $predictionOne = $this->createPrediction(1, $contactOne, $modelOne, 0.91);
        $predictionTwo = $this->createPrediction(2, $contactTwo, $modelTwo, 0.14);

        Database::execute(
            "INSERT INTO ml_prediction_outcomes
             (workspace_id, prediction_id, contact_id, model_id, predicted_probability, actual_outcome, outcome_type, outcome_date, prediction_error, validated_at)
             VALUES
             (1, ?, ?, ?, 0.9100, 1, 'conversion', '2026-04-10', 0.0900, NOW()),
             (2, ?, ?, ?, 0.1400, 1, 'conversion', '2026-04-10', 0.8600, NOW())",
            [$predictionOne, $contactOne, $modelOne, $predictionTwo, $contactTwo, $modelTwo]
        );

        $this->switchWorkspace(1);
        $accuracy = $this->tracker->getPredictionAccuracy('conversion');

        $this->assertSame(1, (int) ($accuracy['overall']['total_validated'] ?? 0));
        $this->assertSame(1.0, (float) ($accuracy['overall']['accuracy'] ?? 0));
    }

    private function createModel(int $workspaceId, string $version): int
    {
        Database::execute(
            "INSERT INTO ml_models
             (workspace_id, model_type, version, algorithm, model_data, feature_list, hyperparameters, is_active, trained_at)
             VALUES (?, 'conversion', ?, 'logistic_regression', ?, '[]', '[]', 1, NOW())",
            [$workspaceId, $version, json_encode(['coefficients' => [], 'bias' => 0.4])]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, 'Outcome', 'Contact', ?, NOW())",
            [$workspaceId, uniqid('ml-outcome-contact-', true), $email]
        );

        return (int) Database::lastInsertId();
    }

    private function createPrediction(int $workspaceId, int $contactId, int $modelId, float $probability): int
    {
        Database::execute(
            "INSERT INTO ml_predictions
             (workspace_id, contact_id, model_id, prediction_score, probability, confidence, top_factors, feature_values, cached_at, expires_at)
             VALUES (?, ?, ?, ?, ?, 0.8000, '[]', '{}', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$workspaceId, $contactId, $modelId, $probability * 100, $probability]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function switchWorkspace(int $workspaceId): void
    {
        Session::set('active_workspace_id', $workspaceId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    }
}
