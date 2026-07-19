<?php
/**
 * Marketing audience activation.
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
$distributionPosts = Database::tableExists('marketing_distribution_posts') ? $marketing->listDistributionPosts([], 250, 0) : [];
$emailRuns = Database::tableExists('marketing_email_campaign_runs') ? $marketing->listEmailCampaignRuns([], 250, 0) : [];
$personas = (array) ($options['personas'] ?? []);
$audienceSegments = (array) ($options['audience_segments'] ?? []);
$campaigns = (array) ($options['campaigns'] ?? []);
$campaignBriefs = (array) ($options['campaign_briefs'] ?? []);
$landingPages = (array) ($options['landing_pages'] ?? []);
$contentItems = (array) ($options['content_items'] ?? []);
$users = (array) ($options['users'] ?? []);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive audience activations.');
            }
            $marketing->archiveAudienceActivation((int) ($_POST['activation_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_audience_activation.php?success=archived');
            exit;
        }
        if ($action === 'map_persona') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to map personas to audience segments.');
            }
            $marketing->mapPersonaToSegment(
                (int) ($_POST['persona_id'] ?? 0),
                (int) ($_POST['audience_segment_id'] ?? 0),
                $_POST + ['created_by' => (int) ($user['id'] ?? 0)]
            );
            header('Location: ' . getBasePath() . '/marketing_audience_activation.php?success=mapped');
            exit;
        }
        if ($action === 'update_status') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update audience activations.');
            }
            $marketing->updateAudienceActivation((int) ($_POST['activation_id'] ?? 0), [
                'status' => (string) ($_POST['status'] ?? 'planned'),
                'owner_user_id' => (int) ($_POST['owner_user_id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_audience_activation.php?success=updated');
            exit;
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to create audience activations.');
        }
        $marketing->createAudienceActivation($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_audience_activation.php?success=created');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$coverageStatus = trim((string) ($_GET['coverage_status'] ?? ''));
$segmentId = (int) ($_GET['audience_segment_id'] ?? 0);
$filters = [];
if ($status !== '') {
    $filters['status'] = $status;
} else {
    $filters['open'] = true;
}
if ($coverageStatus !== '') {
    $filters['coverage_status'] = $coverageStatus;
}
if ($segmentId > 0) {
    $filters['audience_segment_id'] = $segmentId;
}
$summary = $marketing->getAudienceActivationSummary();
$activations = $marketing->listAudienceActivations($filters, 100, 0);
$maps = $marketing->listPersonaSegmentMaps([], 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'active', 'completed' => 'badge-success',
        'blocked' => 'badge-danger',
        'warning', 'planned', 'paused', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$count = static fn(string $key): int => (int) ($summary['counts'][$key] ?? 0);
$activationCount = count($activations);
$mapCount = count($maps);
$warningTotal = array_sum(array_map(static fn(array $activation): int => count((array) ($activation['coverage_warnings_json'] ?? [])), $activations));
$readyCount = $count('ready');
$blockedCount = $count('blocked');
$activeCount = $count('active');
$firstActivation = (array) ($activations[0] ?? []);
$firstActionHref = !empty($firstActivation['audience_segment_id'])
    ? 'marketing_segment_view.php?id=' . (int) $firstActivation['audience_segment_id']
    : 'marketing_segments.php';
$todayActions = array_slice(array_values(array_filter([
    $firstActivation !== [] ? [
        'label' => 'Review the top audience',
        'href' => $firstActionHref,
        'reason' => 'Check the segment behind the first audience activation.',
    ] : null,
    $blockedCount > 0 ? [
        'label' => 'Clear blocked coverage',
        'href' => 'marketing_audience_activation.php?coverage_status=blocked',
        'reason' => 'Blocked audiences need evidence before launch readiness.',
    ] : null,
    $warningTotal > 0 ? [
        'label' => 'Review coverage warnings',
        'href' => 'marketing_audience_activation.php?coverage_status=warning',
        'reason' => 'Warnings explain where an audience may not be launch-ready.',
    ] : null,
    [
        'label' => 'Create activation',
        'href' => '#new-audience-activation',
        'reason' => 'Connect a segment to the campaign work that will use it.',
    ],
    [
        'label' => 'Open audience builder',
        'href' => 'marketing_segments.php',
        'reason' => 'Edit the underlying audience segment.',
    ],
])), 0, 5);
$summaryTiles = [
    ['icon' => 'fa-users-viewfinder', 'label' => 'Open', 'value' => (string) $activationCount, 'tooltip' => 'Audience activations in the current filtered view.'],
    ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $readyCount, 'tooltip' => 'Audiences with enough evidence for campaign use.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Needs work', 'value' => (string) ($blockedCount + $count('warning')), 'tooltip' => 'Blocked or warning audiences stay visible, with details in tooltips and drawers.'],
    ['icon' => 'fa-link', 'label' => 'Persona maps', 'value' => (string) $mapCount, 'tooltip' => 'Persona-to-segment matches that help founders pick the right audience.'],
];
$stageCards = [
    [
        'label' => 'Choose Segment',
        'icon' => 'fa-users',
        'status' => $activationCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Start with a reusable audience.',
        'tooltip' => 'Expert view: audience segment selected for a campaign, brief, content, landing page, email run, or distribution post.',
        'href' => 'marketing_segments.php',
        'action' => 'Open audience',
    ],
    [
        'label' => 'Match Persona',
        'icon' => 'fa-user-check',
        'status' => $mapCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Check who the audience represents.',
        'tooltip' => 'Expert view: persona-to-segment mapping and match score.',
        'href' => '#persona-map',
        'action' => 'Map persona',
    ],
    [
        'label' => 'Connect Campaign',
        'icon' => 'fa-bullhorn',
        'status' => $activeCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Attach the audience to real campaign work.',
        'tooltip' => 'Expert view: campaign, brief, landing page, content, distribution, and email run relationships.',
        'href' => '#new-audience-activation',
        'action' => 'Connect work',
    ],
    [
        'label' => 'Check Coverage',
        'icon' => 'fa-shield-halved',
        'status' => $blockedCount > 0 ? 'blocked' : ($warningTotal > 0 ? 'warning' : 'ready'),
        'sentence' => 'Spot missing fit or permission evidence.',
        'tooltip' => 'Expert view: coverage status, fit score, warnings, and launch readiness blockers.',
        'href' => 'marketing_audience_activation.php?coverage_status=blocked',
        'action' => 'Check blockers',
    ],
    [
        'label' => 'Use In Launch',
        'icon' => 'fa-rocket',
        'status' => $readyCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Send ready audiences to launch checks.',
        'tooltip' => 'Expert view: launch readiness uses the selected audience as required evidence.',
        'href' => 'marketing_launch_readiness.php',
        'action' => 'Open launch',
    ],
];
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the command center.'],
    ['label' => 'Audience Builder', 'href' => 'marketing_segments.php', 'hint' => 'Create or edit CRM-derived audiences.'],
    ['label' => 'Personas', 'href' => 'marketing_personas.php', 'hint' => 'Review customer profiles.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect the audience to campaign launch work.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Use audience evidence in launch checks.'],
    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'hint' => 'Inspect expert relationship evidence.'],
];

$pageTitle = 'Audience Activation - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-audience-activation-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Audience Activation</h1>
                <p>Make the audience usable before launch.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-secondary" href="marketing_segments.php"><i class="fas fa-users-viewfinder"></i> Audience Builder</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Audience activation created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'updated'): ?><div class="alert alert-success">Audience activation updated.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Audience activation archived.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'mapped'): ?><div class="alert alert-success">Persona-to-segment mapping saved.</div><?php endif; ?>

        <section class="marketing-audience-shell">
            <div class="marketing-founder-summary marketing-audience-summary" aria-label="Audience activation summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars($tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars($tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-audience-stage-grid" aria-label="Audience activation path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-audience-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-audience-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-audience-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-audience-layout">
                <main class="marketing-audience-main">
                    <section class="content-card marketing-audience-queue-card-list">
                        <div class="premium-section-header">
                            <div>
                                <h2>Activation Queue</h2>
                                <p>Ready audiences can move into campaign launch checks.</p>
                            </div>
                            <span class="badge <?php echo $activationCount > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $activationCount; ?> open</span>
                        </div>
                        <?php if ($activations === []): ?>
                            <div class="empty-state">
                                <p>No audience activations match this view.</p>
                                <a href="marketing_segments.php">Open audience builder</a>
                                <?php if ($canWriteMarketing): ?><a href="#new-audience-activation">Create activation</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-audience-card-grid">
                                <?php foreach ($activations as $activation): ?>
                                    <?php
                                        $warnings = (array) ($activation['coverage_warnings_json'] ?? []);
                                        $activationStatus = (string) ($activation['status'] ?? 'planned');
                                        $coverage = (string) ($activation['coverage_status'] ?? 'warning');
                                        $fitScore = min(100, max(0, (int) ($activation['fit_score'] ?? 0)));
                                        $primaryHref = !empty($activation['audience_segment_id'])
                                            ? 'marketing_segment_view.php?id=' . (int) $activation['audience_segment_id']
                                            : 'marketing_segments.php';
                                        $primaryLabel = !empty($activation['audience_segment_id']) ? 'Review audience' : 'Choose audience';
                                    ?>
                                    <article class="marketing-audience-activation-card <?php echo htmlspecialchars($coverage); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($warnings === [] ? 'Expert view: activation coverage, fit score, and connected campaign work.' : 'Coverage warning: ' . implode(', ', array_map($labelize, $warnings))); ?>">
                                        <div class="marketing-audience-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-bullseye" aria-hidden="true"></i>
                                            </div>
                                            <div class="marketing-audience-badges">
                                                <span class="badge <?php echo htmlspecialchars($badgeClass($activationStatus)); ?>"><?php echo htmlspecialchars($labelize($activationStatus)); ?></span>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass($coverage)); ?>"><?php echo htmlspecialchars($labelize($coverage)); ?></span>
                                            </div>
                                        </div>
                                        <h3><?php echo htmlspecialchars((string) ($activation['activation_name'] ?? 'Audience activation')); ?></h3>
                                        <progress class="marketing-audience-fit-meter" max="100" value="<?php echo $fitScore; ?>" aria-label="Audience fit score"></progress>
                                        <div class="marketing-audience-card-meta">
                                            <span><?php echo $fitScore; ?>% fit</span>
                                            <span><?php echo htmlspecialchars((string) ($activation['audience_segment_name'] ?? 'No segment')); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($activation['activation_type'] ?? 'other'))); ?></span>
                                        </div>
                                        <a class="btn-premium-secondary marketing-audience-card-action" href="<?php echo htmlspecialchars($primaryHref); ?>"><?php echo htmlspecialchars($primaryLabel); ?></a>
                                        <details class="marketing-audience-card-tools">
                                            <summary>More controls</summary>
                                            <div class="marketing-audience-card-tool-body">
                                                <div class="marketing-audience-related-links">
                                                    <?php if (!empty($activation['campaign_brief_id'])): ?><a href="marketing_brief_view.php?id=<?php echo (int) $activation['campaign_brief_id']; ?>">Brief</a><?php endif; ?>
                                                    <?php if (!empty($activation['content_item_id'])): ?><a href="marketing_content_view.php?id=<?php echo (int) $activation['content_item_id']; ?>">Content</a><?php endif; ?>
                                                    <?php if (!empty($activation['landing_page_id'])): ?><a href="marketing_landing_page_view.php?id=<?php echo (int) $activation['landing_page_id']; ?>">Landing</a><?php endif; ?>
                                                </div>
                                                <?php if ($canWriteMarketing): ?>
                                                    <form method="POST" class="marketing-audience-status-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="activation_id" value="<?php echo (int) $activation['id']; ?>">
                                                        <input type="hidden" name="owner_user_id" value="<?php echo (int) ($activation['owner_user_id'] ?? 0); ?>">
                                                        <select name="status" aria-label="Activation status"><?php foreach (Marketing::AUDIENCE_ACTIVATION_STATUSES as $activationStatusOption): ?><option value="<?php echo htmlspecialchars($activationStatusOption); ?>" <?php echo $selected($activationStatusOption, $activation['status']); ?>><?php echo htmlspecialchars($labelize($activationStatusOption)); ?></option><?php endforeach; ?></select>
                                                        <button class="btn-premium-secondary" type="submit">Save status</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" onsubmit="return confirm('Archive this audience activation?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="activation_id" value="<?php echo (int) $activation['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Archive</button>
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

                <aside class="content-card marketing-audience-today" id="new-audience-activation">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>One short list, then create if needed.</p>
                        </div>
                    </div>
                    <div class="marketing-audience-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-audience-create-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="create">
                            <div class="form-group"><label>Name</label><input type="text" name="activation_name" placeholder="Q3 launch audience"></div>
                            <div class="form-group"><label>Segment</label><select name="audience_segment_id" required><option value="">Select segment</option><?php foreach ($audienceSegments as $segment): ?><option value="<?php echo (int) $segment['id']; ?>"><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Type</label><select name="activation_type"><?php foreach (Marketing::AUDIENCE_ACTIVATION_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                            <details class="marketing-audience-form-more">
                                <summary>More activation fields</summary>
                                <div class="marketing-audience-form-more-body">
                                    <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::AUDIENCE_ACTIVATION_STATUSES as $activationStatusOption): ?><option value="<?php echo htmlspecialchars($activationStatusOption); ?>" <?php echo $activationStatusOption === 'planned' ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($activationStatusOption)); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($campaigns as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Campaign Brief</label><select name="campaign_brief_id"><option value="">None</option><?php foreach ($campaignBriefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($landingPages as $page): ?><option value="<?php echo (int) $page['id']; ?>"><?php echo htmlspecialchars((string) $page['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Content Item</label><select name="content_item_id"><option value="">None</option><?php foreach ($contentItems as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Distribution Post</label><select name="distribution_post_id"><option value="">None</option><?php foreach ($distributionPosts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars((string) ($post['content_title'] ?? 'Distribution') . ' - ' . $labelize((string) ($post['channel'] ?? 'other'))); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Email Run</label><select name="email_run_id"><option value="">None</option><?php foreach ($emailRuns as $run): ?><option value="<?php echo (int) $run['id']; ?>"><?php echo htmlspecialchars((string) $run['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ($users as $workspaceUser): ?><option value="<?php echo (int) $workspaceUser['id']; ?>"><?php echo htmlspecialchars((string) $workspaceUser['email']); ?></option><?php endforeach; ?></select></div>
                                </div>
                            </details>
                            <button class="btn-premium-primary" type="submit">Create activation</button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state"><p>Read-only access.</p></div>
                    <?php endif; ?>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-audience-tools">
                <summary>
                    <span>More audience tools</span>
                    <small>Filters, persona maps, expert routes, and launch evidence.</small>
                </summary>
                <div class="marketing-audience-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>">
                                <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <section class="content-card marketing-audience-filter-card">
                        <div class="premium-section-header"><h2>View filters</h2></div>
                        <form method="GET" class="marketing-audience-filter-form">
                            <div class="form-group"><label>Status</label><select name="status"><option value="">Open</option><?php foreach (Marketing::AUDIENCE_ACTIVATION_STATUSES as $activationStatusOption): ?><option value="<?php echo htmlspecialchars($activationStatusOption); ?>" <?php echo $selected($status, $activationStatusOption); ?>><?php echo htmlspecialchars($labelize($activationStatusOption)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Coverage</label><select name="coverage_status"><option value="">Any</option><?php foreach (Marketing::AUDIENCE_COVERAGE_STATUSES as $coverageOption): ?><option value="<?php echo htmlspecialchars($coverageOption); ?>" <?php echo $selected($coverageStatus, $coverageOption); ?>><?php echo htmlspecialchars($labelize($coverageOption)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Segment</label><select name="audience_segment_id"><option value="">All</option><?php foreach ($audienceSegments as $segment): ?><option value="<?php echo (int) $segment['id']; ?>" <?php echo $selected($segmentId, $segment['id']); ?>><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_audience_activation.php">Reset</a>
                        </form>
                    </section>

                    <section class="content-card marketing-audience-map-card" id="persona-map">
                        <div class="premium-section-header"><h2>Persona Segment Map</h2></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-audience-map-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="map_persona">
                                <div class="form-group"><label>Persona</label><select name="persona_id" required><option value="">Select persona</option><?php foreach ($personas as $persona): ?><option value="<?php echo (int) $persona['id']; ?>"><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Segment</label><select name="audience_segment_id" required><option value="">Select segment</option><?php foreach ($audienceSegments as $segment): ?><option value="<?php echo (int) $segment['id']; ?>"><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Match Score</label><input type="number" min="0" max="100" name="match_score" value="70"></div>
                                <div class="form-group marketing-audience-map-reason"><label>Reason</label><textarea name="match_reason" rows="3" placeholder="Why this persona fits this audience"></textarea></div>
                                <button class="btn-premium-secondary" type="submit">Save mapping</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($maps === []): ?>
                            <div class="empty-state"><p>No persona-to-segment mappings yet.</p></div>
                        <?php else: ?>
                            <div class="marketing-audience-map-list">
                                <?php foreach ($maps as $map): ?>
                                    <article class="marketing-audience-map-row">
                                        <strong><?php echo htmlspecialchars((string) $map['persona_name']); ?></strong>
                                        <span><?php echo htmlspecialchars((string) $map['audience_segment_name']); ?> · <?php echo (int) ($map['match_score'] ?? 0); ?>% match</span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
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
