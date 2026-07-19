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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\WorkflowAutomationProposalService;
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
Authorization::requirePermission('workflow_automation.approvals');

$service = new WorkflowAutomationProposalService();
$userId = (int) ($user['id'] ?? 0);
$message = null;
$error = null;

$query = [
    'status' => trim((string) ($_GET['status'] ?? 'pending')),
    'proposal_type' => trim((string) ($_GET['proposal_type'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $proposalId = (int) ($_POST['proposal_id'] ?? 0);
        $decision = trim((string) ($_POST['decision'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        try {
            if ($proposalId > 0 && $decision === 'approve') {
                if ($notes !== '') {
                    $service->approve($proposalId, $userId, $notes);
                }
                $result = $service->applyProposal($proposalId, $userId);
                if ($result) {
                    $message = 'Workflow proposal approved and applied.';
                } else {
                    $error = 'Proposal could not be applied.';
                }
            } elseif ($proposalId > 0 && $decision === 'reject') {
                $proposal = $service->reject($proposalId, $userId, $notes !== '' ? $notes : 'Rejected from workflow approvals');
                if ($proposal) {
                    $message = 'Workflow proposal rejected.';
                } else {
                    $error = 'Proposal could not be rejected.';
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$proposals = $service->listAll($query + ['limit' => 100]);
$metrics = $service->buildSummaryMetrics($service->listAll(['status' => 'pending', 'limit' => 200]));
$selectedProposalId = (int) ($_GET['id'] ?? 0);
$selectedProposal = $selectedProposalId > 0 ? $service->getById($selectedProposalId) : ($proposals[0] ?? null);

$pageTitle = 'Workflow Approvals - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Workflow Approvals</h1>
                <p>Review AI-created and AI-updated workflows before they go live.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_learning_review.php?domain=workflow_execution" class="btn-premium-secondary">Learning review</a>
                <a href="settings.php?tab=workflow_automation" class="btn-premium-secondary">Workflow automation settings</a>
                <a href="workflows.php" class="btn-premium-secondary">Back to workflows</a>
            </div>
        </div>

        <?php if ($message): ?><div class="content-card" style="background:#d1fae5;border-color:#10b981;color:#065f46;margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="content-card" style="background:#fee2e2;border-color:#ef4444;color:#991b1b;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Pending approvals</div><div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['pending_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Customer-facing</div><div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['customer_facing_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Create proposals</div><div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['create_count'] ?? 0); ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Update proposals</div><div style="font-size:28px;font-weight:700;color:#0f172a;margin-top:.35rem;"><?php echo (int) ($metrics['update_count'] ?? 0); ?></div></div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.85rem;align-items:end;">
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Status</label>
                    <select name="status" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'applied' => 'Applied'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $query['status'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Type</label>
                    <select name="proposal_type" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <?php foreach (['' => 'All', 'create' => 'Create', 'update' => 'Update'] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $query['proposal_type'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.4rem;font-weight:600;">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($query['search']); ?>" placeholder="workflow or id" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button type="submit" class="btn-premium-primary">Filter</button>
                    <a href="workflow_approvals.php" class="btn-premium-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:1rem;align-items:start;">
            <div class="content-card">
                <?php if (empty($proposals)): ?>
                    <p style="margin:0;color:#64748b;">No workflow proposals found for the current filters.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($proposals as $proposal): ?>
                            <a href="?<?php echo htmlspecialchars(http_build_query(array_filter($query + ['id' => (int) $proposal['id']], static fn ($value) => $value !== ''))); ?>" style="display:block;padding:1rem;border:1px solid <?php echo (int) ($selectedProposal['id'] ?? 0) === (int) $proposal['id'] ? '#93c5fd' : 'var(--border-color)'; ?>;border-radius:12px;background:<?php echo (int) ($selectedProposal['id'] ?? 0) === (int) $proposal['id'] ? '#eff6ff' : '#fff'; ?>;text-decoration:none;color:inherit;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                                    <div>
                                        <strong style="color:#0f172a;"><?php echo htmlspecialchars((string) ($proposal['target_workflow_name'] ?? 'Workflow proposal')); ?></strong>
                                        <div style="margin-top:.35rem;color:#475569;"><?php echo htmlspecialchars((string) ($proposal['proposal_type'] ?? 'create')); ?> proposal<?php if (!empty($proposal['workflow_name'])): ?> for <?php echo htmlspecialchars((string) $proposal['workflow_name']); ?><?php endif; ?></div>
                                        <div style="margin-top:.35rem;color:#64748b;font-size:.85rem;">
                                            mode <?php echo htmlspecialchars((string) ($proposal['decision_mode'] ?? 'suggest_only')); ?>
                                            <?php if (!empty($proposal['confidence_score'])): ?> • confidence <?php echo htmlspecialchars(number_format((float) $proposal['confidence_score'], 2)); ?><?php endif; ?>
                                            <?php if (!empty($proposal['risk_summary']['customer_facing'])): ?> • customer-facing<?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;color:#64748b;font-size:.85rem;">
                                        <div><?php echo htmlspecialchars((string) ($proposal['status'] ?? 'pending')); ?></div>
                                        <div style="margin-top:.25rem;"><?php echo htmlspecialchars((string) ($proposal['created_at'] ?? '')); ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <?php if (!$selectedProposal): ?>
                    <p style="margin:0;color:#64748b;">Select a workflow proposal to inspect details.</p>
                <?php else: ?>
                    <?php
                    $diff = (array) ($selectedProposal['diff_summary'] ?? []);
                    $risk = (array) ($selectedProposal['risk_summary'] ?? []);
                    $actions = (array) ($selectedProposal['action_summary'] ?? []);
                    $issues = (array) ($selectedProposal['validation_issues'] ?? []);
                    $decisionSnapshot = (array) ($selectedProposal['decision_snapshot'] ?? []);
                    ?>
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem;">
                        <div>
                            <h2 style="margin:0;"><?php echo htmlspecialchars((string) ($selectedProposal['target_workflow_name'] ?? 'Workflow proposal')); ?></h2>
                            <p style="margin:.35rem 0 0;color:#64748b;"><?php echo htmlspecialchars((string) ($selectedProposal['proposal_type'] ?? 'create')); ?> proposal • status <?php echo htmlspecialchars((string) ($selectedProposal['status'] ?? 'pending')); ?></p>
                        </div>
                        <?php if (!empty($selectedProposal['confidence_score'])): ?>
                            <div style="padding:.55rem .8rem;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:700;">Confidence <?php echo htmlspecialchars(number_format((float) $selectedProposal['confidence_score'], 2)); ?></div>
                        <?php endif; ?>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin-bottom:1rem;">
                        <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Action types</div>
                            <div style="margin-top:.35rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars(implode(', ', (array) ($actions['action_types'] ?? [])) ?: 'none'); ?></div>
                        </div>
                        <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Trigger</div>
                            <div style="margin-top:.35rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($actions['trigger_type'] ?? 'n/a')); ?></div>
                        </div>
                        <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Diff summary</div>
                            <div style="margin-top:.35rem;color:#0f172a;font-weight:700;">
                                Nodes <?php echo (int) ($diff['current_node_count'] ?? 0); ?> → <?php echo (int) ($diff['proposed_node_count'] ?? 0); ?><br>
                                Edges <?php echo (int) ($diff['current_edge_count'] ?? 0); ?> → <?php echo (int) ($diff['proposed_edge_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Governance</div>
                            <div style="margin-top:.35rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($selectedProposal['governance_decision'] ?? ($decisionSnapshot['governance_decision'] ?? 'n/a'))); ?></div>
                        </div>
                        <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Risk flags</div>
                            <div style="margin-top:.35rem;font-weight:700;color:#0f172a;">
                                <?php echo !empty($risk['customer_facing']) ? 'Customer-facing' : 'Internal-only'; ?>
                                <?php if (!empty($risk['high_risk'])): ?> • High-risk actions<?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid;gap:1rem;">
                        <div>
                            <h3 style="margin:0 0 .5rem 0;">Graph validation issues</h3>
                            <?php if (empty($issues)): ?>
                                <p style="margin:0;color:#64748b;">No validation issues detected.</p>
                            <?php else: ?>
                                <ul style="margin:0;padding-left:1rem;color:#334155;">
                                    <?php foreach ($issues as $issue): ?>
                                        <li><?php echo htmlspecialchars((string) (($issue['level'] ?? 'info') . ': ' . ($issue['message'] ?? 'Issue'))); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3 style="margin:0 0 .5rem 0;">Decision reasons</h3>
                            <?php if (empty($decisionSnapshot['reasons'])): ?>
                                <p style="margin:0;color:#64748b;">No explicit decision reasons were captured.</p>
                            <?php else: ?>
                                <ul style="margin:0;padding-left:1rem;color:#334155;">
                                    <?php foreach ((array) $decisionSnapshot['reasons'] as $reason): ?>
                                        <li><?php echo htmlspecialchars((string) $reason); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <details open>
                            <summary style="cursor:pointer;font-weight:700;">Diff and proposal payload</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:260px;overflow:auto;margin-top:.75rem;"><?php echo htmlspecialchars((string) json_encode([
                                'diff_summary' => $selectedProposal['diff_summary'] ?? [],
                                'risk_summary' => $selectedProposal['risk_summary'] ?? [],
                                'decision_snapshot' => $selectedProposal['decision_snapshot'] ?? [],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>

                        <details>
                            <summary style="cursor:pointer;font-weight:700;">Proposed graph</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;max-height:260px;overflow:auto;margin-top:.75rem;"><?php echo htmlspecialchars((string) json_encode($selectedProposal['proposal_graph'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>

                        <?php if (($selectedProposal['status'] ?? '') === 'pending'): ?>
                            <div style="display:grid;gap:.75rem;">
                                <form method="POST" style="display:grid;gap:.65rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="proposal_id" value="<?php echo (int) $selectedProposal['id']; ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <input type="text" name="notes" placeholder="Optional approval note" style="padding:.65rem;border:1px solid var(--border-color);border-radius:8px;min-width:220px;">
                                    <button type="submit" class="btn-premium-primary">Approve and apply</button>
                                </form>
                                <form method="POST" style="display:grid;gap:.65rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="proposal_id" value="<?php echo (int) $selectedProposal['id']; ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <input type="text" name="notes" placeholder="Optional reject note" style="padding:.65rem;border:1px solid var(--border-color);border-radius:8px;min-width:220px;">
                                    <button type="submit" class="btn-premium-secondary">Reject</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
