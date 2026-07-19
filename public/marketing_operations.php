<?php
/**
 * Recurring marketing operations and weekly planning queues.
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
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to manage marketing operations.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = (string) ($_POST['action'] ?? 'queue');
        if ($action === 'rhythm') {
            $marketing->createRecurringRhythm([
                'name' => $_POST['name'] ?? '',
                'rhythm_type' => $_POST['rhythm_type'] ?? 'planning',
                'cadence' => $_POST['cadence'] ?? 'weekly',
                'day_of_week' => $_POST['day_of_week'] ?? '',
                'next_run_at' => $_POST['next_run_at'] ?? '',
                'checklist' => $_POST['checklist'] ?? '',
                'owner_user_id' => (int) ($user['id'] ?? 0),
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_operations.php?success=rhythm');
            exit;
        }
        if ($action === 'template') {
            $marketing->createChecklistTemplate([
                'name' => $_POST['name'] ?? '',
                'template_type' => $_POST['template_type'] ?? 'content',
                'checklist' => $_POST['checklist'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_operations.php?success=template');
            exit;
        }
        if ($action === 'status') {
            $marketing->updatePlanningQueueStatus((int) ($_POST['queue_id'] ?? 0), (string) ($_POST['status'] ?? 'planned'));
            header('Location: ' . getBasePath() . '/marketing_operations.php?success=status');
            exit;
        }

        $marketing->createPlanningQueueItem([
            'title' => $_POST['title'] ?? '',
            'week_start' => $_POST['week_start'] ?? '',
            'item_type' => $_POST['item_type'] ?? 'content',
            'priority' => $_POST['priority'] ?? 'medium',
            'status' => $_POST['status'] ?? 'planned',
            'rhythm_id' => $_POST['rhythm_id'] ?? 0,
            'content_item_id' => $_POST['content_item_id'] ?? 0,
            'campaign_brief_id' => $_POST['campaign_brief_id'] ?? 0,
            'due_at' => $_POST['due_at'] ?? '',
            'owner_user_id' => (int) ($user['id'] ?? 0),
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        header('Location: ' . getBasePath() . '/marketing_operations.php?success=queue');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$weekStart = trim((string) ($_GET['week_start'] ?? ''));
try {
    $report = $marketing->getMarketingOperatingReport($weekStart !== '' ? $weekStart : null);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
    $report = $marketing->getMarketingOperatingReport();
}

$rhythms = $marketing->listRecurringRhythms(['status' => 'active'], 100, 0);
$items = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$briefs = $marketing->listCampaignBriefs(['status_open' => true], 100, 0);
$templates = $marketing->listChecklistTemplates(['status' => 'active'], 100, 0);
$queue = $marketing->listPlanningQueueItems(['week_start' => (string) ($report['week_start'] ?? date('Y-m-d')), 'open' => true], 100, 0);
$aiQueue = $marketing->listMarketingAiQueueSuggestions('', 12, 0);
$automationReadiness = $marketing->getMarketingAutomationReadiness((int) ($user['id'] ?? 0));
$automationLoops = (array) ($automationReadiness['loops'] ?? []);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';

$counts = (array) ($report['counts'] ?? []);
$weekLabel = trim((string) ($report['week_start'] ?? date('Y-m-d')) . ' to ' . (string) ($report['week_end'] ?? date('Y-m-d')));
$activeRhythms = (int) ($counts['active_rhythms'] ?? count($rhythms));
$queueThisWeek = (int) ($counts['queue_this_week'] ?? count($queue));
$openQueue = (int) ($counts['open_queue'] ?? count($queue));
$overdueQueue = (int) ($counts['overdue_queue'] ?? 0);
$aiSuggestionCount = count($aiQueue);
$automationScore = (int) ($automationReadiness['score'] ?? 0);
$automationStatus = (string) ($automationReadiness['status'] ?? 'attention');
$readyLoops = count(array_filter($automationLoops, static fn(array $loop): bool => (string) ($loop['status'] ?? '') === 'ready'));
$attentionLoops = count(array_filter($automationLoops, static fn(array $loop): bool => in_array((string) ($loop['status'] ?? ''), ['attention', 'missing'], true)));

$summaryCards = [
    ['label' => 'Rhythms', 'value' => $activeRhythms, 'icon' => 'fa-repeat', 'tooltip' => 'Recurring operating rhythms that keep planning, reviews, and campaign work moving.'],
    ['label' => 'This Week', 'value' => $queueThisWeek, 'icon' => 'fa-calendar-week', 'tooltip' => 'Planning queue items attached to the selected week.'],
    ['label' => 'Open', 'value' => $openQueue, 'icon' => 'fa-list-check', 'tooltip' => 'Work still waiting for a founder or operator decision.'],
    ['label' => 'Overdue', 'value' => $overdueQueue, 'icon' => 'fa-triangle-exclamation', 'tooltip' => 'Queue items that need attention before the week can move cleanly.'],
];

$operationStages = [
    [
        'title' => 'Pick Week',
        'icon' => 'fa-calendar-days',
        'status' => 'ready',
        'badge' => 'Ready',
        'metric' => (string) ($report['week_start'] ?? date('Y-m-d')),
        'metric_label' => 'selected',
        'tooltip' => 'Choose the planning week before reviewing or changing the work queue.',
        'action_label' => 'Change week',
        'action_href' => '#planning-week',
    ],
    [
        'title' => 'Run Queue',
        'icon' => 'fa-list-check',
        'status' => $openQueue > 0 ? 'in_use' : 'setup_needed',
        'badge' => $openQueue > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $openQueue,
        'metric_label' => 'open',
        'tooltip' => 'The weekly queue is the short list of marketing work to move next.',
        'action_label' => 'Open queue',
        'action_href' => '#weekly-queue',
    ],
    [
        'title' => 'Clear Blocks',
        'icon' => 'fa-check-double',
        'status' => $overdueQueue > 0 ? 'setup_needed' : 'ready',
        'badge' => $overdueQueue > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $overdueQueue,
        'metric_label' => 'overdue',
        'tooltip' => 'Overdue items show where marketing momentum is waiting for a decision.',
        'action_label' => 'Review',
        'action_href' => '#weekly-queue',
    ],
    [
        'title' => 'Use Assist',
        'icon' => 'fa-wand-magic-sparkles',
        'status' => $aiSuggestionCount > 0 ? 'in_use' : 'ready',
        'badge' => $aiSuggestionCount > 0 ? 'In use' : 'Ready',
        'metric' => (string) $aiSuggestionCount,
        'metric_label' => 'ideas',
        'tooltip' => 'AI suggestions stay as optional founder help, not a wall of expert instructions.',
        'action_label' => 'See ideas',
        'action_href' => '#ai-queue',
    ],
    [
        'title' => 'Keep Rhythm',
        'icon' => 'fa-arrows-rotate',
        'status' => $activeRhythms > 0 ? 'in_use' : 'setup_needed',
        'badge' => $activeRhythms > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $activeRhythms,
        'metric_label' => 'rhythms',
        'tooltip' => 'Rhythms turn repeated marketing chores into a predictable weekly operating habit.',
        'action_label' => 'More tools',
        'action_href' => '#operations-tools',
    ],
];

$todayActions = [];
if ($overdueQueue > 0) {
    $todayActions[] = ['label' => 'Clear overdue work', 'href' => '#weekly-queue', 'meta' => $overdueQueue . ' overdue'];
}
if ($queueThisWeek === 0) {
    $todayActions[] = ['label' => 'Add first queue item', 'href' => '#operations-tools', 'meta' => 'start the week'];
}
if ($aiSuggestionCount > 0) {
    $todayActions[] = ['label' => 'Review AI suggestions', 'href' => '#ai-queue', 'meta' => $aiSuggestionCount . ' ideas'];
}
if ($activeRhythms === 0) {
    $todayActions[] = ['label' => 'Create a weekly rhythm', 'href' => '#operations-tools', 'meta' => 'repeatable habit'];
}
if ($automationScore < 80) {
    $todayActions[] = ['label' => 'Check manual loops', 'href' => '#manual-automation', 'meta' => $automationScore . '% ready'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open marketing calendar', 'href' => 'marketing_calendar.php', 'meta' => 'next view'];
}
$todayActions = array_slice($todayActions, 0, 5);

$automationSignals = [
    ['label' => 'Automation Score', 'value' => $automationScore . '%', 'hint' => $labelize($automationStatus)],
    ['label' => 'Ready Loops', 'value' => (string) $readyLoops, 'hint' => 'manual-ready'],
    ['label' => 'Needs Attention', 'value' => (string) $attentionLoops, 'hint' => 'review needed'],
    ['label' => 'Product Decision', 'value' => $automationScore >= 80 ? 'Ready' : 'Manual', 'hint' => 'no external send'],
];

$pageTitle = 'Marketing Operations - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-operations-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Marketing Operations</h1>
                <p>Plan the week and keep campaign work moving.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_calendar.php"><i class="fas fa-calendar-days"></i> Calendar</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Operations update failed.</strong> <?php echo $h($error); ?> The planning workflow was left unchanged.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'rhythm'): ?><div class="alert alert-success">Recurring rhythm created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'queue'): ?><div class="alert alert-success">Planning queue item created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'template'): ?><div class="alert alert-success">Checklist template created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'status'): ?><div class="alert alert-success">Planning queue status updated.</div><?php endif; ?>

        <section class="marketing-operations-shell" aria-label="Marketing operations board">
            <form class="marketing-operations-week" id="planning-week" method="GET" data-tooltip="Change the week before you review the queue." tabindex="0">
                <i class="fas fa-calendar-week"></i>
                <label for="week_start">Planning week</label>
                <input id="week_start" type="date" name="week_start" value="<?php echo $h((string) ($report['week_start'] ?? date('Y-m-d'))); ?>">
                <button class="btn-premium-secondary" type="submit">Open</button>
                <a class="btn-premium-secondary" href="marketing_operations.php">This week</a>
            </form>

            <div class="marketing-founder-summary marketing-operations-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-operations-stage-grid" aria-label="Weekly operations path">
                <?php foreach ($operationStages as $stage): ?>
                    <article class="marketing-operations-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-operations-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-operations-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-operations-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-operations-layout">
                <section class="marketing-operations-board" id="weekly-queue">
                    <div class="premium-section-header">
                        <h2>Weekly Queue</h2>
                        <p><?php echo $h($weekLabel); ?></p>
                    </div>
                    <div class="marketing-operations-list">
                        <?php if (empty($queue)): ?>
                            <div class="empty-state"><p>No open planning queue items for this week.</p><a href="#operations-tools">Add queue item</a></div>
                        <?php else: foreach ($queue as $item): ?>
                            <article class="marketing-operations-card <?php echo $h((string) ($item['priority'] ?? 'medium')); ?>" data-tooltip="<?php echo $h($labelize((string) ($item['item_type'] ?? 'content')) . ' work planned for this week.'); ?>" tabindex="0">
                                <div>
                                    <span class="marketing-stage-status"><?php echo $h($labelize((string) ($item['priority'] ?? 'medium'))); ?></span>
                                    <h3><?php echo $h((string) $item['title']); ?></h3>
                                    <div class="marketing-operations-meta">
                                        <span><?php echo $h($labelize((string) $item['item_type'])); ?></span>
                                        <span><?php echo $h($labelize((string) $item['status'])); ?></span>
                                        <?php if (!empty($item['content_title'])): ?><span><?php echo $h((string) $item['content_title']); ?></span><?php endif; ?>
                                        <?php if (!empty($item['campaign_brief_title'])): ?><span><?php echo $h((string) $item['campaign_brief_title']); ?></span><?php endif; ?>
                                        <?php if (!empty($item['rhythm_name'])): ?><span><?php echo $h((string) $item['rhythm_name']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="marketing-operations-card-action">
                                    <small><?php echo $h((string) ($item['due_at'] ?? 'No due date')); ?></small>
                                    <?php if ($canWriteMarketing): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="status">
                                            <input type="hidden" name="queue_id" value="<?php echo (int) $item['id']; ?>">
                                            <select name="status" aria-label="Queue status"><?php foreach (Marketing::PLANNING_QUEUE_STATUSES as $status): ?><option value="<?php echo $h($status); ?>" <?php echo $selected($item['status'], $status); ?>><?php echo $h($labelize($status)); ?></option><?php endforeach; ?></select>
                                            <button class="btn-premium-secondary" type="submit">Update</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

                <aside class="marketing-operations-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One clear move for the operating week.</p>
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

            <details class="marketing-operations-tools" id="operations-tools">
                <summary>More operations tools</summary>
                <div class="marketing-operations-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_calendar.php" data-tooltip="See campaign milestones and due dates."><i class="fas fa-calendar-days"></i><span>Calendar</span></a>
                        <a href="marketing_task_hub.php" data-tooltip="Open the wider task queue for marketing execution."><i class="fas fa-list-check"></i><span>Task Hub</span></a>
                        <a href="marketing_assistants.php" data-tooltip="Use AI to find gaps and suggest next marketing work."><i class="fas fa-wand-magic-sparkles"></i><span>AI Assistants</span></a>
                        <a href="marketing_weekly_report.php" data-tooltip="Review what happened this week."><i class="fas fa-chart-line"></i><span>Weekly Report</span></a>
                        <a href="marketing.php" data-tooltip="Return to the visual Marketing command path."><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-operations-detail-grid">
                        <article class="marketing-operations-detail-section" id="ai-queue">
                            <div class="premium-section-header"><h2>AI Queue Suggestions</h2><p>Optional founder help for gaps and next moves.</p></div>
                            <div class="marketing-operations-list compact">
                                <?php if (empty($aiQueue)): ?><div class="empty-state"><p>No open AI queue suggestions.</p><a href="marketing_assistants.php">Run strategy gap analysis</a></div><?php else: foreach ($aiQueue as $item): ?>
                                    <?php $meta = (array) ($item['metadata_json'] ?? []); ?>
                                    <article class="marketing-operations-mini-card" data-tooltip="<?php echo $h((string) ($meta['reason'] ?? 'AI suggestion')); ?>" tabindex="0">
                                        <strong><?php echo $h((string) $item['title']); ?></strong>
                                        <span><?php echo $h($labelize((string) ($meta['ai_phase'] ?? 'marketing_ai'))); ?> · <?php echo $h($labelize((string) $item['priority'])); ?></span>
                                        <small><?php echo $h((string) ($meta['recommended_action'] ?? 'Review')); ?></small>
                                    </article>
                                <?php endforeach; endif; ?>
                            </div>
                        </article>

                        <article class="marketing-operations-detail-section" id="manual-automation">
                            <div class="premium-section-header">
                                <h2>Manual Automation Readiness</h2>
                                <p>No external send or publish happens here.</p>
                            </div>
                            <div class="marketing-operations-signal-grid">
                                <?php foreach ($automationSignals as $signal): ?>
                                    <article class="marketing-operations-signal-card" data-tooltip="<?php echo $h($signal['hint']); ?>" tabindex="0">
                                        <span><?php echo $h($signal['label']); ?></span>
                                        <strong><?php echo $h($signal['value']); ?></strong>
                                        <small><?php echo $h($signal['hint']); ?></small>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <div class="marketing-operations-loop-grid">
                                <?php foreach ($automationLoops as $loop): ?>
                                    <?php $loopStatus = (string) ($loop['status'] ?? 'attention'); ?>
                                    <article class="marketing-operations-loop-card <?php echo $h($loopStatus); ?>" data-tooltip="<?php echo $h((string) ($loop['description'] ?? 'Review this loop.')); ?>" tabindex="0">
                                        <span class="marketing-stage-status"><?php echo $h($labelize($loopStatus)); ?></span>
                                        <h3><?php echo $h((string) ($loop['label'] ?? 'Automation loop')); ?></h3>
                                        <small><?php echo $h((string) ($loop['next_action'] ?? 'Review readiness.')); ?></small>
                                        <a class="btn-premium-secondary" href="<?php echo $h((string) ($loop['href'] ?? 'marketing_operations.php')); ?>">Open</a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-operations-detail-grid">
                        <article class="marketing-operations-detail-section">
                            <div class="premium-section-header"><h2>Recurring Rhythms</h2></div>
                            <div class="marketing-operations-list compact">
                                <?php if (empty($rhythms)): ?><div class="empty-state"><p>No active recurring marketing rhythms yet.</p></div><?php else: foreach ($rhythms as $rhythm): ?>
                                    <article class="marketing-operations-mini-card">
                                        <strong><?php echo $h((string) $rhythm['name']); ?></strong>
                                        <span><?php echo $h($labelize((string) $rhythm['rhythm_type'])); ?> · <?php echo $h($labelize((string) $rhythm['cadence'])); ?></span>
                                        <small><?php echo $h((string) ($rhythm['next_run_at'] ?? 'No next run')); ?></small>
                                    </article>
                                <?php endforeach; endif; ?>
                            </div>
                        </article>

                        <article class="marketing-operations-detail-section">
                            <div class="premium-section-header"><h2>Checklist Templates</h2></div>
                            <div class="marketing-operations-list compact">
                                <?php if (empty($templates)): ?><div class="empty-state"><p>No recurring checklist templates yet.</p></div><?php else: foreach ($templates as $template): ?>
                                    <article class="marketing-operations-mini-card">
                                        <strong><?php echo $h((string) $template['name']); ?></strong>
                                        <span><?php echo $h($labelize((string) $template['template_type'])); ?></span>
                                        <small><?php echo count((array) ($template['checklist_json'] ?? [])); ?> steps</small>
                                    </article>
                                <?php endforeach; endif; ?>
                            </div>
                        </article>
                    </section>

                    <?php if ($canWriteMarketing): ?>
                        <section class="marketing-operations-form-grid">
                            <article class="marketing-operations-form-card" id="create-queue-item">
                                <div class="premium-section-header"><h2>Add Queue Item</h2></div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="queue">
                                    <div class="form-group"><label>Title</label><input name="title" required></div>
                                    <div class="form-group"><label>Planning Week</label><input type="date" name="week_start" value="<?php echo $h((string) ($report['week_start'] ?? date('Y-m-d'))); ?>"></div>
                                    <div class="form-group"><label>Type</label><select name="item_type"><?php foreach (Marketing::PLANNING_QUEUE_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::PLANNING_QUEUE_PRIORITIES as $priority): ?><option value="<?php echo $h($priority); ?>"><?php echo $h($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Rhythm</label><select name="rhythm_id"><option value="">Optional</option><?php foreach ($rhythms as $rhythm): ?><option value="<?php echo (int) $rhythm['id']; ?>"><?php echo $h((string) $rhythm['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Brief</label><select name="campaign_brief_id"><option value="">Optional</option><?php foreach ($briefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo $h((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Due</label><input type="datetime-local" name="due_at"></div>
                                    <button class="btn-premium-primary" type="submit">Add Queue Item</button>
                                </form>
                            </article>

                            <article class="marketing-operations-form-card" id="create-marketing-rhythm">
                                <div class="premium-section-header"><h2>Create Rhythm</h2></div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="rhythm">
                                    <div class="form-group"><label>Name</label><input name="name" required></div>
                                    <div class="form-group"><label>Type</label><select name="rhythm_type"><?php foreach (Marketing::RHYTHM_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Cadence</label><select name="cadence"><?php foreach (Marketing::RHYTHM_CADENCES as $cadence): ?><option value="<?php echo $h($cadence); ?>"><?php echo $h($labelize($cadence)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Day Of Week</label><select name="day_of_week"><option value="">Flexible</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="0">Sunday</option></select></div>
                                    <div class="form-group"><label>Next Run</label><input type="datetime-local" name="next_run_at"></div>
                                    <div class="form-group"><label>Checklist</label><textarea name="checklist" rows="3" placeholder="One step per line"></textarea></div>
                                    <button class="btn-premium-secondary" type="submit">Create Rhythm</button>
                                </form>
                            </article>

                            <article class="marketing-operations-form-card">
                                <div class="premium-section-header"><h2>Create Template</h2></div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="template">
                                    <div class="form-group"><label>Name</label><input name="name" required></div>
                                    <div class="form-group"><label>Type</label><select name="template_type"><?php foreach (Marketing::CHECKLIST_TEMPLATE_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Checklist</label><textarea name="checklist" rows="4" placeholder="One step per line"></textarea></div>
                                    <button class="btn-premium-secondary" type="submit">Create Template</button>
                                </form>
                            </article>
                        </section>
                    <?php else: ?>
                        <div class="content-card"><div class="empty-state"><p>Read-only access.</p></div></div>
                    <?php endif; ?>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
