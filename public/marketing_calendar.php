<?php
/**
 * Marketing calendar milestones.
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
$canWriteMarketing = Authorization::can('marketing.write', $user);
$options = $marketing->optionData();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to manage marketing milestones.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'create') {
            $marketing->createCalendarMilestone($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        } elseif ($action === 'mark_done') {
            $marketing->updateCalendarMilestone((int) ($_POST['milestone_id'] ?? 0), ['status' => 'done']);
        } elseif ($action === 'reschedule') {
            $marketing->rescheduleCalendarMilestone((int) ($_POST['milestone_id'] ?? 0), (string) ($_POST['milestone_date'] ?? ''), (int) ($user['id'] ?? 0));
        } elseif ($action === 'create_template') {
            $marketing->createCalendarTemplate($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        }
        header('Location: ' . getBasePath() . '/marketing_calendar.php?success=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filters = [
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to' => $_GET['date_to'] ?? date('Y-m-t'),
];
if (trim((string) ($_GET['status'] ?? '')) !== '') {
    $filters['status'] = (string) $_GET['status'];
}
if (trim((string) ($_GET['milestone_type'] ?? '')) !== '') {
    $filters['milestone_type'] = (string) $_GET['milestone_type'];
}
if (trim((string) ($_GET['channel'] ?? '')) !== '') {
    $filters['channel'] = (string) $_GET['channel'];
}
if (trim((string) ($_GET['conflict_status'] ?? '')) !== '') {
    $filters['conflict_status'] = (string) $_GET['conflict_status'];
}
if ((int) ($_GET['owner_user_id'] ?? 0) > 0) {
    $filters['owner_user_id'] = (int) $_GET['owner_user_id'];
}
$calendar = $marketing->getEditorialCalendar($filters);
$milestones = (array) ($calendar['milestones'] ?? []);
$milestonesByDate = (array) ($calendar['by_date'] ?? []);
$calendarConflicts = (array) ($calendar['conflicts'] ?? []);
$calendarTemplates = (array) ($calendar['templates'] ?? []);
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$plannedCount = count(array_filter($milestones, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'planned'));
$doneCount = count(array_filter($milestones, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'done'));
$overdueCount = count(array_filter($milestones, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'planned' && !empty($row['milestone_date']) && strtotime((string) $row['milestone_date']) < strtotime(date('Y-m-d'))));
$conflictCount = count($calendarConflicts);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'calendar', 6);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$dateLabel = static function (?string $date, string $fallback): string {
    $timestamp = $date !== null && $date !== '' ? strtotime($date) : false;
    return $timestamp !== false ? date('M j', $timestamp) : $fallback;
};
$badgeClass = static function (string $status): string {
    return match ($status) {
        'done', 'clear', 'ready' => 'badge-success',
        'overdue', 'conflict', 'blocked' => 'badge-danger',
        'planned', 'warning', 'setup_needed' => 'badge-warning',
        default => 'badge-default',
    };
};
$firstMilestone = (array) ($milestones[0] ?? []);
$firstOverdue = (array) (array_values(array_filter($milestones, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'planned' && !empty($row['milestone_date']) && strtotime((string) $row['milestone_date']) < strtotime(date('Y-m-d'))))[0] ?? []);
$summaryTiles = [
    ['icon' => 'fa-calendar-days', 'label' => 'Window', 'value' => $dateLabel((string) ($filters['date_from'] ?? ''), 'Start') . ' - ' . $dateLabel((string) ($filters['date_to'] ?? ''), 'End'), 'tooltip' => 'The calendar window currently shown. Change filters in More calendar tools.'],
    ['icon' => 'fa-list-check', 'label' => 'Planned', 'value' => (string) $plannedCount, 'tooltip' => 'Milestones still scheduled for action.'],
    ['icon' => 'fa-clock', 'label' => 'Overdue', 'value' => (string) $overdueCount, 'tooltip' => 'Planned milestones with dates before today.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Conflicts', 'value' => (string) $conflictCount, 'tooltip' => 'Dates at or over recommended calendar capacity.'],
];
$stageCards = [
    [
        'label' => 'Choose Window',
        'icon' => 'fa-calendar-check',
        'status' => 'ready',
        'sentence' => 'Look at this schedule range.',
        'tooltip' => 'Expert view: date_from and date_to filters for marketing_calendar_milestones.',
        'href' => 'marketing_calendar.php',
        'action' => 'View month',
    ],
    [
        'label' => 'Place Work',
        'icon' => 'fa-thumbtack',
        'status' => $plannedCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Put campaign work on dates.',
        'tooltip' => 'Expert view: milestone type, channel, owner, campaign brief, and content links.',
        'href' => $canWriteMarketing ? '#add-marketing-milestone' : 'marketing_content.php',
        'action' => $canWriteMarketing ? 'Add milestone' : 'Review content',
    ],
    [
        'label' => 'Check Load',
        'icon' => 'fa-scale-balanced',
        'status' => $conflictCount > 0 ? 'warning' : 'ready',
        'sentence' => 'Keep dates from getting crowded.',
        'tooltip' => 'Expert view: conflict detection uses daily capacity weight across planned milestones.',
        'href' => '#calendar-conflicts',
        'action' => 'Check load',
    ],
    [
        'label' => 'Move Late Work',
        'icon' => 'fa-arrows-rotate',
        'status' => $overdueCount > 0 ? 'warning' : 'ready',
        'sentence' => 'Reschedule what slipped.',
        'tooltip' => 'Expert view: planned milestones before today should be rescheduled or marked done.',
        'href' => $firstOverdue !== [] ? '#milestone-' . (int) ($firstOverdue['id'] ?? 0) : 'marketing_calendar.php',
        'action' => $overdueCount > 0 ? 'Fix overdue' : 'Looks clear',
    ],
    [
        'label' => 'Finish Step',
        'icon' => 'fa-circle-check',
        'status' => $doneCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Mark completed work done.',
        'tooltip' => 'Expert view: done milestones stay visible as planning evidence.',
        'href' => $firstMilestone !== [] ? '#milestone-' . (int) ($firstMilestone['id'] ?? 0) : 'marketing_reviews.php',
        'action' => 'Review step',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstOverdue !== [] ? [
        'label' => 'Move overdue work',
        'href' => '#milestone-' . (int) ($firstOverdue['id'] ?? 0),
        'reason' => 'Reschedule or close the slipped milestone before adding more work.',
    ] : null,
    $firstMilestone !== [] ? [
        'label' => 'Review next date',
        'href' => '#milestone-' . (int) ($firstMilestone['id'] ?? 0),
        'reason' => 'Open the next milestone and decide if it still belongs on this date.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Add milestone',
        'href' => '#add-marketing-milestone',
        'reason' => 'Place one campaign, review, publish, or operations step on the calendar.',
    ] : null,
    [
        'label' => 'Open reviews',
        'href' => 'marketing_reviews.php',
        'reason' => 'Check approval work that can affect calendar timing.',
    ],
    [
        'label' => 'Open content',
        'href' => 'marketing_content.php',
        'reason' => 'Review content that may need production dates.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the guided command center.'],
    ['label' => 'Reviews', 'href' => 'marketing_reviews.php', 'hint' => 'Check approvals that affect schedule dates.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Open content items that need calendar work.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Connect planned dates to campaign briefs.'],
    ['label' => 'Roadmap', 'href' => 'marketing_roadmap.php', 'hint' => 'Align calendar milestones with launch windows.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check readiness before launch dates.'],
];
$pageTitle = 'Marketing Calendar - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-calendar-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Marketing Calendar</h1>
                <p>Schedule Board: place campaign work on dates without crowding the plan.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#add-marketing-milestone"><i class="fas fa-plus"></i> New Milestone</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Calendar update failed.</strong> <?php echo htmlspecialchars($error); ?> No milestone was changed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">Marketing calendar updated.</div><?php endif; ?>

        <section class="marketing-calendar-shell">
            <div class="marketing-founder-summary marketing-calendar-summary" aria-label="Calendar summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-calendar-stage-grid" aria-label="Calendar path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-calendar-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-calendar-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-calendar-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-calendar-layout">
                <main class="marketing-calendar-main">
                    <section class="content-card marketing-calendar-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Editorial Calendar Plan</h2>
                                <p>Use the next date that needs a decision.</p>
                            </div>
                            <span class="badge <?php echo $plannedCount > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo count($milestones); ?> items</span>
                        </div>

                        <?php if (!empty($calendarConflicts)): ?>
                            <div class="marketing-calendar-conflict-strip" id="calendar-conflicts" tabindex="0" data-tooltip="<?php echo htmlspecialchars(implode('; ', array_map(static fn(array $conflict): string => (string) $conflict['date'] . ' - ' . (string) $conflict['message'], $calendarConflicts))); ?>">
                                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                <strong><?php echo $conflictCount; ?> load warnings</strong>
                                <span>Open More calendar tools for the detailed conflict list.</span>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($milestones)): ?>
                            <div class="empty-state">
                                <p>Use a broader date window or add a launch, review, publishing, or operations milestone.</p>
                                <a class="btn-premium-secondary" href="marketing_calendar.php">Reset to this month</a>
                                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#add-marketing-milestone">Add milestone</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-calendar-date-grid">
                                <?php foreach ($milestonesByDate as $date => $dateMilestones): ?>
                                    <section class="marketing-calendar-date-card">
                                        <div class="marketing-calendar-date-heading">
                                            <strong><?php echo htmlspecialchars(date('M j', strtotime((string) $date))); ?></strong>
                                            <span class="badge badge-default"><?php echo count((array) $dateMilestones); ?> items</span>
                                        </div>
                                        <div class="marketing-calendar-milestone-list">
                                            <?php foreach ($dateMilestones as $milestone): ?>
                                                <?php
                                                $milestoneStatus = (string) ($milestone['status'] ?? 'planned');
                                                $isOverdue = $milestoneStatus === 'planned' && !empty($milestone['milestone_date']) && strtotime((string) $milestone['milestone_date']) < strtotime(date('Y-m-d'));
                                                $cardStatus = $isOverdue ? 'overdue' : $milestoneStatus;
                                                $tooltip = 'Expert view: type ' . $labelize((string) ($milestone['milestone_type'] ?? 'milestone'))
                                                    . ', channel ' . ($milestone['channel'] !== null && (string) $milestone['channel'] !== '' ? $labelize((string) $milestone['channel']) : 'none')
                                                    . ', owner ' . (string) ($milestone['owner_email'] ?? 'unassigned')
                                                    . ', capacity weight ' . (int) ($milestone['capacity_weight'] ?? 1) . '.';
                                                ?>
                                                <article class="marketing-calendar-milestone-card <?php echo htmlspecialchars($cardStatus); ?>" id="milestone-<?php echo (int) $milestone['id']; ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                                    <div class="marketing-calendar-card-top">
                                                        <h3><?php echo htmlspecialchars((string) $milestone['title']); ?></h3>
                                                        <span class="badge <?php echo htmlspecialchars($badgeClass($cardStatus)); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                                                    </div>
                                                    <div class="marketing-calendar-meta">
                                                        <span><?php echo htmlspecialchars($labelize((string) $milestone['milestone_type'])); ?></span>
                                                        <?php if (!empty($milestone['channel'])): ?><span><?php echo htmlspecialchars($labelize((string) $milestone['channel'])); ?></span><?php endif; ?>
                                                        <span>Weight <?php echo (int) ($milestone['capacity_weight'] ?? 1); ?></span>
                                                        <span><?php echo htmlspecialchars((string) ($milestone['owner_email'] ?? 'Unassigned')); ?></span>
                                                    </div>
                                                    <div class="marketing-calendar-links">
                                                        <?php if (!empty($milestone['content_item_id'])): ?><a href="marketing_content_view.php?id=<?php echo (int) $milestone['content_item_id']; ?>"><?php echo htmlspecialchars((string) $milestone['content_title']); ?></a><?php endif; ?>
                                                        <?php if (!empty($milestone['campaign_brief_id'])): ?><a href="marketing_brief_view.php?id=<?php echo (int) $milestone['campaign_brief_id']; ?>"><?php echo htmlspecialchars((string) $milestone['campaign_brief_title']); ?></a><?php endif; ?>
                                                    </div>
                                                    <?php if ($canWriteMarketing && $milestoneStatus === 'planned'): ?>
                                                        <details class="marketing-calendar-card-tools">
                                                            <summary>Update milestone</summary>
                                                            <div class="marketing-calendar-card-tool-body">
                                                                <form method="POST">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                                    <input type="hidden" name="action" value="reschedule">
                                                                    <input type="hidden" name="milestone_id" value="<?php echo (int) $milestone['id']; ?>">
                                                                    <input type="date" name="milestone_date" value="<?php echo htmlspecialchars((string) $milestone['milestone_date']); ?>">
                                                                    <button class="btn-premium-secondary" type="submit">Reschedule</button>
                                                                </form>
                                                                <form method="POST">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                                    <input type="hidden" name="action" value="mark_done">
                                                                    <input type="hidden" name="milestone_id" value="<?php echo (int) $milestone['id']; ?>">
                                                                    <button class="btn-premium-primary" type="submit">Mark done</button>
                                                                </form>
                                                            </div>
                                                        </details>
                                                    <?php endif; ?>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="marketing-calendar-today content-card" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Move one scheduled item forward.</p>
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

            <details class="content-card marketing-calendar-tools" id="add-marketing-milestone">
                <summary>More calendar tools</summary>
                <div class="marketing-calendar-tools-body">
                    <section class="marketing-calendar-filter-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Calendar Filters</h2>
                                <p>Change the dates and expert filters behind the board.</p>
                            </div>
                        </div>
                        <form class="marketing-calendar-filter-form" method="GET">
                            <div class="form-group"><label for="date_from">From</label><input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars((string) $filters['date_from']); ?>"></div>
                            <div class="form-group"><label for="date_to">To</label><input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars((string) $filters['date_to']); ?>"></div>
                            <div class="form-group"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach (Marketing::MILESTONE_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($_GET['status'] ?? '', $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label for="milestone_type">Type</label><select id="milestone_type" name="milestone_type"><option value="">All types</option><?php foreach (Marketing::MILESTONE_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo $selected($_GET['milestone_type'] ?? '', $type); ?>><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label for="channel">Channel</label><select id="channel" name="channel"><option value="">All channels</option><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>" <?php echo $selected($_GET['channel'] ?? '', $channel); ?>><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label for="conflict_status">Conflict</label><select id="conflict_status" name="conflict_status"><option value="">Any conflict state</option><?php foreach (Marketing::CALENDAR_CONFLICT_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($_GET['conflict_status'] ?? '', $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label for="owner_user_id">Owner</label><select id="owner_user_id" name="owner_user_id"><option value="">Any owner</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo ((int) ($_GET['owner_user_id'] ?? 0) === (int) $owner['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                            <button class="btn-premium-primary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_calendar.php">This Month</a>
                        </form>
                    </section>

                    <section class="marketing-calendar-form-card" id="calendar-add-form">
                        <div class="premium-section-header">
                            <div>
                                <h2>Add Milestone</h2>
                                <p>Create one manual calendar step.</p>
                            </div>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <form class="marketing-calendar-create-form" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="create">
                                <div class="form-group"><label for="title">Title</label><input id="title" type="text" name="title" required></div>
                                <div class="form-group"><label for="milestone_date">Date</label><input id="milestone_date" type="date" name="milestone_date" required></div>
                                <div class="form-group"><label for="create_milestone_type">Type</label><select id="create_milestone_type" name="milestone_type"><?php foreach (Marketing::MILESTONE_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="create_channel">Channel</label><select id="create_channel" name="channel"><option value="">No channel</option><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="capacity_weight">Capacity Weight</label><input id="capacity_weight" type="number" name="capacity_weight" min="1" max="10" value="1"></div>
                                <div class="form-group"><label for="create_owner_user_id">Owner</label><select id="create_owner_user_id" name="owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo ((int) $owner['id'] === (int) ($user['id'] ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="campaign_brief_id">Campaign Brief</label><select id="campaign_brief_id" name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="content_item_id">Content</label><select id="content_item_id" name="content_item_id"><option value="">None</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                                <button class="btn-premium-primary marketing-calendar-wide-field" type="submit">Add Milestone</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="marketing-calendar-template-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Calendar Templates</h2>
                                <p>Save repeatable schedule checkpoints.</p>
                            </div>
                        </div>
                        <?php if (empty($calendarTemplates)): ?>
                            <div class="empty-state"><p>No editorial milestone templates yet.</p></div>
                        <?php else: ?>
                            <div class="marketing-calendar-template-grid">
                                <?php foreach ($calendarTemplates as $template): ?>
                                    <article class="marketing-calendar-template-option">
                                        <strong><?php echo htmlspecialchars((string) $template['name']); ?></strong>
                                        <span><?php echo htmlspecialchars($labelize((string) $template['milestone_type'])); ?></span>
                                        <?php if (!empty($template['channel'])): ?><span><?php echo htmlspecialchars($labelize((string) $template['channel'])); ?></span><?php endif; ?>
                                        <span><?php echo (int) $template['offset_days']; ?> day offset</span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($canWriteMarketing): ?>
                            <details class="marketing-calendar-template-tools">
                                <summary>Save template</summary>
                                <form class="marketing-calendar-template-form" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="create_template">
                                    <div class="form-group"><label for="template_name">Template Name</label><input id="template_name" type="text" name="name" placeholder="Launch publish checkpoint"></div>
                                    <div class="form-group"><label for="template_key">Template Key</label><input id="template_key" type="text" name="template_key" placeholder="launch_publish"></div>
                                    <div class="form-group"><label for="default_title">Default Title</label><input id="default_title" type="text" name="default_title" placeholder="Publish launch content"></div>
                                    <div class="form-group"><label for="template_type">Type</label><select id="template_type" name="milestone_type"><?php foreach (Marketing::MILESTONE_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label for="template_channel">Channel</label><select id="template_channel" name="channel"><option value="">No channel</option><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label for="offset_days">Offset Days</label><input id="offset_days" type="number" name="offset_days" value="0"></div>
                                    <div class="form-group marketing-calendar-wide-field"><label for="checklist">Checklist</label><textarea id="checklist" name="checklist" rows="3" placeholder="Brief approved&#10;Media attached"></textarea></div>
                                    <button class="btn-premium-secondary marketing-calendar-wide-field" type="submit">Save Template</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </section>

                    <section class="marketing-calendar-workflow-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Workflow Signals</h2>
                                <p>Extra guidance stays here, not in the first view.</p>
                            </div>
                        </div>
                        <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                    </section>

                    <section class="marketing-calendar-expert-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Expert Routes</h2>
                                <p>Related tools stay available without crowding the board.</p>
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
