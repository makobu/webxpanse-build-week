<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingMediaReadinessUi;
use CRM\Services\MarketingWorkflowNextStepsUi;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to create landing page follow-through work.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if ((string) ($_POST['action'] ?? '') !== 'queue_followthrough') {
            throw new RuntimeException('Unsupported landing page action.');
        }

        $result = $marketing->createMarketingQueueAction([
            'surface' => 'landing_visual',
            'source_id' => (int) ($_POST['source_id'] ?? 0),
            'action_key' => (string) ($_POST['action_key'] ?? ''),
        ], (int) ($user['id'] ?? 0));
        $createdCount = count((array) ($result['created'] ?? []));
        $reusedCount = count((array) ($result['reused'] ?? []));
        $warningCount = count((array) ($result['warnings'] ?? []));
        header('Location: ' . getBasePath() . '/marketing_landing_pages.php?success=queue_action&created=' . $createdCount . '&reused=' . $reusedCount . '&warnings=' . $warningCount);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = (string) ($_GET['status'] ?? '');
$pages = $marketing->listLandingPages($status !== '' ? ['status' => $status] : [], 100, 0);
$allPages = $status !== '' ? $marketing->listLandingPages([], 100, 0) : $pages;
$visualQueue = $marketing->getCachedLandingPageVisualCommandQueue(6, 90);
$mediaReadiness = $marketing->getMarketingMediaOperationalReadiness((int) ($user['id'] ?? 0), 6);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'landing', 6);
$success = (string) ($_GET['success'] ?? '');
if ($success === 'queue_action') {
    $notice = (int) ($_GET['created'] ?? 0) . ' landing page follow-through item(s) created; ' . (int) ($_GET['reused'] ?? 0) . ' existing open item(s) reused.';
    if ((int) ($_GET['warnings'] ?? 0) > 0) {
        $notice .= ' Some cross-tool actions were skipped because their target tool permissions are unavailable.';
    }
}

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$landingQueueActionKeys = [
    'needs_hero_media' => 'landing_hero_visual',
    'needs_social_preview' => 'landing_social_preview',
    'needs_accessibility' => 'landing_accessibility',
    'needs_conversion' => 'landing_conversion',
    'ready_to_preview' => 'landing_preview_review',
];

$pageCount = count($allPages);
$visiblePageCount = count($pages);
$reviewCount = count(array_filter($allPages, static fn(array $page): bool => (string) ($page['status'] ?? '') === 'review'));
$approvedCount = count(array_filter($allPages, static fn(array $page): bool => (string) ($page['status'] ?? '') === 'approved'));
$publishedCount = count(array_filter($allPages, static fn(array $page): bool => (string) ($page['publication_status'] ?? '') === 'published'));
$previewReadyCount = (int) ($visualQueue['counts']['ready_to_preview'] ?? 0);
$needsVisualCount = (int) ($visualQueue['counts']['needs_hero_media'] ?? 0)
    + (int) ($visualQueue['counts']['needs_social_preview'] ?? 0)
    + (int) ($visualQueue['counts']['needs_accessibility'] ?? 0)
    + (int) ($visualQueue['counts']['needs_conversion'] ?? 0);
$visualScore = (int) ($visualQueue['score'] ?? 0);

$summaryCards = [
    ['label' => 'Pages', 'value' => $pageCount, 'icon' => 'fa-file-lines', 'tooltip' => 'Landing page plans in this workspace.'],
    ['label' => 'Approved', 'value' => $approvedCount, 'icon' => 'fa-circle-check', 'tooltip' => 'Plans approved for launch preparation.'],
    ['label' => 'Published', 'value' => $publishedCount, 'icon' => 'fa-globe', 'tooltip' => 'CRM-hosted pages currently published publicly.'],
    ['label' => 'Visuals', 'value' => $visualScore . '%', 'icon' => 'fa-photo-film', 'tooltip' => 'Visual readiness score for hero media, previews, accessibility, conversion, and preview review.'],
];

$landingStages = [
    [
        'title' => 'Draft Page',
        'icon' => 'fa-pen-ruler',
        'status' => $pageCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $pageCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $pageCount,
        'metric_label' => $pageCount === 1 ? 'page' : 'pages',
        'tooltip' => 'Start with one destination for the campaign offer and audience.',
        'action_label' => $canWriteMarketing ? 'New page' : 'View pages',
        'action_href' => $canWriteMarketing ? 'marketing_landing_page_edit.php' : '#landing-board',
    ],
    [
        'title' => 'Add Visuals',
        'icon' => 'fa-image',
        'status' => $needsVisualCount > 0 ? 'setup_needed' : ($pageCount > 0 ? 'ready' : 'locked'),
        'badge' => $needsVisualCount > 0 ? 'Setup needed' : ($pageCount > 0 ? 'Ready' : 'Locked'),
        'metric' => (string) $needsVisualCount,
        'metric_label' => 'gaps',
        'tooltip' => 'Hero images, social previews, and accessibility checks make the page easier to trust.',
        'action_label' => 'Visual queue',
        'action_href' => '#landing-tools',
    ],
    [
        'title' => 'Review Copy',
        'icon' => 'fa-clipboard-check',
        'status' => $reviewCount > 0 ? 'setup_needed' : ($approvedCount > 0 ? 'ready' : ($pageCount > 0 ? 'setup_needed' : 'locked')),
        'badge' => $reviewCount > 0 ? 'Setup needed' : ($approvedCount > 0 ? 'Ready' : ($pageCount > 0 ? 'Setup needed' : 'Locked')),
        'metric' => (string) $reviewCount,
        'metric_label' => 'in review',
        'tooltip' => 'Review keeps the message, CTA, form, and destination aligned before publishing.',
        'action_label' => 'Review',
        'action_href' => '#landing-board',
    ],
    [
        'title' => 'Preview',
        'icon' => 'fa-eye',
        'status' => $previewReadyCount > 0 ? 'ready' : ($approvedCount > 0 ? 'setup_needed' : 'locked'),
        'badge' => $previewReadyCount > 0 ? 'Ready' : ($approvedCount > 0 ? 'Setup needed' : 'Locked'),
        'metric' => (string) $previewReadyCount,
        'metric_label' => 'ready',
        'tooltip' => 'Preview lets a founder inspect the page before it becomes public.',
        'action_label' => 'Preview queue',
        'action_href' => '#landing-tools',
    ],
    [
        'title' => 'Publish',
        'icon' => 'fa-rocket',
        'status' => $publishedCount > 0 ? 'in_use' : ($approvedCount > 0 ? 'setup_needed' : 'locked'),
        'badge' => $publishedCount > 0 ? 'In use' : ($approvedCount > 0 ? 'Setup needed' : 'Locked'),
        'metric' => (string) $publishedCount,
        'metric_label' => 'published',
        'tooltip' => 'Published pages stay connected to campaigns, forms, and performance learning.',
        'action_label' => 'Send / Export',
        'action_href' => 'marketing_distribution.php',
    ],
];

$todayActions = [];
if ($pageCount === 0 && $canWriteMarketing) {
    $todayActions[] = ['label' => 'Create landing page', 'href' => 'marketing_landing_page_edit.php', 'meta' => 'first destination'];
}
if ($needsVisualCount > 0) {
    $todayActions[] = ['label' => 'Clear visual gap', 'href' => '#landing-tools', 'meta' => $needsVisualCount . ' gaps'];
}
if ($reviewCount > 0) {
    $todayActions[] = ['label' => 'Review a page', 'href' => '#landing-board', 'meta' => $reviewCount . ' waiting'];
}
if ($previewReadyCount > 0) {
    $todayActions[] = ['label' => 'Preview ready page', 'href' => '#landing-tools', 'meta' => $previewReadyCount . ' ready'];
}
if ($publishedCount > 0) {
    $todayActions[] = ['label' => 'Check performance', 'href' => 'marketing_performance.php', 'meta' => $publishedCount . ' live'];
}
if ($todayActions === [] && $pageCount > 0) {
    $todayActions[] = ['label' => 'Prepare send/export', 'href' => 'marketing_distribution.php', 'meta' => 'next step'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open Design home', 'href' => 'design.php', 'meta' => 'next context'];
}
$todayActions = array_slice($todayActions, 0, 5);

$pageTitle = 'Design Landing Pages - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-landing-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Landing Page Board</h1>
                <p>Build and review the campaign destination before launch.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php"><i class="fas fa-plus"></i> New Page</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Landing page follow-through failed.</strong> <?php echo $h($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

        <section class="marketing-landing-shell" aria-label="Landing page board">
            <div class="marketing-founder-summary marketing-landing-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-landing-stage-grid" aria-label="Landing page path">
                <?php foreach ($landingStages as $stage): ?>
                    <article class="marketing-landing-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-landing-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-landing-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-landing-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-landing-layout">
                <section class="marketing-landing-board" id="landing-board">
                    <div class="premium-section-header">
                        <h2>Landing Page Plans</h2>
                        <p><?php echo $h((string) $visiblePageCount); ?> page<?php echo $visiblePageCount === 1 ? '' : 's'; ?> in this view.</p>
                    </div>
                    <div class="marketing-landing-list">
                        <?php if (empty($pages)): ?>
                            <div class="empty-state">
                                <p>No landing pages match this view.</p>
                                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php">Create a page</a><?php endif; ?>
                            </div>
                        <?php else: foreach ($pages as $page): ?>
                            <?php
                                $publicationStatus = (string) ($page['publication_status'] ?? 'unpublished');
                                $readinessScore = (int) ($page['publication_readiness_score'] ?? 0);
                                $tooltip = $labelize((string) ($page['status'] ?? 'draft')) . ' plan, ' . $labelize($publicationStatus) . ' publication, ' . $readinessScore . '% publication readiness.';
                            ?>
                            <article class="marketing-landing-card <?php echo $h((string) ($page['status'] ?? 'draft')); ?>" data-tooltip="<?php echo $h($tooltip); ?>" tabindex="0">
                                <div>
                                    <span class="marketing-stage-status"><?php echo $h($labelize((string) ($page['status'] ?? 'draft'))); ?></span>
                                    <h3><a href="marketing_landing_page_view.php?id=<?php echo (int) $page['id']; ?>"><?php echo $h((string) $page['title']); ?></a></h3>
                                    <div class="marketing-landing-meta">
                                        <span>/<?php echo $h((string) $page['slug']); ?></span>
                                        <span><?php echo $h($labelize($publicationStatus)); ?></span>
                                        <span><?php echo $h((string) ($page['campaign_name'] ?? 'No campaign')); ?></span>
                                    </div>
                                </div>
                                <div class="marketing-landing-readiness">
                                    <strong><?php echo $readinessScore; ?>%</strong>
                                    <span>readiness</span>
                                </div>
                                <a class="btn-premium-secondary" href="marketing_landing_page_view.php?id=<?php echo (int) $page['id']; ?>">Open</a>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

                <aside class="marketing-landing-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One destination step before launch.</p>
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

            <details class="marketing-landing-tools" id="landing-tools">
                <summary>More landing tools</summary>
                <div class="marketing-landing-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_landing_page_edit.php" data-tooltip="Create a CRM-hosted campaign destination."><i class="fas fa-plus"></i><span>New Landing Page</span></a>
                        <a href="marketing_assets.php" data-tooltip="Manage hero, social, CTA, proof, and gallery media."><i class="fas fa-photo-film"></i><span>Assets</span></a>
                        <a href="marketing_seo.php" data-tooltip="Connect landing pages to search topics."><i class="fas fa-magnifying-glass-chart"></i><span>SEO Topics</span></a>
                        <a href="marketing_launch_checklists.php" data-tooltip="Move approved pages into launch review."><i class="fas fa-list-check"></i><span>Launch Checks</span></a>
                        <a href="marketing_onboarding.php" data-tooltip="Return to Marketing setup prerequisites."><i class="fas fa-wand-magic-sparkles"></i><span>Marketing Setup</span></a>
                        <a href="marketing.php" data-tooltip="Return to the visual Marketing command path."><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-landing-detail-grid">
                        <article class="marketing-landing-detail-section">
                            <div class="premium-section-header"><h2>Filter Pages</h2></div>
                            <form class="marketing-landing-filter" method="GET">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="">All</option>
                                        <?php foreach (Marketing::LANDING_PAGE_STATUSES as $pageStatus): ?><option value="<?php echo $h($pageStatus); ?>" <?php echo $selected($status, $pageStatus); ?>><?php echo $h($labelize($pageStatus)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn-premium-secondary" type="submit">Filter</button>
                            </form>
                        </article>

                        <article class="marketing-landing-detail-section">
                            <div class="premium-section-header"><h2>Visual Signals</h2></div>
                            <div class="marketing-landing-signal-grid">
                                <article class="marketing-landing-signal-card" data-tooltip="Landing pages scanned by the visual readiness queue." tabindex="0"><span>Scanned</span><strong><?php echo (int) ($visualQueue['counts']['scanned'] ?? 0); ?></strong></article>
                                <article class="marketing-landing-signal-card" data-tooltip="Pages missing hero media." tabindex="0"><span>Hero</span><strong><?php echo (int) ($visualQueue['counts']['needs_hero_media'] ?? 0); ?></strong></article>
                                <article class="marketing-landing-signal-card" data-tooltip="Pages ready for authenticated preview review." tabindex="0"><span>Preview</span><strong><?php echo $previewReadyCount; ?></strong></article>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-landing-detail-grid">
                        <article class="marketing-landing-detail-section" id="landing-visual-queue">
                            <div class="premium-section-header">
                                <h2>Landing Visual Readiness</h2>
                                <p>Media-led conversion queue for destination checks.</p>
                            </div>
                            <div class="marketing-landing-queue-grid">
                                <?php foreach ([
                                    'needs_hero_media' => 'Needs Hero',
                                    'needs_social_preview' => 'Needs Social Preview',
                                    'needs_accessibility' => 'Needs Accessibility',
                                    'needs_conversion' => 'Needs Conversion',
                                    'ready_to_preview' => 'Ready To Preview',
                                ] as $queueKey => $queueLabel): ?>
                                    <article class="marketing-landing-queue-card" data-tooltip="<?php echo $h($queueLabel); ?>" tabindex="0">
                                        <h3><?php echo $h($queueLabel); ?></h3>
                                        <?php $queueItems = (array) ($visualQueue['queues'][$queueKey] ?? []); ?>
                                        <?php if (empty($queueItems)): ?>
                                            <span>No pages.</span>
                                        <?php else: ?>
                                            <?php foreach ($queueItems as $queueItem): ?>
                                                <a href="marketing_landing_page_view.php?id=<?php echo (int) ($queueItem['id'] ?? 0); ?>">
                                                    <strong><?php echo $h((string) ($queueItem['title'] ?? 'Landing page')); ?></strong>
                                                    <small><?php echo $h((string) ($queueItem['slug'] ?? '')); ?> <?php echo (int) ($queueItem['score'] ?? 0); ?>% ready</small>
                                                </a>
                                                <?php if ($canWriteMarketing): ?>
                                                    <form class="marketing-landing-task-form" method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="queue_followthrough">
                                                        <input type="hidden" name="source_id" value="<?php echo (int) ($queueItem['id'] ?? 0); ?>">
                                                        <input type="hidden" name="action_key" value="<?php echo $h((string) ($landingQueueActionKeys[$queueKey] ?? 'landing_preview_review')); ?>">
                                                        <button class="btn-premium-secondary" type="submit">Create task/request</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <article class="marketing-landing-detail-section">
                            <div class="premium-section-header"><h2>Next Visual Actions</h2></div>
                            <div class="marketing-landing-action-list">
                                <?php if (empty($visualQueue['next_actions'])): ?>
                                    <span>No visual actions are queued.</span>
                                <?php else: foreach ((array) ($visualQueue['next_actions'] ?? []) as $action): ?>
                                    <a href="<?php echo $h((string) ($action['href'] ?? 'marketing_landing_pages.php')); ?>">
                                        <strong><?php echo $h((string) ($action['label'] ?? 'Review landing visual readiness')); ?></strong>
                                        <small><?php echo $h((string) ($action['reason'] ?? 'Review landing page visual readiness.')); ?></small>
                                    </a>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="marketing-landing-guardrail">
                                <strong>Manual-first guardrail:</strong>
                                <?php echo $h((string) ($visualQueue['guardrails']['message'] ?? 'Manual-first visual readiness only.')); ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-landing-detail-section">
                        <div class="premium-section-header"><h2>Landing next steps</h2></div>
                        <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                    </section>

                    <section class="marketing-landing-detail-section">
                        <div class="premium-section-header"><h2>Media Readiness</h2></div>
                        <?php echo MarketingMediaReadinessUi::render($mediaReadiness); ?>
                    </section>

                    <section class="marketing-landing-detail-section">
                        <div class="premium-section-header"><h2>Page Links</h2></div>
                        <div class="marketing-landing-link-list">
                            <?php if (empty($pages)): ?>
                                <span>No page links in this view.</span>
                            <?php else: foreach ($pages as $page): ?>
                                <div class="marketing-landing-link-row">
                                    <strong><?php echo $h((string) $page['title']); ?></strong>
                                    <div>
                                        <a class="btn-premium-secondary" href="marketing_landing_page_view.php?id=<?php echo (int) $page['id']; ?>">View</a>
                                        <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_landing_page_edit.php?id=<?php echo (int) $page['id']; ?>">Edit</a><?php endif; ?>
                                        <?php if (!empty($page['public_url']) && (string) ($page['publication_status'] ?? '') === 'published'): ?><a class="btn-premium-secondary" href="<?php echo $h(getBasePath() . '/' . ltrim((string) $page['public_url'], '/')); ?>" target="_blank" rel="noopener">Public</a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
