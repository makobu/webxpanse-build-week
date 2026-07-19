<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\CommercialAutomationApprovalService;
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Services\WorkflowAutomationProposalService;

function mobileDecisionCommercialApproval(array $approval): array
{
    $preview = (array) ($approval['preview'] ?? []);
    $diagnostics = (array) ($approval['diagnostics'] ?? []);
    $action = (string) ($approval['action_key'] ?? 'commercial action');
    $invoiceId = (int) ($approval['invoice_id'] ?? 0);
    $dealId = (int) ($approval['deal_id'] ?? 0);
    $contextLabel = trim((string) ($approval['invoice_number'] ?? $approval['deal_title'] ?? ''));
    $riskFlags = array_values(array_filter(array_map('strval', (array) ($preview['risk_flags'] ?? []))));

    return [
        'source' => 'commercial',
        'id' => (int) ($approval['id'] ?? 0),
        'title' => mobileDecisionLabel($action),
        'summary' => trim((string) ($preview['summary'] ?? 'Review this commercial action before it is executed.')),
        'reason' => trim((string) ($approval['reason'] ?? 'Approval required.')),
        'status' => (string) ($approval['status'] ?? 'pending'),
        'risk_flags' => $riskFlags,
        'customer_facing' => in_array('customer_facing_send', $riskFlags, true),
        'confidence' => isset($diagnostics['assistant_confidence']) ? (float) $diagnostics['assistant_confidence'] : null,
        'requested_by' => (string) ($diagnostics['requested_by_label'] ?? $approval['requested_by_type'] ?? 'system'),
        'context_label' => $contextLabel,
        'context_route' => $invoiceId > 0 ? '/invoices/' . $invoiceId : ($dealId > 0 ? '/deals/' . $dealId : null),
        'web_url' => 'commercial_approvals.php?id=' . (int) ($approval['id'] ?? 0),
        'created_at' => (string) ($approval['created_at'] ?? ''),
        'metadata' => [
            'classification' => (string) ($diagnostics['classification'] ?? ''),
            'document_type' => (string) ($preview['document_type'] ?? ''),
            'channel' => (string) ($preview['channel'] ?? ''),
        ],
    ];
}

function mobileDecisionWorkflowApproval(array $proposal): array
{
    $proposalType = (string) ($proposal['proposal_type'] ?? 'create');
    $actionItems = mobileDecisionStringList($proposal['action_summary'] ?? [], 4);
    $diffItems = mobileDecisionStringList($proposal['diff_summary'] ?? [], 3);
    $riskItems = mobileDecisionStringList($proposal['risk_summary'] ?? [], 6);
    $validationItems = mobileDecisionStringList($proposal['validation_issues'] ?? [], 3);
    $summaryParts = array_merge($actionItems, $diffItems);
    $summary = $summaryParts !== []
        ? implode(' · ', $summaryParts)
        : mobileDecisionLabel($proposalType) . ' the proposed workflow after approval.';

    return [
        'source' => 'workflow',
        'id' => (int) ($proposal['id'] ?? 0),
        'title' => trim((string) ($proposal['target_workflow_name'] ?? 'Workflow proposal')),
        'summary' => $summary,
        'reason' => $validationItems !== []
            ? implode(' · ', $validationItems)
            : trim((string) ($proposal['notes'] ?? 'A workflow change is waiting for human review.')),
        'status' => (string) ($proposal['status'] ?? 'pending'),
        'risk_flags' => $riskItems,
        'customer_facing' => !empty($proposal['risk_summary']['customer_facing']),
        'confidence' => isset($proposal['confidence_score']) ? (float) $proposal['confidence_score'] : null,
        'requested_by' => (string) ($proposal['requested_by_type'] ?? 'ai'),
        'context_label' => mobileDecisionLabel($proposalType) . ' workflow',
        'context_route' => null,
        'web_url' => 'workflow_approvals.php?id=' . (int) ($proposal['id'] ?? 0),
        'created_at' => (string) ($proposal['created_at'] ?? ''),
        'metadata' => [
            'proposal_type' => $proposalType,
            'decision_mode' => (string) ($proposal['decision_mode'] ?? ''),
            'governance_decision' => (string) ($proposal['governance_decision'] ?? ''),
        ],
    ];
}

$method = mobileDecisionRequireMethod('GET', 'POST');
$canCommercial = mobileDecisionCan('commercial_automation.approvals');
$canWorkflow = mobileDecisionCan('workflow_automation.approvals');
if (!$canCommercial && !$canWorkflow) {
    mobileJson([
        'success' => false,
        'error' => 'No approval queues are available to this user.',
        'error_code' => 'permission_denied',
    ], 403);
}

try {
    if ($method === 'POST') {
        $source = strtolower(trim((string) ($mobileDecisionInput['source'] ?? '')));
        $decision = strtolower(trim((string) ($mobileDecisionInput['decision'] ?? '')));
        $id = (int) ($mobileDecisionInput['id'] ?? 0);
        $note = substr(trim((string) ($mobileDecisionInput['note'] ?? '')), 0, 2000);
        if ($id <= 0) {
            throw new InvalidArgumentException('Approval is required.');
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }

        if ($source === 'commercial') {
            mobileDecisionRequire('commercial_automation.approvals');
            $service = new CommercialAutomationApprovalService();
            if ($decision === 'approve') {
                $result = (new CommercialAutomationOrchestrator())->executeApprovedAction($id, $mobileDecisionUserId);
                if (!$result) {
                    mobileJson(['success' => false, 'error' => 'This approval is no longer pending.'], 409);
                }
                mobileJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Commercial action approved and processed.',
                        'context_route' => !empty($result['invoice_id'])
                            ? '/invoices/' . (int) $result['invoice_id']
                            : (!empty($result['deal_id']) ? '/deals/' . (int) $result['deal_id'] : null),
                    ],
                ]);
            }
            $approval = $service->reject($id, $mobileDecisionUserId, $note !== '' ? $note : 'Rejected from mobile Decision Center');
            if (!$approval) {
                mobileJson(['success' => false, 'error' => 'This approval is no longer pending.'], 409);
            }
            mobileJson(['success' => true, 'data' => ['message' => 'Commercial action rejected.']]);
        }

        if ($source === 'workflow') {
            mobileDecisionRequire('workflow_automation.approvals');
            $service = new WorkflowAutomationProposalService();
            if ($decision === 'approve') {
                if ($note !== '') {
                    $service->approve($id, $mobileDecisionUserId, $note);
                }
                $result = $service->applyProposal($id, $mobileDecisionUserId);
                if (!$result) {
                    mobileJson(['success' => false, 'error' => 'This workflow proposal is no longer pending.'], 409);
                }
                mobileJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Workflow proposal approved and applied.',
                        'workflow_id' => (int) ($result['workflow_id'] ?? 0),
                    ],
                ]);
            }
            $proposal = $service->reject($id, $mobileDecisionUserId, $note !== '' ? $note : 'Rejected from mobile Decision Center');
            if (!$proposal) {
                mobileJson(['success' => false, 'error' => 'This workflow proposal is no longer pending.'], 409);
            }
            mobileJson(['success' => true, 'data' => ['message' => 'Workflow proposal rejected.']]);
        }

        throw new InvalidArgumentException('Unsupported approval source.');
    }

    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 40)));
    $items = [];
    $counts = ['commercial' => 0, 'workflow' => 0];
    if ($canCommercial) {
        $commercial = (new CommercialAutomationApprovalService())->listAll(['status' => 'pending', 'limit' => $limit]);
        $counts['commercial'] = count($commercial);
        foreach ($commercial as $approval) {
            $items[] = mobileDecisionCommercialApproval($approval);
        }
    }
    if ($canWorkflow) {
        $workflow = (new WorkflowAutomationProposalService())->listAll(['status' => 'pending', 'limit' => $limit]);
        $counts['workflow'] = count($workflow);
        foreach ($workflow as $proposal) {
            $items[] = mobileDecisionWorkflowApproval($proposal);
        }
    }
    usort($items, static fn(array $left, array $right): int =>
        strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''))
    );
    $items = array_slice($items, 0, $limit);

    mobileJson([
        'success' => true,
        'data' => [
            'items' => $items,
            'summary' => [
                'pending' => array_sum($counts),
                'customer_facing' => count(array_filter($items, static fn(array $item): bool => !empty($item['customer_facing']))),
                'by_source' => $counts,
            ],
            'capabilities' => [
                'commercial' => $canCommercial,
                'workflow' => $canWorkflow,
            ],
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile Decision Center approvals failed: ' . $e->getMessage());
    mobileJson([
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => 'decision_approval_failed',
    ], 422);
}
