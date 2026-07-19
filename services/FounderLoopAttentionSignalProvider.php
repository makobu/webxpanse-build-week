<?php

namespace CRM\Services;

final class FounderLoopAttentionSignalProvider implements FounderAttentionSignalProviderInterface
{
    public function provide(int $workspaceId, int $userId, array $snapshot): array
    {
        $operating = (array) ($snapshot['operating_context'] ?? []);
        $loop = (array) ($operating['founder_operating_loop_context'] ?? []);
        if ($loop === [] || isset($loop['error'])) {
            return [];
        }

        $signals = [];
        $plan = (array) ($loop['weekly_plan'] ?? []);
        $firstCustomer = (array) ($loop['first_customer_signal'] ?? []);
        $current = (array) ($loop['current_commitment'] ?? []);
        $blocked = (array) ($loop['blocked_commitment'] ?? []);
        $focus = trim((string) ($plan['focus'] ?? ''));
        $gap = trim((string) ($firstCustomer['strongest_gap'] ?? ''));
        $nextAction = trim((string) ($loop['next_action'] ?? $loop['next_recommended_loop_step']['next_action'] ?? ''));

        if ($focus !== '' || $gap !== '' || $nextAction !== '') {
            $signals[] = [
                'id' => 'founder_loop:weekly-focus',
                'domain' => 'founder_loop',
                'kind' => 'constraint',
                'constraint_key' => 'first_customer_motion',
                'dedupe_key' => 'founder_loop:weekly-focus',
                'title' => $focus !== '' ? $focus : 'Move the first-customer loop forward',
                'summary' => $gap !== '' ? $gap : $nextAction,
                'why_now' => (string) ($firstCustomer['headline'] ?? 'This is the strongest current first-customer signal.'),
                'impact' => 'high',
                'urgency' => $blocked !== [] ? 'high' : 'medium',
                'confidence' => $gap !== '' ? 0.86 : 0.74,
                'freshness_status' => 'fresh',
                'decision_required' => false,
                'blocked' => $blocked !== [],
                'effort' => 'medium',
                'reversibility' => 'easy',
                'recommended_action' => $nextAction !== '' ? $nextAction : 'Open Founder Loop and work the current commitment.',
                'action_label' => $current !== [] ? 'Open current commitment' : 'Open Founder Loop',
                'action_url' => 'founder_operating_loop.php',
                'owner_user_id' => $userId,
                'due_at' => (string) ($current['due_date'] ?? ''),
                'evidence' => [[
                    'label' => (string) ($firstCustomer['metric'] ?? $gap),
                    'source' => 'founder_operating_loop',
                ]],
                'source_entity_type' => 'founder_week',
                'source_entity_id' => (string) ($loop['active_week']['start'] ?? ''),
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        if ($blocked !== []) {
            $signals[] = [
                'id' => 'founder_loop:blocked-commitment',
                'domain' => 'founder_loop',
                'kind' => 'exception',
                'constraint_key' => 'blocked_founder_commitment',
                'dedupe_key' => 'founder_loop:blocked-commitment',
                'title' => (string) ($blocked['title'] ?? 'Unblock the current founder commitment'),
                'summary' => (string) ($blocked['description'] ?? 'The current commitment is overdue or blocked.'),
                'why_now' => 'The selected operating focus cannot progress while its founder-owned commitment is blocked.',
                'impact' => 'high',
                'urgency' => 'high',
                'confidence' => 1.0,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => true,
                'effort' => 'medium',
                'reversibility' => 'easy',
                'recommended_action' => 'Review the blocker, adjust the commitment, or complete the next step.',
                'action_label' => 'Review commitment',
                'action_url' => 'founder_operating_loop.php',
                'owner_user_id' => $userId,
                'due_at' => (string) ($blocked['due_date'] ?? ''),
                'evidence' => [[
                    'label' => 'Saved commitment is blocked or overdue',
                    'source' => 'founder_weekly_review_commitments',
                ]],
                'source_entity_type' => 'founder_commitment',
                'source_entity_id' => (string) ($blocked['id'] ?? ''),
                'observed_at' => (string) ($snapshot['generated_at'] ?? gmdate('c')),
            ];
        }

        return $signals;
    }
}
