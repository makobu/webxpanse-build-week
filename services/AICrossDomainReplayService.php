<?php

namespace CRM\Services;

class AICrossDomainReplayService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AICrossDomainPlanStoreService $store = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->store = $this->store ?? new AICrossDomainPlanStoreService($workspaceScope);
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function summarizeTenant(?string $tenantKey = null, int $limit = 25): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $runs = $this->store->listRuns($this->workspaceScope->workspaceTenantKey($workspaceId), $limit);
        $steps = [];
        foreach ($runs as $run) {
            $steps = array_merge($steps, $this->store->listSteps((int) $run['id']));
        }
        $intakeRows = $this->fetchIntakeRows($workspaceId, $limit * 3);

        $runCount = count($runs);
        $stepCount = count($steps);
        $completedRuns = count(array_filter($runs, static fn(array $run): bool => ($run['run_status'] ?? '') === 'completed'));
        $blockedRuns = count(array_filter($runs, static fn(array $run): bool => in_array((string) ($run['run_status'] ?? ''), ['blocked', 'approval_required'], true)));
        $waitingRuns = count(array_filter($runs, static fn(array $run): bool => ($run['run_status'] ?? '') === 'waiting'));
        $eventRuns = count(array_filter($runs, static fn(array $run): bool => ($run['origin_type'] ?? 'operator') === 'event'));
        $readyToResumeRuns = count(array_filter($runs, static fn(array $run): bool => ($run['run_status'] ?? '') === 'ready_to_resume'));
        $completedSteps = count(array_filter($steps, static fn(array $step): bool => ($step['step_status'] ?? '') === 'completed'));
        $blockedSteps = count(array_filter($steps, static fn(array $step): bool => in_array((string) ($step['step_status'] ?? ''), ['blocked', 'approval_required'], true)));

        return [
            'run_count' => $runCount,
            'step_count' => $stepCount,
            'event_origin_run_count' => $eventRuns,
            'waiting_run_count' => $waitingRuns,
            'ready_to_resume_count' => $readyToResumeRuns,
            'plan_completion_rate' => $runCount > 0 ? round($completedRuns / $runCount, 4) : 0.0,
            'step_success_rate' => $stepCount > 0 ? round($completedSteps / $stepCount, 4) : 0.0,
            'blocker_rate' => $runCount > 0 ? round($blockedRuns / $runCount, 4) : 0.0,
            'approval_interruption_rate' => $stepCount > 0
                ? round(count(array_filter($steps, static fn(array $step): bool => ($step['step_status'] ?? '') === 'approval_required')) / $stepCount, 4)
                : 0.0,
            'blocked_step_rate' => $stepCount > 0 ? round($blockedSteps / $stepCount, 4) : 0.0,
            'duplicate_or_contradictory_rate' => $this->duplicateRate($steps),
            'suppression_count' => count(array_filter($intakeRows, static fn(array $row): bool => in_array((string) ($row['intake_decision'] ?? ''), ['suppressed', 'duplicate_rejected'], true))),
            'duplicate_trigger_rate' => $this->rateForDecision($intakeRows, 'duplicate_rejected'),
            'resume_success_rate' => $eventRuns > 0 ? round($readyToResumeRuns / $eventRuns, 4) : 0.0,
            'runs' => $runs,
        ];
    }

    private function duplicateRate(array $steps): float
    {
        if ($steps === []) {
            return 0.0;
        }
        $fingerprints = [];
        $duplicates = 0;
        foreach ($steps as $step) {
            $key = implode(':', [
                (string) ($step['domain_key'] ?? ''),
                (string) ($step['action_key'] ?? ''),
                (string) ($step['target_entity_type'] ?? ''),
                (string) ($step['target_entity_id'] ?? ''),
            ]);
            if (isset($fingerprints[$key])) {
                $duplicates++;
            }
            $fingerprints[$key] = true;
        }
        return round($duplicates / max(1, count($steps)), 4);
    }

    private function fetchIntakeRows(int $workspaceId, int $limit): array
    {
        try {
            return \CRM\Database::query(
                "SELECT * FROM ai_cross_domain_intake_events WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(500, $limit)),
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function rateForDecision(array $rows, string $decision): float
    {
        if ($rows === []) {
            return 0.0;
        }
        $count = count(array_filter($rows, static fn(array $row): bool => (string) ($row['intake_decision'] ?? '') === $decision));
        return round($count / count($rows), 4);
    }
}
