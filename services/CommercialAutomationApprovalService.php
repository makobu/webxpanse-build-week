<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;

class CommercialAutomationApprovalService
{
    private AIDecisionOutcomeService $outcomes;
    private AIOutcomeClassifier $classifier;
    private AIDemonstrationCaptureService $capture;
    private AILearningReviewService $review;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->outcomes = new AIDecisionOutcomeService();
        $this->classifier = new AIOutcomeClassifier();
        $this->capture = new AIDemonstrationCaptureService();
        $this->review = new AILearningReviewService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function requestApproval(
        ?int $dealId,
        ?int $invoiceId,
        string $actionKey,
        string $reason,
        array $payload = [],
        string $requestedByType = 'system',
        ?int $requestedById = null
    ): int {
        $workspaceId = $this->resolveWorkspaceId($dealId, $invoiceId);
        Database::execute(
            "INSERT INTO commercial_automation_approvals
                (workspace_id, deal_id, invoice_id, action_key, status, reason, requested_by_type, requested_by_id, payload_json)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?)",
            [$workspaceId, $dealId, $invoiceId, $actionKey, $reason, $requestedByType, $requestedById, json_encode($payload)]
        );
        return (int) Database::lastInsertId();
    }

    public function approve(int $approvalId, int $userId): ?array
    {
        $approval = $this->getById($approvalId);
        if (!$approval || ($approval['status'] ?? '') !== 'pending') {
            return null;
        }
        Database::execute(
            "UPDATE commercial_automation_approvals
             SET status = 'approved', resolved_by = ?, resolved_at = NOW()
             WHERE id = ?",
            [$userId, $approvalId]
        );
        $updated = $this->getById($approvalId);
        if ($updated) {
            $this->outcomes->recordApprovalOutcome($updated, $this->classifier->classifyApprovalOutcome($updated));
            $this->captureApprovalDecision($updated, 'approve', $userId, true);
        }
        return $updated;
    }

    public function reject(int $approvalId, int $userId, string $reason): ?array
    {
        $approval = $this->getById($approvalId);
        if (!$approval || ($approval['status'] ?? '') !== 'pending') {
            return null;
        }
        Database::execute(
            "UPDATE commercial_automation_approvals
             SET status = 'rejected', reason = ?, resolved_by = ?, resolved_at = NOW()
             WHERE id = ?",
            [$reason, $userId, $approvalId]
        );
        $updated = $this->getById($approvalId);
        if ($updated) {
            $this->outcomes->recordApprovalOutcome($updated, $this->classifier->classifyApprovalOutcome($updated));
            $this->captureApprovalDecision($updated, 'reject', $userId, false, $reason);
        }
        return $updated;
    }

    public function listPending(?int $dealId = null, ?int $invoiceId = null): array
    {
        return $this->listAll([
            'status' => 'pending',
            'deal_id' => $dealId,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function listAll(array $filters = []): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $where = ['a.workspace_id = ?'];
        $params = [$workspaceId];

        $status = trim((string) ($filters['status'] ?? ''));
        $actionKey = trim((string) ($filters['action_key'] ?? ''));
        $requestedByType = trim((string) ($filters['requested_by_type'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        if ($status !== '') {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['deal_id'])) {
            $where[] = 'a.deal_id = ?';
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['invoice_id'])) {
            $where[] = 'a.invoice_id = ?';
            $params[] = (int) $filters['invoice_id'];
        }
        if ($actionKey !== '') {
            $where[] = 'a.action_key = ?';
            $params[] = $actionKey;
        }
        if ($requestedByType !== '') {
            $where[] = 'a.requested_by_type = ?';
            $params[] = $requestedByType;
        }
        if ($dateFrom !== '') {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($search !== '') {
            $where[] = '(a.reason LIKE ? OR a.action_key LIKE ? OR CAST(a.id AS CHAR) = ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = $search;
        }

        $sql = "SELECT a.*,
                       d.title AS deal_title,
                       i.invoice_number,
                       i.document_type AS invoice_document_type,
                       i.title AS invoice_title,
                       u.email AS requested_by_email,
                       ru.email AS resolved_by_email
                FROM commercial_automation_approvals a
                LEFT JOIN deals d ON d.id = a.deal_id AND d.workspace_id = a.workspace_id
                LEFT JOIN invoices i ON i.id = a.invoice_id AND i.workspace_id = a.workspace_id
                LEFT JOIN users u ON u.id = a.requested_by_id
                LEFT JOIN users ru ON ru.id = a.resolved_by";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY
                    CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END,
                    a.created_at DESC,
                    a.id DESC
                  LIMIT {$limit}";

        $rows = Database::query($sql, $params);
        return array_map(fn(array $row): array => $this->hydrateApproval($row), $rows);
    }

    public function getById(int $approvalId): ?array
    {
        $row = Database::queryOne(
            "SELECT * FROM commercial_automation_approvals WHERE workspace_id = ? AND id = ?",
            [$this->workspaceScope->requireActiveWorkspaceId(), $approvalId]
        );
        if (!$row) {
            return null;
        }
        return $this->hydrateApproval($row);
    }

    public function getDetailedById(int $approvalId): ?array
    {
        $row = Database::queryOne(
            "SELECT a.*,
                    d.title AS deal_title,
                    i.invoice_number,
                    i.document_type AS invoice_document_type,
                    i.title AS invoice_title,
                    u.email AS requested_by_email,
                    ru.email AS resolved_by_email
             FROM commercial_automation_approvals a
             LEFT JOIN deals d ON d.id = a.deal_id AND d.workspace_id = a.workspace_id
             LEFT JOIN invoices i ON i.id = a.invoice_id AND i.workspace_id = a.workspace_id
             LEFT JOIN users u ON u.id = a.requested_by_id
             LEFT JOIN users ru ON ru.id = a.resolved_by
             WHERE a.workspace_id = ?
               AND a.id = ?",
            [$this->workspaceScope->requireActiveWorkspaceId(), $approvalId]
        );
        if (!$row) {
            return null;
        }

        return $this->hydrateApproval($row);
    }

    public function getDiagnostics(array $approval): array
    {
        $payload = (array) ($approval['payload'] ?? []);
        $context = (array) ($payload['context'] ?? []);
        $policyPayload = (array) ($payload['policy'] ?? []);
        $config = (new CommercialAutomationConfig())->get();
        $documentType = (string) ($context['document_type'] ?? $context['invoice']['document_type'] ?? $approval['invoice_document_type'] ?? '');
        $channel = (string) ($context['channel'] ?? 'email');
        $recipient = (string) ($context['recipient'] ?? '');
        $stage = (string) ($context['deal']['stage'] ?? '');
        $requestedDiscountPercent = (float) ($context['requested_discount_percent'] ?? 0);
        $requestedTotalChangePercent = (float) ($context['requested_total_change_percent'] ?? 0);
        $reasonCodes = $this->extractReasonCodes((string) ($approval['reason'] ?? ''), $context);
        $classification = $this->classifyReasonCodes($reasonCodes);

        return [
            'reason_codes' => $reasonCodes,
            'classification' => $classification,
            'thresholds' => [
                'max_auto_discount_percent' => (float) ($config['max_auto_discount_percent'] ?? 0),
                'max_auto_total_change_percent' => (float) ($config['max_auto_total_change_percent'] ?? 0),
                'max_revision_count_before_approval' => (int) ($config['max_revision_count_before_approval'] ?? 0),
                'require_recipient_for_send' => !empty($config['require_recipient_for_send']),
                'require_nonzero_total_for_send' => !empty($config['require_nonzero_total_for_send']),
                'require_billing_identity_for_final_invoice' => !empty($config['require_billing_identity_for_final_invoice']),
                'auto_convert_requires_status' => (string) ($config['auto_convert_requires_status'] ?? 'accepted'),
                'source' => 'current_config',
            ],
            'linked_document_type' => $documentType,
            'channel' => $channel,
            'recipient' => $recipient,
            'requested_discount_percent' => $requestedDiscountPercent,
            'requested_total_change_percent' => $requestedTotalChangePercent,
            'has_zero_priced_recommendation' => !empty($context['has_zero_priced_recommendation']),
            'source_surface' => (string) ($context['surface'] ?? ''),
            'assistant_confidence' => isset($context['assistant_confidence']) ? (float) $context['assistant_confidence'] : null,
            'confidence_basis' => (array) ($context['confidence_basis'] ?? []),
            'envelope_decision' => $policyPayload['envelope_decision'] ?? ($context['governance']['decision'] ?? null),
            'promotion_gate_status' => (array) ($policyPayload['promotion_gate_status'] ?? []),
            'drift_status' => (array) ($policyPayload['drift_status'] ?? []),
            'incident_id' => $policyPayload['incident_id'] ?? null,
            'stage' => $stage,
            'auto_mode' => (string) ($config['mode'] ?? 'auto_safe'),
            'risk_flags' => $this->buildRiskFlags($approval, $context, $documentType, $channel, $recipient),
            'requested_by_label' => $this->buildRequestedByLabel($approval),
            'learning_review' => $this->review->buildExplanation(
                $this->tenantKeyForApproval($approval),
                'commercial_mvp',
                (string) ($approval['action_key'] ?? ''),
                $context
            ),
        ];
    }

    public function getPreview(array $approval): array
    {
        $payload = (array) ($approval['payload'] ?? []);
        $action = (array) ($payload['action'] ?? []);
        $context = (array) ($payload['context'] ?? []);
        $actionKey = (string) ($approval['action_key'] ?? $action['action'] ?? 'action');
        $documentType = (string) ($context['document_type'] ?? $context['invoice']['document_type'] ?? $approval['invoice_document_type'] ?? 'document');
        $recipient = (string) ($context['recipient'] ?? '');
        $channel = (string) ($context['channel'] ?? 'email');
        $dealId = (int) ($approval['deal_id'] ?? 0);
        $invoiceId = (int) ($approval['invoice_id'] ?? 0);
        $invoiceNumber = (string) ($approval['invoice_number'] ?? '');
        $targetLabel = $invoiceNumber !== '' ? $documentType . ' ' . $invoiceNumber : $documentType;

        $summary = match ($actionKey) {
            'send_document' => 'If approved, the system will send ' . trim($targetLabel) . ($recipient !== '' ? ' to ' . $recipient : '') . ' by ' . $channel . '.',
            'revise_document' => 'If approved, the system will revise the current ' . $documentType . ' using the stored negotiation context and recommendations.',
            'convert_to_invoice' => 'If approved, the system will convert the current ' . $documentType . ' into a final invoice.',
            'finalize_invoice' => 'If approved, the system will finalize the current invoice.',
            'create_draft' => 'If approved, the system will create a new ' . $documentType . ' draft for the linked deal.',
            'cancel_document' => 'If approved, the system will cancel the linked ' . $documentType . '.',
            'mark_overdue' => 'If approved, the system will mark the linked invoice as overdue.',
            default => 'If approved, the system will execute the stored action: ' . $actionKey . '.',
        };

        return [
            'summary' => $summary,
            'action' => $actionKey,
            'document_type' => $documentType,
            'channel' => $channel,
            'recipient' => $recipient,
            'deal_id' => $dealId > 0 ? $dealId : null,
            'invoice_id' => $invoiceId > 0 ? $invoiceId : null,
            'risk_flags' => $this->buildRiskFlags($approval, $context, $documentType, $channel, $recipient),
            'learning_review' => $this->review->buildExplanation(
                $this->tenantKeyForApproval($approval),
                'commercial_mvp',
                $actionKey,
                $context
            ),
        ];
    }

    private function captureApprovalDecision(array $approval, string $decision, int $userId, bool $successful, ?string $reason = null): void
    {
        $payload = (array) ($approval['payload'] ?? []);
        $context = (array) ($payload['context'] ?? []);
        $action = (array) ($payload['action'] ?? []);
        $this->capture->capture([
            'tenant_key' => $this->tenantKeyForApproval($approval),
            'workspace_id' => !empty($approval['workspace_id']) ? (int) $approval['workspace_id'] : null,
            'actor_user_id' => $userId,
            'actor_type' => 'user',
            'source_surface' => 'commercial_approval',
            'domain_key' => 'commercial_mvp',
            'entity_type' => 'approval',
            'entity_id' => (int) ($approval['id'] ?? 0),
            'related_entity_type' => !empty($approval['invoice_id']) ? 'invoice' : 'deal',
            'related_entity_id' => !empty($approval['invoice_id']) ? (int) $approval['invoice_id'] : (!empty($approval['deal_id']) ? (int) $approval['deal_id'] : null),
            'action_key' => $decision . '_commercial_action',
            'prior_state' => ['approval' => $approval, 'context' => $context],
            'action_payload' => ['action' => $action, 'decision' => $decision],
            'outcome_state' => ['approval_status' => $approval['status'] ?? $decision],
            'outcome_label' => $successful ? 'approved' : 'rejected',
            'free_text_reason' => $reason,
            'metadata' => [
                'channel' => $context['channel'] ?? null,
                'document_type' => $context['document_type'] ?? ($context['invoice']['document_type'] ?? null),
            ],
            'linked_approval_id' => (int) ($approval['id'] ?? 0),
            'was_successful' => $successful,
            'was_reversed' => !$successful,
            'was_edited' => false,
        ]);
    }

    public function buildSummaryMetrics(array $approvals): array
    {
        $metrics = [
            'pending_count' => 0,
            'customer_send_count' => 0,
            'assistant_origin_count' => 0,
            'automation_origin_count' => 0,
            'top_reason_category' => 'none',
            'reason_categories' => [],
        ];

        foreach ($approvals as $approval) {
            if (($approval['status'] ?? '') === 'pending') {
                $metrics['pending_count']++;
            }
            $diagnostics = (array) ($approval['diagnostics'] ?? $this->getDiagnostics($approval));
            $preview = (array) ($approval['preview'] ?? $this->getPreview($approval));
            if (in_array('customer_facing_send', (array) ($preview['risk_flags'] ?? []), true)) {
                $metrics['customer_send_count']++;
            }
            if (($approval['requested_by_type'] ?? '') === 'ai') {
                $metrics['assistant_origin_count']++;
            }
            if (in_array((string) ($approval['requested_by_type'] ?? ''), ['system', 'user'], true)) {
                $metrics['automation_origin_count']++;
            }
            $category = (string) ($diagnostics['classification'] ?? 'unknown');
            $metrics['reason_categories'][$category] = (int) ($metrics['reason_categories'][$category] ?? 0) + 1;
        }

        arsort($metrics['reason_categories']);
        $metrics['top_reason_category'] = (string) (array_key_first($metrics['reason_categories']) ?? 'none');

        return $metrics;
    }

    private function hydrateApproval(array $row): array
    {
        $payloadRaw = (string) ($row['payload_json'] ?? '');
        $payload = [];
        $payloadError = null;
        if ($payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payloadError = 'invalid_payload_json';
            }
        }

        $row['payload'] = $payload;
        $row['payload_error'] = $payloadError;
        $row['diagnostics'] = $this->getDiagnostics($row);
        $row['preview'] = $this->getPreview($row);
        return $row;
    }

    private function extractReasonCodes(string $reason, array $context): array
    {
        $reasonLower = strtolower($reason);
        $codes = [];
        $map = [
            'missing recipient' => 'missing_recipient',
            'recipient is required' => 'missing_recipient',
            'zero total' => 'zero_total',
            'must be greater than zero' => 'zero_total',
            'billing identity' => 'missing_billing_identity',
            'billing legal identity' => 'missing_billing_identity',
            'document type' => 'document_type_not_allowed',
            'discount' => 'discount_threshold_exceeded',
            'revision count' => 'revision_count_exceeded',
            'total change' => 'total_change_threshold_exceeded',
            'source status' => 'conversion_status_below_required',
            'confidence' => 'assistant_send_confidence_low',
            'retry' => 'delivery_retry_exhausted',
            'whatsapp' => 'channel_transport_unavailable',
            'not allowed for the current stage' => 'document_type_not_allowed',
            'below the configured minimum' => 'conversion_status_below_required',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($reasonLower, $needle)) {
                $codes[] = $code;
            }
        }

        if (!empty($context['has_zero_priced_recommendation'])) {
            $codes[] = 'zero_priced_recommendation';
        }
        if (($context['recipient'] ?? '') === '' && str_contains($reasonLower, 'recipient')) {
            $codes[] = 'missing_recipient';
        }
        if (($context['requested_discount_percent'] ?? 0) > 0 && str_contains($reasonLower, 'discount')) {
            $codes[] = 'discount_threshold_exceeded';
        }
        if (($context['requested_total_change_percent'] ?? 0) > 0 && str_contains($reasonLower, 'total')) {
            $codes[] = 'total_change_threshold_exceeded';
        }

        return array_values(array_unique($codes));
    }

    private function classifyReasonCodes(array $reasonCodes): string
    {
        foreach ($reasonCodes as $code) {
            if (in_array($code, ['missing_recipient', 'missing_billing_identity', 'zero_total', 'zero_priced_recommendation'], true)) {
                return 'missing_data';
            }
            if (in_array($code, ['discount_threshold_exceeded', 'total_change_threshold_exceeded', 'revision_count_exceeded'], true)) {
                return 'threshold_breach';
            }
            if (in_array($code, ['document_type_not_allowed', 'conversion_status_below_required'], true)) {
                return 'stage_policy';
            }
            if (in_array($code, ['delivery_retry_exhausted', 'channel_transport_unavailable'], true)) {
                return 'transport_or_retry';
            }
            if (in_array($code, ['assistant_send_confidence_low'], true)) {
                return 'confidence_or_ai';
            }
        }

        return 'unknown';
    }

    private function buildRiskFlags(array $approval, array $context, string $documentType, string $channel, string $recipient): array
    {
        $flags = [];
        if (($approval['action_key'] ?? '') === 'send_document') {
            $flags[] = 'customer_facing_send';
        }
        if (($approval['action_key'] ?? '') === 'finalize_invoice') {
            $flags[] = 'finalization';
        }
        if (($approval['action_key'] ?? '') === 'convert_to_invoice') {
            $flags[] = 'conversion';
        }
        if ($recipient === '') {
            $flags[] = 'missing_recipient';
        }
        if (!empty($context['has_zero_priced_recommendation'])) {
            $flags[] = 'zero_priced_recommendation';
        }
        if ($channel === 'whatsapp') {
            $flags[] = 'whatsapp_channel';
        }
        if (in_array($documentType, ['quote', 'proforma', 'invoice'], true)) {
            $flags[] = 'commercial_document';
        }

        return array_values(array_unique($flags));
    }

    private function buildRequestedByLabel(array $approval): string
    {
        $type = (string) ($approval['requested_by_type'] ?? 'system');
        $email = (string) ($approval['requested_by_email'] ?? '');
        $id = (int) ($approval['requested_by_id'] ?? 0);
        if ($email !== '') {
            return $type . ': ' . $email;
        }
        if ($id > 0) {
            return $type . ' #' . $id;
        }
        return $type;
    }

    private function resolveWorkspaceId(?int $dealId, ?int $invoiceId, ?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!empty($dealId)) {
            $this->workspaceScope->assertSameWorkspace('deals', (int) $dealId, $resolvedWorkspaceId);
        }
        if (!empty($invoiceId)) {
            $this->assertInvoiceWorkspace((int) $invoiceId, $resolvedWorkspaceId);
        }

        return $resolvedWorkspaceId;
    }

    private function tenantKeyForApproval(array $approval): string
    {
        return $this->aiWorkspaceScope->workspaceTenantKey(
            $this->resolveWorkspaceId(
                !empty($approval['deal_id']) ? (int) $approval['deal_id'] : null,
                !empty($approval['invoice_id']) ? (int) $approval['invoice_id'] : null,
                !empty($approval['workspace_id']) ? (int) $approval['workspace_id'] : null
            )
        );
    }

    private function assertInvoiceWorkspace(int $invoiceId, int $workspaceId): void
    {
        $row = Database::queryOne(
            "SELECT COALESCE(i.workspace_id, d.workspace_id, c.workspace_id) AS workspace_id
             FROM invoices i
             LEFT JOIN deals d ON d.id = i.deal_id
             LEFT JOIN contacts c ON c.id = i.contact_id
             WHERE i.id = ?
             LIMIT 1",
            [$invoiceId]
        );
        if (!$row) {
            throw new \RuntimeException('The requested record was not found in the active workspace.');
        }
        if ((int) ($row['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('The requested record does not belong to the active workspace.');
        }
    }
}
