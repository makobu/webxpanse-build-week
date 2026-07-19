<?php
/**
 * Marketing review queue.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingWorkflowNextStepsUi;
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
$canManageMarketing = Authorization::can('marketing.manage', $user);
$options = $marketing->optionData();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canManageMarketing) {
            throw new RuntimeException('You do not have permission to decide marketing approvals.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $marketing->decideContentApproval((int) ($_POST['approval_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['decision_note'] ?? ''), (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_reviews.php?success=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$queue = (string) ($_GET['queue'] ?? 'pending');
$validQueues = ['pending', 'overdue', 'approved', 'rejected', 'blocked'];
if (!in_array($queue, $validQueues, true)) {
    $queue = 'pending';
}
$reviewerUserId = (int) ($_GET['reviewer_user_id'] ?? 0);
$approvalFilters = ['queue' => $queue];
if ($reviewerUserId > 0) {
    $approvalFilters['reviewer_user_id'] = $reviewerUserId;
}
$approvals = $marketing->listContentApprovals($approvalFilters, 100, 0);
$closureSummary = $marketing->getMarketingWorkflowClosureSummary();
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'reviews', 6);
$queueCounts = [];
foreach ($validQueues as $queueName) {
    $countFilters = ['queue' => $queueName];
    if ($reviewerUserId > 0) {
        $countFilters['reviewer_user_id'] = $reviewerUserId;
    }
    $queueCounts[$queueName] = count($marketing->listContentApprovals($countFilters, 250, 0));
}
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'approved', 'clear', 'ready' => 'badge-success',
        'rejected', 'blocked', 'overdue' => 'badge-danger',
        'pending', 'needs_attention', 'warning', 'setup_needed' => 'badge-warning',
        default => 'badge-default',
    };
};
$queueHref = static function (string $targetQueue, int $reviewerUserId): string {
    return 'marketing_reviews.php?' . http_build_query(array_filter([
        'queue' => $targetQueue,
        'reviewer_user_id' => $reviewerUserId > 0 ? $reviewerUserId : null,
    ], static fn($value): bool => $value !== null && $value !== ''));
};
$firstApproval = (array) ($approvals[0] ?? []);
$firstBlocked = (array) (array_values(array_filter($approvals, static fn(array $approval): bool => trim((string) ($approval['blocked_reason'] ?? '')) !== ''))[0] ?? []);
$firstClosureItem = (array) ((array) ($closureSummary['closure_items'] ?? []))[0] ?? [];
$summaryTiles = [
    ['icon' => 'fa-inbox', 'label' => 'Queue', 'value' => $labelize($queue), 'tooltip' => 'The current review queue shown on the board.'],
    ['icon' => 'fa-clock', 'label' => 'Pending', 'value' => (string) ($queueCounts['pending'] ?? 0), 'tooltip' => 'Pending approvals that are not overdue or blocked.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Overdue', 'value' => (string) ($queueCounts['overdue'] ?? 0), 'tooltip' => 'Pending reviews past their review due date.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) ($queueCounts['blocked'] ?? 0), 'tooltip' => 'Pending reviews attached to blocked content.'],
];
$stageCards = [
    [
        'label' => 'Choose Queue',
        'icon' => 'fa-filter',
        'status' => 'ready',
        'sentence' => 'Pick the review lane.',
        'tooltip' => 'Expert view: queue filters pending, overdue, approved, rejected, and blocked approvals.',
        'href' => '#review-queues',
        'action' => 'Change queue',
    ],
    [
        'label' => 'Check Blocker',
        'icon' => 'fa-magnifying-glass-chart',
        'status' => (int) ($closureSummary['counts']['blocked_content'] ?? 0) > 0 ? 'blocked' : 'ready',
        'sentence' => 'See why work is stuck.',
        'tooltip' => 'Expert view: workflow closure reasons from content readiness, media, audience, approval, distribution, and UTM evidence.',
        'href' => '#workflow-closure-board',
        'action' => 'View blockers',
    ],
    [
        'label' => 'Open Content',
        'icon' => 'fa-file-lines',
        'status' => $firstApproval !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Review the actual item.',
        'tooltip' => 'Expert view: content item approval record, production stage, dependency state, and readiness score.',
        'href' => $firstApproval !== [] ? 'marketing_content_view.php?id=' . (int) ($firstApproval['content_item_id'] ?? 0) : 'marketing_content.php?status=review',
        'action' => 'Open item',
    ],
    [
        'label' => 'Make Decision',
        'icon' => 'fa-circle-check',
        'status' => $canManageMarketing && $queue === 'pending' && $approvals !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Approve or reject manually.',
        'tooltip' => 'Expert view: only marketing managers can approve or reject. No external publish or send happens here.',
        'href' => $firstApproval !== [] ? '#approval-' . (int) ($firstApproval['id'] ?? 0) : 'marketing_content.php?status=review',
        'action' => 'Decide',
    ],
    [
        'label' => 'Close Loop',
        'icon' => 'fa-route',
        'status' => (string) ($closureSummary['status'] ?? 'clear') === 'clear' ? 'ready' : 'warning',
        'sentence' => 'Move approved work onward.',
        'tooltip' => 'Expert view: approved content can move toward calendar, distribution, launch checks, and performance loops.',
        'href' => 'marketing_calendar.php',
        'action' => 'Open calendar',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstBlocked !== [] ? [
        'label' => 'Resolve blocked review',
        'href' => 'marketing_content_view.php?id=' . (int) ($firstBlocked['content_item_id'] ?? 0),
        'reason' => 'Open the blocked content and clear the reason before deciding.',
    ] : null,
    $firstApproval !== [] ? [
        'label' => 'Review first item',
        'href' => 'marketing_content_view.php?id=' . (int) ($firstApproval['content_item_id'] ?? 0),
        'reason' => 'Read the content and decide if it can move forward.',
    ] : null,
    $firstClosureItem !== [] ? [
        'label' => 'Open blocker',
        'href' => (string) ($firstClosureItem['href'] ?? 'marketing_content.php?blocked=1'),
        'reason' => 'Use the closure summary to unblock the next stuck item.',
    ] : null,
    [
        'label' => 'Open review content',
        'href' => 'marketing_content.php?status=review',
        'reason' => 'See all content currently waiting for review.',
    ],
    [
        'label' => 'Open calendar',
        'href' => 'marketing_calendar.php',
        'reason' => 'Check whether review decisions affect planned dates.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the guided command center.'],
    ['label' => 'Review Content', 'href' => 'marketing_content.php?status=review', 'hint' => 'Open content in the review production stage.'],
    ['label' => 'Blocked Content', 'href' => 'marketing_content.php?blocked=1', 'hint' => 'Resolve blocked content before approval.'],
    ['label' => 'Calendar', 'href' => 'marketing_calendar.php', 'hint' => 'Check dates affected by approvals.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Move approved content toward manual distribution.'],
    ['label' => 'Quality Checks', 'href' => 'marketing_quality.php', 'hint' => 'Review quality evidence before launch.'],
];
$pageTitle = 'Marketing Reviews - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-reviews-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Marketing Approval Workbench</h1>
                <p>Review Board: clear the next approval without losing the expert evidence.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="marketing_content.php?status=review"><i class="fas fa-pen-nib"></i> Review Content</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Review action failed.</strong> <?php echo htmlspecialchars($error); ?> The approval queue was left unchanged.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">Review decision saved.</div><?php endif; ?>

        <section class="marketing-reviews-shell">
            <div class="marketing-founder-summary marketing-reviews-summary" aria-label="Review summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-reviews-stage-grid" aria-label="Review path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-reviews-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-reviews-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-reviews-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-reviews-layout">
                <main class="marketing-reviews-main">
                    <section class="content-card marketing-reviews-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Review Queue</h2>
                                <p>Decide what can move forward next.</p>
                            </div>
                            <span class="badge <?php echo $approvals !== [] ? 'badge-warning' : 'badge-success'; ?>"><?php echo count($approvals); ?> <?php echo htmlspecialchars($labelize($queue)); ?></span>
                        </div>

                        <?php if (empty($approvals)): ?>
                            <div class="empty-state">
                                <p>No marketing reviews match this queue. Check another queue, filter by a different reviewer, or request review from a content detail page.</p>
                                <a class="btn-premium-secondary" href="marketing_reviews.php">All review queues</a>
                                <a class="btn-premium-primary" href="marketing_content.php?status=review">Open review content</a>
                            </div>
                        <?php else: ?>
                            <div class="marketing-reviews-card-grid">
                                <?php foreach ($approvals as $approval): ?>
                                    <?php
                                    $status = (string) ($approval['status'] ?? 'pending');
                                    $isOverdue = $status === 'pending' && !empty($approval['review_due_at']) && strtotime((string) $approval['review_due_at']) < time();
                                    $isBlocked = trim((string) ($approval['blocked_reason'] ?? '')) !== '';
                                    $cardStatus = $isBlocked ? 'blocked' : ($isOverdue ? 'overdue' : $status);
                                    $warnings = (array) ($approval['required_context_warnings_json'] ?? []);
                                    $tooltip = 'Expert view: readiness ' . (int) ($approval['readiness_score'] ?? 0)
                                        . '%, production ' . $labelize((string) ($approval['production_stage'] ?? 'idea'))
                                        . ', dependency ' . $labelize((string) ($approval['dependency_status'] ?? 'clear')) . '.';
                                    ?>
                                    <article class="marketing-reviews-card <?php echo htmlspecialchars($cardStatus); ?>" id="approval-<?php echo (int) $approval['id']; ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                        <div class="marketing-reviews-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                                            </div>
                                            <span class="badge <?php echo htmlspecialchars($badgeClass($cardStatus)); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                                        </div>
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) $approval['content_title']); ?></h3>
                                            <div class="marketing-reviews-meta">
                                                <span>Due <?php echo htmlspecialchars((string) ($approval['review_due_at'] ?? 'Not set')); ?></span>
                                                <span><?php echo (int) ($approval['readiness_score'] ?? 0); ?>% ready</span>
                                                <span><?php echo htmlspecialchars((string) ($approval['reviewer_email'] ?? 'Unassigned')); ?></span>
                                            </div>
                                        </div>
                                        <?php if (!empty($approval['next_action']) || $isBlocked || $warnings !== []): ?>
                                            <div class="marketing-reviews-signal">
                                                <strong><?php echo $isBlocked ? 'Blocked' : 'Next'; ?></strong>
                                                <small><?php echo htmlspecialchars($isBlocked ? (string) $approval['blocked_reason'] : (string) ($approval['next_action'] ?? ('Warnings: ' . implode(', ', array_map($labelize, $warnings))))); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <a class="btn-premium-primary marketing-reviews-card-action" href="marketing_content_view.php?id=<?php echo (int) $approval['content_item_id']; ?>">Open content</a>
                                        <?php if ($canManageMarketing && $status === 'pending'): ?>
                                            <details class="marketing-reviews-card-tools">
                                                <summary>Decision controls</summary>
                                                <form class="marketing-reviews-decision-form" method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                                    <input type="text" name="decision_note" placeholder="Optional note">
                                                    <button class="btn-premium-primary" type="submit" name="decision" value="approved">Approve</button>
                                                    <button class="btn-premium-secondary" type="submit" name="decision" value="rejected">Reject</button>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="marketing-reviews-today content-card" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Clear one review decision.</p>
                        </div>
                    </div>
                    <div class="marketing-playbook-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>">
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                                <small><?php echo htmlspecialchars((string) $action['reason']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-reviews-tools">
                <summary>More review tools</summary>
                <div class="marketing-reviews-tools-body">
                    <section class="marketing-reviews-queue-card" id="review-queues">
                        <div class="premium-section-header">
                            <div>
                                <h2>Review Queues</h2>
                                <p>Switch queues without crowding the board.</p>
                            </div>
                        </div>
                        <div class="marketing-reviews-queue-tabs">
                            <?php foreach ($validQueues as $queueName): ?>
                                <a class="<?php echo $queue === $queueName ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($queueHref($queueName, $reviewerUserId)); ?>"><?php echo htmlspecialchars($labelize($queueName)); ?> <span><?php echo (int) $queueCounts[$queueName]; ?></span></a>
                            <?php endforeach; ?>
                        </div>
                        <form class="marketing-reviews-filter-form" method="GET">
                            <input type="hidden" name="queue" value="<?php echo htmlspecialchars($queue); ?>">
                            <div class="form-group">
                                <label for="reviewer_user_id">Reviewer</label>
                                <select id="reviewer_user_id" name="reviewer_user_id">
                                    <option value="">Any reviewer</option>
                                    <?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($reviewerUserId, (int) $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-primary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_reviews.php?queue=<?php echo urlencode($queue); ?>">Reset</a>
                        </form>
                    </section>

                    <section class="marketing-reviews-closure-card" id="workflow-closure-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Workflow Closure Board</h2>
                                <p>Blocked Reasons, missing context, and Manual-first guidance stay here.</p>
                            </div>
                            <span class="badge <?php echo htmlspecialchars($badgeClass((string) ($closureSummary['status'] ?? 'clear'))); ?>"><?php echo htmlspecialchars($labelize((string) ($closureSummary['status'] ?? 'clear'))); ?></span>
                        </div>
                        <div class="marketing-reviews-closure-grid">
                            <article><span>Blocked Content</span><strong><?php echo (int) ($closureSummary['counts']['blocked_content'] ?? 0); ?></strong></article>
                            <article><span>Overdue Reviews</span><strong><?php echo (int) ($closureSummary['counts']['overdue_reviews'] ?? 0); ?></strong></article>
                            <article><span>Missing Audience</span><strong><?php echo (int) ($closureSummary['counts']['missing_audience'] ?? 0); ?></strong></article>
                            <article><span>Missing Media</span><strong><?php echo (int) ($closureSummary['counts']['missing_media'] ?? 0); ?></strong></article>
                            <article><span>Missing Distribution</span><strong><?php echo (int) ($closureSummary['counts']['missing_distribution'] ?? 0); ?></strong></article>
                        </div>
                        <div class="marketing-reviews-closure-layout">
                            <div>
                                <h3>Blocked Reasons</h3>
                                <?php if (empty($closureSummary['closure_items'])): ?>
                                    <div class="empty-state"><p>No workflow blockers are visible right now.</p><a class="btn-premium-primary" href="marketing_content.php">Open Content Studio</a></div>
                                <?php else: ?>
                                    <div class="marketing-reviews-closure-list">
                                        <?php foreach ((array) $closureSummary['closure_items'] as $item): ?>
                                            <article class="marketing-reviews-closure-item <?php echo htmlspecialchars((string) ($item['severity'] ?? 'warning')); ?>">
                                                <a href="<?php echo htmlspecialchars((string) ($item['href'] ?? '#')); ?>"><?php echo htmlspecialchars((string) ($item['title'] ?? 'Marketing item')); ?></a>
                                                <div class="marketing-reviews-meta">
                                                    <span><?php echo htmlspecialchars($labelize((string) ($item['status'] ?? ''))); ?></span>
                                                    <span><?php echo htmlspecialchars($labelize((string) ($item['severity'] ?? 'warning'))); ?></span>
                                                </div>
                                                <small><?php echo htmlspecialchars(implode(', ', array_map(static fn($reason): string => (string) $reason, (array) ($item['reasons'] ?? [])))); ?></small>
                                                <?php if (!empty($item['next_action'])): ?><small>Next: <?php echo htmlspecialchars((string) $item['next_action']); ?></small><?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3>Next Actions</h3>
                                <div class="marketing-playbook-next-list">
                                    <?php foreach ((array) ($closureSummary['next_actions'] ?? []) as $action): ?>
                                        <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) ($action['href'] ?? '#')); ?>">
                                            <span><?php echo htmlspecialchars((string) ($action['label'] ?? 'Review action')); ?></span>
                                            <small>Workflow closure action</small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="marketing-reviews-manual-first">Manual-first: this board recommends actions only. No external send, publish, or channel API call happens here.</div>
                            </div>
                        </div>
                    </section>

                    <section class="marketing-reviews-workflow-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Review next steps</h2>
                                <p>Workflow guidance stays below the main decision board.</p>
                            </div>
                        </div>
                        <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                    </section>

                    <section class="marketing-reviews-expert-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Expert Routes</h2>
                                <p>Related tools remain reachable without backlink clutter.</p>
                            </div>
                        </div>
                        <div class="marketing-advanced-tools-grid">
                            <?php foreach ($expertLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars((string) $link['href']); ?>">
                                    <span><?php echo htmlspecialchars((string) $link['label']); ?></span>
                                    <small><?php echo htmlspecialchars((string) $link['hint']); ?></small>
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
