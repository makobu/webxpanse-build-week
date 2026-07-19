<?php
/**
 * Campaign launch readiness reviews.
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
$templates = $marketing->listLaunchReadinessTemplates([], 100, 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'evaluate');
        if ($action === 'approve') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to approve launch readiness.');
            }
            $marketing->approveLaunchReadiness((int) ($_POST['review_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_launch_readiness.php?success=approved');
            exit;
        }
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive launch readiness reviews.');
            }
            $marketing->archiveLaunchReadinessReview((int) ($_POST['review_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_launch_readiness.php?success=archived');
            exit;
        }
        if ($action === 'create_template') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create launch readiness templates.');
            }
            $marketing->createLaunchReadinessTemplate($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_launch_readiness.php?success=template');
            exit;
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to evaluate launch readiness.');
        }
        $review = $marketing->evaluateLaunchReadiness($_POST + ['created_by' => (int) ($user['id'] ?? 0)], (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_launch_readiness.php?success=evaluated&id=' . (int) ($review['id'] ?? 0));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$filters = $status !== '' ? ['status' => $status] : ['open' => true];
$reviews = $marketing->listLaunchReadinessReviews($filters, 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'approved', 'ready' => 'badge-success',
        'blocked' => 'badge-danger',
        'archived' => 'badge-default',
        default => 'badge-warning',
    };
};
$openCount = count($reviews);
$blockedCount = count(array_filter($reviews, static fn(array $review): bool => (string) ($review['status'] ?? '') === 'blocked'));
$readyCount = count(array_filter($reviews, static fn(array $review): bool => in_array((string) ($review['status'] ?? ''), ['ready', 'approved'], true)));
$templateCount = count($templates);
$firstReview = (array) ($reviews[0] ?? []);
$firstBlockedReview = (array) (array_values(array_filter($reviews, static fn(array $review): bool => (string) ($review['status'] ?? '') === 'blocked'))[0] ?? []);
$firstReadyReview = (array) (array_values(array_filter($reviews, static fn(array $review): bool => in_array((string) ($review['status'] ?? ''), ['ready', 'approved'], true)))[0] ?? []);
$summaryTiles = [
    ['icon' => 'fa-rocket', 'label' => 'Open', 'value' => (string) $openCount, 'tooltip' => 'Open launch reviews waiting for fixes, approval, or manual launch handoff.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $blockedCount, 'tooltip' => 'Launches missing required audience, offer, approval, media, tracking, or distribution evidence.'],
    ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $readyCount, 'tooltip' => 'Launches that passed the readiness check or already have manager approval.'],
    ['icon' => 'fa-list-check', 'label' => 'Templates', 'value' => (string) $templateCount, 'tooltip' => 'Reusable launch evidence checklists available for future reviews.'],
];
$stageCards = [
    [
        'label' => 'Name Launch',
        'icon' => 'fa-flag-checkered',
        'status' => $canWriteMarketing ? 'ready' : 'setup_needed',
        'sentence' => 'Start with the launch.',
        'tooltip' => 'Founder view: choose the campaign, content, landing page, distribution post, or email run to check before launch.',
        'href' => '#new-launch-review',
        'action' => 'Evaluate',
    ],
    [
        'label' => 'Gather Proof',
        'icon' => 'fa-layer-group',
        'status' => $firstReview !== [] ? 'ready' : 'setup_needed',
        'sentence' => 'Attach the evidence.',
        'tooltip' => 'Expert view: evidence can include audience, brief, content, landing page, media, UTM, checklist, distribution, and email run records.',
        'href' => 'marketing_content.php?status=review',
        'action' => 'Find evidence',
    ],
    [
        'label' => 'Fix Blockers',
        'icon' => 'fa-triangle-exclamation',
        'status' => $blockedCount > 0 ? 'blocked' : 'ready',
        'sentence' => 'Clear what is missing.',
        'tooltip' => 'Blocked launches stay visible and route to the closest content, landing, distribution, or review evidence.',
        'href' => $firstBlockedReview !== [] && !empty($firstBlockedReview['content_item_id']) ? 'marketing_content_view.php?id=' . (int) $firstBlockedReview['content_item_id'] : '#launch-reviews',
        'action' => 'Fix gap',
    ],
    [
        'label' => 'Approve',
        'icon' => 'fa-user-check',
        'status' => $canManageMarketing && $readyCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Manager signs off.',
        'tooltip' => 'Manual-first: approval records readiness only. No external send, publish, or channel API call happens here.',
        'href' => $firstReadyReview !== [] ? '#launch-review-' . (int) ($firstReadyReview['id'] ?? 0) : '#launch-reviews',
        'action' => 'Review ready',
    ],
    [
        'label' => 'Launch Manually',
        'icon' => 'fa-paper-plane',
        'status' => $readyCount > 0 ? 'ready' : 'warning',
        'sentence' => 'Move to execution.',
        'tooltip' => 'Expert view: ready launches can move toward distribution bundles, launch control, handoffs, and performance reporting.',
        'href' => 'marketing_launch_control.php',
        'action' => 'Open control',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstBlockedReview !== [] ? [
        'label' => 'Clear launch blocker',
        'href' => !empty($firstBlockedReview['content_item_id']) ? 'marketing_content_view.php?id=' . (int) $firstBlockedReview['content_item_id'] : '#launch-reviews',
        'reason' => 'Open the first blocked launch and fill the missing proof.',
    ] : null,
    $firstReadyReview !== [] ? [
        'label' => 'Approve ready launch',
        'href' => '#launch-review-' . (int) ($firstReadyReview['id'] ?? 0),
        'reason' => 'Review the ready evidence and approve when it is safe.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Evaluate launch',
        'href' => '#new-launch-review',
        'reason' => 'Run the readiness check before manual execution.',
    ] : null,
    [
        'label' => 'Open launch control',
        'href' => 'marketing_launch_control.php',
        'reason' => 'Coordinate manual launch steps after readiness is clear.',
    ],
    [
        'label' => 'Open distribution',
        'href' => 'marketing_distribution.php',
        'reason' => 'Check the export package connected to this launch.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Coordinate manual launch execution.'],
    ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php', 'hint' => 'Open reusable launch task lists.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Review manual channel packages.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'hint' => 'Check tracking links before launch.'],
    ['label' => 'Quality Checks', 'href' => 'marketing_quality.php', 'hint' => 'Review quality evidence before launch.'],
    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'hint' => 'Close the learning loop after launch.'],
];
$pageTitle = 'Marketing Launch Readiness - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-launch-readiness-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Launch Readiness</h1>
                <p>Launch Board: check the proof before anything goes live.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-primary" href="#new-launch-review"><i class="fas fa-rocket"></i> Evaluate Launch</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'evaluated'): ?><div class="alert alert-success">Launch readiness review saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'approved'): ?><div class="alert alert-success">Launch readiness approved for manual execution.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Launch readiness review archived.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'template'): ?><div class="alert alert-success">Launch readiness template created.</div><?php endif; ?>

        <section class="marketing-launch-readiness-shell">
            <div class="marketing-founder-summary marketing-launch-readiness-summary" aria-label="Launch readiness summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-launch-readiness-stage-grid" aria-label="Launch path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-launch-readiness-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-launch-readiness-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-launch-readiness-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-launch-readiness-layout">
                <main class="marketing-launch-readiness-main">
                    <section class="content-card marketing-launch-readiness-board" id="launch-reviews">
                        <div class="premium-section-header">
                            <div>
                                <h2>Launch Reviews</h2>
                                <p>One board for blockers, proof, and manager sign-off.</p>
                            </div>
                            <span class="badge <?php echo $blockedCount > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $blockedCount > 0 ? 'Fix gaps' : 'Clear'; ?></span>
                        </div>
                        <?php if (empty($reviews)): ?>
                            <div class="empty-state"><p>No launch readiness reviews match this view.</p><?php if ($canWriteMarketing): ?><a href="#new-launch-review">Evaluate a launch</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="marketing-launch-readiness-card-grid">
                                <?php foreach ($reviews as $review): ?>
                                    <?php
                                    $result = (array) ($review['result_json'] ?? []);
                                    $checks = (array) ($result['checks'] ?? []);
                                    $reviewStatus = (string) ($review['status'] ?? 'draft');
                                    $blockers = (array) ($review['blocking_reasons_json'] ?? []);
                                    $warnings = (array) ($review['warnings_json'] ?? []);
                                    $primaryHref = '#launch-review-' . (int) ($review['id'] ?? 0);
                                    $primaryLabel = 'Open review';
                                    if (!empty($review['content_item_id'])) {
                                        $primaryHref = 'marketing_content_view.php?id=' . (int) $review['content_item_id'];
                                        $primaryLabel = 'Open content';
                                    } elseif (!empty($review['landing_page_id'])) {
                                        $primaryHref = 'marketing_landing_page_view.php?id=' . (int) $review['landing_page_id'];
                                        $primaryLabel = 'Open landing';
                                    } elseif (!empty($review['distribution_post_id'])) {
                                        $primaryHref = 'marketing_distribution_bundle.php?id=' . (int) $review['distribution_post_id'];
                                        $primaryLabel = 'Open bundle';
                                    }
                                    $tooltip = $blockers !== []
                                        ? 'Blocked by: ' . implode(', ', array_map($labelize, $blockers))
                                        : 'Expert view: readiness score, linked campaign evidence, checklist checks, warnings, approval, and archive controls.';
                                    ?>
                                    <article class="marketing-launch-readiness-card <?php echo htmlspecialchars($reviewStatus); ?>" id="launch-review-<?php echo (int) ($review['id'] ?? 0); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                        <div class="marketing-stage-visual">
                                            <i class="fas fa-gauge-high" aria-hidden="true"></i>
                                            <span><?php echo (int) ($review['readiness_score'] ?? 0); ?>%</span>
                                        </div>
                                        <div class="marketing-launch-readiness-card-top">
                                            <div>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass($reviewStatus)); ?>"><?php echo htmlspecialchars($labelize($reviewStatus)); ?></span>
                                                <h3><?php echo htmlspecialchars((string) ($review['launch_name'] ?? 'Launch review')); ?></h3>
                                            </div>
                                        </div>
                                        <div class="marketing-launch-readiness-meta">
                                            <span><?php echo htmlspecialchars((string) ($review['launch_date'] ?? 'No date')); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($review['campaign_brief_title'] ?? $review['campaign_name'] ?? 'No campaign')); ?></span>
                                        </div>
                                        <div class="marketing-launch-readiness-signal-grid">
                                            <div><strong><?php echo count($checks); ?></strong><span>Checks</span></div>
                                            <div><strong><?php echo count($blockers); ?></strong><span>Blockers</span></div>
                                            <div><strong><?php echo count($warnings); ?></strong><span>Warnings</span></div>
                                        </div>
                                        <a class="btn-premium-secondary marketing-launch-readiness-card-action" href="<?php echo htmlspecialchars($primaryHref); ?>"><?php echo htmlspecialchars($primaryLabel); ?></a>
                                        <details class="marketing-launch-readiness-card-tools">
                                            <summary>Evidence and manager tools</summary>
                                            <div class="marketing-launch-readiness-card-tool-body">
                                                <?php if ($blockers !== []): ?>
                                                    <div class="marketing-launch-readiness-signal"><strong>Blockers</strong><small><?php echo htmlspecialchars(implode(', ', array_map($labelize, $blockers))); ?></small></div>
                                                <?php endif; ?>
                                                <?php if ($warnings !== []): ?>
                                                    <div class="marketing-launch-readiness-signal"><strong>Warnings</strong><small><?php echo htmlspecialchars(implode(', ', array_map($labelize, $warnings))); ?></small></div>
                                                <?php endif; ?>
                                                <?php if ($checks !== []): ?>
                                                    <div class="marketing-launch-readiness-check-grid">
                                                        <?php foreach ($checks as $check): ?>
                                                            <div class="marketing-launch-readiness-check">
                                                                <strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'Check')); ?></strong>
                                                                <small><?php echo htmlspecialchars($labelize((string) ($check['status'] ?? 'warning'))); ?>: <?php echo htmlspecialchars((string) ($check['message'] ?? '')); ?></small>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="marketing-launch-readiness-link-row">
                                                    <?php if (!empty($review['content_item_id'])): ?><a href="marketing_content_view.php?id=<?php echo (int) $review['content_item_id']; ?>">Content</a><?php endif; ?>
                                                    <?php if (!empty($review['landing_page_id'])): ?><a href="marketing_landing_page_view.php?id=<?php echo (int) $review['landing_page_id']; ?>">Landing</a><?php endif; ?>
                                                    <?php if (!empty($review['distribution_post_id'])): ?><a href="marketing_distribution_bundle.php?id=<?php echo (int) $review['distribution_post_id']; ?>">Bundle</a><?php endif; ?>
                                                </div>
                                                <?php if ($canManageMarketing && in_array($reviewStatus, ['ready', 'draft'], true)): ?>
                                                    <form method="POST" class="marketing-launch-readiness-inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="review_id" value="<?php echo (int) $review['id']; ?>">
                                                        <button class="btn-premium-primary" type="submit">Approve readiness</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" class="marketing-launch-readiness-inline-form" onsubmit="return confirm('Archive this launch readiness review?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="archive">
                                                        <input type="hidden" name="review_id" value="<?php echo (int) $review['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Archive review</button>
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

                <aside class="content-card marketing-launch-readiness-today" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Keep launch work moving.</p>
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

            <details class="content-card marketing-launch-readiness-tools">
                <summary>More launch tools</summary>
                <div class="marketing-launch-readiness-tools-body">
                    <section class="content-card marketing-launch-readiness-filter-card">
                        <div class="premium-section-header"><h2>Review Filter</h2></div>
                        <form method="GET" class="marketing-launch-readiness-filter-form">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">Open</option>
                                    <?php foreach (Marketing::LAUNCH_READINESS_STATUSES as $reviewStatus): ?><option value="<?php echo htmlspecialchars($reviewStatus); ?>" <?php echo $selected($status, $reviewStatus); ?>><?php echo htmlspecialchars($labelize($reviewStatus)); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_launch_readiness.php">Reset</a>
                        </form>
                    </section>

                    <section class="content-card marketing-launch-readiness-form-card" id="new-launch-review">
                        <div class="premium-section-header"><div><h2>Evaluate Launch</h2><p>Attach evidence and score launch readiness before manual execution.</p></div></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-launch-readiness-evaluate-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="evaluate">
                                <div class="form-group"><label>Launch Name</label><input type="text" name="launch_name" placeholder="May product launch"></div>
                                <div class="form-group"><label>Launch Date</label><input type="date" name="launch_date"></div>
                                <div class="form-group"><label>Template</label><select name="template_id"><option value="">None</option><?php foreach ($templates as $template): ?><option value="<?php echo (int) $template['id']; ?>"><?php echo htmlspecialchars((string) $template['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign Brief</label><select name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $page): ?><option value="<?php echo (int) $page['id']; ?>"><?php echo htmlspecialchars((string) $page['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">None</option><?php foreach ($options['content_items'] as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Distribution Post</label><select name="distribution_post_id"><option value="">None</option><?php foreach ($distributionPosts as $post): ?><option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars((string) ($post['content_title'] ?? 'Distribution post') . ' - ' . $labelize((string) ($post['channel'] ?? 'other'))); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Email Run</label><select name="email_run_id"><option value="">None</option><?php foreach ($emailRuns as $run): ?><option value="<?php echo (int) $run['id']; ?>"><?php echo htmlspecialchars((string) $run['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group marketing-launch-readiness-wide-field"><label>Manual Launch Checklist</label><textarea name="checklist" rows="4" placeholder="Brief approved&#10;Final copy reviewed&#10;Manual export downloaded&#10;Launch owner confirmed"></textarea></div>
                                <button class="btn-premium-primary marketing-launch-readiness-wide-field" type="submit">Evaluate Readiness</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-launch-readiness-template-card">
                        <div class="premium-section-header"><div><h2>Templates</h2><p>Reusable evidence lists for repeated launches.</p></div></div>
                        <?php if (empty($templates)): ?>
                            <div class="empty-state"><p>No launch readiness templates yet.</p></div>
                        <?php else: ?>
                            <div class="marketing-launch-readiness-template-grid">
                                <?php foreach ($templates as $template): ?>
                                    <article class="marketing-launch-readiness-template-option">
                                        <strong><?php echo htmlspecialchars((string) $template['name']); ?></strong>
                                        <span><?php echo htmlspecialchars($labelize((string) $template['campaign_type'])); ?> - <?php echo count((array) ($template['required_checks_json'] ?? [])); ?> required checks</span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-launch-readiness-template-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="create_template">
                                <div class="form-group"><label>Template Name</label><input type="text" name="name" required placeholder="Product launch checklist"></div>
                                <div class="form-group"><label>Campaign Type</label><select name="campaign_type"><?php foreach (Marketing::LAUNCH_CAMPAIGN_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Required Checks</label><textarea name="required_checks" rows="3" placeholder="Audience locked&#10;Offer approved&#10;UTM link created"></textarea></div>
                                <div class="form-group"><label>Recommended Checks</label><textarea name="recommended_checks" rows="3" placeholder="Hero image approved&#10;Sales handoff note drafted"></textarea></div>
                                <button class="btn-premium-secondary marketing-launch-readiness-wide-field" type="submit">Save Template</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-launch-readiness-expert-card">
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
