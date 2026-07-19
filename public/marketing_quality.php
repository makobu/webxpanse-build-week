<?php
/**
 * Marketing AI quality and brand-safety checks.
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
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to run quality checks.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $contentId = (int) ($_POST['content_item_id'] ?? 0);
        $marketing->runContentQualityCheck($contentId, (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_quality.php?success=checked&content_id=' . $contentId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$contentId = (int) ($_GET['content_id'] ?? 0);
$filters = $contentId > 0 ? ['content_item_id' => $contentId] : [];
$qualitySummary = $marketing->getMarketingQualityReviewSummary();
$checks = $marketing->listContentQualityChecks($filters, 100, 0);
$items = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));

$status = (string) ($qualitySummary['status'] ?? 'unknown');
$averageScore = (int) ($qualitySummary['average_score'] ?? 0);
$counts = (array) ($qualitySummary['counts'] ?? []);
$needsReview = (int) ($counts['needs_review'] ?? 0);
$riskItems = (int) ($counts['risk_items'] ?? 0);
$draftSafeChecks = (int) ($counts['draft_safe_checks'] ?? 0);
$aiUsed = (int) ($counts['ai_used'] ?? 0);
$fallbackUsed = (int) ($counts['fallback_used'] ?? 0);
$contentNeedingReview = (array) ($qualitySummary['content_needing_review'] ?? []);
$dimensions = (array) ($qualitySummary['dimensions'] ?? []);
$recommendedActions = (array) ($qualitySummary['recommended_actions'] ?? []);

$summaryCards = [
    ['label' => 'Score', 'value' => $averageScore . '/100', 'icon' => 'fa-shield-halved', 'tooltip' => 'Average advisory quality score across recent content checks.'],
    ['label' => 'Needs Review', 'value' => $needsReview, 'icon' => 'fa-clipboard-check', 'tooltip' => 'Content items that need a founder or operator review before use.'],
    ['label' => 'Risk Items', 'value' => $riskItems, 'icon' => 'fa-triangle-exclamation', 'tooltip' => 'Potential brand, compliance, CTA, SEO, or persona-fit issues.'],
    ['label' => 'Draft-Safe', 'value' => $draftSafeChecks, 'icon' => 'fa-lock', 'tooltip' => 'Checks run without publishing, sending, or overwriting draft copy.'],
];

$qualityStages = [
    [
        'title' => 'Choose Copy',
        'icon' => 'fa-file-lines',
        'status' => count($items) > 0 ? 'ready' : 'setup_needed',
        'badge' => count($items) > 0 ? 'Ready' : 'Setup needed',
        'metric' => (string) count($items),
        'metric_label' => 'items',
        'tooltip' => 'Quality review starts by choosing the draft content that may be used in a campaign.',
        'action_label' => 'Pick item',
        'action_href' => '#quality-tools',
    ],
    [
        'title' => 'Check Fit',
        'icon' => 'fa-user-check',
        'status' => $averageScore > 0 ? 'in_use' : 'setup_needed',
        'badge' => $averageScore > 0 ? 'In use' : 'Setup needed',
        'metric' => $averageScore . '/100',
        'metric_label' => 'score',
        'tooltip' => 'Fit checks help founders see whether copy matches the audience, offer, and brand voice.',
        'action_label' => 'Run check',
        'action_href' => '#quality-tools',
    ],
    [
        'title' => 'Spot Risk',
        'icon' => 'fa-triangle-exclamation',
        'status' => $riskItems > 0 ? 'setup_needed' : 'ready',
        'badge' => $riskItems > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $riskItems,
        'metric_label' => 'risks',
        'tooltip' => 'Risk items show copy that may need a safer claim, clearer CTA, or review before launch.',
        'action_label' => 'Review queue',
        'action_href' => '#quality-review-queue',
    ],
    [
        'title' => 'Fix Draft',
        'icon' => 'fa-pen-to-square',
        'status' => $needsReview > 0 ? 'setup_needed' : 'ready',
        'badge' => $needsReview > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $needsReview,
        'metric_label' => 'waiting',
        'tooltip' => 'The page points to what needs attention; it does not rewrite the draft automatically.',
        'action_label' => 'Open content',
        'action_href' => 'marketing_content.php',
    ],
    [
        'title' => 'Approve',
        'icon' => 'fa-check-double',
        'status' => $needsReview === 0 && $riskItems === 0 ? 'ready' : 'setup_needed',
        'badge' => $needsReview === 0 && $riskItems === 0 ? 'Ready' : 'Setup needed',
        'metric' => $labelize($status),
        'metric_label' => 'status',
        'tooltip' => 'Approval remains a human decision after the advisory checks are reviewed.',
        'action_label' => 'Reviews',
        'action_href' => 'marketing_reviews.php',
    ],
];

$todayActions = [];
if ($riskItems > 0) {
    $todayActions[] = ['label' => 'Review risky copy', 'href' => '#quality-review-queue', 'meta' => $riskItems . ' risk items'];
}
if ($needsReview > 0) {
    $todayActions[] = ['label' => 'Clear review queue', 'href' => '#quality-review-queue', 'meta' => $needsReview . ' waiting'];
}
if (count($items) > 0) {
    $todayActions[] = ['label' => 'Run a quality check', 'href' => '#quality-tools', 'meta' => 'draft-safe'];
}
if ($recommendedActions !== []) {
    $todayActions[] = ['label' => 'Read next fix', 'href' => '#quality-actions', 'meta' => 'AI guidance'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open content studio', 'href' => 'marketing_content.php', 'meta' => 'next copy'];
}
$todayActions = array_slice($todayActions, 0, 5);

$qualitySignals = [
    ['label' => 'AI Used', 'value' => (string) $aiUsed, 'hint' => 'AI-assisted advisory checks'],
    ['label' => 'Fallback Used', 'value' => (string) $fallbackUsed, 'hint' => 'Deterministic fallback checks'],
    ['label' => 'Review Areas', 'value' => (string) count($dimensions), 'hint' => 'brand, persona, CTA, SEO, risk'],
    ['label' => 'Status', 'value' => $labelize($status), 'hint' => 'current quality posture'],
];

$pageTitle = 'Marketing Quality Checks - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-quality-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Content Quality Review</h1>
                <p>Check copy before it goes into the campaign.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_content.php"><i class="fas fa-layer-group"></i> Content</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'checked'): ?><div class="alert alert-success">Quality check saved. Draft content was not changed.</div><?php endif; ?>

        <section class="marketing-quality-shell" aria-label="Content quality review">
            <div class="marketing-founder-summary marketing-quality-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-quality-stage-grid" aria-label="Quality review path">
                <?php foreach ($qualityStages as $stage): ?>
                    <article class="marketing-quality-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-quality-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-quality-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-quality-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-quality-layout">
                <section class="marketing-quality-board" id="quality-review-queue">
                    <div class="premium-section-header">
                        <h2>Review Queue</h2>
                        <p>Drafts that may need a safer or clearer pass.</p>
                    </div>
                    <div class="marketing-quality-list">
                        <?php if (empty($contentNeedingReview)): ?>
                            <div class="empty-state"><p>No active content is waiting on quality review.</p></div>
                        <?php else: foreach ($contentNeedingReview as $reviewItem): ?>
                            <article class="marketing-quality-card" data-tooltip="<?php echo $h('Review before use: ' . implode(', ', (array) ($reviewItem['issues'] ?? []))); ?>" tabindex="0">
                                <div>
                                    <span class="marketing-stage-status"><?php echo $reviewItem['overall_score'] !== null ? (int) $reviewItem['overall_score'] . '/100' : 'Needs review'; ?></span>
                                    <h3><a href="<?php echo $h((string) ($reviewItem['href'] ?? '#')); ?>"><?php echo $h((string) ($reviewItem['title'] ?? 'Untitled content')); ?></a></h3>
                                    <div class="marketing-quality-meta">
                                        <span><?php echo $h($labelize((string) ($reviewItem['channel'] ?? 'channel'))); ?></span>
                                        <?php foreach (array_slice((array) ($reviewItem['issues'] ?? []), 0, 2) as $issue): ?><span><?php echo $h((string) $issue); ?></span><?php endforeach; ?>
                                    </div>
                                </div>
                                <a class="btn-premium-secondary" href="<?php echo $h((string) ($reviewItem['href'] ?? '#')); ?>">Open</a>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>

                <aside class="marketing-quality-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>One quality decision before launch.</p>
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

            <details class="marketing-quality-tools" id="quality-tools">
                <summary>More quality tools</summary>
                <div class="marketing-quality-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_content.php" data-tooltip="Open the content library."><i class="fas fa-layer-group"></i><span>Content Studio</span></a>
                        <a href="marketing_reviews.php" data-tooltip="Review approval workflow items."><i class="fas fa-clipboard-check"></i><span>Reviews</span></a>
                        <a href="marketing_assets.php" data-tooltip="Check media and asset readiness."><i class="fas fa-photo-film"></i><span>Media</span></a>
                        <a href="marketing_launch_checklists.php" data-tooltip="Move toward launch checks after quality is reviewed."><i class="fas fa-list-check"></i><span>Launch Checks</span></a>
                        <a href="marketing.php" data-tooltip="Return to the visual Marketing command path."><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-quality-detail-grid">
                        <article class="marketing-quality-detail-section">
                            <div class="premium-section-header"><h2>Review Dimensions</h2></div>
                            <div class="marketing-quality-dimension-grid">
                                <?php foreach ($dimensions as $dimension): ?>
                                    <article class="marketing-quality-dimension-card" data-tooltip="<?php echo $h((string) ($dimension['label'] ?? 'Review area')); ?>" tabindex="0">
                                        <span><?php echo $h((string) ($dimension['label'] ?? 'Review area')); ?></span>
                                        <strong><?php echo (int) ($dimension['average_score'] ?? 0); ?>/100</strong>
                                        <small><?php echo (int) ($dimension['issue_count'] ?? 0); ?> issue<?php echo (int) ($dimension['issue_count'] ?? 0) === 1 ? '' : 's'; ?> &middot; <?php echo $h($labelize((string) ($dimension['status'] ?? 'unknown'))); ?></small>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </article>

                        <article class="marketing-quality-detail-section" id="quality-actions">
                            <div class="premium-section-header"><h2>Next Review Actions</h2></div>
                            <div class="marketing-quality-action-list">
                                <?php if (empty($recommendedActions)): ?><span>No quality recommendations right now.</span><?php else: foreach ($recommendedActions as $action): ?><span><?php echo $h((string) $action); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-quality-detail-grid">
                        <article class="marketing-quality-detail-section">
                            <div class="premium-section-header"><h2>Recent Checks</h2></div>
                            <div class="marketing-quality-list compact">
                                <?php if (empty($checks)): ?>
                                    <div class="empty-state"><p>No marketing quality checks match this view.</p></div>
                                <?php else: foreach ($checks as $check): ?>
                                    <article class="marketing-quality-mini-card">
                                        <strong><a href="marketing_content_view.php?id=<?php echo (int) $check['content_item_id']; ?>"><?php echo $h((string) $check['content_title']); ?></a></strong>
                                        <span><?php echo $h($labelize((string) $check['content_channel'])); ?> &middot; Risk: <?php echo $h($labelize((string) ($check['approval_risk_json']['details']['risk_level'] ?? 'unknown'))); ?></span>
                                        <small><?php echo (int) $check['overall_score']; ?>/100</small>
                                    </article>
                                <?php endforeach; endif; ?>
                            </div>
                        </article>

                        <article class="marketing-quality-detail-section">
                            <div class="premium-section-header"><h2>Quality Signals</h2></div>
                            <div class="marketing-quality-signal-grid">
                                <?php foreach ($qualitySignals as $signal): ?>
                                    <article class="marketing-quality-signal-card" data-tooltip="<?php echo $h($signal['hint']); ?>" tabindex="0">
                                        <span><?php echo $h($signal['label']); ?></span>
                                        <strong><?php echo $h($signal['value']); ?></strong>
                                        <small><?php echo $h($signal['hint']); ?></small>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <div class="marketing-quality-guardrail">
                                <strong>Manual-first guardrail:</strong>
                                Advisory checks do not publish externally, send messages, or overwrite draft copy.
                            </div>
                        </article>
                    </section>

                    <section class="marketing-quality-form-card">
                        <div class="premium-section-header"><h2>Run Check</h2></div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <div class="form-group">
                                    <label>Content Item</label>
                                    <select name="content_item_id" required>
                                        <option value="">Select content</option>
                                        <?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>" <?php echo (int) $item['id'] === $contentId ? 'selected' : ''; ?>><?php echo $h((string) $item['title'] . ' - ' . $labelize((string) $item['status'])); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="btn-premium-primary" type="submit">Run Quality Check</button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state"><p>Read-only access.</p></div>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
