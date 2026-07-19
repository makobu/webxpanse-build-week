<?php

namespace CRM\Services;

class AIAutonomyRolloutOperationsService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyDomainControlService $controls = null,
        private ?AIAutonomyPromotionGateService $promotion = null,
        private ?AIAutonomyDriftMonitoringService $drift = null,
        private ?AIAutonomyIncidentService $incidents = null,
        private ?AIDemonstrationCaptureService $capture = null
    ) {
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
        $this->promotion = $this->promotion ?? new AIAutonomyPromotionGateService();
        $this->drift = $this->drift ?? new AIAutonomyDriftMonitoringService();
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService();
        $this->capture = $this->capture ?? new AIDemonstrationCaptureService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function computeReadiness(?string $tenantKey, string $domainKey): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $control = $this->controls->get($tenantKey, $domainKey);
        $metadata = (array) ($control['metadata'] ?? []);
        $promotion = $this->promotion->evaluate($tenantKey, $domainKey, false);
        $drift = (array) ($promotion['drift'] ?? $this->drift->summarize($tenantKey, $domainKey));
        $ops = $this->incidents->summarizeOperationalState($tenantKey, $domainKey);

        $state = 'ready';
        $reasons = [];
        if (!empty($metadata['paused'])) {
            $state = 'paused';
            $reasons[] = 'domain_paused';
        } elseif (!empty($metadata['manual_freeze'])) {
            $state = 'manually_frozen';
            $reasons[] = 'manual_freeze_active';
        } elseif (!empty($metadata['forced_safe_mode'])) {
            $state = 'degraded';
            $reasons[] = 'forced_safe_mode_active';
        } elseif (!empty($drift['unstable'])) {
            $state = 'degraded';
            $reasons = array_merge($reasons, (array) ($drift['unstable_reasons'] ?? ['drift_unstable']));
        } elseif (($promotion['decision'] ?? 'hold') === 'block' || ($promotion['promotion_status'] ?? '') === 'blocked') {
            $state = 'not_ready';
            $reasons[] = 'promotion_blocked';
        }

        if (($ops['critical_incident_count'] ?? 0) > 0 || ($ops['failed_retry_count'] ?? 0) > 0) {
            $state = 'degraded';
            $reasons[] = 'operational_backlog';
        }

        return [
            'tenant_key' => $tenantKey,
            'domain_key' => $domainKey,
            'rollout_state' => $state,
            'reasons' => array_values(array_unique($reasons)),
            'control' => $control,
            'promotion' => $promotion,
            'drift' => $drift,
            'operational' => $ops,
        ];
    }

    public function applyOperatorAction(?string $tenantKey, string $domainKey, string $actionKey, array $payload, int $operatorUserId): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $before = $this->controls->get($tenantKey, $domainKey);
        $metadata = (array) ($before['metadata'] ?? []);
        $update = [];

        switch ($actionKey) {
            case 'downgrade_domain':
                $currentMode = (string) ($before['autonomy_mode'] ?? 'suggest_only');
                $update['autonomy_mode'] = $currentMode === 'full_auto' ? 'auto_safe' : 'suggest_only';
                $update['promotion_status'] = 'blocked';
                $metadata['forced_safe_mode'] = true;
                break;
            case 'pause_domain':
                $metadata['paused'] = true;
                break;
            case 'pause_customer_facing_only':
                $metadata['pause_customer_facing_only'] = true;
                break;
            case 'resume_domain':
                $metadata['paused'] = false;
                $metadata['pause_customer_facing_only'] = false;
                $metadata['forced_safe_mode'] = false;
                if (!empty($payload['clear_manual_freeze'])) {
                    $metadata['manual_freeze'] = false;
                }
                break;
            case 'freeze_domain':
                $metadata['manual_freeze'] = true;
                break;
            case 'approve_promotion':
                $promotion = $this->promotion->evaluate($tenantKey, $domainKey, false);
                $update['autonomy_mode'] = (string) ($promotion['recommended_mode'] ?? $before['autonomy_mode'] ?? 'suggest_only');
                $update['promotion_status'] = (string) ($promotion['recommended_mode'] ?? $before['promotion_status'] ?? 'suggest_only');
                $metadata['approval_required_for_promotion'] = false;
                break;
            case 'set_daily_cap_override':
                $metadata['temporary_daily_auto_action_cap'] = isset($payload['temporary_daily_auto_action_cap'])
                    ? max(0, (int) $payload['temporary_daily_auto_action_cap'])
                    : null;
                break;
            case 'disable_fast_promotion':
                $update['fast_promotion_enabled'] = false;
                break;
            case 'enable_fast_promotion':
                $update['fast_promotion_enabled'] = true;
                break;
            default:
                throw new \InvalidArgumentException('Unsupported rollout action.');
        }

        $metadata['latest_rollout_reason'] = trim((string) ($payload['reason'] ?? ''));
        $update['metadata'] = $metadata;
        $this->controls->save($tenantKey, $domainKey, $update, $operatorUserId);
        $after = $this->controls->get($tenantKey, $domainKey);

        $this->incidents->logOperatorAction([
            'tenant_key' => $tenantKey,
            'domain_key' => $domainKey,
            'operator_user_id' => $operatorUserId,
            'action_key' => $actionKey,
            'target_type' => 'domain_control',
            'reason' => (string) ($payload['reason'] ?? ''),
            'prior_state' => $before,
            'result_state' => $after,
            'metadata' => ['payload' => $payload],
        ]);

        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'actor_user_id' => $operatorUserId,
            'actor_type' => 'user',
            'source_surface' => 'recovery_workbench',
            'domain_key' => $domainKey,
            'entity_type' => 'domain_control',
            'entity_id' => null,
            'action_key' => $actionKey,
            'prior_state' => $before,
            'action_payload' => $payload,
            'outcome_state' => $after,
            'outcome_label' => 'operator_override',
            'free_text_reason' => (string) ($payload['reason'] ?? ''),
            'metadata' => ['workbench_action' => true],
            'was_successful' => true,
        ]);

        return $after;
    }
}
