<?php
/**
 * Marketing lead handoffs.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Modules\Nurture;
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
$canReadNurture = Authorization::can('nurture.read', $user);
$nurture = $canReadNurture ? new Nurture() : null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_handoff') {
            $handoffStatus = (string) ($_POST['status'] ?? 'assigned');
            $feedbackData = [
                'feedback_reason' => (string) ($_POST['feedback_reason'] ?? ''),
                'feedback_note' => (string) ($_POST['feedback_note'] ?? ''),
                'created_by' => (int) ($user['id'] ?? 0),
            ];
            if ($canWriteMarketing) {
                $feedbackData['assigned_to'] = (int) ($_POST['assigned_to'] ?? 0);
            }
            if (in_array($handoffStatus, Marketing::HANDOFF_FEEDBACK_OUTCOMES, true)) {
                $marketing->recordLeadHandoffFeedback((int) ($_POST['id'] ?? 0), $handoffStatus, $feedbackData);
            } else {
                if (!$canWriteMarketing) {
                    throw new RuntimeException('You do not have permission to update lead handoffs.');
                }
                $marketing->updateLeadHandoffStatus((int) ($_POST['id'] ?? 0), $handoffStatus, $feedbackData);
            }
            header('Location: ' . getBasePath() . '/marketing_handoffs.php?success=updated');
            exit;
        }
        if ($action === 'sync_handoff') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to sync lead handoffs.');
            }
            $marketing->syncLeadHandoffToCrm((int) ($_POST['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_handoffs.php?success=synced');
            exit;
        }
        if ($action === 'create_rule') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to manage handoff rules.');
            }
            $marketing->createHandoffRule($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_handoffs.php?success=rule');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$assignedTo = (int) ($_GET['assigned_to'] ?? 0);
$contactId = (int) ($_GET['contact_id'] ?? 0);
$dealId = (int) ($_GET['deal_id'] ?? 0);
$overdue = (string) ($_GET['overdue'] ?? '') === '1';
$filters = [];
if ($status !== '') {
    $filters['status'] = $status;
} else {
    $filters['open'] = true;
}
if ($assignedTo > 0) {
    $filters['assigned_to'] = $assignedTo;
}
if ($overdue) {
    $filters['overdue'] = true;
}
if ($contactId > 0) {
    $filters['contact_id'] = $contactId;
}
if ($dealId > 0) {
    $filters['deal_id'] = $dealId;
}

$summary = $marketing->getLeadHandoffSummary();
$handoffs = $marketing->listLeadHandoffs($filters, 100, 0);
$rules = $marketing->listHandoffRules([], 20, 0);
$options = $marketing->optionData();
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$nurtureReadinessCache = [];
$getNurtureTransition = static function (array $handoff) use (&$nurtureReadinessCache, $nurture): array {
    $status = (string) ($handoff['status'] ?? '');
    $contactId = (int) ($handoff['contact_id'] ?? 0);
    if (!in_array($status, ['accepted', 'qualified', 'converted'], true)) {
        return [];
    }
    if (!$nurture instanceof Nurture) {
        return [];
    }
    if ($contactId <= 0) {
        return [
            'status' => 'not_ready',
            'label' => 'Needs CRM contact',
            'reason' => 'Sync a CRM contact before Customer Care can continue the handoff.',
            'next_step' => 'Sync CRM contact first.',
            'contact_id' => $contactId,
        ];
    }
    if (!array_key_exists($contactId, $nurtureReadinessCache)) {
        try {
            $nurtureReadinessCache[$contactId] = $nurture->getTransitionReadinessForContact($contactId, false);
        } catch (Throwable $e) {
            $nurtureReadinessCache[$contactId] = [];
        }
    }

    return $nurtureReadinessCache[$contactId];
};
$openCount = (int) ($summary['open'] ?? 0);
$assignedCount = (int) ($summary['assigned'] ?? 0);
$acceptedCount = (int) ($summary['accepted'] ?? 0);
$overdueCount = (int) ($summary['overdue'] ?? 0);
$convertedCount = (int) ($summary['converted'] ?? 0);
$feedbackCount = (int) ($summary['feedback_total'] ?? 0);
$syncPendingCount = (int) ($summary['sync_pending'] ?? 0);
$syncFailedCount = (int) ($summary['sync_failed'] ?? 0);
$ruleCount = count((array) $rules);
$handoffCount = count((array) $handoffs);
$syncAttentionCount = $syncPendingCount + $syncFailedCount;
$todayActions = [];
if ($openCount > 0) {
    $todayActions[] = ['label' => 'Review open handoffs', 'href' => '#handoff-board', 'meta' => $openCount . ' waiting'];
}
if ($overdueCount > 0) {
    $todayActions[] = ['label' => 'Fix overdue follow-up', 'href' => 'marketing_handoffs.php?overdue=1', 'meta' => $overdueCount . ' overdue'];
}
if ($syncAttentionCount > 0) {
    $todayActions[] = ['label' => 'Check CRM sync', 'href' => '#handoff-board', 'meta' => $syncAttentionCount . ' need attention'];
}
if ($feedbackCount > 0) {
    $todayActions[] = ['label' => 'Read sales feedback', 'href' => '#handoff-board', 'meta' => $feedbackCount . ' notes'];
}
if ($ruleCount === 0) {
    $todayActions[] = ['label' => 'Create a routing rule', 'href' => '#routing-rules', 'meta' => 'setup needed'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open landing pages', 'href' => 'marketing_landing_pages.php', 'meta' => 'start capture'];
}
$todayActions = array_slice($todayActions, 0, 5);
$handoffStages = [
    [
        'title' => 'Capture Lead',
        'icon' => 'fa-user-plus',
        'status' => $openCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $openCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $openCount,
        'metric_label' => 'open',
        'tooltip' => 'Captured landing-page conversions become handoffs here when a visitor submits a tracked form.',
        'action_label' => 'View queue',
        'action_href' => '#handoff-board',
    ],
    [
        'title' => 'Assign Owner',
        'icon' => 'fa-user-check',
        'status' => $assignedCount > 0 ? 'ready' : 'setup_needed',
        'badge' => $assignedCount > 0 ? 'Ready' : 'Setup needed',
        'metric' => (string) $assignedCount,
        'metric_label' => 'assigned',
        'tooltip' => 'Every good handoff needs a named owner so the founder is not chasing invisible follow-up.',
        'action_label' => 'Open rules',
        'action_href' => '#routing-rules',
    ],
    [
        'title' => 'Watch SLA',
        'icon' => 'fa-clock',
        'status' => $overdueCount > 0 ? 'setup_needed' : 'ready',
        'badge' => $overdueCount > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $overdueCount,
        'metric_label' => 'overdue',
        'tooltip' => 'SLA timing keeps warm leads from cooling while marketing and sales hand the work across.',
        'action_label' => 'Filter overdue',
        'action_href' => 'marketing_handoffs.php?overdue=1',
    ],
    [
        'title' => 'Sync CRM',
        'icon' => 'fa-arrows-rotate',
        'status' => $syncAttentionCount > 0 ? 'setup_needed' : 'ready',
        'badge' => $syncAttentionCount > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $syncAttentionCount,
        'metric_label' => 'pending',
        'tooltip' => 'CRM sync makes the handoff visible on sales records without changing the marketing workflow.',
        'action_label' => 'Check sync',
        'action_href' => '#handoff-board',
    ],
    [
        'title' => 'Learn Outcome',
        'icon' => 'fa-chart-line',
        'status' => $feedbackCount > 0 || $convertedCount > 0 ? 'in_use' : 'ready',
        'badge' => $feedbackCount > 0 || $convertedCount > 0 ? 'In use' : 'Ready',
        'metric' => (string) ($feedbackCount + $convertedCount),
        'metric_label' => 'signals',
        'tooltip' => 'Sales feedback closes the loop so marketing learns which leads were useful.',
        'action_label' => 'Review signals',
        'action_href' => '#handoff-board',
    ],
];
$pageTitle = 'Lead Handoffs - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-handoffs-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Lead Handoffs</h1>
                <p>Move interested leads into clear sales follow-up.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-chart-pie"></i> Marketing</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'updated'): ?><div class="alert alert-success">Lead handoff updated.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'synced'): ?><div class="alert alert-success">Lead handoff synced to CRM visibility surfaces.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rule'): ?><div class="alert alert-success">Handoff routing rule saved.</div><?php endif; ?>

        <section class="marketing-handoff-shell" aria-label="Lead handoff board">
            <div class="marketing-founder-summary marketing-handoff-summary">
                <article class="marketing-summary-tile" data-tooltip="Leads waiting for a sales follow-up decision.">
                    <i class="fas fa-inbox"></i>
                    <div><span>Open</span><strong><?php echo $openCount; ?></strong></div>
                </article>
                <article class="marketing-summary-tile" data-tooltip="Leads with an owner already selected.">
                    <i class="fas fa-user-check"></i>
                    <div><span>Assigned</span><strong><?php echo $assignedCount; ?></strong></div>
                </article>
                <article class="marketing-summary-tile" data-tooltip="Follow-ups that have missed their response target.">
                    <i class="fas fa-clock"></i>
                    <div><span>Overdue</span><strong><?php echo $overdueCount; ?></strong></div>
                </article>
                <article class="marketing-summary-tile" data-tooltip="Accepted or converted handoffs that show useful sales movement.">
                    <i class="fas fa-chart-line"></i>
                    <div><span>Won Signals</span><strong><?php echo $acceptedCount + $convertedCount; ?></strong></div>
                </article>
            </div>

            <div class="marketing-handoff-stage-grid" aria-label="Handoff path">
                <?php foreach ($handoffStages as $stage): ?>
                    <article class="marketing-handoff-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-handoff-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-handoff-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-handoff-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-handoff-layout">
                <section class="marketing-handoff-board" id="handoff-board">
                    <div class="premium-section-header">
                        <h2>Follow-Up Queue</h2>
                        <p><?php echo $handoffCount; ?> handoffs in this view.</p>
                    </div>
                    <?php if (empty($handoffs)): ?>
                        <div class="marketing-handoff-empty">
                            <div class="marketing-stage-visual"><i class="fas fa-route"></i></div>
                            <h3>No handoffs in this view</h3>
                            <p>Captured landing-page conversions will appear here when a published page records a conversion.</p>
                            <a class="btn-premium-primary" href="marketing_landing_pages.php">Open Landing Pages</a>
                        </div>
                    <?php else: ?>
                        <div class="marketing-handoff-list">
                            <?php foreach ($handoffs as $handoff): ?>
                                <?php
                                    $isOverdue = !empty($handoff['sla_due_at']) && strtotime((string) $handoff['sla_due_at']) < time() && in_array((string) $handoff['status'], ['new', 'assigned'], true);
                                    $leadLabel = trim((string) ($handoff['contact_email'] ?? $handoff['contact_name'] ?? ''));
                                    if ($leadLabel === '') {
                                        $leadLabel = (string) ($handoff['landing_page_title'] ?? 'Marketing lead');
                                    }
                                    $canUpdateThisHandoff = $canWriteMarketing || (int) ($handoff['assigned_to'] ?? 0) === (int) ($user['id'] ?? 0);
                                    $statusOptions = $canWriteMarketing ? Marketing::LEAD_HANDOFF_STATUSES : Marketing::HANDOFF_FEEDBACK_OUTCOMES;
                                    $leadMeta = array_filter([
                                        !empty($handoff['campaign_name']) ? 'Campaign: ' . (string) $handoff['campaign_name'] : '',
                                        !empty($handoff['landing_page_title']) ? 'Landing: ' . (string) $handoff['landing_page_title'] : '',
                                        !empty($handoff['conversion_goal_title']) ? 'Goal: ' . (string) $handoff['conversion_goal_title'] : '',
                                        !empty($handoff['sales_outcome']) ? 'Outcome: ' . $labelize((string) $handoff['sales_outcome']) : '',
                                    ]);
                                    $nurtureTransition = $getNurtureTransition($handoff);
                                ?>
                                <article class="marketing-handoff-card <?php echo $isOverdue ? 'needs_attention' : 'ready'; ?>" data-tooltip="Update status, owner, and CRM visibility from this handoff card." tabindex="0">
                                    <div class="marketing-handoff-card-main">
                                        <div class="marketing-handoff-card-title">
                                            <span class="marketing-stage-status"><?php echo $h($labelize((string) $handoff['status'])); ?></span>
                                            <?php if ($isOverdue): ?><span class="marketing-stage-status danger">Overdue</span><?php endif; ?>
                                            <h3><?php echo $h($leadLabel); ?></h3>
                                        </div>
                                        <div class="marketing-handoff-meta">
                                            <span><?php echo $h($labelize((string) $handoff['priority'])); ?></span>
                                            <span><?php echo $h((string) ($handoff['assigned_to_email'] ?? 'Unassigned')); ?></span>
                                            <span>CRM: <?php echo $h($labelize((string) ($handoff['crm_sync_status'] ?? 'pending'))); ?></span>
                                        </div>
                                        <?php if ($leadMeta !== []): ?>
                                            <div class="marketing-handoff-meta marketing-handoff-context">
                                                <?php foreach (array_slice($leadMeta, 0, 3) as $meta): ?><span><?php echo $h($meta); ?></span><?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($nurtureTransition !== []): ?>
                                            <?php
                                                $transitionStatus = (string) ($nurtureTransition['status'] ?? 'not_ready');
                                                $transitionContactId = (int) ($nurtureTransition['contact_id'] ?? $handoff['contact_id'] ?? 0);
                                                $needsPurchase = $transitionStatus === 'not_ready';
                                                $fallbackHref = !empty($handoff['deal_id'])
                                                    ? 'deal_view.php?id=' . (int) $handoff['deal_id']
                                                    : ($transitionContactId > 0 ? 'contact_view.php?id=' . $transitionContactId : 'contacts.php');
                                            ?>
                                            <div class="marketing-handoff-nurture <?php echo $h($transitionStatus); ?>">
                                                <span class="marketing-stage-status"><?php echo $h((string) ($nurtureTransition['label'] ?? 'Needs purchase evidence')); ?></span>
                                                <p><?php echo $h((string) ($nurtureTransition['next_step'] ?? 'Create a sales task or record purchase evidence before customer care.')); ?></p>
                                                <div class="marketing-handoff-nurture-actions">
                                                    <?php if ($needsPurchase): ?>
                                                        <a class="btn-premium-secondary" href="<?php echo $h($fallbackHref); ?>">Finish sales handoff</a>
                                                    <?php elseif ($transitionContactId > 0): ?>
                                                        <a class="btn-premium-primary" href="nurture_view.php?contact_id=<?php echo (int) $transitionContactId; ?>">Open Customer Care</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($handoff['feedback_reason']) || !empty($handoff['handoff_note']) || !empty($handoff['task_id']) || !empty($handoff['crm_activity_id']) || !empty($handoff['notification_id'])): ?>
                                            <details class="marketing-handoff-card-detail">
                                                <summary>More context</summary>
                                                <div class="marketing-handoff-detail-grid">
                                                    <?php if (!empty($handoff['feedback_reason'])): ?><span>Feedback: <?php echo $h((string) $handoff['feedback_reason']); ?></span><?php endif; ?>
                                                    <?php if (!empty($handoff['handoff_note'])): ?><span>Note: <?php echo $h((string) $handoff['handoff_note']); ?></span><?php endif; ?>
                                                    <?php if (!empty($handoff['task_id'])): ?><span>Task #<?php echo (int) $handoff['task_id']; ?></span><?php endif; ?>
                                                    <?php if (!empty($handoff['crm_activity_id'])): ?><span>Activity #<?php echo (int) $handoff['crm_activity_id']; ?></span><?php endif; ?>
                                                    <?php if (!empty($handoff['notification_id'])): ?><span>Notification #<?php echo (int) $handoff['notification_id']; ?></span><?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                    <div class="marketing-handoff-actions">
                                        <?php if ($canUpdateThisHandoff): ?>
                                            <form method="POST" class="marketing-handoff-update-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="update_handoff">
                                                <input type="hidden" name="id" value="<?php echo (int) $handoff['id']; ?>">
                                                <div class="form-group">
                                                    <label>Status</label>
                                                    <select name="status">
                                                        <?php foreach ($statusOptions as $handoffStatus): ?><option value="<?php echo $h($handoffStatus); ?>" <?php echo $selected((string) $handoff['status'], $handoffStatus); ?>><?php echo $h($labelize($handoffStatus)); ?></option><?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php if ($canWriteMarketing): ?>
                                                    <div class="form-group">
                                                        <label>Owner</label>
                                                        <select name="assigned_to">
                                                            <option value="">Keep owner</option>
                                                            <?php foreach ((array) ($options['users'] ?? []) as $optionUser): ?><option value="<?php echo (int) $optionUser['id']; ?>" <?php echo $selected((int) ($handoff['assigned_to'] ?? 0), (int) $optionUser['id']); ?>><?php echo $h((string) $optionUser['email']); ?></option><?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-group">
                                                    <label>Reason</label>
                                                    <input type="text" name="feedback_reason" value="" placeholder="Outcome reason">
                                                </div>
                                                <button class="btn-premium-secondary" type="submit">Update</button>
                                            </form>
                                            <?php if ($canWriteMarketing): ?><form method="POST" class="marketing-handoff-sync-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="sync_handoff">
                                                <input type="hidden" name="id" value="<?php echo (int) $handoff['id']; ?>">
                                                <button class="btn-premium-secondary" type="submit">Sync CRM</button>
                                            </form><?php endif; ?>
                                        <?php else: ?>
                                            <span class="marketing-handoff-meta">Read-only access</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <aside class="marketing-handoff-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One queue, one next move.</p>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo $h($action['href']); ?>">
                                <span><?php echo $h($action['label']); ?></span>
                                <small><?php echo $h($action['meta']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="marketing-handoff-tools" id="routing-rules">
                <summary>More handoff tools</summary>
                <div class="marketing-handoff-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_performance.php"><i class="fas fa-chart-line"></i><span>Performance</span></a>
                        <a href="tasks.php"><i class="fas fa-list-check"></i><span>Sales Tasks</span></a>
                        <a href="marketing_landing_pages.php"><i class="fas fa-window-maximize"></i><span>Landing Pages</span></a>
                        <a href="marketing.php"><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-handoff-detail-section">
                        <details class="marketing-handoff-filter" open>
                            <summary>Queue Filter</summary>
                            <form method="GET" class="marketing-handoff-filter-form">
                                <?php if ($contactId > 0): ?><input type="hidden" name="contact_id" value="<?php echo (int) $contactId; ?>"><?php endif; ?>
                                <?php if ($dealId > 0): ?><input type="hidden" name="deal_id" value="<?php echo (int) $dealId; ?>"><?php endif; ?>
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="">Open</option>
                                        <?php foreach (Marketing::LEAD_HANDOFF_STATUSES as $handoffStatus): ?><option value="<?php echo $h($handoffStatus); ?>" <?php echo $selected($status, $handoffStatus); ?>><?php echo $h($labelize($handoffStatus)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Owner</label>
                                    <select name="assigned_to">
                                        <option value="">Any owner</option>
                                        <?php foreach ((array) ($options['users'] ?? []) as $optionUser): ?><option value="<?php echo (int) $optionUser['id']; ?>" <?php echo $selected($assignedTo, (int) $optionUser['id']); ?>><?php echo $h((string) $optionUser['email']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="marketing-handoff-check"><input type="checkbox" name="overdue" value="1" <?php echo $overdue ? 'checked' : ''; ?>> Overdue only</label>
                                <button class="btn-premium-secondary" type="submit">Filter</button>
                                <a class="btn-premium-secondary" href="marketing_handoffs.php">Reset</a>
                                <?php if ($contactId > 0 || $dealId > 0): ?>
                                    <span class="badge">CRM record filter: <?php echo $contactId > 0 ? 'contact #' . (int) $contactId : 'deal #' . (int) $dealId; ?></span>
                                <?php endif; ?>
                            </form>
                        </details>
                    </section>

                    <section class="marketing-handoff-detail-section">
                        <div class="premium-section-header"><h2>Routing Rules</h2><p>Active rules assign new conversion handoffs to a workspace user and create a sales task.</p></div>
                        <?php if (empty($rules)): ?><div class="empty-state"><p>No handoff routing rules yet.</p></div><?php else: foreach ($rules as $rule): ?>
                            <div class="marketing-handoff-rule">
                                <strong><?php echo $h((string) $rule['name']); ?></strong>
                                <div class="marketing-handoff-meta">
                                    <span><?php echo $h($labelize((string) $rule['status'])); ?></span>
                                    <span><?php echo $h($labelize((string) $rule['source_type'])); ?></span>
                                    <span><?php echo $h((string) ($rule['assigned_to_email'] ?? 'Unassigned')); ?></span>
                                    <span><?php echo (int) ($rule['sla_minutes'] ?? 0); ?> min SLA</span>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>

                        <?php if ($canManageMarketing): ?>
                            <details class="marketing-handoff-rule-form">
                                <summary>Add Rule</summary>
                                <form method="POST" class="marketing-handoff-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="create_rule">
                                    <div class="marketing-handoff-form-grid">
                                        <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="Demo request routing"></div>
                                        <div class="form-group"><label>Status</label><select name="status"><option value="active">Active</option><option value="draft">Draft</option><option value="paused">Paused</option></select></div>
                                        <div class="form-group"><label>Source Type</label><select name="source_type"><?php foreach (Marketing::HANDOFF_SOURCE_TYPES as $sourceType): ?><option value="<?php echo $h($sourceType); ?>"><?php echo $h($labelize($sourceType)); ?></option><?php endforeach; ?></select></div>
                                        <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::HANDOFF_PRIORITIES as $priority): ?><option value="<?php echo $h($priority); ?>"><?php echo $h($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                                        <div class="form-group"><label>Assign To</label><select name="assigned_to"><option value="">Leave unassigned</option><?php foreach ((array) ($options['users'] ?? []) as $optionUser): ?><option value="<?php echo (int) $optionUser['id']; ?>"><?php echo $h((string) $optionUser['email']); ?></option><?php endforeach; ?></select></div>
                                        <div class="form-group"><label>SLA Minutes</label><input type="number" min="15" max="43200" name="sla_minutes" value="1440"></div>
                                        <div class="form-group marketing-handoff-wide-field"><label>Task Title Template</label><input type="text" name="task_title_template" placeholder="Follow up {lead} from {campaign}"></div>
                                    </div>
                                    <button class="btn-premium-primary" type="submit">Save Rule</button>
                                </form>
                            </details>
                        <?php else: ?>
                            <div class="empty-state"><p>Routing rule management is reserved for Marketing managers and owners.</p></div>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
