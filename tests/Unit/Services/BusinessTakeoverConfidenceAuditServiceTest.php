<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\BusinessTakeoverConfidenceAuditService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class BusinessTakeoverConfidenceAuditServiceTest extends DatabaseTestCase
{
    public function testAuditReturnsEnvironmentPrerequisiteWhenConfiguredDatabaseProbeFails(): void
    {
        $service = new class($this->healthyVerificationSnapshot(false, true), []) extends BusinessTakeoverConfidenceAuditService {
            public function __construct(
                private array $verification,
                private array $inputs,
                private int $subjectUserId = 42
            ) {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }

            protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
            {
                return $requestedUserId ?? $this->subjectUserId;
            }

            protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
            {
                return $this->inputs;
            }
        };

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => 42,
        ]);

        $this->assertSame('block', $audit['overall_status']);
        $this->assertSame('environment_prerequisite', $audit['failure_class']);
        $this->assertFalse($audit['takeover_ready']);
        $this->assertSame(2, BusinessTakeoverConfidenceAuditService::exitCodeFor($audit));
        $this->assertSame('block', $audit['domains']['runtime']['status']);
        $this->assertTrue((bool) ($audit['verification_snapshot']['transient_environment_risk'] ?? false));
        $this->assertSame(
            'block',
            $this->gateStatus($audit['hard_gates'], 'database_connection')
        );
    }

    public function testAuditReturnsProductRegressionWhenGovernanceAndCustomerFacingGatesFail(): void
    {
        $inputs = $this->healthyInputs();
        $inputs['active_incidents'] = [
            ['severity' => 'critical', 'status' => 'open'],
        ];
        $inputs['domain_rollouts']['commercial_mvp']['rollout_state'] = 'not_ready';
        $inputs['domain_rollouts']['customer_thread']['rollout_state'] = 'paused';

        $service = new class($this->healthyVerificationSnapshot(), $inputs) extends BusinessTakeoverConfidenceAuditService {
            public function __construct(
                private array $verification,
                private array $inputs,
                private int $subjectUserId = 7
            ) {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }

            protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
            {
                return $requestedUserId ?? $this->subjectUserId;
            }

            protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
            {
                return $this->inputs;
            }
        };

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => 7,
        ]);

        $this->assertSame('block', $audit['overall_status']);
        $this->assertSame('product_regression', $audit['failure_class']);
        $this->assertFalse($audit['takeover_ready']);
        $this->assertSame('pass', $audit['domains']['runtime']['status']);
        $this->assertSame('block', $audit['domains']['customer_facing']['status']);
        $this->assertSame('block', $audit['domains']['governance']['status']);
        $this->assertSame(3, BusinessTakeoverConfidenceAuditService::exitCodeFor($audit));
        $this->assertSame(
            'block',
            $this->gateStatus($audit['hard_gates'], 'customer_facing_ready')
        );
        $this->assertSame(
            'block',
            $this->gateStatus($audit['hard_gates'], 'governance_clear')
        );
    }

    public function testAuditHardStopsWhenFoundationsAndLearningEvidenceMissMinimumGates(): void
    {
        $inputs = $this->healthyInputs();
        $inputs['battery']['layers']['setup'] = [
            'score' => 58,
            'blockers' => ['Complete company profile'],
            'signals' => ['Product pricing ready'],
            'is_complete' => false,
            'completed_count' => 4,
            'total_count' => 7,
        ];
        $inputs['battery']['layers']['learning'] = [
            'score' => 45,
            'blockers' => ['Learning history is still too thin'],
            'signals' => [],
        ];
        $inputs['learning_counts'] = [
            'decision_outcomes' => 8,
            'demonstrations' => 4,
        ];
        $inputs['calibration_summary'] = [
            'summary' => [
                'commercial' => [
                    'send_document' => ['precision_at_current_threshold' => 0.74],
                ],
            ],
        ];

        $service = new class($this->healthyVerificationSnapshot(), $inputs) extends BusinessTakeoverConfidenceAuditService {
            public function __construct(
                private array $verification,
                private array $inputs,
                private int $subjectUserId = 11
            ) {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }

            protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
            {
                return $requestedUserId ?? $this->subjectUserId;
            }

            protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
            {
                return $this->inputs;
            }
        };

        $audit = $service->audit([
            'scope' => 'internal_ops',
            'user_id' => 11,
        ]);

        $this->assertSame('block', $audit['overall_status']);
        $this->assertSame('product_regression', $audit['failure_class']);
        $this->assertFalse($audit['takeover_ready']);
        $this->assertArrayHasKey('internal_ops', $audit['domains']);
        $this->assertArrayNotHasKey('customer_facing', $audit['domains']);
        $this->assertSame('block', $audit['domains']['foundations']['status']);
        $this->assertSame('block', $audit['domains']['learning']['status']);
        $this->assertSame(
            'block',
            $this->gateStatus($audit['hard_gates'], 'foundations_ready')
        );
        $this->assertSame(
            'block',
            $this->gateStatus($audit['hard_gates'], 'learning_ready')
        );
    }

    public function testAuditActivatesResolvedWorkspaceAndRestoresPreviousRuntimeContext(): void
    {
        $userId = $this->createAuditUser('takeover-workspace@example.test');
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Takeover Workspace Two', 'takeover-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $userId]
        );

        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');
        $this->assertSame(1, (int) WorkspaceContext::currentWorkspaceId());

        $inputs = $this->healthyInputs();
        $service = new class($this->healthyVerificationSnapshot(), $inputs, $workspaceTwoId, $userId) extends BusinessTakeoverConfidenceAuditService {
            public ?int $observedWorkspaceId = null;

            public function __construct(
                private array $verification,
                private array $inputs,
                private int $workspaceId,
                private int $subjectUserId
            ) {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }

            protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
            {
                return $requestedUserId ?? $this->subjectUserId;
            }

            protected function resolveAuditWorkspaceId(int $subjectUserId, ?int $requestedWorkspaceId = null): int
            {
                return $this->workspaceId;
            }

            protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
            {
                $this->observedWorkspaceId = (int) WorkspaceContext::currentWorkspaceId();
                return $this->inputs;
            }
        };

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => $userId,
        ]);

        $this->assertSame($workspaceTwoId, $service->observedWorkspaceId);
        $this->assertSame(1, (int) WorkspaceContext::currentWorkspaceId());
        $this->assertSame('pass', $audit['overall_status']);
    }

    public function testAuditBlocksAndRestoresRuntimeWhenResolvedWorkspaceCannotBeActivated(): void
    {
        $userId = $this->createAuditUser('takeover-missing-workspace@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $service = new class($this->healthyVerificationSnapshot(), $userId) extends BusinessTakeoverConfidenceAuditService {
            public bool $collected = false;

            public function __construct(
                private array $verification,
                private int $subjectUserId
            ) {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }

            protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
            {
                return $requestedUserId ?? $this->subjectUserId;
            }

            protected function resolveAuditWorkspaceId(int $subjectUserId, ?int $requestedWorkspaceId = null): int
            {
                return 999999;
            }

            protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
            {
                $this->collected = true;
                return [];
            }
        };

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => $userId,
        ]);

        $this->assertFalse($service->collected);
        $this->assertSame(1, (int) WorkspaceContext::currentWorkspaceId());
        $this->assertSame('environment_prerequisite', $audit['failure_class']);
        $this->assertSame('workspace_context_unavailable', (string) ($audit['verification_snapshot']['workspace_context_error'] ?? ''));
        $this->assertSame('block', $this->gateStatus($audit['hard_gates'], 'workspace_context'));
    }

    private function healthyVerificationSnapshot(bool $configuredSuccess = true, bool $transientRisk = false): array
    {
        return [
            'generated_at' => '2026-04-08T12:00:00+03:00',
            'database_probes' => [
                'configured' => [
                    'success' => $configuredSuccess,
                    'host' => 'localhost',
                    'message' => $configuredSuccess ? 'Connection succeeded.' : 'SQLSTATE[HY000] [2002] Connection refused',
                ],
                'localhost' => [
                    'success' => true,
                    'host' => 'localhost',
                    'message' => 'Connection succeeded.',
                ],
                'loopback' => [
                    'success' => true,
                    'host' => '127.0.0.1',
                    'message' => 'Connection succeeded.',
                ],
            ],
            'provider_probe' => [
                'core_ai_provider_ready' => true,
                'message' => 'Provider ready.',
            ],
            'transient_environment_risk' => $transientRisk,
        ];
    }

    private function createAuditUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('takeover-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function healthyInputs(): array
    {
        $domainEvaluation = [
            'score' => 91,
            'status' => 'pass',
            'blockers' => [],
            'signals' => ['Healthy replay sample is available.'],
            'metrics' => ['sample_size' => 12],
            'summary' => [],
        ];

        return [
            'battery' => [
                'layers' => [
                    'setup' => [
                        'score' => 100,
                        'blockers' => [],
                        'signals' => ['Setup is complete'],
                        'is_complete' => true,
                        'completed_count' => 7,
                        'total_count' => 7,
                    ],
                    'learning' => [
                        'score' => 86,
                        'blockers' => [],
                        'signals' => ['Learning evidence is healthy'],
                    ],
                    'autoresponder' => [
                        'score' => 88,
                        'blockers' => [],
                        'signals' => ['Recent auto-sent replies detected'],
                    ],
                    'commercial' => [
                        'score' => 89,
                        'blockers' => [],
                        'signals' => ['Recent commercial automation runs detected'],
                    ],
                    'workflow_automation' => [
                        'score' => 90,
                        'blockers' => [],
                        'signals' => ['Recent workflow automation proposals detected'],
                    ],
                    'deal' => [
                        'score' => 87,
                        'blockers' => [],
                        'signals' => ['Deal automation readiness checks are passing'],
                    ],
                ],
            ],
            'provider_probe' => [
                'core_ai_provider_ready' => true,
                'message' => 'Provider ready.',
            ],
            'job_summary' => [
                'healthy' => 2,
                'failed' => 0,
                'stale' => 0,
                'running' => 0,
                'total' => 2,
            ],
            'job_health' => [],
            'active_incidents' => [],
            'required_tables' => array_map(
                static fn(string $table): array => ['table' => $table, 'exists' => true],
                [
                    'ai_autonomy_domain_controls',
                    'ai_autonomy_eval_runs',
                    'ai_decision_outcomes',
                    'ai_operator_demonstrations',
                    'automation_job_health',
                    'ai_autonomy_incidents',
                    'ai_autonomy_recovery_queue',
                ]
            ),
            'learning_counts' => [
                'decision_outcomes' => 20,
                'demonstrations' => 14,
            ],
            'domain_evaluations' => [
                'commercial_mvp' => $domainEvaluation,
                'customer_thread' => $domainEvaluation,
                'workflow_execution' => $domainEvaluation,
                'deal_followthrough' => $domainEvaluation,
                'task_followthrough' => $domainEvaluation,
            ],
            'domain_rollouts' => [
                'commercial_mvp' => ['rollout_state' => 'ready'],
                'customer_thread' => ['rollout_state' => 'ready'],
                'workflow_execution' => ['rollout_state' => 'ready'],
                'deal_followthrough' => ['rollout_state' => 'ready'],
                'task_followthrough' => ['rollout_state' => 'ready'],
            ],
            'calibration_summary' => [
                'summary' => [
                    'commercial' => [
                        'send_document' => ['precision_at_current_threshold' => 0.96],
                    ],
                    'workflow' => [
                        'create_task' => ['precision_at_current_threshold' => 0.93],
                    ],
                ],
            ],
            'config_state' => [],
            'scope' => 'all',
        ];
    }

    private function gateStatus(array $hardGates, string $key): ?string
    {
        foreach ($hardGates as $gate) {
            if (($gate['key'] ?? null) === $key) {
                return (string) ($gate['status'] ?? 'block');
            }
        }

        return null;
    }
}
