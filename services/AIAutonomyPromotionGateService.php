<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyPromotionGateService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyDomainControlService $controls = null,
        private ?AIAutonomyDriftMonitoringService $drift = null,
        private ?AIAutonomyIncidentService $incidents = null
    ) {
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
        $this->drift = $this->drift ?? new AIAutonomyDriftMonitoringService();
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function evaluate(string $tenantKey, string $domainKey, bool $applyChanges = true): array
    {
        $control = $this->controls->get($tenantKey, $domainKey);
        $metadata = (array) ($control['metadata'] ?? []);
        $recentRuns = $this->getRecentCompletedEvaluations($tenantKey, $domainKey, max(1, (int) ($metadata['min_eval_runs_to_promote'] ?? 1)));
        $latest = $recentRuns[0] ?? null;
        $metrics = $latest ? $this->decodeJson($latest['metrics_json'] ?? null) : [];
        $summary = $latest ? $this->decodeJson($latest['summary_json'] ?? null) : [];
        $drift = $this->drift->summarize($tenantKey, $domainKey);
        $operational = $this->incidents->summarizeOperationalState($tenantKey, $domainKey);
        $hasCriticalIncident = (int) ($operational['critical_incident_count'] ?? 0) > 0;

        $meets = $latest !== null
            && count($recentRuns) >= (int) ($metadata['min_eval_runs_to_promote'] ?? 1)
            && (int) ($metrics['sample_size'] ?? 0) >= (int) ($metadata['min_sample_size_to_promote'] ?? 10)
            && (float) ($metrics['precision_at_threshold'] ?? 0) >= (float) ($control['min_precision_to_promote'] ?? 0.9)
            && (float) ($metrics['reversal_rate'] ?? 1) <= (float) ($control['max_reversal_rate_to_promote'] ?? 0.08)
            && (float) ($metrics['edit_after_autonomy_rate'] ?? 1) <= (float) ($control['max_edit_rate_to_promote'] ?? 0.12)
            && (float) ($metrics['duplicate_action_rate'] ?? 1) <= (float) ($metadata['max_duplicate_rate_to_promote'] ?? 0.05)
            && (float) ($metrics['approval_override_rate'] ?? 1) <= (float) ($metadata['max_override_rate_to_promote'] ?? 0.12)
            && empty($drift['unstable'])
            && !$hasCriticalIncident;

        $currentMode = (string) ($control['autonomy_mode'] ?? 'suggest_only');
        $promotionStatus = (string) ($control['promotion_status'] ?? 'suggest_only');
        $recommendedMode = $currentMode;
        $decision = 'hold';
        $reasons = [];

        if (!empty($metadata['manual_freeze'])) {
            $promotionStatus = 'blocked';
            $decision = 'block';
            $reasons[] = 'manual_freeze_active';
        } elseif (!empty($metadata['forced_safe_mode'])) {
            $recommendedMode = $currentMode === 'full_auto' ? 'auto_safe' : 'suggest_only';
            $promotionStatus = 'blocked';
            $decision = $currentMode === $recommendedMode ? 'block' : 'downgrade';
            $reasons[] = 'forced_safe_mode_active';
        }

        if ($latest === null) {
            $reasons[] = 'no_completed_evaluations';
        }
        if (count($recentRuns) < (int) ($metadata['min_eval_runs_to_promote'] ?? 1)) {
            $reasons[] = 'insufficient_eval_runs';
        }
        if ((int) ($metrics['sample_size'] ?? 0) < (int) ($metadata['min_sample_size_to_promote'] ?? 10)) {
            $reasons[] = 'sample_size_below_gate';
        }
        if (!empty($drift['unstable'])) {
            $reasons = array_merge($reasons, (array) ($drift['unstable_reasons'] ?? []));
        }
        if ($hasCriticalIncident) {
            $reasons[] = 'critical_incident_active';
        }

        if ($decision === 'hold' && $hasCriticalIncident) {
            $promotionStatus = 'blocked';
            $decision = 'block';
        } elseif ($decision === 'hold' && $meets && !empty($control['fast_promotion_enabled']) && empty($metadata['approval_required_for_promotion'])) {
            $recommendedMode = $currentMode === 'suggest_only' ? 'auto_safe' : ($currentMode === 'auto_safe' ? 'full_auto' : 'full_auto');
            $promotionStatus = $recommendedMode;
            $decision = $recommendedMode !== $currentMode ? 'promote' : 'hold';
        } elseif ($decision === 'hold' && $meets && !empty($metadata['approval_required_for_promotion'])) {
            $promotionStatus = $currentMode === 'suggest_only' ? 'auto_safe' : ($currentMode === 'auto_safe' ? 'full_auto' : 'full_auto');
            $decision = 'approval_required';
            $reasons[] = 'promotion_requires_operator_approval';
        } elseif ($decision === 'hold' && !empty($drift['unstable']) && !empty($metadata['auto_downgrade_on_drift'])) {
            $recommendedMode = $currentMode === 'full_auto' ? 'auto_safe' : 'suggest_only';
            $promotionStatus = 'blocked';
            $decision = 'downgrade';
        } elseif ($decision === 'hold' && $latest !== null && !$meets && $this->isSevereBreach($metrics, $control, $metadata, $drift)) {
            $promotionStatus = 'blocked';
            $decision = 'block';
            $reasons[] = 'severe_metric_breach';
        }

        if (in_array($decision, ['downgrade', 'block'], true)) {
            $incidentId = $this->incidents->recordIncident([
                'tenant_key' => $tenantKey,
                'domain_key' => $domainKey,
                'incident_key' => $decision === 'downgrade' ? 'autonomy_downgrade' : 'promotion_blocked',
                'severity' => $decision === 'downgrade' ? 'high' : 'medium',
                'reason_codes' => $reasons,
                'details' => [
                    'metrics' => $metrics,
                    'summary' => $summary,
                    'drift' => $drift,
                    'current_mode' => $currentMode,
                    'recommended_mode' => $recommendedMode,
                ],
                'linked_eval_run_id' => !empty($latest['id']) ? (int) $latest['id'] : null,
            ]);
            if ($incidentId) {
                $this->incidents->queueRecovery([
                    'incident_id' => $incidentId,
                    'tenant_key' => $tenantKey,
                    'domain_key' => $domainKey,
                    'suggested_manual_action' => 'Review rollout metrics and downgrade or pause autonomy for this domain',
                    'payload' => ['metrics' => $metrics, 'drift' => $drift, 'promotion_status' => $promotionStatus],
                ]);
            }
        }

        if ($applyChanges && $decision === 'promote') {
            $this->controls->save($tenantKey, $domainKey, [
                'autonomy_mode' => $recommendedMode,
                'promotion_status' => $promotionStatus,
            ], null);
        } elseif ($applyChanges && $decision === 'downgrade') {
            $this->controls->save($tenantKey, $domainKey, [
                'autonomy_mode' => $recommendedMode,
                'promotion_status' => 'blocked',
            ], null);
        } elseif ($applyChanges && $decision === 'block' && $promotionStatus === 'blocked') {
            $this->controls->save($tenantKey, $domainKey, [
                'promotion_status' => 'blocked',
            ], null);
        }

        return [
            'decision' => $decision,
            'current_mode' => $currentMode,
            'recommended_mode' => $recommendedMode,
            'promotion_status' => $promotionStatus,
            'metrics' => $metrics,
            'summary' => $summary,
            'drift' => $drift,
            'operational' => $operational,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function getRecentCompletedEvaluations(string $tenantKey, string $domainKey, int $limit): array
    {
        if (!$this->tableExists('ai_autonomy_eval_runs')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        return Database::query(
            "SELECT * FROM ai_autonomy_eval_runs
             WHERE workspace_id = ? AND domain_key = ? AND run_status = 'completed'
             ORDER BY completed_at DESC, id DESC LIMIT " . max(1, min(20, $limit)),
            [$workspaceId, $domainKey]
        );
    }

    private function isSevereBreach(array $metrics, array $control, array $metadata, array $drift): bool
    {
        return (float) ($metrics['reversal_rate'] ?? 0) > ((float) ($control['max_reversal_rate_to_promote'] ?? 0.08) * 1.5)
            || (float) ($metrics['edit_after_autonomy_rate'] ?? 0) > ((float) ($control['max_edit_rate_to_promote'] ?? 0.12) * 1.5)
            || (float) ($metrics['duplicate_action_rate'] ?? 0) > ((float) ($metadata['max_duplicate_rate_to_promote'] ?? 0.05) * 1.5)
            || !empty($drift['unstable']);
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
