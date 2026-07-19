<?php
/**
 * Marketing task hub.
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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to update the Marketing Task Hub.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_preferences') {
            $marketing->updateMarketingTaskHubPreferences((int) ($user['id'] ?? 0), [
                'default_queue' => (string) ($_POST['default_queue'] ?? 'my_work'),
                'include_team_items' => !empty($_POST['include_team_items']),
            ]);
            header('Location: ' . getBasePath() . '/marketing_task_hub.php?success=preferences');
            exit;
        }
        if ($action === 'log_action') {
            $marketing->recordMarketingTaskHubAction(
                (string) ($_POST['item_type'] ?? 'content'),
                (int) ($_POST['item_id'] ?? 0),
                (string) ($_POST['action_type'] ?? 'acknowledged'),
                ['source' => 'marketing_task_hub'],
                (string) ($_POST['note'] ?? ''),
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_task_hub.php?success=action');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$teamScope = isset($_GET['team']) ? (bool) (int) $_GET['team'] : null;
$hub = $marketing->getMarketingTaskHub((int) ($user['id'] ?? 0), $teamScope !== null ? ['include_team_items' => $teamScope] : []);
$preferences = (array) ($hub['preferences'] ?? []);
$includeTeam = (bool) ($hub['include_team_items'] ?? false);
$queues = (array) ($hub['queues'] ?? []);
$counts = (array) ($hub['counts'] ?? []);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$queueFilter = trim((string) ($_GET['queue'] ?? ''));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$blockedCount = (int) ($counts['blocked_content'] ?? 0) + (int) ($counts['blocked_launches'] ?? 0);
$dueCount = (int) ($counts['due_content'] ?? 0) + (int) ($counts['upcoming_calendar'] ?? 0);
$reviewCount = (int) ($counts['pending_reviews'] ?? 0) + (int) ($counts['overdue_reviews'] ?? 0);
$guidedCount = (int) ($counts['guided_actions'] ?? 0);
$summaryTiles = [
    ['icon' => 'fa-user-check', 'label' => 'Assigned', 'value' => (string) ((int) ($counts['assigned_content'] ?? 0)), 'tooltip' => 'Content assigned to the current operator or team scope.'],
    ['icon' => 'fa-clock', 'label' => 'Due', 'value' => (string) $dueCount, 'tooltip' => 'Content and calendar items that need attention soon.'],
    ['icon' => 'fa-clipboard-check', 'label' => 'Reviews', 'value' => (string) $reviewCount, 'tooltip' => 'Pending and overdue review work.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $blockedCount, 'tooltip' => 'Blocked content and launch work that can stall the campaign path.'],
];

$queueBlocks = [
    'my_work' => ['title' => 'Assigned Content', 'items' => $queues['assigned_content'] ?? [], 'item_type' => 'content', 'href' => 'marketing_content_view.php?id=', 'empty' => 'No assigned content in this scope.', 'icon' => 'fa-user-check'],
    'overdue' => ['title' => 'Due Soon', 'items' => $queues['due_content'] ?? [], 'item_type' => 'content', 'href' => 'marketing_content_view.php?id=', 'empty' => 'No content is due in the next seven days.', 'icon' => 'fa-clock'],
    'reviews' => ['title' => 'Pending Reviews', 'items' => $queues['pending_reviews'] ?? [], 'item_type' => 'approval', 'href' => 'marketing_reviews.php', 'empty' => 'No pending reviews.', 'icon' => 'fa-clipboard-check'],
    'calendar' => ['title' => 'Calendar Items', 'items' => $queues['upcoming_calendar'] ?? [], 'item_type' => 'calendar', 'href' => 'marketing_calendar.php', 'empty' => 'No upcoming calendar items.', 'icon' => 'fa-calendar-days'],
    'blocked' => ['title' => 'Blocked Content', 'items' => $queues['blocked_content'] ?? [], 'item_type' => 'content', 'href' => 'marketing_content_view.php?id=', 'empty' => 'No blocked content.', 'icon' => 'fa-triangle-exclamation'],
    'launches' => ['title' => 'Blocked Launches', 'items' => $queues['blocked_launches'] ?? [], 'item_type' => 'launch_control', 'href' => 'marketing_launch_control.php?status=blocked', 'empty' => 'No blocked launches.', 'icon' => 'fa-rocket'],
    'guided' => ['title' => 'Guided Next Actions', 'items' => $queues['guided_actions'] ?? [], 'item_type' => 'guided_workflow', 'href' => 'marketing_guided_workflows.php', 'empty' => 'No guided next actions.', 'icon' => 'fa-route'],
];
$visibleQueueBlocks = array_filter(
    $queueBlocks,
    static fn(array $block, string $queueKey): bool => $queueFilter === '' || $queueFilter === $queueKey || ($queueFilter === 'team_work' && $queueKey === 'my_work'),
    ARRAY_FILTER_USE_BOTH
);
$firstSuggestedAction = (array) (((array) ($hub['suggested_actions'] ?? []))[0] ?? []);
$stageCards = [
    ['label' => 'Pick Queue', 'icon' => 'fa-table-columns', 'status' => $queueFilter !== '' ? 'ready' : 'in_use', 'sentence' => $queueFilter !== '' ? $labelize($queueFilter) : 'All queues.', 'tooltip' => 'Choose one queue when you want less noise, or keep all queues visible for a full operator board.', 'href' => '#task-tools', 'action' => 'Choose'],
    ['label' => 'Clear Blockers', 'icon' => 'fa-ban', 'status' => $blockedCount > 0 ? 'blocked' : 'ready', 'sentence' => $blockedCount > 0 ? $blockedCount . ' blocked.' : 'No blockers.', 'tooltip' => 'Blocked work should be handled before launch work moves forward.', 'href' => $blockedCount > 0 ? 'marketing_task_hub.php?queue=blocked' : '#task-board', 'action' => 'Review'],
    ['label' => 'Do Due Work', 'icon' => 'fa-clock', 'status' => $dueCount > 0 ? 'warning' : 'ready', 'sentence' => $dueCount > 0 ? $dueCount . ' due soon.' : 'Clear today.', 'tooltip' => 'Due work includes content production and upcoming calendar commitments.', 'href' => $dueCount > 0 ? 'marketing_task_hub.php?queue=overdue' : '#task-board', 'action' => 'Open'],
    ['label' => 'Review Approvals', 'icon' => 'fa-clipboard-check', 'status' => $reviewCount > 0 ? 'warning' : 'ready', 'sentence' => $reviewCount > 0 ? $reviewCount . ' reviews.' : 'No reviews.', 'tooltip' => 'Approval work stays visible here, while detailed review decisions remain in Reviews.', 'href' => $reviewCount > 0 ? 'marketing_task_hub.php?queue=reviews' : 'marketing_reviews.php', 'action' => 'Review'],
    ['label' => 'Follow Guidance', 'icon' => 'fa-route', 'status' => $guidedCount > 0 ? 'ready' : 'setup_needed', 'sentence' => $guidedCount > 0 ? $guidedCount . ' actions.' : 'Open path.', 'tooltip' => 'Guided workflow suggestions keep the task board connected to the founder path.', 'href' => (string) ($firstSuggestedAction['href'] ?? 'marketing_guided_workflows.php'), 'action' => 'Follow'],
];
$todayActions = array_slice(array_values(array_filter([
    $firstSuggestedAction !== [] ? [
        'label' => (string) ($firstSuggestedAction['label'] ?? 'Review suggested action'),
        'href' => (string) ($firstSuggestedAction['href'] ?? 'marketing_task_hub.php'),
        'description' => 'Highest-priority Task Hub suggestion.',
    ] : null,
    $blockedCount > 0 ? ['label' => 'Clear blocked work', 'href' => 'marketing_task_hub.php?queue=blocked', 'description' => 'Remove launch or content blockers first.'] : null,
    $dueCount > 0 ? ['label' => 'Work due queue', 'href' => 'marketing_task_hub.php?queue=overdue', 'description' => 'Handle content and calendar work due soon.'] : null,
    $reviewCount > 0 ? ['label' => 'Review approvals', 'href' => 'marketing_task_hub.php?queue=reviews', 'description' => 'Move pending approvals forward.'] : null,
    ['label' => 'Open guided workflows', 'href' => 'marketing_guided_workflows.php', 'description' => 'Use the guided path when the next task is unclear.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Guided Workflows', 'href' => 'marketing_guided_workflows.php', 'hint' => 'Open the procedural workflow path.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Open the production board.'],
    ['label' => 'Reviews', 'href' => 'marketing_reviews.php', 'hint' => 'Open detailed approval queues.'],
    ['label' => 'Calendar', 'href' => 'marketing_calendar.php', 'hint' => 'Review milestone timing.'],
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Resolve launch execution blockers.'],
    ['label' => 'Command Center', 'href' => 'marketing.php', 'hint' => 'Return to the Marketing operating map.'],
];

$renderActionForm = static function (string $itemType, int $itemId) use ($canWriteMarketing, $h): void {
    if (!$canWriteMarketing || $itemId <= 0) {
        return;
    }
    ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
        <input type="hidden" name="action" value="log_action">
        <input type="hidden" name="item_type" value="<?php echo $h($itemType); ?>">
        <input type="hidden" name="item_id" value="<?php echo (int) $itemId; ?>">
        <input type="hidden" name="action_type" value="acknowledged">
        <button class="btn-premium-secondary" type="submit">Acknowledge</button>
    </form>
    <?php
};

$pageTitle = 'Marketing Task Hub - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-task-hub-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Marketing Task Hub</h1>
                <p>Task Board: clear the next marketing job without hunting through tools.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="#task-board"><i class="fas fa-inbox"></i> Open Board</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'preferences'): ?><div class="alert alert-success">Task Hub preferences saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'action'): ?><div class="alert alert-success">Task action recorded.</div><?php endif; ?>

        <section class="marketing-task-hub-shell">
            <div class="marketing-founder-summary marketing-task-hub-summary" aria-label="Task Hub summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                        <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo $h($tile['label']); ?></span>
                        <strong><?php echo $h($tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-task-hub-stage-grid" aria-label="Task Hub operating path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-task-hub-stage-card <?php echo $h($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-task-hub-stage-body">
                            <span class="badge <?php echo $stageStatus === 'blocked' ? 'badge-danger' : ($stageStatus === 'ready' ? 'badge-success' : 'badge-warning'); ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                            <h2><?php echo $h($stage['label']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-task-hub-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-task-hub-layout">
                <main class="marketing-task-hub-main">
                    <section class="content-card marketing-task-hub-board" id="task-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Task Board</h2>
                                <p><?php echo $queueFilter !== '' ? $h($labelize($queueFilter)) . ' queue' : 'One procedural view of current work.'; ?></p>
                            </div>
                            <span class="badge <?php echo $blockedCount > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $blockedCount > 0 ? 'Blocked' : 'Clear'; ?></span>
                        </div>

                        <div class="marketing-task-hub-queue-grid">
                            <?php foreach ($visibleQueueBlocks as $queueKey => $block): ?>
                                <section class="marketing-task-hub-queue-card <?php echo !empty($block['items']) ? 'has-work' : 'is-empty'; ?>" data-tooltip="<?php echo $h($block['empty']); ?>" tabindex="0">
                                    <div class="marketing-task-hub-queue-head">
                                        <div class="marketing-stage-visual">
                                            <i class="fas <?php echo $h($block['icon']); ?>" aria-hidden="true"></i>
                                            <span><?php echo count((array) $block['items']); ?></span>
                                        </div>
                                        <div>
                                            <span class="badge badge-default"><?php echo $h($labelize((string) $queueKey)); ?></span>
                                            <h3><?php echo $h($block['title']); ?></h3>
                                        </div>
                                    </div>
                                    <?php if (empty($block['items'])): ?>
                                        <div class="empty-state"><p><?php echo $h($block['empty']); ?></p></div>
                                    <?php else: ?>
                                        <div class="marketing-task-hub-task-list">
                                            <?php foreach (array_slice((array) $block['items'], 0, 4) as $item): ?>
                                                <?php
                                                $itemId = (int) ($item['id'] ?? 0);
                                                $title = (string) ($item['title'] ?? $item['content_title'] ?? $item['launch_name'] ?? $item['label'] ?? 'Marketing task');
                                                $href = str_ends_with((string) $block['href'], '=') ? (string) $block['href'] . $itemId : (string) ($item['href'] ?? $block['href']);
                                                ?>
                                                <article class="marketing-task-hub-task-card">
                                                    <div>
                                                        <h4><?php echo $h($title); ?></h4>
                                                        <div class="marketing-task-hub-meta">
                                                            <?php if (!empty($item['status'])): ?><span><?php echo $h($labelize((string) $item['status'])); ?></span><?php endif; ?>
                                                            <?php if (!empty($item['channel'])): ?><span><?php echo $h($labelize((string) $item['channel'])); ?></span><?php endif; ?>
                                                            <?php if (!empty($item['production_due_at'])): ?><span>Due <?php echo $h($item['production_due_at']); ?></span><?php endif; ?>
                                                            <?php if (!empty($item['review_due_at'])): ?><span>Review due <?php echo $h($item['review_due_at']); ?></span><?php endif; ?>
                                                            <?php if (!empty($item['launch_date'])): ?><span>Launch <?php echo $h($item['launch_date']); ?></span><?php endif; ?>
                                                            <?php if (!empty($item['owner_email'])): ?><span><?php echo $h($item['owner_email']); ?></span><?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <a class="btn-premium-secondary marketing-task-hub-card-action" href="<?php echo $h($href); ?>">Open</a>
                                                    <details class="marketing-task-hub-task-tools">
                                                        <summary>Task controls</summary>
                                                        <div class="marketing-task-hub-control-row"><?php $renderActionForm((string) $block['item_type'], $itemId); ?></div>
                                                    </details>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </main>

                <aside class="content-card marketing-task-hub-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Use one task queue.</p>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-item" href="<?php echo $h($action['href']); ?>">
                                <strong><?php echo $h($action['label']); ?></strong>
                                <span><?php echo $h($action['description']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-task-hub-tools" id="task-tools">
                <summary>More task tools</summary>
                <div class="marketing-task-hub-tools-body">
                    <section class="content-card marketing-task-hub-filter-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Queue Filter</h2>
                                <p>Choose a focused view when the board feels busy.</p>
                            </div>
                        </div>
                        <div class="marketing-task-hub-toolbar">
                            <form method="GET" class="marketing-task-hub-filter-form">
                                <div class="form-group">
                                    <label>Queue</label>
                                    <select name="queue">
                                        <option value="">All queues</option>
                                        <?php foreach (Marketing::TASK_HUB_QUEUES as $queue): ?>
                                            <option value="<?php echo $h($queue); ?>" <?php echo $queueFilter === $queue ? 'selected' : ''; ?>><?php echo $h($labelize($queue)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="hidden" name="team" value="0">
                                <label class="marketing-task-hub-checkbox">
                                    <input type="checkbox" name="team" value="1" <?php echo $includeTeam ? 'checked' : ''; ?>> Team scope
                                </label>
                                <button class="btn-premium-secondary" type="submit">Apply</button>
                                <a class="btn-premium-secondary" href="marketing_task_hub.php">Reset</a>
                            </form>
                            <?php if ($canWriteMarketing): ?>
                                <form method="POST" class="marketing-task-hub-save-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="save_preferences">
                                    <input type="hidden" name="include_team_items" value="<?php echo $includeTeam ? '1' : '0'; ?>">
                                    <input type="hidden" name="default_queue" value="<?php echo $h($queueFilter !== '' ? $queueFilter : (string) ($preferences['default_queue'] ?? 'my_work')); ?>">
                                    <button class="btn-premium-primary" type="submit"><i class="fas fa-save"></i> Save View</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-card marketing-task-hub-recent-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Recent Task Actions</h2>
                                <p>Acknowledgements and operator updates.</p>
                            </div>
                        </div>
                        <?php if (empty($queues['recent_actions'])): ?>
                            <div class="empty-state"><p>No Task Hub actions have been recorded yet.</p></div>
                        <?php else: ?>
                            <div class="marketing-task-hub-recent-list">
                                <?php foreach ((array) $queues['recent_actions'] as $action): ?>
                                    <div class="marketing-task-hub-recent-item">
                                        <strong><?php echo $h($labelize((string) ($action['action_type'] ?? 'action'))); ?></strong>
                                        <div class="marketing-task-hub-meta">
                                            <span><?php echo $h($labelize((string) ($action['item_type'] ?? 'item'))); ?></span>
                                            <span><?php echo $h($action['created_by_email'] ?? 'Unknown user'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-task-hub-expert-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Advanced Marketing Tools</h2>
                                <p>Expert routes stay available without crowding the task board.</p>
                            </div>
                        </div>
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
