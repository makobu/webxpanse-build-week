<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Security;
use CRM\Services\AIDemonstrationCaptureService;
use CRM\Services\AILearningReviewService;
use CRM\Services\AIWorkspaceScopeService;
use CRM\Services\CommercialAutomationApprovalService;
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Services\InvoiceDeliveryReadinessService;
use CRM\Services\InvoiceRenderer;
use CRM\Services\InvoiceTemplateService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('invoices.view');

$invoiceId = (int) ($_GET['id'] ?? 0);
$module = new Invoices();
$invoice = $module->getById($invoiceId);
if (!$invoice) {
    header('Location: invoices.php');
    exit;
}
$userId = (int) (Auth::user()['id'] ?? 0);
$approvalService = new CommercialAutomationApprovalService();
$commercialAutomation = new CommercialAutomationOrchestrator();
$capture = new AIDemonstrationCaptureService();
$learningReview = new AILearningReviewService();
$readinessService = new InvoiceDeliveryReadinessService();
$aiWorkspaceScope = new AIWorkspaceScopeService();
$invoiceTenantKey = $aiWorkspaceScope->workspaceTenantKey((int) ($invoice['workspace_id'] ?? 0));
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'send') {
                Authorization::requirePermission('invoices.send');
                $recipient = (string) ($_POST['recipient'] ?? '');
                (new \CRM\Services\InvoiceSendService())->send($invoiceId, $recipient, $userId, 'user');
                $capture->capture([
                    'workspace_id' => (int) ($invoice['workspace_id'] ?? 0),
                    'tenant_key' => $invoiceTenantKey,
                    'actor_user_id' => $userId,
                    'actor_type' => 'user',
                    'source_surface' => 'invoice_view',
                    'domain_key' => 'commercial_mvp',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoiceId,
                    'related_entity_type' => !empty($invoice['deal_id']) ? 'deal' : null,
                    'related_entity_id' => !empty($invoice['deal_id']) ? (int) $invoice['deal_id'] : null,
                    'action_key' => 'manual_send_document',
                    'prior_state' => ['status' => $invoice['status'] ?? null],
                    'action_payload' => ['recipient' => $recipient],
                    'outcome_state' => ['status' => 'sent'],
                    'outcome_label' => 'accepted',
                    'metadata' => ['channel' => 'email', 'document_type' => $invoice['document_type'] ?? null],
                    'was_successful' => true,
                ]);
                $message = 'Document sent successfully.';
            } elseif ($action === 'convert') {
                Authorization::requirePermission('invoices.create');
                $newId = $module->convertToInvoice($invoiceId, $userId, 'user');
                $capture->capture([
                    'workspace_id' => (int) ($invoice['workspace_id'] ?? 0),
                    'tenant_key' => $invoiceTenantKey,
                    'actor_user_id' => $userId,
                    'actor_type' => 'user',
                    'source_surface' => 'invoice_view',
                    'domain_key' => 'commercial_mvp',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoiceId,
                    'action_key' => 'manual_convert_to_invoice',
                    'prior_state' => ['document_type' => $invoice['document_type'] ?? null, 'status' => $invoice['status'] ?? null],
                    'action_payload' => ['from_invoice_id' => $invoiceId],
                    'outcome_state' => ['invoice_id' => $newId, 'status' => 'converted'],
                    'outcome_label' => 'accepted',
                    'metadata' => ['document_type' => $invoice['document_type'] ?? null],
                    'was_successful' => true,
                ]);
                header('Location: invoice_view.php?id=' . $newId . '&success=converted');
                exit;
            } elseif ($action === 'mark_paid') {
                Authorization::requirePermission('invoices.mark_paid');
                $amount = (float) ($_POST['amount'] ?? 0);
                $module->markPaid($invoiceId, $amount, date('Y-m-d H:i:s'), $userId, 'user', 'Manual payment update');
                $capture->capture([
                    'workspace_id' => (int) ($invoice['workspace_id'] ?? 0),
                    'tenant_key' => $invoiceTenantKey,
                    'actor_user_id' => $userId,
                    'actor_type' => 'user',
                    'source_surface' => 'invoice_view',
                    'domain_key' => 'commercial_mvp',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoiceId,
                    'action_key' => 'manual_mark_paid',
                    'prior_state' => ['balance_due' => $invoice['balance_due'] ?? null, 'status' => $invoice['status'] ?? null],
                    'action_payload' => ['amount' => $amount],
                    'outcome_state' => ['status' => 'paid'],
                    'outcome_label' => 'accepted',
                    'metadata' => ['document_type' => $invoice['document_type'] ?? null],
                    'was_successful' => true,
                ]);
                $message = 'Payment recorded.';
            } elseif ($action === 'transition') {
                $status = (string) ($_POST['status'] ?? 'draft');
                if ($status === 'finalized') {
                    Authorization::requirePermission('invoices.finalize');
                } elseif (in_array($status, ['partially_paid', 'paid'], true)) {
                    Authorization::requirePermission('invoices.mark_paid');
                } else {
                    Authorization::requirePermission('invoices.edit');
                }
                $module->transitionStatus($invoiceId, $status, $userId, 'user', 'Manual status update');
                $capture->capture([
                    'workspace_id' => (int) ($invoice['workspace_id'] ?? 0),
                    'tenant_key' => $invoiceTenantKey,
                    'actor_user_id' => $userId,
                    'actor_type' => 'user',
                    'source_surface' => 'invoice_view',
                    'domain_key' => 'commercial_mvp',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoiceId,
                    'action_key' => 'manual_transition_status',
                    'prior_state' => ['status' => $invoice['status'] ?? null],
                    'action_payload' => ['to_status' => $status],
                    'outcome_state' => ['status' => $status],
                    'outcome_label' => 'accepted',
                    'metadata' => ['document_type' => $invoice['document_type'] ?? null],
                    'was_successful' => true,
                ]);
                $message = 'Status updated.';
            } elseif ($action === 'approve_commercial_action') {
                Authorization::requirePermission('commercial_automation.approvals');
                $result = $commercialAutomation->executeApprovedAction((int) ($_POST['approval_id'] ?? 0), $userId);
                $message = $result ? 'Commercial automation approval executed.' : 'Approval could not be processed.';
            } elseif ($action === 'reject_commercial_action') {
                Authorization::requirePermission('commercial_automation.approvals');
                $approvalService->reject((int) ($_POST['approval_id'] ?? 0), $userId, 'Rejected from invoice view');
                $message = 'Commercial automation approval rejected.';
            } elseif ($action === 'run_commercial_automation') {
                Authorization::requirePermission('commercial_automation.approvals');
                $commercialAutomation->runForDeal((int) ($invoice['deal_id'] ?? 0), 'manual', $invoiceId);
                $message = 'Commercial automation run queued.';
            }
            $invoice = $module->getById($invoiceId);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pendingApprovals = $approvalService->listPending(null, $invoiceId);
$sendReadiness = $readinessService->compile($invoice);
$recentRuns = $commercialAutomation->getRecentRuns(null, $invoiceId, 6);
$assistantTypeWhere = Database::columnExists('email_assistant_runs', 'assistant_type') ? " AND assistant_type = 'email'" : '';
$assistantRuns = Database::query(
    "SELECT * FROM email_assistant_runs WHERE workspace_id = ? AND invoice_id = ?{$assistantTypeWhere} ORDER BY created_at DESC, id DESC LIMIT 6",
    [(int) ($invoice['workspace_id'] ?? 0), $invoiceId]
);
$assistantAmbiguities = Database::query(
    "SELECT * FROM email_assistant_runs WHERE workspace_id = ? AND invoice_id = ? AND resolution_status = 'ambiguous'{$assistantTypeWhere} ORDER BY created_at DESC, id DESC LIMIT 3",
    [(int) ($invoice['workspace_id'] ?? 0), $invoiceId]
);
$latestAssistantRun = $assistantRuns[0] ?? null;
$learningExplanation = $learningReview->buildExplanation(
    $invoiceTenantKey,
    'commercial_mvp',
    'send_document',
    [
        'invoice' => $invoice,
        'deal' => ['id' => $invoice['deal_id'] ?? null, 'stage' => $invoice['deal_stage'] ?? ''],
        'document_type' => $invoice['document_type'] ?? '',
        'channel' => 'email',
    ]
);
$previewHtml = (new InvoiceRenderer())->renderHtml($invoice);
$templateLabel = (new InvoiceTemplateService())->getAvailableThemes()[(string) ($invoice['template_key'] ?? 'classic')] ?? 'Classic';
$pageTitle = ($invoice['invoice_number'] ?? 'Invoice') . ' - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
                <p><?php echo htmlspecialchars(ucfirst($invoice['document_type']) . ' • ' . ucwords(str_replace('_', ' ', $invoice['status'])) . ' • ' . $templateLabel . ' template'); ?></p>
            </div>
            <div class="page-header-actions">
                <a href="ai_learning_review.php?domain=commercial_mvp" class="btn-premium-secondary">AI learning review</a>
                <a href="invoice_edit.php?id=<?php echo $invoiceId; ?>" class="btn-premium-secondary">Edit</a>
                <a href="invoice_pdf.php?id=<?php echo $invoiceId; ?>" target="_blank" class="btn-premium-secondary">PDF</a>
                <a href="invoices.php" class="btn-premium-secondary">Back</a>
            </div>
        </div>
        <?php if ($message): ?><div class="content-card" style="background:#d1fae5;border-color:#10b981;color:#065f46;margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="background:#fee2e2;border-color:#ef4444;color:#991b1b;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!empty($pendingApprovals)): ?>
            <div class="content-card" style="background:#fff7ed;border-color:#fb923c;color:#9a3412;margin-bottom:1rem;">
                <strong>Commercial automation needs approval.</strong>
                <div style="margin-top:.5rem;display:grid;gap:.75rem;">
                    <?php foreach ($pendingApprovals as $approval): ?>
                        <?php $diagnostics = (array) ($approval['diagnostics'] ?? []); ?>
                        <?php $preview = (array) ($approval['preview'] ?? []); ?>
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;">
                            <div>
                                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                    <div style="font-weight:600;"><?php echo htmlspecialchars((string) ($approval['action_key'] ?? 'action')); ?></div>
                                    <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f9731615;color:#c2410c;font-size:12px;font-weight:700;"><?php echo htmlspecialchars((string) ($diagnostics['classification'] ?? 'approval')); ?></span>
                                    <?php if (in_array('customer_facing_send', (array) ($diagnostics['risk_flags'] ?? []), true)): ?>
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#dc262615;color:#b91c1c;font-size:12px;font-weight:700;">customer send</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:.85rem;"><?php echo htmlspecialchars((string) ($approval['reason'] ?? 'Approval required')); ?></div>
                                <div style="margin-top:.3rem;font-size:.8rem;"><?php echo htmlspecialchars((string) ($preview['summary'] ?? '')); ?></div>
                                <div style="margin-top:.25rem;font-size:.75rem;">Codes: <?php echo htmlspecialchars(implode(', ', (array) ($diagnostics['reason_codes'] ?? [])) ?: 'none'); ?></div>
                            </div>
                            <div style="display:flex;gap:.5rem;">
                                <a href="commercial_approvals.php?id=<?php echo (int) $approval['id']; ?>" class="btn-premium-secondary">Inspect</a>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="approve_commercial_action">
                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                    <button type="submit" class="btn-premium-primary">Approve</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="reject_commercial_action">
                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                    <button type="submit" class="btn-premium-secondary">Reject</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,.9fr);gap:1rem;align-items:start;">
            <div class="content-card" style="overflow:auto;"><?php echo $previewHtml; ?></div>
            <div style="display:grid;gap:1rem;">
                <div class="content-card">
                    <h2 style="margin-top:0;">Quick actions</h2>
                    <?php if (!empty($sendReadiness['blocking_issues']) || !empty($sendReadiness['warnings'])): ?>
                        <div style="display:grid;gap:.5rem;margin-bottom:1rem;padding:.85rem;border-radius:10px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;">
                            <strong>Delivery readiness</strong>
                            <?php foreach ((array) ($sendReadiness['blocking_issues'] ?? []) as $issue): ?>
                                <div style="font-size:.9rem;">Blocker: <?php echo htmlspecialchars((string) ($issue['message'] ?? '')); ?></div>
                            <?php endforeach; ?>
                            <?php foreach ((array) ($sendReadiness['warnings'] ?? []) as $warning): ?>
                                <div style="font-size:.9rem;">Warning: <?php echo htmlspecialchars((string) ($warning['message'] ?? '')); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($invoice['deal_id'])): ?>
                        <form method="POST" style="margin-bottom:1rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="run_commercial_automation">
                            <button type="submit" class="btn-premium-secondary" style="width:100%;">Run commercial automation now</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="display:grid;gap:.75rem;margin-bottom:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="send">
                        <input type="email" name="recipient" value="<?php echo htmlspecialchars((string) ($sendReadiness['recipient'] ?? ($invoice['billing_email'] ?? $invoice['contact_email'] ?? ''))); ?>" placeholder="customer@example.com" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        <button type="submit" class="btn-premium-primary">Send by email</button>
                    </form>
                    <form method="POST" style="display:grid;gap:.75rem;margin-bottom:1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="transition">
                        <select name="status" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            <?php foreach (\CRM\Modules\Invoices::STATUSES as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $invoice['status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-premium-secondary">Update status</button>
                    </form>
                    <form method="POST" style="display:grid;gap:.75rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="number" step="0.01" min="0" name="amount" value="<?php echo htmlspecialchars((string) max(0, (float) ($invoice['balance_due'] ?? 0))); ?>" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        <button type="submit" class="btn-premium-secondary">Mark payment received</button>
                    </form>
                    <?php if (in_array($invoice['document_type'], ['quote', 'proforma'], true)): ?>
                        <form method="POST" style="margin-top:1rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="convert">
                            <button type="submit" class="btn-premium-primary">Convert to invoice</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="content-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <h2 style="margin-top:0;margin-bottom:0;">Learned rationale</h2>
                        <a href="ai_learning_review.php?domain=commercial_mvp" class="btn-premium-secondary">Open review</a>
                    </div>
                    <div style="margin-top:.75rem;font-size:.9rem;color:#475569;">
                        Preferred channel: <strong><?php echo htmlspecialchars((string) ($learningExplanation['tenant_policy']['preferred_channel'] ?? 'none')); ?></strong><br>
                        Preferred document type: <strong><?php echo htmlspecialchars((string) ($learningExplanation['tenant_policy']['preferred_document_type'] ?? 'none')); ?></strong><br>
                        Reversal risk: <strong><?php echo htmlspecialchars(number_format((float) ($learningExplanation['tenant_policy']['reversal_risk'] ?? 0), 2)); ?></strong><br>
                        Promotion gate: <strong><?php echo htmlspecialchars((string) (($learningExplanation['promotion_gate_status']['decision'] ?? 'hold'))); ?></strong><br>
                        Drift unstable: <strong><?php echo !empty($learningExplanation['drift_status']['unstable']) ? 'yes' : 'no'; ?></strong>
                    </div>
                    <?php if (!empty($learningExplanation['similar_examples'])): ?>
                        <div style="margin-top:.75rem;display:grid;gap:.55rem;">
                            <?php foreach (array_slice((array) $learningExplanation['similar_examples'], 0, 3) as $example): ?>
                                <div style="padding:.75rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                                    <strong><?php echo htmlspecialchars((string) ($example['feature_summary']['action_key'] ?? 'example')); ?></strong>
                                    <div style="font-size:.85rem;color:#64748b;">
                                        score <?php echo htmlspecialchars(number_format((float) ($example['score'] ?? 0), 2)); ?> •
                                        <?php echo htmlspecialchars((string) ($example['feature_summary']['deal_stage'] ?? '')); ?> •
                                        <?php echo htmlspecialchars((string) ($example['feature_summary']['channel'] ?? '')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="content-card">
                    <h2 style="margin-top:0;">Timeline</h2>
                    <?php foreach ($invoice['status_history'] as $history): ?>
                        <div style="padding:.5rem 0;border-bottom:1px solid var(--border-color);">
                            <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $history['to_status']))); ?></strong>
                            <div style="color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) $history['created_at']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="content-card">
                    <h2 style="margin-top:0;">Activity</h2>
                    <?php foreach ($invoice['activity_log'] as $activity): ?>
                        <div style="padding:.5rem 0;border-bottom:1px solid var(--border-color);">
                            <strong><?php echo htmlspecialchars($activity['summary']); ?></strong>
                            <div style="color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) $activity['created_at']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="content-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <h2 style="margin-top:0;margin-bottom:0;">Commercial automation</h2>
                        <a href="commercial_approvals.php?invoice_id=<?php echo (int) $invoiceId; ?>&status=pending" class="btn-premium-secondary">Open approvals workbench</a>
                    </div>
                    <?php if (empty($recentRuns)): ?>
                        <p style="margin:0;color:#64748b;">No automation runs recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($recentRuns as $run): ?>
                            <div style="padding:.5rem 0;border-bottom:1px solid var(--border-color);">
                                <strong><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($run['trigger_type'] ?? 'manual'))); ?></strong>
                                <div style="color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) ($run['decision'] ?? 'reject')); ?> • <?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="content-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <h2 style="margin-top:0;margin-bottom:0;">Email assistant</h2>
                        <a href="email_assistant_runs.php" class="btn-premium-secondary">View runs</a>
                    </div>
                    <p style="margin:.5rem 0 1rem;color:#64748b;">Assistant-origin activity tied to this commercial document.</p>
                    <?php if ($latestAssistantRun): ?>
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:1rem;">
                            <div style="padding:.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Last intent</div>
                                <div style="font-weight:700;color:#0f172a;margin-top:.3rem;"><?php echo htmlspecialchars((string) ($latestAssistantRun['intent'] ?? '')); ?></div>
                            </div>
                            <div style="padding:.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Execution</div>
                                <div style="font-weight:700;color:#0f172a;margin-top:.3rem;"><?php echo htmlspecialchars((string) ($latestAssistantRun['execution_status'] ?? '')); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($assistantAmbiguities)): ?>
                        <div style="display:grid;gap:.75rem;margin-bottom:1rem;">
                            <?php foreach ($assistantAmbiguities as $ambiguity): ?>
                                <div style="padding:.85rem;border:1px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#92400e;">
                                    <strong>Ambiguous assistant action</strong>
                                    <div style="margin-top:.25rem;font-size:.85rem;"><?php echo htmlspecialchars((string) ($ambiguity['intent'] ?? 'assistant_action')); ?> needs clarification.</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($assistantRuns)): ?>
                        <p style="margin:0;color:#64748b;">No assistant activity recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($assistantRuns as $run): ?>
                            <div style="padding:.5rem 0;border-bottom:1px solid var(--border-color);">
                                <strong><?php echo htmlspecialchars((string) ($run['intent'] ?? 'assistant_action')); ?></strong>
                                <div style="color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) ($run['resolution_status'] ?? 'resolved')); ?> • <?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
