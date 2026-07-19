<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyIncidentService
{
    private const INCIDENT_STATUSES = ['open', 'queued', 'in_progress', 'resolved', 'suppressed'];
    private const RECOVERY_STATUSES = ['pending', 'assigned', 'in_progress', 'resolved', 'suppressed', 'failed_retry'];
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function recordIncident(array $data): ?int
    {
        if (!$this->tableExists('ai_autonomy_incidents')) {
            return null;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''));
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        Database::execute(
            "INSERT INTO ai_autonomy_incidents
                (workspace_id, tenant_key, domain_key, action_key, incident_key, severity, status, reason_codes_json, details_json,
                 linked_run_id, linked_eval_run_id, linked_entity_type, linked_entity_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $tenantKey,
                (string) ($data['domain_key'] ?? 'commercial_mvp'),
                !empty($data['action_key']) ? (string) $data['action_key'] : null,
                (string) ($data['incident_key'] ?? 'autonomy_incident'),
                $this->normalizeSeverity((string) ($data['severity'] ?? 'medium')),
                $this->normalizeIncidentStatus((string) ($data['status'] ?? 'open')),
                json_encode(array_values(array_unique((array) ($data['reason_codes'] ?? [])))),
                json_encode((array) ($data['details'] ?? [])),
                !empty($data['linked_run_id']) ? (int) $data['linked_run_id'] : null,
                !empty($data['linked_eval_run_id']) ? (int) $data['linked_eval_run_id'] : null,
                !empty($data['linked_entity_type']) ? (string) $data['linked_entity_type'] : null,
                !empty($data['linked_entity_id']) ? (int) $data['linked_entity_id'] : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function queueRecovery(array $data): ?int
    {
        if (!$this->tableExists('ai_autonomy_recovery_queue') || empty($data['incident_id'])) {
            return null;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''));
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        Database::execute(
            "INSERT INTO ai_autonomy_recovery_queue
                (workspace_id, incident_id, tenant_key, domain_key, action_key, status, suggested_manual_action, payload_json, assigned_to)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)",
            [
                $workspaceId,
                (int) $data['incident_id'],
                $tenantKey,
                (string) ($data['domain_key'] ?? 'commercial_mvp'),
                !empty($data['action_key']) ? (string) $data['action_key'] : null,
                (string) ($data['suggested_manual_action'] ?? 'Review and resolve autonomous action'),
                json_encode((array) ($data['payload'] ?? [])),
                !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function getIncident(int $incidentId): ?array
    {
        if (!$this->tableExists('ai_autonomy_incidents')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM ai_autonomy_incidents
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1",
            [$incidentId, $this->workspaceScope->requireWorkspaceId()]
        );
        return $row ? $this->hydrateIncident($row) : null;
    }

    public function getRecoveryItem(int $recoveryId): ?array
    {
        if (!$this->tableExists('ai_autonomy_recovery_queue')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM ai_autonomy_recovery_queue
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1",
            [$recoveryId, $this->workspaceScope->requireWorkspaceId()]
        );
        return $row ? $this->hydrateRecovery($row) : null;
    }

    public function listIncidents(?string $tenantKey = null, string $domainKey = 'commercial_mvp', int $limit = 20, array $filters = []): array
    {
        if (!$this->tableExists('ai_autonomy_incidents')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $where = ['workspace_id = ?', 'domain_key = ?'];
        $params = [$workspaceId, $domainKey];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $this->normalizeIncidentStatus((string) $filters['status']);
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'assigned_to = ?';
            $params[] = (int) $filters['assigned_to'];
        }
        $rows = Database::query(
            "SELECT * FROM ai_autonomy_incidents WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)),
            $params
        );
        return array_map(fn(array $row): array => $this->hydrateIncident($row), $rows);
    }

    public function listRecoveryQueue(?string $tenantKey = null, string $domainKey = 'commercial_mvp', int $limit = 20, array $filters = []): array
    {
        if (!$this->tableExists('ai_autonomy_recovery_queue')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $where = ['workspace_id = ?', 'domain_key = ?'];
        $params = [$workspaceId, $domainKey];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $this->normalizeRecoveryStatus((string) $filters['status']);
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'assigned_to = ?';
            $params[] = (int) $filters['assigned_to'];
        }
        $rows = Database::query(
            "SELECT * FROM ai_autonomy_recovery_queue WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)),
            $params
        );
        return array_map(fn(array $row): array => $this->hydrateRecovery($row), $rows);
    }

    public function assignRecoveryItem(int $recoveryId, int $assignedTo, ?int $operatorUserId = null, string $reason = ''): ?array
    {
        $item = $this->getRecoveryItem($recoveryId);
        if (!$item) {
            return null;
        }

        Database::execute(
            "UPDATE ai_autonomy_recovery_queue
             SET assigned_to = ?, assigned_at = NOW(), status = 'assigned', operator_notes_json = ?
             WHERE id = ?",
            [$assignedTo, json_encode($this->mergeNotes((array) ($item['operator_notes'] ?? []), $reason, $operatorUserId)), $recoveryId]
        );

        return $this->getRecoveryItem($recoveryId);
    }

    public function updateRecoveryStatus(int $recoveryId, string $status, ?int $operatorUserId = null, string $reason = '', array $patch = []): ?array
    {
        $item = $this->getRecoveryItem($recoveryId);
        if (!$item) {
            return null;
        }

        $status = $this->normalizeRecoveryStatus($status);
        $fields = ['status = ?', 'operator_notes_json = ?'];
        $params = [
            $status,
            json_encode($this->mergeNotes((array) ($item['operator_notes'] ?? []), $reason, $operatorUserId)),
        ];

        if ($status === 'resolved') {
            $fields[] = 'resolved_by = ?';
            $fields[] = 'resolved_at = NOW()';
            $params[] = $operatorUserId;
        }
        if ($status === 'suppressed') {
            $fields[] = 'suppressed_by = ?';
            $fields[] = 'suppressed_at = NOW()';
            $params[] = $operatorUserId;
        }
        if ($status === 'failed_retry') {
            $fields[] = 'last_attempted_at = NOW()';
            $fields[] = 'last_error = ?';
            $params[] = (string) ($patch['last_error'] ?? 'Retry failed');
        }
        if ($status === 'in_progress') {
            $fields[] = 'last_attempted_at = NOW()';
        }
        if (array_key_exists('assigned_to', $patch)) {
            $fields[] = 'assigned_to = ?';
            $params[] = $patch['assigned_to'] !== null ? (int) $patch['assigned_to'] : null;
        }

        $params[] = $recoveryId;
        Database::execute(
            "UPDATE ai_autonomy_recovery_queue SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );

        return $this->getRecoveryItem($recoveryId);
    }

    public function updateIncidentStatus(int $incidentId, string $status, ?int $operatorUserId = null, string $reason = '', array $patch = []): ?array
    {
        $incident = $this->getIncident($incidentId);
        if (!$incident) {
            return null;
        }

        $status = $this->normalizeIncidentStatus($status);
        $fields = ['status = ?', 'operator_notes_json = ?', 'last_operator_action = ?'];
        $params = [
            $status,
            json_encode($this->mergeNotes((array) ($incident['operator_notes'] ?? []), $reason, $operatorUserId)),
            (string) ($patch['last_operator_action'] ?? ('status:' . $status)),
        ];
        if ($status === 'in_progress') {
            $fields[] = 'assigned_to = COALESCE(assigned_to, ?)';
            $fields[] = 'assigned_at = COALESCE(assigned_at, NOW())';
            $params[] = $operatorUserId;
        }
        if ($status === 'resolved') {
            $fields[] = 'resolved_by = ?';
            $fields[] = 'resolved_at = NOW()';
            $params[] = $operatorUserId;
        }
        if ($status === 'suppressed') {
            $fields[] = 'suppressed_by = ?';
            $fields[] = 'suppressed_at = NOW()';
            $params[] = $operatorUserId;
        }
        if (array_key_exists('assigned_to', $patch)) {
            $fields[] = 'assigned_to = ?';
            $params[] = $patch['assigned_to'] !== null ? (int) $patch['assigned_to'] : null;
        }

        $params[] = $incidentId;
        Database::execute(
            "UPDATE ai_autonomy_incidents SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );

        return $this->getIncident($incidentId);
    }

    public function countRecentBlockedIncidents(string $tenantKey, string $domainKey, string $actionKey, int $windowHours = 24): int
    {
        if (!$this->tableExists('ai_autonomy_incidents')) {
            return 0;
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS aggregate_count
             FROM ai_autonomy_incidents
             WHERE workspace_id = ?
               AND domain_key = ?
               AND action_key = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$workspaceId, $domainKey, $actionKey, max(1, $windowHours)]
        );
        return (int) ($row['aggregate_count'] ?? 0);
    }

    public function summarizeOperationalState(string $tenantKey, string $domainKey): array
    {
        $incidents = $this->listIncidents($tenantKey, $domainKey, 100);
        $queue = $this->listRecoveryQueue($tenantKey, $domainKey, 100);
        $openIncidents = array_filter($incidents, static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['open', 'queued', 'in_progress'], true));
        $pendingQueue = array_filter($queue, static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['pending', 'assigned', 'in_progress', 'failed_retry'], true));
        $oldestPending = null;
        foreach ($pendingQueue as $item) {
            $createdAt = (string) ($item['created_at'] ?? '');
            if ($createdAt !== '' && ($oldestPending === null || strcmp($createdAt, $oldestPending) < 0)) {
                $oldestPending = $createdAt;
            }
        }

        return [
            'open_incident_count' => count($openIncidents),
            'recovery_backlog_count' => count($pendingQueue),
            'oldest_recovery_created_at' => $oldestPending,
            'critical_incident_count' => count(array_filter($openIncidents, static fn(array $row): bool => (string) ($row['severity'] ?? '') === 'critical')),
            'high_incident_count' => count(array_filter($openIncidents, static fn(array $row): bool => (string) ($row['severity'] ?? '') === 'high')),
            'failed_retry_count' => count(array_filter($queue, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'failed_retry')),
        ];
    }

    public function logOperatorAction(array $data): ?int
    {
        if (!$this->tableExists('ai_autonomy_operator_actions')) {
            return null;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''));
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        Database::execute(
            "INSERT INTO ai_autonomy_operator_actions
                (workspace_id, tenant_key, domain_key, operator_user_id, action_key, incident_id, recovery_queue_id, target_type,
                 target_id, reason, prior_state_json, result_state_json, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $tenantKey,
                (string) ($data['domain_key'] ?? 'commercial_mvp'),
                !empty($data['operator_user_id']) ? (int) $data['operator_user_id'] : null,
                (string) ($data['action_key'] ?? 'operator_action'),
                !empty($data['incident_id']) ? (int) $data['incident_id'] : null,
                !empty($data['recovery_queue_id']) ? (int) $data['recovery_queue_id'] : null,
                !empty($data['target_type']) ? (string) $data['target_type'] : null,
                !empty($data['target_id']) ? (int) $data['target_id'] : null,
                trim((string) ($data['reason'] ?? '')),
                json_encode((array) ($data['prior_state'] ?? [])),
                json_encode((array) ($data['result_state'] ?? [])),
                json_encode((array) ($data['metadata'] ?? [])),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function listOperatorActions(?string $tenantKey = null, string $domainKey = 'commercial_mvp', int $limit = 20): array
    {
        if (!$this->tableExists('ai_autonomy_operator_actions')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT *
             FROM ai_autonomy_operator_actions
             WHERE workspace_id = ?
               AND domain_key = ?
             ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(100, $limit)),
            [$workspaceId, $domainKey]
        );
        foreach ($rows as &$row) {
            $row['prior_state'] = $this->decodeJson($row['prior_state_json'] ?? null);
            $row['result_state'] = $this->decodeJson($row['result_state_json'] ?? null);
            $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
        }
        return $rows;
    }

    private function hydrateIncident(array $row): array
    {
        $row['reason_codes'] = $this->decodeJson($row['reason_codes_json'] ?? null);
        $row['details'] = $this->decodeJson($row['details_json'] ?? null);
        $row['operator_notes'] = $this->decodeJson($row['operator_notes_json'] ?? null);
        return $row;
    }

    private function hydrateRecovery(array $row): array
    {
        $row['payload'] = $this->decodeJson($row['payload_json'] ?? null);
        $row['operator_notes'] = $this->decodeJson($row['operator_notes_json'] ?? null);
        return $row;
    }

    private function normalizeSeverity(string $severity): string
    {
        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    }

    private function normalizeIncidentStatus(string $status): string
    {
        return in_array($status, self::INCIDENT_STATUSES, true) ? $status : 'open';
    }

    private function normalizeRecoveryStatus(string $status): string
    {
        return in_array($status, self::RECOVERY_STATUSES, true) ? $status : 'pending';
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

    private function mergeNotes(array $notes, string $reason, ?int $operatorUserId): array
    {
        $notes[] = [
            'reason' => trim($reason),
            'operator_user_id' => $operatorUserId,
            'recorded_at' => date('Y-m-d H:i:s'),
        ];
        return array_values(array_filter($notes, static fn(array $note): bool => $note['reason'] !== '' || !empty($note['operator_user_id'])));
    }
}
