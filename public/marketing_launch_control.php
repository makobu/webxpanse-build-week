<?php
/**
 * Marketing launch control room.
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
$options = $marketing->optionData();
$readinessReviews = Database::tableExists('marketing_launch_readiness_reviews') ? $marketing->listLaunchReadinessReviews([], 250, 0) : [];
$distributionPosts = Database::tableExists('marketing_distribution_posts') ? $marketing->listDistributionPosts([], 250, 0) : [];
$emailRuns = Database::tableExists('marketing_email_campaign_runs') ? $marketing->listEmailCampaignRuns([], 250, 0) : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'prepare');
        if ($action === 'manual_launch') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to mark launches as manually launched.');
            }
            $marketing->markLaunchControlManuallyLaunched((int) ($_POST['launch_control_id'] ?? 0), (int) ($user['id'] ?? 0), (string) ($_POST['launch_note'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_launch_control.php?success=launched');
            exit;
        }
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive launch control records.');
            }
            $marketing->archiveLaunchControlRecord((int) ($_POST['launch_control_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_launch_control.php?success=archived');
            exit;
        }
        if ($action === 'status') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update launch control status.');
            }
            $marketing->updateLaunchControlStatus((int) ($_POST['launch_control_id'] ?? 0), (string) ($_POST['status'] ?? 'planning'));
            header('Location: ' . getBasePath() . '/marketing_launch_control.php?success=updated');
            exit;
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to prepare launch control records.');
        }
        $control = $marketing->prepareLaunchControl($_POST + ['created_by' => (int) ($user['id'] ?? 0)], (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_launch_control.php?success=prepared&id=' . (int) ($control['id'] ?? 0));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$filters = $status !== '' ? ['status' => $status] : ['open' => true];
$records = $marketing->listLaunchControlRecords($filters, 100, 0);
$summary = $marketing->getLaunchControlSummary();
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'launched_manual', 'completed' => 'badge-success',
        'blocked' => 'badge-danger',
        'planning', 'paused', 'setup_needed', 'warning' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$openCount = (int) ($summary['counts']['open'] ?? count($records));
$readyCount = (int) ($summary['counts']['ready'] ?? count(array_filter($records, static fn(array $record): bool => (string) ($record['status'] ?? '') === 'ready')));
$blockedCount = (int) ($summary['counts']['blocked'] ?? count(array_filter($records, static fn(array $record): bool => (string) ($record['status'] ?? '') === 'blocked')));
$manualLaunchCount = (int) ($summary['counts']['launched_manual'] ?? 0);
$firstRecord = (array) ($records[0] ?? []);
$firstReadyRecord = (array) (array_values(array_filter($records, static fn(array $record): bool => (string) ($record['status'] ?? '') === 'ready'))[0] ?? []);
$firstBlockedRecord = (array) (array_values(array_filter($records, static fn(array $record): bool => (string) ($record['status'] ?? '') === 'blocked'))[0] ?? []);
$summaryTiles = [
    ['icon' => 'fa-tower-broadcast', 'label' => 'Open', 'value' => (string) $openCount, 'tooltip' => 'Launch control records still moving toward manual launch or closure.'],
    ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $readyCount, 'tooltip' => 'Launches that can be manually launched by a manager.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $blockedCount, 'tooltip' => 'Launches that still have readiness or evidence blockers.'],
    ['icon' => 'fa-paper-plane', 'label' => 'Manual', 'value' => (string) $manualLaunchCount, 'tooltip' => 'Launches recorded as manually launched. No external send or publish action is performed here.'],
];
$stageCards = [
    [
        'label' => 'Choose Launch',
        'icon' => 'fa-bullseye',
        'status' => $canWriteMarketing ? 'ready' : 'setup_needed',
        'sentence' => 'Prepare the control record.',
        'tooltip' => 'Founder view: select the launch, readiness review, audience, content, distribution package, email run, and owner.',
        'href' => '#prepare-launch-control',
        'action' => 'Prepare',
    ],
    [
        'label' => 'Check Readiness',
        'icon' => 'fa-clipboard-check',
        'status' => $firstRecord !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Confirm the proof.',
        'tooltip' => 'Expert view: launch control inherits readiness score, checklist, campaign, audience, landing, content, distribution, and email evidence.',
        'href' => 'marketing_launch_readiness.php',
        'action' => 'Open readiness',
    ],
    [
        'label' => 'Clear Blocker',
        'icon' => 'fa-triangle-exclamation',
        'status' => $blockedCount > 0 ? 'blocked' : 'ready',
        'sentence' => 'Fix risky launches.',
        'tooltip' => 'Blocked records stay visible until missing readiness or launch evidence is resolved.',
        'href' => $firstBlockedRecord !== [] ? '#launch-control-' . (int) ($firstBlockedRecord['id'] ?? 0) : '#launch-queue',
        'action' => 'View gaps',
    ],
    [
        'label' => 'Manual Launch',
        'icon' => 'fa-paper-plane',
        'status' => $canManageMarketing && $readyCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Record the launch.',
        'tooltip' => 'Manual-first: managers record work performed outside the CRM. No external send, publish, or channel API call happens here.',
        'href' => $firstReadyRecord !== [] ? '#launch-control-' . (int) ($firstReadyRecord['id'] ?? 0) : '#launch-queue',
        'action' => 'Open ready',
    ],
    [
        'label' => 'Learn',
        'icon' => 'fa-chart-line',
        'status' => $manualLaunchCount > 0 ? 'ready' : 'warning',
        'sentence' => 'Watch what worked.',
        'tooltip' => 'Expert view: after manual launch, move into UTM, handoff, performance, weekly report, and monthly report evidence.',
        'href' => 'marketing_performance.php',
        'action' => 'Open results',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstBlockedRecord !== [] ? [
        'label' => 'Clear launch blocker',
        'href' => '#launch-control-' . (int) ($firstBlockedRecord['id'] ?? 0),
        'reason' => 'Open the blocked control record and resolve the missing evidence.',
    ] : null,
    $firstReadyRecord !== [] ? [
        'label' => 'Record ready launch',
        'href' => '#launch-control-' . (int) ($firstReadyRecord['id'] ?? 0),
        'reason' => 'Managers can mark the launch as manually completed.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Prepare launch control',
        'href' => '#prepare-launch-control',
        'reason' => 'Turn a readiness review into an execution record.',
    ] : null,
    [
        'label' => 'Open launch readiness',
        'href' => 'marketing_launch_readiness.php',
        'reason' => 'Check proof before the control record moves forward.',
    ],
    [
        'label' => 'Open performance',
        'href' => 'marketing_performance.php',
        'reason' => 'Review results after manual launch.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check launch evidence before manual launch.'],
    ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php', 'hint' => 'Open reusable launch task lists.'],
    ['label' => 'Audience Activation', 'href' => 'marketing_audience_activation.php', 'hint' => 'Confirm the launch audience.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Review manual channel packages.'],
    ['label' => 'Handoffs', 'href' => 'marketing_handoffs.php', 'hint' => 'Prepare sales or service handoff notes.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Close the launch learning loop.'],
];
$pageTitle = 'Launch Control Room - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-launch-control-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Launch Control Room</h1>
                <p>Control Board: move ready launches through manual execution.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="#prepare-launch-control"><i class="fas fa-tower-broadcast"></i> Prepare Launch</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'prepared'): ?><div class="alert alert-success">Launch control record prepared.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'launched'): ?><div class="alert alert-success">Manual launch recorded. No external publishing or sending was performed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'updated'): ?><div class="alert alert-success">Launch control status updated.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Launch control record archived.</div><?php endif; ?>

        <section class="marketing-launch-control-shell">
            <div class="marketing-founder-summary marketing-launch-control-summary" aria-label="Launch control summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-launch-control-stage-grid" aria-label="Launch control path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-launch-control-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-launch-control-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-launch-control-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-launch-control-layout">
                <main class="marketing-launch-control-main">
                    <section class="content-card marketing-launch-control-board" id="launch-queue">
                        <div class="premium-section-header">
                            <div>
                                <h2>Launch Queue</h2>
                                <p>Manual launch records with risk gates visible.</p>
                            </div>
                            <span class="badge <?php echo $blockedCount > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $blockedCount > 0 ? 'Blocked' : 'Clear'; ?></span>
                        </div>
                        <?php if (empty($records)): ?>
                            <div class="empty-state">
                                <p>No launch control records match this view. Prepare one from a ready launch readiness review.</p>
                                <a href="marketing_launch_readiness.php">Open Launch Readiness</a>
                                <?php if ($canWriteMarketing): ?><a href="#prepare-launch-control">Prepare launch control</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-launch-control-card-grid">
                                <?php foreach ($records as $record): ?>
                                    <?php
                                    $checks = (array) ($record['checklist_json'] ?? []);
                                    $blockers = (array) ($record['blocker_json'] ?? []);
                                    $recordStatus = (string) ($record['status'] ?? 'planning');
                                    $primaryHref = !empty($record['launch_readiness_review_id']) ? 'marketing_launch_readiness.php?id=' . (int) $record['launch_readiness_review_id'] : '#launch-control-' . (int) ($record['id'] ?? 0);
                                    $primaryLabel = !empty($record['launch_readiness_review_id']) ? 'Open readiness' : 'Open details';
                                    $tooltip = $blockers !== []
                                        ? 'Blocked by: ' . implode(', ', array_map($labelize, $blockers))
                                        : 'Expert view: readiness score, launch owner, campaign evidence, checklist state, status controls, manual launch log, and archive controls.';
                                    ?>
                                    <article class="marketing-launch-control-card <?php echo htmlspecialchars($recordStatus); ?>" id="launch-control-<?php echo (int) ($record['id'] ?? 0); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                        <div class="marketing-stage-visual">
                                            <i class="fas fa-tower-broadcast" aria-hidden="true"></i>
                                            <span><?php echo (int) ($record['readiness_score'] ?? 0); ?>%</span>
                                        </div>
                                        <div class="marketing-launch-control-card-top">
                                            <div>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass($recordStatus)); ?>"><?php echo htmlspecialchars($labelize($recordStatus)); ?></span>
                                                <h3><?php echo htmlspecialchars((string) ($record['launch_name'] ?? 'Launch control')); ?></h3>
                                            </div>
                                        </div>
                                        <div class="marketing-launch-control-meta">
                                            <span><?php echo htmlspecialchars((string) ($record['launch_date'] ?? 'No date')); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($record['owner_email'] ?? 'No owner')); ?></span>
                                        </div>
                                        <div class="marketing-launch-control-signal-grid">
                                            <div><strong><?php echo count($checks); ?></strong><span>Checks</span></div>
                                            <div><strong><?php echo count($blockers); ?></strong><span>Blockers</span></div>
                                            <div><strong><?php echo !empty($record['manual_launch_log_json']) ? count((array) $record['manual_launch_log_json']) : 0; ?></strong><span>Logs</span></div>
                                        </div>
                                        <a class="btn-premium-secondary marketing-launch-control-card-action" href="<?php echo htmlspecialchars($primaryHref); ?>"><?php echo htmlspecialchars($primaryLabel); ?></a>
                                        <details class="marketing-launch-control-card-tools">
                                            <summary>Launch evidence and controls</summary>
                                            <div class="marketing-launch-control-card-tool-body">
                                                <?php if ($blockers !== []): ?>
                                                    <div class="marketing-launch-control-signal"><strong>Blockers</strong><small><?php echo htmlspecialchars(implode(', ', array_map($labelize, $blockers))); ?></small></div>
                                                <?php endif; ?>
                                                <div class="marketing-launch-control-link-row">
                                                    <?php if (!empty($record['campaign_brief_title'])): ?><span>Brief: <?php echo htmlspecialchars((string) $record['campaign_brief_title']); ?></span><?php endif; ?>
                                                    <?php if (!empty($record['audience_activation_name'])): ?><span>Audience: <?php echo htmlspecialchars((string) $record['audience_activation_name']); ?></span><?php endif; ?>
                                                    <?php if (!empty($record['content_title'])): ?><span>Content: <?php echo htmlspecialchars((string) $record['content_title']); ?></span><?php endif; ?>
                                                    <?php if (!empty($record['distribution_channel'])): ?><span>Distribution: <?php echo htmlspecialchars($labelize((string) $record['distribution_channel'])); ?></span><?php endif; ?>
                                                </div>
                                                <?php if ($checks !== []): ?>
                                                    <div class="marketing-launch-control-check-grid">
                                                        <?php foreach ($checks as $check): ?>
                                                            <div class="marketing-launch-control-check">
                                                                <strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'Check')); ?></strong>
                                                                <small><?php echo htmlspecialchars($labelize((string) ($check['status'] ?? 'warning'))); ?>: <?php echo htmlspecialchars((string) ($check['message'] ?? '')); ?></small>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="marketing-launch-control-link-row">
                                                    <?php if (!empty($record['launch_readiness_review_id'])): ?><a href="marketing_launch_readiness.php?id=<?php echo (int) $record['launch_readiness_review_id']; ?>">Readiness</a><?php endif; ?>
                                                    <?php if (!empty($record['campaign_brief_id'])): ?><a href="marketing_brief_view.php?id=<?php echo (int) $record['campaign_brief_id']; ?>">Brief</a><?php endif; ?>
                                                </div>
                                                <?php if ($canWriteMarketing): ?>
                                                    <form method="POST" class="marketing-launch-control-status-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="status">
                                                        <input type="hidden" name="launch_control_id" value="<?php echo (int) $record['id']; ?>">
                                                        <select name="status" aria-label="Launch control status"><?php foreach (Marketing::LAUNCH_CONTROL_STATUSES as $controlStatus): ?><option value="<?php echo htmlspecialchars($controlStatus); ?>" <?php echo $selected($controlStatus, $record['status']); ?>><?php echo htmlspecialchars($labelize($controlStatus)); ?></option><?php endforeach; ?></select>
                                                        <button class="btn-premium-secondary" type="submit">Save status</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing && $recordStatus === 'ready'): ?>
                                                    <form method="POST" class="marketing-launch-control-inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="manual_launch">
                                                        <input type="hidden" name="launch_control_id" value="<?php echo (int) $record['id']; ?>">
                                                        <input type="hidden" name="launch_note" value="Manual launch confirmed from Launch Control Room.">
                                                        <button class="btn-premium-primary" type="submit">Mark manually launched</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" class="marketing-launch-control-inline-form" onsubmit="return confirm('Archive this launch control record?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="launch_control_id" value="<?php echo (int) $record['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Archive record</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="content-card marketing-launch-control-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Keep launch execution controlled.</p>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-item" href="<?php echo htmlspecialchars((string) $action['href']); ?>">
                                <strong><?php echo htmlspecialchars((string) $action['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $action['reason']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-launch-control-tools">
                <summary>More launch control tools</summary>
                <div class="marketing-launch-control-tools-body">
                    <section class="content-card marketing-launch-control-filter-card">
                        <div class="premium-section-header"><h2>Queue Filter</h2></div>
                        <form method="GET" class="marketing-launch-control-filter-form">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">Open</option>
                                    <?php foreach (Marketing::LAUNCH_CONTROL_STATUSES as $controlStatus): ?><option value="<?php echo htmlspecialchars($controlStatus); ?>" <?php echo $selected($status, $controlStatus); ?>><?php echo htmlspecialchars($labelize($controlStatus)); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_launch_control.php">Reset</a>
                        </form>
                    </section>

                    <section class="content-card marketing-launch-control-form-card" id="prepare-launch-control">
                        <div class="premium-section-header"><div><h2>Prepare Launch</h2><p>Build a manual launch record from readiness and campaign evidence.</p></div></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-launch-control-prepare-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="prepare">
                                <div class="form-group"><label>Launch Name</label><input type="text" name="launch_name" placeholder="Q3 campaign launch"></div>
                                <div class="form-group"><label>Launch Date</label><input type="date" name="launch_date"></div>
                                <div class="form-group"><label>Readiness Review</label><select name="launch_readiness_review_id"><option value="">None</option><?php foreach ($readinessReviews as $review): ?><option value="<?php echo (int) $review['id']; ?>"><?php echo htmlspecialchars((string) $review['launch_name'] . ' - ' . $labelize((string) $review['status'])); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Audience Activation</label><select name="audience_activation_id"><option value="">Auto-detect</option><?php foreach (($options['audience_activations'] ?? []) as $activation): ?><option value="<?php echo (int) $activation['id']; ?>"><?php echo htmlspecialchars((string) $activation['activation_name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign Brief</label><select name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $page): ?><option value="<?php echo (int) $page['id']; ?>"><?php echo htmlspecialchars((string) $page['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Content Item</label><select name="content_item_id"><option value="">None</option><?php foreach ($options['content_items'] as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Distribution Post</label><select name="distribution_post_id"><option value="">None</option><?php foreach ($distributionPosts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars((string) ($post['content_title'] ?? 'Distribution') . ' - ' . $labelize((string) ($post['channel'] ?? 'other'))); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Email Run</label><select name="email_run_id"><option value="">None</option><?php foreach ($emailRuns as $run): ?><option value="<?php echo (int) $run['id']; ?>"><?php echo htmlspecialchars((string) $run['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $workspaceUser): ?><option value="<?php echo (int) $workspaceUser['id']; ?>"><?php echo htmlspecialchars((string) $workspaceUser['email']); ?></option><?php endforeach; ?></select></div>
                                <button class="btn-premium-primary marketing-launch-control-wide-field" type="submit">Prepare Launch Control</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-launch-control-manual-card">
                        <div class="premium-section-header"><div><h2>Manual-first Safeguard</h2><p>No external publishing or sending happens from this page.</p></div></div>
                        <div class="marketing-launch-control-signal">
                            <strong>Manager-only launch recording</strong>
                            <small>Ready launches can be marked manually launched only after the operator completes the work outside the CRM.</small>
                        </div>
                    </section>

                    <section class="content-card marketing-launch-control-expert-card">
                        <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the board.</p></div></div>
                        <div class="marketing-advanced-tools-grid">
                            <?php foreach ($expertLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>">
                                    <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                    <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
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
