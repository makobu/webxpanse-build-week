<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Modules\Notes;
use CRM\Modules\Tasks;
use CRM\Modules\Deals;
use CRM\Modules\AINoteProcessor;

class MeetingNoteTakerService
{
    private const MODE_ORDER = [
        'suggest_only' => 1,
        'auto_safe' => 2,
        'full_auto' => 3,
    ];

    private ?int $workspaceId = null;

    public function __construct(
        private ?MeetingNotePayloadNormalizer $normalizer = null,
        private ?MeetingNoteTakerConfig $configModule = null,
        private ?AIService $ai = null,
        private ?AINoteProcessor $noteProcessor = null,
        private ?DealStageTransitionService $dealStageTransitions = null,
        private ?Contacts $contacts = null,
        private ?Notes $notes = null,
        private ?Tasks $tasks = null,
        private ?Deals $deals = null,
        ?int $workspaceId = null,
    ) {
        $this->normalizer = $this->normalizer ?? new MeetingNotePayloadNormalizer();
        $this->configModule = $this->configModule ?? new MeetingNoteTakerConfig();
        $this->ai = $this->ai ?? new AIService();
        $this->noteProcessor = $this->noteProcessor ?? new AINoteProcessor();
        $this->dealStageTransitions = $this->dealStageTransitions ?? new DealStageTransitionService();
        $this->contacts = $this->contacts ?? new Contacts();
        $this->notes = $this->notes ?? new Notes();
        $this->tasks = $this->tasks ?? new Tasks();
        $this->deals = $this->deals ?? new Deals();
        $this->workspaceId = $workspaceId;
    }

    public function setWorkspaceId(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function ingest(array $payload, ?int $actorUserId = null): array
    {
        $workspaceId = $this->workspaceId();
        $previousWorkspace = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $actorUserId);
        try {
            $config = $this->configModule->get($workspaceId);
            if (empty($config['enabled'])) {
                return [
                    'success' => false,
                    'run_id' => null,
                    'status' => 'blocked',
                    'matched_contact_id' => null,
                    'matched_deal_id' => null,
                    'confidence' => 0.0,
                    'applied_changes' => [],
                    'skipped_changes' => [],
                    'reasons' => ['meeting_note_taker_disabled'],
                    'extracted_actions' => [],
                    'review_required' => false,
                ];
            }

            $config = $this->applyAutoApplyModeCeiling($config, $payload['auto_apply_mode_ceiling'] ?? null);
            $normalized = $this->normalizer->normalize($payload);
            $dedupeKey = $this->buildDedupeKey($normalized);
            $existingRun = $this->findRunByDedupeKey($workspaceId, $dedupeKey);
            if ($existingRun) {
                if ((string) ($existingRun['apply_status'] ?? '') !== 'failed') {
                    return $this->responseFromExistingRun($existingRun);
                }
                $this->releaseFailedDedupeKey($existingRun);
            }

            $match = $this->resolveEntities($normalized, $workspaceId);
            $runId = $this->createRun($normalized, $match, $actorUserId, $workspaceId, $dedupeKey);
            $sourceOptions = $this->buildSourceOptions($normalized);

            $startedTransaction = false;
            try {
                $analysis = $this->analyze($normalized, $match, $config);
                $startedTransaction = !Database::getInstance()->inTransaction();
                if ($startedTransaction) {
                    Database::beginTransaction();
                }

                $applyResult = $this->applyActions($analysis, $match, $config, $actorUserId, $runId, $sourceOptions);
                $response = [
                    'success' => true,
                    'run_id' => $runId,
                    'status' => $applyResult['status'],
                    'matched_contact_id' => $match['contact_id'],
                    'matched_deal_id' => $match['deal_id'],
                    'confidence' => $analysis['confidence'],
                    'applied_changes' => $applyResult['applied'],
                    'skipped_changes' => $applyResult['skipped'],
                    'reasons' => $this->mergeReasons($match['reasons'] ?? [], $applyResult['reasons'] ?? []),
                    'extracted_actions' => $analysis,
                    'review_required' => !empty($applyResult['review_required']),
                ];
                $this->finalizeRun($runId, $normalized, $match, $analysis, $applyResult);
                if ($startedTransaction && Database::getInstance()->inTransaction()) {
                    Database::commit();
                }
                return $response;
            } catch (\Throwable $e) {
                if ($startedTransaction && Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
                $this->failRun($runId, $e->getMessage());
                throw $e;
            }
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($previousWorkspace);
        }
    }

    public function getRecentRuns(int $limit = 20): array
    {
        $workspaceId = $this->workspaceId();
        return Database::query(
            "SELECT r.*, c.first_name, c.last_name, c.email AS contact_email, d.title AS deal_title
             FROM meeting_note_taker_runs r
             LEFT JOIN contacts c ON c.id = r.matched_contact_id AND c.workspace_id = r.workspace_id
             LEFT JOIN deals d ON d.id = r.matched_deal_id AND d.workspace_id = r.workspace_id
             WHERE r.workspace_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT " . max(1, (int) $limit),
            [$workspaceId]
        );
    }

    private function resolveEntities(array $normalized, int $workspaceId): array
    {
        $contactId = !empty($normalized['contact_id']) ? (int) $normalized['contact_id'] : 0;
        $dealId = !empty($normalized['deal_id']) ? (int) $normalized['deal_id'] : 0;
        $status = 'unmatched';
        $reason = [];

        if ($contactId <= 0) {
            $emails = [];
            foreach ((array) ($normalized['attendees'] ?? []) as $attendee) {
                $email = strtolower(trim((string) ($attendee['email'] ?? '')));
                if ($email !== '') {
                    $emails[] = $email;
                }
            }
            $emails = array_values(array_unique($emails));

            if ($emails !== []) {
                $placeholders = implode(',', array_fill(0, count($emails), '?'));
                $matches = Database::query(
                    "SELECT id, email
                     FROM contacts
                     WHERE workspace_id = ?
                       AND LOWER(email) IN ({$placeholders})
                     ORDER BY id ASC",
                    array_merge([$workspaceId], $emails)
                );
                if (count($matches) === 1) {
                    $contactId = (int) $matches[0]['id'];
                    $status = 'matched';
                } elseif (count($matches) > 1) {
                    $status = 'ambiguous';
                    $reason[] = 'multiple_contact_matches';
                } else {
                    $reason[] = 'no_contact_match';
                }
            }
        } else {
            $contactExists = Database::queryOne(
                "SELECT id FROM contacts WHERE id = ? AND workspace_id = ? LIMIT 1",
                [$contactId, $workspaceId]
            );
            if ($contactExists) {
                $status = 'matched';
            } else {
                $contactId = 0;
                $status = 'unmatched';
                $reason[] = 'contact_outside_workspace';
            }
        }

        if ($dealId <= 0 && $contactId > 0) {
            $activeDeals = Database::query(
                "SELECT id, stage FROM deals
                 WHERE workspace_id = ?
                   AND contact_id = ?
                   AND stage NOT IN ('closed_won', 'closed_lost')
                 ORDER BY updated_at DESC, id DESC",
                [$workspaceId, $contactId]
            );
            if (count($activeDeals) === 1) {
                $dealId = (int) $activeDeals[0]['id'];
            } elseif (count($activeDeals) > 1) {
                $reason[] = 'multiple_active_deals';
            }
        } elseif ($dealId > 0) {
            $dealExists = Database::queryOne(
                "SELECT id, contact_id FROM deals WHERE id = ? AND workspace_id = ? LIMIT 1",
                [$dealId, $workspaceId]
            );
            if (!$dealExists) {
                $dealId = 0;
                $reason[] = 'deal_outside_workspace';
            }
        }

        if ($contactId > 0 && $dealId > 0) {
            $dealContact = Database::queryOne(
                "SELECT contact_id FROM deals WHERE id = ? AND workspace_id = ? LIMIT 1",
                [$dealId, $workspaceId]
            );
            if (!$dealContact || (int) ($dealContact['contact_id'] ?? 0) !== $contactId) {
                $dealId = 0;
                $reason[] = 'deal_contact_mismatch';
            }
        }

        return [
            'contact_id' => $contactId > 0 ? $contactId : null,
            'deal_id' => $dealId > 0 ? $dealId : null,
            'status' => $status,
            'reasons' => $reason,
        ];
    }

    private function analyze(array $normalized, array $match, array $config): array
    {
        $context = [
            'provider' => $normalized['provider'],
            'title' => $normalized['title'],
            'summary' => $normalized['summary'],
            'transcript' => $normalized['transcript'],
            'attendees' => $normalized['attendees'],
            'contact' => $match['contact_id'] ? $this->contacts->getById((int) $match['contact_id']) : null,
            'deal' => $match['deal_id'] ? $this->deals->getById((int) $match['deal_id']) : null,
            'allowed_contact_fields' => $config['allowed_contact_fields'] ?? [],
        ];

        $raw = '';
        try {
            $raw = $this->ai->process('meeting_note_analysis', [
                'text' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            $raw = '';
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && isset($decoded['summary'])) {
            return $this->normalizeAnalysis($decoded, false);
        }

        return $this->fallbackAnalysis($normalized, $match);
    }

    private function normalizeAnalysis(array $decoded, bool $fallback): array
    {
        $contactUpdates = [];
        foreach ((array) ($decoded['contact_updates'] ?? []) as $field => $payload) {
            if (is_array($payload)) {
                $contactUpdates[$field] = [
                    'value' => trim((string) ($payload['value'] ?? '')),
                    'confidence' => max(0.0, min(1.0, (float) ($payload['confidence'] ?? 0))),
                    'reason' => trim((string) ($payload['reason'] ?? '')),
                ];
            }
        }

        $actionItems = [];
        foreach ((array) ($decoded['action_items'] ?? []) as $item) {
            if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') {
                continue;
            }
            $actionItems[] = [
                'text' => trim((string) ($item['text'] ?? '')),
                'due' => trim((string) ($item['due'] ?? '')) ?: null,
                'assignee_hint' => trim((string) ($item['assignee_hint'] ?? '')) ?: null,
            ];
        }

        $dealStage = is_array($decoded['deal_stage'] ?? null) ? $decoded['deal_stage'] : [];

        return [
            'summary' => trim((string) ($decoded['summary'] ?? 'Meeting note captured.')),
            'relationship_context' => trim((string) ($decoded['relationship_context'] ?? '')),
            'next_step' => trim((string) ($decoded['next_step'] ?? '')),
            'contact_updates' => $contactUpdates,
            'action_items' => $actionItems,
            'deal_stage' => [
                'suggested_stage' => trim((string) ($dealStage['suggested_stage'] ?? '')) ?: null,
                'confidence' => max(0.0, min(1.0, (float) ($dealStage['confidence'] ?? 0))),
                'reason' => trim((string) ($dealStage['reason'] ?? '')),
            ],
            'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0.7))),
            'fallback' => $fallback,
        ];
    }

    private function fallbackAnalysis(array $normalized, array $match): array
    {
        $source = trim(($normalized['summary'] ?? '') . "\n\n" . ($normalized['transcript'] ?? ''));
        $actions = $this->noteProcessor->extractActionItems($source);
        $summary = trim((string) ($normalized['summary'] ?? ''));
        if ($summary === '') {
            $summary = trim(preg_replace('/\s+/', ' ', substr($normalized['transcript'] ?? '', 0, 500)));
        }
        if ($summary === '') {
            $summary = 'Meeting note captured.';
        }

        $dealStage = ['suggested_stage' => null, 'confidence' => 0.0, 'reason' => ''];
        if (!empty($match['deal_id'])) {
            $suggestion = (new DealAutomationAIStageSuggestion())->suggest([
                'current_stage' => (string) (($this->deals->getById((int) $match['deal_id'])['stage'] ?? 'prospecting')),
                'summary' => $summary,
                'transcript_excerpt' => substr((string) ($normalized['transcript'] ?? ''), 0, 1000),
            ]);
            $dealStage = [
                'suggested_stage' => $suggestion['suggested_stage'] ?? null,
                'confidence' => (float) ($suggestion['confidence'] ?? 0),
                'reason' => (string) ($suggestion['reasoning'] ?? ''),
            ];
        }

        return [
            'summary' => $summary,
            'relationship_context' => $summary,
            'next_step' => $actions[0]['text'] ?? '',
            'contact_updates' => [],
            'action_items' => $actions,
            'deal_stage' => $dealStage,
            'confidence' => 0.55,
            'fallback' => true,
        ];
    }

    private function applyActions(array $analysis, array $match, array $config, ?int $actorUserId, int $runId, array $sourceOptions = []): array
    {
        $applied = [];
        $skipped = [];
        $reasons = [];
        $status = 'skipped';
        $noteTitle = trim((string) ($sourceOptions['note_title'] ?? 'Meeting note taker summary'));
        $stageActorLabel = trim((string) ($sourceOptions['actor_label'] ?? 'Meeting note taker'));
        $taskSourceSurface = trim((string) ($sourceOptions['source_surface'] ?? 'meeting_note_taker'));

        $contactId = (int) ($match['contact_id'] ?? 0);
        $dealId = (int) ($match['deal_id'] ?? 0);
        $contactRecord = $contactId > 0 ? ($this->contacts->getById($contactId) ?? []) : [];
        $dealRecord = $dealId > 0 ? ($this->deals->getById($dealId) ?? []) : [];
        $ownerUserId = (int) ($actorUserId
            ?: ($dealRecord['assigned_to'] ?? 0)
            ?: ($contactRecord['assigned_to'] ?? 0)
            ?: ($dealRecord['created_by'] ?? 0)
            ?: 0);

        if ($contactId <= 0) {
            return [
                'status' => 'blocked',
                'applied' => [],
                'skipped' => ['contact_resolution'],
                'reasons' => array_merge(['contact_not_resolved'], (array) ($match['reasons'] ?? [])),
            ];
        }

        if ((string) ($config['auto_apply_mode'] ?? 'full_auto') === 'suggest_only') {
            return [
                'status' => 'review_required',
                'persisted_status' => 'skipped',
                'applied' => [],
                'skipped' => $this->buildSuggestOnlySkips($analysis, $dealId),
                'reasons' => ['suggest_only_review_required'],
                'review_required' => true,
            ];
        }

        $noteContent = $analysis['summary'];
        if (!empty($analysis['relationship_context'])) {
            $noteContent .= "\n\nRelationship context: " . $analysis['relationship_context'];
        }
        if (!empty($analysis['next_step'])) {
            $noteContent .= "\n\nNext step: " . $analysis['next_step'];
        }
        $contactNoteId = $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'title' => $noteTitle !== '' ? $noteTitle : 'Meeting note taker summary',
            'content' => $noteContent,
            'created_by' => $ownerUserId,
        ]);
        $applied[] = ['type' => 'contact_note', 'id' => $contactNoteId];
        $status = 'applied';

        if ($dealId > 0) {
            $dealNoteId = $this->notes->create([
                'entity_type' => 'deal',
                'entity_id' => $dealId,
                'title' => $noteTitle !== '' ? $noteTitle : 'Meeting note taker summary',
                'content' => $noteContent,
                'created_by' => $ownerUserId,
            ]);
            $applied[] = ['type' => 'deal_note', 'id' => $dealNoteId];
        }

        $contactContextSaved = $this->appendContactContext($contactId, [
            'run_id' => $runId,
            'summary' => $analysis['summary'],
            'relationship_context' => $analysis['relationship_context'],
            'next_step' => $analysis['next_step'],
            'captured_at' => date('c'),
        ], (int) ($config['max_context_entries'] ?? 10));
        if ($contactContextSaved) {
            $applied[] = ['type' => 'contact_context', 'contact_id' => $contactId];
        }

        foreach ((array) ($analysis['contact_updates'] ?? []) as $field => $payload) {
            if (!in_array($field, (array) ($config['allowed_contact_fields'] ?? []), true)) {
                $skipped[] = ['type' => 'contact_update', 'field' => $field, 'reason' => 'field_not_allowed'];
                continue;
            }
            if (($payload['confidence'] ?? 0) < (float) ($config['contact_update_min_confidence'] ?? 0.75)) {
                $skipped[] = ['type' => 'contact_update', 'field' => $field, 'reason' => 'low_confidence'];
                continue;
            }
            if (!empty($config['contact_updates_additive_only'])) {
                $contact = $this->contacts->getById($contactId) ?? [];
                if (trim((string) ($contact[$field] ?? '')) !== '') {
                    $skipped[] = ['type' => 'contact_update', 'field' => $field, 'reason' => 'additive_only_existing_value'];
                    continue;
                }
            }
            $this->contacts->update($contactId, [$field => $payload['value']]);
            $applied[] = ['type' => 'contact_update', 'field' => $field];
        }

        if (!empty($config['task_auto_create_enabled']) && in_array((string) ($config['auto_apply_mode'] ?? 'full_auto'), ['auto_safe', 'full_auto'], true)) {
            foreach ((array) ($analysis['action_items'] ?? []) as $item) {
                $actionFingerprint = hash('sha256', json_encode([
                    'run' => $sourceOptions['meeting_bot_run_id'] ?? null,
                    'text' => trim((string) ($item['text'] ?? '')),
                    'due' => $item['due'] ?? null,
                ], JSON_UNESCAPED_SLASHES));
                $taskId = $this->tasks->create([
                    'title' => $item['text'],
                    'description' => 'Created automatically from meeting transcript.',
                    'contact_id' => $contactId,
                    'created_by' => $ownerUserId,
                    'assigned_to' => $ownerUserId > 0 ? $ownerUserId : null,
                    'due_date' => $item['due'] ?? null,
                    'origin_type' => 'automation',
                    'completion_mode' => 'review',
                    'automation_dedupe_key' => 'meeting_note:' . $actionFingerprint,
                    'metadata_json' => [
                        'source_surface' => $taskSourceSurface !== '' ? $taskSourceSurface : 'meeting_note_taker',
                        'meeting_note_taker' => true,
                        'meeting_bot_run_id' => $sourceOptions['meeting_bot_run_id'] ?? null,
                        'assignee_hint' => $item['assignee_hint'] ?? null,
                        'linked_contact_id' => $contactId,
                        'linked_deal_id' => $dealId > 0 ? $dealId : null,
                        'action_item_fingerprint' => $actionFingerprint,
                    ],
                ]);
                $applied[] = ['type' => 'task', 'id' => $taskId];
            }
        } elseif (!empty($analysis['action_items'])) {
            $skipped[] = ['type' => 'task', 'reason' => 'task_auto_create_disabled'];
        }

        $dealStage = (array) ($analysis['deal_stage'] ?? []);
        if (
            $dealId > 0
            && !empty($config['deal_stage_auto_move_enabled'])
            && (($dealStage['confidence'] ?? 0) >= (float) ($config['deal_stage_min_confidence'] ?? 0.90))
            && !empty($dealStage['suggested_stage'])
            && (string) ($config['auto_apply_mode'] ?? 'full_auto') === 'full_auto'
        ) {
            try {
                $transition = $this->dealStageTransitions->transition($dealId, (string) $dealStage['suggested_stage'], [
                    'reason' => 'Meeting transcript indicated forward progress.',
                    'close_note' => trim((string) ($dealStage['reason'] ?? '')),
                    'note_title' => $noteTitle !== '' ? $noteTitle : 'Meeting note taker stage transition',
                    'created_by' => $ownerUserId,
                    'actor_label' => $stageActorLabel !== '' ? $stageActorLabel : 'Meeting note taker',
                ]);
                $applied[] = ['type' => 'deal_stage', 'deal_id' => $dealId, 'to_stage' => $transition['to_stage']];
            } catch (\Throwable $e) {
                $skipped[] = ['type' => 'deal_stage', 'reason' => $e->getMessage()];
                $reasons[] = 'deal_stage_move_blocked';
            }
        } elseif ($dealId > 0 && !empty($dealStage['suggested_stage'])) {
            $skipped[] = ['type' => 'deal_stage', 'reason' => 'confidence_or_policy_gate'];
        }

        if ($applied === []) {
            $status = 'skipped';
        } elseif ($skipped !== []) {
            $status = 'partial';
        }

        return [
            'status' => $status,
            'applied' => $applied,
            'skipped' => $skipped,
            'reasons' => $reasons,
        ];
    }

    private function buildDedupeKey(array $normalized): ?string
    {
        $raw = is_array($normalized['raw_payload'] ?? null) ? $normalized['raw_payload'] : [];
        $meetingBotRunId = !empty($raw['meeting_bot_run_id']) ? (int) $raw['meeting_bot_run_id'] : 0;
        if ($meetingBotRunId > 0) {
            return 'meeting_bot_run:' . $meetingBotRunId;
        }

        $provider = strtolower(trim((string) ($normalized['provider'] ?? 'generic')));
        $externalMeetingId = trim((string) ($normalized['external_meeting_id'] ?? ''));
        if ($provider === '' || $externalMeetingId === '') {
            return null;
        }

        $provider = preg_replace('/[^a-z0-9_.:-]+/', '_', $provider) ?: 'generic';
        return 'provider:' . $provider . ':external:' . hash('sha256', $externalMeetingId);
    }

    private function findRunByDedupeKey(int $workspaceId, ?string $dedupeKey): ?array
    {
        if ($dedupeKey === null || !Database::columnExists('meeting_note_taker_runs', 'dedupe_key')) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM meeting_note_taker_runs
             WHERE workspace_id = ?
               AND dedupe_key = ?
             ORDER BY id ASC
             LIMIT 1",
            [$workspaceId, $dedupeKey]
        );
    }

    private function releaseFailedDedupeKey(array $run): void
    {
        if (!Database::columnExists('meeting_note_taker_runs', 'dedupe_key')) {
            return;
        }

        $reasons = $this->decodeJsonArray($run['reasons_json'] ?? null);
        $reasons[] = 'retry_superseded_failed_run';
        Database::execute(
            "UPDATE meeting_note_taker_runs
             SET dedupe_key = NULL, reasons_json = ?, updated_at = NOW()
             WHERE id = ? AND workspace_id = ? AND apply_status = 'failed'",
            [
                json_encode(array_values(array_unique(array_map('strval', $reasons)))),
                (int) ($run['id'] ?? 0),
                (int) ($run['workspace_id'] ?? $this->workspaceId()),
            ]
        );
    }

    private function responseFromExistingRun(array $run): array
    {
        $reasons = $this->decodeJsonArray($run['reasons_json'] ?? null);
        $status = (string) ($run['apply_status'] ?? 'skipped');
        $reviewRequired = in_array('suggest_only_review_required', $reasons, true);
        if ($reviewRequired && $status === 'skipped') {
            $status = 'review_required';
        }

        return [
            'success' => true,
            'duplicate' => true,
            'run_id' => (int) ($run['id'] ?? 0),
            'status' => $status,
            'matched_contact_id' => !empty($run['matched_contact_id']) ? (int) $run['matched_contact_id'] : null,
            'matched_deal_id' => !empty($run['matched_deal_id']) ? (int) $run['matched_deal_id'] : null,
            'confidence' => (float) ($run['confidence'] ?? 0),
            'applied_changes' => $this->decodeJsonArray($run['applied_actions_json'] ?? null),
            'skipped_changes' => $this->decodeJsonArray($run['skipped_actions_json'] ?? null),
            'reasons' => $reasons,
            'extracted_actions' => $this->decodeJsonArray($run['extracted_actions_json'] ?? null),
            'review_required' => $reviewRequired,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonArray(mixed $json): array
    {
        $decoded = json_decode((string) ($json ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function mergeReasons(array ...$reasonSets): array
    {
        $merged = [];
        foreach ($reasonSets as $reasons) {
            foreach ($reasons as $reason) {
                $reason = trim((string) $reason);
                if ($reason !== '') {
                    $merged[] = $reason;
                }
            }
        }

        return array_values(array_unique($merged));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildSuggestOnlySkips(array $analysis, int $dealId): array
    {
        $skipped = [
            ['type' => 'contact_note', 'reason' => 'suggest_only_review_required'],
            ['type' => 'contact_context', 'reason' => 'suggest_only_review_required'],
        ];

        if ($dealId > 0) {
            $skipped[] = ['type' => 'deal_note', 'reason' => 'suggest_only_review_required'];
        }

        foreach (array_keys((array) ($analysis['contact_updates'] ?? [])) as $field) {
            $skipped[] = [
                'type' => 'contact_update',
                'field' => (string) $field,
                'reason' => 'suggest_only_review_required',
            ];
        }

        foreach ((array) ($analysis['action_items'] ?? []) as $item) {
            $skipped[] = [
                'type' => 'task',
                'title' => (string) ($item['text'] ?? ''),
                'reason' => 'suggest_only_review_required',
            ];
        }

        $dealStage = (array) ($analysis['deal_stage'] ?? []);
        if ($dealId > 0 && !empty($dealStage['suggested_stage'])) {
            $skipped[] = [
                'type' => 'deal_stage',
                'to_stage' => (string) $dealStage['suggested_stage'],
                'reason' => 'suggest_only_review_required',
            ];
        }

        return $skipped;
    }

    private function createRun(array $normalized, array $match, ?int $actorUserId, int $workspaceId, ?string $dedupeKey = null): int
    {
        $fields = [
            'workspace_id',
            'provider',
            'external_meeting_id',
            'title',
            'organizer_email',
            'started_at',
            'ended_at',
            'transcript_text',
            'summary_text',
            'normalized_payload_json',
            'meeting_bot_run_id',
            'matched_contact_id',
            'matched_deal_id',
            'entity_match_status',
            'extraction_status',
            'apply_status',
            'confidence',
            'reasons_json',
            'created_by',
        ];
        $values = [
            $workspaceId,
            (string) ($normalized['provider'] ?? 'generic'),
            $normalized['external_meeting_id'] ?? null,
            $normalized['title'] ?? null,
            (string) (($normalized['organizer']['email'] ?? '') ?: null),
            $normalized['started_at'] ?? null,
            $normalized['ended_at'] ?? null,
            $normalized['transcript'] ?? null,
            $normalized['summary'] ?? null,
            json_encode($normalized),
            !empty($normalized['raw_payload']['meeting_bot_run_id']) ? (int) $normalized['raw_payload']['meeting_bot_run_id'] : null,
            $match['contact_id'] ?? null,
            $match['deal_id'] ?? null,
            (string) ($match['status'] ?? 'unmatched'),
            'pending',
            'skipped',
            0,
            json_encode($match['reasons'] ?? []),
            $actorUserId,
        ];

        if ($dedupeKey !== null && Database::columnExists('meeting_note_taker_runs', 'dedupe_key')) {
            array_splice($fields, 3, 0, ['dedupe_key']);
            array_splice($values, 3, 0, [$dedupeKey]);
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        Database::execute(
            "INSERT INTO meeting_note_taker_runs (" . implode(', ', $fields) . ") VALUES ({$placeholders})",
            $values
        );
        return (int) Database::lastInsertId();
    }

    private function finalizeRun(int $runId, array $normalized, array $match, array $analysis, array $applyResult): void
    {
        Database::execute(
            "UPDATE meeting_note_taker_runs SET
                extraction_status = ?, apply_status = ?, confidence = ?, extracted_actions_json = ?,
                applied_actions_json = ?, skipped_actions_json = ?, reasons_json = ?, updated_at = NOW()
             WHERE id = ? AND workspace_id = ?",
            [
                !empty($analysis['fallback']) ? 'fallback' : 'completed',
                (string) ($applyResult['persisted_status'] ?? $applyResult['status']),
                (float) ($analysis['confidence'] ?? 0),
                json_encode($analysis),
                json_encode($applyResult['applied'] ?? []),
                json_encode($applyResult['skipped'] ?? []),
                json_encode(array_merge((array) ($match['reasons'] ?? []), (array) ($applyResult['reasons'] ?? []))),
                $runId,
                $this->workspaceId(),
            ]
        );
    }

    private function failRun(int $runId, string $message): void
    {
        Database::execute(
            "UPDATE meeting_note_taker_runs
             SET extraction_status = 'failed', apply_status = 'failed', reasons_json = ?, updated_at = NOW()
             WHERE id = ? AND workspace_id = ?",
            [json_encode([$message]), $runId, $this->workspaceId()]
        );
    }

    private function appendContactContext(int $contactId, array $entry, int $maxEntries): bool
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact || !array_key_exists('metadata_json', $contact)) {
            return false;
        }

        $metadata = json_decode((string) ($contact['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $contextEntries = is_array($metadata['meeting_context'] ?? null) ? $metadata['meeting_context'] : [];
        array_unshift($contextEntries, $entry);
        $metadata['meeting_context'] = array_slice($contextEntries, 0, max(1, $maxEntries));
        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE id = ? AND workspace_id = ?",
            [json_encode($metadata), $contactId, $this->workspaceId()]
        );
        return true;
    }

    private function workspaceId(): int
    {
        if ($this->workspaceId !== null && $this->workspaceId > 0) {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId($this->workspaceId);
        }

        try {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function applyAutoApplyModeCeiling(array $config, mixed $ceiling): array
    {
        $currentMode = $this->normalizeAutoApplyMode((string) ($config['auto_apply_mode'] ?? 'full_auto'));
        if ($currentMode === '') {
            $currentMode = 'full_auto';
        }
        $ceilingMode = $this->normalizeAutoApplyMode((string) $ceiling);
        if ($ceilingMode === '') {
            $config['auto_apply_mode'] = $currentMode;
            return $config;
        }

        $config['auto_apply_mode'] = self::MODE_ORDER[$ceilingMode] < self::MODE_ORDER[$currentMode]
            ? $ceilingMode
            : $currentMode;
        return $config;
    }

    private function normalizeAutoApplyMode(string $mode): string
    {
        $mode = trim($mode);
        return array_key_exists($mode, self::MODE_ORDER) ? $mode : '';
    }

    private function buildSourceOptions(array $normalized): array
    {
        $raw = is_array($normalized['raw_payload'] ?? null) ? $normalized['raw_payload'] : [];

        return [
            'note_title' => trim((string) ($raw['meeting_note_title'] ?? (($raw['meeting_bot_display_name'] ?? '') !== '' ? ($raw['meeting_bot_display_name'] . ' summary') : 'Meeting note taker summary'))),
            'actor_label' => trim((string) ($raw['meeting_bot_display_name'] ?? 'Meeting note taker')),
            'source_surface' => trim((string) ($raw['source_surface'] ?? 'meeting_note_taker')),
            'meeting_bot_run_id' => !empty($raw['meeting_bot_run_id']) ? (int) $raw['meeting_bot_run_id'] : null,
        ];
    }
}
