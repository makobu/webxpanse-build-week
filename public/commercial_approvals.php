<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/ai_ui_helper.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\CommercialAutomationApprovalService;
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}
Authorization::requirePermission('commercial_automation.approvals');

$approvalService = new CommercialAutomationApprovalService();
$orchestrator = new CommercialAutomationOrchestrator();
$userId = (int) ($user['id'] ?? 0);
$message = null;
$error = null;

$query = [
    'status' => trim((string) ($_GET['status'] ?? 'pending')),
    'action_key' => trim((string) ($_GET['action_key'] ?? '')),
    'requested_by_type' => trim((string) ($_GET['requested_by_type'] ?? '')),
    'deal_id' => (int) ($_GET['deal_id'] ?? 0),
    'invoice_id' => (int) ($_GET['invoice_id'] ?? 0),
    'search' => trim((string) ($_GET['search'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $approvalId = (int) ($_POST['approval_id'] ?? 0);
        $decision = trim((string) ($_POST['decision'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            if ($approvalId > 0 && $decision === 'approve') {
                $result = $orchestrator->executeApprovedAction($approvalId, $userId);
                if ($result) {
                    $message = 'Approval executed successfully.';
                } else {
                    $error = 'Approval could not be processed.';
                }
            } elseif ($approvalId > 0 && $decision === 'reject') {
                $approval = $approvalService->reject($approvalId, $userId, $reason !== '' ? $reason : 'Rejected from approvals workbench');
                if ($approval) {
                    $message = 'Approval rejected.';
                } else {
                    $error = 'Approval could not be rejected.';
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$approvals = $approvalService->listAll($query + ['limit' => 100]);
$metrics = $approvalService->buildSummaryMetrics($approvalService->listAll(['status' => 'pending', 'limit' => 200]));
$selectedApprovalId = (int) ($_GET['id'] ?? 0);
$selectedApproval = $selectedApprovalId > 0 ? $approvalService->getDetailedById($selectedApprovalId) : ($approvals[0] ?? null);
$selectedPreview = $selectedApproval ? $orchestrator->previewApprovedAction((int) $selectedApproval['id']) : null;

function commercialApprovalBadge(string $value): string
{
    return aiUiDecisionColors(match ($value) {
        'pending' => 'approval_required',
        'approved', 'auto_apply' => 'allow',
        'rejected', 'reject', 'expired' => 'blocked',
        default => $value,
    })['text'];
}

function commercialApprovalLink(string $label, string $path, ?int $id): string
{
    if (($id ?? 0) <= 0) {
        return 'n/a';
    }

    return '<a href="' . htmlspecialchars($path . '?id=' . (int) $id) . '">' . htmlspecialchars($label . ' #' . (int) $id) . '</a>';
}

function commercialApprovalDiagnosticsLink(array $approval): string
{
    $query = array_filter([
        'source' => 'commercial',
        'deal_id' => (int) ($approval['deal_id'] ?? 0),
        'invoice_id' => (int) ($approval['invoice_id'] ?? 0),
    ], static fn ($value) => $value !== 0 && $value !== '');

    return 'ai_automation_diagnostics.php?' . http_build_query($query);
}

$pageTitle = 'Commercial Approvals - ' . brandProductName();
$commercialApprovalsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_COMMERCIAL_APPROVALS);
ob_start();
?>
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Commercial Approvals</h1>
                <p>Review pending commercial actions, inspect diagnostics, and approve or reject with full context.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($commercialApprovalsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_COMMERCIAL_APPROVALS, 'Commercial Approvals page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="ai_learning_review.php?domain=commercial_mvp" class="btn-premium-secondary">AI learning review</a>
                <a href="ai_automation_diagnostics.php?source=commercial" class="btn-premium-secondary">Open diagnostics</a>
                <a href="settings.php?tab=commercial_automation" class="btn-premium-secondary">Commercial automation settings</a>
            </div>
        </div>

        <?php if ($message): ?><div class="content-card" style="background:#d1fae5;border-color:#10b981;color:#065f46;margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="background:#fee2e2;border-color:#ef4444;color:#991b1b;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Pending approvals</div>
                <div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['pending_count'] ?? 0); ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Customer-send approvals</div>
                <div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['customer_send_count'] ?? 0); ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Assistant-origin</div>
                <div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['assistant_origin_count'] ?? 0); ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Top reason category</div>
                <div style="font-size:20px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($metrics['top_reason_category'] ?? 'none')); ?></div>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.85rem;align-items:end;">
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Status</label>
                    <select name="status" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $query['status'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Action</label>
                    <input type="text" name="action_key" value="<?php echo htmlspecialchars($query['action_key']); ?>" placeholder="send_document" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Origin</label>
                    <select name="requested_by_type" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'ai' => 'AI', 'system' => 'System', 'user' => 'User'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $query['requested_by_type'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Deal ID</label>
                    <input type="number" min="0" name="deal_id" value="<?php echo $query['deal_id'] > 0 ? (int) $query['deal_id'] : ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Invoice ID</label>
                    <input type="number" min="0" name="invoice_id" value="<?php echo $query['invoice_id'] > 0 ? (int) $query['invoice_id'] : ''; ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($query['search']); ?>" placeholder="reason, action, id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($query['date_from']); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($query['date_to']); ?>" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button type="submit" class="btn-premium-primary">Filter</button>
                    <a href="commercial_approvals.php" class="btn-premium-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.95fr);gap:1rem;align-items:start;">
            <div class="content-card">
                <?php if (empty($approvals)): ?>
                    <p style="margin:0;color:#64748b;">No approvals found for the current filters.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($approvals as $approval): ?>
                            <?php
                            $statusColor = commercialApprovalBadge((string) ($approval['status'] ?? 'pending'));
                            $actionColor = commercialApprovalBadge((string) ($approval['action_key'] ?? ''));
                            ?>
                            <a href="?<?php echo htmlspecialchars(http_build_query(array_filter($query + ['id' => (int) $approval['id']], static fn ($value) => $value !== '' && $value !== 0))); ?>" style="display:block;padding:1rem;border:1px solid <?php echo (int) ($selectedApproval['id'] ?? 0) === (int) $approval['id'] ? '#93c5fd' : 'var(--border-color)'; ?>;border-radius:12px;background:<?php echo (int) ($selectedApproval['id'] ?? 0) === (int) $approval['id'] ? '#eff6ff' : '#fff'; ?>;text-decoration:none;color:inherit;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                                    <div>
                                        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                            <strong style="color:#0f172a;"><?php echo htmlspecialchars((string) ($approval['action_key'] ?? 'approval')); ?></strong>
                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $statusColor; ?>15;color:<?php echo $statusColor; ?>;font-size:12px;font-weight:600;"><?php echo htmlspecialchars(aiUiDecisionMeta(match ((string) ($approval['status'] ?? 'pending')) { 'pending' => 'approval_required', 'approved' => 'allow', 'rejected', 'expired' => 'blocked', default => (string) ($approval['status'] ?? 'pending'), })['label']); ?></span>
                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $actionColor; ?>12;color:<?php echo $actionColor; ?>;font-size:12px;font-weight:600;"><?php echo htmlspecialchars((string) ($approval['diagnostics']['classification'] ?? 'unknown')); ?></span>
                                        </div>
                                        <div style="margin-top:.45rem;color:#475569;"><?php echo htmlspecialchars((string) ($approval['reason'] ?? 'Approval required')); ?></div>
                                        <div style="margin-top:.4rem;color:#64748b;font-size:.85rem;">
                                            <?php echo htmlspecialchars((string) ($approval['preview']['summary'] ?? '')); ?><br>
                                            <span style="display:inline-block;margin-top:.35rem;"><a href="<?php echo htmlspecialchars(commercialApprovalDiagnosticsLink($approval)); ?>">Open diagnostics</a></span>
                                        </div>
                                    </div>
                                    <div style="text-align:right;color:#64748b;font-size:.85rem;">
                                        <div><?php echo htmlspecialchars((string) ($approval['requested_by_type'] ?? 'system')); ?></div>
                                        <div style="margin-top:.25rem;"><?php echo htmlspecialchars((string) ($approval['created_at'] ?? '')); ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <?php if (!$selectedApproval): ?>
                    <p style="margin:0;color:#64748b;">Select an approval to inspect details.</p>
                <?php else: ?>
                    <?php
                    $detail = $selectedApproval;
                    $diagnostics = (array) ($detail['diagnostics'] ?? []);
                    $preview = (array) (($selectedPreview['preview'] ?? null) ?: ($detail['preview'] ?? []));
                    $payload = (array) ($detail['payload'] ?? []);
                    $learningReview = (array) ($diagnostics['learning_review'] ?? $preview['learning_review'] ?? []);
                    ?>
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
                        <div>
                            <h2 style="margin:0;"><?php echo htmlspecialchars((string) ($detail['action_key'] ?? 'approval')); ?></h2>
                            <p style="margin:.35rem 0 0 0;color:#64748b;"><?php echo htmlspecialchars((string) ($detail['reason'] ?? 'Approval required')); ?></p>
                        </div>
                        <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:<?php echo commercialApprovalBadge((string) ($detail['status'] ?? 'pending')); ?>15;color:<?php echo commercialApprovalBadge((string) ($detail['status'] ?? 'pending')); ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars(aiUiDecisionMeta(match ((string) ($detail['status'] ?? 'pending')) { 'pending' => 'approval_required', 'approved' => 'allow', 'rejected', 'expired' => 'blocked', default => (string) ($detail['status'] ?? 'pending'), })['label']); ?></span>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:1rem;">
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Requested by</div>
                            <div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($diagnostics['requested_by_label'] ?? ($detail['requested_by_type'] ?? 'system'))); ?></div>
                        </div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Created</div>
                            <div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($detail['created_at'] ?? '')); ?></div>
                        </div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Linked records</div>
                            <div style="font-weight:700;color:#0f172a;margin-top:.35rem;">
                                Deal: <?php echo commercialApprovalLink('Deal', 'deal_view.php', !empty($detail['deal_id']) ? (int) $detail['deal_id'] : null); ?><br>
                                Invoice: <?php echo commercialApprovalLink('Invoice', 'invoice_view.php', !empty($detail['invoice_id']) ? (int) $detail['invoice_id'] : null); ?>
                            </div>
                        </div>
                        <div style="padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Reason category</div>
                            <div style="font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($diagnostics['classification'] ?? 'unknown')); ?></div>
                        </div>
                    </div>

                    <div style="display:grid;gap:1rem;">
                        <div>
                            <h3 style="margin:0 0 .5rem 0;">Action preview</h3>
                            <?php if (!empty($detail['payload_error'])): ?>
                                <div style="margin-bottom:.75rem;padding:.75rem 1rem;border:1px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#92400e;">
                                    This approval has a malformed stored payload. Preview and diagnostics may be incomplete, but you can still inspect the raw payload and reject it safely.
                                </div>
                            <?php endif; ?>
                            <div style="padding:1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;color:#334155;">
                                <?php echo htmlspecialchars((string) ($preview['summary'] ?? 'No preview available.')); ?>
                            </div>
                        </div>

                        <div>
                            <h3 style="margin:0 0 .5rem 0;">Diagnostics</h3>
                            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;">
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Reason codes</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars(implode(', ', array_map('aiUiWarningLabel', (array) ($diagnostics['reason_codes'] ?? []))) ?: 'none'); ?></div>
                                </div>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Channel / recipient</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars((string) (($diagnostics['channel'] ?? 'email') . ' / ' . (($diagnostics['recipient'] ?? '') !== '' ? $diagnostics['recipient'] : 'not set'))); ?></div>
                                </div>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Document / stage</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars((string) (($diagnostics['linked_document_type'] ?? 'document') . ' / ' . (($diagnostics['stage'] ?? '') !== '' ? $diagnostics['stage'] : 'n/a'))); ?></div>
                                </div>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Risk flags</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars(implode(', ', (array) ($diagnostics['risk_flags'] ?? [])) ?: 'none'); ?></div>
                                </div>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Discount / total delta</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars(number_format((float) ($diagnostics['requested_discount_percent'] ?? 0), 2) . '% / ' . number_format((float) ($diagnostics['requested_total_change_percent'] ?? 0), 2) . '%'); ?></div>
                                </div>
                                <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Assistant confidence</div>
                                    <div style="margin-top:.35rem;color:#0f172a;font-weight:600;"><?php echo isset($diagnostics['assistant_confidence']) ? htmlspecialchars(number_format((float) $diagnostics['assistant_confidence'], 2)) : 'n/a'; ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($learningReview)): ?>
                            <div>
                                <h3 style="margin:0 0 .5rem 0;">Learning evidence</h3>
                                <div style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                                    <div style="font-size:.9rem;color:#475569;">
                                        Preferred channel: <strong><?php echo htmlspecialchars((string) ($learningReview['tenant_policy']['preferred_channel'] ?? 'none')); ?></strong><br>
                                        Preferred document type: <strong><?php echo htmlspecialchars((string) ($learningReview['tenant_policy']['preferred_document_type'] ?? 'none')); ?></strong><br>
                                        Reversal risk: <strong><?php echo htmlspecialchars(number_format((float) ($learningReview['tenant_policy']['reversal_risk'] ?? 0), 2)); ?></strong>
                                    </div>
                                    <?php if (!empty($learningReview['similar_examples'])): ?>
                                        <div style="margin-top:.65rem;display:grid;gap:.5rem;">
                                            <?php foreach (array_slice((array) $learningReview['similar_examples'], 0, 3) as $example): ?>
                                                <div style="padding:.65rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
                                                    <strong><?php echo htmlspecialchars((string) ($example['feature_summary']['action_key'] ?? 'example')); ?></strong>
                                                    <div style="font-size:.8rem;color:#64748b;">
                                                        score <?php echo htmlspecialchars(number_format((float) ($example['score'] ?? 0), 2)); ?> •
                                                        <?php echo htmlspecialchars((string) ($example['feature_summary']['deal_stage'] ?? '')); ?> •
                                                        <?php echo htmlspecialchars((string) ($example['feature_summary']['channel'] ?? '')); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h3 style="margin:0 0 .5rem 0;">Threshold snapshot</h3>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:220px;overflow:auto;"><?php echo htmlspecialchars((string) json_encode((array) ($diagnostics['thresholds'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </div>

                        <details>
                            <summary style="cursor:pointer;font-weight:700;">Raw payload</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:260px;overflow:auto;margin-top:.75rem;"><?php echo htmlspecialchars((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>

                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-start;">
                            <?php if (($detail['status'] ?? '') === 'pending'): ?>
                                <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="approval_id" value="<?php echo (int) $detail['id']; ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="btn-premium-primary">Approve and execute</button>
                                </form>
                                <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="approval_id" value="<?php echo (int) $detail['id']; ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <input type="text" name="reason" placeholder="Optional reject reason" style="padding:.65rem;border:1px solid var(--border-color);border-radius:8px;min-width:220px;">
                                    <button type="submit" class="btn-premium-secondary">Reject</button>
                                </form>
                            <?php endif; ?>
                            <a href="deal_view.php?id=<?php echo (int) ($detail['deal_id'] ?? 0); ?>" class="btn-premium-secondary<?php echo empty($detail['deal_id']) ? ' disabled' : ''; ?>">Open deal</a>
                            <a href="invoice_view.php?id=<?php echo (int) ($detail['invoice_id'] ?? 0); ?>" class="btn-premium-secondary<?php echo empty($detail['invoice_id']) ? ' disabled' : ''; ?>">Open invoice</a>
                            <a href="<?php echo htmlspecialchars(commercialApprovalDiagnosticsLink($detail)); ?>" class="btn-premium-secondary">Open diagnostics</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_COMMERCIAL_APPROVALS, 'How to use Commercial Approvals', $commercialApprovalsGuideVideoUrl); ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
