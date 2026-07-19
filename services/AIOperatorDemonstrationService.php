<?php

namespace CRM\Services;

use CRM\Database;

class AIOperatorDemonstrationService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function record(array $data): int
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return 0;
        }

        $normalized = $this->normalizeRecord($data);
        $existing = Database::queryOne(
            "SELECT id FROM ai_operator_demonstrations WHERE demonstration_hash = ? LIMIT 1",
            [$normalized['demonstration_hash']]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO ai_operator_demonstrations
                (workspace_id, tenant_key, actor_user_id, actor_type, source_surface, domain_key, entity_type, entity_id,
                 related_entity_type, related_entity_id, action_key, prior_state_json, action_payload_json,
                 outcome_state_json, outcome_label, free_text_reason, metadata_json, demonstration_hash,
                 linked_commercial_run_id, linked_approval_id, linked_task_id, was_successful, was_reversed,
                 was_edited, observed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $normalized['workspace_id'],
                $normalized['tenant_key'],
                $normalized['actor_user_id'],
                $normalized['actor_type'],
                $normalized['source_surface'],
                $normalized['domain_key'],
                $normalized['entity_type'],
                $normalized['entity_id'],
                $normalized['related_entity_type'],
                $normalized['related_entity_id'],
                $normalized['action_key'],
                json_encode($normalized['prior_state']),
                json_encode($normalized['action_payload']),
                json_encode($normalized['outcome_state']),
                $normalized['outcome_label'],
                $normalized['free_text_reason'],
                json_encode($normalized['metadata']),
                $normalized['demonstration_hash'],
                $normalized['linked_commercial_run_id'],
                $normalized['linked_approval_id'],
                $normalized['linked_task_id'],
                $normalized['was_successful'] ? 1 : 0,
                $normalized['was_reversed'] ? 1 : 0,
                $normalized['was_edited'] ? 1 : 0,
                $normalized['observed_at'],
            ]
        );

        $id = (int) Database::lastInsertId();
        $this->indexSimilarity($id, $normalized);

        return $id;
    }

    public function recordCommercialAction(
        array $action,
        array $context,
        array $result,
        string $actorType,
        ?int $actorId = null,
        ?int $commercialRunId = null,
        ?int $approvalId = null
    ): int {
        $deal = (array) ($context['deal'] ?? []);
        $invoice = (array) ($context['invoice'] ?? []);
        $entityType = !empty($deal['id']) ? 'deal' : 'invoice';
        $entityId = !empty($deal['id']) ? (int) $deal['id'] : (!empty($invoice['id']) ? (int) $invoice['id'] : null);

        return $this->record([
            'tenant_key' => $this->resolveTenantKey($context, $actorId),
            'workspace_id' => $this->workspaceScope->requireWorkspaceId($this->resolveTenantKey($context, $actorId)),
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'source_surface' => (string) ($context['surface'] ?? 'commercial_automation'),
            'domain_key' => 'commercial_mvp',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'related_entity_type' => !empty($invoice['id']) ? 'invoice' : null,
            'related_entity_id' => !empty($invoice['id']) ? (int) $invoice['id'] : null,
            'action_key' => (string) ($action['action'] ?? 'commercial_action'),
            'prior_state' => [
                'deal' => $deal,
                'invoice' => $invoice,
                'trigger_type' => $context['trigger_type'] ?? null,
                'document_type' => $context['document_type'] ?? ($invoice['document_type'] ?? null),
                'channel' => $context['channel'] ?? null,
                'recipient' => !empty($context['recipient']) ? 'present' : 'missing',
                'assistant_confidence' => $context['assistant_confidence'] ?? null,
            ],
            'action_payload' => [
                'action' => $action,
                'channel' => $context['channel'] ?? null,
                'recipient' => $context['recipient'] ?? null,
                'document_type' => $context['document_type'] ?? ($invoice['document_type'] ?? null),
            ],
            'outcome_state' => [
                'result' => $result,
                'invoice_status' => $result['status'] ?? null,
            ],
            'outcome_label' => $this->mapOutcomeLabel($result),
            'metadata' => [
                'channel' => $context['channel'] ?? null,
                'document_type' => $context['document_type'] ?? ($invoice['document_type'] ?? null),
                'assistant_confidence' => $context['assistant_confidence'] ?? null,
                'deal_stage' => $deal['stage'] ?? null,
            ],
            'linked_commercial_run_id' => $commercialRunId,
            'linked_approval_id' => $approvalId,
            'was_successful' => $this->isSuccessfulResult($result),
            'was_reversed' => false,
            'was_edited' => false,
            'observed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markOutcome(int $demonstrationId, array $updates): bool
    {
        if ($demonstrationId <= 0 || !$this->tableExists('ai_operator_demonstrations')) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT metadata_json FROM ai_operator_demonstrations WHERE id = ? LIMIT 1",
            [$demonstrationId]
        );
        if (!$row) {
            return false;
        }

        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        $metadata = array_merge($metadata, (array) ($updates['metadata'] ?? []));

        Database::execute(
            "UPDATE ai_operator_demonstrations
             SET outcome_state_json = ?,
                 outcome_label = ?,
                 free_text_reason = ?,
                 metadata_json = ?,
                 was_successful = ?,
                 was_reversed = ?,
                 was_edited = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [
                json_encode((array) ($updates['outcome_state'] ?? [])),
                (string) ($updates['outcome_label'] ?? 'observed'),
                $updates['free_text_reason'] ?? null,
                json_encode($metadata),
                !empty($updates['was_successful']) ? 1 : 0,
                !empty($updates['was_reversed']) ? 1 : 0,
                !empty($updates['was_edited']) ? 1 : 0,
                $demonstrationId,
            ]
        );

        return true;
    }

    public function resolveTenantKey(array $context, ?int $fallbackUserId = null): string
    {
        $tenantKey = trim((string) ($context['tenant_key'] ?? ''));
        $workspaceId = $this->workspaceScope->resolveWorkspaceIdFromTenantKey($tenantKey);
        if ($workspaceId !== null && $workspaceId > 0) {
            return $this->workspaceScope->workspaceTenantKey($workspaceId);
        }

        $invoice = (array) ($context['invoice'] ?? []);
        $deal = (array) ($context['deal'] ?? []);

        if (!empty($invoice['workspace_id'])) {
            return $this->workspaceScope->workspaceTenantKey((int) $invoice['workspace_id']);
        }
        if (!empty($deal['workspace_id'])) {
            return $this->workspaceScope->workspaceTenantKey((int) $deal['workspace_id']);
        }
        if (!empty($invoice['company_id'])) {
            return $this->workspaceScope->workspaceTenantKey($this->workspaceScope->requireWorkspaceId('company:' . (int) $invoice['company_id']));
        }
        if (!empty($deal['company_id'])) {
            return $this->workspaceScope->workspaceTenantKey($this->workspaceScope->requireWorkspaceId('company:' . (int) $deal['company_id']));
        }
        if (!empty($invoice['contact_id'])) {
            return $this->workspaceScope->workspaceTenantKey($this->workspaceScope->requireWorkspaceId('contact:' . (int) $invoice['contact_id']));
        }
        if (!empty($deal['contact_id'])) {
            return $this->workspaceScope->workspaceTenantKey($this->workspaceScope->requireWorkspaceId('contact:' . (int) $deal['contact_id']));
        }
        if ($fallbackUserId !== null && $fallbackUserId > 0) {
            return $this->workspaceScope->workspaceTenantKey($this->workspaceScope->requireWorkspaceId('user:' . $fallbackUserId));
        }

        return $this->workspaceScope->currentTenantKey();
    }

    private function normalizeRecord(array $data): array
    {
        $priorState = (array) ($data['prior_state'] ?? []);
        $actionPayload = (array) ($data['action_payload'] ?? []);
        $outcomeState = (array) ($data['outcome_state'] ?? []);
        $metadata = (array) ($data['metadata'] ?? []);
        $normalized = [
            'workspace_id' => $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''), isset($data['workspace_id']) ? (int) $data['workspace_id'] : null),
            'tenant_key' => $this->workspaceScope->workspaceTenantKey(
                $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''), isset($data['workspace_id']) ? (int) $data['workspace_id'] : null)
            ),
            'actor_user_id' => !empty($data['actor_user_id']) ? (int) $data['actor_user_id'] : null,
            'actor_type' => (string) ($data['actor_type'] ?? 'system'),
            'source_surface' => (string) ($data['source_surface'] ?? 'unknown'),
            'domain_key' => (string) ($data['domain_key'] ?? 'commercial_mvp'),
            'entity_type' => (string) ($data['entity_type'] ?? 'unknown'),
            'entity_id' => isset($data['entity_id']) && $data['entity_id'] !== null ? (int) $data['entity_id'] : null,
            'related_entity_type' => !empty($data['related_entity_type']) ? (string) $data['related_entity_type'] : null,
            'related_entity_id' => isset($data['related_entity_id']) && $data['related_entity_id'] !== null ? (int) $data['related_entity_id'] : null,
            'action_key' => (string) ($data['action_key'] ?? 'unknown'),
            'prior_state' => $priorState,
            'action_payload' => $actionPayload,
            'outcome_state' => $outcomeState,
            'outcome_label' => (string) ($data['outcome_label'] ?? 'observed'),
            'free_text_reason' => $data['free_text_reason'] ?? null,
            'metadata' => $metadata,
            'linked_commercial_run_id' => !empty($data['linked_commercial_run_id']) ? (int) $data['linked_commercial_run_id'] : null,
            'linked_approval_id' => !empty($data['linked_approval_id']) ? (int) $data['linked_approval_id'] : null,
            'linked_task_id' => !empty($data['linked_task_id']) ? (int) $data['linked_task_id'] : null,
            'was_successful' => !empty($data['was_successful']),
            'was_reversed' => !empty($data['was_reversed']),
            'was_edited' => !empty($data['was_edited']),
            'observed_at' => (string) ($data['observed_at'] ?? date('Y-m-d H:i:s')),
        ];

        $normalized['demonstration_hash'] = !empty($data['demonstration_hash'])
            ? (string) $data['demonstration_hash']
            : sha1(json_encode([
                $normalized['tenant_key'],
                $normalized['source_surface'],
                $normalized['domain_key'],
                $normalized['entity_type'],
                $normalized['entity_id'],
                $normalized['related_entity_type'],
                $normalized['related_entity_id'],
                $normalized['action_key'],
                $normalized['actor_type'],
                $normalized['actor_user_id'],
                $normalized['linked_commercial_run_id'],
                $normalized['linked_approval_id'],
                $normalized['action_payload'],
            ]));

        return $normalized;
    }

    private function indexSimilarity(int $demonstrationId, array $normalized): void
    {
        if ($demonstrationId <= 0 || !$this->tableExists('ai_action_similarity_index')) {
            return;
        }

        $featureSummary = [
            'source_surface' => $normalized['source_surface'],
            'entity_type' => $normalized['entity_type'],
            'related_entity_type' => $normalized['related_entity_type'],
            'action_key' => $normalized['action_key'],
            'document_type' => $normalized['metadata']['document_type'] ?? null,
            'channel' => $normalized['metadata']['channel'] ?? null,
            'deal_stage' => $normalized['metadata']['deal_stage'] ?? null,
            'task_status' => $normalized['metadata']['task_status'] ?? null,
            'task_intent' => $normalized['metadata']['task_intent'] ?? null,
            'thread_goal' => $normalized['metadata']['thread_goal'] ?? null,
            'workflow_trigger_type' => $normalized['metadata']['workflow_trigger_type'] ?? null,
            'workflow_action_type' => $normalized['metadata']['workflow_action_type'] ?? null,
            'workflow_queue_state' => $normalized['metadata']['workflow_queue_state'] ?? null,
            'workflow_customer_facing' => $normalized['metadata']['workflow_customer_facing'] ?? null,
        ];
        $featureFingerprint = sha1(json_encode($featureSummary));
        $score = $normalized['was_successful'] ? 1.0 : 0.25;

        Database::execute(
            "INSERT INTO ai_action_similarity_index
                (workspace_id, tenant_key, domain_key, action_key, demonstration_id, feature_fingerprint, feature_summary_json, outcome_label, score)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                workspace_id = VALUES(workspace_id),
                tenant_key = VALUES(tenant_key),
                domain_key = VALUES(domain_key),
                action_key = VALUES(action_key),
                feature_fingerprint = VALUES(feature_fingerprint),
                feature_summary_json = VALUES(feature_summary_json),
                outcome_label = VALUES(outcome_label),
                score = VALUES(score)",
            [
                $normalized['workspace_id'],
                $normalized['tenant_key'],
                $normalized['domain_key'],
                $normalized['action_key'],
                $demonstrationId,
                $featureFingerprint,
                json_encode($featureSummary),
                $normalized['outcome_label'],
                $score,
            ]
        );
    }

    private function mapOutcomeLabel(array $result): string
    {
        return $this->isSuccessfulResult($result) ? 'accepted' : match ((string) ($result['status'] ?? 'observed')) {
            'failed', 'blocked' => 'failed',
            'approval_required' => 'approval_required',
            default => 'observed',
        };
    }

    private function isSuccessfulResult(array $result): bool
    {
        return in_array((string) ($result['status'] ?? ''), [
            'sent',
            'converted',
            'finalized',
            'created',
            'revised',
            'paid',
            'overdue',
            'cancelled',
        ], true);
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
}
