<?php

namespace CRM\Services;

use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Services\InvoiceAIAuthorizationService;

class CommercialAutomationPolicyService
{
    private CommercialAutomationConfig $config;
    private DealAutomationConfig $dealAutomationConfig;
    private InvoiceSettings $invoiceSettings;
    private InvoiceAIAuthorizationService $invoiceAuth;
    private AITenantPolicyResolverService $tenantPolicyResolver;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->config = new CommercialAutomationConfig();
        $this->dealAutomationConfig = new DealAutomationConfig();
        $this->invoiceSettings = new InvoiceSettings();
        $this->invoiceAuth = new InvoiceAIAuthorizationService();
        $this->tenantPolicyResolver = new AITenantPolicyResolverService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function getPolicySnapshot(?int $workspaceId = null): array
    {
        return [
            'commercial' => $this->config->get($workspaceId),
            'deal_automation' => $this->dealAutomationConfig->get($workspaceId),
            'invoice' => $this->invoiceSettings->get(),
        ];
    }

    public function evaluateAction(string $action, array $context): array
    {
        $workspaceId = $this->resolveWorkspaceId($context);
        $context['workspace_id'] = $workspaceId;
        $context['tenant_key'] = $this->aiWorkspaceScope->workspaceTenantKey($workspaceId);
        $policy = $this->getPolicySnapshot($workspaceId);
        $commercial = $policy['commercial'];
        $invoiceSettings = $policy['invoice'];
        $dealStage = (string) ($context['deal']['stage'] ?? $context['current_stage'] ?? '');
        $documentType = (string) ($context['invoice']['document_type'] ?? $context['document_type'] ?? '');
        $channel = (string) ($context['channel'] ?? 'email');
        $assistantConfidence = (float) ($context['assistant_confidence'] ?? 1.0);
        $mode = (string) ($commercial['mode'] ?? 'auto_safe');
        $actionThreshold = $this->resolveActionThreshold($action, $commercial);
        $reasons = [];
        $blockers = [];
        $tenantKey = (string) $context['tenant_key'];
        $learnedSignals = (array) ($context['learned_signals'] ?? $this->tenantPolicyResolver->resolve(
            $tenantKey,
            (string) ($context['domain_key'] ?? 'commercial_mvp'),
            array_merge($context, ['action_key' => $action])
        ));

        if (empty($commercial['enabled'])) {
            return ['decision' => 'reject', 'reason' => 'Commercial automation disabled.', 'reasons' => ['disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if (!$this->isActionAllowed($action, $documentType)) {
            return ['decision' => 'reject', 'reason' => 'AI permission disabled for action.', 'reasons' => ['action_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if ($mode === 'suggest_only') {
            return ['decision' => 'suggest_only', 'reason' => 'Commercial automation is in suggest-only mode.', 'reasons' => ['suggest_only_mode'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if (in_array($action, ['send_document', 'resend_document'], true)) {
            if (empty($commercial['auto_send_enabled'])) {
                return ['decision' => 'reject', 'reason' => 'Auto-send disabled.', 'reasons' => ['auto_send_disabled'], 'policy' => $policy];
            }
            if (empty(($commercial['send_channels'] ?? [])[$channel])) {
                $blockers[] = 'channel_disabled';
            }
            if ($channel === 'email' && empty($invoiceSettings['ai_allowed_channels']['email'])) {
                $blockers[] = 'invoice_channel_disabled';
            }
            if ($channel === 'whatsapp' && empty($invoiceSettings['ai_allowed_channels']['whatsapp'])) {
                $blockers[] = 'invoice_channel_disabled';
            }
            if (!empty($commercial['require_recipient_for_send']) && empty($context['recipient'])) {
                $blockers[] = 'missing_recipient';
            }
            if ($channel === 'whatsapp' && empty($context['within_whatsapp_window'])) {
                $blockers[] = 'whatsapp_window_closed';
            }
            if ($channel === 'whatsapp' && empty($context['whatsapp_provider_document_supported'])) {
                $blockers[] = 'unsupported_transport';
            }
            if (!empty($commercial['require_nonzero_total_for_send']) && (float) ($context['invoice']['grand_total'] ?? 0) <= 0) {
                $blockers[] = 'zero_total';
            }
        }

        if (in_array($action, ['create_draft', 'revise_document', 'convert_to_invoice', 'finalize_invoice'], true) && $dealStage !== '' && $documentType !== '') {
            $allowed = $invoiceSettings['ai_allowed_document_types_by_stage'][$dealStage] ?? [];
            if ($allowed && !in_array($documentType, $allowed, true)) {
                $blockers[] = 'document_type_not_allowed';
            }
        }

        if ($action === 'revise_document') {
            $discount = (float) ($context['requested_discount_percent'] ?? 0);
            if ($discount > (float) ($commercial['max_auto_discount_percent'] ?? 20)) {
                $blockers[] = 'discount_threshold_exceeded';
            }
            $totalChange = (float) ($context['requested_total_change_percent'] ?? 0);
            if ($totalChange > (float) ($commercial['max_auto_total_change_percent'] ?? 25)) {
                $blockers[] = 'total_change_threshold_exceeded';
            }
            $revisionNumber = (int) ($context['invoice']['revision_number'] ?? 1);
            if ($revisionNumber >= (int) ($commercial['max_revision_count_before_approval'] ?? 2)) {
                $blockers[] = 'revision_limit_reached';
            }
            if (!empty($context['has_zero_priced_recommendation'])) {
                $blockers[] = 'zero_priced_product';
            }
        }

        if ($action === 'convert_to_invoice') {
            $requiredStatus = (string) ($commercial['auto_convert_requires_status'] ?? 'accepted');
            $currentStatus = (string) ($context['invoice']['status'] ?? 'draft');
            $allowedStatuses = $requiredStatus === 'any_non_draft'
                ? array_diff(Invoices::STATUSES, ['draft'])
                : [$requiredStatus];
            if (!in_array($currentStatus, $allowedStatuses, true)) {
                $blockers[] = 'conversion_source_status_invalid';
            }
            if (!empty($commercial['require_billing_identity_for_final_invoice'])) {
                $billingName = trim((string) ($context['invoice']['billing_name'] ?? ''));
                $billingEmail = trim((string) ($context['invoice']['billing_email'] ?? ''));
                if ($billingName === '' || $billingEmail === '') {
                    $blockers[] = 'missing_billing_identity';
                }
            }
        }

        if ($action === 'mark_overdue' && empty($commercial['auto_mark_overdue_enabled'])) {
            return ['decision' => 'reject', 'reason' => 'Auto-overdue disabled.', 'reasons' => ['auto_mark_overdue_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if ($action === 'mark_paid') {
            if (!$this->invoiceAuth->canPerform('mark_invoice_paid')) {
                return ['decision' => 'reject', 'reason' => 'Mark-paid automation disabled in invoice settings.', 'reasons' => ['invoice_mark_paid_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
            }
            if ((float) ($context['invoice']['balance_due'] ?? 0) <= 0) {
                $blockers[] = 'invalid_payment_state';
            }
            if (empty($context['payment_signal_detected'])) {
                $blockers[] = 'payment_signal_missing';
            }
        }

        if ($action === 'create_draft' && empty($commercial['stage_entry_enabled'])) {
            return ['decision' => 'reject', 'reason' => 'Stage-entry automation disabled.', 'reasons' => ['stage_entry_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if ($action === 'revise_document' && empty($commercial['negotiation_revisions_enabled'])) {
            return ['decision' => 'reject', 'reason' => 'Negotiation revision automation disabled.', 'reasons' => ['revision_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if ($action === 'finalize_invoice' && empty($invoiceSettings['ai_finalize_invoices'])) {
            return ['decision' => 'reject', 'reason' => 'Invoice finalization disabled in invoice settings.', 'reasons' => ['invoice_finalize_disabled'], 'policy' => $policy, 'learned_signals' => $learnedSignals];
        }

        if ((float) ($learnedSignals['reversal_risk'] ?? 0) >= 0.35) {
            $reasons[] = 'learned_high_reversal_risk';
        }

        if ($action === 'finalize_invoice' && !empty($invoiceSettings['ai_require_approval_finalize']) && $mode !== 'full_auto') {
            $reasons[] = 'finalize_requires_approval';
        }
        if (in_array($action, ['send_document', 'resend_document'], true) && !empty($invoiceSettings['ai_require_approval_send']) && $mode !== 'full_auto') {
            $reasons[] = 'send_requires_approval';
        }

        if ($blockers) {
            return ['decision' => 'reject', 'reason' => implode(', ', $blockers), 'reasons' => $blockers, 'policy' => $policy, 'threshold' => $actionThreshold, 'learned_signals' => $learnedSignals];
        }

        if ($assistantConfidence > 0 && $assistantConfidence < $actionThreshold) {
            $reasons[] = 'confidence_below_threshold';
            if ($mode === 'full_auto') {
                return ['decision' => 'reject', 'reason' => 'Confidence below threshold.', 'reasons' => $reasons, 'policy' => $policy, 'threshold' => $actionThreshold, 'learned_signals' => $learnedSignals];
            }
            return ['decision' => 'approval_required', 'reason' => 'Confidence below threshold.', 'reasons' => $reasons, 'policy' => $policy, 'threshold' => $actionThreshold, 'learned_signals' => $learnedSignals];
        }

        if ($reasons) {
            return ['decision' => 'approval_required', 'reason' => implode(', ', $reasons), 'reasons' => $reasons, 'policy' => $policy, 'threshold' => $actionThreshold, 'learned_signals' => $learnedSignals];
        }

        return ['decision' => 'auto_apply', 'reason' => 'Policy checks passed.', 'reasons' => [], 'policy' => $policy, 'threshold' => $actionThreshold, 'learned_signals' => $learnedSignals];
    }

    public function requiresApproval(string $action, array $context): bool
    {
        return ($this->evaluateAction($action, $context)['decision'] ?? 'reject') === 'approval_required';
    }

    private function resolveActionThreshold(string $action, array $commercial): float
    {
        $thresholds = (array) ($commercial['action_confidence_thresholds'] ?? []);
        $default = (float) (($this->config->get()['action_confidence_thresholds'][$action] ?? 0.9));
        return max(0.0, min(1.0, (float) ($thresholds[$action] ?? $default)));
    }

    private function isActionAllowed(string $action, string $documentType): bool
    {
        return match ($action) {
            'create_draft' => $documentType === 'invoice'
                ? $this->invoiceAuth->canPerform('create_invoice')
                : $this->invoiceAuth->canPerform('create_quote'),
            'revise_document' => $this->invoiceAuth->canPerform('revise_quote'),
            'send_document', 'resend_document' => $this->invoiceAuth->canPerform('send_invoice'),
            'convert_to_invoice', 'finalize_invoice' => $this->invoiceAuth->canPerform('finalize_invoice'),
            'mark_paid' => $this->invoiceAuth->canPerform('mark_invoice_paid'),
            default => true,
        };
    }

    private function resolveWorkspaceId(array $context): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();

        $entityChecks = [
            ['contact', 'contacts'],
            ['deal', 'deals'],
            ['invoice', 'invoices'],
        ];

        foreach ($entityChecks as [$contextKey, $table]) {
            $entity = (array) ($context[$contextKey] ?? []);
            $entityId = (int) ($entity['id'] ?? 0);
            if ($entityId > 0) {
                if ($table === 'invoices') {
                    $this->assertInvoiceWorkspace($entityId, $workspaceId);
                    continue;
                }
                $this->workspaceScope->assertSameWorkspace($table, $entityId, $workspaceId);
            }
        }

        return $workspaceId;
    }

    private function assertInvoiceWorkspace(int $invoiceId, int $workspaceId): void
    {
        $row = \CRM\Database::queryOne(
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
