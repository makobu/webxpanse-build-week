<?php

namespace CRM\Services;

use CRM\Database;

class TargetIntelligenceService
{
    private const DEFAULT_SCOPE = 'personal';
    private const DEFAULT_PROGRESS_MODE = 'manual';

    /** @var array<string, bool> */
    private static array $columnCache = [];
    private TargetPluginIntegrationService $pluginIntegration;
    private TargetMeasurementCollectorRegistry $collectors;

    public function __construct(?TargetPluginIntegrationService $pluginIntegration = null)
    {
        $this->pluginIntegration = $pluginIntegration ?? new TargetPluginIntegrationService();
        $this->collectors = new TargetMeasurementCollectorRegistry($this->pluginIntegration);
    }

    public function syncTarget(int $targetId, ?int $workspaceId = null, bool $evaluateCompletion = true): ?array
    {
        $workspaceId = $workspaceId ?: (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to synchronize target intelligence.');
        }
        $target = Database::queryOne("SELECT * FROM targets WHERE workspace_id = ? AND id = ?", [$workspaceId, $targetId]);
        if (!$target) {
            return null;
        }

        $enriched = $this->enrichTarget($target, true);
        $metadata = $this->decodeJson($target['metadata_json'] ?? null);
        $metadata['rollup_definition'] = $enriched['rollup_definition'] ?? ($metadata['rollup_definition'] ?? null);
        $metadata['intelligence'] = $enriched['intelligence'] ?? [];
        $metadata['milestone_summary'] = $enriched['milestone_summary'] ?? [];
        $metadata['rollup_explanation'] = $enriched['rollup_explanation'] ?? [];

        $currentValue = (float) ($enriched['current_value'] ?? 0);
        $status = (string) ($target['status'] ?? 'active');
        $completedAt = $target['completed_at'] ?? null;
        if ($status === 'completed' && $currentValue < (float) ($target['target_value'] ?? 0)) {
            $currentValue = max((float) ($target['current_value'] ?? 0), (float) ($target['target_value'] ?? 0));
            $enriched['current_value'] = $currentValue;
            $metadata['completion_regression_review'] = [
                'calculated_value' => (float) ($enriched['measurement']['rollup_value'] ?? 0),
                'observed_at' => date('Y-m-d H:i:s'),
                'reason' => 'Source records no longer support the completed value; review before reopening.',
            ];
        }

        Database::execute(
            "UPDATE targets
             SET current_value = ?, metadata_json = ?, state_version = state_version + 1
             WHERE workspace_id = ? AND id = ?",
            [$currentValue, json_encode($metadata, JSON_UNESCAPED_SLASHES), $workspaceId, $targetId]
        );

        $this->persistEvidence($target, (array) ($enriched['measurement']['evidence'] ?? []));

        $this->syncMilestones(
            $targetId,
            (int) ($target['workspace_id'] ?? 0),
            (float) ($enriched['current_value'] ?? 0),
            (array) ($metadata['milestones'] ?? [])
        );

        $enriched['status'] = $status;
        $enriched['completed_at'] = $completedAt;
        if ($evaluateCompletion && $status === 'active') {
            (new TargetCoordinator())->evaluateMeasuredTarget($targetId, $workspaceId, $enriched);
            $fresh = Database::queryOne('SELECT status, completed_at, completion_source, state_version FROM targets WHERE workspace_id = ? AND id = ?', [$workspaceId, $targetId]);
            if ($fresh) {
                $enriched = array_merge($enriched, $fresh);
            }
        }
        return $enriched;
    }

    public function syncTargets(array $filters = [], int $limit = 250): int
    {
        $workspaceId = (int) ($filters['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to scan target intelligence.');
        }
        $where = ["workspace_id = ?", "status IN ('active', 'missed', 'completed')", 'id > ?'];
        $params = [$workspaceId, max(0, (int) ($filters['cursor_target_id'] ?? 0))];

        if (!empty($filters['scope'])) {
            $where[] = "scope = ?";
            $params[] = $filters['scope'];
        }

        if ($this->hasProgressModeColumn() && !empty($filters['progress_mode'])) {
            $where[] = "progress_mode = ?";
            $params[] = $filters['progress_mode'];
        }

        $rows = Database::query(
            "SELECT id FROM targets WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT " . max(1, (int) $limit),
            $params
        );

        $count = 0;
        foreach ($rows as $row) {
            $targetId = (int) ($row['id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            $this->syncTarget($targetId, $workspaceId);
            $count++;
        }

        return $count;
    }

    public function refreshAfterEntityChange(string $entityType, ?int $entityId = null, array $context = []): int
    {
        $workspaceId = (int) ($context['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to refresh target intelligence.');
        }
        $source = strtolower(trim($entityType));
        (new TargetIntelligenceScanQueueService())->enqueue($workspaceId, $source, $entityId, $context);
        $aliases = $source === 'invoices' ? ['invoices', 'documents'] : [$source];
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $rows = Database::query(
            "SELECT id FROM targets
             WHERE workspace_id = ? AND status = 'active' AND progress_mode IN ('auto_rollup','hybrid')
               AND rollup_source IN ({$placeholders}) ORDER BY id ASC",
            array_merge([$workspaceId], $aliases)
        );
        $count = 0;
        foreach ($rows as $row) {
            $this->syncTarget((int) $row['id'], $workspaceId);
            $count++;
        }
        return $count;
    }

    public function getAdviceContext(int $targetId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }
        $target = Database::queryOne("SELECT * FROM targets WHERE workspace_id = ? AND id = ?", [$workspaceId, $targetId]);
        if (!$target) {
            return [];
        }

        return $this->enrichTarget($target, true);
    }

    public function enrichTarget(array $target, bool $withEvidence = false): array
    {
        $target = $this->normalizeTarget($target);
        $metadata = $this->decodeJson($target['metadata_json'] ?? null);
        $typedDefinition = array_filter([
            'source' => $target['rollup_source'] ?? null,
            'metric' => $target['rollup_metric'] ?? null,
            'filters' => $this->decodeJson($target['rollup_filters_json'] ?? null),
        ], static fn($value): bool => $value !== null && $value !== '' && $value !== []);
        $rollupDefinition = $this->normalizeRollupDefinition($typedDefinition ?: ($metadata['rollup_definition'] ?? []), (int) ($target['workspace_id'] ?? 0));

        $rollup = $this->computeRollup($target, $rollupDefinition, $withEvidence);
        $manualAdjustment = (float) ($target['manual_adjustment_value'] ?? 0);
        $storedCurrentValue = (float) ($target['current_value'] ?? 0);
        $currentValue = $storedCurrentValue;
        if ($target['progress_mode'] === 'auto_rollup') {
            $currentValue = (float) ($rollup['value'] ?? 0);
        } elseif ($target['progress_mode'] === 'hybrid') {
            $currentValue = max(0.0, (float) ($rollup['value'] ?? 0) + $manualAdjustment);
        }

        $target['current_value'] = round($currentValue, 2);
        $target['progress_percentage'] = $this->calculateProgress($target);
        $target['days_remaining'] = $this->getDaysRemaining($target);

        $milestoneSummary = $this->buildMilestoneSummary($target, $metadata, $currentValue);
        $target['rollup_definition'] = $rollupDefinition;
        $intelligence = $this->buildIntelligence($target, $rollup, $milestoneSummary);

        $target['intelligence'] = $intelligence;
        $target['status_band'] = (string) ($intelligence['status_band'] ?? $this->legacyStatusCategory($target, $intelligence));
        $target['status_category'] = $target['status_band'];
        $target['is_on_track'] = in_array($target['status_band'], ['on_track', 'completed'], true);
        $target['forecast_score'] = (float) ($intelligence['forecast_score'] ?? 0.0);
        $target['projected_completion_date'] = (string) ($intelligence['projected_completion_date'] ?? '');
        $target['pace_summary'] = (string) ($intelligence['pace_summary'] ?? '');
        $target['blockers'] = (array) ($intelligence['blockers'] ?? []);
        $target['next_best_actions'] = (array) ($intelligence['next_best_actions'] ?? []);
        $target['rollup_source_label'] = $this->formatRollupSourceLabel($rollupDefinition);
        $target['rollup_explanation'] = [
            'source' => $rollupDefinition['source'] ?? 'manual',
            'metric' => $rollupDefinition['metric'] ?? 'manual',
            'value' => (float) ($rollup['value'] ?? 0),
            'label' => (string) ($rollup['label'] ?? ''),
            'evidence' => (array) ($rollup['evidence'] ?? []),
            'matching_count' => (int) ($rollup['matching_count'] ?? 0),
            'calculated_at' => (string) ($rollup['calculated_at'] ?? ''),
            'missing_configuration' => (array) ($rollup['missing_configuration'] ?? []),
            'conflicts' => (array) ($rollup['conflicts'] ?? []),
        ];
        $target['measurement'] = [
            'mode' => (string) $target['progress_mode'],
            'source' => (string) ($rollup['source'] ?? $rollupDefinition['source'] ?? ''),
            'metric' => (string) ($rollup['metric'] ?? $rollupDefinition['metric'] ?? ''),
            'window' => (string) ($target['rollup_window'] ?? 'target_period'),
            'currency' => $target['currency_code'] ?? null,
            'rollup_value' => (float) ($rollup['value'] ?? 0),
            'manual_adjustment' => $manualAdjustment,
            'calculated_at' => (string) ($rollup['calculated_at'] ?? ''),
            'matching_count' => (int) ($rollup['matching_count'] ?? 0),
            'explanation' => (string) ($rollup['explanation'] ?? ''),
            'evidence' => (array) ($rollup['evidence'] ?? []),
            'missing_configuration' => (array) ($rollup['missing_configuration'] ?? []),
            'conflicts' => (array) ($rollup['conflicts'] ?? []),
        ];
        $target['milestone_summary'] = $milestoneSummary;

        return $target;
    }

    private function normalizeTarget(array $target): array
    {
        $target['scope'] = $this->hasScopeColumn()
            ? $this->normalizeScope($target['scope'] ?? self::DEFAULT_SCOPE)
            : self::DEFAULT_SCOPE;
        $target['progress_mode'] = $this->hasProgressModeColumn()
            ? $this->normalizeProgressMode($target['progress_mode'] ?? self::DEFAULT_PROGRESS_MODE)
            : self::DEFAULT_PROGRESS_MODE;
        $target['manual_adjustment_value'] = $this->hasManualAdjustmentColumn()
            ? (float) ($target['manual_adjustment_value'] ?? 0)
            : 0.0;
        return $target;
    }

    private function normalizeScope(mixed $scope): string
    {
        $scope = strtolower(trim((string) $scope));
        return in_array($scope, ['personal', 'team', 'company'], true) ? $scope : self::DEFAULT_SCOPE;
    }

    private function normalizeProgressMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, ['manual', 'auto_rollup', 'hybrid'], true) ? $mode : self::DEFAULT_PROGRESS_MODE;
    }

    private function normalizeRollupDefinition(mixed $definition, int $workspaceId = 0): array
    {
        return $this->pluginIntegration->normalizeRollupDefinition($workspaceId, $definition);
    }

    private function computeRollup(array $target, array $definition, bool $withEvidence): array
    {
        if ($definition === []) {
            return [
                'value' => 0.0,
                'label' => 'Manual target',
                'evidence' => [],
            ];
        }

        return $this->collectors->collect($target, $definition, $withEvidence);
    }

    private function computeDealRollup(array $target, array $definition, bool $withEvidence): array
    {
        $scopeSql = $this->buildDealScopeSql($target);
        $metric = $definition['metric'];
        $sql = match ($metric) {
            'closed_won_count' => "SELECT COUNT(*) AS value FROM deals d WHERE d.stage = 'closed_won' {$scopeSql['sql']}",
            'open_count' => "SELECT COUNT(*) AS value FROM deals d WHERE d.stage NOT IN ('closed_won', 'closed_lost') {$scopeSql['sql']}",
            'proposal_negotiation_count' => "SELECT COUNT(*) AS value FROM deals d WHERE d.stage IN ('proposal', 'negotiation') {$scopeSql['sql']}",
            'won_value' => "SELECT COALESCE(SUM(d.value), 0) AS value FROM deals d WHERE d.stage = 'closed_won' {$scopeSql['sql']}",
            'pipeline_value' => "SELECT COALESCE(SUM(d.value), 0) AS value FROM deals d WHERE d.stage NOT IN ('closed_won', 'closed_lost') {$scopeSql['sql']}",
            default => "SELECT 0 AS value",
        };
        $row = Database::queryOne($sql, $scopeSql['params']) ?: ['value' => 0];
        $evidence = [];
        if ($withEvidence) {
            $evidenceSql = match ($metric) {
                'won_value', 'closed_won_count' => "SELECT d.id, d.title, d.stage, d.value, d.updated_at, d.created_at
                                                     FROM deals d
                                                     WHERE d.stage = 'closed_won' {$scopeSql['sql']}
                                                     ORDER BY COALESCE(d.actual_close_date, d.updated_at, d.created_at) DESC LIMIT 5",
                'open_count', 'pipeline_value', 'proposal_negotiation_count' => "SELECT d.id, d.title, d.stage, d.value, d.updated_at, d.created_at
                                                     FROM deals d
                                                     WHERE " . ($metric === 'proposal_negotiation_count' ? "d.stage IN ('proposal', 'negotiation')" : "d.stage NOT IN ('closed_won', 'closed_lost')") . " {$scopeSql['sql']}
                                                     ORDER BY COALESCE(d.updated_at, d.created_at) DESC LIMIT 5",
                default => '',
            };
            if ($evidenceSql !== '') {
                foreach (Database::query($evidenceSql, $scopeSql['params']) as $rowItem) {
                    $evidence[] = [
                        'label' => (string) ($rowItem['title'] ?? 'Deal #' . (int) ($rowItem['id'] ?? 0)),
                        'value' => (float) ($rowItem['value'] ?? 0),
                        'status' => (string) ($rowItem['stage'] ?? ''),
                        'url' => publicUrl('deal_view.php?id=' . (int) ($rowItem['id'] ?? 0)),
                    ];
                }
            }
        }

        return [
            'value' => (float) ($row['value'] ?? 0),
            'label' => $this->formatRollupLabel($definition),
            'evidence' => $evidence,
        ];
    }

    private function computeInvoiceRollup(array $target, array $definition, bool $withEvidence): array
    {
        $scopeSql = $this->buildInvoiceScopeSql($target);
        $metric = $definition['metric'];
        $sql = match ($metric) {
            'paid_total' => "SELECT COALESCE(SUM(i.grand_total), 0) AS value FROM invoices i WHERE i.status IN ('paid', 'partially_paid') {$scopeSql['sql']}",
            'sent_total' => "SELECT COALESCE(SUM(i.grand_total), 0) AS value FROM invoices i WHERE i.status IN ('sent', 'viewed', 'accepted', 'finalized', 'partially_paid', 'paid', 'overdue') {$scopeSql['sql']}",
            'paid_count' => "SELECT COUNT(*) AS value FROM invoices i WHERE i.status = 'paid' {$scopeSql['sql']}",
            'active_quote_count' => "SELECT COUNT(*) AS value FROM invoices i WHERE i.document_type IN ('quote', 'proforma') AND i.status NOT IN ('cancelled', 'paid') {$scopeSql['sql']}",
            default => "SELECT 0 AS value",
        };
        $row = Database::queryOne($sql, $scopeSql['params']) ?: ['value' => 0];
        $evidence = [];
        if ($withEvidence) {
            $evidenceSql = match ($metric) {
                'paid_total', 'paid_count' => "SELECT i.id, i.invoice_number, i.status, i.grand_total, i.document_type, i.created_at
                                               FROM invoices i WHERE " . ($metric === 'paid_count' ? "i.status = 'paid'" : "i.status IN ('paid', 'partially_paid')") . " {$scopeSql['sql']}
                                               ORDER BY COALESCE(i.paid_at, i.updated_at, i.created_at) DESC LIMIT 5",
                'sent_total', 'active_quote_count' => "SELECT i.id, i.invoice_number, i.status, i.grand_total, i.document_type, i.created_at
                                               FROM invoices i WHERE " . ($metric === 'active_quote_count' ? "i.document_type IN ('quote', 'proforma') AND i.status NOT IN ('cancelled', 'paid')" : "i.status IN ('sent', 'viewed', 'accepted', 'finalized', 'partially_paid', 'paid', 'overdue')") . " {$scopeSql['sql']}
                                               ORDER BY COALESCE(i.last_sent_at, i.updated_at, i.created_at) DESC LIMIT 5",
                default => '',
            };
            if ($evidenceSql !== '') {
                foreach (Database::query($evidenceSql, $scopeSql['params']) as $rowItem) {
                    $evidence[] = [
                        'label' => (string) ($rowItem['invoice_number'] ?? ('Invoice #' . (int) ($rowItem['id'] ?? 0))),
                        'value' => (float) ($rowItem['grand_total'] ?? 0),
                        'status' => (string) ($rowItem['status'] ?? ''),
                        'url' => publicUrl('invoice_view.php?id=' . (int) ($rowItem['id'] ?? 0)),
                    ];
                }
            }
        }

        return [
            'value' => (float) ($row['value'] ?? 0),
            'label' => $this->formatRollupLabel($definition),
            'evidence' => $evidence,
        ];
    }

    private function computeTaskRollup(array $target, array $definition, bool $withEvidence): array
    {
        $scopeSql = $this->buildTaskScopeSql($target);
        $metric = $definition['metric'];
        $sql = match ($metric) {
            'completed_count' => "SELECT COUNT(*) AS value FROM tasks t WHERE t.status = 'completed' {$scopeSql['sql']}",
            'open_count' => "SELECT COUNT(*) AS value FROM tasks t WHERE t.status NOT IN ('completed', 'cancelled') {$scopeSql['sql']}",
            default => "SELECT 0 AS value",
        };
        $row = Database::queryOne($sql, $scopeSql['params']) ?: ['value' => 0];
        $evidence = [];
        if ($withEvidence) {
            $evidenceSql = "SELECT t.id, t.title, t.status, t.due_date, t.completed_at
                            FROM tasks t
                            WHERE " . ($metric === 'completed_count' ? "t.status = 'completed'" : "t.status NOT IN ('completed', 'cancelled')") . " {$scopeSql['sql']}
                            ORDER BY COALESCE(t.completed_at, t.due_date, t.updated_at, t.created_at) DESC LIMIT 5";
            foreach (Database::query($evidenceSql, $scopeSql['params']) as $rowItem) {
                $evidence[] = [
                    'label' => (string) ($rowItem['title'] ?? 'Task #' . (int) ($rowItem['id'] ?? 0)),
                    'status' => (string) ($rowItem['status'] ?? ''),
                    'url' => publicUrl('task_view.php?id=' . (int) ($rowItem['id'] ?? 0)),
                ];
            }
        }

        return [
            'value' => (float) ($row['value'] ?? 0),
            'label' => $this->formatRollupLabel($definition),
            'evidence' => $evidence,
        ];
    }

    private function computeContactRollup(array $target, array $definition, bool $withEvidence): array
    {
        $scopeSql = $this->buildContactScopeSql($target);
        $metric = $definition['metric'];
        $sql = match ($metric) {
            'created_count' => "SELECT COUNT(*) AS value FROM contacts c WHERE 1=1 {$scopeSql['sql']}",
            'qualified_count' => "SELECT COUNT(*) AS value FROM contacts c WHERE c.stage IN ('qualified', 'proposal', 'negotiation', 'won') {$scopeSql['sql']}",
            'won_count' => "SELECT COUNT(*) AS value FROM contacts c WHERE c.stage = 'won' {$scopeSql['sql']}",
            default => "SELECT 0 AS value",
        };
        $row = Database::queryOne($sql, $scopeSql['params']) ?: ['value' => 0];
        $evidence = [];
        if ($withEvidence) {
            $condition = match ($metric) {
                'qualified_count' => "c.stage IN ('qualified', 'proposal', 'negotiation', 'won')",
                'won_count' => "c.stage = 'won'",
                default => "1=1",
            };
            foreach (Database::query(
                "SELECT c.id, c.first_name, c.last_name, c.stage, c.company
                 FROM contacts c
                 WHERE {$condition} {$scopeSql['sql']}
                 ORDER BY COALESCE(c.updated_at, c.created_at) DESC LIMIT 5",
                $scopeSql['params']
            ) as $rowItem) {
                $label = trim((string) (($rowItem['first_name'] ?? '') . ' ' . ($rowItem['last_name'] ?? '')));
                $evidence[] = [
                    'label' => $label !== '' ? $label : ('Contact #' . (int) ($rowItem['id'] ?? 0)),
                    'status' => (string) ($rowItem['stage'] ?? ''),
                    'url' => publicUrl('contact_view.php?id=' . (int) ($rowItem['id'] ?? 0)),
                ];
            }
        }

        return [
            'value' => (float) ($row['value'] ?? 0),
            'label' => $this->formatRollupLabel($definition),
            'evidence' => $evidence,
        ];
    }

    private function computeCommunicationRollup(array $target, array $definition, bool $withEvidence): array
    {
        $scopeSql = $this->buildCommunicationScopeSql($target);
        $metric = $definition['metric'];
        $sql = match ($metric) {
            'outbound_count' => "SELECT COUNT(*) AS value FROM communications c {$scopeSql['joins']} WHERE c.direction = 'outbound' {$scopeSql['sql']}",
            'inbound_count' => "SELECT COUNT(*) AS value FROM communications c {$scopeSql['joins']} WHERE c.direction = 'inbound' {$scopeSql['sql']}",
            'reply_count' => "SELECT COUNT(*) AS value FROM communications c {$scopeSql['joins']} WHERE c.direction = 'inbound' {$scopeSql['sql']}",
            default => "SELECT 0 AS value",
        };
        $row = Database::queryOne($sql, $scopeSql['params']) ?: ['value' => 0];
        $evidence = [];
        if ($withEvidence) {
            $direction = $metric === 'outbound_count' ? 'outbound' : 'inbound';
            foreach (Database::query(
                "SELECT c.id, c.channel, c.direction, c.subject, c.body, c.contact_id
                 FROM communications c {$scopeSql['joins']}
                 WHERE c.direction = ? {$scopeSql['sql']}
                 ORDER BY c.created_at DESC LIMIT 5",
                array_merge([$direction], $scopeSql['params'])
            ) as $rowItem) {
                $evidence[] = [
                    'label' => trim((string) ($rowItem['subject'] ?? '')) !== '' ? (string) $rowItem['subject'] : substr(trim((string) ($rowItem['body'] ?? '')), 0, 80),
                    'status' => (string) ($rowItem['channel'] ?? ''),
                    'url' => !empty($rowItem['contact_id']) ? publicUrl('contact_view.php?id=' . (int) ($rowItem['contact_id'] ?? 0)) : '',
                ];
            }
        }

        return [
            'value' => (float) ($row['value'] ?? 0),
            'label' => $this->formatRollupLabel($definition),
            'evidence' => $evidence,
        ];
    }

    private function buildMilestoneSummary(array $target, array $metadata, float $currentValue): array
    {
        $stored = Database::query(
            "SELECT * FROM target_milestones WHERE workspace_id = ? AND target_id = ? ORDER BY sort_order ASC, id ASC",
            [(int) ($target['workspace_id'] ?? 0), (int) ($target['id'] ?? 0)]
        );

        $milestones = [];
        foreach ($stored as $row) {
            $targetValue = (float) ($row['target_value'] ?? 0);
            $milestones[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'target_value' => $targetValue,
                'current_value' => min($currentValue, $targetValue),
                'due_date' => (string) ($row['due_date'] ?? ''),
                'status' => $currentValue >= $targetValue && $targetValue > 0 ? 'completed' : 'pending',
            ];
        }

        if ($milestones === []) {
            $milestones = $this->buildSuggestedMilestones($target, $currentValue);
        }

        $completed = 0;
        foreach ($milestones as $milestone) {
            if (($milestone['status'] ?? 'pending') === 'completed') {
                $completed++;
            }
        }

        return [
            'milestones' => $milestones,
            'total' => count($milestones),
            'completed' => $completed,
            'has_custom_milestones' => !empty($stored),
        ];
    }

    private function buildSuggestedMilestones(array $target, float $currentValue): array
    {
        $targetValue = max(0.0, (float) ($target['target_value'] ?? 0));
        if ($targetValue <= 0) {
            return [];
        }

        $startTs = strtotime((string) ($target['start_date'] ?? $target['created_at'] ?? date('Y-m-d')));
        $endTs = strtotime((string) ($target['target_date'] ?? date('Y-m-d')));
        $steps = [
            ['label' => 'Checkpoint 25%', 'fraction' => 0.25],
            ['label' => 'Checkpoint 50%', 'fraction' => 0.50],
            ['label' => 'Checkpoint 75%', 'fraction' => 0.75],
        ];
        $milestones = [];
        foreach ($steps as $step) {
            $value = round($targetValue * $step['fraction'], 2);
            $dueDate = '';
            if ($startTs && $endTs && $endTs >= $startTs) {
                $dueDate = date('Y-m-d', (int) round($startTs + (($endTs - $startTs) * $step['fraction'])));
            }
            $milestones[] = [
                'id' => 0,
                'title' => $step['label'],
                'target_value' => $value,
                'current_value' => min($currentValue, $value),
                'due_date' => $dueDate,
                'status' => $currentValue >= $value ? 'completed' : 'pending',
            ];
        }
        return $milestones;
    }

    private function buildIntelligence(array $target, array $rollup, array $milestoneSummary): array
    {
        $targetValue = max(0.0, (float) ($target['target_value'] ?? 0));
        $currentValue = max(0.0, (float) ($target['current_value'] ?? 0));
        $progress = (float) ($target['progress_percentage'] ?? $this->calculateProgress($target));
        $startTs = strtotime((string) ($target['start_date'] ?? $target['created_at'] ?? date('Y-m-d')));
        $endTs = strtotime((string) ($target['target_date'] ?? date('Y-m-d')));
        $todayTs = strtotime(date('Y-m-d'));
        $totalDays = max(1, (int) ceil(($endTs - $startTs) / 86400));
        $elapsedDays = max(1, (int) floor(($todayTs - $startTs) / 86400) + 1);
        $elapsedDays = min($elapsedDays, $totalDays);
        $expectedProgress = min(100.0, ($elapsedDays / max(1, $totalDays)) * 100.0);
        $pacePerDay = $elapsedDays > 0 ? $currentValue / $elapsedDays : 0.0;
        $remainingValue = max(0.0, $targetValue - $currentValue);
        $projectedCompletionDate = '';
        if ($remainingValue <= 0) {
            $projectedCompletionDate = date('Y-m-d');
        } elseif ($pacePerDay > 0) {
            $projectedCompletionDate = date('Y-m-d', strtotime('+' . (int) ceil($remainingValue / $pacePerDay) . ' days'));
        }

        $statusBand = 'on_track';
        if (($target['status'] ?? '') === 'completed' || $progress >= 100) {
            $statusBand = 'completed';
        } elseif (($target['status'] ?? '') === 'missed' || ($endTs < $todayTs && $progress < 100)) {
            $statusBand = 'missed';
        } elseif ($pacePerDay <= 0 && $elapsedDays >= max(3, (int) ceil($totalDays / 4)) && $progress < 15) {
            $statusBand = 'blocked';
        } elseif ($progress >= ($expectedProgress - 5)) {
            $statusBand = 'on_track';
        } elseif ($progress >= ($expectedProgress - 20)) {
            $statusBand = 'at_risk';
        } else {
            $statusBand = 'behind';
        }

        $forecastScore = $targetValue > 0
            ? max(0.0, min(1.0, ($progress / max(1.0, $expectedProgress)) * 0.7 + (($pacePerDay > 0 ? min(1.0, (($currentValue / max(1.0, $elapsedDays)) / max(0.0001, $targetValue / max(1.0, $totalDays)))) : 0.0) * 0.3)))
            : 0.0;

        $blockers = [];
        if (($rollup['value'] ?? 0) <= 0 && in_array((string) ($target['progress_mode'] ?? ''), ['auto_rollup', 'hybrid'], true)) {
            $blockers[] = 'No source activity is currently contributing to this target.';
        }
        if (($milestoneSummary['total'] ?? 0) > 0 && ($milestoneSummary['completed'] ?? 0) === 0 && $elapsedDays > max(7, (int) ceil($totalDays / 3))) {
            $blockers[] = 'No milestones have been cleared yet for this target.';
        }
        if ($statusBand === 'behind' && $projectedCompletionDate !== '' && !empty($target['target_date']) && strtotime($projectedCompletionDate) > strtotime((string) $target['target_date'])) {
            $blockers[] = 'At the current pace, the projected completion date is later than the deadline.';
        }

        $nextActions = $this->buildNextActions($target, $statusBand, $remainingValue, $pacePerDay);
        $paceSummary = $pacePerDay > 0
            ? 'Current pace is ' . number_format($pacePerDay, 2) . ($target['unit'] ? ' ' . $target['unit'] : '') . ' per day.'
            : 'No measurable progress pace has been established yet.';

        return [
            'status_band' => $statusBand,
            'forecast_score' => round($forecastScore, 4),
            'projected_completion_date' => $projectedCompletionDate,
            'pace_summary' => $paceSummary,
            'expected_progress' => round($expectedProgress, 2),
            'pace_per_day' => round($pacePerDay, 4),
            'remaining_value' => round($remainingValue, 2),
            'blockers' => $blockers,
            'next_best_actions' => $nextActions,
            'milestone_recommendations' => $this->buildMilestoneRecommendations($target, $milestoneSummary, $statusBand),
        ];
    }

    private function buildNextActions(array $target, string $statusBand, float $remainingValue, float $pacePerDay): array
    {
        $actions = [];

        if ($statusBand === 'blocked') {
            $actions[] = 'Create the first measurable movement on this target today to unlock forecasting.';
        }

        if ($remainingValue > 0 && !empty($target['days_remaining']) && (int) $target['days_remaining'] > 0) {
            $neededPerDay = $remainingValue / max(1, (int) $target['days_remaining']);
            $actions[] = 'Aim for about ' . number_format($neededPerDay, 2) . ($target['unit'] ? ' ' . $target['unit'] : '') . ' per day to hit this target.';
        }

        switch (($target['rollup_definition']['source'] ?? '')) {
            case 'deals':
                $actions[] = 'Review open deals in proposal and negotiation and move the highest-probability ones forward.';
                break;
            case 'invoices':
                $actions[] = 'Follow up on sent and overdue commercial documents to unlock more target progress.';
                break;
            case 'tasks':
                $actions[] = 'Close the highest-priority open tasks first to improve this target pace.';
                break;
            case 'contacts':
                $actions[] = 'Prioritize qualifying new contacts and pushing active leads to the next stage.';
                break;
            case 'communications':
                $actions[] = 'Increase communication throughput on the channel that is currently under target.';
                break;
            default:
                if (($target['progress_mode'] ?? 'manual') === 'manual') {
                    $actions[] = 'Update this target manually whenever measurable progress happens.';
                }
                break;
        }

        if ($statusBand === 'behind' || $statusBand === 'at_risk') {
            $actions[] = 'Break the remaining work into smaller milestones and review progress again after the next checkpoint.';
        }

        return array_values(array_unique(array_filter($actions)));
    }

    private function buildMilestoneRecommendations(array $target, array $milestoneSummary, string $statusBand): array
    {
        if (!empty($milestoneSummary['has_custom_milestones'])) {
            return [];
        }

        $recommendations = [];
        if ($statusBand === 'behind' || $statusBand === 'at_risk') {
            $recommendations[] = 'Define milestone checkpoints with dates so slippage is visible earlier.';
        }
        if (($milestoneSummary['total'] ?? 0) === 0) {
            $recommendations[] = 'Add at least three measurable checkpoints between start and deadline.';
        }

        return $recommendations;
    }

    private function syncMilestones(int $targetId, int $workspaceId, float $currentValue, array $metadataMilestones = []): void
    {
        $rows = Database::query("SELECT id, target_value FROM target_milestones WHERE workspace_id = ? AND target_id = ?", [$workspaceId, $targetId]);
        if ($rows === []) {
            if ($metadataMilestones !== []) {
                foreach ($metadataMilestones as $index => $milestone) {
                    $title = trim((string) ($milestone['title'] ?? ''));
                    if ($title === '') {
                        continue;
                    }
                    $targetValue = (float) ($milestone['target_value'] ?? 0);
                    Database::execute(
                        "INSERT INTO target_milestones (workspace_id, target_id, title, target_value, current_value, due_date, status, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $workspaceId,
                            $targetId,
                            $title,
                            $targetValue,
                            min($currentValue, $targetValue),
                            !empty($milestone['due_date']) ? $milestone['due_date'] : null,
                            $currentValue >= $targetValue && $targetValue > 0 ? 'completed' : 'pending',
                            $index,
                        ]
                    );
                }
            }
            return;
        }

        foreach ($rows as $row) {
            $milestoneTargetValue = (float) ($row['target_value'] ?? 0);
            Database::execute(
                "UPDATE target_milestones SET current_value = ?, status = ? WHERE workspace_id = ? AND id = ?",
                [
                    min($currentValue, $milestoneTargetValue),
                    $currentValue >= $milestoneTargetValue && $milestoneTargetValue > 0 ? 'completed' : 'pending',
                    $workspaceId,
                    (int) ($row['id'] ?? 0),
                ]
            );
        }
    }

    private function buildDealScopeSql(array $target): array
    {
        if ($this->hasScopeColumn() && ($target['scope'] ?? 'personal') === 'personal') {
            return [
                'sql' => " AND (d.assigned_to = ? OR d.created_by = ?)",
                'params' => [(int) ($target['user_id'] ?? 0), (int) ($target['user_id'] ?? 0)],
            ];
        }

        return ['sql' => '', 'params' => []];
    }

    private function buildInvoiceScopeSql(array $target): array
    {
        if ($this->hasScopeColumn() && ($target['scope'] ?? 'personal') === 'personal') {
            return [
                'sql' => " AND (i.assigned_to = ? OR i.created_by = ?)",
                'params' => [(int) ($target['user_id'] ?? 0), (int) ($target['user_id'] ?? 0)],
            ];
        }

        return ['sql' => '', 'params' => []];
    }

    private function buildTaskScopeSql(array $target): array
    {
        if ($this->hasScopeColumn() && ($target['scope'] ?? 'personal') === 'personal') {
            return [
                'sql' => " AND (t.assigned_to = ? OR t.created_by = ?)",
                'params' => [(int) ($target['user_id'] ?? 0), (int) ($target['user_id'] ?? 0)],
            ];
        }

        return ['sql' => '', 'params' => []];
    }

    private function buildContactScopeSql(array $target): array
    {
        $parts = [];
        $params = [];
        if ($this->hasScopeColumn() && ($target['scope'] ?? 'personal') === 'personal') {
            $parts[] = "(c.assigned_to = ?";
            $params[] = (int) ($target['user_id'] ?? 0);
            if ($this->columnExists('contacts', 'created_by')) {
                $parts[] = " OR c.created_by = ?";
                $params[] = (int) ($target['user_id'] ?? 0);
            }
            $parts[] = ")";
        }

        return [
            'sql' => $parts ? ' AND ' . implode('', $parts) : '',
            'params' => $params,
        ];
    }

    private function buildCommunicationScopeSql(array $target): array
    {
        if ($this->hasScopeColumn() && ($target['scope'] ?? 'personal') === 'personal') {
            return [
                'joins' => ' INNER JOIN contacts ct ON ct.id = c.contact_id ',
                'sql' => ' AND (ct.assigned_to = ?' . ($this->columnExists('contacts', 'created_by') ? ' OR ct.created_by = ?' : '') . ')',
                'params' => $this->columnExists('contacts', 'created_by')
                    ? [(int) ($target['user_id'] ?? 0), (int) ($target['user_id'] ?? 0)]
                    : [(int) ($target['user_id'] ?? 0)],
            ];
        }

        return ['joins' => '', 'sql' => '', 'params' => []];
    }

    private function calculateProgress(array $target): float
    {
        $targetValue = (float) ($target['target_value'] ?? 0);
        $currentValue = (float) ($target['current_value'] ?? 0);
        if ($targetValue <= 0) {
            return 0.0;
        }
        return min(100.0, max(0.0, round(($currentValue / $targetValue) * 100, 2)));
    }

    private function getDaysRemaining(array $target): ?int
    {
        if (empty($target['target_date'])) {
            return null;
        }
        $targetDate = strtotime((string) $target['target_date']);
        $today = strtotime(date('Y-m-d'));
        return (int) ceil(($targetDate - $today) / 86400);
    }

    private function legacyStatusCategory(array $target, array $intelligence): string
    {
        if (($target['status'] ?? '') === 'completed') {
            return 'completed';
        }
        if (($target['status'] ?? '') === 'missed') {
            return 'missed';
        }
        return (string) ($intelligence['status_band'] ?? 'on_track');
    }

    private function formatRollupSourceLabel(array $definition): string
    {
        if ($definition === []) {
            return 'Manual progress';
        }

        return ucfirst((string) $definition['source']) . ' · ' . str_replace('_', ' ', (string) $definition['metric']);
    }

    private function formatRollupLabel(array $definition): string
    {
        return $this->formatRollupSourceLabel($definition);
    }

    private function persistEvidence(array $target, array $evidence): void
    {
        if (!Database::tableExists('target_evidence')) {
            return;
        }
        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        $targetId = (int) ($target['id'] ?? 0);
        foreach ($evidence as $item) {
            $fingerprint = trim((string) ($item['fingerprint'] ?? ''));
            if ($workspaceId <= 0 || $targetId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
                continue;
            }
            Database::execute(
                "INSERT INTO target_evidence
                    (workspace_id, target_id, collector_key, source_entity_type, source_entity_id, observed_at,
                     contribution_value, currency_code, evidence_payload_json, evidence_fingerprint)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at), contribution_value=VALUES(contribution_value),
                    currency_code=VALUES(currency_code), evidence_payload_json=VALUES(evidence_payload_json),
                    decision_state=IF(decision_state IN ('accepted','rejected'),decision_state,'observed')",
                [$workspaceId, $targetId, (string) ($item['collector'] ?? 'target_rollup'),
                 (string) ($item['entity_type'] ?? 'unknown'), (int) ($item['entity_id'] ?? 0) ?: null,
                 (string) ($item['observed_at'] ?? date('Y-m-d H:i:s')), (float) ($item['value'] ?? 0),
                 $item['currency'] ?? null, json_encode($item, JSON_UNESCAPED_SLASHES), $fingerprint]
            );
        }
    }

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

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }

        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            self::$columnCache[$cacheKey] = !empty($row);
        } catch (\Throwable $e) {
            self::$columnCache[$cacheKey] = false;
        }

        return self::$columnCache[$cacheKey];
    }

    private function hasScopeColumn(): bool
    {
        return $this->columnExists('targets', 'scope');
    }

    private function hasProgressModeColumn(): bool
    {
        return $this->columnExists('targets', 'progress_mode');
    }

    private function hasManualAdjustmentColumn(): bool
    {
        return $this->columnExists('targets', 'manual_adjustment_value');
    }
}
