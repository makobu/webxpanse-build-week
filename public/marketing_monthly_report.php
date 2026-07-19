<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
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
$summary = $marketing->getMarketingAnalyticsSummary('monthly', (string) ($_GET['date'] ?? ''));
$executionEvidence = (array) ($summary['execution_evidence'] ?? []);
$snapshots = $marketing->listMarketingAnalyticsSnapshots('monthly', 5, 0);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$money = static fn($value): string => '$' . number_format((float) $value, 2);
$periodStart = (string) ($summary['period']['start'] ?? '');
$periodEnd = (string) ($summary['period']['end'] ?? '');
$contentCreated = (int) ($summary['content_velocity']['total_created'] ?? 0);
$overdueReviews = (int) ($summary['review_bottlenecks']['overdue'] ?? 0);
$utmLinks = (int) ($summary['utm_usage']['total'] ?? 0);
$briefCount = (int) ($summary['campaign_performance']['brief_count'] ?? 0);
$formSubmissions = (int) ($summary['form_submissions']['total'] ?? 0);
$attributedRevenue = (float) ($summary['attributed_revenue']['total'] ?? 0);
$roiPercent = $summary['campaign_roi']['roi_percent'] ?? null;
$influencedContent = (int) ($summary['content_influence']['influenced_content'] ?? 0);
$landingSubmissions = (int) ($summary['landing_page_funnel']['submissions'] ?? 0);
$snapshotCount = (int) ($executionEvidence['counts']['current'] ?? 0);
$urgentActions = (int) ($executionEvidence['counts']['urgent_actions'] ?? 0);
$evidenceStatus = (string) ($executionEvidence['status'] ?? 'needs_evidence');
$leadSignals = $formSubmissions + $landingSubmissions;
$summaryCards = [
    ['label' => 'Produced', 'value' => $contentCreated, 'icon' => 'fa-layer-group', 'tooltip' => 'Marketing assets created during this monthly period.'],
    ['label' => 'Campaigns', 'value' => $briefCount, 'icon' => 'fa-bullseye', 'tooltip' => 'Campaign briefs available for monthly performance review.'],
    ['label' => 'Lead Signals', 'value' => $leadSignals, 'icon' => 'fa-user-plus', 'tooltip' => 'Form and landing-page activity captured during the month.'],
    ['label' => 'Revenue', 'value' => $money($attributedRevenue), 'icon' => 'fa-chart-line', 'tooltip' => 'Revenue credited to marketing activity in this monthly view.'],
];
$monthStages = [
    [
        'title' => 'Plan',
        'icon' => 'fa-bullseye',
        'status' => $briefCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $briefCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $briefCount,
        'metric_label' => 'briefs',
        'tooltip' => 'A monthly read starts with which campaigns were planned and active.',
        'action_label' => 'Open plans',
        'action_href' => 'marketing_briefs.php',
    ],
    [
        'title' => 'Produce',
        'icon' => 'fa-layer-group',
        'status' => $contentCreated > 0 ? 'in_use' : 'setup_needed',
        'badge' => $contentCreated > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $contentCreated,
        'metric_label' => 'items',
        'tooltip' => 'Production volume shows whether the month had enough campaign material to learn from.',
        'action_label' => 'Open content',
        'action_href' => 'marketing_content.php',
    ],
    [
        'title' => 'Unblock',
        'icon' => 'fa-triangle-exclamation',
        'status' => $overdueReviews > 0 ? 'setup_needed' : 'ready',
        'badge' => $overdueReviews > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $overdueReviews,
        'metric_label' => 'overdue',
        'tooltip' => 'Overdue reviews reveal where monthly momentum stalled.',
        'action_label' => 'Open reviews',
        'action_href' => 'marketing_reviews.php',
    ],
    [
        'title' => 'Measure',
        'icon' => 'fa-link',
        'status' => $utmLinks > 0 ? 'ready' : 'setup_needed',
        'badge' => $utmLinks > 0 ? 'Ready' : 'Setup needed',
        'metric' => (string) $utmLinks,
        'metric_label' => 'links',
        'tooltip' => 'Tracking links help connect monthly work to outcomes.',
        'action_label' => 'Open links',
        'action_href' => 'marketing_utm_links.php',
    ],
    [
        'title' => 'Decide',
        'icon' => 'fa-compass',
        'status' => $snapshotCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $snapshotCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $snapshotCount,
        'metric_label' => 'snapshots',
        'tooltip' => 'Monthly decisions need saved evidence, not just activity totals.',
        'action_label' => 'Performance',
        'action_href' => 'marketing_performance.php',
    ],
];
$todayActions = [];
if ($urgentActions > 0) {
    $todayActions[] = ['label' => 'Resolve urgent actions', 'href' => '#monthly-evidence', 'meta' => $urgentActions . ' urgent'];
}
if ($overdueReviews > 0) {
    $todayActions[] = ['label' => 'Unblock reviews', 'href' => 'marketing_reviews.php', 'meta' => $overdueReviews . ' overdue'];
}
if ($briefCount === 0) {
    $todayActions[] = ['label' => 'Create campaign plan', 'href' => 'marketing_brief_edit.php', 'meta' => 'strategy gap'];
}
if ($utmLinks === 0) {
    $todayActions[] = ['label' => 'Add tracking links', 'href' => 'marketing_utm_links.php', 'meta' => 'measurement gap'];
}
if ($snapshotCount === 0) {
    $todayActions[] = ['label' => 'Save monthly evidence', 'href' => 'marketing_performance.php', 'meta' => 'learning gap'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open weekly view', 'href' => 'marketing_weekly_report.php', 'meta' => 'zoom in'];
}
$todayActions = array_slice($todayActions, 0, 5);
$monthSignals = [
    ['label' => 'Campaign ROI', 'value' => $roiPercent === null ? 'N/A' : (string) $roiPercent . '%', 'hint' => 'monthly ROI'],
    ['label' => 'Influenced Content', 'value' => (string) $influencedContent, 'hint' => 'content tied to outcomes'],
    ['label' => 'Lead Signals', 'value' => (string) $leadSignals, 'hint' => 'forms and landing activity'],
    ['label' => 'Evidence Status', 'value' => $labelize($evidenceStatus), 'hint' => 'monthly proof'],
];
$pageTitle = 'Monthly Marketing Report - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-monthly-report-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Monthly Marketing Report</h1>
                <p>Review the month and choose the next bet.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_weekly_report.php"><i class="fas fa-calendar-week"></i> Weekly</a>
            </div>
        </div>

        <section class="marketing-monthly-report-shell" aria-label="Monthly marketing report">
            <div class="marketing-monthly-period" data-tooltip="Report period: <?php echo $h($periodStart); ?> to <?php echo $h($periodEnd); ?>" tabindex="0">
                <i class="fas fa-calendar-days"></i>
                <span><?php echo $h($periodStart); ?> to <?php echo $h($periodEnd); ?></span>
            </div>

            <div class="marketing-founder-summary marketing-monthly-report-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-monthly-report-stage-grid" aria-label="Monthly review path">
                <?php foreach ($monthStages as $stage): ?>
                    <article class="marketing-monthly-report-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-monthly-report-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-monthly-report-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-monthly-report-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-monthly-report-layout">
                <section class="marketing-monthly-report-board" id="monthly-signals">
                    <div class="premium-section-header">
                        <h2>This Month</h2>
                        <p>Strategic signals to shape the next campaign bet.</p>
                    </div>
                    <div class="marketing-monthly-signal-grid">
                        <?php foreach ($monthSignals as $signal): ?>
                            <article class="marketing-monthly-signal-card" data-tooltip="<?php echo $h($signal['hint']); ?>" tabindex="0">
                                <span><?php echo $h($signal['label']); ?></span>
                                <strong><?php echo $h($signal['value']); ?></strong>
                                <small><?php echo $h($signal['hint']); ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="marketing-monthly-report-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>Turn the month into one next bet.</p>
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

            <details class="marketing-monthly-report-tools" id="monthly-evidence">
                <summary>More monthly tools</summary>
                <div class="marketing-monthly-report-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_weekly_report.php"><i class="fas fa-calendar-week"></i><span>Weekly Report</span></a>
                        <a href="marketing_performance.php"><i class="fas fa-chart-line"></i><span>Performance</span></a>
                        <a href="marketing_handoffs.php"><i class="fas fa-handshake"></i><span>Lead Handoffs</span></a>
                        <a href="marketing.php"><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-monthly-detail-grid">
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>Content By Status</h2></div>
                            <div class="marketing-monthly-list">
                                <?php foreach ((array) ($summary['content_velocity']['by_status'] ?? []) as $row): ?><span><?php echo $h($labelize((string) ($row['status'] ?? 'unknown'))); ?>: <?php echo (int) ($row['count'] ?? 0); ?></span><?php endforeach; ?>
                                <?php if (empty($summary['content_velocity']['by_status'])): ?><span>No content status data yet.</span><?php endif; ?>
                            </div>
                        </article>
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>UTM Sources</h2></div>
                            <div class="marketing-monthly-list">
                                <?php foreach ((array) ($summary['utm_usage']['by_source'] ?? []) as $row): ?><span><?php echo $h((string) ($row['utm_source'] ?? 'source')); ?>: <?php echo (int) ($row['count'] ?? 0); ?></span><?php endforeach; ?>
                                <?php if (empty($summary['utm_usage']['by_source'])): ?><span>No UTM source data yet.</span><?php endif; ?>
                            </div>
                        </article>
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>Recent Snapshots</h2></div>
                            <div class="marketing-monthly-list">
                                <?php if (empty($snapshots)): ?><span>No saved monthly snapshots yet.</span><?php else: foreach ($snapshots as $snapshot): ?><span><?php echo $h((string) ($snapshot['period_start'] ?? '')); ?> to <?php echo $h((string) ($snapshot['period_end'] ?? '')); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>Campaign ROI</h2></div>
                            <div class="marketing-monthly-list">
                                <?php if (empty($summary['campaign_roi']['campaigns'])): ?><span>No campaign ROI data yet.</span><?php else: foreach ((array) $summary['campaign_roi']['campaigns'] as $row): ?><span><?php echo $h((string) ($row['campaign_name'] ?? 'Campaign')); ?>: <?php echo $h($money($row['attributed_revenue'] ?? 0)); ?> / <?php echo ($row['roi_percent'] ?? null) === null ? 'N/A' : $h((string) $row['roi_percent'] . '%'); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>Top Content Influence</h2></div>
                            <div class="marketing-monthly-list">
                                <?php if (empty($summary['content_influence']['items'])): ?><span>No content influence data yet.</span><?php else: foreach ((array) $summary['content_influence']['items'] as $row): ?><span><?php echo $h((string) ($row['title'] ?? 'Content')); ?>: <?php echo (int) ($row['distribution_count'] ?? 0); ?> posts, <?php echo (int) ($row['utm_link_count'] ?? 0); ?> links</span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-monthly-detail-section">
                            <div class="premium-section-header"><h2>Revenue Channels</h2></div>
                            <div class="marketing-monthly-list">
                                <?php if (empty($summary['revenue_attribution']['by_channel'])): ?><span>No attributed channel revenue yet.</span><?php else: foreach ((array) $summary['revenue_attribution']['by_channel'] as $row): ?><span><?php echo $h($labelize((string) ($row['channel'] ?? 'unknown'))); ?>: <?php echo $h($money($row['credited_revenue'] ?? 0)); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-monthly-detail-section">
                        <div class="premium-section-header"><h2>Execution Evidence</h2><p>Saved action snapshots and recommended fixes for this period.</p></div>
                        <div class="marketing-monthly-list">
                            <?php if (empty($executionEvidence['latest_snapshots'])): ?>
                                <span>No saved action snapshots in this report period yet.</span>
                            <?php else: foreach ((array) $executionEvidence['latest_snapshots'] as $snapshot): ?>
                                <span><?php echo $h((string) ($snapshot['campaign_name'] ?? 'Campaign')); ?>: <?php echo $h($labelize((string) ($snapshot['action_center_status'] ?? 'needs_attention'))); ?>, readiness <?php echo (int) ($snapshot['readiness_score'] ?? 0); ?>, <?php echo (int) ($snapshot['urgent_actions'] ?? 0); ?> urgent action(s)</span>
                            <?php endforeach; endif; ?>
                            <?php foreach ((array) ($executionEvidence['recommended_actions'] ?? []) as $action): ?><span><?php echo $h((string) $action); ?></span><?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
