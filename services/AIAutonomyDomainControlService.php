<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyDomainControlService
{
    private AIWorkspaceScopeService $workspaceScope;
    private SystemContextRegistryService $contextRegistry;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null, ?SystemContextRegistryService $contextRegistry = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
        $this->contextRegistry = $contextRegistry ?? new SystemContextRegistryService();
    }

    public function get(?string $tenantKey = null, string $domainKey = 'commercial_mvp'): array
    {
        $defaults = $this->defaultsForDomain($domainKey);
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $resolvedTenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        if (!$this->tableExists('ai_autonomy_domain_controls')) {
            return array_merge($defaults, ['tenant_key' => $resolvedTenantKey, 'workspace_id' => $workspaceId, 'domain_key' => $domainKey]);
        }

        $tenantRow = $this->fetchRow($workspaceId, $resolvedTenantKey, $domainKey);
        if (!$tenantRow) {
            return array_merge($defaults, [
                'tenant_key' => $resolvedTenantKey,
                'workspace_id' => $workspaceId,
                'domain_key' => $domainKey,
                'resolved_from_global' => false,
            ]);
        }

        return $this->hydrateRow($tenantRow, $defaults, $resolvedTenantKey, $domainKey, $workspaceId);
    }

    public function save(?string $tenantKey, string $domainKey, array $data, ?int $userId = null): void
    {
        if (!$this->tableExists('ai_autonomy_domain_controls')) {
            return;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $resolvedTenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $current = $this->get($resolvedTenantKey, $domainKey);
        $merged = array_merge($current, $data);
        $merged['autonomy_mode'] = in_array((string) ($merged['autonomy_mode'] ?? 'suggest_only'), ['suggest_only', 'auto_safe', 'full_auto'], true)
            ? (string) $merged['autonomy_mode']
            : 'suggest_only';
        $merged['promotion_status'] = in_array((string) ($merged['promotion_status'] ?? 'suggest_only'), ['suggest_only', 'auto_safe', 'full_auto', 'blocked'], true)
            ? (string) $merged['promotion_status']
            : 'suggest_only';
        $metadata = array_key_exists('metadata', $data)
            ? (array) $data['metadata']
            : (array) ($current['metadata'] ?? []);
        $merged['metadata'] = $this->normalizeMetadata($metadata);
        $baseDefaults = $this->contextRegistry->baseAutomationDomainDefaults();
        $merged['metadata']['auto_downgrade_on_drift'] = !empty($data['auto_downgrade_on_drift'] ?? $current['auto_downgrade_on_drift'] ?? $baseDefaults['auto_downgrade_on_drift'] ?? true);

        Database::execute(
            "INSERT INTO ai_autonomy_domain_controls
                (workspace_id, tenant_key, domain_key, autonomy_mode, demonstration_capture_enabled, policy_learning_enabled,
                 review_ui_enabled, fast_promotion_enabled, promotion_status, min_precision_to_promote,
                 max_reversal_rate_to_promote, max_edit_rate_to_promote, metadata_json, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                workspace_id = VALUES(workspace_id),
                autonomy_mode = VALUES(autonomy_mode),
                demonstration_capture_enabled = VALUES(demonstration_capture_enabled),
                policy_learning_enabled = VALUES(policy_learning_enabled),
                review_ui_enabled = VALUES(review_ui_enabled),
                fast_promotion_enabled = VALUES(fast_promotion_enabled),
                promotion_status = VALUES(promotion_status),
                min_precision_to_promote = VALUES(min_precision_to_promote),
                max_reversal_rate_to_promote = VALUES(max_reversal_rate_to_promote),
                max_edit_rate_to_promote = VALUES(max_edit_rate_to_promote),
                metadata_json = VALUES(metadata_json),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP",
            [
                $workspaceId,
                $resolvedTenantKey,
                $domainKey,
                $merged['autonomy_mode'],
                !empty($merged['demonstration_capture_enabled']) ? 1 : 0,
                !empty($merged['policy_learning_enabled']) ? 1 : 0,
                !empty($merged['review_ui_enabled']) ? 1 : 0,
                !empty($merged['fast_promotion_enabled']) ? 1 : 0,
                $merged['promotion_status'],
                max(0.0, min(1.0, (float) ($merged['min_precision_to_promote'] ?? 0.9))),
                max(0.0, min(1.0, (float) ($merged['max_reversal_rate_to_promote'] ?? 0.08))),
                max(0.0, min(1.0, (float) ($merged['max_edit_rate_to_promote'] ?? 0.12))),
                json_encode((array) ($merged['metadata'] ?? [])),
                $userId,
            ]
        );
    }

    public function listAll(): array
    {
        if (!$this->tableExists('ai_autonomy_domain_controls')) {
            return [];
        }
        return Database::query(
            "SELECT *
             FROM ai_autonomy_domain_controls
             WHERE workspace_id = ?
             ORDER BY domain_key ASC, id ASC",
            [$this->workspaceScope->requireWorkspaceId()]
        );
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

    private function normalizeMetadata(array $metadata): array
    {
        $baseDefaults = $this->contextRegistry->baseAutomationDomainDefaults();
        $defaultMetadata = (array) ($baseDefaults['metadata'] ?? []);
        $normalized = array_merge($defaultMetadata, $metadata);
        $normalized['allowed_actions'] = array_values(array_unique(array_filter(array_map('strval', (array) ($normalized['allowed_actions'] ?? [])))));
        $normalized['require_human_checkpoint_actions'] = array_values(array_unique(array_filter(array_map('strval', (array) ($normalized['require_human_checkpoint_actions'] ?? [])))));
        $normalized['max_daily_auto_actions'] = max(0, (int) ($normalized['max_daily_auto_actions'] ?? $defaultMetadata['max_daily_auto_actions'] ?? 50));
        $normalized['max_customer_facing_risk'] = max(0.0, min(1.0, (float) ($normalized['max_customer_facing_risk'] ?? $defaultMetadata['max_customer_facing_risk'] ?? 0.95)));
        $normalized['block_customer_facing_full_auto'] = !empty($normalized['block_customer_facing_full_auto']);
        $normalized['min_sample_size_to_promote'] = max(0, (int) ($normalized['min_sample_size_to_promote'] ?? $defaultMetadata['min_sample_size_to_promote'] ?? 10));
        $normalized['max_duplicate_rate_to_promote'] = max(0.0, min(1.0, (float) ($normalized['max_duplicate_rate_to_promote'] ?? $defaultMetadata['max_duplicate_rate_to_promote'] ?? 0.05)));
        $normalized['max_override_rate_to_promote'] = max(0.0, min(1.0, (float) ($normalized['max_override_rate_to_promote'] ?? $defaultMetadata['max_override_rate_to_promote'] ?? 0.12)));
        $normalized['min_eval_runs_to_promote'] = max(1, (int) ($normalized['min_eval_runs_to_promote'] ?? $defaultMetadata['min_eval_runs_to_promote'] ?? 1));
        $normalized['auto_downgrade_on_drift'] = !empty($normalized['auto_downgrade_on_drift']);
        $normalized['manual_freeze'] = !empty($normalized['manual_freeze']);
        $normalized['paused'] = !empty($normalized['paused']);
        $normalized['pause_customer_facing_only'] = !empty($normalized['pause_customer_facing_only']);
        $normalized['forced_safe_mode'] = !empty($normalized['forced_safe_mode']);
        $normalized['approval_required_for_promotion'] = !empty($normalized['approval_required_for_promotion']);
        $normalized['temporary_daily_auto_action_cap'] = $normalized['temporary_daily_auto_action_cap'] === null || $normalized['temporary_daily_auto_action_cap'] === ''
            ? null
            : max(0, (int) $normalized['temporary_daily_auto_action_cap']);
        $normalized['latest_rollout_reason'] = trim((string) ($normalized['latest_rollout_reason'] ?? ''));
        return $normalized;
    }

    private function defaultsForDomain(string $domainKey): array
    {
        return $this->contextRegistry->automationDomainDefaults($domainKey);
    }

    private function fetchRow(int $workspaceId, string $tenantKey, string $domainKey): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM ai_autonomy_domain_controls
             WHERE workspace_id = ?
               AND (tenant_key = ? OR tenant_key = ?)
               AND domain_key = ?
             ORDER BY CASE WHEN tenant_key = ? THEN 0 ELSE 1 END
             LIMIT 1",
            [$workspaceId, $tenantKey, $this->workspaceScope->workspaceTenantKey($workspaceId), $domainKey, $this->workspaceScope->workspaceTenantKey($workspaceId)]
        );
    }

    private function hydrateRow(?array $row, array $defaults, string $tenantKey, string $domainKey, int $workspaceId): array
    {
        if (!$row) {
            return array_merge($defaults, ['tenant_key' => $tenantKey, 'workspace_id' => $workspaceId, 'domain_key' => $domainKey]);
        }

        return [
            'workspace_id' => (int) ($row['workspace_id'] ?? $workspaceId),
            'tenant_key' => (string) ($row['tenant_key'] ?? $tenantKey),
            'domain_key' => (string) ($row['domain_key'] ?? $domainKey),
            'autonomy_mode' => (string) ($row['autonomy_mode'] ?? $defaults['autonomy_mode']),
            'demonstration_capture_enabled' => !empty($row['demonstration_capture_enabled']),
            'policy_learning_enabled' => !empty($row['policy_learning_enabled']),
            'review_ui_enabled' => !empty($row['review_ui_enabled']),
            'fast_promotion_enabled' => !empty($row['fast_promotion_enabled']),
            'auto_downgrade_on_drift' => !empty(($this->decodeJson($row['metadata_json'] ?? null)['auto_downgrade_on_drift'] ?? ($this->contextRegistry->baseAutomationDomainDefaults()['auto_downgrade_on_drift'] ?? true))),
            'promotion_status' => (string) ($row['promotion_status'] ?? $defaults['promotion_status']),
            'min_precision_to_promote' => (float) ($row['min_precision_to_promote'] ?? $defaults['min_precision_to_promote']),
            'max_reversal_rate_to_promote' => (float) ($row['max_reversal_rate_to_promote'] ?? $defaults['max_reversal_rate_to_promote']),
            'max_edit_rate_to_promote' => (float) ($row['max_edit_rate_to_promote'] ?? $defaults['max_edit_rate_to_promote']),
            'metadata' => $this->normalizeMetadata($this->decodeJson($row['metadata_json'] ?? null)),
            'updated_by' => !empty($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function minPositiveInt(int $first, int $second): int
    {
        if ($first <= 0) {
            return $second;
        }
        if ($second <= 0) {
            return $first;
        }
        return min($first, $second);
    }

    private function minNullableInt($first, $second): ?int
    {
        $first = $first === null || $first === '' ? null : (int) $first;
        $second = $second === null || $second === '' ? null : (int) $second;
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }
        return min($first, $second);
    }
}
