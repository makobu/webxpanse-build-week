<?php

namespace CRM\Services;

use CRM\Database;

class AICrossDomainPlanStoreService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function createRun(array $data): int
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $candidateWorkspaceId = isset($data['workspace_id']) ? (int) $data['workspace_id'] : 0;
        if ($candidateWorkspaceId > 0 && $candidateWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration run does not belong to the active workspace.');
        }
        $candidateTenantWorkspaceId = $this->workspaceScope->resolveWorkspaceIdFromTenantKey((string) ($data['tenant_key'] ?? ''));
        if ($candidateTenantWorkspaceId !== null && $candidateTenantWorkspaceId > 0 && $candidateTenantWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration run does not belong to the active workspace.');
        }
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        Database::execute(
            "INSERT INTO ai_cross_domain_runs
                (workspace_id, tenant_key, objective_key, primary_entity_type, primary_entity_id, related_entities_json, execution_mode,
                 run_status, reason_note, plan_json, summary_json, created_by, started_at, completed_at,
                 origin_type, trigger_source_domain, trigger_key, trigger_entity_type, trigger_entity_id,
                 trigger_metadata_json, wait_state_json, suppression_reason, last_resume_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $tenantKey,
                (string) ($data['objective_key'] ?? 'unknown_objective'),
                (string) ($data['primary_entity_type'] ?? 'unknown'),
                (int) ($data['primary_entity_id'] ?? 0),
                json_encode((array) ($data['related_entities'] ?? [])),
                (string) ($data['execution_mode'] ?? 'plan_only'),
                (string) ($data['run_status'] ?? 'planned'),
                trim((string) ($data['reason_note'] ?? '')),
                json_encode((array) ($data['plan'] ?? [])),
                json_encode((array) ($data['summary'] ?? [])),
                !empty($data['created_by']) ? (int) $data['created_by'] : null,
                $data['started_at'] ?? null,
                $data['completed_at'] ?? null,
                (string) ($data['origin_type'] ?? 'operator'),
                !empty($data['trigger_source_domain']) ? (string) $data['trigger_source_domain'] : null,
                !empty($data['trigger_key']) ? (string) $data['trigger_key'] : null,
                !empty($data['trigger_entity_type']) ? (string) $data['trigger_entity_type'] : null,
                !empty($data['trigger_entity_id']) ? (int) $data['trigger_entity_id'] : null,
                json_encode((array) ($data['trigger_metadata'] ?? [])),
                json_encode((array) ($data['wait_state'] ?? [])),
                !empty($data['suppression_reason']) ? (string) $data['suppression_reason'] : null,
                $data['last_resume_attempt_at'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function addStep(int $runId, array $step): int
    {
        $run = $this->getRun($runId);
        if (!$run) {
            throw new \InvalidArgumentException('Orchestration run not found.');
        }

        Database::execute(
            "INSERT INTO ai_cross_domain_steps
                (workspace_id, run_id, step_order, domain_key, action_key, target_entity_type, target_entity_id, depends_on_step_id,
                 customer_facing, assistant_confidence, precheck_status, step_status, linked_incident_id,
                 linked_recovery_queue_id, linked_domain_run_id, plan_context_json, result_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) ($run['workspace_id'] ?? 0),
                $runId,
                (int) ($step['step_order'] ?? 0),
                (string) ($step['domain_key'] ?? 'unknown'),
                (string) ($step['action_key'] ?? 'unknown_action'),
                (string) ($step['target_entity_type'] ?? 'unknown'),
                (int) ($step['target_entity_id'] ?? 0),
                !empty($step['depends_on_step_id']) ? (int) $step['depends_on_step_id'] : null,
                !empty($step['customer_facing']) ? 1 : 0,
                isset($step['assistant_confidence']) ? (float) $step['assistant_confidence'] : null,
                (string) ($step['precheck_status'] ?? 'pending'),
                (string) ($step['step_status'] ?? 'planned'),
                !empty($step['linked_incident_id']) ? (int) $step['linked_incident_id'] : null,
                !empty($step['linked_recovery_queue_id']) ? (int) $step['linked_recovery_queue_id'] : null,
                !empty($step['linked_domain_run_id']) ? (int) $step['linked_domain_run_id'] : null,
                json_encode((array) ($step['plan_context'] ?? [])),
                json_encode((array) ($step['result'] ?? [])),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function getRun(int $runId): ?array
    {
        $row = Database::queryOne(
            "SELECT * FROM ai_cross_domain_runs WHERE id = ? AND workspace_id = ? LIMIT 1",
            [$runId, $this->workspaceScope->requireWorkspaceId()]
        );
        if (!$row) {
            return null;
        }
        return $this->hydrateRun($row);
    }

    public function getStep(int $stepId): ?array
    {
        $row = Database::queryOne(
            "SELECT * FROM ai_cross_domain_steps WHERE id = ? AND workspace_id = ? LIMIT 1",
            [$stepId, $this->workspaceScope->requireWorkspaceId()]
        );
        if (!$row) {
            return null;
        }
        return $this->hydrateStep($row);
    }

    public function listRuns(?string $tenantKey = null, int $limit = 20): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $rows = Database::query(
            "SELECT * FROM ai_cross_domain_runs WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)),
            [$workspaceId]
        );
        return array_map(fn(array $row): array => $this->hydrateRun($row), $rows);
    }

    public function listAllRuns(int $limit = 20): array
    {
        $rows = Database::query(
            "SELECT * FROM ai_cross_domain_runs WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)),
            [$this->workspaceScope->requireWorkspaceId()]
        );
        return array_map(fn(array $row): array => $this->hydrateRun($row), $rows);
    }

    public function listSteps(int $runId): array
    {
        $run = $this->getRun($runId);
        if (!$run) {
            return [];
        }

        $rows = Database::query(
            "SELECT * FROM ai_cross_domain_steps WHERE run_id = ? AND workspace_id = ? ORDER BY step_order ASC, id ASC",
            [$runId, (int) ($run['workspace_id'] ?? 0)]
        );
        return array_map(fn(array $row): array => $this->hydrateStep($row), $rows);
    }

    public function updateRun(int $runId, array $patch): void
    {
        $fields = [];
        $params = [];
        foreach ([
            'execution_mode' => 'execution_mode',
            'run_status' => 'run_status',
            'reason_note' => 'reason_note',
            'created_by' => 'created_by',
            'origin_type' => 'origin_type',
            'trigger_source_domain' => 'trigger_source_domain',
            'trigger_key' => 'trigger_key',
            'trigger_entity_type' => 'trigger_entity_type',
            'trigger_entity_id' => 'trigger_entity_id',
            'suppression_reason' => 'suppression_reason',
            'last_resume_attempt_at' => 'last_resume_attempt_at',
        ] as $key => $column) {
            if (array_key_exists($key, $patch)) {
                $fields[] = $column . ' = ?';
                $params[] = $patch[$key];
            }
        }
        if (array_key_exists('plan', $patch)) {
            $fields[] = 'plan_json = ?';
            $params[] = json_encode((array) $patch['plan']);
        }
        if (array_key_exists('summary', $patch)) {
            $fields[] = 'summary_json = ?';
            $params[] = json_encode((array) $patch['summary']);
        }
        if (array_key_exists('trigger_metadata', $patch)) {
            $fields[] = 'trigger_metadata_json = ?';
            $params[] = json_encode((array) $patch['trigger_metadata']);
        }
        if (array_key_exists('wait_state', $patch)) {
            $fields[] = 'wait_state_json = ?';
            $params[] = json_encode((array) $patch['wait_state']);
        }
        if (array_key_exists('started_at', $patch)) {
            $fields[] = 'started_at = ?';
            $params[] = $patch['started_at'];
        }
        if (array_key_exists('completed_at', $patch)) {
            $fields[] = 'completed_at = ?';
            $params[] = $patch['completed_at'];
        }
        if ($fields === []) {
            return;
        }
        $params[] = $runId;
        $params[] = $this->workspaceScope->requireWorkspaceId();
        Database::execute(
            "UPDATE ai_cross_domain_runs SET " . implode(', ', $fields) . " WHERE id = ? AND workspace_id = ?",
            $params
        );
    }

    public function updateStep(int $stepId, array $patch): void
    {
        $fields = [];
        $params = [];
        foreach ([
            'depends_on_step_id' => 'depends_on_step_id',
            'assistant_confidence' => 'assistant_confidence',
            'precheck_status' => 'precheck_status',
            'step_status' => 'step_status',
            'linked_incident_id' => 'linked_incident_id',
            'linked_recovery_queue_id' => 'linked_recovery_queue_id',
            'linked_domain_run_id' => 'linked_domain_run_id',
        ] as $key => $column) {
            if (array_key_exists($key, $patch)) {
                $fields[] = $column . ' = ?';
                $params[] = $patch[$key];
            }
        }
        if (array_key_exists('plan_context', $patch)) {
            $fields[] = 'plan_context_json = ?';
            $params[] = json_encode((array) $patch['plan_context']);
        }
        if (array_key_exists('result', $patch)) {
            $fields[] = 'result_json = ?';
            $params[] = json_encode((array) $patch['result']);
        }
        if ($fields === []) {
            return;
        }
        $params[] = $stepId;
        $params[] = $this->workspaceScope->requireWorkspaceId();
        Database::execute(
            "UPDATE ai_cross_domain_steps SET " . implode(', ', $fields) . " WHERE id = ? AND workspace_id = ?",
            $params
        );
    }

    private function hydrateRun(array $row): array
    {
        $row['workspace_id'] = (int) ($row['workspace_id'] ?? 0);
        $row['tenant_key'] = $this->workspaceScope->workspaceTenantKey(
            $row['workspace_id'] > 0 ? $row['workspace_id'] : $this->workspaceScope->requireWorkspaceId()
        );
        $row['related_entities'] = $this->decodeJson($row['related_entities_json'] ?? null);
        $row['plan'] = $this->decodeJson($row['plan_json'] ?? null);
        $row['summary'] = $this->decodeJson($row['summary_json'] ?? null);
        $row['trigger_metadata'] = $this->decodeJson($row['trigger_metadata_json'] ?? null);
        $row['wait_state'] = $this->decodeJson($row['wait_state_json'] ?? null);
        return $row;
    }

    private function hydrateStep(array $row): array
    {
        $row['workspace_id'] = (int) ($row['workspace_id'] ?? 0);
        $row['plan_context'] = $this->decodeJson($row['plan_context_json'] ?? null);
        $row['result'] = $this->decodeJson($row['result_json'] ?? null);
        return $row;
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
}
