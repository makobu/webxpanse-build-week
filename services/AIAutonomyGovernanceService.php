<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyGovernanceService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyDomainControlService $controls = null,
        private ?AIAutonomyPromotionGateService $promotion = null,
        private ?AIAutonomyDriftMonitoringService $drift = null,
        private ?AIAutonomyIncidentService $incidents = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
        $this->promotion = $this->promotion ?? new AIAutonomyPromotionGateService();
        $this->drift = $this->drift ?? new AIAutonomyDriftMonitoringService();
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function evaluate(string $tenantKey, string $domainKey, string $actionKey, array $context, array $policyDecision = []): array
    {
        $control = $this->controls->get($tenantKey, $domainKey);
        $metadata = (array) ($control['metadata'] ?? []);
        $promotion = $this->promotion->evaluate($tenantKey, $domainKey, true);
        $drift = (array) ($promotion['drift'] ?? $this->drift->summarize($tenantKey, $domainKey, $actionKey));
        $customerFacing = $this->isCustomerFacing($actionKey, $context);
        $riskScore = $this->riskScore($actionKey, $context);
        $reasonCodes = [];
        $decision = 'allow';
        $mode = (string) ($control['autonomy_mode'] ?? 'suggest_only');
        $effectiveDailyCap = $metadata['temporary_daily_auto_action_cap'] ?? $metadata['max_daily_auto_actions'] ?? 50;

        if (($policyDecision['decision'] ?? '') === 'reject') {
            return [
                'decision' => 'blocked',
                'reason_codes' => (array) ($policyDecision['reasons'] ?? ['hard_blocker']),
                'control' => $control,
                'promotion' => $promotion,
                'drift' => $drift,
                'risk_score' => $riskScore,
                'customer_facing' => $customerFacing,
                'incident_id' => null,
            ];
        }

        if (!empty($metadata['paused'])) {
            $decision = 'blocked';
            $reasonCodes[] = 'domain_paused';
        }

        if ($decision === 'allow' && !empty($metadata['pause_customer_facing_only']) && $customerFacing) {
            $decision = 'approval_required';
            $reasonCodes[] = 'customer_facing_paused';
        }

        if ($decision === 'allow' && !empty($metadata['manual_freeze'])) {
            $decision = 'approval_required';
            $reasonCodes[] = 'manual_freeze_active';
        }

        if ($decision === 'allow' && !empty($metadata['forced_safe_mode']) && $mode === 'full_auto') {
            $decision = 'approval_required';
            $reasonCodes[] = 'forced_safe_mode_active';
        }

        $allowedActions = (array) ($metadata['allowed_actions'] ?? []);
        if ($allowedActions !== [] && !in_array($actionKey, $allowedActions, true)) {
            $decision = 'blocked';
            $reasonCodes[] = 'action_outside_envelope';
        }

        $humanCheckpointActions = (array) ($metadata['require_human_checkpoint_actions'] ?? []);
        if ($decision === 'allow' && in_array($actionKey, $humanCheckpointActions, true)) {
            $decision = 'approval_required';
            $reasonCodes[] = 'human_checkpoint_required';
        }

        if ($decision === 'allow' && $customerFacing && $mode === 'full_auto' && !empty($metadata['block_customer_facing_full_auto'])) {
            $decision = 'approval_required';
            $reasonCodes[] = 'customer_facing_full_auto_blocked';
        }

        if ($decision === 'allow' && $customerFacing && $riskScore > (float) ($metadata['max_customer_facing_risk'] ?? 0.95)) {
            $decision = 'approval_required';
            $reasonCodes[] = 'customer_facing_risk_cap_exceeded';
        }

        if ($decision === 'allow' && $this->countDailyAutonomousActions($tenantKey, $domainKey) >= (int) $effectiveDailyCap) {
            $decision = 'blocked';
            $reasonCodes[] = 'daily_auto_action_cap_exceeded';
        }

        if ($decision === 'allow' && ($promotion['promotion_status'] ?? $control['promotion_status'] ?? 'suggest_only') === 'blocked') {
            $decision = 'approval_required';
            $reasonCodes[] = 'promotion_status_blocked';
        }

        if ($decision === 'allow' && !empty($drift['unstable']) && $mode === 'full_auto') {
            $decision = 'approval_required';
            $reasonCodes = array_merge($reasonCodes, (array) ($drift['unstable_reasons'] ?? ['drift_detected']));
        }

        $incidentId = null;
        if ($decision !== 'allow') {
            $severity = $decision === 'blocked' ? 'high' : 'medium';
            $incidentId = $this->incidents->recordIncident([
                'tenant_key' => $tenantKey,
                'domain_key' => $domainKey,
                'action_key' => $actionKey,
                'incident_key' => $decision === 'blocked' ? 'governance_block' : 'governance_review_required',
                'severity' => $severity,
                'reason_codes' => $reasonCodes,
                'details' => [
                    'action_key' => $actionKey,
                    'policy_decision' => $policyDecision,
                    'promotion' => $promotion,
                    'drift' => $drift,
                    'risk_score' => $riskScore,
                    'customer_facing' => $customerFacing,
                ],
                'linked_entity_type' => !empty($context['invoice']['id']) ? 'invoice' : (!empty($context['deal']['id']) ? 'deal' : (!empty($context['task']['id']) ? 'task' : (!empty($context['contact']['id']) || !empty($context['contact_id']) ? 'contact' : null))),
                'linked_entity_id' => !empty($context['invoice']['id']) ? (int) $context['invoice']['id'] : (!empty($context['deal']['id']) ? (int) $context['deal']['id'] : (!empty($context['task']['id']) ? (int) $context['task']['id'] : (!empty($context['contact']['id']) ? (int) $context['contact']['id'] : (!empty($context['contact_id']) ? (int) $context['contact_id'] : null)))),
            ]);
            if ($incidentId && in_array($decision, ['blocked', 'approval_required'], true)) {
                $this->incidents->queueRecovery([
                    'incident_id' => $incidentId,
                    'tenant_key' => $tenantKey,
                    'domain_key' => $domainKey,
                    'action_key' => $actionKey,
                    'suggested_manual_action' => 'Review governance blocker and decide whether to downgrade or manually execute the action',
                    'payload' => [
                        'reason_codes' => $reasonCodes,
                        'promotion' => $promotion,
                        'drift' => $drift,
                    ],
                ]);
            }
        }

        return [
            'decision' => $decision,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'control' => $control,
            'promotion' => $promotion,
            'drift' => $drift,
            'risk_score' => $riskScore,
            'customer_facing' => $customerFacing,
            'incident_id' => $incidentId,
        ];
    }

    private function countDailyAutonomousActions(string $tenantKey, string $domainKey): int
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return 0;
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS aggregate_count
             FROM ai_operator_demonstrations
             WHERE workspace_id = ?
               AND domain_key = ?
               AND actor_type IN ('system','ai')
               AND observed_at >= CURDATE()",
            [$workspaceId, $domainKey]
        );
        return (int) ($row['aggregate_count'] ?? 0);
    }

    private function isCustomerFacing(string $actionKey, array $context): bool
    {
        if (!empty($context['requires_customer_send'])) {
            return true;
        }
        return in_array($actionKey, ['send_document', 'resend_document', 'send_customer_reply', 'send_email', 'send_whatsapp', 'send_sms'], true);
    }

    private function riskScore(string $actionKey, array $context): float
    {
        $score = match ($actionKey) {
            'mark_paid' => 0.96,
            'finalize_invoice' => 0.9,
            'convert_to_invoice' => 0.86,
            'send_document', 'resend_document', 'send_customer_reply' => 0.82,
            'send_email', 'send_whatsapp', 'send_sms' => 0.82,
            'update_contact_field', 'update_deal_stage', 'change_stage' => 0.74,
            'cancel_document' => 0.8,
            default => 0.55,
        };
        if (empty($context['recipient']) && in_array($actionKey, ['send_document', 'resend_document', 'send_customer_reply', 'send_email', 'send_whatsapp', 'send_sms'], true)) {
            $score += 0.08;
        }
        if (!empty($context['has_zero_priced_recommendation'])) {
            $score += 0.06;
        }
        if ((float) ($context['requested_discount_percent'] ?? 0) > 10) {
            $score += 0.05;
        }
        return max(0.0, min(1.0, round($score, 4)));
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
