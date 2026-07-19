<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\MLModelManager;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class MLModelManagerTest extends DatabaseTestCase
{
    private MLModelManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new MLModelManager();
        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');
    }

    public function testGetActiveModelIsScopedToActiveWorkspace(): void
    {
        $workspaceOneModel = $this->createModel(1, 'conversion', 'workspace-one-v1', true);
        $workspaceTwoModel = $this->createModel(2, 'conversion', 'workspace-two-v1', true);

        $this->switchWorkspace(1);
        $activeOne = $this->manager->getActiveModel('conversion');
        $this->assertSame($workspaceOneModel, (int) ($activeOne['id'] ?? 0));

        $this->switchWorkspace(2);
        $activeTwo = $this->manager->getActiveModel('conversion');
        $this->assertSame($workspaceTwoModel, (int) ($activeTwo['id'] ?? 0));
    }

    public function testPromoteModelOnlyTouchesCurrentWorkspace(): void
    {
        $workspaceOneCurrent = $this->createModel(1, 'conversion', 'workspace-one-current', true);
        $workspaceOneCandidate = $this->createModel(1, 'conversion', 'workspace-one-next', false);
        $workspaceTwoModel = $this->createModel(2, 'conversion', 'workspace-two-current', true);

        $workspaceOneContact = $this->createContact(1, 'workspace-one@example.test', null);
        $workspaceTwoContact = $this->createContact(2, 'workspace-two@example.test', $workspaceTwoModel);

        $this->switchWorkspace(1);
        $this->manager->promoteModel($workspaceOneCandidate);

        $workspaceOneModels = Database::query(
            "SELECT id, is_active
             FROM ml_models
             WHERE workspace_id = 1
             ORDER BY id ASC"
        );
        $workspaceTwoModels = Database::query(
            "SELECT id, is_active
             FROM ml_models
             WHERE workspace_id = 2
             ORDER BY id ASC"
        );

        $this->assertSame(0, (int) $workspaceOneModels[0]['is_active']);
        $this->assertSame(1, (int) $workspaceOneModels[1]['is_active']);
        $this->assertSame(1, (int) $workspaceTwoModels[0]['is_active']);

        $workspaceOneContactRow = Database::queryOne(
            "SELECT ml_model_id FROM contacts WHERE id = ?",
            [$workspaceOneContact]
        );
        $workspaceTwoContactRow = Database::queryOne(
            "SELECT ml_model_id FROM contacts WHERE id = ?",
            [$workspaceTwoContact]
        );

        $this->assertSame($workspaceOneCandidate, (int) ($workspaceOneContactRow['ml_model_id'] ?? 0));
        $this->assertSame($workspaceTwoModel, (int) ($workspaceTwoContactRow['ml_model_id'] ?? 0));
        $this->assertNotSame($workspaceOneCurrent, $workspaceOneCandidate);
    }

    private function createModel(int $workspaceId, string $modelType, string $version, bool $isActive): int
    {
        Database::execute(
            "INSERT INTO ml_models
             (workspace_id, model_type, version, algorithm, model_data, feature_list, hyperparameters, is_active, trained_at)
             VALUES (?, ?, ?, 'logistic_regression', ?, '[]', '[]', ?, NOW())",
            [$workspaceId, $modelType, $version, json_encode(['coefficients' => [], 'bias' => 0.2]), $isActive ? 1 : 0]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(int $workspaceId, string $email, ?int $mlModelId): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, ml_model_id, created_at)
             VALUES (?, ?, 'ML', 'Contact', ?, ?, NOW())",
            [$workspaceId, uniqid('ml-contact-', true), $email, $mlModelId]
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
