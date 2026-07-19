<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class WorkflowMigrationService
{
    private WorkflowGraphService $graphService;

    public function __construct()
    {
        $this->graphService = new WorkflowGraphService();
    }

    public function migrateAllPending(): array
    {
        $rows = Database::query(
            "SELECT * FROM workflows WHERE migration_status IS NULL OR migration_status != 'migrated'",
            []
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->migrateWorkflow((int) $row['id']);
        }

        return $results;
    }

    public function migrateWorkflow(int $workflowId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $workflow = $workspaceId > 0
            ? Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId])
            : Database::queryOne("SELECT * FROM workflows WHERE id = ?", [$workflowId]);
        if (!$workflow) {
            throw new \RuntimeException('Workflow not found');
        }

        $graph = $this->graphService->migrateLegacyWorkflow($workflow);
        $validation = $this->graphService->validateGraph($graph);
        $status = $validation['valid'] ? 'migrated' : 'failed';
        $sourceFormat = !empty($workflow['graph_json']) ? 'graph_v2' : (!empty($workflow['visual_data']) ? 'visual_v1' : 'legacy_form');

        Database::execute(
            "UPDATE workflows SET graph_json = ?, builder_version = 2, workflow_mode = COALESCE(workflow_mode, 'mixed'),
             migration_source = ?, migration_status = ?, last_migrated_at = NOW(), last_validated_at = NOW()
             WHERE " . ($workspaceId > 0 ? "workspace_id = ? AND " : "") . "id = ?",
            $workspaceId > 0
                ? [json_encode($graph), $sourceFormat, $status, $workspaceId, $workflowId]
                : [json_encode($graph), $sourceFormat, $status, $workflowId]
        );

        Database::execute(
            "INSERT INTO workflow_migration_log (workflow_id, source_format, target_format, status, notes, before_snapshot_json, after_snapshot_json)
             VALUES (?, ?, 'graph_v2', ?, ?, ?, ?)",
            [
                $workflowId,
                $sourceFormat,
                $status,
                json_encode($validation['issues']),
                json_encode([
                    'trigger_config' => $workflow['trigger_config'] ?? null,
                    'conditions' => $workflow['conditions'] ?? null,
                    'actions' => $workflow['actions'] ?? null,
                    'visual_data' => $workflow['visual_data'] ?? null,
                ]),
                json_encode($graph),
            ]
        );

        return [
            'workflow_id' => $workflowId,
            'status' => $status,
            'graph' => $graph,
            'validation' => $validation,
        ];
    }

    public function compareLegacyToGraph(array $workflow, array $graph): array
    {
        $legacy = $this->graphService->deriveLegacyPayload($graph);
        $currentTrigger = is_string($workflow['trigger_config'] ?? null)
            ? (json_decode($workflow['trigger_config'], true) ?? [])
            : ($workflow['trigger_config'] ?? []);
        $currentActions = is_string($workflow['actions'] ?? null)
            ? (json_decode($workflow['actions'], true) ?? [])
            : ($workflow['actions'] ?? []);

        return [
            'trigger_matches' => ($currentTrigger['type'] ?? null) === ($legacy['trigger']['type'] ?? null),
            'action_count_matches' => count($currentActions) === count($legacy['actions']),
            'legacy_payload' => $legacy,
        ];
    }
}
