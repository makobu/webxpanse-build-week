<?php
/**
 * Marketing campaign roadmap.
 */

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
$canWriteMarketing = Authorization::can('marketing.write', $user);
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-t', strtotime('+60 days')));
$roadmap = $marketing->listCampaignRoadmap($dateFrom, $dateTo);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$cleanStatus = static fn(string $value): string => preg_replace('/[^a-z0-9_-]/i', '', $value) ?: 'setup_needed';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'active', 'ready', 'completed', 'launched' => 'badge-success',
        'blocked', 'needs_foundation' => 'badge-danger',
        'draft', 'planned', 'warning', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$dateLabel = static function (?string $date, string $fallback): string {
    $timestamp = $date !== null && $date !== '' ? strtotime($date) : false;
    return $timestamp !== false ? date('M j', $timestamp) : $fallback;
};
$briefScore = static fn(array $brief): int => max(0, min(100, (int) ($brief['readiness_score'] ?? $brief['context_score'] ?? 0)));
$briefMissing = static fn(array $brief): array => array_values(array_filter((array) ($brief['missing_strategy_json'] ?? []), static fn($value): bool => trim((string) $value) !== ''));
$roadmapTotal = count($roadmap);
$readyTotal = count(array_filter($roadmap, static fn(array $brief): bool => $briefScore($brief) >= 80));
$activeTotal = count(array_filter($roadmap, static fn(array $brief): bool => (string) ($brief['status'] ?? '') === 'active'));
$missingTotal = array_sum(array_map(static fn(array $brief): int => count($briefMissing($brief)), $roadmap));
$averageReadiness = $roadmapTotal > 0
    ? (int) round(array_sum(array_map($briefScore, $roadmap)) / $roadmapTotal)
    : 0;
$nextBrief = (array) ($roadmap[0] ?? []);
$firstGapBrief = (array) (array_values(array_filter($roadmap, static fn(array $brief): bool => $briefMissing($brief) !== []))[0] ?? []);
$nextBriefUrl = $nextBrief !== [] ? 'marketing_brief_view.php?id=' . (int) ($nextBrief['id'] ?? 0) : 'marketing_briefs.php';
$gapBriefUrl = $firstGapBrief !== [] ? 'marketing_brief_view.php?id=' . (int) ($firstGapBrief['id'] ?? 0) : 'marketing_briefs.php';
$summaryTiles = [
    ['icon' => 'fa-calendar-days', 'label' => 'Window', 'value' => $dateLabel($dateFrom, 'Start') . ' - ' . $dateLabel($dateTo, 'End'), 'tooltip' => 'The launch window currently shown on the roadmap. Change it in More roadmap tools.'],
    ['icon' => 'fa-route', 'label' => 'Campaigns', 'value' => (string) $roadmapTotal, 'tooltip' => 'Open campaign briefs inside this launch window.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $averageReadiness . '%', 'tooltip' => 'Average planning readiness across visible campaign briefs.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Gaps', 'value' => (string) $missingTotal, 'tooltip' => 'Missing planning inputs across visible briefs. Open a card to fix the details.'],
];
$stageCards = [
    [
        'label' => 'Choose Window',
        'icon' => 'fa-calendar-check',
        'status' => $dateFrom !== '' && $dateTo !== '' ? 'ready' : 'setup_needed',
        'sentence' => 'Pick the launch period.',
        'tooltip' => 'Expert view: date_from and date_to filters for open campaign briefs.',
        'href' => 'marketing_roadmap.php',
        'action' => 'View window',
    ],
    [
        'label' => 'Check Readiness',
        'icon' => 'fa-chart-simple',
        'status' => $readyTotal > 0 ? 'ready' : ($roadmapTotal > 0 ? 'setup_needed' : 'blocked'),
        'sentence' => 'Find plans ready to move.',
        'tooltip' => 'Expert view: campaign readiness score, context score, and open brief status.',
        'href' => $nextBriefUrl,
        'action' => 'Check plan',
    ],
    [
        'label' => 'Fill Gaps',
        'icon' => 'fa-wand-magic-sparkles',
        'status' => $missingTotal === 0 && $roadmapTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Close the missing inputs.',
        'tooltip' => 'Expert view: missing_strategy_json fields such as audience, offer, message, channel plan, and timing.',
        'href' => $gapBriefUrl,
        'action' => 'Fix gaps',
    ],
    [
        'label' => 'Open Brief',
        'icon' => 'fa-file-lines',
        'status' => $roadmapTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Work from one plan.',
        'tooltip' => 'Expert view: campaign brief workspace with audience, offer, content, and launch evidence.',
        'href' => $nextBriefUrl,
        'action' => 'Open brief',
    ],
    [
        'label' => 'Launch Route',
        'icon' => 'fa-rocket',
        'status' => $activeTotal > 0 && $readyTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Move toward launch checks.',
        'tooltip' => 'Expert view: launch readiness, campaign workspace, and channel handoff pages.',
        'href' => 'marketing_launch_readiness.php',
        'action' => 'Check launch',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $nextBrief !== [] ? [
        'label' => 'Review next campaign',
        'href' => $nextBriefUrl,
        'reason' => 'Open the next dated brief and decide what needs attention first.',
    ] : null,
    $firstGapBrief !== [] ? [
        'label' => 'Fix roadmap gaps',
        'href' => $gapBriefUrl,
        'reason' => 'Complete missing planning inputs before the launch window gets crowded.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Create launch brief',
        'href' => 'marketing_brief_edit.php',
        'reason' => 'Add one campaign plan to the roadmap.',
    ] : null,
    [
        'label' => 'Open launch readiness',
        'href' => 'marketing_launch_readiness.php',
        'reason' => 'Check whether the campaign has the evidence needed to launch cleanly.',
    ],
    [
        'label' => 'Open workspace',
        'href' => 'marketing_campaign_workspace.php',
        'reason' => 'Connect the roadmap to campaign execution and learning.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the guided command center.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Create and review campaign plans.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect briefs to execution readiness.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check campaign launch evidence.'],
    ['label' => 'Calendar', 'href' => 'marketing_calendar.php', 'hint' => 'Review scheduled marketing work.'],
    ['label' => 'Playbook Library', 'href' => 'marketing_playbooks.php', 'hint' => 'Start from reusable campaign patterns.'],
];
$pageTitle = 'Campaign Roadmap - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-roadmap-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Campaign Roadmap</h1>
                <p>Launch Map: choose the next window with less guesswork.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_brief_edit.php"><i class="fas fa-plus"></i> New Brief</a><?php endif; ?>
            </div>
        </div>

        <section class="marketing-roadmap-shell">
            <div class="marketing-founder-summary marketing-roadmap-summary" aria-label="Roadmap summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-roadmap-stage-grid" aria-label="Launch roadmap path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-roadmap-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-roadmap-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-roadmap-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-roadmap-layout">
                <main class="marketing-roadmap-main">
                    <section class="content-card marketing-roadmap-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Launch Window</h2>
                                <p>Pick the campaign that needs your attention next.</p>
                            </div>
                            <span class="badge <?php echo $roadmapTotal > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $roadmapTotal; ?> plans</span>
                        </div>

                        <?php if ($roadmap === []): ?>
                            <div class="empty-state">
                                <p>No campaign briefs match this launch window.</p>
                                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_brief_edit.php">Create brief</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-roadmap-card-grid">
                                <?php foreach ($roadmap as $brief): ?>
                                    <?php
                                    $score = $briefScore($brief);
                                    $missing = $briefMissing($brief);
                                    $status = $cleanStatus((string) ($brief['status'] ?? 'draft'));
                                    $cardTone = $score >= 80 ? 'ready' : ($missing !== [] ? 'setup_needed' : 'warning');
                                    $tooltip = $missing === []
                                        ? 'Ready enough for launch review. Expert view: no missing strategy inputs are recorded.'
                                        : 'Missing: ' . implode(', ', array_map($labelize, $missing));
                                    ?>
                                    <article class="marketing-roadmap-card <?php echo htmlspecialchars($cardTone); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                        <div class="marketing-roadmap-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-map-location-dot" aria-hidden="true"></i>
                                            </div>
                                            <span class="badge <?php echo htmlspecialchars($badgeClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                                        </div>
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) ($brief['title'] ?? 'Untitled brief')); ?></h3>
                                            <div class="marketing-roadmap-card-meta">
                                                <span><?php echo htmlspecialchars($dateLabel((string) ($brief['start_date'] ?? ''), 'No start')); ?> to <?php echo htmlspecialchars($dateLabel((string) ($brief['end_date'] ?? ''), 'No end')); ?></span>
                                                <span><?php echo htmlspecialchars((string) ($brief['persona_name'] ?? $brief['audience_segment_name'] ?? 'Audience needed')); ?></span>
                                                <span><?php echo htmlspecialchars((string) ($brief['offer_title'] ?? 'Offer needed')); ?></span>
                                            </div>
                                        </div>
                                        <div class="marketing-roadmap-score">
                                            <strong><?php echo $score; ?>%</strong>
                                            <progress class="marketing-roadmap-meter" max="100" value="<?php echo $score; ?>" aria-label="Readiness score"></progress>
                                            <small><?php echo $missing === [] ? 'Ready for launch review' : count($missing) . ' gaps to close'; ?></small>
                                        </div>
                                        <a class="btn-premium-primary marketing-roadmap-card-action" href="marketing_brief_view.php?id=<?php echo (int) ($brief['id'] ?? 0); ?>">Open brief</a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="marketing-roadmap-today content-card" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>One launch move at a time.</p>
                        </div>
                    </div>
                    <div class="marketing-playbook-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>">
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                                <small><?php echo htmlspecialchars((string) $action['reason']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-roadmap-tools">
                <summary>More roadmap tools</summary>
                <div class="marketing-roadmap-tools-body">
                    <section class="marketing-roadmap-filter-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Window Filters</h2>
                                <p>Change the dates behind the launch map.</p>
                            </div>
                        </div>
                        <form class="marketing-roadmap-filter-form" method="GET">
                            <div class="form-group">
                                <label for="date_from">From</label>
                                <input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="form-group">
                                <label for="date_to">To</label>
                                <input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <button class="btn-premium-primary" type="submit">Update Roadmap</button>
                        </form>
                    </section>

                    <section class="marketing-playbook-template-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Advanced Routes</h2>
                                <p>Keep expert paths available without crowding the board.</p>
                            </div>
                        </div>
                        <div class="marketing-advanced-tools-grid">
                            <?php foreach ($expertLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars((string) $link['href']); ?>">
                                    <span><?php echo htmlspecialchars((string) $link['label']); ?></span>
                                    <small><?php echo htmlspecialchars((string) $link['hint']); ?></small>
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
