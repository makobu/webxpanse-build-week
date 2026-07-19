<?php
/**
 * Marketing campaign launch checklists.
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
$options = $marketing->optionData();
$distributionPosts = Database::tableExists('marketing_distribution_posts') ? $marketing->listDistributionPosts(['open' => true], 250, 0) : [];
$emailRuns = Database::tableExists('marketing_email_campaign_runs') ? $marketing->listEmailCampaignRuns(['open' => true], 250, 0) : [];
$channelBundles = Database::tableExists('marketing_channel_export_bundles') ? $marketing->listChannelExportBundles(['open' => true], 250, 0) : [];
$connectors = Database::tableExists('marketing_channel_connectors') ? $marketing->listChannelConnectors([], 250, 0) : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to update launch checklists.');
        }

        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'refresh') {
            $marketing->refreshCampaignLaunchChecklist((int) ($_POST['checklist_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_launch_checklists.php?success=refreshed');
            exit;
        }

        $id = $marketing->createCampaignLaunchChecklist($_POST + ['created_by' => (int) ($user['id'] ?? 0)], (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_launch_checklists.php?success=created&id=' . $id);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);
$filters = $selectedId > 0 ? ['id' => $selectedId] : ($status !== '' ? ['status' => $status] : ['open' => true]);
$checklists = $marketing->listCampaignLaunchChecklists($filters, 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'launched' => 'badge-success',
        'blocked' => 'badge-danger',
        'draft', 'setup_needed', 'warning' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$openCount = count($checklists);
$readyCount = count(array_filter($checklists, static fn(array $checklist): bool => in_array((string) ($checklist['status'] ?? ''), ['ready', 'launched'], true)));
$blockedCount = count(array_filter($checklists, static fn(array $checklist): bool => (string) ($checklist['status'] ?? '') === 'blocked'));
$averageReadiness = $openCount > 0 ? (int) round(array_sum(array_map(static fn(array $checklist): int => (int) ($checklist['readiness_score'] ?? 0), $checklists)) / $openCount) : 0;
$firstChecklist = (array) ($checklists[0] ?? []);
$firstReadyChecklist = (array) (array_values(array_filter($checklists, static fn(array $checklist): bool => in_array((string) ($checklist['status'] ?? ''), ['ready', 'launched'], true)))[0] ?? []);
$firstBlockedChecklist = (array) (array_values(array_filter($checklists, static fn(array $checklist): bool => (string) ($checklist['status'] ?? '') === 'blocked'))[0] ?? []);
$summaryTiles = [
    ['icon' => 'fa-list-check', 'label' => 'Open', 'value' => (string) $openCount, 'tooltip' => 'Visible launch checklists for the current filter.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $blockedCount, 'tooltip' => 'Checklists missing required launch evidence.'],
    ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $readyCount, 'tooltip' => 'Checklists that passed the final manual launch gate.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Score', 'value' => $averageReadiness . '%', 'tooltip' => 'Average readiness score across the visible checklists.'],
];
$stageCards = [
    [
        'label' => 'Pick Campaign',
        'icon' => 'fa-bullseye',
        'status' => $canWriteMarketing ? 'ready' : 'setup_needed',
        'sentence' => 'Choose the launch.',
        'tooltip' => 'Founder view: choose the campaign, brief, audience, content, landing page, distribution variant, email run, connector, and owner.',
        'href' => '#create-launch-checklist',
        'action' => 'Create',
    ],
    [
        'label' => 'Check Proof',
        'icon' => 'fa-clipboard-check',
        'status' => $firstChecklist !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Review the evidence.',
        'tooltip' => 'Expert view: required checks cover strategy, audience, content, media, tracking, exports, approvals, and connector readiness.',
        'href' => $firstChecklist !== [] ? '#launch-checklist-' . (int) ($firstChecklist['id'] ?? 0) : 'marketing_campaign_workspace.php',
        'action' => 'Open checks',
    ],
    [
        'label' => 'Fix Gaps',
        'icon' => 'fa-triangle-exclamation',
        'status' => $blockedCount > 0 ? 'blocked' : 'ready',
        'sentence' => 'Clear missing items.',
        'tooltip' => 'Blocked checklist cards stay visible and route to the missing launch evidence.',
        'href' => $firstBlockedChecklist !== [] ? '#launch-checklist-' . (int) ($firstBlockedChecklist['id'] ?? 0) : '#launch-gates',
        'action' => 'View gaps',
    ],
    [
        'label' => 'Refresh Gate',
        'icon' => 'fa-rotate',
        'status' => $canWriteMarketing && $firstChecklist !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Recheck the launch.',
        'tooltip' => 'Refreshing recalculates checklist readiness from the current linked campaign evidence.',
        'href' => $firstChecklist !== [] ? '#launch-checklist-' . (int) ($firstChecklist['id'] ?? 0) : '#create-launch-checklist',
        'action' => 'Refresh',
    ],
    [
        'label' => 'Launch Manually',
        'icon' => 'fa-paper-plane',
        'status' => $readyCount > 0 ? 'ready' : 'warning',
        'sentence' => 'Operator goes live.',
        'tooltip' => 'Manual-first: passing the checklist does not publish or send anything from the CRM.',
        'href' => $firstReadyChecklist !== [] ? 'marketing_launch_control.php' : 'marketing_launch_readiness.php',
        'action' => 'Open control',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstBlockedChecklist !== [] ? [
        'label' => 'Resolve blocked checklist',
        'href' => '#launch-checklist-' . (int) ($firstBlockedChecklist['id'] ?? 0),
        'reason' => 'Open the first blocked checklist and clear required evidence.',
    ] : null,
    $firstReadyChecklist !== [] ? [
        'label' => 'Move ready launch',
        'href' => 'marketing_launch_control.php',
        'reason' => 'Use launch control after the checklist is ready.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Create checklist',
        'href' => '#create-launch-checklist',
        'reason' => 'Run the final gate before manual launch.',
    ] : null,
    [
        'label' => 'Open readiness',
        'href' => 'marketing_launch_readiness.php',
        'reason' => 'Check launch proof before final gate refresh.',
    ],
    [
        'label' => 'Open launch control',
        'href' => 'marketing_launch_control.php',
        'reason' => 'Record manual launch after gate clearance.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Review the campaign operating map.'],
    ['label' => 'Execution Center', 'href' => 'marketing_execution.php', 'hint' => 'Open execution and readiness controls.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Score campaign launch proof.'],
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Record manual launch execution.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Review channel export packages.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'hint' => 'Check tracking links.'],
];
$pageTitle = 'Campaign Launch Checklists - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-launch-checklists-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Campaign Launch Checklists</h1>
                <p>Checklist Board: clear the last gate before manual launch.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="#create-launch-checklist"><i class="fas fa-list-check"></i> Create Checklist</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Campaign launch checklist created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'refreshed'): ?><div class="alert alert-success">Campaign launch checklist refreshed.</div><?php endif; ?>

        <section class="marketing-launch-checklists-shell">
            <div class="marketing-founder-summary marketing-launch-checklists-summary" aria-label="Launch checklist summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                        <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo $h($tile['label']); ?></span>
                        <strong><?php echo $h($tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-launch-checklists-stage-grid" aria-label="Checklist path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-launch-checklists-stage-card <?php echo $h($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-launch-checklists-stage-body">
                            <span class="badge <?php echo $h($badgeClass($stageStatus)); ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                            <h2><?php echo $h($stage['label']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-launch-checklists-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-launch-checklists-layout">
                <main class="marketing-launch-checklists-main">
                    <section class="content-card marketing-launch-checklists-board" id="launch-gates">
                        <div class="premium-section-header">
                            <div>
                                <h2>Launch Gates</h2>
                                <p>Final checks before manual launch.</p>
                            </div>
                            <span class="badge <?php echo $blockedCount > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $blockedCount > 0 ? 'Blocked' : 'Clear'; ?></span>
                        </div>

                        <?php if (empty($checklists)): ?>
                            <div class="empty-state">
                                <p>No campaign launch checklists match this view.</p>
                                <a href="marketing_campaign_workspace.php">Open Campaign Workspace</a>
                                <?php if ($canWriteMarketing): ?><a href="#create-launch-checklist">Create launch checklist</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-launch-checklists-card-grid">
                                <?php foreach ($checklists as $checklist): ?>
                                    <?php
                                    $checks = array_slice((array) ($checklist['required_checks_json'] ?? []), 0, 8);
                                    $missing = (array) ($checklist['missing_items_json'] ?? []);
                                    $warnings = (array) ($checklist['warning_json'] ?? []);
                                    $nextActions = (array) ($checklist['next_actions_json'] ?? []);
                                    $checklistStatus = (string) ($checklist['status'] ?? 'draft');
                                    $primaryHref = !empty($checklist['campaign_brief_id']) ? 'marketing_brief_view.php?id=' . (int) $checklist['campaign_brief_id'] : '#launch-checklist-' . (int) ($checklist['id'] ?? 0);
                                    $primaryLabel = !empty($checklist['campaign_brief_id']) ? 'Open brief' : 'Open gate';
                                    $tooltip = $missing !== []
                                        ? 'Missing: ' . implode(', ', array_map($labelize, $missing))
                                        : 'Expert view: required checks, warnings, next actions, score, owner, linked campaign evidence, and refresh control.';
                                    ?>
                                    <article class="marketing-launch-checklists-card <?php echo $h($checklistStatus); ?>" id="launch-checklist-<?php echo (int) ($checklist['id'] ?? 0); ?>" tabindex="0" data-tooltip="<?php echo $h($tooltip); ?>">
                                        <div class="marketing-stage-visual">
                                            <i class="fas fa-list-check" aria-hidden="true"></i>
                                            <span><?php echo (int) ($checklist['readiness_score'] ?? 0); ?>%</span>
                                        </div>
                                        <div class="marketing-launch-checklists-card-top">
                                            <div>
                                                <span class="badge <?php echo $h($badgeClass($checklistStatus)); ?>"><?php echo $h($labelize($checklistStatus)); ?></span>
                                                <h3><?php echo $h($checklist['title'] ?? 'Launch checklist'); ?></h3>
                                            </div>
                                        </div>
                                        <div class="marketing-launch-checklists-meta">
                                            <span><?php echo $h($checklist['launch_date'] ?: 'No date'); ?></span>
                                            <span><?php echo $h($checklist['owner_email'] ?: 'No owner'); ?></span>
                                        </div>
                                        <div class="marketing-launch-checklists-signal-grid">
                                            <div><strong><?php echo count($checks); ?></strong><span>Checks</span></div>
                                            <div><strong><?php echo count($missing); ?></strong><span>Missing</span></div>
                                            <div><strong><?php echo count($warnings); ?></strong><span>Warnings</span></div>
                                        </div>
                                        <a class="btn-premium-secondary marketing-launch-checklists-card-action" href="<?php echo $h($primaryHref); ?>"><?php echo $h($primaryLabel); ?></a>
                                        <details class="marketing-launch-checklists-card-tools">
                                            <summary>Checklist evidence and refresh</summary>
                                            <div class="marketing-launch-checklists-card-tool-body">
                                                <?php if (!empty($missing)): ?>
                                                    <div class="marketing-launch-checklists-signal"><strong>Missing required checks</strong><small><?php echo $h(implode(', ', array_map($labelize, $missing))); ?></small></div>
                                                <?php elseif ($checklistStatus === 'ready'): ?>
                                                    <div class="marketing-launch-checklists-signal"><strong>Ready</strong><small>All required checks are ready. Manual publishing and sending still require operator action.</small></div>
                                                <?php endif; ?>
                                                <?php if (!empty($warnings)): ?>
                                                    <div class="marketing-launch-checklists-signal"><strong>Warnings</strong><small><?php echo $h(implode(', ', array_map($labelize, $warnings))); ?></small></div>
                                                <?php endif; ?>
                                                <div class="marketing-launch-checklists-link-row">
                                                    <?php if (!empty($checklist['campaign_name'])): ?><span>Campaign: <?php echo $h($checklist['campaign_name']); ?></span><?php endif; ?>
                                                    <?php if (!empty($checklist['campaign_brief_title'])): ?><span>Brief: <?php echo $h($checklist['campaign_brief_title']); ?></span><?php endif; ?>
                                                    <?php if (!empty($checklist['audience_segment_name'])): ?><span>Audience: <?php echo $h($checklist['audience_segment_name']); ?></span><?php endif; ?>
                                                    <?php if (!empty($checklist['landing_page_title'])): ?><span>Landing: <?php echo $h($checklist['landing_page_title']); ?></span><?php endif; ?>
                                                    <?php if (!empty($checklist['content_title'])): ?><span>Content: <?php echo $h($checklist['content_title']); ?></span><?php endif; ?>
                                                </div>
                                                <?php if (!empty($nextActions)): ?>
                                                    <div class="marketing-launch-checklists-link-row">
                                                        <?php foreach (array_slice($nextActions, 0, 4) as $action): ?>
                                                            <a href="<?php echo $h($action['href'] ?? '#'); ?>"><?php echo $h($action['label'] ?? 'Review item'); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($checks)): ?>
                                                    <div class="marketing-launch-checklists-check-grid">
                                                        <?php foreach ($checks as $check): ?>
                                                            <div class="marketing-launch-checklists-check">
                                                                <strong><?php echo $h($check['label'] ?? 'Checklist item'); ?></strong>
                                                                <small><?php echo $h($labelize((string) ($check['status'] ?? 'pending'))); ?><?php echo !empty($check['required']) ? ' required' : ' recommended'; ?></small>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="marketing-launch-checklists-link-row">
                                                    <?php if (!empty($checklist['campaign_brief_id'])): ?><a href="marketing_brief_view.php?id=<?php echo (int) $checklist['campaign_brief_id']; ?>">Brief</a><?php endif; ?>
                                                    <?php if (!empty($checklist['content_item_id'])): ?><a href="marketing_content_view.php?id=<?php echo (int) $checklist['content_item_id']; ?>">Content</a><?php endif; ?>
                                                    <?php if (!empty($checklist['landing_page_id'])): ?><a href="marketing_landing_page_view.php?id=<?php echo (int) $checklist['landing_page_id']; ?>">Landing</a><?php endif; ?>
                                                </div>
                                                <?php if ($canWriteMarketing): ?>
                                                    <form method="POST" class="marketing-launch-checklists-inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                        <input type="hidden" name="action" value="refresh">
                                                        <input type="hidden" name="checklist_id" value="<?php echo (int) $checklist['id']; ?>">
                                                        <button class="btn-premium-secondary" type="submit">Refresh checklist</button>
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

                <aside class="content-card marketing-launch-checklists-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Clear the final gate.</p>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-item" href="<?php echo $h($action['href']); ?>">
                                <strong><?php echo $h($action['label']); ?></strong>
                                <span><?php echo $h($action['reason']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-launch-checklists-tools">
                <summary>More checklist tools</summary>
                <div class="marketing-launch-checklists-tools-body">
                    <section class="content-card marketing-launch-checklists-filter-card">
                        <div class="premium-section-header"><h2>Checklist Filter</h2></div>
                        <form method="GET" class="marketing-launch-checklists-filter-form">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">Open</option>
                                    <?php foreach (Marketing::CAMPAIGN_LAUNCH_CHECKLIST_STATUSES as $checklistStatus): ?>
                                        <option value="<?php echo $h($checklistStatus); ?>" <?php echo $selected($status, $checklistStatus); ?>><?php echo $h($labelize($checklistStatus)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_launch_checklists.php">Reset</a>
                        </form>
                    </section>

                    <section class="content-card marketing-launch-checklists-form-card" id="create-launch-checklist">
                        <div class="premium-section-header"><div><h2>Create Checklist</h2><p>Attach the campaign evidence for the final manual-first gate.</p></div></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-launch-checklists-create-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="action" value="create">
                                <div class="form-group"><label>Title</label><input type="text" name="title" placeholder="Q3 launch checklist" required></div>
                                <div class="form-group"><label>Launch Date</label><input type="date" name="launch_date"></div>
                                <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo $h($campaign['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign Brief</label><select name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo $h($brief['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Audience Segment</label><select name="audience_segment_id"><option value="">None</option><?php foreach ($options['audience_segments'] as $segment): ?><option value="<?php echo (int) $segment['id']; ?>"><?php echo $h($segment['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo $h($landingPage['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Content Item</label><select name="content_item_id"><option value="">None</option><?php foreach ($options['content_items'] as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h($item['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Distribution Variant</label><select name="distribution_post_id"><option value="">None</option><?php foreach ($distributionPosts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo $h((string) ($post['content_title'] ?? 'Distribution') . ' - ' . $labelize((string) ($post['channel'] ?? 'other'))); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Email Run</label><select name="email_run_id"><option value="">None</option><?php foreach ($emailRuns as $run): ?><option value="<?php echo (int) $run['id']; ?>"><?php echo $h($run['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Channel Export Bundle</label><select name="channel_export_bundle_id"><option value="">None</option><?php foreach ($channelBundles as $bundle): ?><option value="<?php echo (int) $bundle['id']; ?>"><?php echo $h((string) ($bundle['content_title'] ?? 'Bundle') . ' - ' . $labelize((string) ($bundle['channel'] ?? 'other'))); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Connector</label><select name="connector_id"><option value="">None</option><?php foreach ($connectors as $connector): ?><option value="<?php echo (int) $connector['id']; ?>"><?php echo $h((string) ($connector['name'] ?? 'Connector') . ' - ' . $labelize((string) ($connector['setup_status'] ?? 'not_started'))); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $workspaceUser): ?><option value="<?php echo (int) $workspaceUser['id']; ?>"><?php echo $h($workspaceUser['email']); ?></option><?php endforeach; ?></select></div>
                                <button class="btn-premium-primary marketing-launch-checklists-wide-field" type="submit">Create Launch Checklist</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access. Ask a marketing operator to create or refresh launch checklists.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-launch-checklists-manual-card">
                        <div class="premium-section-header"><div><h2>Manual-first Safeguard</h2><p>Checklist readiness never sends or publishes by itself.</p></div></div>
                        <div class="marketing-launch-checklists-signal">
                            <strong>Operator launch still required</strong>
                            <small>Manual publishing and sending still require operator action.</small>
                        </div>
                    </section>

                    <section class="content-card marketing-launch-checklists-expert-card">
                        <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the checklist board.</p></div></div>
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
