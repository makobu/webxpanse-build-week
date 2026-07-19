<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\DealAutomationConfig;

class DealAutomationReadinessService
{
    private DealAutomationConfig $config;
    private AutoAdminService $autoAdmin;

    public function __construct()
    {
        $this->config = new DealAutomationConfig();
        $this->autoAdmin = new AutoAdminService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $config = $this->config->get($workspaceId > 0 ? $workspaceId : null);
        $currentMode = !empty($config['enabled']) ? (string) ($config['mode'] ?? 'suggest_only') : 'manual';
        $auditSummary = $this->getAuditSummary($subjectUserId, $workspaceId > 0 ? $workspaceId : null);
        $readinessChecks = [
            'automation_enabled' => !empty($config['enabled']),
            'has_transition_rules' => !empty($config['transitions']),
            'dry_run_disabled' => empty($config['dry_run']),
            'recent_audit_volume' => (int) ($auditSummary['recent_total'] ?? 0) >= 5,
            'recent_stability' => !$this->hasRecentInstability($auditSummary),
            'terminal_stage_safety' => $this->hasSafeTerminalSettings($config),
        ];

        $blockingReasons = [];
        if (!$readinessChecks['automation_enabled']) {
            $blockingReasons[] = 'Deal automation is disabled.';
        }
        if (!$readinessChecks['has_transition_rules']) {
            $blockingReasons[] = 'No transition rules are configured.';
        }
        if (!$readinessChecks['dry_run_disabled']) {
            $blockingReasons[] = 'Dry run is still enabled.';
        }
        if (!$readinessChecks['recent_audit_volume']) {
            $blockingReasons[] = 'Not enough recent automation audit history yet.';
        }
        if (!$readinessChecks['recent_stability']) {
            $blockingReasons[] = 'Recent audit history shows unstable automation outcomes.';
        }
        if (!$readinessChecks['terminal_stage_safety']) {
            $blockingReasons[] = 'Terminal-stage safety settings are not strict enough.';
        }

        $readinessStatus = empty($blockingReasons) ? 'ready' : 'not_ready';
        if ($currentMode === 'full_auto' && !empty($blockingReasons)) {
            $readinessStatus = 'needs_attention';
        }

        return [
            'current_mode' => $currentMode,
            'current_mode_label' => $this->getModeLabel($currentMode),
            'is_managed_by_auto_admin' => $this->autoAdmin->isEnabledForWorkspace($workspaceId) && $this->autoAdmin->isManagedTab('deal_automation'),
            'readiness_status' => $readinessStatus,
            'readiness_checks' => $readinessChecks,
            'blocking_reasons' => $blockingReasons,
            'recent_audit_summary' => $auditSummary,
            'config' => $config,
        ];
    }

    public function canPromoteToFullAuto(?int $subjectUserId = null, ?int $workspaceId = null): bool
    {
        $state = $this->getState($subjectUserId, $workspaceId);
        return empty($state['blocking_reasons']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAuditSummary(?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $recentTotal = 0;
        $recentApplied = 0;
        $recentRejects = 0;
        $recentRows = [];

        if ($this->tableExists('deal_automation_audit')) {
            $params = [];
            $where = ["daa.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)"];
            if ($workspaceId !== null && $workspaceId > 0) {
                if ($this->columnExists('deal_automation_audit', 'workspace_id')) {
                    $where[] = 'daa.workspace_id = ?';
                    $params[] = $workspaceId;
                } else {
                    $where[] = 'COALESCE(d.workspace_id, c.workspace_id) = ?';
                    $params[] = $workspaceId;
                }
            }
            if ($subjectUserId !== null) {
                $where[] = '(d.assigned_to = ? OR c.assigned_to = ?)';
                $params[] = $subjectUserId;
                $params[] = $subjectUserId;
            }
            $summary = Database::queryOne(
                "SELECT
                    COUNT(DISTINCT daa.id) AS total_count,
                    SUM(CASE WHEN daa.applied = 1 THEN 1 ELSE 0 END) AS applied_count,
                    SUM(CASE WHEN daa.decision = 'reject' THEN 1 ELSE 0 END) AS reject_count
                 FROM deal_automation_audit daa
                 LEFT JOIN deals d ON d.id = daa.deal_id
                 LEFT JOIN contacts c ON c.id = COALESCE(daa.contact_id, d.contact_id)
                 WHERE " . implode(' AND ', $where),
                $params
            );
            $recentTotal = (int) ($summary['total_count'] ?? 0);
            $recentApplied = (int) ($summary['applied_count'] ?? 0);
            $recentRejects = (int) ($summary['reject_count'] ?? 0);
            $recentRows = Database::query(
                "SELECT daa.decision, daa.applied, daa.reason, daa.created_at
                 FROM deal_automation_audit daa
                 LEFT JOIN deals d ON d.id = daa.deal_id
                 LEFT JOIN contacts c ON c.id = COALESCE(daa.contact_id, d.contact_id)
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY daa.created_at DESC
                 LIMIT 10",
                $params
            );
        }

        return [
            'recent_total' => $recentTotal,
            'recent_applied' => $recentApplied,
            'recent_rejects' => $recentRejects,
            'recent_rows' => $recentRows,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hasSafeTerminalSettings(array $config): bool
    {
        return !empty($config['require_approval_terminal'])
            || (float) ($config['min_terminal_confidence'] ?? 0) >= 0.90;
    }

    /**
     * @param array<string, mixed> $auditSummary
     */
    private function hasRecentInstability(array $auditSummary): bool
    {
        $rows = $auditSummary['recent_rows'] ?? [];
        if (!is_array($rows) || count($rows) < 5) {
            return false;
        }

        $rejects = 0;
        $applied = 0;
        foreach ($rows as $row) {
            if (($row['decision'] ?? '') === 'reject') {
                $rejects++;
            }
            if (!empty($row['applied'])) {
                $applied++;
            }
        }

        return $rejects >= 8 && $applied === 0;
    }

    private function getModeLabel(string $mode): string
    {
        return match ($mode) {
            'manual' => 'Manual',
            'suggest_only' => 'Suggest only',
            'auto_safe' => 'Auto-safe',
            'full_auto' => 'Full auto',
            default => ucfirst(str_replace('_', ' ', $mode)),
        };
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );
            return ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return Database::columnExists($table, $column);
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
