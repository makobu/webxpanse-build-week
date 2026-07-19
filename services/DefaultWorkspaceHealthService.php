<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceHealthService
{
    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
    }

    /**
     * @return array<string,mixed>
     */
    public function health(?int $actorUserId = null): array
    {
        $identity = $this->defaultWorkspace->health();
        $workspaceId = (int) ($identity['workspace_id'] ?? 0);
        $issues = (array) ($identity['issues'] ?? []);
        $warnings = (array) ($identity['warnings'] ?? []);
        $metrics = [
            'owner_contacts_active' => 0,
            'owner_contacts_inactive' => 0,
            'owner_contacts_stale' => 0,
            'sync_errors_open' => 0,
            'ops_events_open_high' => 0,
            'failed_billing_provider_workspaces' => 0,
            'channel_gap_workspaces' => 0,
            'recent_protected_audit_actions' => 0,
            'missing_seeded_assets' => 0,
            'missing_ai_prompt_overrides' => 0,
        ];
        $opsStatus = [];

        if ($workspaceId > 0) {
            try {
                $opsStatus = (new DefaultWorkspaceOperationalizationService())->status($actorUserId ?? 0);
                $diagnostics = (array) ($opsStatus['diagnostics'] ?? []);
                $metrics['missing_seeded_assets'] = count((array) ($diagnostics['missing'] ?? []));
                $metrics['missing_ai_prompt_overrides'] = count((array) ($diagnostics['components']['ai_prompts']['missing'] ?? []));
                foreach ((array) ($diagnostics['warnings'] ?? []) as $warning) {
                    $warnings[] = (string) $warning;
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Platform Ops operationalization diagnostics failed: ' . $e->getMessage();
            }

            if (Database::tableExists('default_workspace_owner_contacts')) {
                $row = Database::queryOne(
                    "SELECT
                        SUM(CASE WHEN relationship_status = 'active' THEN 1 ELSE 0 END) AS active_count,
                        SUM(CASE WHEN relationship_status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
                        SUM(CASE WHEN last_reconciled_at IS NULL OR last_reconciled_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale_count
                     FROM default_workspace_owner_contacts
                     WHERE default_workspace_id = ?",
                    [$workspaceId]
                ) ?: [];
                $metrics['owner_contacts_active'] = (int) ($row['active_count'] ?? 0);
                $metrics['owner_contacts_inactive'] = (int) ($row['inactive_count'] ?? 0);
                $metrics['owner_contacts_stale'] = (int) ($row['stale_count'] ?? 0);
            }

            if (Database::tableExists('default_workspace_owner_contact_sync_errors')) {
                $metrics['sync_errors_open'] = $this->count(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_owner_contact_sync_errors
                     WHERE default_workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)",
                    [$workspaceId]
                );
            }

            if (Database::tableExists('default_workspace_ops_events')) {
                $metrics['ops_events_open_high'] = $this->count(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status IN ('open','in_progress','waiting_on_owner','waiting_on_provider')
                       AND severity IN ('warning','critical')",
                    [$workspaceId]
                );
            }

            if (Database::tableExists('billing_provider_events')) {
                $metrics['failed_billing_provider_workspaces'] = $this->count(
                    "SELECT COUNT(DISTINCT workspace_id) AS c
                     FROM billing_provider_events
                     WHERE workspace_id IS NOT NULL
                       AND workspace_id <> ?
                       AND processing_status = 'failed'",
                    [$workspaceId]
                );
            }

            if (Database::tableExists('email_integrations')) {
                $metrics['channel_gap_workspaces'] = $this->count(
                    "SELECT COUNT(*) AS c
                     FROM workspaces w
                     WHERE w.id <> ?
                       AND w.status IN ('active', 'trialing')
                       AND NOT EXISTS (
                           SELECT 1 FROM email_integrations ei WHERE ei.workspace_id = w.id AND ei.is_active = 1
                       )",
                    [$workspaceId]
                );
            }

            if (Database::tableExists('operator_audit_log')) {
                $metrics['recent_protected_audit_actions'] = $this->count(
                    "SELECT COUNT(*) AS c
                     FROM operator_audit_log
                     WHERE target_workspace_id = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       AND action_type LIKE 'default_workspace_%'",
                    [$workspaceId]
                );
            }
        }

        if (empty($identity['healthy'])) {
            $status = 'critical';
        } elseif (
            $metrics['sync_errors_open'] > 0
            || $metrics['ops_events_open_high'] > 0
            || $metrics['missing_seeded_assets'] > 0
            || $metrics['missing_ai_prompt_overrides'] > 0
        ) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'healthy' => $status === 'ok',
            'identity_health' => $identity,
            'operationalization_status' => $opsStatus,
            'metrics' => $metrics,
            'issues' => $issues,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    /**
     * @param array<int,mixed> $params
     */
    private function count(string $sql, array $params = []): int
    {
        return (int) ((Database::queryOne($sql, $params) ?: [])['c'] ?? 0);
    }
}
