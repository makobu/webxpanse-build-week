<?php

namespace CRM\Services;

class EmailAssistantPolicyBridge
{
    private AIOperatingContextService $operatingContext;
    private AIQualificationPolicyService $qualification;
    private CommercialAutomationPolicyService $policy;
    private AIControlDecisionBridge $controlBridge;
    private AIAutonomyGovernanceService $governance;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->operatingContext = new AIOperatingContextService();
        $this->qualification = new AIQualificationPolicyService();
        $this->policy = new CommercialAutomationPolicyService();
        $this->controlBridge = new AIControlDecisionBridge();
        $this->governance = new AIAutonomyGovernanceService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function evaluateAssistantAction(string $action, array $context, int $userId): array
    {
        $assistantContext = $this->buildAssistantContext($context, $userId, (string) ($context['surface'] ?? 'admin_command'));
        $qualification = $this->qualification->evaluateAssistantAction($action, $assistantContext);
        $normalized = $this->normalizeDecision($qualification, null, $assistantContext);
        return $this->applyRuntimeControls($normalized, $assistantContext, false);
    }

    public function evaluateAssistantAdvice(string $surface, array $context, int $userId): array
    {
        $assistantContext = $this->buildAssistantContext($context, $userId, $surface);
        $qualification = $this->qualification->evaluateAssistantAdvice($surface, $assistantContext);
        $normalized = $this->normalizeDecision($qualification, null, $assistantContext);
        return $this->applyRuntimeControls($normalized, $assistantContext, false);
    }

    public function evaluateCommercialAssistantAction(string $action, array $context, int $userId): array
    {
        $assistantContext = $this->buildAssistantContext($context, $userId, (string) ($context['surface'] ?? 'admin_command'));
        $qualification = $this->qualification->evaluateAssistantAction($action, $assistantContext);

        if (in_array((string) ($qualification['decision'] ?? 'blocked'), ['blocked', 'suggest_only'], true)) {
            $normalized = $this->normalizeDecision($qualification, null, $assistantContext);
            return $this->applyRuntimeControls($normalized, $assistantContext, true);
        }

        $commercial = $this->policy->evaluateAction($action, $assistantContext);
        $tenantKey = (string) ($assistantContext['tenant_key'] ?? $this->aiWorkspaceScope->currentTenantKey());
        $domainKey = !empty($assistantContext['requires_customer_send']) ? 'customer_thread' : 'commercial_mvp';
        $governance = $this->governance->evaluate($tenantKey, $domainKey, $action, $assistantContext, $commercial);
        $commercial = $this->applyGovernanceToCommercialDecision($commercial, $governance);
        $normalized = $this->normalizeDecision($qualification, $commercial, $assistantContext);
        return $this->applyRuntimeControls($normalized, $assistantContext, true);
    }

    public function evaluateCustomerReplySend(array $context, int $userId): array
    {
        $context['surface'] = 'customer_thread';
        $context['requires_customer_send'] = true;
        return $this->evaluateCommercialAssistantAction('send_document', $context, $userId);
    }

    public function normalizeDecision(array $qualification, ?array $commercial = null, array $context = []): array
    {
        $qualificationDecision = (string) ($qualification['decision'] ?? 'blocked');
        $qualificationMapped = $this->mapQualificationDecision($qualificationDecision);
        $commercialMapped = $commercial ? $this->mapCommercialDecision((string) ($commercial['decision'] ?? 'reject')) : null;
        $decision = $qualificationMapped;
        $warnings = [];
        $reasons = array_values(array_unique(array_filter((array) ($qualification['reasons'] ?? []))));

        if ($commercialMapped !== null && in_array($qualificationMapped, ['allow', 'allow_with_warning'], true)) {
            $decision = $commercialMapped;
            if ($commercialMapped === 'allow' && $qualificationMapped === 'allow_with_warning') {
                $decision = 'allow_with_warning';
            }
            $reasons = array_values(array_unique(array_filter(array_merge($reasons, (array) ($commercial['reasons'] ?? [])))));
        }

        if ($qualificationMapped === 'allow_with_warning') {
            $warnings = array_values(array_unique(array_filter($qualification['reasons'] ?? [])));
        }
        if ($decision === 'allow_with_warning' && $commercial) {
            $warnings = array_values(array_unique(array_filter(array_merge($warnings, (array) ($commercial['reasons'] ?? [])))));
        }

        return [
            'decision' => $decision,
            'confidence_score' => (float) ($qualification['confidence_score'] ?? $context['assistant_confidence'] ?? 0.0),
            'context_quality_score' => (float) ($qualification['context_quality_score'] ?? 0.0),
            'goal_relevance_score' => (float) ($qualification['goal_relevance_score'] ?? ($context['goal_state']['goal_relevance_score'] ?? 0.0)),
            'mode' => (string) ($qualification['mode'] ?? ($context['qualification_state']['effective_mode'] ?? '1')),
            'threshold' => (float) ($qualification['confidence_threshold'] ?? 0.0),
            'reasons' => $reasons,
            'warnings' => $warnings,
            'approval_required' => $decision === 'approval_required',
            'can_execute' => in_array($decision, ['allow', 'allow_with_warning'], true),
            'policy_sources' => [
                'qualification' => $qualification,
                'commercial' => $commercial,
            ],
            'learning_explanation' => $commercial['learned_signals'] ?? null,
            'envelope_decision' => $commercial['envelope_decision'] ?? null,
            'promotion_gate_status' => $commercial['promotion_gate_status'] ?? null,
            'drift_status' => $commercial['drift_status'] ?? null,
            'incident_id' => $commercial['incident_id'] ?? null,
        ];
    }

    private function buildAssistantContext(array $context, int $userId, string $surface): array
    {
        $assistantContext = $this->operatingContext->buildForSurface($userId, $surface);
        $assistantContext['surface']['name'] = $surface;
        $assistantContext['identity']['user_id'] = $userId;
        $assistantContext['assistant_confidence'] = (float) ($context['assistant_confidence'] ?? $context['confidence_score'] ?? 1.0);
        $assistantContext['confidence_score'] = (float) ($context['assistant_confidence'] ?? $context['confidence_score'] ?? 1.0);
        $assistantContext['origin'] = 'email_assistant';
        $assistantContext['requires_customer_send'] = (bool) ($context['requires_customer_send'] ?? false);
        $assistantContext['thread_context_present'] = !empty($context['thread_context_present']) || !empty($context['thread_context']) || !empty($context['communication_id']);
        $assistantContext['assistant_requested_action'] = (string) ($context['assistant_requested_action'] ?? '');
        $assistantContext['deal'] = (array) ($context['deal'] ?? []);
        $assistantContext['invoice'] = (array) ($context['invoice'] ?? []);
        $assistantContext['contact'] = (array) ($context['contact'] ?? []);
        $assistantContext['document_type'] = (string) ($context['document_type'] ?? ($assistantContext['invoice']['document_type'] ?? ''));
        $assistantContext['recipient'] = (string) ($context['recipient'] ?? '');
        $assistantContext['channel'] = (string) ($context['channel'] ?? 'email');
        $assistantContext['current_page'] = (string) ($context['current_page'] ?? '');
        if (array_key_exists('explicit_evidence_available', $context)) {
            $assistantContext['task_state']['explicit_evidence_available'] = (bool) $context['explicit_evidence_available'];
        }
        foreach ([
            'requested_discount_percent',
            'requested_total_change_percent',
            'has_zero_priced_recommendation',
        ] as $key) {
            if (array_key_exists($key, $context)) {
                $assistantContext[$key] = $context[$key];
            }
        }

        $workspaceId = $this->resolveWorkspaceId($assistantContext);
        $assistantContext['workspace_id'] = $workspaceId;
        $assistantContext['tenant_key'] = $this->aiWorkspaceScope->workspaceTenantKey($workspaceId);

        return $assistantContext;
    }

    private function mapQualificationDecision(string $decision): string
    {
        return match ($decision) {
            'allow', 'allow_with_warning', 'suggest_only', 'blocked' => $decision,
            default => 'blocked',
        };
    }

    private function mapCommercialDecision(string $decision): string
    {
        return match ($decision) {
            'auto_apply' => 'allow',
            'approval_required' => 'approval_required',
            'suggest_only' => 'suggest_only',
            'reject' => 'blocked',
            default => 'blocked',
        };
    }

    private function applyRuntimeControls(array $decision, array $context, bool $isAction): array
    {
        $surfaces = ['assistant'];
        $surface = (string) ($context['surface']['name'] ?? '');
        if (in_array($surface, ['assistant', 'customer_thread', 'commercial_assistant'], true)) {
            $surfaces[] = $surface;
        }
        if (!empty($context['requires_customer_send'])) {
            $surfaces[] = 'customer_thread';
            $surfaces[] = 'commercial_assistant';
        }
        if (($context['origin'] ?? '') === 'email_assistant' && str_contains((string) ($context['assistant_requested_action'] ?? ''), 'commercial')) {
            $surfaces[] = 'commercial_assistant';
        }

        return $this->controlBridge->applyControls($surfaces, $decision, $isAction);
    }

    private function applyGovernanceToCommercialDecision(array $commercial, array $governance): array
    {
        $commercial['envelope_decision'] = $governance['decision'] ?? 'allow';
        $commercial['promotion_gate_status'] = $governance['promotion'] ?? [];
        $commercial['drift_status'] = $governance['drift'] ?? [];
        $commercial['incident_id'] = $governance['incident_id'] ?? null;
        if (($governance['decision'] ?? 'allow') === 'allow') {
            return $commercial;
        }
        $commercial['decision'] = ($governance['decision'] ?? 'blocked') === 'approval_required' ? 'approval_required' : 'reject';
        $commercial['reason'] = implode(', ', (array) ($governance['reason_codes'] ?? ['governance_blocked']));
        $commercial['reasons'] = (array) ($governance['reason_codes'] ?? ['governance_blocked']);
        return $commercial;
    }

    private function resolveWorkspaceId(array $context): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        foreach ([
            ['contact', 'contacts'],
            ['deal', 'deals'],
            ['invoice', 'invoices'],
        ] as [$contextKey, $table]) {
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
