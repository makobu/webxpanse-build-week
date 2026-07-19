<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;
use PDO;
use Throwable;

class BusinessTakeoverConfidenceAuditService
{
    private const EXIT_CODES = [
        'pass' => 0,
        'environment_prerequisite' => 2,
        'product_regression' => 3,
        'mixed' => 4,
    ];

    private const DOMAIN_KEYS = [
        'commercial_mvp',
        'customer_thread',
        'workflow_execution',
        'deal_followthrough',
        'task_followthrough',
    ];

    private const REQUIRED_TABLES = [
        'ai_autonomy_domain_controls',
        'ai_autonomy_eval_runs',
        'ai_decision_outcomes',
        'ai_operator_demonstrations',
        'automation_job_health',
        'ai_autonomy_incidents',
        'ai_autonomy_recovery_queue',
    ];

    private AutomationBatteryService $battery;
    private AIAutonomyEvaluationService $evaluation;
    private AIAutonomyRolloutOperationsService $rollout;
    private AIProviderProbeService $providerProbe;
    private AIAutonomyScenarioReplayService $scenarioReplay;
    private AutomationJobHealthService $jobHealth;
    private AIAutonomyIncidentService $incidents;
    private AIConfidenceCalibrationService $calibration;
    private AIAutoResponderConfig $autoResponderConfig;
    private CommercialAutomationConfig $commercialConfig;
    private DealAutomationConfig $dealConfig;
    private AIWorkspaceScopeService $workspaceScope;
    private $databaseProbe;

    public function __construct(
        ?AutomationBatteryService $battery = null,
        ?AIAutonomyEvaluationService $evaluation = null,
        ?AIAutonomyRolloutOperationsService $rollout = null,
        ?AIProviderProbeService $providerProbe = null,
        ?AIAutonomyScenarioReplayService $scenarioReplay = null,
        ?AutomationJobHealthService $jobHealth = null,
        ?AIAutonomyIncidentService $incidents = null,
        ?AIConfidenceCalibrationService $calibration = null,
        ?AIAutoResponderConfig $autoResponderConfig = null,
        ?CommercialAutomationConfig $commercialConfig = null,
        ?DealAutomationConfig $dealConfig = null,
        $databaseProbe = null
    ) {
        $this->battery = $battery ?? new AutomationBatteryService();
        $this->evaluation = $evaluation ?? new AIAutonomyEvaluationService();
        $this->rollout = $rollout ?? new AIAutonomyRolloutOperationsService();
        $this->providerProbe = $providerProbe ?? new AIProviderProbeService();
        $this->scenarioReplay = $scenarioReplay ?? new AIAutonomyScenarioReplayService($this->evaluation);
        $this->jobHealth = $jobHealth ?? new AutomationJobHealthService();
        $this->incidents = $incidents ?? new AIAutonomyIncidentService();
        $this->calibration = $calibration ?? new AIConfidenceCalibrationService();
        $this->autoResponderConfig = $autoResponderConfig ?? new AIAutoResponderConfig();
        $this->commercialConfig = $commercialConfig ?? new CommercialAutomationConfig();
        $this->dealConfig = $dealConfig ?? new DealAutomationConfig();
        $this->workspaceScope = new AIWorkspaceScopeService();
        $this->databaseProbe = $databaseProbe;
    }

    public function audit(array $options = []): array
    {
        $scope = $this->normalizeScope((string) ($options['scope'] ?? 'all'));
        $requestedUserId = isset($options['user_id']) ? max(0, (int) $options['user_id']) : null;
        $dbConfig = (array) ($options['db_config'] ?? []);
        $verification = $this->buildVerificationSnapshot($dbConfig);

        if (!empty($dbConfig) && empty($options['skip_database_init']) && !empty($verification['database_probes']['configured']['success'])) {
            Database::close();
            Database::init($dbConfig);
        }

        $subjectUserId = $this->resolveSubjectUserId($requestedUserId, !empty($verification['database_probes']['configured']['success']));
        $workspaceId = !empty($verification['database_probes']['configured']['success'])
            ? $this->resolveAuditWorkspaceId($subjectUserId, isset($options['workspace_id']) ? (int) $options['workspace_id'] : null)
            : max(1, (int) ($options['workspace_id'] ?? 1));
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $verification['scope'] = $scope;
        $verification['subject_user_id'] = $subjectUserId;
        $verification['workspace_id'] = $workspaceId;
        $verification['tenant_key'] = $tenantKey;

        if (empty($verification['database_probes']['configured']['success'])) {
            return $this->buildEnvironmentBlockedResult($scope, $subjectUserId, $tenantKey, $verification);
        }

        $runtimeSnapshot = WorkspaceContext::runtimeSnapshot();
        $activatedWorkspace = WorkspaceContext::activateRuntimeWorkspace(
            $workspaceId,
            $subjectUserId > 0 ? $subjectUserId : null
        );
        if ($activatedWorkspace === null) {
            WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
            $verification['workspace_context_error'] = 'workspace_context_unavailable';
            return $this->buildWorkspaceContextBlockedResult($scope, $subjectUserId, $tenantKey, $verification);
        }

        try {
            $inputs = $this->collectAuditInputs($tenantKey, $subjectUserId, $scope, $verification);
            return $this->composeAuditResult($scope, $subjectUserId, $tenantKey, $verification, $inputs);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
        }
    }

    public static function exitCodeFor(array $audit): int
    {
        if (!empty($audit['takeover_ready'])) {
            return self::EXIT_CODES['pass'];
        }

        $failureClass = (string) ($audit['failure_class'] ?? 'mixed');
        return self::EXIT_CODES[$failureClass] ?? self::EXIT_CODES['mixed'];
    }

    protected function collectAuditInputs(string $tenantKey, int $subjectUserId, string $scope, array $verification): array
    {
        $workspaceId = max(1, (int) ($verification['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId($tenantKey)));
        $battery = $subjectUserId > 0
            ? $this->battery->getStatus($subjectUserId, $subjectUserId)
            : $this->emptyBatteryStatus();
        $jobSummary = $this->jobHealth->getSummary();
        $jobHealth = $this->jobHealth->getJobs();
        $activeIncidents = $this->collectActiveIncidents($tenantKey);
        $requiredTables = $this->listRequiredTablePresence();
        $learningCounts = [
            'decision_outcomes' => $subjectUserId > 0 ? $this->countWorkspaceRows('ai_decision_outcomes', 'measured_at', 30, $workspaceId, 'user_id = ?', [$subjectUserId]) : 0,
            'demonstrations' => $subjectUserId > 0 ? $this->countWorkspaceRows('ai_operator_demonstrations', 'created_at', 30, $workspaceId, 'actor_user_id = ?', [$subjectUserId]) : 0,
        ];

        $domainEvaluations = [];
        $domainRollouts = [];
        foreach (self::DOMAIN_KEYS as $domainKey) {
            $domainEvaluations[$domainKey] = $this->evaluateDomain($tenantKey, $domainKey);
            $domainRollouts[$domainKey] = $this->rollout->computeReadiness($tenantKey, $domainKey);
        }

        return [
            'battery' => $battery,
            'provider_probe' => (array) ($verification['provider_probe'] ?? []),
            'job_summary' => $jobSummary,
            'job_health' => $jobHealth,
            'active_incidents' => $activeIncidents,
            'required_tables' => $requiredTables,
            'learning_counts' => $learningCounts,
            'domain_evaluations' => $domainEvaluations,
            'domain_rollouts' => $domainRollouts,
            'calibration_summary' => $this->calibration->getCalibrationSummary([
                'date_from' => date('Y-m-d', strtotime('-30 days')),
                'date_to' => date('Y-m-d'),
                'user_id' => $subjectUserId > 0 ? $subjectUserId : null,
            ]),
            'config_state' => [
                'autoresponder' => $this->autoResponderConfig->get(),
                'commercial' => $this->commercialConfig->get(),
                'deal' => $this->dealConfig->get(),
            ],
            'scope' => $scope,
        ];
    }

    protected function buildVerificationSnapshot(array $dbConfig): array
    {
        $configuredHost = trim((string) ($dbConfig['host'] ?? ''));
        $dbUser = (string) ($dbConfig['user'] ?? '');
        $dbPass = (string) ($dbConfig['pass'] ?? '');

        $configuredProbe = $configuredHost !== ''
            ? $this->probeDatabaseHost($configuredHost, $dbUser, $dbPass)
            : ['success' => false, 'message' => 'Database host is not configured.', 'host' => ''];
        $localhostProbe = $this->probeDatabaseHost('localhost', $dbUser, $dbPass);
        $loopbackProbe = $this->probeDatabaseHost('127.0.0.1', $dbUser, $dbPass);
        $providerProbe = $this->providerProbe->probe(false);

        return [
            'generated_at' => date('c'),
            'database_probes' => [
                'configured' => $configuredProbe,
                'localhost' => $localhostProbe,
                'loopback' => $loopbackProbe,
            ],
            'provider_probe' => $providerProbe,
            'transient_environment_risk' => $this->hasTransientProbeMismatch($configuredProbe, $localhostProbe, $loopbackProbe),
        ];
    }

    protected function resolveSubjectUserId(?int $requestedUserId, bool $databaseReady): int
    {
        if (!$databaseReady) {
            return max(0, (int) ($requestedUserId ?? 0));
        }
        if (($requestedUserId ?? 0) > 0) {
            return (int) $requestedUserId;
        }

        $row = Database::queryOne(
            "SELECT id FROM users ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id ASC LIMIT 1"
        );

        return (int) ($row['id'] ?? 0);
    }

    protected function resolveAuditWorkspaceId(int $subjectUserId, ?int $requestedWorkspaceId = null): int
    {
        try {
            if ($requestedWorkspaceId !== null && $requestedWorkspaceId > 0) {
                return $this->workspaceScope->requireWorkspaceId(null, $requestedWorkspaceId);
            }

            if ($subjectUserId > 0) {
                $workspaceId = $this->workspaceScope->resolveWorkspaceIdFromTenantKey('user:' . $subjectUserId);
                if ($workspaceId !== null && $workspaceId > 0) {
                    return $workspaceId;
                }
            }

            return $this->workspaceScope->requireWorkspaceId();
        } catch (\Throwable $e) {
            return max(1, (int) ($requestedWorkspaceId ?? 1));
        }
    }

    protected function buildEnvironmentBlockedResult(string $scope, int $subjectUserId, string $tenantKey, array $verification): array
    {
        $domains = [
            'foundations' => $this->makeDomain('Foundations', 0, 'block', ['Database connectivity is required before setup readiness can be audited.'], [], 'product_regression'),
            'learning' => $this->makeDomain('Learning', 0, 'block', ['Database connectivity is required before learning evidence can be audited.'], [], 'product_regression'),
            'customer_facing' => $this->makeDomain('Customer Facing', 0, 'block', ['Database connectivity is required before customer-facing automation can be audited.'], [], 'product_regression'),
            'internal_ops' => $this->makeDomain('Internal Ops', 0, 'block', ['Database connectivity is required before internal automation can be audited.'], [], 'product_regression'),
            'runtime' => $this->makeDomain('Runtime', 0, 'block', ['Configured database host is not reachable from PHP.'], $this->runtimeSignalsFromVerification($verification), 'environment_prerequisite'),
            'governance' => $this->makeDomain('Governance', 0, 'block', ['Database connectivity is required before rollout governance can be audited.'], [], 'product_regression'),
        ];

        $hardGates = [
            $this->gate('database_connection', 'Configured database host is reachable', 'block', 'environment_prerequisite', (string) ($verification['database_probes']['configured']['message'] ?? 'Database probe failed.')),
            $this->gate(
                'provider_configuration',
                'Core AI provider is configured',
                !empty($verification['provider_probe']['core_ai_provider_ready']) ? 'pass' : 'block',
                'environment_prerequisite',
                (string) ($verification['provider_probe']['message'] ?? 'AI provider configuration is missing.')
            ),
        ];

        return [
            'generated_at' => (string) ($verification['generated_at'] ?? date('c')),
            'scope' => $scope,
            'subject_user_id' => $subjectUserId,
            'tenant_key' => $tenantKey,
            'overall_status' => 'block',
            'failure_class' => 'environment_prerequisite',
            'platform_score' => 0,
            'takeover_ready' => false,
            'domains' => $this->filterDomainsForScope($domains, $scope),
            'hard_gates' => $hardGates,
            'recommended_actions' => $this->dedupeActions([
                'Restore PHP database connectivity for the configured host before trusting takeover readiness results.',
                'Compare the configured host probe with the localhost and 127.0.0.1 probes to isolate transient connection drift.',
                !empty($verification['provider_probe']['core_ai_provider_ready']) ? '' : 'Configure the core AI provider credentials so runtime readiness can be measured.',
            ]),
            'verification_snapshot' => $verification,
        ];
    }

    protected function buildWorkspaceContextBlockedResult(string $scope, int $subjectUserId, string $tenantKey, array $verification): array
    {
        $domains = [
            'foundations' => $this->makeDomain('Foundations', 0, 'block', ['Workspace context is required before setup readiness can be audited.'], [], 'environment_prerequisite'),
            'learning' => $this->makeDomain('Learning', 0, 'block', ['Workspace context is required before learning evidence can be audited.'], [], 'environment_prerequisite'),
            'customer_facing' => $this->makeDomain('Customer Facing', 0, 'block', ['Workspace context is required before customer-facing automation can be audited.'], [], 'environment_prerequisite'),
            'internal_ops' => $this->makeDomain('Internal Ops', 0, 'block', ['Workspace context is required before internal automation can be audited.'], [], 'environment_prerequisite'),
            'runtime' => $this->makeDomain('Runtime', 0, 'block', ['Resolved audit workspace could not be activated.'], $this->runtimeSignalsFromVerification($verification), 'environment_prerequisite'),
            'governance' => $this->makeDomain('Governance', 0, 'block', ['Workspace context is required before rollout governance can be audited.'], [], 'environment_prerequisite'),
        ];

        $hardGates = [
            $this->gate(
                'workspace_context',
                'Resolved workspace context can be activated',
                'block',
                'environment_prerequisite',
                'Resolved audit workspace could not be activated for takeover scoring.'
            ),
            $this->gate(
                'database_connection',
                'Configured database host is reachable',
                !empty($verification['database_probes']['configured']['success']) ? 'pass' : 'block',
                'environment_prerequisite',
                (string) ($verification['database_probes']['configured']['message'] ?? 'Database probe failed.')
            ),
            $this->gate(
                'provider_configuration',
                'Core AI provider is configured',
                !empty($verification['provider_probe']['core_ai_provider_ready']) ? 'pass' : 'block',
                'environment_prerequisite',
                (string) ($verification['provider_probe']['message'] ?? 'AI provider configuration is missing.')
            ),
        ];

        return [
            'generated_at' => (string) ($verification['generated_at'] ?? date('c')),
            'scope' => $scope,
            'subject_user_id' => $subjectUserId,
            'tenant_key' => $tenantKey,
            'overall_status' => 'block',
            'failure_class' => 'environment_prerequisite',
            'platform_score' => 0,
            'takeover_ready' => false,
            'domains' => $this->filterDomainsForScope($domains, $scope),
            'hard_gates' => $hardGates,
            'recommended_actions' => $this->dedupeActions([
                'Repair the resolved workspace record before trusting takeover readiness results.',
                'Confirm the audit subject still has an active workspace membership.',
            ]),
            'verification_snapshot' => $verification,
        ];
    }

    protected function composeAuditResult(string $scope, int $subjectUserId, string $tenantKey, array $verification, array $inputs): array
    {
        $domains = [
            'foundations' => $this->buildFoundationsDomain($inputs),
            'learning' => $this->buildLearningDomain($inputs),
            'customer_facing' => $this->buildCustomerFacingDomain($inputs),
            'internal_ops' => $this->buildInternalOpsDomain($inputs),
            'runtime' => $this->buildRuntimeDomain($verification, $inputs),
            'governance' => $this->buildGovernanceDomain($inputs),
        ];
        $domains = $this->filterDomainsForScope($domains, $scope);

        $hardGates = $this->buildHardGates($domains, $verification, $inputs);
        $failedGateClasses = array_values(array_unique(array_map(
            static fn(array $gate): string => (string) ($gate['classification'] ?? 'mixed'),
            array_filter($hardGates, static fn(array $gate): bool => (string) ($gate['status'] ?? 'block') !== 'pass')
        )));

        $takeoverReady = $failedGateClasses === [];
        $failureClass = $takeoverReady
            ? 'none'
            : ($failedGateClasses === ['environment_prerequisite']
                ? 'environment_prerequisite'
                : ($failedGateClasses === ['product_regression'] ? 'product_regression' : 'mixed'));

        return [
            'generated_at' => (string) ($verification['generated_at'] ?? date('c')),
            'scope' => $scope,
            'subject_user_id' => $subjectUserId,
            'tenant_key' => $tenantKey,
            'overall_status' => $takeoverReady ? 'pass' : 'block',
            'failure_class' => $failureClass,
            'platform_score' => $this->computePlatformScore($domains),
            'takeover_ready' => $takeoverReady,
            'domains' => $domains,
            'hard_gates' => $hardGates,
            'recommended_actions' => $this->buildRecommendedActions($domains, $hardGates, $verification, $inputs),
            'verification_snapshot' => $verification,
        ];
    }

    private function buildFoundationsDomain(array $inputs): array
    {
        $setup = (array) (($inputs['battery']['layers']['setup'] ?? []));
        $missingTables = array_values(array_map(
            static fn(array $row): string => (string) $row['table'],
            array_filter((array) ($inputs['required_tables'] ?? []), static fn(array $row): bool => empty($row['exists']))
        ));
        $blockers = array_values(array_filter(array_merge(
            array_map('strval', (array) ($setup['blockers'] ?? [])),
            $missingTables !== [] ? ['Missing required audit tables: ' . implode(', ', $missingTables)] : []
        )));
        $signals = array_values(array_filter(array_merge(
            array_map('strval', (array) ($setup['signals'] ?? [])),
            $missingTables === [] ? ['Required audit tables are present'] : []
        )));

        $score = (int) ($setup['score'] ?? 0);
        if ($missingTables !== []) {
            $score = max(0, $score - min(40, count($missingTables) * 8));
        }

        return $this->makeDomain(
            'Foundations',
            $score,
            (!empty($setup['is_complete']) && $missingTables === []) ? 'pass' : 'block',
            $blockers,
            $signals,
            'product_regression',
            [
                'completed_count' => (int) ($setup['completed_count'] ?? 0),
                'total_count' => (int) ($setup['total_count'] ?? 0),
                'missing_tables' => $missingTables,
            ]
        );
    }

    private function buildLearningDomain(array $inputs): array
    {
        $learning = (array) (($inputs['battery']['layers']['learning'] ?? []));
        $counts = (array) ($inputs['learning_counts'] ?? []);
        $averagePrecision = $this->averageCalibrationPrecision((array) ($inputs['calibration_summary']['summary'] ?? []));
        $blockers = array_values(array_filter(array_merge(
            array_map('strval', (array) ($learning['blockers'] ?? [])),
            ((int) ($counts['decision_outcomes'] ?? 0) < 15) ? ['Recent AI outcome history is below the minimum gate of 15.'] : [],
            ((int) ($counts['demonstrations'] ?? 0) < 10) ? ['Recent operator demonstrations are below the minimum gate of 10.'] : [],
            ($averagePrecision > 0.0 && $averagePrecision < 0.80) ? ['Average calibration precision is below the minimum gate of 0.80.'] : []
        )));
        $signals = array_values(array_filter(array_merge(
            array_map('strval', (array) ($learning['signals'] ?? [])),
            ((int) ($counts['decision_outcomes'] ?? 0) >= 15) ? ['Outcome history meets the minimum gate.'] : [],
            ((int) ($counts['demonstrations'] ?? 0) >= 10) ? ['Operator demonstrations meet the minimum gate.'] : [],
            ($averagePrecision >= 0.80) ? ['Average calibration precision is above the minimum gate.'] : []
        )));

        $status = ((int) ($learning['score'] ?? 0) >= 60)
            && (int) ($counts['decision_outcomes'] ?? 0) >= 15
            && (int) ($counts['demonstrations'] ?? 0) >= 10
            && ($averagePrecision === 0.0 || $averagePrecision >= 0.80)
            ? 'pass'
            : 'block';

        return $this->makeDomain(
            'Learning',
            (int) ($learning['score'] ?? 0),
            $status,
            $blockers,
            $signals,
            'product_regression',
            [
                'decision_outcomes_30d' => (int) ($counts['decision_outcomes'] ?? 0),
                'demonstrations_30d' => (int) ($counts['demonstrations'] ?? 0),
                'average_calibration_precision' => $averagePrecision,
            ]
        );
    }

    private function buildCustomerFacingDomain(array $inputs): array
    {
        $autoresponder = (array) (($inputs['battery']['layers']['autoresponder'] ?? []));
        $commercial = (array) (($inputs['battery']['layers']['commercial'] ?? []));
        $commercialEval = (array) (($inputs['domain_evaluations']['commercial_mvp'] ?? []));
        $threadEval = (array) (($inputs['domain_evaluations']['customer_thread'] ?? []));
        $commercialRollout = (array) (($inputs['domain_rollouts']['commercial_mvp'] ?? []));
        $threadRollout = (array) (($inputs['domain_rollouts']['customer_thread'] ?? []));
        $score = (int) round(array_sum([
            (int) ($autoresponder['score'] ?? 0),
            (int) ($commercial['score'] ?? 0),
            (int) ($commercialEval['score'] ?? 0),
            (int) ($threadEval['score'] ?? 0),
        ]) / 4);

        $blockers = array_values(array_filter(array_merge(
            array_map('strval', (array) ($autoresponder['blockers'] ?? [])),
            array_map('strval', (array) ($commercial['blockers'] ?? [])),
            array_map('strval', (array) ($commercialEval['blockers'] ?? [])),
            array_map('strval', (array) ($threadEval['blockers'] ?? [])),
            (($commercialRollout['rollout_state'] ?? 'not_ready') !== 'ready') ? ['Commercial rollout state is not ready.'] : [],
            (($threadRollout['rollout_state'] ?? 'not_ready') !== 'ready') ? ['Customer thread rollout state is not ready.'] : []
        )));
        $signals = array_values(array_filter(array_merge(
            array_map('strval', (array) ($autoresponder['signals'] ?? [])),
            array_map('strval', (array) ($commercial['signals'] ?? [])),
            array_map('strval', (array) ($commercialEval['signals'] ?? [])),
            array_map('strval', (array) ($threadEval['signals'] ?? [])),
            (($commercialRollout['rollout_state'] ?? '') === 'ready') ? ['Commercial rollout is ready.'] : [],
            (($threadRollout['rollout_state'] ?? '') === 'ready') ? ['Customer thread rollout is ready.'] : []
        )));

        $status = $score >= 70
            && ($commercialRollout['rollout_state'] ?? 'not_ready') === 'ready'
            && ($threadRollout['rollout_state'] ?? 'not_ready') === 'ready'
            ? 'pass'
            : 'block';

        return $this->makeDomain(
            'Customer Facing',
            $score,
            $status,
            $blockers,
            $signals,
            'product_regression',
            [
                'commercial_eval_sample_size' => (int) (($commercialEval['metrics']['sample_size'] ?? 0)),
                'customer_thread_eval_sample_size' => (int) (($threadEval['metrics']['sample_size'] ?? 0)),
                'commercial_rollout_state' => (string) ($commercialRollout['rollout_state'] ?? 'not_ready'),
                'customer_thread_rollout_state' => (string) ($threadRollout['rollout_state'] ?? 'not_ready'),
            ]
        );
    }

    private function buildInternalOpsDomain(array $inputs): array
    {
        $workflow = (array) (($inputs['battery']['layers']['workflow_automation'] ?? []));
        $deal = (array) (($inputs['battery']['layers']['deal'] ?? []));
        $workflowEval = (array) (($inputs['domain_evaluations']['workflow_execution'] ?? []));
        $dealEval = (array) (($inputs['domain_evaluations']['deal_followthrough'] ?? []));
        $taskEval = (array) (($inputs['domain_evaluations']['task_followthrough'] ?? []));
        $workflowRollout = (array) (($inputs['domain_rollouts']['workflow_execution'] ?? []));
        $dealRollout = (array) (($inputs['domain_rollouts']['deal_followthrough'] ?? []));
        $taskRollout = (array) (($inputs['domain_rollouts']['task_followthrough'] ?? []));

        $score = (int) round(array_sum([
            (int) ($workflow['score'] ?? 0),
            (int) ($deal['score'] ?? 0),
            (int) ($workflowEval['score'] ?? 0),
            (int) ($dealEval['score'] ?? 0),
            (int) ($taskEval['score'] ?? 0),
        ]) / 5);

        $blockers = array_values(array_filter(array_merge(
            array_map('strval', (array) ($workflow['blockers'] ?? [])),
            array_map('strval', (array) ($deal['blockers'] ?? [])),
            array_map('strval', (array) ($workflowEval['blockers'] ?? [])),
            array_map('strval', (array) ($dealEval['blockers'] ?? [])),
            array_map('strval', (array) ($taskEval['blockers'] ?? [])),
            (($workflowRollout['rollout_state'] ?? 'not_ready') !== 'ready') ? ['Workflow rollout state is not ready.'] : [],
            (($dealRollout['rollout_state'] ?? 'not_ready') !== 'ready') ? ['Deal followthrough rollout state is not ready.'] : [],
            (($taskRollout['rollout_state'] ?? 'not_ready') !== 'ready') ? ['Task followthrough rollout state is not ready.'] : []
        )));
        $signals = array_values(array_filter(array_merge(
            array_map('strval', (array) ($workflow['signals'] ?? [])),
            array_map('strval', (array) ($deal['signals'] ?? [])),
            array_map('strval', (array) ($workflowEval['signals'] ?? [])),
            array_map('strval', (array) ($dealEval['signals'] ?? [])),
            array_map('strval', (array) ($taskEval['signals'] ?? [])),
            (($workflowRollout['rollout_state'] ?? '') === 'ready') ? ['Workflow rollout is ready.'] : [],
            (($dealRollout['rollout_state'] ?? '') === 'ready') ? ['Deal followthrough rollout is ready.'] : [],
            (($taskRollout['rollout_state'] ?? '') === 'ready') ? ['Task followthrough rollout is ready.'] : []
        )));

        $status = $score >= 70
            && ($workflowRollout['rollout_state'] ?? 'not_ready') === 'ready'
            && ($dealRollout['rollout_state'] ?? 'not_ready') === 'ready'
            && ($taskRollout['rollout_state'] ?? 'not_ready') === 'ready'
            ? 'pass'
            : 'block';

        return $this->makeDomain(
            'Internal Ops',
            $score,
            $status,
            $blockers,
            $signals,
            'product_regression',
            [
                'workflow_eval_sample_size' => (int) (($workflowEval['metrics']['sample_size'] ?? 0)),
                'deal_eval_sample_size' => (int) (($dealEval['metrics']['sample_size'] ?? 0)),
                'task_eval_sample_size' => (int) (($taskEval['metrics']['sample_size'] ?? 0)),
                'workflow_rollout_state' => (string) ($workflowRollout['rollout_state'] ?? 'not_ready'),
                'deal_rollout_state' => (string) ($dealRollout['rollout_state'] ?? 'not_ready'),
                'task_rollout_state' => (string) ($taskRollout['rollout_state'] ?? 'not_ready'),
            ]
        );
    }

    private function buildRuntimeDomain(array $verification, array $inputs): array
    {
        $jobSummary = (array) ($inputs['job_summary'] ?? []);
        $providerProbe = (array) ($inputs['provider_probe'] ?? []);
        $blockers = [];
        if (empty($verification['database_probes']['configured']['success'])) {
            $blockers[] = 'Configured database host is not reachable from PHP.';
        }
        if (($jobSummary['failed'] ?? 0) > 0) {
            $blockers[] = 'Failed automation jobs were detected.';
        }
        if (($jobSummary['stale'] ?? 0) > 0) {
            $blockers[] = 'Stale automation jobs were detected.';
        }
        if (empty($providerProbe['core_ai_provider_ready'])) {
            $blockers[] = (string) ($providerProbe['message'] ?? 'Core AI provider is not configured.');
        }

        $signals = $this->runtimeSignalsFromVerification($verification);
        if (($jobSummary['failed'] ?? 0) === 0 && ($jobSummary['stale'] ?? 0) === 0) {
            $signals[] = 'Automation job health has no failed or stale jobs.';
        }

        $score = 100;
        if (($jobSummary['failed'] ?? 0) > 0) {
            $score -= min(45, (int) $jobSummary['failed'] * 20);
        }
        if (($jobSummary['stale'] ?? 0) > 0) {
            $score -= min(30, (int) $jobSummary['stale'] * 12);
        }
        if (empty($providerProbe['core_ai_provider_ready'])) {
            $score -= 25;
        }

        return $this->makeDomain(
            'Runtime',
            max(0, $score),
            $blockers === [] ? 'pass' : 'block',
            $blockers,
            array_values(array_unique(array_filter($signals))),
            'environment_prerequisite',
            [
                'job_summary' => $jobSummary,
                'provider_ready' => !empty($providerProbe['core_ai_provider_ready']),
                'transient_environment_risk' => !empty($verification['transient_environment_risk']),
            ]
        );
    }

    private function buildGovernanceDomain(array $inputs): array
    {
        $rollouts = (array) ($inputs['domain_rollouts'] ?? []);
        $activeIncidents = (array) ($inputs['active_incidents'] ?? []);
        $blockers = [];
        $signals = [];
        $score = 100;

        foreach ($rollouts as $domainKey => $rollout) {
            $state = (string) ($rollout['rollout_state'] ?? 'not_ready');
            if ($state === 'ready') {
                $signals[] = $this->labelizeDomain($domainKey) . ' rollout is ready.';
                continue;
            }

            $blockers[] = $this->labelizeDomain($domainKey) . ' rollout is ' . str_replace('_', ' ', $state) . '.';
            $score -= match ($state) {
                'degraded' => 18,
                'paused', 'manually_frozen' => 25,
                default => 20,
            };
        }

        $highSeverityCount = count(array_filter($activeIncidents, static fn(array $row): bool => in_array((string) ($row['severity'] ?? ''), ['high', 'critical'], true)));
        if ($highSeverityCount > 0) {
            $blockers[] = sprintf('%d open high-severity autonomy incidents require recovery.', $highSeverityCount);
            $score -= min(35, $highSeverityCount * 12);
        } elseif ($activeIncidents !== []) {
            $signals[] = 'Open incidents exist, but none are high severity.';
        } else {
            $signals[] = 'No open autonomy incidents are blocking rollout governance.';
        }

        return $this->makeDomain(
            'Governance',
            max(0, $score),
            $blockers === [] ? 'pass' : 'block',
            array_values(array_unique($blockers)),
            array_values(array_unique($signals)),
            'product_regression',
            [
                'open_incident_count' => count($activeIncidents),
                'high_severity_incident_count' => $highSeverityCount,
                'rollout_states' => array_map(static fn(array $row): string => (string) ($row['rollout_state'] ?? 'not_ready'), $rollouts),
            ]
        );
    }

    private function buildHardGates(array $domains, array $verification, array $inputs): array
    {
        $requiredTables = array_filter((array) ($inputs['required_tables'] ?? []), static fn(array $row): bool => empty($row['exists']));
        $counts = (array) ($inputs['learning_counts'] ?? []);
        $jobSummary = (array) ($inputs['job_summary'] ?? []);
        $providerProbe = (array) ($inputs['provider_probe'] ?? []);

        $gates = [
            $this->gate('database_connection', 'Configured database host is reachable', !empty($verification['database_probes']['configured']['success']) ? 'pass' : 'block', 'environment_prerequisite', (string) ($verification['database_probes']['configured']['message'] ?? 'Database probe failed.')),
            $this->gate(
                'audit_schema_present',
                'Required AI audit tables exist',
                $requiredTables === [] ? 'pass' : 'block',
                'product_regression',
                $requiredTables === [] ? 'Required audit tables are present.' : 'Missing required tables: ' . implode(', ', array_map(static fn(array $row): string => (string) $row['table'], $requiredTables))
            ),
            $this->gate(
                'provider_configuration',
                'Core AI provider is configured',
                !empty($providerProbe['core_ai_provider_ready']) ? 'pass' : 'block',
                'environment_prerequisite',
                (string) ($providerProbe['message'] ?? 'AI provider configuration is missing.')
            ),
            $this->gate(
                'runtime_jobs_healthy',
                'Automation jobs are healthy',
                (($jobSummary['failed'] ?? 0) === 0 && ($jobSummary['stale'] ?? 0) === 0) ? 'pass' : 'block',
                'environment_prerequisite',
                (($jobSummary['failed'] ?? 0) === 0 && ($jobSummary['stale'] ?? 0) === 0)
                    ? 'No failed or stale automation jobs were detected.'
                    : sprintf('Failed jobs: %d, stale jobs: %d.', (int) ($jobSummary['failed'] ?? 0), (int) ($jobSummary['stale'] ?? 0))
            ),
            $this->gate('foundations_ready', 'Setup foundations are complete', ($domains['foundations']['status'] ?? 'block') === 'pass' ? 'pass' : 'block', 'product_regression', $this->primaryBlockerForDomain($domains['foundations'] ?? [])),
            $this->gate(
                'learning_ready',
                'Learning evidence meets minimum gates',
                ($domains['learning']['status'] ?? 'block') === 'pass'
                    && (int) ($counts['decision_outcomes'] ?? 0) >= 15
                    && (int) ($counts['demonstrations'] ?? 0) >= 10
                    ? 'pass'
                    : 'block',
                'product_regression',
                $this->primaryBlockerForDomain($domains['learning'] ?? [])
            ),
            $this->gate('governance_clear', 'Rollout governance has no blocking state', ($domains['governance']['status'] ?? 'block') === 'pass' ? 'pass' : 'block', 'product_regression', $this->primaryBlockerForDomain($domains['governance'] ?? [])),
        ];

        if (isset($domains['customer_facing'])) {
            $gates[] = $this->gate('customer_facing_ready', 'Customer-facing automation is ready', ($domains['customer_facing']['status'] ?? 'block') === 'pass' ? 'pass' : 'block', 'product_regression', $this->primaryBlockerForDomain($domains['customer_facing']));
        }

        if (isset($domains['internal_ops'])) {
            $gates[] = $this->gate('internal_ops_ready', 'Internal operations automation is ready', ($domains['internal_ops']['status'] ?? 'block') === 'pass' ? 'pass' : 'block', 'product_regression', $this->primaryBlockerForDomain($domains['internal_ops']));
        }

        return $gates;
    }

    private function buildRecommendedActions(array $domains, array $hardGates, array $verification, array $inputs): array
    {
        $actions = [];
        foreach ($hardGates as $gate) {
            if (($gate['status'] ?? 'pass') === 'pass') {
                continue;
            }

            $actions[] = match ((string) ($gate['key'] ?? '')) {
                'database_connection' => 'Restore database connectivity for the configured PHP host before relying on takeover confidence.',
                'audit_schema_present' => 'Apply the missing AI takeover foundation tables so the audit can measure evidence safely.',
                'provider_configuration' => 'Configure the core AI provider credentials and endpoint so runtime readiness can be trusted.',
                'runtime_jobs_healthy' => 'Clear failed and stale automation jobs before promoting takeover confidence.',
                'foundations_ready' => 'Finish the remaining setup foundations before allowing the system to scale autonomy.',
                'learning_ready' => 'Gather more accepted outcomes and demonstrations until the learning gates are comfortably met.',
                'customer_facing_ready' => 'Stabilize customer-facing automations and bring the rollout state to ready.',
                'internal_ops_ready' => 'Build more workflow, deal, and task automation evidence until internal operations are rollout-ready.',
                'governance_clear' => 'Resolve rollout freezes, degraded domains, and open recovery backlogs before trusting takeover.',
                default => (string) ($gate['reason'] ?? ''),
            };
        }

        if (!empty($verification['transient_environment_risk'])) {
            $actions[] = 'Track the mismatch between configured-host and localhost/127.0.0.1 database probes so transient infrastructure drift is visible.';
        }

        foreach ($domains as $domain) {
            foreach (array_slice((array) ($domain['blockers'] ?? []), 0, 1) as $blocker) {
                $actions[] = (string) $blocker;
            }
        }

        return $this->dedupeActions($actions);
    }

    private function evaluateDomain(string $tenantKey, string $domainKey): array
    {
        $events = match ($domainKey) {
            'commercial_mvp' => $this->scenarioReplay->collectCommercialScenarios($tenantKey),
            'deal_followthrough' => $this->scenarioReplay->collectDealScenarios($tenantKey),
            'task_followthrough' => $this->scenarioReplay->collectTaskScenarios($tenantKey),
            'customer_thread' => $this->scenarioReplay->collectCustomerThreadScenarios($tenantKey),
            'workflow_execution' => $this->scenarioReplay->collectWorkflowScenarios($tenantKey),
            default => $this->scenarioReplay->collectDomainEvents($tenantKey, $domainKey),
        };

        $metrics = $this->evaluation->computeMetrics($events);
        $summary = $this->evaluation->buildSummary($tenantKey, $domainKey, $metrics);
        $score = $this->scoreEvaluationMetrics($metrics, $summary);
        $blockers = [];
        if ((int) ($metrics['sample_size'] ?? 0) === 0) {
            $blockers[] = 'No evaluation scenarios were available for ' . $this->labelizeDomain($domainKey) . '.';
        }
        if ((float) ($metrics['precision_at_threshold'] ?? 0.0) > 0.0 && (float) ($metrics['precision_at_threshold'] ?? 0.0) < 0.85) {
            $blockers[] = $this->labelizeDomain($domainKey) . ' precision at threshold is below 0.85.';
        }
        if ((float) ($metrics['reversal_rate'] ?? 0.0) > 0.08) {
            $blockers[] = $this->labelizeDomain($domainKey) . ' reversal rate is above 0.08.';
        }
        if ((float) ($metrics['duplicate_action_rate'] ?? 0.0) > 0.05) {
            $blockers[] = $this->labelizeDomain($domainKey) . ' duplicate action rate is above 0.05.';
        }
        foreach ((array) ($summary['unstable_reasons'] ?? []) as $reason) {
            $blockers[] = $this->labelizeDomain($domainKey) . ' drift is unstable: ' . str_replace('_', ' ', (string) $reason) . '.';
        }

        $signals = [];
        if ((int) ($metrics['sample_size'] ?? 0) > 0) {
            $signals[] = sprintf('%s has %d replayable scenarios.', $this->labelizeDomain($domainKey), (int) ($metrics['sample_size'] ?? 0));
        }
        if (!empty($summary['promotion_recommended'])) {
            $signals[] = $this->labelizeDomain($domainKey) . ' meets the promotion recommendation threshold.';
        }
        if ((float) ($metrics['precision_at_threshold'] ?? 0.0) >= 0.90) {
            $signals[] = $this->labelizeDomain($domainKey) . ' precision at threshold is strong.';
        }

        return [
            'score' => $score,
            'status' => $blockers === [] && $score >= 70 ? 'pass' : 'block',
            'blockers' => array_values(array_unique($blockers)),
            'signals' => array_values(array_unique($signals)),
            'metrics' => $metrics,
            'summary' => $summary,
        ];
    }

    private function listRequiredTablePresence(): array
    {
        $rows = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $rows[] = [
                'table' => $table,
                'exists' => $this->tableExists($table),
            ];
        }
        return $rows;
    }

    private function collectActiveIncidents(string $tenantKey): array
    {
        $all = [];
        foreach (self::DOMAIN_KEYS as $domainKey) {
            foreach ($this->incidents->listIncidents($tenantKey, $domainKey, 50) as $incident) {
                if (in_array((string) ($incident['status'] ?? ''), ['open', 'queued', 'in_progress'], true)) {
                    $all[] = $incident;
                }
            }
        }
        return $all;
    }

    private function scoreEvaluationMetrics(array $metrics, array $summary): int
    {
        $sampleSize = (int) ($metrics['sample_size'] ?? 0);
        if ($sampleSize <= 0) {
            return 20;
        }

        $score = 25;
        $score += min(20, $sampleSize * 2);
        $score += (float) ($metrics['precision_at_threshold'] ?? 0.0) * 30;
        $score += (float) ($metrics['business_completion_rate'] ?? 0.0) * 15;
        $score -= (float) ($metrics['reversal_rate'] ?? 0.0) * 30;
        $score -= (float) ($metrics['edit_after_autonomy_rate'] ?? 0.0) * 20;
        $score -= (float) ($metrics['duplicate_action_rate'] ?? 0.0) * 20;
        $score -= (float) ($metrics['approval_override_rate'] ?? 0.0) * 10;
        $score -= (float) ($metrics['confidence_calibration_error'] ?? 0.0) * 20;
        if (!empty($summary['promotion_recommended'])) {
            $score += 8;
        }
        if (!empty($summary['unstable_reasons'])) {
            $score -= min(18, count((array) $summary['unstable_reasons']) * 6);
        }

        return max(0, min(100, (int) round($score)));
    }

    private function countRows(string $table, string $dateColumn, int $days, string $whereClause, array $params): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM {$table}
             WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND {$whereClause}",
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    private function countWorkspaceRows(string $table, string $dateColumn, int $days, int $workspaceId, string $whereClause, array $params): int
    {
        if ($this->columnExists($table, 'workspace_id')) {
            return $this->countRows(
                $table,
                $dateColumn,
                $days,
                'workspace_id = ? AND ' . $whereClause,
                array_merge([$workspaceId], $params)
            );
        }

        return $this->countRows($table, $dateColumn, $days, $whereClause, $params);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?
                 LIMIT 1",
                [$table, $column]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function averageCalibrationPrecision(array $summary): float
    {
        $values = [];
        foreach ($summary as $actions) {
            if (!is_array($actions)) {
                continue;
            }
            foreach ($actions as $metrics) {
                if (!is_array($metrics)) {
                    continue;
                }
                $precision = (float) ($metrics['precision_at_current_threshold'] ?? 0.0);
                if ($precision > 0.0) {
                    $values[] = $precision;
                }
            }
        }

        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 4);
    }

    private function filterDomainsForScope(array $domains, string $scope): array
    {
        return match ($scope) {
            'customer_facing' => array_intersect_key($domains, array_flip(['foundations', 'learning', 'customer_facing', 'runtime', 'governance'])),
            'internal_ops' => array_intersect_key($domains, array_flip(['foundations', 'learning', 'internal_ops', 'runtime', 'governance'])),
            default => $domains,
        };
    }

    private function computePlatformScore(array $domains): int
    {
        if ($domains === []) {
            return 0;
        }

        $scores = array_map(static fn(array $domain): int => (int) ($domain['score'] ?? 0), $domains);
        return (int) round(array_sum($scores) / count($scores));
    }

    private function makeDomain(string $label, int $score, string $status, array $blockers, array $signals, string $classification, array $details = []): array
    {
        return [
            'label' => $label,
            'score' => max(0, min(100, $score)),
            'status' => $status === 'pass' ? 'pass' : 'block',
            'classification' => $classification,
            'blockers' => array_values(array_unique(array_map('strval', array_filter($blockers)))),
            'signals' => array_values(array_unique(array_map('strval', array_filter($signals)))),
            'details' => $details,
        ];
    }

    private function gate(string $key, string $label, string $status, string $classification, string $reason): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status === 'pass' ? 'pass' : 'block',
            'classification' => $classification,
            'reason' => trim($reason),
        ];
    }

    private function primaryBlockerForDomain(array $domain): string
    {
        $blockers = array_values(array_filter(array_map('strval', (array) ($domain['blockers'] ?? []))));
        return $blockers[0] ?? 'This domain is still below its confidence gate.';
    }

    private function dedupeActions(array $actions): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(static function ($action): string {
            return trim((string) $action);
        }, $actions)))));
    }

    private function runtimeSignalsFromVerification(array $verification): array
    {
        $signals = [];
        if (!empty($verification['database_probes']['configured']['success'])) {
            $signals[] = 'Configured database host probe succeeded.';
        }
        if (!empty($verification['database_probes']['localhost']['success'])) {
            $signals[] = 'localhost database probe succeeded.';
        }
        if (!empty($verification['database_probes']['loopback']['success'])) {
            $signals[] = '127.0.0.1 database probe succeeded.';
        }
        if (!empty($verification['provider_probe']['core_ai_provider_ready'])) {
            $signals[] = 'Core AI provider configuration is ready.';
        }
        return $signals;
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, ['all', 'customer_facing', 'internal_ops'], true) ? $scope : 'all';
    }

    private function hasTransientProbeMismatch(array $configured, array $localhost, array $loopback): bool
    {
        $statuses = [
            !empty($configured['success']),
            !empty($localhost['success']),
            !empty($loopback['success']),
        ];

        return count(array_unique($statuses)) > 1;
    }

    private function probeDatabaseHost(string $host, string $user, string $pass): array
    {
        if (is_callable($this->databaseProbe)) {
            return (array) call_user_func($this->databaseProbe, $host, $user, $pass);
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . $host . ';charset=utf8mb4',
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo = null;
            return [
                'success' => true,
                'host' => $host,
                'message' => 'Connection succeeded.',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'host' => $host,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );
            return ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function labelizeDomain(string $domainKey): string
    {
        return match ($domainKey) {
            'commercial_mvp' => 'Commercial automation',
            'customer_thread' => 'Customer thread automation',
            'workflow_execution' => 'Workflow execution',
            'deal_followthrough' => 'Deal followthrough',
            'task_followthrough' => 'Task followthrough',
            default => ucwords(str_replace('_', ' ', $domainKey)),
        };
    }

    private function emptyBatteryStatus(): array
    {
        return [
            'layers' => [
                'setup' => ['score' => 0, 'blockers' => [], 'signals' => [], 'is_complete' => false, 'completed_count' => 0, 'total_count' => 0],
                'learning' => ['score' => 0, 'blockers' => [], 'signals' => []],
                'autoresponder' => ['score' => 0, 'blockers' => [], 'signals' => []],
                'commercial' => ['score' => 0, 'blockers' => [], 'signals' => []],
                'workflow_automation' => ['score' => 0, 'blockers' => [], 'signals' => []],
                'deal' => ['score' => 0, 'blockers' => [], 'signals' => []],
            ],
        ];
    }
}
