<?php

namespace CRM\Services;

use CRM\Database;

class AutomationProgressionPolicyService
{
    private const MODE_ORDER = [
        'manual' => 0,
        'off' => 0,
        'draft_only' => 1,
        'suggest_only' => 1,
        'hybrid' => 2,
        'auto_safe' => 2,
        'full_auto' => 3,
    ];

    /**
     * @param array<string,mixed> $managedDefaults
     * @param array<string,mixed> $workspaceSettings
     * @return array{effective_modes:array<string,string>,readiness_snapshot:array<string,mixed>,commercial_auto_send_ready:bool,runtime_blocked_domains:array<int,string>}
     */
    public function evaluateWorkspace(int $workspaceId, array $managedDefaults, array $workspaceSettings = []): array
    {
        $targetModes = (array) ($workspaceSettings['target_modes'] ?? []);
        $readiness = [];
        $runtimeBlockedDomains = [];

        $dealTarget = (string) ($targetModes['deal_automation'] ?? ($managedDefaults['deal_automation']['mode'] ?? 'suggest_only'));
        $dealState = (new DealAutomationReadinessService())->getState(null, $workspaceId);
        $dealFullAutoReady = empty($dealState['blocking_reasons']);
        $dealCeiling = $dealFullAutoReady ? 'full_auto' : 'auto_safe';
        $dealRuntime = $this->runtimeControlCeiling($workspaceId, 'assistant', 'deal_automation');
        $dealCeiling = $this->capMode($dealCeiling, (string) $dealRuntime['ceiling']);
        if (!empty($dealRuntime['blocking_reasons'])) {
            $runtimeBlockedDomains[] = 'deal_automation';
        }
        $readiness['deal_automation'] = [
            'target_mode' => $dealTarget,
            'ceiling' => $dealCeiling,
            'readiness_status' => (string) ($dealState['readiness_status'] ?? 'not_ready'),
            'blocking_reasons' => array_values(array_unique(array_merge(
                (array) ($dealState['blocking_reasons'] ?? []),
                (array) $dealRuntime['blocking_reasons']
            ))),
            'runtime_control' => $dealRuntime['control'],
        ];

        $workflowTarget = (string) ($targetModes['workflow_automation'] ?? ($managedDefaults['workflow_automation']['autonomy_mode'] ?? 'auto_safe'));
        $workflowPromotion = $this->workflowPromotionGate($workspaceId);
        $workflowCeiling = !empty($workflowPromotion['full_auto_allowed']) ? 'full_auto' : 'auto_safe';
        $workflowRuntime = $this->runtimeControlCeiling($workspaceId, 'workflow', 'workflow_automation');
        $workflowCeiling = $this->capMode($workflowCeiling, (string) $workflowRuntime['ceiling']);
        if (!empty($workflowRuntime['blocking_reasons'])) {
            $runtimeBlockedDomains[] = 'workflow_automation';
        }
        $readiness['workflow_automation'] = [
            'target_mode' => $workflowTarget,
            'ceiling' => $workflowCeiling,
            'promotion_gate' => $workflowPromotion['gate'],
            'blocking_reasons' => array_values(array_unique(array_merge(
                (array) $workflowPromotion['blocking_reasons'],
                (array) $workflowRuntime['blocking_reasons']
            ))),
            'runtime_control' => $workflowRuntime['control'],
        ];

        $aiResponderTarget = (string) ($targetModes['ai_autoresponder'] ?? ($managedDefaults['ai_autoresponder']['mode'] ?? 'draft_only'));
        $aiResponderRuntime = $this->runtimeControlCeiling($workspaceId, 'customer_thread', 'ai_autoresponder', 'draft_only');
        if (!empty($aiResponderRuntime['blocking_reasons'])) {
            $runtimeBlockedDomains[] = 'ai_autoresponder';
        }
        $readiness['ai_autoresponder'] = [
            'target_mode' => $aiResponderTarget,
            'ceiling' => 'draft_only',
            'blocking_reasons' => array_values(array_unique(array_merge(
                $aiResponderTarget === 'full_auto' ? ['AI auto-responder full auto is not enabled by Auto Admin v2.'] : [],
                (array) $aiResponderRuntime['blocking_reasons']
            ))),
            'runtime_control' => $aiResponderRuntime['control'],
        ];

        $commercialTarget = (string) ($targetModes['commercial_automation'] ?? ($managedDefaults['commercial_automation']['mode'] ?? 'auto_safe'));
        $commercialRuntime = $this->runtimeControlCeiling($workspaceId, 'commercial_assistant', 'commercial_automation');
        $commercialAutoSendReady = $this->commercialAutoSendReady($workspaceId) && empty($commercialRuntime['blocking_reasons']);
        if (!empty($commercialRuntime['blocking_reasons'])) {
            $runtimeBlockedDomains[] = 'commercial_automation';
        }
        $readiness['commercial_automation'] = [
            'target_mode' => $commercialTarget,
            'ceiling' => $this->capMode('auto_safe', (string) $commercialRuntime['ceiling']),
            'auto_send_ready' => $commercialAutoSendReady,
            'blocking_reasons' => array_values(array_unique(array_merge(
                $commercialAutoSendReady ? [] : ['Communication, invoice, or runtime readiness is incomplete.'],
                (array) $commercialRuntime['blocking_reasons']
            ))),
            'runtime_control' => $commercialRuntime['control'],
        ];

        return [
            'effective_modes' => [
                'deal_automation' => $this->capMode($dealTarget, $dealCeiling),
                'workflow_automation' => $this->capMode($workflowTarget, $workflowCeiling),
                'ai_autoresponder' => $this->capMode($aiResponderTarget, 'draft_only'),
                'commercial_automation' => $this->capMode($commercialTarget, (string) $readiness['commercial_automation']['ceiling']),
            ],
            'readiness_snapshot' => $readiness,
            'commercial_auto_send_ready' => $commercialAutoSendReady,
            'runtime_blocked_domains' => array_values(array_unique($runtimeBlockedDomains)),
        ];
    }

    private function capMode(string $target, string $ceiling): string
    {
        $target = $this->normalizeMode($target);
        $ceiling = $this->normalizeMode($ceiling);

        return (self::MODE_ORDER[$target] ?? 0) <= (self::MODE_ORDER[$ceiling] ?? 0)
            ? $target
            : $ceiling;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return array_key_exists($mode, self::MODE_ORDER) ? $mode : 'suggest_only';
    }

    private function commercialAutoSendReady(int $workspaceId): bool
    {
        $communicationReady = false;
        try {
            $communicationReady = (new WorkspaceCommunicationGateService())->isRuntimeReady($workspaceId);
        } catch (\Throwable $e) {
            $communicationReady = false;
        }

        $invoiceReady = $this->workspaceInvoiceReady($workspaceId);

        return $communicationReady && $invoiceReady;
    }

    private function workspaceInvoiceReady(int $workspaceId): bool
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_onboarding_state')) {
            return false;
        }

        try {
            $row = Database::queryOne(
                "SELECT optional_setup_json
                 FROM workspace_onboarding_state
                 WHERE workspace_id = ?
                 LIMIT 1",
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        if (!$row) {
            return false;
        }

        $optional = $this->decodeJson($row['optional_setup_json'] ?? null);
        $invoiceSetup = (array) ($optional['invoice_setup'] ?? []);

        return !empty($invoiceSetup['enabled'])
            && !empty($invoiceSetup['has_payment_instructions']);
    }

    /**
     * @return array{ceiling:string,blocking_reasons:array<int,string>,control:array<string,mixed>}
     */
    private function runtimeControlCeiling(int $workspaceId, string $surface, string $domain, string $blockedCeiling = 'suggest_only'): array
    {
        $control = [];
        try {
            $control = (new AIRuntimeControlService())->getEffectiveControl($surface, $workspaceId);
        } catch (\Throwable $e) {
            $control = ['control_mode' => 'normal', 'surface' => $surface, 'effective_surface' => $surface];
        }

        $mode = (string) ($control['control_mode'] ?? 'normal');
        if (in_array($mode, ['paused', 'diagnostics_only', 'suggest_only'], true)) {
            return [
                'ceiling' => $blockedCeiling,
                'blocking_reasons' => [sprintf('Runtime control %s is active for %s.', $mode, $domain)],
                'control' => $control,
            ];
        }

        return [
            'ceiling' => 'full_auto',
            'blocking_reasons' => [],
            'control' => $control,
        ];
    }

    /**
     * @return array{full_auto_allowed:bool,gate:array<string,mixed>,blocking_reasons:array<int,string>}
     */
    private function workflowPromotionGate(int $workspaceId): array
    {
        try {
            $tenantKey = 'workspace:' . $workspaceId;
            $gate = (new AIAutonomyPromotionGateService())->evaluate($tenantKey, 'workflow_execution', false);
        } catch (\Throwable $e) {
            $gate = [
                'decision' => 'block',
                'recommended_mode' => 'auto_safe',
                'promotion_status' => 'blocked',
                'reasons' => ['promotion_gate_unavailable'],
            ];
        }

        $decision = (string) ($gate['decision'] ?? 'hold');
        $recommendedMode = (string) ($gate['recommended_mode'] ?? '');
        $fullAutoAllowed = $decision === 'promote' && $recommendedMode === 'full_auto';

        return [
            'full_auto_allowed' => $fullAutoAllowed,
            'gate' => $gate,
            'blocking_reasons' => $fullAutoAllowed ? [] : array_values(array_unique(array_merge(
                ['Workflow promotion gate has not approved full_auto.'],
                (array) ($gate['reasons'] ?? [])
            ))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
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
