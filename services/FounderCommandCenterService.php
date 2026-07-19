<?php

namespace CRM\Services;

final class FounderCommandCenterService
{
    private BusinessContextSnapshotService $snapshots;
    private FounderAttentionRankingService $ranking;
    private FounderAutomationActivityService $activity;
    private FounderGrowthExperimentService $growth;

    /** @var list<FounderAttentionSignalProviderInterface> */
    private array $providers;

    /**
     * @param list<FounderAttentionSignalProviderInterface>|null $providers
     */
    public function __construct(
        ?BusinessContextSnapshotService $snapshots = null,
        ?FounderAttentionRankingService $ranking = null,
        ?FounderAutomationActivityService $activity = null,
        ?FounderGrowthExperimentService $growth = null,
        ?array $providers = null
    ) {
        $this->snapshots = $snapshots ?? new BusinessContextSnapshotService();
        $this->ranking = $ranking ?? new FounderAttentionRankingService();
        $this->activity = $activity ?? new FounderAutomationActivityService();
        $this->growth = $growth ?? new FounderGrowthExperimentService();
        $this->providers = $providers ?? [
            new FounderContextAttentionSignalProvider(),
            new FounderLoopAttentionSignalProvider(),
            new FounderWorkAttentionSignalProvider(),
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId, array $options = []): array
    {
        $snapshot = isset($options['snapshot']) && is_array($options['snapshot'])
            ? $options['snapshot']
            : $this->snapshots->build($workspaceId, $userId);
        $activity = isset($options['activity']) && is_array($options['activity'])
            ? $options['activity']
            : $this->activity->build($workspaceId, $userId);

        $signals = [];
        foreach ($this->providers as $provider) {
            try {
                array_push($signals, ...$provider->provide($workspaceId, $userId, $snapshot));
            } catch (\Throwable $e) {
                // One domain must not prevent the founder from seeing other valid work.
            }
        }
        array_push($signals, ...$this->activityAttentionSignals($activity, $userId, $snapshot));
        if (isset($options['signals']) && is_array($options['signals'])) {
            array_push($signals, ...array_values(array_filter($options['signals'], 'is_array')));
        }

        $attention = $this->ranking->rank($signals, 3);
        $growth = isset($options['growth']) && is_array($options['growth'])
            ? $options['growth']
            : $this->growth->build($workspaceId, $userId, $snapshot, !empty($options['allow_marketing']));

        return $this->compose($workspaceId, $userId, $snapshot, $attention, $activity, $growth);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<array<string,mixed>> $attention
     * @param array<string,mixed> $activity
     * @param array<string,mixed> $growth
     * @return array<string,mixed>
     */
    public function compose(
        int $workspaceId,
        int $userId,
        array $snapshot,
        array $attention,
        array $activity,
        array $growth
    ): array {
        $operating = (array) ($snapshot['operating_context'] ?? []);
        $loop = (array) ($operating['founder_operating_loop_context'] ?? []);
        $primary = $this->primaryConstraint($attention, $loop, $snapshot);
        $commitment = $this->currentCommitment((array) ($loop['current_commitment'] ?? []));
        $context = $snapshot;
        unset($context['operating_context']);

        $attentionCount = count($attention);
        $handledCount = (int) ($activity['counts']['handled'] ?? 0);
        $headline = (string) ($primary['title'] ?? 'Your operating rhythm is clear');
        $summary = $attentionCount > 0
            ? $attentionCount . ' item' . ($attentionCount === 1 ? ' needs' : 's need') . ' your judgment or founder work.'
            : 'No immediate decisions are competing for your attention.';
        if ($handledCount > 0) {
            $summary .= ' ' . $handledCount . ' verified action' . ($handledCount === 1 ? ' was' : 's were') . ' handled quietly.';
        }

        return [
            'version' => 1,
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'generated_at' => gmdate('c'),
            'headline' => $headline,
            'summary' => $summary,
            'primary_constraint' => $primary,
            'needs_you' => array_slice($attention, 0, 3),
            'current_commitment' => $commitment,
            'system_activity' => $activity,
            'growth_experiment' => $growth,
            'context' => $context,
            'rhythm' => $this->rhythmState($context, $primary, $commitment, $activity, $growth),
            'ui' => [
                'visible_attention_limit' => 3,
                'quiet_mode' => true,
                'show_explanations_on_demand' => true,
            ],
        ];
    }

    /**
     * Keep the mobile contract focused on the same rhythm without shipping the
     * full provenance graph needed by the desktop disclosure panel.
     *
     * @param array<string,mixed> $commandCenter
     * @return array<string,mixed>
     */
    public function compactForMobile(array $commandCenter): array
    {
        $activity = (array) ($commandCenter['system_activity'] ?? []);
        $lanes = (array) ($activity['lanes'] ?? []);
        $context = (array) ($commandCenter['context'] ?? []);

        return [
            'version' => (int) ($commandCenter['version'] ?? 1),
            'generated_at' => $commandCenter['generated_at'] ?? gmdate('c'),
            'headline' => (string) ($commandCenter['headline'] ?? ''),
            'summary' => (string) ($commandCenter['summary'] ?? ''),
            'primary_constraint' => (array) ($commandCenter['primary_constraint'] ?? []),
            'needs_you' => array_slice((array) ($commandCenter['needs_you'] ?? []), 0, 3),
            'current_commitment' => (array) ($commandCenter['current_commitment'] ?? []),
            'system_activity' => [
                'summary' => (string) ($activity['summary'] ?? ''),
                'counts' => (array) ($activity['counts'] ?? []),
                'handled' => array_slice((array) ($lanes['handled'] ?? []), 0, 2),
            ],
            'growth_experiment' => (array) ($commandCenter['growth_experiment'] ?? []),
            'context' => [
                'snapshot_id' => $context['snapshot_id'] ?? null,
                'health' => (array) ($context['health'] ?? []),
                'summary' => (array) ($context['summary'] ?? []),
            ],
            'rhythm' => (array) ($commandCenter['rhythm'] ?? []),
        ];
    }

    /**
     * @param list<array<string,mixed>> $attention
     * @return array<string,mixed>
     */
    private function primaryConstraint(array $attention, array $loop, array $snapshot): array
    {
        if ($attention !== []) {
            return $this->focusFromAttention((array) $attention[0]);
        }

        $plan = (array) ($loop['weekly_plan'] ?? []);
        $focus = trim((string) ($plan['focus'] ?? ''));
        if ($focus !== '') {
            return [
                'constraint_key' => 'founder_loop_weekly_focus',
                'title' => $focus,
                'summary' => (string) ($plan['objective'] ?? ''),
                'why_now' => 'This is the current Founder Loop focus based on Clarity Journey and CRM evidence.',
                'confidence' => 0.76,
                'evidence' => [],
                'action_label' => 'Open Founder Loop',
                'action_url' => 'founder_operating_loop.php',
                'source_item_id' => null,
            ];
        }

        $health = (array) ($snapshot['health'] ?? []);
        return [
            'constraint_key' => 'no_immediate_constraint',
            'title' => 'No immediate constraint needs a decision',
            'summary' => 'The system will keep watching for meaningful changes instead of inventing more work.',
            'why_now' => 'Current context contains no evidence-backed exception that should displace planned work.',
            'confidence' => ((int) ($health['strength'] ?? 0)) / 100,
            'evidence' => [],
            'action_label' => '',
            'action_url' => '',
            'source_item_id' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function focusFromAttention(array $item): array
    {
        return [
            'constraint_key' => (string) ($item['constraint_key'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'summary' => (string) ($item['summary'] ?? ''),
            'why_now' => (string) ($item['why_now'] ?? ''),
            'confidence' => (float) ($item['confidence'] ?? 0),
            'evidence' => array_slice((array) ($item['evidence'] ?? []), 0, 3),
            'action_label' => (string) ($item['action_label'] ?? 'Open'),
            'action_url' => (string) ($item['action_url'] ?? ''),
            'source_item_id' => (string) ($item['id'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function currentCommitment(array $commitment): array
    {
        if ($commitment === []) {
            return [
                'status' => 'not_selected',
                'title' => 'No founder commitment selected yet',
                'description' => 'Choose one measurable next move before adding more work.',
                'due_date' => null,
                'task_id' => null,
                'action_label' => 'Choose in Founder Loop',
                'action_url' => 'founder_operating_loop.php',
            ];
        }
        $taskId = (int) ($commitment['task_id'] ?? 0);
        return [
            'status' => (string) ($commitment['status'] ?? 'pending'),
            'title' => (string) ($commitment['title'] ?? 'Current founder commitment'),
            'description' => (string) ($commitment['description'] ?? ''),
            'due_date' => $commitment['due_date'] ?? null,
            'task_id' => $taskId > 0 ? $taskId : null,
            'action_label' => $taskId > 0 ? 'Open task' : 'Open Founder Loop',
            'action_url' => $taskId > 0 ? 'task_view.php?id=' . $taskId : 'founder_operating_loop.php',
        ];
    }

    /**
     * @param array<string,mixed> $activity
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    private function activityAttentionSignals(array $activity, int $userId, array $snapshot): array
    {
        $signals = [];
        foreach ((array) ($activity['lanes']['needs_you'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $signals[] = [
                'id' => 'automation_attention:' . (string) ($event['id'] ?? ''),
                'domain' => (string) ($event['domain'] ?? 'automation'),
                'kind' => 'approval',
                'constraint_key' => 'automation_exception:' . (string) ($event['id'] ?? ''),
                'dedupe_key' => 'automation:' . (string) ($event['id'] ?? ''),
                'title' => (string) ($event['title'] ?? 'Automation needs review'),
                'summary' => (string) ($event['summary'] ?? ''),
                'why_now' => !empty($event['customer_facing'])
                    ? 'A customer-facing action is waiting for a safe human decision.'
                    : 'Automation stopped safely and needs a human exception decision.',
                'impact' => !empty($event['customer_facing']) ? 'high' : 'medium',
                'urgency' => 'high',
                'confidence' => isset($event['confidence']) ? (float) $event['confidence'] : 0.95,
                'freshness_status' => 'fresh',
                'decision_required' => true,
                'blocked' => true,
                'effort' => 'low',
                'reversibility' => !empty($event['can_undo']) ? 'easy' : 'unknown',
                'recommended_action' => 'Review the evidence and choose whether to apply, retry, or leave blocked.',
                'action_label' => 'Review',
                'action_url' => (string) ($event['action_url'] ?? ''),
                'owner_user_id' => $userId,
                'evidence' => [[
                    'label' => (string) ($event['summary'] ?? 'Automation exception'),
                    'source' => (string) ($event['source_type'] ?? 'automation'),
                ]],
                'source_entity_type' => (string) ($event['source_type'] ?? 'automation_event'),
                'source_entity_id' => (string) ($event['source_id'] ?? ''),
                'observed_at' => (string) ($event['occurred_at'] ?? $snapshot['generated_at'] ?? gmdate('c')),
            ];
        }
        return $signals;
    }

    /** @return array<string,mixed> */
    private function rhythmState(array $context, array $constraint, array $commitment, array $activity, array $growth): array
    {
        $contextStatus = (string) ($context['health']['status'] ?? 'building');
        $evidenceState = (string) ($growth['evidence_state'] ?? 'not_started');
        $learning = (array) ($growth['last_learning'] ?? []);
        return [
            'understand' => in_array($contextStatus, ['ready', 'needs_confirmation'], true) ? 'ready' : $contextStatus,
            'identify_constraint' => (string) ($constraint['constraint_key'] ?? '') !== 'no_immediate_constraint' ? 'ready' : 'watching',
            'decide' => (string) ($commitment['status'] ?? 'not_selected') !== 'not_selected' ? 'ready' : 'needs_attention',
            'automate' => (int) ($activity['counts']['needs_you'] ?? 0) > 0
                ? 'needs_attention'
                : ((int) ($activity['counts']['handled'] ?? 0) > 0 ? 'verified' : 'watching'),
            'execute' => (string) ($commitment['status'] ?? 'not_selected'),
            'measure' => $evidenceState,
            'learn' => !empty($learning['has_conclusion']) ? 'complete' : 'waiting',
        ];
    }
}
