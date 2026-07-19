<?php

namespace CRM\Services;

final class FounderWorkAttentionSignalProvider implements FounderAttentionSignalProviderInterface
{
    public function provide(int $workspaceId, int $userId, array $snapshot): array
    {
        $operating = (array) ($snapshot['operating_context'] ?? []);
        $taskState = (array) ($operating['task_state'] ?? []);
        $targetState = (array) ($operating['target_state'] ?? []);
        $inboxState = (array) ($operating['inbox_state'] ?? []);
        $signals = [];

        $overdueThreads = (int) ($inboxState['overdue_threads'] ?? 0);
        if ($overdueThreads > 0) {
            $signals[] = [
                'id' => 'inbox:overdue-responses',
                'domain' => 'customer',
                'kind' => 'exception',
                'constraint_key' => 'customer_response_delay',
                'dedupe_key' => 'inbox:overdue-responses',
                'title' => 'Reply to overdue customer conversations',
                'summary' => $overdueThreads . ' conversation' . ($overdueThreads === 1 ? ' is' : 's are') . ' past the response window.',
                'why_now' => 'Waiting customers can slow pipeline movement and weaken trust.',
                'impact' => 'high',
                'urgency' => 'high',
                'confidence' => 1.0,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => false,
                'effort' => 'medium',
                'reversibility' => 'easy',
                'recommended_action' => 'Open the inbox and respond to the oldest customer-owned item first.',
                'action_label' => 'Open inbox',
                'action_url' => 'inbox.php?status=unread',
                'owner_user_id' => $userId,
                'evidence' => [['label' => $overdueThreads . ' overdue response window(s)', 'source' => 'conversation_threads']],
                'source_entity_type' => 'conversation_queue',
                'source_entity_id' => 'overdue',
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        $overdueTasks = (int) ($taskState['overdue_tasks'] ?? 0);
        if ($overdueTasks > 0) {
            $signals[] = [
                'id' => 'tasks:overdue',
                'domain' => 'execution',
                'kind' => 'exception',
                'constraint_key' => 'overdue_execution',
                'dedupe_key' => 'tasks:overdue',
                'title' => 'Clear the oldest overdue commitment',
                'summary' => $overdueTasks . ' task' . ($overdueTasks === 1 ? ' needs' : 's need') . ' a decision, new date, or completion evidence.',
                'why_now' => 'Old commitments create noise and make the operating focus less trustworthy.',
                'impact' => $overdueTasks >= 3 ? 'high' : 'medium',
                'urgency' => 'high',
                'confidence' => 1.0,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => $overdueTasks >= 3,
                'effort' => 'medium',
                'reversibility' => 'easy',
                'recommended_action' => 'Review the oldest overdue task and complete, reschedule, or cancel it deliberately.',
                'action_label' => 'Review tasks',
                'action_url' => 'tasks.php?filter=overdue',
                'owner_user_id' => $userId,
                'evidence' => [['label' => $overdueTasks . ' overdue task(s)', 'source' => 'tasks']],
                'source_entity_type' => 'task_queue',
                'source_entity_id' => 'overdue',
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        $topTargets = array_values(array_filter((array) ($targetState['top_targets'] ?? []), 'is_array'));
        foreach ($topTargets as $target) {
            $band = (string) ($target['status_band'] ?? 'on_track');
            if (!in_array($band, ['at_risk', 'blocked', 'behind', 'missed'], true)) {
                continue;
            }
            $targetId = (int) ($target['id'] ?? 0);
            $signals[] = [
                'id' => 'target:' . $targetId,
                'domain' => 'growth',
                'kind' => 'decision',
                'constraint_key' => 'target_off_track:' . $targetId,
                'dedupe_key' => 'target:' . $targetId,
                'title' => (string) ($target['title'] ?? 'Review an off-track target'),
                'summary' => (string) (($target['pace_summary'] ?? '') ?: 'This target is ' . str_replace('_', ' ', $band) . '.'),
                'why_now' => 'A target that is off track needs a changed action, date, or expectation before more work is added.',
                'impact' => in_array($band, ['blocked', 'missed'], true) ? 'high' : 'medium',
                'urgency' => in_array($band, ['blocked', 'missed'], true) ? 'high' : 'medium',
                'confidence' => max(0.55, min(1.0, (float) ($target['forecast_score'] ?? 0.75))),
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => $band === 'blocked',
                'effort' => 'medium',
                'reversibility' => 'easy',
                'recommended_action' => 'Review the evidence and choose the next measurable action.',
                'action_label' => 'Review target',
                'action_url' => $targetId > 0 ? 'target_view.php?id=' . $targetId : 'targets.php',
                'owner_user_id' => $userId,
                'evidence' => [['label' => str_replace('_', ' ', $band), 'source' => 'target_intelligence']],
                'source_entity_type' => 'target',
                'source_entity_id' => (string) $targetId,
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
            break;
        }

        return $signals;
    }
}
