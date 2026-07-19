<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyScenarioReplayService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyEvaluationService $evaluation = null,
        private ?AIAutonomyPromotionGateService $promotion = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->evaluation = $this->evaluation ?? new AIAutonomyEvaluationService();
        $this->promotion = $this->promotion ?? new AIAutonomyPromotionGateService();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function runForDomain(string $tenantKey, string $domainKey, string $autonomyMode = 'suggest_only', ?int $userId = null): int
    {
        $events = match ($domainKey) {
            'commercial_mvp' => $this->collectCommercialScenarios($tenantKey),
            'deal_followthrough' => $this->collectDealScenarios($tenantKey),
            'task_followthrough' => $this->collectTaskScenarios($tenantKey),
            'customer_thread' => $this->collectCustomerThreadScenarios($tenantKey),
            'workflow_execution' => $this->collectWorkflowScenarios($tenantKey),
            'cross_domain_orchestrator' => $this->collectCrossDomainScenarios($tenantKey),
            default => $this->collectDomainEvents($tenantKey, $domainKey),
        };
        $runId = $this->evaluation->startRun([
            'tenant_key' => $tenantKey,
            'domain_key' => $domainKey,
            'autonomy_mode' => $autonomyMode,
            'scenario_count' => count($events),
            'created_by' => $userId,
        ]);
        $metrics = $this->evaluation->computeMetrics($events);
        $summary = array_merge(
            $this->evaluation->buildSummary($tenantKey, $domainKey, $metrics),
            [
                'sampled_from_demonstrations' => count($events),
                'scenario_mode' => in_array($domainKey, ['commercial_mvp', 'deal_followthrough', 'task_followthrough', 'customer_thread', 'workflow_execution', 'cross_domain_orchestrator'], true) ? 'sequence' : 'aggregate',
            ]
        );
        $this->evaluation->completeRun($runId, $metrics, $summary);
        $promotion = $this->promotion->evaluate($tenantKey, $domainKey);
        $summary['promotion_gate_status'] = $promotion;
        $this->evaluation->completeRun($runId, $metrics, $summary);
        return $runId;
    }

    public function collectDomainEvents(string $tenantKey, string $domainKey): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT * FROM ai_operator_demonstrations
             WHERE workspace_id = ? AND domain_key = ?
             ORDER BY observed_at DESC
             LIMIT 200",
            [$workspaceId, $domainKey]
        );

        $events = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $events[] = [
                'autonomous' => in_array((string) ($row['actor_type'] ?? ''), ['system', 'ai'], true),
                'was_successful' => !empty($row['was_successful']),
                'was_reversed' => !empty($row['was_reversed']),
                'was_edited' => !empty($row['was_edited']),
                'approval_override' => !empty($row['linked_approval_id']) && (string) ($row['actor_type'] ?? '') === 'user',
                'duplicate_action' => !empty($metadata['duplicate_action']),
                'completed' => in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved'], true),
                'time_to_outcome_minutes' => isset($metadata['time_to_outcome_minutes']) ? (float) $metadata['time_to_outcome_minutes'] : null,
                'human_baseline_minutes' => isset($metadata['human_baseline_minutes']) ? (float) $metadata['human_baseline_minutes'] : null,
                'confidence_score' => isset($metadata['assistant_confidence']) ? (float) $metadata['assistant_confidence'] : 0.0,
            ];
        }

        return $events;
    }

    public function collectCommercialScenarios(string $tenantKey): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT * FROM ai_operator_demonstrations
             WHERE workspace_id = ? AND domain_key = 'commercial_mvp'
             ORDER BY observed_at ASC, id ASC
             LIMIT 300",
            [$workspaceId]
        );

        $scenarios = [];
        foreach ($rows as $row) {
            $key = (string) ($row['related_entity_type'] ?? $row['entity_type'] ?? 'entity') . ':' . (int) ($row['related_entity_id'] ?? $row['entity_id'] ?? 0);
            if (!isset($scenarios[$key])) {
                $scenarios[$key] = [
                    'autonomous' => false,
                    'was_successful' => false,
                    'was_reversed' => false,
                    'was_edited' => false,
                    'approval_override' => false,
                    'duplicate_action' => false,
                    'completed' => false,
                    'time_to_outcome_minutes' => null,
                    'human_baseline_minutes' => null,
                    'confidence_score' => 0.0,
                ];
            }
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $scenarios[$key]['autonomous'] = $scenarios[$key]['autonomous'] || in_array((string) ($row['actor_type'] ?? ''), ['system', 'ai'], true);
            $scenarios[$key]['was_successful'] = $scenarios[$key]['was_successful'] || !empty($row['was_successful']);
            $scenarios[$key]['was_reversed'] = $scenarios[$key]['was_reversed'] || !empty($row['was_reversed']);
            $scenarios[$key]['was_edited'] = $scenarios[$key]['was_edited'] || !empty($row['was_edited']);
            $scenarios[$key]['approval_override'] = $scenarios[$key]['approval_override'] || (!empty($row['linked_approval_id']) && (string) ($row['actor_type'] ?? '') === 'user');
            $scenarios[$key]['duplicate_action'] = $scenarios[$key]['duplicate_action'] || !empty($metadata['duplicate_action']);
            $scenarios[$key]['completed'] = $scenarios[$key]['completed'] || in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved'], true);
            $scenarios[$key]['confidence_score'] = max($scenarios[$key]['confidence_score'], (float) ($metadata['assistant_confidence'] ?? 0.0));
            if (isset($metadata['time_to_outcome_minutes'])) {
                $scenarios[$key]['time_to_outcome_minutes'] = (float) $metadata['time_to_outcome_minutes'];
            }
            if (isset($metadata['human_baseline_minutes'])) {
                $scenarios[$key]['human_baseline_minutes'] = (float) $metadata['human_baseline_minutes'];
            }
        }

        return array_values($scenarios);
    }

    public function collectDealScenarios(string $tenantKey): array
    {
        return $this->collectEntityGroupedScenarios($tenantKey, 'deal_followthrough', 'deal');
    }

    public function collectTaskScenarios(string $tenantKey): array
    {
        return $this->collectEntityGroupedScenarios($tenantKey, 'task_followthrough', 'task');
    }

    public function collectCustomerThreadScenarios(string $tenantKey): array
    {
        return $this->collectEntityGroupedScenarios($tenantKey, 'customer_thread', 'contact');
    }

    public function collectWorkflowScenarios(string $tenantKey): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT * FROM ai_operator_demonstrations
             WHERE workspace_id = ? AND domain_key = 'workflow_execution'
             ORDER BY observed_at ASC, id ASC
             LIMIT 400",
            [$workspaceId]
        );

        $scenarios = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $key = 'workflow:' . (int) ($metadata['workflow_id'] ?? 0) . ':execution:' . (int) ($metadata['workflow_execution_id'] ?? 0);
            if (!isset($scenarios[$key])) {
                $scenarios[$key] = [
                    'autonomous' => false,
                    'was_successful' => false,
                    'was_reversed' => false,
                    'was_edited' => false,
                    'approval_override' => false,
                    'duplicate_action' => false,
                    'completed' => false,
                    'time_to_outcome_minutes' => null,
                    'human_baseline_minutes' => null,
                    'confidence_score' => 0.0,
                ];
            }
            $scenarios[$key]['autonomous'] = $scenarios[$key]['autonomous'] || in_array((string) ($row['actor_type'] ?? ''), ['system', 'ai'], true);
            $scenarios[$key]['was_successful'] = $scenarios[$key]['was_successful'] || !empty($row['was_successful']);
            $scenarios[$key]['was_reversed'] = $scenarios[$key]['was_reversed'] || !empty($row['was_reversed']);
            $scenarios[$key]['was_edited'] = $scenarios[$key]['was_edited'] || !empty($row['was_edited']);
            $scenarios[$key]['approval_override'] = $scenarios[$key]['approval_override'] || (!empty($row['linked_approval_id']) && (string) ($row['actor_type'] ?? '') === 'user');
            $scenarios[$key]['duplicate_action'] = $scenarios[$key]['duplicate_action'] || !empty($metadata['duplicate_action']);
            $scenarios[$key]['completed'] = $scenarios[$key]['completed'] || in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved'], true);
            $scenarios[$key]['confidence_score'] = max($scenarios[$key]['confidence_score'], (float) ($metadata['assistant_confidence'] ?? 0.0));
            if (isset($metadata['time_to_outcome_minutes'])) {
                $scenarios[$key]['time_to_outcome_minutes'] = (float) $metadata['time_to_outcome_minutes'];
            }
            if (isset($metadata['human_baseline_minutes'])) {
                $scenarios[$key]['human_baseline_minutes'] = (float) $metadata['human_baseline_minutes'];
            }
        }

        return array_values($scenarios);
    }

    public function collectCrossDomainScenarios(string $tenantKey): array
    {
        $replay = new AICrossDomainReplayService();
        $summary = $replay->summarizeTenant($tenantKey, 100);
        $runs = $summary['runs'] ?? [];
        $events = [];
        foreach ($runs as $run) {
            $steps = (array) ($run['steps'] ?? []);
            $completedSteps = count(array_filter($steps, static fn(array $step): bool => ($step['step_status'] ?? '') === 'completed'));
            $events[] = [
                'autonomous' => (string) ($run['execution_mode'] ?? 'plan_only') !== 'plan_only',
                'was_successful' => (string) ($run['run_status'] ?? '') === 'completed',
                'was_reversed' => false,
                'was_edited' => false,
                'approval_override' => (string) ($run['run_status'] ?? '') === 'approval_required',
                'duplicate_action' => false,
                'completed' => (string) ($run['run_status'] ?? '') === 'completed',
                'time_to_outcome_minutes' => null,
                'human_baseline_minutes' => null,
                'confidence_score' => count($steps) > 0 ? round(array_sum(array_map(static fn(array $step): float => (float) ($step['assistant_confidence'] ?? 0.0), $steps)) / count($steps), 4) : 0.0,
            ];
            if ((string) ($run['run_status'] ?? '') === 'failed' && $completedSteps > 0) {
                $events[count($events) - 1]['was_edited'] = true;
            }
        }

        return $events;
    }

    private function collectEntityGroupedScenarios(string $tenantKey, string $domainKey, string $defaultEntityType): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT * FROM ai_operator_demonstrations
             WHERE workspace_id = ? AND domain_key = ?
             ORDER BY observed_at ASC, id ASC
             LIMIT 300",
            [$workspaceId, $domainKey]
        );

        $scenarios = [];
        foreach ($rows as $row) {
            $groupType = (string) ($row['related_entity_type'] ?? $row['entity_type'] ?? $defaultEntityType);
            $groupId = (int) ($row['related_entity_id'] ?? $row['entity_id'] ?? 0);
            $key = $groupType . ':' . $groupId;
            if (!isset($scenarios[$key])) {
                $scenarios[$key] = [
                    'autonomous' => false,
                    'was_successful' => false,
                    'was_reversed' => false,
                    'was_edited' => false,
                    'approval_override' => false,
                    'duplicate_action' => false,
                    'completed' => false,
                    'time_to_outcome_minutes' => null,
                    'human_baseline_minutes' => null,
                    'confidence_score' => 0.0,
                ];
            }
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $scenarios[$key]['autonomous'] = $scenarios[$key]['autonomous'] || in_array((string) ($row['actor_type'] ?? ''), ['system', 'ai'], true);
            $scenarios[$key]['was_successful'] = $scenarios[$key]['was_successful'] || !empty($row['was_successful']);
            $scenarios[$key]['was_reversed'] = $scenarios[$key]['was_reversed'] || !empty($row['was_reversed']);
            $scenarios[$key]['was_edited'] = $scenarios[$key]['was_edited'] || !empty($row['was_edited']);
            $scenarios[$key]['approval_override'] = $scenarios[$key]['approval_override'] || (!empty($row['linked_approval_id']) && (string) ($row['actor_type'] ?? '') === 'user');
            $scenarios[$key]['duplicate_action'] = $scenarios[$key]['duplicate_action'] || !empty($metadata['duplicate_action']);
            $scenarios[$key]['completed'] = $scenarios[$key]['completed'] || in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved'], true);
            $scenarios[$key]['confidence_score'] = max($scenarios[$key]['confidence_score'], (float) ($metadata['assistant_confidence'] ?? 0.0));
            if (isset($metadata['time_to_outcome_minutes'])) {
                $scenarios[$key]['time_to_outcome_minutes'] = (float) $metadata['time_to_outcome_minutes'];
            }
            if (isset($metadata['human_baseline_minutes'])) {
                $scenarios[$key]['human_baseline_minutes'] = (float) $metadata['human_baseline_minutes'];
            }
        }

        return array_values($scenarios);
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

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
