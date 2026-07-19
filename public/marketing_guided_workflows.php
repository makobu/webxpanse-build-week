<?php
/**
 * Marketing guided workflows.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to update guided workflows.');
        }

        $action = (string) ($_POST['action'] ?? 'refresh');
        if ($action === 'refresh') {
            $marketing->refreshGuidedMarketingWorkflows((int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_guided_workflows.php?success=refreshed');
            exit;
        }

        $workflowKey = (string) ($_POST['workflow_key'] ?? '');
        if ($action === 'start_wizard') {
            $marketing->startMarketingWorkflowWizard($workflowKey, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_guided_workflows.php?success=started');
            exit;
        }
        if ($action === 'archive' && !$canManageMarketing) {
            throw new RuntimeException('You do not have permission to archive guided workflows.');
        }
        $targetStatus = match ($action) {
            'complete' => 'completed',
            'dismiss' => 'dismissed',
            'archive' => 'archived',
            default => (string) ($_POST['status'] ?? 'in_progress'),
        };
        $marketing->updateGuidedMarketingWorkflowState($workflowKey, [
            'status' => $targetStatus,
            'note' => (string) ($_POST['note'] ?? ''),
        ], (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_guided_workflows.php?success=updated');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$filters = $status !== '' ? ['status' => $status] : [];
$workflows = $marketing->listGuidedMarketingWorkflows($filters);
$summary = $marketing->getGuidedMarketingWorkflowSummary();
$wizardCatalog = $marketing->getMarketingWorkflowWizardCatalog((int) ($user['id'] ?? 0));
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'completed' => 'badge-success',
        'blocked' => 'badge-danger',
        'in_progress', 'setup_needed', 'warning', 'not_started' => 'badge-warning',
        default => 'badge-default',
    };
};
$workflowCounts = (array) ($summary['counts'] ?? []);
$blockedCount = (int) ($workflowCounts['blocked'] ?? 0);
$inProgressCount = (int) ($workflowCounts['in_progress'] ?? 0);
$readyCount = (int) ($workflowCounts['ready'] ?? 0);
$completedCount = (int) ($workflowCounts['completed'] ?? 0);
$wizardCount = (int) ($wizardCatalog['counts']['total'] ?? count((array) ($wizardCatalog['wizards'] ?? [])));
$firstWorkflow = (array) ($workflows[0] ?? []);
$firstBlockedWorkflow = (array) (array_values(array_filter($workflows, static fn(array $workflow): bool => (string) ($workflow['status'] ?? '') === 'blocked'))[0] ?? []);
$firstProgressWorkflow = (array) (array_values(array_filter($workflows, static fn(array $workflow): bool => (string) ($workflow['status'] ?? '') === 'in_progress'))[0] ?? []);
$firstWizard = (array) (((array) ($wizardCatalog['wizards'] ?? []))[0] ?? []);
$firstStartableWizard = (array) (array_values(array_filter((array) ($wizardCatalog['wizards'] ?? []), static fn(array $wizard): bool => !empty($wizard['can_start'])))[0] ?? []);
$summaryTiles = [
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $blockedCount, 'tooltip' => 'Guided workflows waiting on required marketing evidence.'],
    ['icon' => 'fa-spinner', 'label' => 'Active', 'value' => (string) $inProgressCount, 'tooltip' => 'Workflows currently in progress.'],
    ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $readyCount, 'tooltip' => 'Workflows with the next step ready to act on.'],
    ['icon' => 'fa-wand-magic-sparkles', 'label' => 'Wizards', 'value' => (string) $wizardCount, 'tooltip' => 'Startable guided paths for common marketing jobs.'],
];
$stageCards = [
    [
        'label' => 'Choose Flow',
        'icon' => 'fa-route',
        'status' => $wizardCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Pick the job.',
        'tooltip' => 'Founder view: choose a guided workflow for launch, content, landing pages, exports, review approval, or performance review.',
        'href' => '#workflow-wizards',
        'action' => 'Open wizards',
    ],
    [
        'label' => 'Start Wizard',
        'icon' => 'fa-play',
        'status' => $canWriteMarketing && $firstStartableWizard !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Begin the path.',
        'tooltip' => 'Starting a wizard records progress only. External sending and publishing remain manual-first.',
        'href' => '#workflow-wizards',
        'action' => 'Start',
    ],
    [
        'label' => 'Clear Blocker',
        'icon' => 'fa-triangle-exclamation',
        'status' => $blockedCount > 0 ? 'blocked' : 'ready',
        'sentence' => 'Fix the missing step.',
        'tooltip' => 'Blocked workflows route to the exact missing prerequisite instead of hiding the capability.',
        'href' => $firstBlockedWorkflow !== [] ? '#workflow-' . (string) ($firstBlockedWorkflow['workflow_key'] ?? '') : '#workflow-board',
        'action' => 'View blocker',
    ],
    [
        'label' => 'Do Next Step',
        'icon' => 'fa-arrow-right',
        'status' => $firstProgressWorkflow !== [] || $firstWorkflow !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Follow one action.',
        'tooltip' => 'Each workflow keeps the expert checklist behind a drawer while showing one primary next action.',
        'href' => $firstProgressWorkflow !== [] ? '#workflow-' . (string) ($firstProgressWorkflow['workflow_key'] ?? '') : ($firstWorkflow !== [] ? '#workflow-' . (string) ($firstWorkflow['workflow_key'] ?? '') : '#workflow-board'),
        'action' => 'Open next',
    ],
    [
        'label' => 'Finish Or Pause',
        'icon' => 'fa-flag-checkered',
        'status' => $completedCount > 0 ? 'ready' : 'warning',
        'sentence' => 'Close the loop.',
        'tooltip' => 'Operators can complete, dismiss, or archive workflows from drawer-gated controls.',
        'href' => '#workflow-board',
        'action' => 'Review',
    ],
];
$nextActions = array_values((array) ($summary['next_actions'] ?? []));
$todayActions = array_slice(array_values(array_filter([
    $nextActions[0] ?? null,
    $firstBlockedWorkflow !== [] ? [
        'label' => 'Resolve blocked workflow',
        'href' => '#workflow-' . (string) ($firstBlockedWorkflow['workflow_key'] ?? ''),
        'description' => 'Open the blocked workflow and follow its missing step.',
    ] : null,
    $firstStartableWizard !== [] ? [
        'label' => (string) ($firstStartableWizard['primary_action'] ?? 'Start wizard'),
        'href' => '#workflow-wizards',
        'description' => (string) ($firstStartableWizard['label'] ?? 'Start the next guided workflow.'),
    ] : null,
    [
        'label' => 'Refresh guidance',
        'href' => '#workflow-tools',
        'description' => 'Recalculate workflows from current marketing records.',
    ],
    [
        'label' => 'Open task hub',
        'href' => 'marketing_task_hub.php',
        'description' => 'Turn workflow actions into working tasks.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Task Hub', 'href' => 'marketing_task_hub.php', 'hint' => 'Turn workflow guidance into assigned work.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Review the campaign operating map.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Create or review content.'],
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Record manual launch execution.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Review manual channel packages.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Review post-launch results.'],
];

$pageTitle = 'Marketing Guided Workflows - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-guided-workflows-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Marketing Guided Workflows</h1>
                <p>Workflow Board: follow one guided next step at a time.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="#workflow-wizards"><i class="fas fa-wand-magic-sparkles"></i> Open Wizards</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'refreshed'): ?><div class="alert alert-success">Guided workflows refreshed from the current Marketing data.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'started'): ?><div class="alert alert-success">Workflow wizard started. Follow the next required step before external action.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'updated'): ?><div class="alert alert-success">Guided workflow state updated.</div><?php endif; ?>

        <section class="marketing-guided-workflows-shell">
            <div class="marketing-founder-summary marketing-guided-workflows-summary" aria-label="Guided workflow summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                        <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo $h($tile['label']); ?></span>
                        <strong><?php echo $h($tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-guided-workflows-stage-grid" aria-label="Guided workflow path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-guided-workflows-stage-card <?php echo $h($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-guided-workflows-stage-body">
                            <span class="badge <?php echo $h($badgeClass($stageStatus)); ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                            <h2><?php echo $h($stage['label']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-guided-workflows-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-guided-workflows-layout">
                <main class="marketing-guided-workflows-main">
                    <section class="content-card marketing-guided-workflows-board" id="workflow-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Workflow Board</h2>
                                <p>One next step per workflow.</p>
                            </div>
                            <span class="badge <?php echo $blockedCount > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $blockedCount > 0 ? 'Blocked' : 'Clear'; ?></span>
                        </div>

                        <?php if (empty($workflows)): ?>
                            <div class="empty-state">
                                <p>No guided workflows match this filter.</p>
                                <a href="marketing_guided_workflows.php">Show all workflows</a>
                            </div>
                        <?php else: ?>
                            <div class="marketing-guided-workflows-card-grid">
                                <?php foreach ($workflows as $workflow): ?>
                                    <?php
                                    $workflowStatus = (string) ($workflow['status'] ?? 'not_started');
                                    $recommendation = (array) ($workflow['recommendation'] ?? $workflow['recommendation_json'] ?? []);
                                    $steps = (array) ($workflow['steps'] ?? $workflow['step_state_json'] ?? []);
                                    $progress = max(0, min(100, (int) ($workflow['progress_score'] ?? 0)));
                                    $workflowKey = (string) ($workflow['workflow_key'] ?? '');
                                    $tooltip = 'Expert view: workflow progress, required steps, recommendation, completion, dismissal, and archive controls.';
                                    if ($workflowStatus === 'blocked') {
                                        $tooltip = 'Blocked workflow: open the next recommended action to clear the missing requirement.';
                                    }
                                    ?>
                                    <article class="marketing-guided-workflows-card <?php echo $h($workflowStatus); ?>" id="workflow-<?php echo $h($workflowKey); ?>" tabindex="0" data-tooltip="<?php echo $h($tooltip); ?>">
                                        <div class="marketing-stage-visual">
                                            <i class="fas fa-route" aria-hidden="true"></i>
                                            <span><?php echo $progress; ?>%</span>
                                        </div>
                                        <div class="marketing-guided-workflows-card-top">
                                            <div>
                                                <span class="badge <?php echo $h($badgeClass($workflowStatus)); ?>"><?php echo $h($labelize($workflowStatus)); ?></span>
                                                <h3><?php echo $h($workflow['title'] ?? 'Guided workflow'); ?></h3>
                                            </div>
                                        </div>
                                        <progress class="marketing-guided-workflows-progress" max="100" value="<?php echo $progress; ?>" aria-label="<?php echo $progress; ?> percent complete"></progress>
                                        <p class="marketing-guided-workflows-card-copy"><?php echo $h($recommendation['message'] ?? 'Review the next recommended action.'); ?></p>
                                        <a class="btn-premium-secondary marketing-guided-workflows-card-action" href="<?php echo $h($recommendation['href'] ?? $workflow['primary_href'] ?? 'marketing_guided_workflows.php'); ?>"><?php echo $h($recommendation['label'] ?? 'Open next action'); ?></a>
                                        <details class="marketing-guided-workflows-card-tools">
                                            <summary>Steps and workflow controls</summary>
                                            <div class="marketing-guided-workflows-card-tool-body">
                                                <div class="marketing-guided-workflows-step-grid">
                                                    <?php foreach ($steps as $step): ?>
                                                        <a class="marketing-guided-workflows-step <?php echo $h($step['status'] ?? 'missing'); ?>" href="<?php echo $h($step['href'] ?? 'marketing_guided_workflows.php'); ?>">
                                                            <strong><?php echo $h($step['label'] ?? 'Step'); ?></strong>
                                                            <small><?php echo $h($labelize((string) ($step['status'] ?? 'missing'))); ?><?php echo !empty($step['required']) ? ' - required' : ' - recommended'; ?></small>
                                                            <small><?php echo $h($step['description'] ?? ''); ?></small>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="marketing-guided-workflows-control-row">
                                                    <?php if ($canWriteMarketing && !in_array($workflowStatus, ['completed', 'dismissed', 'archived'], true)): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="complete">
                                                            <input type="hidden" name="workflow_key" value="<?php echo $h($workflowKey); ?>">
                                                            <button class="btn-premium-secondary" type="submit">Mark Complete</button>
                                                        </form>
                                                        <form method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="dismiss">
                                                            <input type="hidden" name="workflow_key" value="<?php echo $h($workflowKey); ?>">
                                                            <button class="btn-premium-secondary" type="submit">Dismiss</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($canManageMarketing): ?>
                                                        <form method="POST" onsubmit="return confirm('Archive this workflow guide?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                            <input type="hidden" name="action" value="archive">
                                                            <input type="hidden" name="workflow_key" value="<?php echo $h($workflowKey); ?>">
                                                            <button class="btn-premium-secondary manage-only" type="submit">Archive</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </details>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="content-card marketing-guided-workflows-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Follow one workflow step.</p>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-item" href="<?php echo $h($action['href'] ?? 'marketing_guided_workflows.php'); ?>">
                                <strong><?php echo $h($action['label'] ?? 'Review workflow'); ?></strong>
                                <span><?php echo $h($action['description'] ?? $action['reason'] ?? 'Open the next workflow action.'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-guided-workflows-tools" id="workflow-tools">
                <summary>More workflow tools</summary>
                <div class="marketing-guided-workflows-tools-body">
                    <section class="content-card marketing-guided-workflows-wizard-card" id="workflow-wizards">
                        <div class="premium-section-header">
                            <div>
                                <h2>Workflow Wizards</h2>
                                <p>Start guided flows without crowding the board.</p>
                            </div>
                            <span class="badge badge-default"><?php echo $wizardCount; ?> wizards</span>
                        </div>
                        <div class="marketing-guided-workflows-wizard-grid">
                            <?php foreach ((array) ($wizardCatalog['wizards'] ?? []) as $wizard): ?>
                                <article class="marketing-guided-workflows-wizard-option" tabindex="0" data-tooltip="<?php echo $h($wizard['description'] ?? $wizard['best_for'] ?? 'Guided Marketing workflow.'); ?>">
                                    <div class="marketing-guided-workflows-meta">
                                        <span><?php echo $h($labelize((string) ($wizard['status'] ?? 'not_started'))); ?></span>
                                        <span><?php echo (int) ($wizard['progress_score'] ?? 0); ?>% ready</span>
                                    </div>
                                    <strong><?php echo $h($wizard['label'] ?? 'Workflow wizard'); ?></strong>
                                    <small><?php echo $h($wizard['best_for'] ?? 'Guided Marketing workflow.'); ?></small>
                                    <small><?php echo (int) ($wizard['ready_steps'] ?? 0); ?> of <?php echo max(1, (int) ($wizard['total_steps'] ?? 0)); ?> steps ready.</small>
                                    <div class="marketing-guided-workflows-control-row">
                                        <a class="btn-premium-secondary" href="<?php echo $h($wizard['primary_href'] ?? 'marketing_guided_workflows.php'); ?>">Open Work Area</a>
                                        <?php if ($canWriteMarketing && !empty($wizard['can_start'])): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                <input type="hidden" name="action" value="start_wizard">
                                                <input type="hidden" name="workflow_key" value="<?php echo $h($wizard['workflow_key'] ?? ''); ?>">
                                                <button class="btn-premium-primary" type="submit"><?php echo $h($wizard['primary_action'] ?? 'Start Wizard'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="marketing-guided-workflows-manual"><?php echo $h($wizardCatalog['recommended_product_decision'] ?? 'Workflow wizards stay manual-first.'); ?></p>
                    </section>

                    <section class="content-card marketing-guided-workflows-filter-card">
                        <div class="premium-section-header"><h2>Workflow Filter</h2></div>
                        <div class="marketing-guided-workflows-toolbar">
                            <form method="GET" class="marketing-guided-workflows-filter-form">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="">All active</option>
                                        <?php foreach (Marketing::GUIDED_WORKFLOW_STATUSES as $workflowStatus): ?>
                                            <option value="<?php echo $h($workflowStatus); ?>" <?php echo $selected($status, $workflowStatus); ?>><?php echo $h($labelize($workflowStatus)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn-premium-secondary" type="submit">Filter</button>
                                <a class="btn-premium-secondary" href="marketing_guided_workflows.php">Reset</a>
                            </form>
                            <?php if ($canWriteMarketing): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="refresh">
                                    <button class="btn-premium-primary" type="submit"><i class="fas fa-rotate"></i> Refresh Guidance</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-card marketing-guided-workflows-manual-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Manual-first Safeguard</h2>
                                <p>Workflow guidance records progress only.</p>
                            </div>
                        </div>
                        <div class="marketing-guided-workflows-safeguard-grid">
                            <div>
                                <i class="fas fa-hand-paper" aria-hidden="true"></i>
                                <strong>No external send</strong>
                                <span>Email, SMS, publishing, and ad actions still require operator action.</span>
                            </div>
                            <div>
                                <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                                <strong>Evidence first</strong>
                                <span>Blocked paths route to the missing CRM or Marketing record.</span>
                            </div>
                            <div>
                                <i class="fas fa-user-shield" aria-hidden="true"></i>
                                <strong>Permissions hold</strong>
                                <span>Existing write and manage permissions still control workflow changes.</span>
                            </div>
                        </div>
                    </section>

                    <section class="content-card marketing-guided-workflows-expert-card">
                        <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the workflow board.</p></div></div>
                        <div class="marketing-advanced-tools-grid">
                            <?php foreach ($expertLinks as $link): ?>
                                <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>">
                                    <strong><?php echo $h($link['label']); ?></strong>
                                    <span><?php echo $h($link['hint']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
