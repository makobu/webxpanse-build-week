<?php

namespace CRM\Services;

use CRM\Database;

class AILearningReviewService
{
    private const DOMAINS = ['commercial_mvp', 'customer_care', 'deal_followthrough', 'task_followthrough', 'customer_thread', 'workflow_execution', 'cross_domain_orchestrator'];
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AITenantPolicyResolverService $resolver = null,
        private ?AIActionSimilarityService $similarity = null,
        private ?AIAutonomyDomainControlService $controls = null,
        private ?AIAutonomyDriftMonitoringService $drift = null,
        private ?AIAutonomyPromotionGateService $promotion = null,
        private ?AIAutonomyIncidentService $incidents = null,
        private ?AIAutonomyRolloutOperationsService $rollout = null,
        private ?AICrossDomainOrchestratorService $orchestrator = null,
        private ?AICrossDomainReplayService $orchestrationReplay = null
    ) {
        $this->resolver = $this->resolver ?? new AITenantPolicyResolverService();
        $this->similarity = $this->similarity ?? new AIActionSimilarityService();
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
        $this->drift = $this->drift ?? new AIAutonomyDriftMonitoringService();
        $this->promotion = $this->promotion ?? new AIAutonomyPromotionGateService();
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService();
        $this->rollout = $this->rollout ?? new AIAutonomyRolloutOperationsService();
        $this->orchestrator = $this->orchestrator ?? new AICrossDomainOrchestratorService();
        $this->orchestrationReplay = $this->orchestrationReplay ?? new AICrossDomainReplayService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getRecentDemonstrations(?string $tenantKey = null, ?string $domainKey = null, int $limit = 30): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $where = [];
        $params = [];
        $where[] = 'workspace_id = ?';
        $params[] = $workspaceId;
        if ($domainKey) {
            $where[] = 'domain_key = ?';
            $params[] = $domainKey;
        }
        $sql = "SELECT * FROM ai_operator_demonstrations";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY observed_at DESC, id DESC LIMIT " . max(1, min(100, $limit));
        return Database::query($sql, $params);
    }

    public function buildExplanation(string $tenantKey, string $domainKey, string $actionKey, array $context = []): array
    {
        return [
            'tenant_policy' => $this->resolver->resolve($tenantKey, $domainKey, array_merge($context, ['action_key' => $actionKey])),
            'similar_examples' => $this->similarity->findSimilarExamples($tenantKey, $domainKey, $actionKey, $context, 5),
            'control' => $this->controls->get($tenantKey, $domainKey),
            'drift_status' => $this->drift->summarize($tenantKey, $domainKey, $actionKey),
            'promotion_gate_status' => $this->promotion->evaluate($tenantKey, $domainKey, false),
            'incidents' => $this->incidents->listIncidents($tenantKey, $domainKey, 5),
        ];
    }

    public function getDashboardData(?string $tenantKey = null, string $domainKey = 'commercial_mvp'): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey($this->workspaceScope->requireWorkspaceId($tenantKey));
        $evaluations = $this->tableExists('ai_autonomy_eval_runs')
            ? Database::query(
                "SELECT *
                 FROM ai_autonomy_eval_runs
                 WHERE workspace_id = ?
                   AND domain_key = ?
                 ORDER BY started_at DESC LIMIT 10",
                [$this->workspaceScope->requireWorkspaceId($tenantKey), $domainKey]
            )
            : [];

        return [
            'controls' => $this->controls->get($tenantKey, $domainKey),
            'recent_demonstrations' => $this->getRecentDemonstrations($tenantKey, $domainKey, 20),
            'tenant_policy' => $this->resolver->resolve($tenantKey, $domainKey),
            'evaluations' => $evaluations,
            'drift_status' => $this->drift->summarize($tenantKey, $domainKey),
            'promotion_gate_status' => $this->promotion->evaluate($tenantKey, $domainKey, false),
            'incidents' => $this->incidents->listIncidents($tenantKey, $domainKey, 10),
            'recovery_queue' => $this->incidents->listRecoveryQueue($tenantKey, $domainKey, 10),
            'operator_actions' => $this->incidents->listOperatorActions($tenantKey, $domainKey, 10),
            'rollout_readiness' => $this->rollout->computeReadiness($tenantKey, $domainKey),
            'domain_summaries' => $this->getDomainSummaries($tenantKey),
            'orchestration_runs' => $this->orchestrator->listRuns($tenantKey, 12),
            'orchestration_summary' => $this->orchestrationReplay->summarizeTenant($tenantKey, 25),
        ];
    }

    public function getDomainSummaries(?string $tenantKey = null): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey($this->workspaceScope->requireWorkspaceId($tenantKey));
        $summaries = [];
        foreach (self::DOMAINS as $domainKey) {
            $summaries[$domainKey] = [
                'controls' => $this->controls->get($tenantKey, $domainKey),
                'recent_demonstration_count' => count($this->getRecentDemonstrations($tenantKey, $domainKey, 5)),
                'drift_status' => $this->drift->summarize($tenantKey, $domainKey),
                'promotion_gate_status' => $this->promotion->evaluate($tenantKey, $domainKey, false),
                'incident_count' => count($this->incidents->listIncidents($tenantKey, $domainKey, 20)),
                'rollout_readiness' => $this->rollout->computeReadiness($tenantKey, $domainKey),
            ];
            if ($domainKey === 'cross_domain_orchestrator') {
                $summaries[$domainKey]['recent_run_count'] = count($this->orchestrator->listRuns($tenantKey, 5));
                $summaries[$domainKey]['replay_summary'] = $this->orchestrationReplay->summarizeTenant($tenantKey, 25);
            }
        }
        return $summaries;
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
