<?php
/**
 * Target View Page
 */

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
use CRM\Database;
use CRM\Modules\TargetAdvice;
use CRM\Modules\Targets;
use CRM\Security;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\TargetCoordinator;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();
if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$targetsModule = new Targets();
$adviceModule = new TargetAdvice();
$intelligenceService = new TargetIntelligenceService();
$targetCoordinator = new TargetCoordinator();
$user = Auth::user();
$targetId = (int) ($_GET['id'] ?? 0);
if ($targetId <= 0) {
    $targetId = $targetsModule->findTargetIdByLookup([
        'title' => (string) ($_GET['lookup_title'] ?? ''),
        'user_id' => (int) ($_GET['lookup_owner'] ?? 0),
        'target_date' => (string) ($_GET['lookup_date'] ?? ''),
        'description' => (string) ($_GET['lookup_description'] ?? ''),
    ]);
}

if (!$targetId) {
    header('Location: ' . $basePath . '/targets.php');
    exit;
}

$target = $targetsModule->getById($targetId);
if (!$target) {
    header('Location: ' . $basePath . '/targets.php');
    exit;
}

if (!$targetsModule->canViewTarget($target, $user)) {
    header('Location: ' . $basePath . '/targets.php?error=forbidden');
    exit;
}

$canEditTarget = $targetsModule->canEditTarget($target, $user);
$canDeleteTarget = $targetsModule->canDeleteTarget($target, $user);

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!$canEditTarget) {
        $error = 'You have read-only access to this shared target.';
    } else {
        try {
            if ($_POST['action'] === 'update_progress') {
                $targetsModule->updateProgress($targetId, (float) ($_POST['current_value'] ?? 0));
                $success = 'Progress updated successfully.';
            } elseif ($_POST['action'] === 'mark_complete') {
                $targetsModule->markComplete($targetId);
                $success = 'Target marked as complete.';
            } elseif ($_POST['action'] === 'generate_advice') {
                $adviceModule->generateAdvice($targetId);
                $success = 'Advice refreshed.';
            } elseif ($_POST['action'] === 'refresh_intelligence') {
                $intelligenceService->syncTarget($targetId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0));
                $success = 'Target intelligence refreshed.';
            } elseif ($_POST['action'] === 'set_automation_mode') {
                $targetCoordinator->setMode($targetId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0), (string) ($_POST['automation_mode'] ?? 'manual'), (int) ($user['id'] ?? 0), isset($_POST['state_version']) ? (int) $_POST['state_version'] : null);
                $success = 'Target automation preference updated.';
            } elseif ($_POST['action'] === 'reopen') {
                $targetCoordinator->reopen($targetId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0), (int) ($user['id'] ?? 0), (string) ($_POST['reason'] ?? ''), isset($_POST['state_version']) ? (int) $_POST['state_version'] : null);
                $success = 'Target reopened. Previously accepted evidence will not complete it again.';
            } elseif ($_POST['action'] === 'approve_proposal') {
                $targetCoordinator->approveProposal((int) ($_POST['proposal_id'] ?? 0), (int) (WorkspaceContext::currentWorkspaceId() ?? 0), (int) ($user['id'] ?? 0));
                $success = 'Clarity recommendation approved.';
            } elseif ($_POST['action'] === 'reject_proposal') {
                $targetCoordinator->rejectProposal((int) ($_POST['proposal_id'] ?? 0), (int) (WorkspaceContext::currentWorkspaceId() ?? 0), (int) ($user['id'] ?? 0));
                $success = 'Clarity recommendation kept open.';
            }
            $target = $targetsModule->getById($targetId);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$target = $canEditTarget ? ($intelligenceService->syncTarget($targetId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0)) ?: $target) : $target;
$target = $targetsModule->getById($targetId) ?: $target;
$v2 = $targetCoordinator->payload($target, $canEditTarget);
$measurement = (array) ($target['measurement'] ?? []);
$automation = (array) ($v2['automation'] ?? []);
$completion = (array) ($v2['completion'] ?? []);
$source = (array) ($v2['source'] ?? []);
$latestAdvice = $adviceModule->getLatestAdvice($targetId);
if ($canEditTarget && !$latestAdvice && ($target['status'] ?? 'active') === 'active') {
    try {
        $adviceModule->generateAdvice($targetId);
        $latestAdvice = $adviceModule->getLatestAdvice($targetId);
    } catch (\Throwable $e) {
    }
}
$adviceHistory = $adviceModule->getAllAdvice($targetId, 6);
$progress = (float) ($target['progress_percentage'] ?? 0);
$daysRemaining = $target['days_remaining'] ?? null;
$statusCategory = (string) ($target['status_category'] ?? 'on_track');
$statusBadges = [
    'active' => 'badge-primary',
    'completed' => 'badge-success',
    'missed' => 'badge-danger',
    'cancelled' => 'badge-default',
];
$categoryColors = [
    'on_track' => '#10b981',
    'at_risk' => '#f59e0b',
    'behind' => '#ef4444',
    'blocked' => '#8b5cf6',
    'completed' => '#10b981',
    'missed' => '#ef4444',
];
$milestoneSummary = (array) ($target['milestone_summary'] ?? []);
$milestones = (array) ($v2['milestones'] ?? $milestoneSummary['milestones'] ?? []);
$rollupExplanation = (array) ($target['rollup_explanation'] ?? []);
$pageTitle = 'Target: ' . htmlspecialchars((string) ($target['title'] ?? '')) . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/premium-pages.css">
<style>
.target-v2-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:var(--spacing-xl)}
.target-v2-actions{display:flex;gap:8px;flex-wrap:wrap}.target-v2-actions button{min-height:40px;white-space:normal}
@media(max-width:820px){.target-v2-layout{grid-template-columns:1fr}.target-v2-sticky{position:static!important}.target-v2-metrics{grid-template-columns:1fr!important}}
</style>

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) ($target['title'] ?? '')); ?></h1>
                <p style="color: var(--charcoal-grey); display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span class="badge <?php echo $statusBadges[$target['status'] ?? 'active'] ?? 'badge-default'; ?>"><?php echo htmlspecialchars(ucfirst((string) ($target['status'] ?? 'active'))); ?></span>
                    <span class="badge badge-default"><?php echo htmlspecialchars(ucfirst((string) ($target['scope'] ?? 'personal'))); ?></span>
                    <span class="badge badge-default"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($target['progress_mode'] ?? 'manual')))); ?></span>
                    <?php if (($source['origin_type'] ?? 'manual') !== 'manual'): ?><span class="badge badge-primary">Created by <?php echo htmlspecialchars(($source['origin_type'] ?? '') === 'ai' ? 'Clarity' : ucfirst((string) $source['origin_type'])); ?></span><?php endif; ?>
                    <?php if ($statusCategory !== 'completed' && $statusCategory !== 'on_track'): ?>
                        <span class="badge" style="background: <?php echo $categoryColors[$statusCategory] ?? '#64748b'; ?>; color: white;"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($statusCategory))); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="page-header-actions">
                <?php if ($canEditTarget): ?>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/target_edit.php?id=<?php echo $targetId; ?>" class="btn-premium-secondary"><i class="fas fa-edit"></i> Edit</a>
                <?php endif; ?>
                <?php if ($canDeleteTarget): ?>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/target_delete.php?id=<?php echo $targetId; ?>" class="btn-premium-secondary" style="color:#b91c1c;border-color:#fecaca;"><i class="fas fa-trash"></i> Delete</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div style="background: #efe; border: 1px solid #cfc; color: #166534; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!$canEditTarget): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;padding:var(--spacing-md);border-radius:4px;margin-bottom:var(--spacing-md);">
                You have read-only access to this shared target.
            </div>
        <?php endif; ?>
        <?php if (($completion['completed_by'] ?? null) === 'clarity'): ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:var(--spacing-md);border-radius:8px;margin-bottom:var(--spacing-md);display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                <div><strong>Completed by Clarity</strong><div style="font-size:.9rem;"><?php echo htmlspecialchars((string) ($completion['explanation'] ?? 'Completed from verified CRM evidence.')); ?></div></div>
                <?php if ($canEditTarget): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="state_version" value="<?php echo (int) ($target['state_version'] ?? 1); ?>"><input type="hidden" name="reason" value="Completion needs review"><button class="btn-premium-secondary" type="submit">Undo / Reopen</button></form><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="target-v2-layout">
            <div>
                <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg);">
                    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-lg);">Progress</h2>
                    <div style="font-size:.8rem;color:#64748b;margin-top:calc(var(--spacing-md) * -1);margin-bottom:var(--spacing-md);">Scoring and forecast are based on the configured measurement window.</div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: var(--spacing-sm);">
                        <span style="color: #64748b;"><?php echo number_format((float) ($target['current_value'] ?? 0), 2); ?><?php echo htmlspecialchars((string) ($target['unit'] ?? '')); ?> / <?php echo number_format((float) ($target['target_value'] ?? 0), 2); ?><?php echo htmlspecialchars((string) ($target['unit'] ?? '')); ?></span>
                        <strong style="color: <?php echo $categoryColors[$statusCategory] ?? '#667eea'; ?>;"><?php echo number_format($progress, 1); ?>%</strong>
                    </div>
                    <div style="width: 100%; height: 24px; background: #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: var(--spacing-md);">
                        <div style="width: <?php echo min(100, $progress); ?>%; height: 100%; background: <?php echo $categoryColors[$statusCategory] ?? '#667eea'; ?>;"></div>
                    </div>
                    <div class="target-v2-metrics" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--spacing-md);">
                        <div style="background: #f8fafc; padding: var(--spacing-md); border-radius: 8px;">
                            <div style="font-size: 0.8rem; color: #64748b;">Forecast</div>
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo number_format(((float) ($target['forecast_score'] ?? 0)) * 100, 0); ?>%</div>
                        </div>
                        <div style="background: #f8fafc; padding: var(--spacing-md); border-radius: 8px;">
                            <div style="font-size: 0.8rem; color: #64748b;">Projected Finish</div>
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo !empty($target['projected_completion_date']) ? htmlspecialchars(date('M d, Y', strtotime((string) $target['projected_completion_date']))) : 'Not enough data'; ?></div>
                        </div>
                        <div style="background: #f8fafc; padding: var(--spacing-md); border-radius: 8px;">
                            <div style="font-size: 0.8rem; color: #64748b;">Days Remaining</div>
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo $daysRemaining !== null ? htmlspecialchars((string) $daysRemaining) : '—'; ?></div>
                        </div>
                    </div>
                    <div style="margin-top: var(--spacing-lg); color: #475569;"><?php echo htmlspecialchars((string) ($target['pace_summary'] ?? '')); ?></div>
                </div>

                <?php if (($target['progress_mode'] ?? 'manual') !== 'manual' || ($automation['target_mode'] ?? 'manual') !== 'manual'): ?>
                <div style="background:white;padding:var(--spacing-xl);border:1px solid var(--border-color);border-radius:8px;margin-bottom:var(--spacing-lg);">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;"><div><h2 style="margin:0 0 6px;color:var(--midnight-black);">Completion evidence</h2><div style="color:#64748b;"><?php echo htmlspecialchars((string) ($measurement['explanation'] ?? '')); ?></div></div><span class="badge badge-default"><?php echo htmlspecialchars(ucfirst((string) ($automation['target_mode'] ?? 'manual'))); ?> · workspace <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($automation['workspace_mode'] ?? 'review'))); ?></span></div>
                    <div class="target-v2-metrics" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px;"><div style="background:#f8fafc;padding:12px;border-radius:8px;"><small>Calculated</small><div style="font-weight:700;"><?php echo number_format((float) ($measurement['rollup_value'] ?? 0), 2); ?></div></div><div style="background:#f8fafc;padding:12px;border-radius:8px;"><small>Manual adjustment</small><div style="font-weight:700;"><?php echo number_format((float) ($measurement['manual_adjustment'] ?? 0), 2); ?></div></div><div style="background:#f8fafc;padding:12px;border-radius:8px;"><small>Matching records</small><div style="font-weight:700;"><?php echo (int) ($measurement['matching_count'] ?? 0); ?></div></div></div>
                    <?php foreach ((array) ($measurement['missing_configuration'] ?? []) as $item): ?><div style="margin-top:10px;color:#92400e;background:#fffbeb;padding:10px;border-radius:6px;">Missing: <?php echo htmlspecialchars((string) $item); ?></div><?php endforeach; ?>
                    <?php foreach ((array) ($measurement['conflicts'] ?? []) as $item): ?><div style="margin-top:10px;color:#991b1b;background:#fef2f2;padding:10px;border-radius:6px;">Conflict: <?php echo htmlspecialchars((string) $item); ?></div><?php endforeach; ?>
                    <?php if (!empty($automation['proposal'])): $proposal = (array) $automation['proposal']; ?><div style="margin-top:16px;border-top:1px solid var(--border-color);padding-top:16px;"><strong>Clarity recommends review</strong><p><?php echo htmlspecialchars((string) ($proposal['explanation'] ?? '')); ?></p><?php if ($canEditTarget): ?><div class="target-v2-actions"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="approve_proposal"><input type="hidden" name="proposal_id" value="<?php echo (int) ($proposal['id'] ?? 0); ?>"><button class="btn-premium-primary" type="submit">Approve</button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reject_proposal"><input type="hidden" name="proposal_id" value="<?php echo (int) ($proposal['id'] ?? 0); ?>"><button class="btn-premium-secondary" type="submit">Keep open</button></form></div><?php endif; ?></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg);">
                    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-lg);">Target Intelligence</h2>
                    <div style="display: grid; gap: var(--spacing-md);">
                        <div>
                            <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;">Rollup Source</div>
                            <div style="font-weight: 600; color: var(--midnight-black);"><?php echo htmlspecialchars((string) ($target['rollup_source_label'] ?? 'Manual progress')); ?></div>
                        </div>
                        <?php if (!empty($target['blockers'])): ?>
                            <div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;">Blockers</div>
                                <ul style="margin: 0; padding-left: 18px; color: #334155;">
                                    <?php foreach ((array) $target['blockers'] as $blocker): ?>
                                        <li><?php echo htmlspecialchars((string) $blocker); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($target['next_best_actions'])): ?>
                            <div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 4px;">Next Best Actions</div>
                                <ul style="margin: 0; padding-left: 18px; color: #334155;">
                                    <?php foreach ((array) $target['next_best_actions'] as $action): ?>
                                        <li><?php echo htmlspecialchars((string) $action); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg);">
                    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-lg);">Milestones</h2>
                    <?php if ($milestones === []): ?>
                        <div style="color: #64748b;">No milestones configured.</div>
                    <?php else: ?>
                        <div style="display: grid; gap: var(--spacing-sm);">
                            <?php foreach ($milestones as $milestone): ?>
                                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-md); display: flex; justify-content: space-between; gap: var(--spacing-md);">
                                    <div>
                                        <div style="font-weight: 600; color: var(--midnight-black);"><?php echo htmlspecialchars((string) ($milestone['title'] ?? 'Checkpoint')); ?></div>
                                        <div style="font-size: 0.85rem; color: #64748b;">
                                            <?php echo number_format((float) ($milestone['current_value'] ?? 0), 2); ?> / <?php echo number_format((float) ($milestone['target_value'] ?? 0), 2); ?>
                                            <?php if (!empty($milestone['due_date'])): ?>
                                                · due <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $milestone['due_date']))); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo (($milestone['status'] ?? 'pending') === 'completed') ? 'badge-success' : 'badge-default'; ?>"><?php echo htmlspecialchars(ucfirst((string) ($milestone['status'] ?? 'pending'))); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg);">
                    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-lg);">Rollup Evidence</h2>
                    <?php if (empty($rollupExplanation['evidence'])): ?>
                        <div style="color: #64748b;">No supporting records available yet for this target source.</div>
                    <?php else: ?>
                        <div style="display: grid; gap: var(--spacing-sm);">
                            <?php foreach ((array) $rollupExplanation['evidence'] as $evidence): ?>
                                <a href="<?php echo htmlspecialchars((string) ($evidence['url'] ?? '#')); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 8px; text-decoration: none; color: inherit;">
                                    <span><?php echo htmlspecialchars((string) ($evidence['label'] ?? 'Record')); ?></span>
                                    <span style="font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars((string) ($evidence['status'] ?? '')); ?><?php echo isset($evidence['value']) ? ' · ' . number_format((float) $evidence['value'], 2) : ''; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
                        <h2 style="color: var(--midnight-black); margin: 0;">Advice History</h2>
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="generate_advice">
                            <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; cursor: pointer;">Refresh Advice</button>
                        </form>
                    </div>
                    <?php if ($adviceHistory === []): ?>
                        <div style="color: #64748b;">No advice available yet.</div>
                    <?php else: ?>
                        <div style="display: grid; gap: var(--spacing-md);">
                            <?php foreach ($adviceHistory as $advice): ?>
                                <div style="background: #f8fafc; padding: var(--spacing-lg); border-radius: 8px; border-left: 4px solid var(--accent-blue);">
                                    <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;"><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string) ($advice['generated_at'] ?? 'now')))); ?></div>
                                    <div style="white-space: pre-wrap; color: #0f172a;"><?php echo htmlspecialchars((string) ($advice['advice_text'] ?? '')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="target-v2-sticky" style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: var(--spacing-lg); position: sticky; top: 88px;">
                    <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-lg);">Quick Actions</h3>
                    <?php if ($canEditTarget): ?>
                        <?php if (($target['status'] ?? 'active') === 'active' && ($target['progress_mode'] ?? 'manual') !== 'auto_rollup'): ?>
                            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="update_progress">
                                <label for="current_value" style="font-size: 0.8rem; color: #64748b;"><?php echo ($target['progress_mode'] ?? 'manual') === 'hybrid' ? 'Set total progress (calculated + adjustment)' : 'Update progress'; ?></label>
                                <input type="number" name="current_value" step="0.01" value="<?php echo htmlspecialchars((string) ($target['current_value'] ?? 0)); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm); border: none; border-radius: 4px; cursor: pointer;">Update</button>
                            </form>
                        <?php endif; ?>
                        <?php if (($target['status'] ?? '') === 'completed'): ?><form method="POST" style="margin-bottom:var(--spacing-sm);display:grid;gap:8px;"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="state_version" value="<?php echo (int) ($target['state_version'] ?? 1); ?>"><input name="reason" required placeholder="Why should this target reopen?" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><button class="btn-premium-secondary" type="submit">Reopen target</button></form><?php endif; ?>
                        <?php if (($target['status'] ?? '') === 'active'): ?><form method="POST" style="margin-bottom:var(--spacing-sm);display:grid;gap:8px;"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="set_automation_mode"><input type="hidden" name="state_version" value="<?php echo (int) ($target['state_version'] ?? 1); ?>"><label style="font-size:.8rem;color:#64748b;">Clarity automation</label><select name="automation_mode" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><option value="manual" <?php echo ($automation['target_mode'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option><option value="review" <?php echo ($automation['target_mode'] ?? '') === 'review' ? 'selected' : ''; ?>>Review recommendations</option><option value="auto" <?php echo ($automation['target_mode'] ?? '') === 'auto' ? 'selected' : ''; ?>>Let Clarity complete</option></select><button class="btn-premium-secondary" type="submit">Save automation</button></form><?php endif; ?>
                        <?php if (($target['status'] ?? 'active') === 'active'): ?>
                            <form method="POST" action="" style="margin-bottom: var(--spacing-sm);">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="mark_complete">
                                <button type="submit" style="width: 100%; background: #10b981; color: white; padding: var(--spacing-sm); border: none; border-radius: 4px; cursor: pointer;">Mark as Complete</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="" style="margin-bottom: var(--spacing-sm);">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="refresh_intelligence">
                            <button type="submit" style="width: 100%; background: #0f172a; color: white; padding: var(--spacing-sm); border: none; border-radius: 4px; cursor: pointer;">Refresh Intelligence</button>
                        </form>
                        <a href="<?php echo htmlspecialchars($basePath); ?>/target_edit.php?id=<?php echo $targetId; ?>" style="display: block; text-align: center; padding: var(--spacing-sm); color: var(--accent-blue); text-decoration: none; border: 1px solid var(--border-color); border-radius: 4px;">Edit Target</a>
                    <?php else: ?>
                        <div style="color:#64748b;line-height:1.5;">This target is shared for visibility, but only its owner or a user with full target management access can change it.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
