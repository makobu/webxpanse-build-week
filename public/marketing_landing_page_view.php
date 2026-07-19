<?php
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
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$id = (int) ($_GET['id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ai_creative') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to run AI creative tools.');
            }
            $marketing->runAiCreativeAction((string) ($_POST['creative_action'] ?? ''), [
                'landing_page_id' => $id,
                'notes' => $_POST['notes'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_landing_page_view.php?id=' . $id . '&success=creative');
            exit;
        }
        if (!$canManageMarketing) {
            throw new RuntimeException('You do not have permission to publish marketing landing pages.');
        }
        if ($action === 'publish') {
            $marketing->publishLandingPage($id, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_landing_page_view.php?id=' . $id . '&success=published');
            exit;
        }
        if ($action === 'unpublish') {
            $marketing->unpublishLandingPage($id, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_landing_page_view.php?id=' . $id . '&success=unpublished');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$page = $marketing->getLandingPage($id);
if (!$page) { http_response_code(404); echo 'Marketing landing page not found.'; exit; }
$readiness = $marketing->getLandingPagePublishingReadiness($id);
$builderChecklist = $marketing->getLandingPageBuilderChecklist($id);
$trackingSummary = $marketing->getMarketingTrackingSummary($id);
$publication = $marketing->getLandingPagePublication($id);
$creativeRuns = $marketing->listAiCreativeRuns(['landing_page_id' => $id], 8, 0);
$versions = $marketing->listLandingPageVersions($id);
$publicUrl = !empty($publication['public_url']) ? getBasePath() . '/' . ltrim((string) $publication['public_url'], '/') : '';
$previewDesktop = (string) ($builderChecklist['preview_links']['desktop'] ?? ('marketing_landing_page_preview.php?token=' . urlencode((string) $page['preview_token'])));
$previewMobile = (string) ($builderChecklist['preview_links']['mobile'] ?? ('marketing_landing_page_preview.php?token=' . urlencode((string) $page['preview_token']) . '&viewport=mobile'));
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$renderMedia = static function (?array $media, Marketing $marketing): string {
    if (!$media) { return ''; }
    $url = $marketing->mediaDisplayUrl($media);
    if ($url === '') { return ''; }
    $title = htmlspecialchars((string) ($media['title'] ?? 'Landing page media'));
    $caption = htmlspecialchars((string) ($media['caption'] ?? ''));
    $alt = htmlspecialchars((string) ($media['alt_text'] ?? $media['title'] ?? 'Landing page media'));
    if ((string) ($media['media_type'] ?? '') === 'video') {
        $html = '<figure class="landing-media"><video controls preload="metadata" src="' . htmlspecialchars($url) . '"></video>';
    } else {
        $html = '<figure class="landing-media"><img src="' . htmlspecialchars($url) . '" alt="' . $alt . '">';
    }
    $html .= '<figcaption>' . ($caption !== '' ? $caption : $title) . '</figcaption></figure>';
    return $html;
};
$jsonBlock = static fn(array $data): string => htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$toneForScore = static function (int $score, int $ready = 80, int $attention = 45): string {
    if ($score >= $ready) { return 'ready'; }
    if ($score >= $attention) { return 'attention'; }
    return 'blocked';
};
$toneForFlag = static fn(bool $ready, bool $attention = false): string => $ready ? 'ready' : ($attention ? 'attention' : 'blocked');

$status = (string) ($page['status'] ?? 'draft');
$publicationStatus = (string) ($publication['status'] ?? 'unpublished');
$isPublished = $publicationStatus === 'published';
$builderScore = max(0, min(100, (int) ($builderChecklist['score'] ?? 0)));
$publishScore = max(0, min(100, (int) ($readiness['score'] ?? 0)));
$requiredMissing = count((array) ($builderChecklist['required_missing'] ?? []));
$recommendedMissing = count((array) ($builderChecklist['recommended_missing'] ?? []));
$mediaWarnings = (array) ($readiness['media_warnings'] ?? []);
$mediaCount = count((array) ($page['_media']['by_id'] ?? []));
$trackedViews = (int) ($trackingSummary['page_views'] ?? 0);
$ctaClicks = (int) ($trackingSummary['cta_clicks'] ?? 0);
$conversions = (int) ($trackingSummary['conversions'] ?? 0);
$hasHeadline = trim((string) ($page['headline'] ?? $page['hero_headline'] ?? '')) !== '';
$hasCta = trim((string) ($page['cta_text'] ?? '')) !== '' || !empty($page['cta_blocks_json']);
$hasForm = !empty($page['form_id']);
$hasTracking = ($trackedViews + $ctaClicks + $conversions) > 0;
$headlineLabel = $hasHeadline ? 'Ready' : 'Setup needed';
$visualLabel = $builderScore >= 80 ? 'Ready' : ($builderScore >= 45 ? 'Setup needed' : 'Blocked');
$publishLabel = $isPublished ? 'In use' : ($publishScore >= 80 ? 'Ready' : 'Setup needed');

$summaryTiles = [
    ['icon' => 'fa-circle-check', 'label' => 'Status', 'value' => $labelize($status), 'tooltip' => 'Current landing page workflow state.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Builder', 'value' => $builderScore . '%', 'tooltip' => 'Visual and content checklist readiness.'],
    ['icon' => 'fa-rocket', 'label' => 'Publish', 'value' => $publishScore . '%', 'tooltip' => 'Evidence needed before tokenized publishing.'],
    ['icon' => 'fa-image', 'label' => 'Media', 'value' => (string) $mediaCount, 'tooltip' => 'Attached hero, proof, CTA, section, and gallery media.'],
    ['icon' => 'fa-chart-line', 'label' => 'Conversions', 'value' => (string) $conversions, 'tooltip' => 'Tracked conversions for this destination.'],
];

$stageCards = [
    [
        'icon' => 'fa-heading',
        'title' => 'Review Hero',
        'status' => $headlineLabel,
        'tone' => $toneForFlag($hasHeadline, true),
        'copy' => 'Make the promise clear at a glance.',
        'tooltip' => 'Checks the first message founders see before judging the rest of the page.',
        'href' => $canWriteMarketing ? 'marketing_landing_page_edit.php?id=' . (int) $page['id'] : '#landing-evidence',
        'action' => $canWriteMarketing ? 'Edit Hero' : 'View Evidence',
    ],
    [
        'icon' => 'fa-photo-film',
        'title' => 'Check Media',
        'status' => $visualLabel,
        'tone' => $toneForScore($builderScore),
        'copy' => 'Confirm the page has useful visuals.',
        'tooltip' => 'Uses the builder checklist and media warnings without showing the full matrix by default.',
        'href' => '#landing-visuals',
        'action' => 'Open Visuals',
    ],
    [
        'icon' => 'fa-rectangle-list',
        'title' => 'Connect Form',
        'status' => $hasForm ? 'Ready' : 'Setup needed',
        'tone' => $toneForFlag($hasForm, true),
        'copy' => 'Give visitors one clear way to respond.',
        'tooltip' => 'The form captures the lead or next action tied to this destination.',
        'href' => $canWriteMarketing ? 'marketing_landing_page_edit.php?id=' . (int) $page['id'] : '#landing-evidence',
        'action' => $canWriteMarketing ? 'Set Form' : 'View Form',
    ],
    [
        'icon' => 'fa-bullseye',
        'title' => 'Tune CTA',
        'status' => $hasCta ? 'Ready' : 'Setup needed',
        'tone' => $toneForFlag($hasCta, true),
        'copy' => 'Make the next step obvious.',
        'tooltip' => 'Keeps the call-to-action visible without exposing every copy block in the first view.',
        'href' => $canWriteMarketing ? 'marketing_landing_page_edit.php?id=' . (int) $page['id'] : '#landing-preview',
        'action' => $canWriteMarketing ? 'Tune CTA' : 'View CTA',
    ],
    [
        'icon' => 'fa-paper-plane',
        'title' => 'Publish Page',
        'status' => $publishLabel,
        'tone' => $isPublished ? 'ready' : $toneForScore($publishScore),
        'copy' => 'Launch only when the evidence is ready.',
        'tooltip' => 'Publishing is manual and tokenized; no external ad, social, or email API is triggered.',
        'href' => '#landing-publishing',
        'action' => $isPublished ? 'Review Live' : 'Review Publish',
    ],
    [
        'icon' => 'fa-chart-simple',
        'title' => 'Learn Results',
        'status' => $hasTracking ? 'In use' : ($isPublished ? 'Ready' : 'Locked'),
        'tone' => $hasTracking ? 'ready' : ($isPublished ? 'attention' : 'blocked'),
        'copy' => 'Use the results to improve the page.',
        'tooltip' => 'Learning starts after the page has views, clicks, or conversion tracking evidence.',
        'href' => '#landing-tracking',
        'action' => 'See Results',
    ],
];

$todayActions = [];
if (!$hasHeadline && $canWriteMarketing) {
    $todayActions[] = ['icon' => 'fa-heading', 'label' => 'Clarify the hero', 'hint' => 'Open the editor and sharpen the first promise.', 'href' => 'marketing_landing_page_edit.php?id=' . (int) $page['id'], 'tooltip' => 'A founder-friendly page starts with one clear promise.'];
}
if ($requiredMissing > 0 || $builderScore < 80) {
    $todayActions[] = ['icon' => 'fa-gauge-high', 'label' => 'Clear visual blockers', 'hint' => $requiredMissing . ' required and ' . $recommendedMissing . ' recommended checks need attention.', 'href' => '#landing-visuals', 'tooltip' => 'Opens the full builder checklist below the first screen.'];
}
if (!$hasForm && $canWriteMarketing) {
    $todayActions[] = ['icon' => 'fa-rectangle-list', 'label' => 'Connect the form', 'hint' => 'Add the capture path before launch.', 'href' => 'marketing_landing_page_edit.php?id=' . (int) $page['id'], 'tooltip' => 'A landing page needs one clear response path.'];
}
if (!$isPublished) {
    $todayActions[] = ['icon' => 'fa-display', 'label' => 'Preview the page', 'hint' => 'Check the desktop preview before publishing.', 'href' => $previewDesktop, 'tooltip' => 'Preview keeps review visual without opening every page block.'];
}
if (!$isPublished && $canManageMarketing) {
    $todayActions[] = ['icon' => 'fa-paper-plane', 'label' => 'Review publishing', 'hint' => 'Open the publish controls once evidence is ready.', 'href' => '#landing-publishing', 'tooltip' => 'Publishing remains manual and permission-gated.'];
}
if ($isPublished && $publicUrl !== '') {
    $todayActions[] = ['icon' => 'fa-arrow-up-right-from-square', 'label' => 'Open public page', 'hint' => 'Review the live tokenized destination.', 'href' => $publicUrl, 'tooltip' => 'Shows the live CRM-hosted page in a new tab.', 'external' => true];
}
if (empty($todayActions)) {
    $todayActions[] = ['icon' => 'fa-chart-line', 'label' => 'Review performance', 'hint' => 'Use views, clicks, and conversions to choose the next improvement.', 'href' => '#landing-tracking', 'tooltip' => 'Learning evidence appears once the page has activity.'];
}
$todayActions = array_slice($todayActions, 0, 5);

$pageTitle = (string) $page['title'] . ' - Marketing Landing Page - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-ui-page marketing-landing-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $page['title']); ?></h1>
                <p>Review the destination, visuals, form, and publish state.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_landing_pages.php">Landing Pages</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_landing_page_edit.php?id=<?php echo (int) $page['id']; ?>">Edit</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'saved'): ?><div class="alert alert-success">Landing page plan saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'published'): ?><div class="alert alert-success">Landing page published to a tokenized CRM public page.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'unpublished'): ?><div class="alert alert-success">Landing page unpublished.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'creative'): ?><div class="alert alert-success">AI visual direction saved for review. No landing page fields were overwritten.</div><?php endif; ?>

        <section class="marketing-landing-view-summary" aria-label="Landing page summary">
            <?php foreach ($summaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                    <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>"></i>
                    <div>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="marketing-landing-view-layout" aria-label="Destination review workspace">
            <div class="marketing-landing-view-main">
                <div class="content-card marketing-landing-view-board">
                    <div class="premium-section-header">
                        <div>
                            <h2>Destination Review Board</h2>
                            <p>Six checks, one visible action each.</p>
                        </div>
                        <span class="badge badge-default">/<?php echo htmlspecialchars((string) $page['slug']); ?></span>
                    </div>
                    <div class="marketing-landing-view-card-grid">
                        <?php foreach ($stageCards as $card): ?>
                            <article class="marketing-landing-view-card <?php echo htmlspecialchars((string) $card['tone']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                                <div class="marketing-landing-view-visual"><i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>"></i></div>
                                <div class="marketing-landing-view-card-body">
                                    <div class="marketing-landing-view-card-title">
                                        <strong><?php echo htmlspecialchars((string) $card['title']); ?></strong>
                                        <span class="marketing-landing-view-status <?php echo htmlspecialchars((string) $card['tone']); ?>"><?php echo htmlspecialchars((string) $card['status']); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars((string) $card['copy']); ?></span>
                                </div>
                                <a class="btn-premium-secondary marketing-landing-view-card-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <aside class="content-card marketing-landing-view-today" aria-label="Today">
                <div class="premium-section-header">
                    <div>
                        <h2>Today</h2>
                        <p>Next useful moves.</p>
                    </div>
                </div>
                <div class="marketing-landing-view-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-landing-view-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" <?php echo !empty($action['external']) ? 'target="_blank" rel="noopener"' : ''; ?> tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $action['tooltip']); ?>">
                            <i class="fas <?php echo htmlspecialchars((string) $action['icon']); ?>"></i>
                            <span><strong><?php echo htmlspecialchars((string) $action['label']); ?></strong><?php echo htmlspecialchars((string) $action['hint']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>

        <details class="marketing-landing-view-tools" id="landing-evidence">
            <summary>More destination evidence</summary>
            <div class="marketing-landing-view-tools-body">
                <div class="content-card" id="landing-visuals">
                    <div class="premium-section-header">
                        <div>
                            <h2>Landing Visual Readiness</h2>
                            <p>Detailed checklist, warnings, and guardrails.</p>
                        </div>
                        <span class="badge badge-default"><?php echo $builderScore; ?>% builder ready</span>
                    </div>
                    <div class="builder-readiness">
                        <aside class="builder-score">
                            <div class="marketing-detail-label">Builder Score</div>
                            <strong><?php echo $builderScore; ?>%</strong>
                            <p>Required missing: <?php echo $requiredMissing; ?>. Recommended missing: <?php echo $recommendedMissing; ?>.</p>
                            <div class="builder-guardrails">
                                <?php foreach ((array) ($builderChecklist['guardrails'] ?? []) as $guardrail): ?><span><?php echo htmlspecialchars((string) $guardrail); ?></span><?php endforeach; ?>
                            </div>
                        </aside>
                        <div>
                            <div class="builder-checklist-grid">
                                <?php foreach ((array) ($builderChecklist['checks'] ?? []) as $check): $isComplete = (bool) ($check['complete'] ?? false); ?>
                                    <section class="builder-check <?php echo $isComplete ? 'is-complete' : 'is-missing'; ?>">
                                        <div class="builder-check-status"><?php echo $isComplete ? 'Complete' : ((bool) ($check['required'] ?? false) ? 'Required' : 'Recommended'); ?></div>
                                        <h3><?php echo htmlspecialchars((string) ($check['label'] ?? 'Checklist item')); ?></h3>
                                        <p><?php echo htmlspecialchars((string) ($check['message'] ?? '')); ?></p>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($builderChecklist['next_actions'])): ?>
                                <div class="builder-action-list">
                                    <?php foreach ((array) $builderChecklist['next_actions'] as $action): ?>
                                        <a href="<?php echo htmlspecialchars((string) ($action['href'] ?? '#')); ?>"><span><strong><?php echo htmlspecialchars((string) ($action['label'] ?? 'Next action')); ?></strong><small><?php echo htmlspecialchars((string) ($action['reason'] ?? '')); ?></small></span><span><?php echo htmlspecialchars(ucwords((string) ($action['priority'] ?? 'medium'))); ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="content-card" id="landing-publishing">
                    <div class="premium-section-header">
                        <div>
                            <h2>Publishing Foundation</h2>
                            <p>Tokenized CRM publishing, kept manual and permission-gated.</p>
                        </div>
                        <span class="badge badge-default"><?php echo $publishScore; ?>% ready</span>
                    </div>
                    <?php if (!empty($readiness['missing'])): ?>
                        <div class="alert alert-warning">Missing before publish: <?php echo htmlspecialchars(implode(', ', array_map($labelize, (array) $readiness['missing']))); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($mediaWarnings)): ?>
                        <div class="alert alert-warning">Media warnings: <?php echo htmlspecialchars(implode(', ', array_map($labelize, $mediaWarnings))); ?></div>
                    <?php endif; ?>
                    <div class="marketing-detail-grid">
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Publication Status</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize($publicationStatus)); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Public URL</div><div class="marketing-detail-value"><?php echo $publicUrl !== '' ? '<a href="' . htmlspecialchars($publicUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($publicUrl) . '</a>' : '-'; ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Published At</div><div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($publication['published_at'] ?? '-')); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Draft Version</div><div class="marketing-detail-value"><?php echo !empty($page['draft_version_id']) ? '#' . (int) $page['draft_version_id'] : '-'; ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Published Version</div><div class="marketing-detail-value"><?php echo !empty($page['published_version_id']) ? '#' . (int) $page['published_version_id'] : '-'; ?></div></div>
                    </div>
                    <?php if ($canManageMarketing): ?>
                        <form method="POST" class="landing-action-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <?php if ($isPublished): ?>
                                <button class="btn-premium-secondary" type="submit" name="action" value="unpublish">Unpublish</button>
                            <?php else: ?>
                                <button class="btn-premium-primary" type="submit" name="action" value="publish">Publish Tokenized Page</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="marketing-detail-grid" id="landing-tracking">
                    <div class="marketing-detail-card"><div class="marketing-detail-label">Tracked Views</div><div class="marketing-detail-value"><?php echo $trackedViews; ?></div></div>
                    <div class="marketing-detail-card"><div class="marketing-detail-label">CTA Clicks</div><div class="marketing-detail-value"><?php echo $ctaClicks; ?></div></div>
                    <div class="marketing-detail-card"><div class="marketing-detail-label">Conversions</div><div class="marketing-detail-value"><?php echo $conversions; ?><?php if (($trackingSummary['conversion_rate'] ?? null) !== null): ?> &middot; <?php echo htmlspecialchars((string) $trackingSummary['conversion_rate']); ?>%<?php endif; ?></div></div>
                </div>

                <div class="content-card">
                    <div class="premium-section-header"><h2>Destination Details</h2></div>
                    <div class="marketing-detail-grid">
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Campaign</div><div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($page['campaign_name'] ?? '-')); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Form</div><div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($page['form_name'] ?? '-')); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Audience Segment</div><div class="marketing-detail-value"><?php if (!empty($page['audience_segment_id'])): ?><a href="marketing_segment_view.php?id=<?php echo (int) $page['audience_segment_id']; ?>"><?php echo htmlspecialchars((string) ($page['audience_segment_name'] ?? '-')); ?></a><?php else: ?>-<?php endif; ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Conversion Goal</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) ($page['conversion_goal'] ?? 'lead_capture'))); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Goal Record</div><div class="marketing-detail-value"><?php echo !empty($page['conversion_goal_id']) ? htmlspecialchars((string) ($page['conversion_goal_title'] ?? ('Goal #' . (int) $page['conversion_goal_id']))) : '-'; ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Builder Status</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) ($page['builder_status'] ?? 'draft'))); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Versions</div><div class="marketing-detail-value"><?php echo count($versions); ?> snapshots</div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">SEO Title</div><div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($page['seo_title'] ?? '-')); ?></div></div>
                        <div class="marketing-detail-card"><div class="marketing-detail-label">Meta Description</div><div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($page['meta_description'] ?? '-')); ?></div></div>
                    </div>
                    <div class="marketing-detail-card">
                        <div class="marketing-detail-label">Headline</div>
                        <div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($page['headline'] ?? '-')); ?></div>
                        <?php echo $renderMedia($page['_media']['hero'] ?? null, $marketing); ?>
                    </div>
                </div>

                <div class="marketing-detail-grid">
                    <div class="content-card"><div class="premium-section-header"><h2>Theme Settings</h2></div><pre class="marketing-json-preview"><?php echo $jsonBlock((array) ($page['theme_settings_json'] ?? [])); ?></pre></div>
                    <div class="content-card"><div class="premium-section-header"><h2>SEO And Social Preview</h2></div><pre class="marketing-json-preview"><?php echo $jsonBlock(['seo' => (array) ($page['seo_controls_json'] ?? []), 'social' => (array) ($page['social_preview_json'] ?? [])]); ?></pre></div>
                    <div class="content-card"><div class="premium-section-header"><h2>Form Placement</h2></div><pre class="marketing-json-preview"><?php echo $jsonBlock((array) ($page['form_blocks_json'] ?? [])); ?></pre></div>
                </div>
            </div>
        </details>

        <details class="marketing-landing-view-tools" id="landing-preview">
            <summary>Preview and manage page</summary>
            <div class="marketing-landing-view-tools-body">
                <div class="content-card">
                    <div class="premium-section-header">
                        <div>
                            <h2>Landing Page Preview</h2>
                            <p>Review copy blocks, media sections, and page snapshots.</p>
                        </div>
                        <div class="page-header-actions">
                            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($previewDesktop); ?>">Desktop Preview</a>
                            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($previewMobile); ?>">Mobile Preview</a>
                        </div>
                    </div>
                    <div class="marketing-detail-grid">
                        <div class="content-card"><div class="premium-section-header"><h2>Body Sections</h2></div>
                            <?php if (empty($page['body_sections_json'])): ?><div class="empty-state"><p>No page sections yet.</p></div><?php else: foreach ((array) $page['body_sections_json'] as $section): ?>
                                <section class="marketing-detail-card"><strong><?php echo htmlspecialchars((string) ($section['heading'] ?? 'Section')); ?></strong><div class="landing-copy-block"><?php echo htmlspecialchars((string) ($section['body'] ?? '')); ?></div></section>
                            <?php endforeach; endif; ?>
                            <?php foreach ((array) ($page['section_media_json'] ?? []) as $entry): $media = $page['_media']['by_id'][(int) ($entry['media_file_id'] ?? 0)] ?? null; ?>
                                <section class="marketing-detail-card"><strong><?php echo htmlspecialchars((string) ($entry['heading'] ?? 'Section media')); ?></strong><?php echo $renderMedia($media, $marketing); ?><?php if (!empty($entry['caption'])): ?><p class="landing-muted-copy"><?php echo htmlspecialchars((string) $entry['caption']); ?></p><?php endif; ?></section>
                            <?php endforeach; ?>
                        </div>
                        <div class="content-card"><div class="premium-section-header"><h2>CTA Blocks</h2></div><?php echo $renderMedia($page['_media']['cta'] ?? null, $marketing); ?><?php if (empty($page['cta_blocks_json'])): ?><div class="empty-state"><p>No CTA blocks yet.</p></div><?php else: foreach ((array) $page['cta_blocks_json'] as $section): ?><section class="marketing-detail-card"><strong><?php echo htmlspecialchars((string) ($section['heading'] ?? 'CTA')); ?></strong><div><?php echo htmlspecialchars((string) ($section['body'] ?? '')); ?></div></section><?php endforeach; endif; ?></div>
                        <div class="content-card"><div class="premium-section-header"><h2>Proof Blocks</h2></div><?php echo $renderMedia($page['_media']['proof'] ?? null, $marketing); ?><?php echo $renderMedia($page['_media']['testimonial'] ?? null, $marketing); ?><?php if (empty($page['proof_blocks_json'])): ?><div class="empty-state"><p>No proof blocks yet.</p></div><?php else: foreach ((array) $page['proof_blocks_json'] as $section): ?><section class="marketing-detail-card"><strong><?php echo htmlspecialchars((string) ($section['heading'] ?? 'Proof')); ?></strong><div><?php echo htmlspecialchars((string) ($section['body'] ?? '')); ?></div></section><?php endforeach; endif; ?></div>
                        <div class="content-card"><div class="premium-section-header"><h2>FAQ Blocks</h2></div><?php if (empty($page['faq_blocks_json'])): ?><div class="empty-state"><p>No FAQs yet.</p></div><?php else: foreach ((array) $page['faq_blocks_json'] as $section): ?><section class="marketing-detail-card"><strong><?php echo htmlspecialchars((string) ($section['heading'] ?? 'Question')); ?></strong><div><?php echo htmlspecialchars((string) ($section['body'] ?? '')); ?></div></section><?php endforeach; endif; ?></div>
                    </div>
                    <?php if (!empty($page['gallery_media_json'])): ?><div class="content-card landing-inner-section"><div class="premium-section-header"><h2>Media Gallery</h2></div><div class="marketing-detail-grid"><?php foreach ((array) $page['gallery_media_json'] as $entry): $media = $page['_media']['by_id'][(int) ($entry['media_file_id'] ?? 0)] ?? null; ?><div><?php echo $renderMedia($media, $marketing); ?></div><?php endforeach; ?></div></div><?php endif; ?>
                    <div class="content-card landing-inner-section"><div class="premium-section-header"><h2>Thank-you Copy</h2></div><div class="landing-copy-block"><?php echo htmlspecialchars((string) ($page['thank_you_copy'] ?? '')); ?></div></div>
                </div>

                <div class="content-card">
                    <div class="premium-section-header"><h2>Version Snapshots</h2></div>
                    <?php if (empty($versions)): ?><div class="empty-state"><p>No version snapshots yet.</p></div><?php else: foreach (array_slice($versions, 0, 8) as $version): ?>
                        <section class="marketing-detail-card"><strong><?php echo htmlspecialchars($labelize((string) $version['version_type'])); ?> v<?php echo (int) $version['version_number']; ?></strong><div class="marketing-detail-label"><?php echo htmlspecialchars((string) ($version['created_at'] ?? '')); ?> &middot; <?php echo htmlspecialchars((string) ($version['title'] ?? '')); ?></div></section>
                    <?php endforeach; endif; ?>
                </div>

                <div class="content-card">
                    <div class="premium-section-header"><h2>AI Visual Direction</h2></div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="landing-creative-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="ai_creative">
                            <div class="form-group"><label>Creative Tool</label><select name="creative_action">
                                <option value="landing_visual_direction">Suggest Visuals</option>
                                <option value="image_prompt">Generate Image Prompt</option>
                                <option value="video_storyboard">Create Video Storyboard</option>
                                <option value="thumbnail_concept">Thumbnail Concept</option>
                                <option value="media_readiness_review">Review Media Readiness</option>
                            </select></div>
                            <div class="form-group"><label>Notes</label><input type="text" name="notes" placeholder="Optional direction for the visual pass"></div>
                            <button class="btn-premium-secondary" type="submit">Run Creative Tool</button>
                        </form>
                    <?php endif; ?>
                    <?php if (empty($creativeRuns)): ?>
                        <div class="empty-state"><p>No AI visual direction runs yet.</p></div>
                    <?php else: foreach ($creativeRuns as $run): ?>
                        <section class="marketing-detail-card"><strong><?php echo htmlspecialchars($labelize((string) $run['action'])); ?></strong><div class="marketing-detail-label"><?php echo htmlspecialchars((string) $run['created_at']); ?> &middot; <?php echo htmlspecialchars((string) ($run['prompt_key'] ?? '')); ?></div><pre class="marketing-json-preview"><?php echo $jsonBlock((array) ($run['result_json'] ?? [])); ?></pre></section>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </details>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
