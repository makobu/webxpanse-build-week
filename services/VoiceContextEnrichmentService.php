<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

/**
 * Applies post-call intelligence through the workspace automation policy.
 * Raw transcripts never enter contact context; only bounded, traceable facts
 * and summaries are eligible for CRM application.
 */
class VoiceContextEnrichmentService
{
    private VoicePolicyDecisionService $policies;
    private WorkspaceVoiceConfigService $configs;

    public function __construct(
        ?VoicePolicyDecisionService $policies = null,
        ?WorkspaceVoiceConfigService $configs = null
    ) {
        $this->policies = $policies ?? new VoicePolicyDecisionService();
        $this->configs = $configs ?? new WorkspaceVoiceConfigService();
    }

    /** @return array<string,mixed> */
    public function apply(
        int $workspaceId,
        int $callId,
        array $analysis,
        bool $approved = false,
        int $approvedByUserId = 0
    ): array {
        if ($workspaceId <= 0 || $callId <= 0) {
            return ['applied' => [], 'skipped' => [], 'decisions' => [], 'review_status' => 'reviewed'];
        }

        $call = Database::queryOne(
            "SELECT c.*, ct.assigned_to AS contact_owner_user_id
             FROM voice_calls c
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
             WHERE c.workspace_id = ? AND c.id = ? AND c.state = 'completed' LIMIT 1",
            [$workspaceId, $callId]
        );
        if (!$call) {
            throw new \RuntimeException('Completed voice call not found for context enrichment.');
        }

        $config = $this->configs->get($workspaceId, false);
        $confidence = max(0.0, min(1.0, (float) ($analysis['confidence'] ?? 0.0)));
        $applied = [];
        $skipped = [];
        $decisions = [];
        $hasReviewableSuggestion = false;

        $summaryDecision = $this->policies->decide($config, 'call_summary', $approved, $confidence);
        $contactDecision = $this->policies->decide($config, 'contact_context', $approved, $confidence);
        $decisions['call_summary'] = $summaryDecision;
        $decisions['contact_context'] = $contactDecision;
        $crmInsight = null;
        if (!empty($summaryDecision['apply'])) {
            // A summary may be automatic while structured contact context still
            // requires approval. Only upgrade activity metadata with structured
            // fields when that separate capability is permitted.
            $crmInsight = [
                'summary' => (string) ($analysis['summary'] ?? ''),
                'confidence' => $confidence,
            ];
            if (!empty($contactDecision['apply'])) {
                $crmInsight = $analysis;
            }
        }
        $crmLinks = (new VoiceCrmContextService())->sync(
            $workspaceId,
            $callId,
            $crmInsight
        );
        if (!empty($summaryDecision['apply']) && $crmLinks !== []) {
            $applied[] = [
                'type' => 'crm_summary',
                'activity_id' => (int) ($crmLinks['activity_id'] ?? 0),
                'communication_id' => (int) ($crmLinks['communication_id'] ?? 0),
                'policy_mode' => (string) $summaryDecision['mode'],
            ];
        } elseif (empty($summaryDecision['apply'])) {
            $skipped[] = $this->skipFromDecision('crm_summary', $summaryDecision);
        }

        if (!empty($contactDecision['apply']) && (int) ($call['contact_id'] ?? 0) > 0) {
            if ($this->appendContactContext($workspaceId, $call, $analysis, $approvedByUserId, (string) $contactDecision['mode'])) {
                $applied[] = [
                    'type' => 'contact_context',
                    'contact_id' => (int) $call['contact_id'],
                    'source_call_id' => $callId,
                    'policy_mode' => (string) $contactDecision['mode'],
                ];
            }
        } elseif ((int) ($call['contact_id'] ?? 0) <= 0) {
            $skipped[] = ['type' => 'contact_context', 'reason' => 'contact_not_resolved', 'policy_mode' => (string) $contactDecision['mode']];
            $hasReviewableSuggestion = $hasReviewableSuggestion || in_array((string) $contactDecision['decision'], ['suggest', 'require_approval', 'allow'], true);
        } else {
            $skipped[] = $this->skipFromDecision('contact_context', $contactDecision);
            $hasReviewableSuggestion = $hasReviewableSuggestion || in_array((string) $contactDecision['decision'], ['suggest', 'require_approval'], true);
        }

        $taskSuggestions = array_values((array) ($analysis['task_suggestions'] ?? []));
        $taskDecision = $this->policies->decide($config, 'follow_up_tasks', $approved, $confidence);
        $decisions['follow_up_tasks'] = $taskDecision;
        if ($taskSuggestions !== [] && !empty($taskDecision['apply']) && (int) ($call['contact_id'] ?? 0) > 0) {
            $taskResult = $this->createTasks($workspaceId, $call, $taskSuggestions, $approvedByUserId, (string) $taskDecision['mode']);
            $applied = array_merge($applied, $taskResult['applied']);
            $skipped = array_merge($skipped, $taskResult['skipped']);
        } elseif ($taskSuggestions !== []) {
            $reason = (int) ($call['contact_id'] ?? 0) > 0 ? null : 'contact_not_resolved';
            $skip = $this->skipFromDecision('follow_up_tasks', $taskDecision);
            if ($reason !== null) {
                $skip['reason'] = $reason;
            }
            $skip['suggestion_count'] = count($taskSuggestions);
            $skipped[] = $skip;
            $hasReviewableSuggestion = $hasReviewableSuggestion || in_array((string) $taskDecision['decision'], ['suggest', 'require_approval', 'allow'], true);
        }

        $dealSuggestion = (array) ($analysis['deal_stage_suggestion'] ?? []);
        $dealDecision = $this->policies->decide($config, 'deal_stage', $approved, $confidence);
        $decisions['deal_stage'] = $dealDecision;
        if ($dealSuggestion !== []) {
            $skipped[] = [
                'type' => 'deal_stage',
                'reason' => !empty($dealDecision['apply']) ? 'deal_stage_application_not_supported_v1' : (string) $dealDecision['reason'],
                'policy_mode' => (string) $dealDecision['mode'],
            ];
            $hasReviewableSuggestion = $hasReviewableSuggestion || in_array((string) $dealDecision['decision'], ['suggest', 'require_approval'], true);
        }

        $messageDecision = $this->policies->decide($config, 'follow_up_messages', $approved, $confidence);
        $decisions['follow_up_messages'] = $messageDecision;
        if ((array) ($analysis['requested_actions'] ?? []) !== []) {
            $skipped[] = [
                'type' => 'follow_up_messages',
                'reason' => !empty($messageDecision['apply']) ? 'automatic_message_sending_not_supported_v1' : (string) $messageDecision['reason'],
                'policy_mode' => (string) $messageDecision['mode'],
            ];
            $hasReviewableSuggestion = $hasReviewableSuggestion || in_array((string) $messageDecision['decision'], ['suggest', 'require_approval'], true);
        }

        $decisions['customer_voice'] = $this->policies->decide($config, 'customer_voice', false, $confidence);
        $reviewRequired = $hasReviewableSuggestion;
        $reviewStatus = $approved
            ? ($applied !== [] ? 'applied' : 'reviewed')
            : ($reviewRequired ? 'pending' : ($applied !== [] ? 'applied' : 'reviewed'));

        $result = [
            'applied' => $this->uniqueActions($applied),
            'skipped' => $this->uniqueActions($skipped),
            'decisions' => $decisions,
            'review_required' => $reviewRequired,
            'review_status' => $reviewStatus,
            'approved' => $approved,
            'approved_by_user_id' => $approvedByUserId > 0 ? $approvedByUserId : null,
        ];
        $this->persistOutcome($workspaceId, $callId, $result);

        if ((int) ($call['contact_id'] ?? 0) > 0 && $applied !== []) {
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $call['contact_id']);
            } catch (\Throwable $e) {
                error_log('Voice contact enrichment refresh failed: ' . $e->getMessage());
            }
        }

        try {
            (new VoiceCallService())->recordInternalEvent($workspaceId, $callId, 'voice.insight.policy_evaluated', null);
        } catch (\Throwable $e) {
            error_log('Voice policy event recording failed: ' . $e->getMessage());
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function applyStoredInsight(int $workspaceId, int $callId, bool $approved = false, int $approvedByUserId = 0): array
    {
        $row = Database::queryOne(
            'SELECT * FROM voice_call_insights WHERE workspace_id = ? AND call_id = ? LIMIT 1',
            [$workspaceId, $callId]
        );
        if (!$row) {
            return (new VoiceCrmContextService())->sync($workspaceId, $callId);
        }
        $decode = static function ($value): array {
            $decoded = $value ? json_decode((string) $value, true) : [];
            return is_array($decoded) ? $decoded : [];
        };
        return $this->apply($workspaceId, $callId, [
            'summary' => (string) ($row['summary'] ?? ''),
            'relationship_context' => (string) ($row['relationship_context'] ?? ''),
            'sentiment' => (string) ($row['sentiment'] ?? ''),
            'intent' => (string) ($row['intent'] ?? ''),
            'pains' => $decode($row['pains_json'] ?? null),
            'goals' => $decode($row['goals_json'] ?? null),
            'objections' => $decode($row['objections_json'] ?? null),
            'commitments' => $decode($row['commitments_json'] ?? null),
            'requested_actions' => $decode($row['requested_actions_json'] ?? null),
            'next_step' => (string) ($row['next_step'] ?? ''),
            'contact_updates' => $decode($row['contact_updates_json'] ?? null),
            'task_suggestions' => $decode($row['task_suggestions_json'] ?? null),
            'deal_stage_suggestion' => $decode($row['deal_stage_suggestion_json'] ?? null),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'evidence' => $decode($row['evidence_json'] ?? null),
        ], $approved, $approvedByUserId);
    }

    private function appendContactContext(
        int $workspaceId,
        array $call,
        array $analysis,
        int $actorUserId,
        string $policyMode
    ): bool {
        $contactId = (int) ($call['contact_id'] ?? 0);
        if ($contactId <= 0) {
            return false;
        }
        $bounded = static function ($values, int $limit = 6): array {
            return array_slice(array_values(array_filter(array_map(
                static fn($value): string => mb_substr(trim(is_array($value) ? (string) ($value['text'] ?? $value['value'] ?? '') : (string) $value), 0, 240),
                is_array($values) ? $values : []
            ))), 0, $limit);
        };
        $entry = [
            'record_id' => 'voice_call:' . (int) $call['id'],
            'source_type' => 'voice_call',
            'source_call_id' => (int) $call['id'],
            'observed_at' => (string) ($call['completed_at'] ?? $call['created_at'] ?? ''),
            'summary' => mb_substr(trim((string) ($analysis['summary'] ?? '')), 0, 900),
            'relationship_context' => mb_substr(trim((string) ($analysis['relationship_context'] ?? '')), 0, 900),
            'sentiment_inferred' => mb_substr(trim((string) ($analysis['sentiment'] ?? '')), 0, 40),
            'intent_inferred' => mb_substr(trim((string) ($analysis['intent'] ?? '')), 0, 120),
            'pains_inferred' => $bounded($analysis['pains'] ?? []),
            'goals_observed_or_inferred' => $bounded($analysis['goals'] ?? []),
            'objections_inferred' => $bounded($analysis['objections'] ?? []),
            'commitments_observed' => $bounded($analysis['commitments'] ?? []),
            'next_step' => mb_substr(trim((string) ($analysis['next_step'] ?? '')), 0, 500),
            'confidence' => max(0.0, min(1.0, (float) ($analysis['confidence'] ?? 0))),
            'policy_mode' => $policyMode,
            'applied_at' => date(DATE_ATOM),
            'applied_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ];

        Database::beginTransaction();
        try {
            $contact = Database::queryOne(
                'SELECT metadata_json, ai_context FROM contacts WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [$workspaceId, $contactId]
            );
            if (!$contact) {
                Database::commit();
                return false;
            }
            $metadata = $this->decodeObject($contact['metadata_json'] ?? null);
            $aiContext = $this->decodeObject($contact['ai_context'] ?? null);
            $metadata['voice_context'] = $this->upsertContextEntry((array) ($metadata['voice_context'] ?? []), $entry);
            $aiContext['voice_context'] = $this->upsertContextEntry((array) ($aiContext['voice_context'] ?? []), $entry);
            Database::execute(
                'UPDATE contacts SET metadata_json = ?, ai_context = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                [json_encode($metadata, JSON_UNESCAPED_SLASHES), json_encode($aiContext, JSON_UNESCAPED_SLASHES), $workspaceId, $contactId]
            );
            Database::commit();
            return true;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** @return array{applied:list<array<string,mixed>>,skipped:list<array<string,mixed>>} */
    private function createTasks(int $workspaceId, array $call, array $suggestions, int $approvedByUserId, string $policyMode): array
    {
        $ownerUserId = $this->workspaceOwnerUserId($workspaceId, $call);
        if ($ownerUserId <= 0) {
            return ['applied' => [], 'skipped' => [['type' => 'follow_up_tasks', 'reason' => 'workspace_owner_not_resolved']]];
        }
        $assignedTo = (int) ($call['agent_user_id'] ?? $call['contact_owner_user_id'] ?? 0);
        if ($assignedTo <= 0 || !Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
            [$workspaceId, $assignedTo]
        )) {
            $assignedTo = $ownerUserId;
        }
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $ownerUserId, 'owner');
        $applied = [];
        $skipped = [];
        try {
            foreach (array_slice($suggestions, 0, 5) as $index => $suggestion) {
                $payload = is_array($suggestion) ? $suggestion : ['text' => (string) $suggestion];
                $title = trim((string) ($payload['title'] ?? $payload['text'] ?? $payload['task'] ?? ''));
                if ($title === '') {
                    $skipped[] = ['type' => 'task', 'reason' => 'empty_task_suggestion', 'index' => $index];
                    continue;
                }
                $title = mb_substr($title, 0, 255);
                $dedupe = 'voice:' . $workspaceId . ':' . (int) $call['id'] . ':task:' . substr(hash('sha256', mb_strtolower($title)), 0, 24);
                try {
                    $taskId = (new Tasks())->create([
                        'title' => $title,
                        'description' => mb_substr(trim((string) ($payload['description'] ?? 'Suggested from voice call #' . (int) $call['id'] . '.')), 0, 4000),
                        'contact_id' => (int) $call['contact_id'],
                        'assigned_to' => $assignedTo,
                        'created_by' => $ownerUserId,
                        'actor_user_id' => $ownerUserId,
                        'status' => 'pending',
                        'priority' => in_array((string) ($payload['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], true) ? (string) $payload['priority'] : 'medium',
                        'due_date' => $payload['due_date'] ?? $payload['due'] ?? null,
                        'source_skill_key' => 'voice_call_center',
                        'source_plugin_key' => 'voice_call_center',
                        'source_surface' => 'voice_call_insight',
                        'source_capability_key' => 'voice.follow_up_tasks',
                        'source_run_id' => (string) $call['id'],
                        'origin_type' => 'ai',
                        'completion_mode' => $policyMode === 'automatic_safe' ? 'auto' : 'review',
                        'automation_dedupe_key' => $dedupe,
                        'metadata_json' => [
                            'voice_call_id' => (int) $call['id'],
                            'policy_mode' => $policyMode,
                            'approved_by_user_id' => $approvedByUserId > 0 ? $approvedByUserId : null,
                        ],
                    ]);
                    $applied[] = ['type' => 'task', 'id' => $taskId, 'title' => $title, 'policy_mode' => $policyMode];
                } catch (\Throwable $e) {
                    $skipped[] = ['type' => 'task', 'title' => $title, 'reason' => 'task_creation_failed', 'error' => mb_substr($e->getMessage(), 0, 240)];
                }
            }
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function workspaceOwnerUserId(int $workspaceId, array $call): int
    {
        $config = $this->configs->get($workspaceId, false);
        $acknowledgedBy = (int) ($config['compliance_acknowledged_by_user_id'] ?? 0);
        if ($acknowledgedBy > 0 && Database::queryOne(
            "SELECT id FROM workspace_memberships
             WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner') LIMIT 1",
            [$workspaceId, $acknowledgedBy]
        )) {
            return $acknowledgedBy;
        }
        $owner = Database::queryOne(
            "SELECT user_id FROM workspace_memberships
             WHERE workspace_id = ? AND membership_status = 'active' AND (is_owner = 1 OR role_slug = 'owner')
             ORDER BY is_owner DESC, id ASC LIMIT 1",
            [$workspaceId]
        );
        if ($owner) {
            return (int) $owner['user_id'];
        }
        return 0;
    }

    /** @return array<string,mixed> */
    private function skipFromDecision(string $type, array $decision): array
    {
        return [
            'type' => $type,
            'reason' => (string) ($decision['reason'] ?? 'policy_blocked'),
            'policy_mode' => (string) ($decision['mode'] ?? 'off'),
            'decision' => (string) ($decision['decision'] ?? 'block'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function upsertContextEntry(array $entries, array $entry): array
    {
        $callId = (int) $entry['source_call_id'];
        $entries = array_values(array_filter(
            $entries,
            static fn($existing): bool => !is_array($existing) || (int) ($existing['source_call_id'] ?? 0) !== $callId
        ));
        array_unshift($entries, $entry);
        return array_slice($entries, 0, 10);
    }

    /** @return array<string,mixed> */
    private function decodeObject($value): array
    {
        $decoded = $value ? json_decode((string) $value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string,mixed>> */
    private function uniqueActions(array $actions): array
    {
        $unique = [];
        foreach ($actions as $action) {
            $key = hash('sha256', (string) json_encode($action, JSON_UNESCAPED_SLASHES));
            $unique[$key] = $action;
        }
        return array_values($unique);
    }

    private function persistOutcome(int $workspaceId, int $callId, array $result): void
    {
        $existing = Database::queryOne(
            'SELECT applied_actions_json, skipped_actions_json FROM voice_call_insights WHERE workspace_id = ? AND call_id = ? LIMIT 1',
            [$workspaceId, $callId]
        ) ?: [];
        $decode = static function ($value): array {
            $decoded = $value ? json_decode((string) $value, true) : [];
            return is_array($decoded) ? $decoded : [];
        };
        $applied = $this->uniqueActions(array_merge($decode($existing['applied_actions_json'] ?? null), (array) $result['applied']));
        $skipped = $this->uniqueActions(array_merge($decode($existing['skipped_actions_json'] ?? null), (array) $result['skipped']));
        Database::execute(
            'UPDATE voice_call_insights
             SET applied_actions_json = ?, skipped_actions_json = ?, review_status = ?,
                 reviewed_by_user_id = CASE WHEN ? > 0 THEN ? ELSE reviewed_by_user_id END,
                 reviewed_at = CASE WHEN ? > 0 THEN NOW() ELSE reviewed_at END, updated_at = NOW()
             WHERE workspace_id = ? AND call_id = ?',
            [
                json_encode($applied, JSON_UNESCAPED_SLASHES),
                json_encode($skipped, JSON_UNESCAPED_SLASHES),
                (string) $result['review_status'],
                (int) ($result['approved_by_user_id'] ?? 0),
                (int) ($result['approved_by_user_id'] ?? 0),
                (int) ($result['approved_by_user_id'] ?? 0),
                $workspaceId,
                $callId,
            ]
        );
    }
}
