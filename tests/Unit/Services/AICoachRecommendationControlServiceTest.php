<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AICoachRecommendationControlService;
use CRM\Services\WorkspaceContext;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AICoachRecommendationControlServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['coach-controls@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testCreatesBoostMuteAndResetControlsAndReturnsSummary(): void
    {
        $service = new AICoachRecommendationControlService();
        $boostId = $service->setControl('feedback_signature', 'Coach Sig One', 'boosted', $this->userId, 'trusted pattern');
        $muteId = $service->setControl('source_type', 'Marketplace Module', 'muted', $this->userId, 'too noisy');
        $resetId = $service->setControl('source_section', 'Priorities', 'reset_learning', $this->userId, 'fresh start');

        $this->assertGreaterThan(0, $boostId);
        $this->assertGreaterThan(0, $muteId);
        $this->assertGreaterThan(0, $resetId);

        $summary = $service->summary();
        $this->assertSame(3, $summary['total_controls']);
        $this->assertSame(1, $summary['counts']['boosted']);
        $this->assertSame(1, $summary['counts']['muted']);
        $this->assertSame(1, $summary['counts']['reset_learning']);
        $this->assertSame(1, $summary['by_scope']['feedback_signature']);

        $auditRows = Database::query(
            "SELECT * FROM audit_log WHERE action = 'ai_coach_control_created' AND entity_type = 'ai_coach_recommendation_control'"
        );
        $this->assertCount(3, $auditRows);
    }

    public function testDisablesControlAndAuditsChange(): void
    {
        $service = new AICoachRecommendationControlService();
        $controlId = $service->setControl('feedback_signature', 'coach_sig_disable', 'muted', $this->userId, 'test disable');

        $this->assertTrue($service->disableControl($controlId, $this->userId, 'no longer needed'));
        $this->assertSame([], $service->activeControls());

        $audit = Database::queryOne(
            "SELECT * FROM audit_log WHERE action = 'ai_coach_control_disabled' AND entity_id = ? LIMIT 1",
            [$controlId]
        );
        $this->assertNotNull($audit);
        $payload = json_decode((string) $audit['new_values'], true);
        $this->assertFalse((bool) ($payload['enabled'] ?? true));
        $this->assertSame($this->userId, (int) ($payload['actor_user_id'] ?? 0));
    }

    public function testValidatesUnsupportedScopeTypeAndValue(): void
    {
        $service = new AICoachRecommendationControlService();

        $this->expectException(\InvalidArgumentException::class);
        $service->setControl('unsupported', 'coach_sig', 'boosted', $this->userId);
    }

    public function testValidatesUnsupportedType(): void
    {
        $service = new AICoachRecommendationControlService();

        $this->expectException(\InvalidArgumentException::class);
        $service->setControl('feedback_signature', 'coach_sig', 'delete_forever', $this->userId);
    }

    public function testValidatesEmptyValue(): void
    {
        $service = new AICoachRecommendationControlService();

        $this->expectException(\InvalidArgumentException::class);
        $service->setControl('feedback_signature', '   ', 'boosted', $this->userId);
    }

    public function testScopesControlsByWorkspace(): void
    {
        $service = new AICoachRecommendationControlService();
        $service->setControl('feedback_signature', 'coach_sig_shared', 'boosted', $this->userId, 'workspace one');
        $this->createWorkspace(2, 'coach-two');
        $this->activateWorkspace(2);
        $service->setControl('feedback_signature', 'coach_sig_shared', 'muted', $this->userId, 'workspace two');

        $workspaceTwoSummary = $service->summary();
        $this->assertSame(1, $workspaceTwoSummary['total_controls']);
        $this->assertSame(1, $workspaceTwoSummary['counts']['muted']);

        $workspaceOneSummary = $service->summary(['workspace_id' => 1]);
        $this->assertSame(1, $workspaceOneSummary['total_controls']);
        $this->assertSame(1, $workspaceOneSummary['counts']['boosted']);
    }

    public function testReturnsRelevantControlsAndResetCutoffsForRecommendationCards(): void
    {
        $service = new AICoachRecommendationControlService();
        $service->setControl('feedback_signature', 'coach_sig_exact', 'boosted', $this->userId);
        $service->setControl('source_type', 'crm_activity', 'muted', $this->userId);
        $service->setControl('source_section', 'priorities', 'reset_learning', $this->userId);

        $recommendations = [
            'foundation_gaps' => [],
            'priorities' => [[
                'feedback_signature' => 'coach_sig_exact',
                'source_recommendation_type' => 'crm_activity',
            ]],
            'quick_wins' => [],
            'missing_features' => [],
        ];

        $controls = $service->controlsForRecommendations($recommendations);
        $this->assertCount(3, $controls['coach_sig_exact']);
        $cutoffs = $service->resetCutoffsBySignature($recommendations, $controls);
        $this->assertArrayHasKey('coach_sig_exact', $cutoffs);
    }

    private function createWorkspace(int $id, string $slug): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'active', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), 'Coach Workspace ' . $id, $slug]
        );
    }

    private function activateWorkspace(int $workspaceId): void
    {
        Session::set('active_workspace_id', $workspaceId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    }
}
