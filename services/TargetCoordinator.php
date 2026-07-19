<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\TargetReminders;

class TargetCoordinator
{
    private WorkspaceTargetAutomationSettingsService $settings;
    private TargetAutomationDecisionService $decisions;
    private CoreEntityLifecycleEventService $events;

    public function __construct(
        ?WorkspaceTargetAutomationSettingsService $settings = null,
        ?TargetAutomationDecisionService $decisions = null
    ) {
        $this->settings = $settings ?? new WorkspaceTargetAutomationSettingsService();
        $this->decisions = $decisions ?? new TargetAutomationDecisionService();
        $this->events = new CoreEntityLifecycleEventService();
    }

    public function completeManually(int $targetId, int $workspaceId, int $actorUserId, array $options = []): bool
    {
        $target = $this->target($targetId, $workspaceId);
        if (!$target || (string) $target['status'] === 'cancelled') {
            return false;
        }
        return $this->transition($target, [
            'status' => 'completed',
            'current_value' => (float) $target['target_value'],
            'completion_source' => 'manual',
            'completed_by_user_id' => $actorUserId ?: null,
            'completed_at' => date('Y-m-d H:i:s'),
            'reopened_at' => null,
            'reopened_by_user_id' => null,
        ], [
            'actor_type' => 'user',
            'actor_user_id' => $actorUserId,
            'decision_source' => (string) ($options['decision_source'] ?? 'manual'),
            'explanation' => trim((string) ($options['explanation'] ?? '')) ?: 'Target completed by a user.',
            'expected_version' => $options['expected_version'] ?? null,
        ]);
    }

    public function updateProgress(int $targetId, int $workspaceId, float $requestedTotal, int $actorUserId, ?int $expectedVersion = null): bool
    {
        if ($requestedTotal < 0) {
            throw new \InvalidArgumentException('Progress cannot be negative.');
        }
        $target = $this->target($targetId, $workspaceId);
        if (!$target || (string) $target['status'] !== 'active') {
            throw new \RuntimeException('Only active targets can be updated.');
        }
        $mode = (string) ($target['progress_mode'] ?? 'manual');
        if ($mode === 'auto_rollup') {
            throw new \RuntimeException('Automatic targets are updated from their source records.');
        }
        $changes = ['current_value' => round($requestedTotal, 2)];
        if ($mode === 'hybrid') {
            $enriched = (new TargetIntelligenceService())->enrichTarget($target, false);
            $rollup = (float) ($enriched['measurement']['rollup_value'] ?? 0);
            $changes['manual_adjustment_value'] = round($requestedTotal - $rollup, 2);
        }
        if ($requestedTotal >= (float) $target['target_value']) {
            $changes += [
                'status' => 'completed', 'completion_source' => 'manual',
                'completed_by_user_id' => $actorUserId ?: null, 'completed_at' => date('Y-m-d H:i:s'),
            ];
        }
        return $this->transition($target, $changes, [
            'actor_type' => 'user', 'actor_user_id' => $actorUserId, 'decision_source' => 'manual',
            'explanation' => 'Target progress set explicitly by a user.', 'expected_version' => $expectedVersion,
        ]);
    }

    public function evaluateMeasuredTarget(int $targetId, int $workspaceId, array $enriched, int $actorUserId = 0): array
    {
        $target = $this->target($targetId, $workspaceId);
        if (!$target || (string) $target['status'] !== 'active') {
            return ['decision' => 'no_change'];
        }
        $measurement = (array) ($enriched['measurement'] ?? []);
        $target['current_value'] = (float) ($enriched['current_value'] ?? $target['current_value'] ?? 0);
        $settings = $this->settings->get($workspaceId);
        $requiresAi = (string) ($target['origin_type'] ?? 'manual') === 'ai';
        $decision = $this->decisions->decide($target, $measurement, $settings, $requiresAi);

        if ($decision['decision'] === 'complete'
            && (string) ($target['automation_mode'] ?? 'manual') === 'auto'
            && (string) ($settings['mode'] ?? 'review') === 'full_auto'
            && !empty($settings['allow_ai_completion'])) {
            if ($this->fingerprintsRejected($workspaceId, $targetId, (array) $decision['evidence_fingerprints'])) {
                $decision['decision'] = 'keep_open';
                $decision['conflicts'][] = 'rejected_completion_evidence';
                return $decision;
            }
            $this->transition($target, [
                'status' => 'completed',
                'current_value' => (float) $target['current_value'],
                'completion_source' => $requiresAi ? 'ai_review' : 'rollup',
                'completed_by_user_id' => null,
                'completed_at' => date('Y-m-d H:i:s'),
            ], [
                'actor_type' => $requiresAi ? 'clarity' : 'automation',
                'actor_user_id' => $actorUserId,
                'decision_source' => $requiresAi ? 'ai_review' : 'rollup',
                'confidence' => (float) $decision['confidence'],
                'fingerprints' => (array) $decision['evidence_fingerprints'],
                'explanation' => (string) $decision['explanation'],
            ]);
            $this->setEvidenceState($workspaceId, $targetId, (array) $decision['evidence_fingerprints'], 'accepted', $actorUserId);
            return $decision;
        }
        if (in_array($decision['decision'], ['complete', 'review'], true)) {
            $this->persistProposal($target, 'complete', [], $decision);
            $decision['decision'] = 'review';
        }
        if (!empty($target['target_date']) && strtotime((string) $target['target_date']) < strtotime(date('Y-m-d'))
            && (float) $target['current_value'] < (float) $target['target_value']) {
            $this->transition($target, ['status' => 'missed', 'completed_at' => null], [
                'actor_type' => 'system', 'actor_user_id' => 0, 'decision_source' => 'missed',
                'explanation' => 'The target deadline passed before the configured threshold was reached.',
            ]);
        }
        return $decision;
    }

    public function reopen(int $targetId, int $workspaceId, int $actorUserId, string $reason, ?int $expectedVersion = null): bool
    {
        $target = $this->target($targetId, $workspaceId);
        if (!$target || (string) $target['status'] !== 'completed') {
            return false;
        }
        $accepted = Database::query(
            "SELECT evidence_fingerprint FROM target_evidence WHERE workspace_id=? AND target_id=? AND decision_state='accepted'",
            [$workspaceId, $targetId]
        );
        $fingerprints = array_map(static fn(array $row): string => (string) $row['evidence_fingerprint'], $accepted);
        $changed = $this->transition($target, [
            'status' => 'active', 'completed_at' => null, 'completion_source' => null, 'completed_by_user_id' => null,
            'reopened_at' => date('Y-m-d H:i:s'), 'reopened_by_user_id' => $actorUserId ?: null,
        ], [
            'actor_type' => 'user', 'actor_user_id' => $actorUserId, 'decision_source' => 'reopen',
            'fingerprints' => $fingerprints, 'explanation' => trim($reason) ?: 'Target reopened by a user.',
            'expected_version' => $expectedVersion,
        ]);
        if ($changed) {
            $this->setEvidenceState($workspaceId, $targetId, $fingerprints, 'rejected', $actorUserId);
            $fresh = $this->target($targetId, $workspaceId);
            if ($fresh) {
                (new TargetReminders())->scheduleInitialReminders($targetId, (string) $fresh['reminder_frequency'], (string) $fresh['target_date'], $fresh['custom_reminder_days'] !== null ? (int) $fresh['custom_reminder_days'] : null, $workspaceId);
            }
        }
        return $changed;
    }

    public function setMode(int $targetId, int $workspaceId, string $mode, int $actorUserId, ?int $expectedVersion = null): bool
    {
        if (!in_array($mode, ['manual', 'review', 'auto'], true)) {
            throw new \InvalidArgumentException('Invalid target automation mode.');
        }
        $target = $this->target($targetId, $workspaceId);
        if (!$target) {
            return false;
        }
        $settings = $this->settings->get($workspaceId);
        if ($mode === 'auto' && (string) ($target['origin_type'] ?? 'manual') === 'manual' && empty($settings['allow_user_target_opt_in'])) {
            throw new \RuntimeException('This workspace does not allow automatic management of user-created targets.');
        }
        return $this->transition($target, ['automation_mode' => $mode], [
            'actor_type' => 'user', 'actor_user_id' => $actorUserId, 'decision_source' => 'manual',
            'explanation' => 'Target automation mode changed to ' . $mode . '.', 'expected_version' => $expectedVersion,
        ]);
    }

    public function changeStatus(int $targetId, int $workspaceId, string $status, int $actorUserId, array $options = []): bool
    {
        if (!in_array($status, ['active', 'completed', 'cancelled', 'missed'], true)) {
            throw new \InvalidArgumentException('Invalid target status.');
        }
        if ($status === 'completed') {
            return $this->completeManually($targetId, $workspaceId, $actorUserId, $options);
        }
        if ($status === 'active') {
            $target = $this->target($targetId, $workspaceId);
            if ($target && (string) $target['status'] === 'completed') {
                return $this->reopen($targetId, $workspaceId, $actorUserId, (string) ($options['reason'] ?? 'Target reopened through a compatible status update.'), $options['expected_version'] ?? null);
            }
        }
        $target = $this->target($targetId, $workspaceId);
        if (!$target) {
            return false;
        }
        return $this->transition($target, ['status' => $status, 'completed_at' => null, 'completion_source' => null, 'completed_by_user_id' => null], [
            'actor_type' => 'user', 'actor_user_id' => $actorUserId,
            'decision_source' => $status === 'missed' ? 'missed' : ($status === 'cancelled' ? 'cancel' : 'manual'),
            'explanation' => (string) ($options['reason'] ?? 'Target status changed by a user.'),
            'expected_version' => $options['expected_version'] ?? null,
        ]);
    }

    public function approveProposal(int $proposalId, int $workspaceId, int $actorUserId): bool
    {
        $proposal = Database::queryOne("SELECT * FROM target_automation_proposals WHERE workspace_id=? AND id=? AND decision_state='pending' FOR UPDATE", [$workspaceId, $proposalId]);
        if (!$proposal) {
            return false;
        }
        if ((string) $proposal['proposal_type'] === 'create' && empty($proposal['target_id'])) {
            $data = $this->decode($proposal['proposed_changes_json'] ?? null);
            $dedupe = trim((string) ($data['automation_dedupe_key'] ?? ''));
            unset($data['automation_dedupe_key']);
            $targetId = (new TargetCreationPolicy())->createAutomated($data, [
                'origin_type' => 'ai', 'automation_mode' => 'review', 'surface' => 'ai_proposal',
                'run_id' => (string) ($proposal['source_run_id'] ?? ''),
                'dedupe_key' => $dedupe ?: 'ai-proposal:' . (int) $proposal['id'],
            ]);
            Database::execute("UPDATE target_automation_proposals SET target_id=?,decision_state='applied',reviewed_by_user_id=?,reviewed_at=NOW() WHERE workspace_id=? AND id=?", [$targetId, $actorUserId ?: null, $workspaceId, $proposalId]);
            return true;
        }
        if (empty($proposal['target_id'])) {
            return false;
        }
        $target = $this->target((int) $proposal['target_id'], $workspaceId);
        if (!$target) {
            return false;
        }
        $changes = $this->decode($proposal['proposed_changes_json'] ?? null);
        if ((string) $proposal['proposal_type'] === 'complete') {
            $changes += ['status' => 'completed', 'current_value' => max((float) $target['current_value'], (float) $target['target_value']),
                'completion_source' => 'ai_review', 'completed_by_user_id' => $actorUserId ?: null, 'completed_at' => date('Y-m-d H:i:s')];
        }
        $changed = $this->transition($target, $changes, [
            'actor_type' => 'user', 'actor_user_id' => $actorUserId, 'decision_source' => 'ai_review',
            'confidence' => $proposal['confidence_score'], 'fingerprints' => $this->decode($proposal['evidence_fingerprints_json'] ?? null),
            'proposal_id' => $proposalId, 'explanation' => (string) ($proposal['explanation'] ?? 'Target automation proposal approved.'),
        ]);
        if ($changed) {
            Database::execute("UPDATE target_automation_proposals SET decision_state='applied', reviewed_by_user_id=?, reviewed_at=NOW() WHERE workspace_id=? AND id=?", [$actorUserId ?: null, $workspaceId, $proposalId]);
        }
        return $changed;
    }

    public function rejectProposal(int $proposalId, int $workspaceId, int $actorUserId): bool
    {
        return Database::execute("UPDATE target_automation_proposals SET decision_state='rejected', reviewed_by_user_id=?, reviewed_at=NOW() WHERE workspace_id=? AND id=? AND decision_state='pending'", [$actorUserId ?: null, $workspaceId, $proposalId]) > 0;
    }

    public function payload(array $target, bool $canEdit = false): array
    {
        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        $targetId = (int) ($target['id'] ?? 0);
        $settings = $this->settings->get($workspaceId);
        $evidence = Database::tableExists('target_evidence') ? Database::query(
            'SELECT collector_key,source_entity_type,source_entity_id,observed_at,contribution_value,currency_code,evidence_payload_json,evidence_fingerprint,decision_state FROM target_evidence WHERE workspace_id=? AND target_id=? ORDER BY observed_at DESC,id DESC LIMIT 20',
            [$workspaceId, $targetId]
        ) : [];
        $proposal = Database::tableExists('target_automation_proposals') ? Database::queryOne(
            "SELECT * FROM target_automation_proposals WHERE workspace_id=? AND target_id=? AND decision_state='pending' ORDER BY id DESC LIMIT 1",
            [$workspaceId, $targetId]
        ) : null;
        $milestones = Database::tableExists('target_milestones') ? Database::query(
            'SELECT id,title,target_value,current_value,due_date,status,sort_order,updated_at FROM target_milestones WHERE workspace_id=? AND target_id=? ORDER BY sort_order ASC,id ASC',
            [$workspaceId, $targetId]
        ) : [];
        $transitions = Database::tableExists('target_state_transitions') ? Database::query(
            'SELECT from_status,to_status,previous_value,new_value,actor_type,actor_user_id,decision_source,confidence_score,explanation,created_at FROM target_state_transitions WHERE workspace_id=? AND target_id=? ORDER BY id DESC LIMIT 20',
            [$workspaceId, $targetId]
        ) : [];
        $completed = (string) ($target['status'] ?? '') === 'completed';
        $reopened = !empty($target['reopened_at']);
        return [
            'source' => [
                'origin_type' => (string) ($target['origin_type'] ?? 'manual'), 'surface' => $target['source_surface'] ?? null,
                'skill_key' => $target['source_skill_key'] ?? null, 'plugin_key' => $target['source_plugin_key'] ?? null,
                'run_id' => $target['source_run_id'] ?? null,
            ],
            'automation' => [
                'target_mode' => (string) ($target['automation_mode'] ?? 'manual'), 'workspace_mode' => (string) $settings['mode'],
                'confidence' => $proposal ? (float) ($proposal['confidence_score'] ?? 0) : null,
                'risk' => $proposal['risk_level'] ?? null, 'proposal' => $proposal ? $this->proposalPayload($proposal) : null,
                'can_enable_auto' => $canEdit && !empty($settings['allow_user_target_opt_in']),
            ],
            'completion' => [
                'state' => $completed ? 'completed' : ($reopened ? 'reopened' : ($proposal ? 'recommended' : ($evidence ? 'evidence_found' : 'open'))),
                'source' => $target['completion_source'] ?? null,
                'completed_by' => $completed ? (!empty($target['completed_by_user_id']) ? 'user' : 'clarity') : null,
                'explanation' => (string) ($proposal['explanation'] ?? ''),
                'accepted_evidence' => array_values(array_filter(array_map(fn(array $row): array => $this->evidencePayload($row), $evidence), static fn(array $row): bool => $row['decision_state'] === 'accepted')),
                'can_evaluate' => $canEdit && !$completed, 'can_complete' => $canEdit && !$completed, 'can_reopen' => $canEdit && $completed,
            ],
            'evidence' => array_map(fn(array $row): array => $this->evidencePayload($row), $evidence),
            'milestones' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'title' => (string) $row['title'], 'target_value' => (float) $row['target_value'],
                'current_value' => (float) $row['current_value'], 'due_date' => $row['due_date'] ?: null,
                'status' => (string) $row['status'], 'sort_order' => (int) $row['sort_order'], 'updated_at' => (string) $row['updated_at'],
            ], $milestones),
            'transitions' => array_map(static fn(array $row): array => [
                'from_status' => (string) $row['from_status'], 'to_status' => (string) $row['to_status'],
                'previous_value' => (float) $row['previous_value'], 'new_value' => (float) $row['new_value'],
                'actor_type' => (string) $row['actor_type'], 'actor_user_id' => !empty($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
                'decision_source' => (string) $row['decision_source'], 'confidence' => isset($row['confidence_score']) ? (float) $row['confidence_score'] : null,
                'explanation' => (string) ($row['explanation'] ?? ''), 'created_at' => (string) $row['created_at'],
            ], $transitions),
        ];
    }

    private function transition(array $target, array $changes, array $context): bool
    {
        $workspaceId = (int) $target['workspace_id'];
        $targetId = (int) $target['id'];
        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $locked = Database::queryOne('SELECT * FROM targets WHERE workspace_id=? AND id=? FOR UPDATE', [$workspaceId, $targetId]);
            if (!$locked) {
                throw new \RuntimeException('Target not found.');
            }
            if (isset($context['expected_version']) && $context['expected_version'] !== null && (int) $context['expected_version'] !== (int) $locked['state_version']) {
                throw new TargetStateConflictException('The target changed since it was loaded.', $locked);
            }
            $allowed = ['status','current_value','manual_adjustment_value','automation_mode','completion_source','completed_by_user_id','completed_at','reopened_at','reopened_by_user_id'];
            $sets = [];
            $params = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $changes)) {
                    $sets[] = "{$field}=?";
                    $params[] = $changes[$field];
                }
            }
            if ($sets === []) {
                return false;
            }
            $sets[] = 'state_version=state_version+1';
            $params[] = $workspaceId;
            $params[] = $targetId;
            if (Database::execute('UPDATE targets SET ' . implode(',', $sets) . ' WHERE workspace_id=? AND id=?', $params) !== 1) {
                throw new \RuntimeException('Target transition did not update exactly one row.');
            }
            $newStatus = (string) ($changes['status'] ?? $locked['status']);
            $newValue = (float) ($changes['current_value'] ?? $locked['current_value']);
            $fingerprints = array_values(array_unique(array_filter(array_map('strval', (array) ($context['fingerprints'] ?? [])))));
            $key = hash('sha256', implode('|', [$workspaceId, $targetId, (int) $locked['state_version'], (string) $locked['status'], $newStatus,
                (string) $locked['current_value'], $newValue, (string) ($context['decision_source'] ?? 'manual'), implode(',', $fingerprints)]));
            Database::execute(
                "INSERT INTO target_state_transitions
                    (workspace_id,target_id,from_status,to_status,previous_value,new_value,actor_type,actor_user_id,decision_source,
                     confidence_score,evidence_fingerprints_json,proposal_id,explanation,transition_key)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$workspaceId,$targetId,(string) $locked['status'],$newStatus,(float) $locked['current_value'],$newValue,
                 (string) ($context['actor_type'] ?? 'system'),(int) ($context['actor_user_id'] ?? 0) ?: null,
                 (string) ($context['decision_source'] ?? 'manual'),$context['confidence'] ?? null,
                 json_encode($fingerprints, JSON_UNESCAPED_SLASHES),$context['proposal_id'] ?? null,
                 (string) ($context['explanation'] ?? ''),$key]
            );
            if (in_array($newStatus, ['completed','cancelled','missed'], true)) {
                (new TargetReminders())->cancelPendingReminders($targetId, $workspaceId);
            }
            if ($started) {
                Database::commit();
            }
            $fresh = $this->target($targetId, $workspaceId) ?? $target;
            $event = $newStatus !== (string) $locked['status'] ? 'target.' . ($newStatus === 'active' ? 'reopened' : $newStatus) : 'target.progress_updated';
            $this->events->emit($event, 'target', $targetId, $fresh, [
                'workspace_id' => $workspaceId, 'actor_user_id' => (int) ($context['actor_user_id'] ?? 0),
                'changes' => $changes, 'decision_source' => (string) ($context['decision_source'] ?? 'manual'),
            ]);
            return true;
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function persistProposal(array $target, string $type, array $changes, array $decision): int
    {
        $fingerprints = (array) ($decision['evidence_fingerprints'] ?? []);
        $existing = Database::queryOne(
            "SELECT id FROM target_automation_proposals WHERE workspace_id=? AND target_id=? AND proposal_type=? AND decision_state='pending' AND evidence_fingerprints_json=? LIMIT 1",
            [(int) $target['workspace_id'], (int) $target['id'], $type, json_encode($fingerprints, JSON_UNESCAPED_SLASHES)]
        );
        if ($existing) {
            return (int) $existing['id'];
        }
        Database::execute(
            "INSERT INTO target_automation_proposals
                (workspace_id,target_id,proposal_type,proposed_changes_json,confidence_score,risk_level,explanation,
                 evidence_fingerprints_json,missing_evidence_json,conflicts_json,provider_key,source_run_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [(int) $target['workspace_id'],(int) $target['id'],$type,json_encode($changes, JSON_UNESCAPED_SLASHES),
             $decision['confidence'] ?? null,(string) ($decision['risk'] ?? 'medium'),(string) ($decision['explanation'] ?? ''),
             json_encode($fingerprints, JSON_UNESCAPED_SLASHES),json_encode((array) ($decision['missing_evidence'] ?? []), JSON_UNESCAPED_SLASHES),
             json_encode((array) ($decision['conflicts'] ?? []), JSON_UNESCAPED_SLASHES),(string) ($decision['judge_source'] ?? ''),
             $target['source_run_id'] ?? null]
        );
        return (int) Database::lastInsertId();
    }

    private function setEvidenceState(int $workspaceId, int $targetId, array $fingerprints, string $state, int $actorUserId): void
    {
        foreach (array_unique(array_filter(array_map('strval', $fingerprints))) as $fingerprint) {
            Database::execute('UPDATE target_evidence SET decision_state=?,decided_at=NOW(),decided_by_user_id=? WHERE workspace_id=? AND target_id=? AND evidence_fingerprint=?',
                [$state, $actorUserId ?: null, $workspaceId, $targetId, $fingerprint]);
        }
    }

    private function fingerprintsRejected(int $workspaceId, int $targetId, array $fingerprints): bool
    {
        foreach ($fingerprints as $fingerprint) {
            $row = Database::queryOne("SELECT 1 FROM target_evidence WHERE workspace_id=? AND target_id=? AND evidence_fingerprint=? AND decision_state='rejected'", [$workspaceId, $targetId, $fingerprint]);
            if ($row) {
                return true;
            }
        }
        return false;
    }

    private function target(int $targetId, int $workspaceId): ?array
    {
        return Database::queryOne('SELECT * FROM targets WHERE workspace_id=? AND id=?', [$workspaceId, $targetId]);
    }

    private function evidencePayload(array $row): array
    {
        return [
            'collector' => (string) ($row['collector_key'] ?? ''), 'entity_type' => (string) ($row['source_entity_type'] ?? ''),
            'entity_id' => !empty($row['source_entity_id']) ? (int) $row['source_entity_id'] : null,
            'observed_at' => (string) ($row['observed_at'] ?? ''), 'value' => (float) ($row['contribution_value'] ?? 0),
            'currency' => $row['currency_code'] ?? null, 'fingerprint' => (string) ($row['evidence_fingerprint'] ?? ''),
            'decision_state' => (string) ($row['decision_state'] ?? 'observed'), 'facts' => $this->decode($row['evidence_payload_json'] ?? null),
        ];
    }

    private function proposalPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'type' => (string) $row['proposal_type'], 'changes' => $this->decode($row['proposed_changes_json'] ?? null),
            'confidence' => isset($row['confidence_score']) ? (float) $row['confidence_score'] : null, 'risk' => (string) $row['risk_level'],
            'explanation' => (string) ($row['explanation'] ?? ''), 'missing_evidence' => $this->decode($row['missing_evidence_json'] ?? null),
            'conflicts' => $this->decode($row['conflicts_json'] ?? null), 'state' => (string) $row['decision_state'],
        ];
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}

class TargetStateConflictException extends \RuntimeException
{
    public function __construct(string $message, private array $currentTarget)
    {
        parent::__construct($message, 409);
    }

    public function currentTarget(): array
    {
        return $this->currentTarget;
    }
}
