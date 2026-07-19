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
$onboardingStatus = $marketing->getMarketingOnboardingStatus();
$summary = $marketing->getCachedDashboardSummary($onboardingStatus, 60);
$systemMap = (array) ($summary['system_map'] ?? $marketing->getMarketingSystemMap($summary, $onboardingStatus));
$stages = (array) ($systemMap['stages'] ?? []);
$lowestStage = (array) ($systemMap['lowest_stage'] ?? []);
$nextDecision = (array) ($systemMap['next_decision'] ?? []);
$stageCounts = (array) ($systemMap['counts'] ?? []);
$guardrails = (array) ($systemMap['guardrails'] ?? []);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$statusClass = static fn(string $status): string => match ($status) {
    'ready' => 'badge-success',
    'attention', 'needs_attention' => 'badge-warning',
    default => 'badge-danger',
};
$stageClass = static fn(string $status): string => str_replace('_', '-', strtolower($status));
$stageIcons = [
    'setup' => 'fa-rocket',
    'context' => 'fa-brain',
    'strategy' => 'fa-route',
    'content' => 'fa-pen-nib',
    'media' => 'fa-images',
    'distribution' => 'fa-share-nodes',
    'revenue' => 'fa-chart-line',
    'operate' => 'fa-sliders',
];
$averageScore = (int) ($systemMap['average_score'] ?? 0);
$systemStatus = (string) ($systemMap['status'] ?? 'ready');
$weakestLabel = (string) ($lowestStage['label'] ?? 'No weak stage');
$weakestScore = (int) ($lowestStage['score'] ?? 0);
$readyCount = (int) ($stageCounts['ready'] ?? 0);
$attentionCount = (int) ($stageCounts['attention'] ?? 0);
$missingCount = (int) ($stageCounts['missing'] ?? 0);
$totalStages = max(1, (int) ($stageCounts['total'] ?? count($stages)));

$todayActions = [];
if ($lowestStage !== []) {
    $todayActions[] = [
        'label' => 'Open weakest area',
        'href' => (string) ($lowestStage['href'] ?? 'marketing.php'),
        'source' => (string) ($lowestStage['label'] ?? 'System Map'),
        'reason' => 'Start where the system has the lowest readiness score.',
    ];
}
if ($nextDecision !== []) {
    $todayActions[] = [
        'label' => (string) ($nextDecision['title'] ?? 'Review next decision'),
        'href' => 'marketing_decisions.php',
        'source' => 'Decision Center',
        'reason' => 'Review the highest-value decision behind this map.',
    ];
}
$todayActions[] = [
    'label' => 'Open Action Router',
    'href' => 'marketing_action_router.php',
    'source' => 'Route Board',
    'reason' => 'Turn the system signal into the next manual action.',
];
$todayActions[] = [
    'label' => 'Return to Marketing',
    'href' => 'marketing.php',
    'source' => 'Command Center',
    'reason' => 'Go back to the founder-guided operating map.',
];
$todayActions = array_slice($todayActions, 0, 5);
$advancedLinks = [
    'marketing.php' => 'Command Center',
    'marketing_action_router.php' => 'Action Router',
    'marketing_relationships.php' => 'Relationship Graph',
    'marketing_execution.php' => 'Execution Center',
    'marketing_decisions.php' => 'Decision Center',
    'marketing_admin.php' => 'Admin Diagnostics',
];

$pageTitle = 'Marketing System Map - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-system-map-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Marketing System Map</h1>
                <p>See system health and open the weakest area first.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-primary" href="#system-map-board">View Map</a>
            </div>
        </div>

        <div class="marketing-system-map-shell">
            <div class="marketing-system-map-summary marketing-founder-summary">
                <div class="marketing-summary-tile" data-tooltip="Average readiness across setup, context, strategy, content, media, distribution, revenue, and operations." tabindex="0">
                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                    <div><span>System</span><strong><?php echo $averageScore; ?>%</strong></div>
                </div>
                <div class="marketing-summary-tile" data-tooltip="The area to open first if you want the fastest system improvement." tabindex="0">
                    <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                    <div><span>Weakest</span><strong><?php echo htmlspecialchars($weakestLabel); ?></strong></div>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Stages with enough evidence to use confidently." tabindex="0">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <div><span>Ready</span><strong><?php echo $readyCount; ?>/<?php echo $totalStages; ?></strong></div>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Stages that need attention or foundation work." tabindex="0">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <div><span>Gaps</span><strong><?php echo $attentionCount + $missingCount; ?></strong></div>
                </div>
            </div>

            <div class="marketing-system-map-layout">
                <div class="content-card marketing-system-map-board" id="system-map-board">
                    <div class="premium-section-header">
                        <div>
                            <h2>System Health Map</h2>
                            <p><?php echo htmlspecialchars($labelize($systemStatus)); ?>. Open one area and improve its evidence.</p>
                        </div>
                        <span class="badge <?php echo htmlspecialchars($statusClass($systemStatus)); ?>"><?php echo $averageScore; ?>%</span>
                    </div>

                    <?php if ($stages === []): ?>
                        <div class="empty-state"><p>The Marketing map could not build from the current workspace summary.</p><a href="marketing.php">Return to Marketing</a></div>
                    <?php else: ?>
                        <div class="marketing-system-map-stage-grid">
                            <?php foreach ($stages as $stage): ?>
                                <?php
                                    $score = (int) ($stage['score'] ?? 0);
                                    $status = (string) ($stage['status'] ?? 'missing');
                                    $key = (string) ($stage['key'] ?? '');
                                    $tooltip = (string) ($stage['description'] ?? 'Open this system area.');
                                ?>
                                <a class="marketing-system-map-stage-card <?php echo htmlspecialchars($stageClass($status)); ?>" href="<?php echo htmlspecialchars((string) ($stage['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>" tabindex="0">
                                    <div class="marketing-stage-visual">
                                        <i class="fa-solid <?php echo htmlspecialchars((string) ($stageIcons[$key] ?? 'fa-circle-nodes')); ?>" aria-hidden="true"></i>
                                        <span class="badge <?php echo htmlspecialchars($statusClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                                    </div>
                                    <div class="marketing-system-map-stage-body">
                                        <h2><?php echo htmlspecialchars((string) ($stage['label'] ?? 'Stage')); ?></h2>
                                        <div class="marketing-system-map-stage-metric"><strong><?php echo $score; ?>%</strong><span>ready</span></div>
                                        <progress class="marketing-system-map-progress" value="<?php echo $score; ?>" max="100"><?php echo $score; ?>%</progress>
                                        <span class="btn-premium-secondary marketing-system-map-stage-action">Open Area</span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="content-card marketing-system-map-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Use the map procedurally.</p>
                        </div>
                        <span class="badge badge-info"><?php echo count($todayActions); ?>/5</span>
                    </div>
                    <div class="marketing-system-map-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-system-map-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>" tabindex="0">
                                <strong><?php echo htmlspecialchars((string) $action['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $action['source']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-system-map-tools">
                <summary>More system tools</summary>
                <div class="marketing-system-map-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($advancedLinks as $href => $label): ?>
                            <a href="<?php echo htmlspecialchars($href); ?>" data-tooltip="Open an expert Marketing system surface." tabindex="0">
                                <strong><?php echo htmlspecialchars($label); ?></strong>
                                <span>Advanced system view</span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <section class="marketing-system-map-detail-section">
                        <div class="premium-section-header">
                            <div>
                                <h2>System Evidence</h2>
                                <p><?php echo htmlspecialchars((string) ($systemMap['recommended_product_decision'] ?? 'Use the map as the orientation layer for Marketing.')); ?></p>
                            </div>
                            <span class="badge badge-info">Weakest <?php echo $weakestScore; ?>%</span>
                        </div>
                        <div class="marketing-system-map-detail-grid">
                            <?php foreach ($stages as $stage): ?>
                                <?php
                                    $score = (int) ($stage['score'] ?? 0);
                                    $status = (string) ($stage['status'] ?? 'missing');
                                ?>
                                <article class="marketing-system-map-detail-card">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string) ($stage['label'] ?? 'Stage')); ?></strong>
                                        <span class="badge <?php echo htmlspecialchars($statusClass($status)); ?>"><?php echo $score; ?>%</span>
                                    </div>
                                    <div class="marketing-system-map-mini-grid">
                                        <?php foreach ((array) ($stage['metrics'] ?? []) as $metric): ?>
                                            <div class="marketing-system-map-mini-card">
                                                <strong><?php echo htmlspecialchars((string) ($metric['value'] ?? '')); ?></strong>
                                                <span><?php echo htmlspecialchars((string) ($metric['label'] ?? 'Metric')); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="marketing-system-map-chip-row" aria-label="Tools">
                                        <?php foreach ((array) ($stage['tools'] ?? []) as $tool): ?><span><?php echo htmlspecialchars((string) $tool); ?></span><?php endforeach; ?>
                                    </div>
                                    <div class="marketing-system-map-chip-row next" aria-label="Next actions">
                                        <?php foreach ((array) ($stage['next_actions'] ?? []) as $action): ?><span><?php echo htmlspecialchars((string) $action); ?></span><?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="marketing-system-map-detail-section">
                        <div class="premium-section-header"><h2>Guardrails</h2></div>
                        <div class="marketing-system-map-guardrail-grid">
                            <?php foreach ($guardrails as $key => $value): ?>
                                <div class="marketing-system-map-mini-card">
                                    <strong><?php echo is_bool($value) ? ($value ? 'On' : 'Off') : htmlspecialchars((string) $value); ?></strong>
                                    <span><?php echo htmlspecialchars($labelize((string) $key)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </details>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
