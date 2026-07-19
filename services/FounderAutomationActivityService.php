<?php

namespace CRM\Services;

use CRM\Database;

final class FounderAutomationActivityService
{
    /**
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0) {
            return $this->summarizeEvents([]);
        }

        $events = array_merge(
            $this->dealEvents($workspaceId),
            $this->workflowEvents($workspaceId),
            $this->commercialEvents($workspaceId),
            $this->targetEvents($workspaceId),
            $this->taskEvidenceEvents($workspaceId, $userId),
            $this->autoAdminEvents($workspaceId)
        );

        return $this->summarizeEvents($events);
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public function summarizeEvents(array $events): array
    {
        $lanes = ['needs_you' => [], 'handled' => [], 'watching' => []];
        $seen = [];
        usort($events, static fn(array $left, array $right): int => strcmp(
            (string) ($right['occurred_at'] ?? ''),
            (string) ($left['occurred_at'] ?? '')
        ));

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $lane = (string) ($event['lane'] ?? 'watching');
            if (!array_key_exists($lane, $lanes)) {
                $lane = 'watching';
            }
            $id = (string) ($event['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $event['lane'] = $lane;
            $event['lifecycle'] = (string) ($event['lifecycle'] ?? ($lane === 'handled' ? 'succeeded' : ($lane === 'needs_you' ? 'awaiting_attention' : 'observed')));
            $event['customer_facing'] = !empty($event['customer_facing']);
            $event['can_undo'] = !empty($event['can_undo']);
            $lanes[$lane][] = $event;
        }

        $counts = array_map('count', $lanes);
        $visible = [
            'needs_you' => array_slice($lanes['needs_you'], 0, 3),
            'handled' => array_slice($lanes['handled'], 0, 3),
            'watching' => array_slice($lanes['watching'], 0, 2),
        ];

        $handledCount = (int) $counts['handled'];
        $needsCount = (int) $counts['needs_you'];
        if ($handledCount > 0 && $needsCount === 0) {
            $summary = $handledCount . ' verified action' . ($handledCount === 1 ? ' was' : 's were') . ' handled quietly. Nothing needs approval.';
        } elseif ($handledCount > 0) {
            $summary = $handledCount . ' verified action' . ($handledCount === 1 ? ' was' : 's were') . ' handled; ' . $needsCount . ' exception' . ($needsCount === 1 ? ' needs' : 's need') . ' attention.';
        } elseif ($needsCount > 0) {
            $summary = $needsCount . ' automation exception' . ($needsCount === 1 ? ' needs' : 's need') . ' attention. No work is being described as handled without execution evidence.';
        } else {
            $summary = 'No verified automated work needs reporting right now. The system will keep watching quietly.';
        }

        return [
            'summary' => $summary,
            'counts' => $counts,
            'lanes' => $visible,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function dealEvents(int $workspaceId): array
    {
        $rows = $this->safeQuery(
            'deal_automation_audit',
            "SELECT id, deal_id, contact_id, from_stage, to_stage, decision, confidence, applied, reason, evidence_summary, created_at
             FROM deal_automation_audit WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 30",
            [$workspaceId]
        );
        $events = [];
        foreach ($rows as $row) {
            $applied = !empty($row['applied']);
            $decision = strtolower((string) ($row['decision'] ?? ''));
            $lane = $applied ? 'handled' : (in_array($decision, ['approval_required', 'blocked', 'failed'], true) ? 'needs_you' : 'watching');
            $dealId = (int) ($row['deal_id'] ?? 0);
            $events[] = [
                'id' => 'deal_automation:' . (int) $row['id'],
                'domain' => 'deals',
                'lane' => $lane,
                'lifecycle' => $applied ? 'succeeded' : ($lane === 'needs_you' ? $decision : 'suggested'),
                'title' => $applied
                    ? 'Deal stage updated'
                    : ($lane === 'needs_you' ? 'Deal automation needs review' : 'Deal movement is being watched'),
                'summary' => $applied
                    ? trim((string) ($row['from_stage'] ?? '')) . ' → ' . trim((string) ($row['to_stage'] ?? ''))
                    : (string) (($row['reason'] ?? '') ?: ($row['evidence_summary'] ?? 'Automation recorded a deal signal.')),
                'entity_label' => $dealId > 0 ? 'Deal #' . $dealId : 'Deal',
                'action_url' => $dealId > 0 ? 'deal_view.php?id=' . $dealId : 'deals.php',
                'customer_facing' => false,
                'can_undo' => $applied,
                'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
                'occurred_at' => (string) ($row['created_at'] ?? ''),
                'source_type' => 'deal_automation_audit',
                'source_id' => (int) $row['id'],
            ];
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function workflowEvents(int $workspaceId): array
    {
        $rows = $this->safeQuery(
            'workflow_executions',
            "SELECT id, workflow_id, contact_id, status, error_message, actions_completed, actions_failed, executed_at, completed_at
             FROM workflow_executions WHERE workspace_id = ? ORDER BY COALESCE(completed_at, executed_at) DESC, id DESC LIMIT 30",
            [$workspaceId]
        );
        $events = [];
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $actionsCompleted = (int) ($row['actions_completed'] ?? 0);
            $handled = $status === 'completed' && $actionsCompleted > 0;
            $needs = $status === 'failed' || (int) ($row['actions_failed'] ?? 0) > 0;
            $workflowId = (int) ($row['workflow_id'] ?? 0);
            $events[] = [
                'id' => 'workflow_execution:' . (int) $row['id'],
                'domain' => 'workflows',
                'lane' => $handled ? 'handled' : ($needs ? 'needs_you' : 'watching'),
                'lifecycle' => $handled ? 'succeeded' : ($needs ? 'failed' : ($status !== '' ? $status : 'observed')),
                'title' => $handled ? 'Workflow completed' : ($needs ? 'Workflow needs review' : 'Workflow is in progress'),
                'summary' => $handled
                    ? $actionsCompleted . ' workflow action' . ($actionsCompleted === 1 ? '' : 's') . ' completed.'
                    : (string) (($row['error_message'] ?? '') ?: 'The workflow has not produced verified completed work yet.'),
                'entity_label' => $workflowId > 0 ? 'Workflow #' . $workflowId : 'Workflow',
                'action_url' => $workflowId > 0 ? 'workflow_builder.php?id=' . $workflowId : 'workflows.php',
                'customer_facing' => false,
                'can_undo' => false,
                'occurred_at' => (string) (($row['completed_at'] ?? '') ?: ($row['executed_at'] ?? '')),
                'source_type' => 'workflow_executions',
                'source_id' => (int) $row['id'],
            ];
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function commercialEvents(int $workspaceId): array
    {
        $rows = $this->safeQuery(
            'commercial_automation_runs',
            "SELECT id, deal_id, contact_id, invoice_id, trigger_type, decision, evidence_json, created_at
             FROM commercial_automation_runs WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 20",
            [$workspaceId]
        );
        $events = [];
        foreach ($rows as $row) {
            $decision = strtolower((string) ($row['decision'] ?? ''));
            $needs = in_array($decision, ['approval_required', 'blocked', 'failed'], true);
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $events[] = [
                'id' => 'commercial_run:' . (int) $row['id'],
                'domain' => 'commercial',
                'lane' => $needs ? 'needs_you' : 'watching',
                'lifecycle' => $needs ? $decision : 'observed',
                'title' => $needs ? 'Commercial action needs review' : 'Commercial action is being evaluated',
                'summary' => $needs
                    ? 'A customer-facing commercial action is waiting for a safe decision.'
                    : 'The policy allowed or observed this action, but execution success has not been proven yet.',
                'entity_label' => $invoiceId > 0 ? 'Invoice #' . $invoiceId : 'Commercial action',
                'action_url' => $needs ? 'commercial_approvals.php' : ($invoiceId > 0 ? 'invoice_view.php?id=' . $invoiceId : 'invoices.php'),
                'customer_facing' => true,
                'can_undo' => false,
                'occurred_at' => (string) ($row['created_at'] ?? ''),
                'source_type' => 'commercial_automation_runs',
                'source_id' => (int) $row['id'],
            ];
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function targetEvents(int $workspaceId): array
    {
        $rows = $this->safeQuery(
            'target_state_transitions',
            "SELECT st.id, st.target_id, st.from_status, st.to_status, st.previous_value, st.new_value,
                    st.actor_type, st.decision_source, st.confidence_score, st.explanation, st.created_at, t.title
             FROM target_state_transitions st
             JOIN targets t ON t.id = st.target_id AND t.workspace_id = st.workspace_id
             WHERE st.workspace_id = ? AND st.actor_type IN ('clarity','automation','system')
             ORDER BY st.created_at DESC, st.id DESC LIMIT 20",
            [$workspaceId]
        );
        $events = [];
        foreach ($rows as $row) {
            $targetId = (int) ($row['target_id'] ?? 0);
            $events[] = [
                'id' => 'target_transition:' . (int) $row['id'],
                'domain' => 'targets',
                'lane' => 'handled',
                'lifecycle' => 'succeeded',
                'title' => 'Target evidence updated',
                'summary' => (string) (($row['explanation'] ?? '') ?: ('Progress moved from ' . (string) ($row['previous_value'] ?? 0) . ' to ' . (string) ($row['new_value'] ?? 0) . '.')),
                'entity_label' => (string) (($row['title'] ?? '') ?: ('Target #' . $targetId)),
                'action_url' => $targetId > 0 ? 'target_view.php?id=' . $targetId : 'targets.php',
                'customer_facing' => false,
                'can_undo' => (string) ($row['to_status'] ?? '') !== 'completed',
                'confidence' => isset($row['confidence_score']) ? (float) $row['confidence_score'] : null,
                'occurred_at' => (string) ($row['created_at'] ?? ''),
                'source_type' => 'target_state_transitions',
                'source_id' => (int) $row['id'],
            ];
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function taskEvidenceEvents(int $workspaceId, int $userId): array
    {
        $rows = $this->safeQuery(
            'ai_task_evidence',
            "SELECT e.id, e.task_id, e.evidence_type, e.confidence_score, e.decision_status, e.created_at,
                    t.title, t.status, t.assigned_to
             FROM ai_task_evidence e
             JOIN tasks t ON t.id = e.task_id AND t.workspace_id = e.workspace_id
             WHERE e.workspace_id = ? AND (t.assigned_to = ? OR t.created_by = ?)
             ORDER BY e.created_at DESC, e.id DESC LIMIT 20",
            [$workspaceId, $userId, $userId]
        );
        $events = [];
        foreach ($rows as $row) {
            $completed = (string) ($row['status'] ?? '') === 'completed';
            $taskId = (int) ($row['task_id'] ?? 0);
            $events[] = [
                'id' => 'task_evidence:' . (int) $row['id'],
                'domain' => 'tasks',
                'lane' => $completed ? 'handled' : 'watching',
                'lifecycle' => $completed ? 'succeeded' : 'observed',
                'title' => $completed ? 'Task completed with evidence' : 'Task evidence recorded',
                'summary' => (string) (($row['title'] ?? '') ?: 'Task evidence was recorded.'),
                'entity_label' => (string) (($row['title'] ?? '') ?: ('Task #' . $taskId)),
                'action_url' => $taskId > 0 ? 'task_view.php?id=' . $taskId : 'tasks.php',
                'customer_facing' => false,
                'can_undo' => false,
                'confidence' => isset($row['confidence_score']) ? (float) $row['confidence_score'] : null,
                'occurred_at' => (string) ($row['created_at'] ?? ''),
                'source_type' => 'ai_task_evidence',
                'source_id' => (int) $row['id'],
            ];
        }
        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function autoAdminEvents(int $workspaceId): array
    {
        $rows = $this->safeQuery(
            'workspace_auto_admin_events',
            "SELECT id, event_type, reason, created_at FROM workspace_auto_admin_events
             WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 10",
            [$workspaceId]
        );
        return array_map(static fn(array $row): array => [
            'id' => 'auto_admin:' . (int) $row['id'],
            'domain' => 'automation_safety',
            'lane' => 'watching',
            'lifecycle' => 'observed',
            'title' => 'Automation safety setting changed',
            'summary' => (string) (($row['reason'] ?? '') ?: str_replace('_', ' ', (string) ($row['event_type'] ?? 'Auto Admin event'))),
            'entity_label' => 'Automation controls',
            'action_url' => 'automation.php',
            'customer_facing' => false,
            'can_undo' => false,
            'occurred_at' => (string) ($row['created_at'] ?? ''),
            'source_type' => 'workspace_auto_admin_events',
            'source_id' => (int) $row['id'],
        ], $rows);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function safeQuery(string $table, string $sql, array $params): array
    {
        try {
            if (!Database::tableExists($table)) {
                return [];
            }
            return Database::query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
