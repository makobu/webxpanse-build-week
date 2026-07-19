<?php

namespace CRM\Services;

class WorkflowAutomationControlService
{
    public const GLOBAL_TENANT_KEY = 'global:default';
    public const DOMAIN_KEY = 'workflow_execution';
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyDomainControlService $controls = null,
        private ?AIAutonomyPromotionGateService $promotionGate = null,
        private ?AutoAdminService $autoAdmin = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->controls = $this->controls ?? new AIAutonomyDomainControlService();
        $this->promotionGate = $this->promotionGate ?? new AIAutonomyPromotionGateService();
        $this->autoAdmin = $this->autoAdmin ?? new AutoAdminService();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function getWorkspaceControl(): array
    {
        $control = $this->controls->get($this->currentTenantKey(), self::DOMAIN_KEY);
        $control['managed_by_auto_admin'] = $this->isManagedByAutoAdmin();
        return $control;
    }

    public function saveWorkspaceControl(array $data, ?int $userId = null): array
    {
        $existing = $this->getWorkspaceControl();
        $metadata = array_merge((array) ($existing['metadata'] ?? []), (array) ($data['metadata'] ?? []));
        $payload = array_merge($existing, $data, ['metadata' => $metadata]);
        $payload['autonomy_mode'] = $this->normalizeMode((string) ($payload['autonomy_mode'] ?? 'suggest_only'));
        $payload['promotion_status'] = $this->normalizePromotionStatus((string) ($payload['promotion_status'] ?? $payload['autonomy_mode']));

        $this->controls->save($this->currentTenantKey(), self::DOMAIN_KEY, $payload, $userId);
        return $this->getWorkspaceControl();
    }

    public function buildSettingsState(): array
    {
        $control = $this->getWorkspaceControl();
        $promotion = $this->promotionGate->evaluate($this->currentTenantKey(), self::DOMAIN_KEY, false);

        return [
            'control' => $control,
            'promotion' => $promotion,
            'managed_by_auto_admin' => $this->isManagedByAutoAdmin(),
            'mode_label' => $this->labelForMode((string) ($control['autonomy_mode'] ?? 'suggest_only')),
        ];
    }

    public function isManagedByAutoAdmin(): bool
    {
        try {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
        } catch (\Throwable $e) {
            $workspaceId = 0;
        }

        return $this->autoAdmin->isEnabledForWorkspace($workspaceId) && $this->autoAdmin->isManagedTab('workflow_automation');
    }

    public function labelForMode(string $mode): string
    {
        return match ($this->normalizeMode($mode)) {
            'auto_safe' => 'Auto-safe',
            'full_auto' => 'Full auto',
            default => 'Suggest only',
        };
    }

    public function normalizeMode(string $mode): string
    {
        return in_array($mode, ['suggest_only', 'auto_safe', 'full_auto'], true) ? $mode : 'suggest_only';
    }

    public function normalizePromotionStatus(string $status): string
    {
        return in_array($status, ['suggest_only', 'auto_safe', 'full_auto', 'blocked'], true) ? $status : 'suggest_only';
    }

    private function currentTenantKey(): string
    {
        return $this->workspaceScope->currentTenantKey();
    }
}
