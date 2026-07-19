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
$summary = $marketing->getMarketingAnalyticsSummary('weekly', (string) ($_GET['date'] ?? ''));
$executionEvidence = (array) ($summary['execution_evidence'] ?? []);
$snapshots = $marketing->listMarketingAnalyticsSnapshots('weekly', 5, 0);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$money = static fn($value): string => '$' . number_format((float) $value, 2);
$periodStart = (string) ($summary['period']['start'] ?? '');
$periodEnd = (string) ($summary['period']['end'] ?? '');
$contentCreated = (int) ($summary['content_velocity']['total_created'] ?? 0);
$pendingReviews = (int) ($summary['review_bottlenecks']['pending'] ?? 0);
$utmLinks = (int) ($summary['utm_usage']['total'] ?? 0);
$landingPages = (int) ($summary['landing_page_conversion']['pages'] ?? 0);
$formSubmissions = (int) ($summary['form_submissions']['total'] ?? 0);
$attributedRevenue = (float) ($summary['attributed_revenue']['total'] ?? 0);
$roiPercent = $summary['campaign_roi']['roi_percent'] ?? null;
$influencedContent = (int) ($summary['content_influence']['influenced_content'] ?? 0);
$landingSubmissions = (int) ($summary['landing_page_funnel']['submissions'] ?? 0);
$snapshotCount = (int) ($executionEvidence['counts']['current'] ?? 0);
$urgentActions = (int) ($executionEvidence['counts']['urgent_actions'] ?? 0);
$evidenceStatus = (string) ($executionEvidence['status'] ?? 'needs_evidence');
$summaryCards = [
    ['label' => 'Created', 'value' => $contentCreated, 'icon' => 'fa-pen-nib', 'tooltip' => 'New marketing content created during this weekly period.'],
    ['label' => 'Reviews', 'value' => $pendingReviews, 'icon' => 'fa-clipboard-check', 'tooltip' => 'Pending reviews that may slow down the next campaign step.'],
    ['label' => 'Leads', 'value' => $formSubmissions + $landingSubmissions, 'icon' => 'fa-user-plus', 'tooltip' => 'Form and landing-page signals captured during the report period.'],
    ['label' => 'Revenue', 'value' => $money($attributedRevenue), 'icon' => 'fa-chart-line', 'tooltip' => 'Revenue credited to marketing activity in this weekly view.'],
];
$reviewStages = [
    [
        'title' => 'Create',
        'icon' => 'fa-pen-ruler',
        'status' => $contentCreated > 0 ? 'in_use' : 'setup_needed',
        'badge' => $contentCreated > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $contentCreated,
        'metric_label' => 'items',
        'tooltip' => 'Start the weekly review by seeing whether useful campaign assets were created.',
        'action_label' => 'Open content',
        'action_href' => 'marketing_content.php',
    ],
    [
        'title' => 'Approve',
        'icon' => 'fa-check-double',
        'status' => $pendingReviews > 0 ? 'setup_needed' : 'ready',
        'badge' => $pendingReviews > 0 ? 'Setup needed' : 'Ready',
        'metric' => (string) $pendingReviews,
        'metric_label' => 'pending',
        'tooltip' => 'Reviews show where launch progress might be waiting for a decision.',
        'action_label' => 'Open reviews',
        'action_href' => 'marketing_reviews.php',
    ],
    [
        'title' => 'Track',
        'icon' => 'fa-link',
        'status' => $utmLinks > 0 ? 'ready' : 'setup_needed',
        'badge' => $utmLinks > 0 ? 'Ready' : 'Setup needed',
        'metric' => (string) $utmLinks,
        'metric_label' => 'links',
        'tooltip' => 'UTM links help connect weekly actions to traffic and later revenue signals.',
        'action_label' => 'Open links',
        'action_href' => 'marketing_utm_links.php',
    ],
    [
        'title' => 'Capture',
        'icon' => 'fa-window-maximize',
        'status' => $formSubmissions + $landingSubmissions > 0 ? 'in_use' : 'ready',
        'badge' => $formSubmissions + $landingSubmissions > 0 ? 'In use' : 'Ready',
        'metric' => (string) ($formSubmissions + $landingSubmissions),
        'metric_label' => 'signals',
        'tooltip' => 'Landing and form activity shows whether the campaign produced interested leads.',
        'action_label' => 'Open pages',
        'action_href' => 'marketing_landing_pages.php',
    ],
    [
        'title' => 'Learn',
        'icon' => 'fa-lightbulb',
        'status' => $snapshotCount > 0 ? 'in_use' : 'setup_needed',
        'badge' => $snapshotCount > 0 ? 'In use' : 'Setup needed',
        'metric' => (string) $snapshotCount,
        'metric_label' => 'snapshots',
        'tooltip' => 'Saved action snapshots turn weekly activity into evidence for the next campaign decision.',
        'action_label' => 'Performance',
        'action_href' => 'marketing_performance.php',
    ],
];
$todayActions = [];
if ($urgentActions > 0) {
    $todayActions[] = ['label' => 'Handle urgent actions', 'href' => '#execution-evidence', 'meta' => $urgentActions . ' urgent'];
}
if ($pendingReviews > 0) {
    $todayActions[] = ['label' => 'Clear pending reviews', 'href' => 'marketing_reviews.php', 'meta' => $pendingReviews . ' waiting'];
}
if ($utmLinks === 0) {
    $todayActions[] = ['label' => 'Create tracking links', 'href' => 'marketing_utm_links.php', 'meta' => 'setup needed'];
}
if ($formSubmissions + $landingSubmissions === 0) {
    $todayActions[] = ['label' => 'Check landing capture', 'href' => 'marketing_landing_pages.php', 'meta' => 'no leads yet'];
}
if ($snapshotCount === 0) {
    $todayActions[] = ['label' => 'Save action evidence', 'href' => 'marketing_performance.php', 'meta' => 'evidence gap'];
}
if ($todayActions === []) {
    $todayActions[] = ['label' => 'Open monthly view', 'href' => 'marketing_monthly_report.php', 'meta' => 'zoom out'];
}
$todayActions = array_slice($todayActions, 0, 5);
$reportSignals = [
    ['label' => 'Landing Pages', 'value' => (string) $landingPages, 'hint' => 'active pages'],
    ['label' => 'Campaign ROI', 'value' => $roiPercent === null ? 'N/A' : (string) $roiPercent . '%', 'hint' => 'current ROI'],
    ['label' => 'Influenced Content', 'value' => (string) $influencedContent, 'hint' => 'content tied to outcomes'],
    ['label' => 'Evidence Status', 'value' => $labelize($evidenceStatus), 'hint' => 'weekly proof'],
];
$pageTitle = 'Weekly Marketing Report - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-weekly-report-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Weekly Marketing Report</h1>
                <p>Review the week and choose one lesson.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_monthly_report.php"><i class="fas fa-calendar-days"></i> Monthly</a>
            </div>
        </div>

        <section class="marketing-weekly-report-shell" aria-label="Weekly marketing report">
            <div class="marketing-weekly-period" data-tooltip="Report period: <?php echo $h($periodStart); ?> to <?php echo $h($periodEnd); ?>" tabindex="0">
                <i class="fas fa-calendar-week"></i>
                <span><?php echo $h($periodStart); ?> to <?php echo $h($periodEnd); ?></span>
            </div>

            <div class="marketing-founder-summary marketing-weekly-report-summary">
                <?php foreach ($summaryCards as $card): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($card['tooltip']); ?>">
                        <i class="fas <?php echo $h($card['icon']); ?>"></i>
                        <div><span><?php echo $h($card['label']); ?></span><strong><?php echo $h($card['value']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-weekly-report-stage-grid" aria-label="Weekly review path">
                <?php foreach ($reviewStages as $stage): ?>
                    <article class="marketing-weekly-report-stage-card <?php echo $h($stage['status']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-weekly-report-stage-body">
                            <div>
                                <span class="marketing-stage-status"><?php echo $h($stage['badge']); ?></span>
                                <h2><?php echo $h($stage['title']); ?></h2>
                            </div>
                            <div class="marketing-weekly-report-stage-metric">
                                <strong><?php echo $h($stage['metric']); ?></strong>
                                <span><?php echo $h($stage['metric_label']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-weekly-report-stage-action" href="<?php echo $h($stage['action_href']); ?>"><?php echo $h($stage['action_label']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-weekly-report-layout">
                <section class="marketing-weekly-report-board" id="weekly-signals">
                    <div class="premium-section-header">
                        <h2>This Week</h2>
                        <p>Signals worth noticing before the next campaign move.</p>
                    </div>
                    <div class="marketing-weekly-signal-grid">
                        <?php foreach ($reportSignals as $signal): ?>
                            <article class="marketing-weekly-signal-card" data-tooltip="<?php echo $h($signal['hint']); ?>" tabindex="0">
                                <span><?php echo $h($signal['label']); ?></span>
                                <strong><?php echo $h($signal['value']); ?></strong>
                                <small><?php echo $h($signal['hint']); ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="marketing-weekly-report-today">
                    <div class="premium-section-header">
                        <h2>Today</h2>
                        <p>Turn the report into one next move.</p>
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

            <details class="marketing-weekly-report-tools" id="execution-evidence">
                <summary>More weekly tools</summary>
                <div class="marketing-weekly-report-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_monthly_report.php"><i class="fas fa-calendar-days"></i><span>Monthly Report</span></a>
                        <a href="marketing_performance.php"><i class="fas fa-chart-line"></i><span>Performance</span></a>
                        <a href="marketing_handoffs.php"><i class="fas fa-handshake"></i><span>Lead Handoffs</span></a>
                        <a href="marketing.php"><i class="fas fa-chart-pie"></i><span>Command Center</span></a>
                    </div>

                    <section class="marketing-weekly-detail-grid">
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>Content By Channel</h2></div>
                            <div class="marketing-weekly-list">
                                <?php foreach ((array) ($summary['content_velocity']['by_channel'] ?? []) as $row): ?><span><?php echo $h($labelize((string) ($row['channel'] ?? 'unknown'))); ?>: <?php echo (int) ($row['count'] ?? 0); ?></span><?php endforeach; ?>
                                <?php if (empty($summary['content_velocity']['by_channel'])): ?><span>No channel content yet.</span><?php endif; ?>
                            </div>
                        </article>
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>Landing Goals</h2></div>
                            <div class="marketing-weekly-list">
                                <?php foreach ((array) ($summary['landing_page_conversion']['by_goal'] ?? []) as $row): ?><span><?php echo $h($labelize((string) ($row['conversion_goal'] ?? 'unknown'))); ?>: <?php echo (int) ($row['count'] ?? 0); ?></span><?php endforeach; ?>
                                <?php if (empty($summary['landing_page_conversion']['by_goal'])): ?><span>No landing goal data yet.</span><?php endif; ?>
                            </div>
                        </article>
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>Recent Snapshots</h2></div>
                            <div class="marketing-weekly-list">
                                <?php if (empty($snapshots)): ?><span>No saved weekly snapshots yet.</span><?php else: foreach ($snapshots as $snapshot): ?><span><?php echo $h((string) ($snapshot['period_start'] ?? '')); ?> to <?php echo $h((string) ($snapshot['period_end'] ?? '')); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>Campaign ROI</h2></div>
                            <div class="marketing-weekly-list">
                                <?php if (empty($summary['campaign_roi']['campaigns'])): ?><span>No campaign ROI data yet.</span><?php else: foreach ((array) $summary['campaign_roi']['campaigns'] as $row): ?><span><?php echo $h((string) ($row['campaign_name'] ?? 'Campaign')); ?>: <?php echo $h($money($row['attributed_revenue'] ?? 0)); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>UTM Source/Medium</h2></div>
                            <div class="marketing-weekly-list">
                                <?php if (empty($summary['utm_performance']['by_source_medium'])): ?><span>No UTM performance data yet.</span><?php else: foreach ((array) $summary['utm_performance']['by_source_medium'] as $row): ?><span><?php echo $h((string) ($row['utm_source'] ?? 'source')); ?> / <?php echo $h((string) ($row['utm_medium'] ?? 'medium')); ?>: <?php echo (int) ($row['count'] ?? 0); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                        <article class="marketing-weekly-detail-section">
                            <div class="premium-section-header"><h2>Revenue Channels</h2></div>
                            <div class="marketing-weekly-list">
                                <?php if (empty($summary['revenue_attribution']['by_channel'])): ?><span>No attributed channel revenue yet.</span><?php else: foreach ((array) $summary['revenue_attribution']['by_channel'] as $row): ?><span><?php echo $h($labelize((string) ($row['channel'] ?? 'unknown'))); ?>: <?php echo $h($money($row['credited_revenue'] ?? 0)); ?></span><?php endforeach; endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="marketing-weekly-detail-section">
                        <div class="premium-section-header"><h2>Execution Evidence</h2><p>Saved action snapshots and recommended fixes for this period.</p></div>
                        <div class="marketing-weekly-list">
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
