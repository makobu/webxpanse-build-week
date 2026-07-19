<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\CacheManager;
use CRM\Modules\AILeadScoring;
use CRM\Services\MLPredictionService;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class MLPredictionServiceTest extends DatabaseTestCase
{
    private MLPredictionService $predictionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->predictionService = new MLPredictionService();
        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');
    }

    public function testPredictConversionCachesPredictionInActiveWorkspace(): void
    {
        $contactId = $this->createContact(1, 'workspace-one@example.test');
        $modelId = $this->createModel(1, 'workspace-one-v1', 0.35);

        $this->switchWorkspace(1);
        $prediction = $this->predictionService->predictConversion($contactId, 'conversion');

        $this->assertSame($modelId, (int) ($prediction['model_id'] ?? 0));

        $cached = Database::queryOne(
            "SELECT workspace_id, model_id
             FROM ml_predictions
             WHERE workspace_id = 1 AND contact_id = ?
             LIMIT 1",
            [$contactId]
        );

        $this->assertSame(1, (int) ($cached['workspace_id'] ?? 0));
        $this->assertSame($modelId, (int) ($cached['model_id'] ?? 0));
    }

    public function testPredictConversionRejectsForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'workspace-two@example.test');
        $this->createModel(1, 'workspace-one-v1', 0.15);

        $this->switchWorkspace(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact not found in the active workspace.');
        $this->predictionService->predictConversion($foreignContactId, 'conversion');
    }

    public function testPredictConversionReturnsModelMetadataFromDatabaseCache(): void
    {
        $contactId = $this->createContact(1, 'cached-workspace-one@example.test');
        $modelId = $this->createModel(1, 'workspace-one-cached-v1', 0.15);
        $this->createPrediction(1, $contactId, $modelId, 82.0, 0.82);
        $this->clearPredictionCache(1, $contactId);

        $this->switchWorkspace(1);
        $prediction = $this->predictionService->predictConversion($contactId, 'conversion');

        $this->assertTrue((bool) ($prediction['cached'] ?? false));
        $this->assertSame($modelId, (int) ($prediction['model_id'] ?? 0));
        $this->assertSame('workspace-one-cached-v1', (string) ($prediction['model_version'] ?? ''));
    }

    public function testCachedPredictionIsTreatedAsAvailableMlScore(): void
    {
        $contactId = $this->createContact(1, 'cached-available-ml@example.test');
        $modelId = $this->createModel(1, 'workspace-one-available-v1', 0.15);
        $this->createPrediction(1, $contactId, $modelId, 84.0, 0.84);
        $this->clearPredictionCache(1, $contactId);

        $this->switchWorkspace(1);
        $score = (new AILeadScoring())->calculateMLScore($contactId, 'conversion');

        $this->assertTrue((bool) ($score['available'] ?? false));
        $this->assertSame(84, (int) ($score['score'] ?? 0));
    }

    private function createModel(int $workspaceId, string $version, float $bias): int
    {
        Database::execute(
            "INSERT INTO ml_models
             (workspace_id, model_type, version, algorithm, model_data, feature_list, hyperparameters, is_active, trained_at)
             VALUES (?, 'conversion', ?, 'logistic_regression', ?, '[]', '[]', 1, NOW())",
            [$workspaceId, $version, json_encode(['coefficients' => [], 'bias' => $bias])]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, 'Predict', 'Contact', ?, NOW())",
            [$workspaceId, uniqid('ml-predict-contact-', true), $email]
        );

        return (int) Database::lastInsertId();
    }

    private function createPrediction(
        int $workspaceId,
        int $contactId,
        int $modelId,
        float $predictionScore,
        float $probability
    ): void {
        Database::execute(
            "INSERT INTO ml_predictions
             (workspace_id, contact_id, model_id, prediction_score, probability, confidence, top_factors, feature_values, cached_at, expires_at)
             VALUES (?, ?, ?, ?, ?, 0.8000, '[]', '{}', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$workspaceId, $contactId, $modelId, $predictionScore, $probability]
        );
    }

    private function clearPredictionCache(int $workspaceId, int $contactId): void
    {
        (new CacheManager())->delete("ml_prediction_{$workspaceId}_{$contactId}_conversion");
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
