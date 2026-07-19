<?php

namespace CRM\Services;

use CRM\AuditLogger;
use CRM\Database;
use CRM\Modules\AutomationEngine;

class WorkflowAutomationProposalService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?WorkflowGraphService $graphService = null,
        private ?AutomationEngine $automationEngine = null
    ) {
        $this->graphService = $this->graphService ?? new WorkflowGraphService();
        $this->automationEngine = $this->automationEngine ?? new AutomationEngine();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function createProposal(array $payload): int
    {
        $columns = [
            'workflow_id',
            'proposal_type',
            'source_surface',
            'status',
            'requested_by_type',
            'requested_by_id',
            'target_workflow_name',
            'prompt_text',
            'decision_mode',
            'governance_decision',
            'confidence_score',
            'proposal_graph_json',
            'current_graph_json',
            'legacy_payload_json',
            'validation_issues_json',
            'risk_summary_json',
            'action_summary_json',
            'diff_summary_json',
            'decision_snapshot_json',
            'notes',
        ];
        $values = [
            !empty($payload['workflow_id']) ? (int) $payload['workflow_id'] : null,
            $this->normalizeProposalType((string) ($payload['proposal_type'] ?? 'create')),
            (string) ($payload['source_surface'] ?? 'workflow_generation'),
            $this->normalizeStatus((string) ($payload['status'] ?? 'pending')),
            $this->normalizeRequesterType((string) ($payload['requested_by_type'] ?? 'ai')),
            !empty($payload['requested_by_id']) ? (int) $payload['requested_by_id'] : null,
            trim((string) ($payload['target_workflow_name'] ?? 'Workflow proposal')),
            trim((string) ($payload['prompt_text'] ?? '')) ?: null,
            $this->normalizeMode((string) ($payload['decision_mode'] ?? 'suggest_only')),
            trim((string) ($payload['governance_decision'] ?? '')) ?: null,
            isset($payload['confidence_score']) ? round((float) $payload['confidence_score'], 4) : null,
            $this->encodeJson($payload['proposal_graph'] ?? null),
            $this->encodeJson($payload['current_graph'] ?? null),
            $this->encodeJson($payload['legacy_payload'] ?? null),
            $this->encodeJson($payload['validation_issues'] ?? []),
            $this->encodeJson($payload['risk_summary'] ?? []),
            $this->encodeJson($payload['action_summary'] ?? []),
            $this->encodeJson($payload['diff_summary'] ?? []),
            $this->encodeJson($payload['decision_snapshot'] ?? []),
            trim((string) ($payload['notes'] ?? '')) ?: null,
        ];
        if ($this->columnExists('workflow_automation_proposals', 'workspace_id')) {
            array_unshift($columns, 'workspace_id');
            array_unshift($values, $this->workspaceScope->requireWorkspaceId(
                null,
                !empty($payload['workspace_id']) ? (int) $payload['workspace_id'] : null
            ));
        }

        Database::execute(
            sprintf(
                "INSERT INTO workflow_automation_proposals (%s) VALUES (%s)",
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            ),
            $values
        );

        $proposalId = (int) Database::lastInsertId();
        AuditLogger::log('workflow_automation_proposal.created', 'workflow_automation_proposal', $proposalId, null, [
            'workflow_id' => !empty($payload['workflow_id']) ? (int) $payload['workflow_id'] : null,
            'proposal_type' => $payload['proposal_type'] ?? 'create',
            'decision_mode' => $payload['decision_mode'] ?? 'suggest_only',
            'confidence_score' => $payload['confidence_score'] ?? null,
        ]);

        return $proposalId;
    }

    public function listAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        $proposalType = trim((string) ($filters['proposal_type'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        if ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($proposalType !== '') {
            $where[] = 'p.proposal_type = ?';
            $params[] = $proposalType;
        }
        if (!empty($filters['workflow_id'])) {
            $where[] = 'p.workflow_id = ?';
            $params[] = (int) $filters['workflow_id'];
        }
        if ($search !== '') {
            $where[] = '(p.target_workflow_name LIKE ? OR CAST(p.id AS CHAR) = ?)';
            $params[] = '%' . $search . '%';
            $params[] = $search;
        }
        if ($this->columnExists('workflow_automation_proposals', 'workspace_id')) {
            $where[] = 'p.workspace_id = ?';
            $params[] = $this->workspaceScope->requireWorkspaceId(
                null,
                !empty($filters['workspace_id']) ? (int) $filters['workspace_id'] : null
            );
        }

        $sql = "SELECT p.*,
                       w.name AS workflow_name,
                       aw.name AS applied_workflow_name,
                       rb.email AS requested_by_email,
                       ab.email AS approved_by_email,
                       rej.email AS rejected_by_email
                FROM workflow_automation_proposals p
                LEFT JOIN workflows w ON w.id = p.workflow_id AND w.workspace_id = p.workspace_id
                LEFT JOIN workflows aw ON aw.id = p.applied_workflow_id AND aw.workspace_id = p.workspace_id
                LEFT JOIN users rb ON rb.id = p.requested_by_id
                LEFT JOIN users ab ON ab.id = p.approved_by
                LEFT JOIN users rej ON rej.id = p.rejected_by";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY
                    CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END,
                    p.created_at DESC,
                    p.id DESC
                  LIMIT {$limit}";

        return array_map(fn(array $row): array => $this->hydrateProposal($row), Database::query($sql, $params));
    }

    public function getById(int $proposalId): ?array
    {
        if ($proposalId <= 0) {
            return null;
        }

        $where = ['p.id = ?'];
        $params = [$proposalId];
        if ($this->columnExists('workflow_automation_proposals', 'workspace_id')) {
            $where[] = 'p.workspace_id = ?';
            $params[] = $this->workspaceScope->requireWorkspaceId();
        }

        $row = Database::queryOne(
            "SELECT p.*,
                    w.name AS workflow_name,
                    aw.name AS applied_workflow_name,
                    rb.email AS requested_by_email,
                    ab.email AS approved_by_email,
                    rej.email AS rejected_by_email
             FROM workflow_automation_proposals p
             LEFT JOIN workflows w ON w.id = p.workflow_id AND w.workspace_id = p.workspace_id
             LEFT JOIN workflows aw ON aw.id = p.applied_workflow_id AND aw.workspace_id = p.workspace_id
             LEFT JOIN users rb ON rb.id = p.requested_by_id
             LEFT JOIN users ab ON ab.id = p.approved_by
             LEFT JOIN users rej ON rej.id = p.rejected_by
             WHERE " . implode(' AND ', $where),
            $params
        );

        return $row ? $this->hydrateProposal($row) : null;
    }

    public function approve(int $proposalId, int $userId, string $notes = ''): ?array
    {
        $proposal = $this->getById($proposalId);
        if (!$proposal || ($proposal['status'] ?? '') !== 'pending') {
            return null;
        }

        Database::execute(
            "UPDATE workflow_automation_proposals
             SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
             WHERE id = ?"
                . ($this->columnExists('workflow_automation_proposals', 'workspace_id') ? " AND workspace_id = ?" : ""),
            array_merge(
                [$userId, trim($notes) !== '' ? trim($notes) : ($proposal['notes'] ?? null), $proposalId],
                $this->columnExists('workflow_automation_proposals', 'workspace_id') ? [(int) $proposal['workspace_id']] : []
            )
        );

        AuditLogger::log('workflow_automation_proposal.approved', 'workflow_automation_proposal', $proposalId, [
            'status' => 'pending',
        ], [
            'status' => 'approved',
            'approved_by' => $userId,
        ]);

        return $this->getById($proposalId);
    }

    public function reject(int $proposalId, int $userId, string $notes = ''): ?array
    {
        $proposal = $this->getById($proposalId);
        if (!$proposal || ($proposal['status'] ?? '') !== 'pending') {
            return null;
        }

        Database::execute(
            "UPDATE workflow_automation_proposals
             SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), notes = ?
             WHERE id = ?"
                . ($this->columnExists('workflow_automation_proposals', 'workspace_id') ? " AND workspace_id = ?" : ""),
            array_merge(
                [$userId, trim($notes) !== '' ? trim($notes) : ($proposal['notes'] ?? null), $proposalId],
                $this->columnExists('workflow_automation_proposals', 'workspace_id') ? [(int) $proposal['workspace_id']] : []
            )
        );

        AuditLogger::log('workflow_automation_proposal.rejected', 'workflow_automation_proposal', $proposalId, [
            'status' => 'pending',
        ], [
            'status' => 'rejected',
            'rejected_by' => $userId,
            'notes' => trim($notes) !== '' ? trim($notes) : null,
        ]);

        return $this->getById($proposalId);
    }

    public function applyProposal(int $proposalId, int $userId, bool $autoApproved = false): ?array
    {
        $proposal = $this->getById($proposalId);
        if (!$proposal) {
            return null;
        }

        $status = (string) ($proposal['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved'], true)) {
            return null;
        }

        if ($status === 'pending') {
            $proposal = $this->approve($proposalId, $userId, (string) ($proposal['notes'] ?? '')) ?: $proposal;
        }

        $legacyPayload = (array) ($proposal['legacy_payload'] ?? []);
        $graph = (array) ($proposal['proposal_graph'] ?? []);
        $workflowMode = (string) ($graph['meta']['mode'] ?? 'mixed');
        $isActive = !empty($legacyPayload['is_active']) || !array_key_exists('is_active', $legacyPayload);

        Database::beginTransaction();
        try {
            $workflowId = !empty($proposal['workflow_id']) ? (int) $proposal['workflow_id'] : 0;
            if (($proposal['proposal_type'] ?? 'create') === 'update' && $workflowId > 0) {
                $this->automationEngine->updateWorkflow(
                    $workflowId,
                    (string) ($proposal['target_workflow_name'] ?? 'Workflow'),
                    (array) ($legacyPayload['trigger'] ?? []),
                    (array) ($legacyPayload['conditions'] ?? []),
                    (array) ($legacyPayload['actions'] ?? []),
                    $isActive
                );
            } else {
                $workflowId = $this->automationEngine->createWorkflow(
                    (string) ($proposal['target_workflow_name'] ?? 'Workflow'),
                    (array) ($legacyPayload['trigger'] ?? []),
                    (array) ($legacyPayload['conditions'] ?? []),
                    (array) ($legacyPayload['actions'] ?? [])
                );
            }

            $this->persistWorkflowArtifacts(
                $workflowId,
                $graph,
                (array) ($legacyPayload['visual_data'] ?? []),
                (array) ($legacyPayload['branches'] ?? []),
                $workflowMode,
                $isActive
            );

            Database::execute(
                "UPDATE workflow_automation_proposals
                 SET status = 'applied',
                     applied_workflow_id = ?,
                     approved_by = COALESCE(approved_by, ?),
                     approved_at = COALESCE(approved_at, NOW()),
                     notes = ?
                 WHERE workspace_id = ? AND id = ?",
                [$workflowId, $userId, $proposal['notes'] ?? null, (int) ($proposal['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId()), $proposalId]
            );

            Database::commit();

            AuditLogger::log('workflow_automation_proposal.applied', 'workflow_automation_proposal', $proposalId, [
                'status' => $status,
            ], [
                'status' => 'applied',
                'applied_workflow_id' => $workflowId,
                'auto_approved' => $autoApproved,
            ]);

            return [
                'proposal' => $this->getById($proposalId),
                'workflow_id' => $workflowId,
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function buildSummaryMetrics(array $proposals): array
    {
        $metrics = [
            'pending_count' => 0,
            'applied_count' => 0,
            'customer_facing_count' => 0,
            'create_count' => 0,
            'update_count' => 0,
        ];

        foreach ($proposals as $proposal) {
            if (($proposal['status'] ?? '') === 'pending') {
                $metrics['pending_count']++;
            }
            if (($proposal['status'] ?? '') === 'applied') {
                $metrics['applied_count']++;
            }
            if (($proposal['proposal_type'] ?? '') === 'create') {
                $metrics['create_count']++;
            }
            if (($proposal['proposal_type'] ?? '') === 'update') {
                $metrics['update_count']++;
            }
            if (!empty(($proposal['risk_summary']['customer_facing'] ?? false))) {
                $metrics['customer_facing_count']++;
            }
        }

        return $metrics;
    }

    private function persistWorkflowArtifacts(
        int $workflowId,
        array $graph,
        array $visualData,
        array $branches,
        string $workflowMode,
        bool $isActive
    ): void {
        Database::execute(
            "UPDATE workflows SET visual_data = ?, graph_json = ?, builder_version = 2, workflow_mode = ?,
             migration_source = 'graph_v2', migration_status = 'migrated',
             last_saved_at = NOW(), last_migrated_at = NOW(), last_validated_at = NOW(), is_active = ?
             WHERE workspace_id = ? AND id = ?",
            [
                json_encode($visualData),
                json_encode($graph),
                in_array($workflowMode, ['crm', 'journey', 'mixed'], true) ? $workflowMode : 'mixed',
                $isActive ? 1 : 0,
                $this->workspaceScope->requireWorkspaceId(),
                $workflowId,
            ]
        );

        Database::execute("DELETE FROM workflow_branches WHERE workflow_id = ?", [$workflowId]);
        foreach ($branches as $index => $branch) {
            Database::execute(
                "INSERT INTO workflow_branches (workflow_id, source_node_id, target_node_id, branch_type, condition_value, branch_order)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $workflowId,
                    (string) ($branch['from'] ?? ''),
                    (string) ($branch['to'] ?? ''),
                    (string) ($branch['type'] ?? 'default'),
                    $branch['condition_value'] ?? null,
                    (int) ($branch['order'] ?? $index),
                ]
            );
        }

        $triggerService = new WorkflowTriggerService();
        if ($isActive) {
            $triggerService->subscribeWorkflow($workflowId);
        } else {
            $triggerService->unsubscribeWorkflow($workflowId);
        }
        WorkflowExecutionService::invalidateWorkflowCache($workflowId);
    }

    private function hydrateProposal(array $row): array
    {
        $row['proposal_graph'] = $this->decodeJson($row['proposal_graph_json'] ?? null);
        $row['current_graph'] = $this->decodeJson($row['current_graph_json'] ?? null);
        $row['legacy_payload'] = $this->decodeJson($row['legacy_payload_json'] ?? null);
        $row['validation_issues'] = $this->decodeJson($row['validation_issues_json'] ?? null);
        $row['risk_summary'] = $this->decodeJson($row['risk_summary_json'] ?? null);
        $row['action_summary'] = $this->decodeJson($row['action_summary_json'] ?? null);
        $row['diff_summary'] = $this->decodeJson($row['diff_summary_json'] ?? null);
        $row['decision_snapshot'] = $this->decodeJson($row['decision_snapshot_json'] ?? null);
        return $row;
    }

    private function normalizeProposalType(string $value): string
    {
        return in_array($value, ['create', 'update'], true) ? $value : 'create';
    }

    private function normalizeStatus(string $value): string
    {
        return in_array($value, ['pending', 'approved', 'rejected', 'applied', 'expired'], true) ? $value : 'pending';
    }

    private function normalizeRequesterType(string $value): string
    {
        return in_array($value, ['ai', 'system', 'user'], true) ? $value : 'ai';
    }

    private function normalizeMode(string $value): string
    {
        return in_array($value, ['suggest_only', 'auto_safe', 'full_auto'], true) ? $value : 'suggest_only';
    }

    private function encodeJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?",
                [$table, $column]
            );

            return ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
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
}
