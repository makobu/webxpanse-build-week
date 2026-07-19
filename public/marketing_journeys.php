<?php
/**
 * Marketing journeys list.
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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        $journeyId = (int) ($_POST['id'] ?? 0);
        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete marketing journeys.');
            }
            $marketing->deleteJourney($journeyId);
            header('Location: ' . getBasePath() . '/marketing_journeys.php?success=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filters = [];
$status = trim((string) ($_GET['status'] ?? ''));
$segmentId = (int) ($_GET['audience_segment_id'] ?? 0);
$campaignId = (int) ($_GET['campaign_id'] ?? 0);
if ($status !== '') {
    $filters['status'] = $status;
}
if ($segmentId > 0) {
    $filters['audience_segment_id'] = $segmentId;
}
if ($campaignId > 0) {
    $filters['campaign_id'] = $campaignId;
}

$journeys = $marketing->listJourneys($filters, 100, 0);
$options = $marketing->optionData();
$audienceJourneyCenter = $marketing->getAudienceJourneyUsabilityCenter((int) ($user['id'] ?? 0), 8);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'active', 'completed' => 'badge-success',
        'blocked', 'needs_foundation' => 'badge-danger',
        'warning', 'attention', 'draft', 'planned', 'paused', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$cleanStatus = static fn(string $value): string => preg_replace('/[^a-z0-9_-]/i', '', $value) ?: 'setup_needed';
$journeyCockpitStatus = $cleanStatus((string) ($audienceJourneyCenter['status'] ?? 'needs_foundation'));
$journeyCockpitCounts = (array) ($audienceJourneyCenter['counts'] ?? []);
$journeyCounts = (array) ($journeyCockpitCounts['journeys'] ?? []);
$segmentCounts = (array) ($journeyCockpitCounts['segments'] ?? []);
$journeyTotal = count($journeys);
$readySegments = (int) ($segmentCounts['ready'] ?? 0);
$totalSegments = (int) ($segmentCounts['total'] ?? 0);
$blockedJourneys = (int) ($journeyCounts['blocked'] ?? 0);
$missingAudience = (int) ($journeyCounts['missing_audience'] ?? 0);
$missingCopy = (int) ($journeyCounts['missing_copy'] ?? 0);
$score = (int) ($audienceJourneyCenter['score'] ?? 0);
$firstJourney = (array) ($journeys[0] ?? []);
$todayActions = array_slice(array_values(array_filter([
    $firstJourney !== [] ? [
        'label' => 'Review first journey',
        'href' => 'marketing_journey_view.php?id=' . (int) ($firstJourney['id'] ?? 0),
        'reason' => 'Open the top journey and check the path from audience to action.',
    ] : null,
    $missingAudience > 0 ? [
        'label' => 'Connect an audience',
        'href' => 'marketing_segments.php',
        'reason' => 'A journey needs a real audience before it can guide campaign work.',
    ] : null,
    $missingCopy > 0 ? [
        'label' => 'Create missing message',
        'href' => 'marketing_content.php',
        'reason' => 'Journey steps need usable messages before launch preparation.',
    ] : null,
    $canWriteMarketing ? [
        'label' => 'Create journey',
        'href' => 'marketing_journey_edit.php',
        'reason' => 'Start a simple path for this audience and campaign.',
    ] : null,
    [
        'label' => 'Open audience activation',
        'href' => 'marketing_audience_activation.php',
        'reason' => 'Make sure the selected audience is usable before launch.',
    ],
])), 0, 5);
$summaryTiles = [
    ['icon' => 'fa-route', 'label' => 'Journeys', 'value' => (string) $journeyTotal, 'tooltip' => 'Journey maps in the current filtered view.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $score . '%', 'tooltip' => 'Audience Journey Cockpit readiness score from linked audiences, copy, and sequence checks.'],
    ['icon' => 'fa-users', 'label' => 'Ready segments', 'value' => $readySegments . '/' . $totalSegments, 'tooltip' => 'Audiences ready enough to support a journey.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Gaps', 'value' => (string) ($blockedJourneys + $missingAudience + $missingCopy), 'tooltip' => 'Blocked journeys, audience gaps, and copy gaps.'],
];
$stageCards = [
    [
        'label' => 'Choose Audience',
        'icon' => 'fa-users-viewfinder',
        'status' => $readySegments > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Start with a usable audience.',
        'tooltip' => 'Expert view: ready segment count from the Audience Journey Cockpit.',
        'href' => 'marketing_segments.php',
        'action' => 'Open audience',
    ],
    [
        'label' => 'Map Path',
        'icon' => 'fa-route',
        'status' => $journeyTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Sketch the steps customers should take.',
        'tooltip' => 'Expert view: journey records, steps, waits, conditions, and branches.',
        'href' => $canWriteMarketing ? 'marketing_journey_edit.php' : 'marketing_journeys.php',
        'action' => $canWriteMarketing ? 'Create path' : 'Review path',
    ],
    [
        'label' => 'Add Message',
        'icon' => 'fa-comment-dots',
        'status' => $missingCopy > 0 ? 'warning' : 'ready',
        'sentence' => 'Each step needs a clear message.',
        'tooltip' => 'Expert view: journey copy gaps and content drafts.',
        'href' => 'marketing_content.php',
        'action' => 'Open content',
    ],
    [
        'label' => 'Check Gaps',
        'icon' => 'fa-shield-halved',
        'status' => $blockedJourneys > 0 || $missingAudience > 0 ? 'blocked' : 'ready',
        'sentence' => 'Find blockers before launch work.',
        'tooltip' => 'Expert view: readiness blockers, missing audience evidence, and sequence gaps.',
        'href' => 'marketing_journeys.php',
        'action' => 'Check journey',
    ],
    [
        'label' => 'Use In Campaign',
        'icon' => 'fa-bullhorn',
        'status' => $score >= 70 ? 'ready' : 'setup_needed',
        'sentence' => 'Use the path to shape campaign work.',
        'tooltip' => 'Expert view: campaign, audience, content, and launch readiness connections.',
        'href' => 'marketing_campaign_workspace.php',
        'action' => 'Open workspace',
    ],
];
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the command center.'],
    ['label' => 'Audience Activation', 'href' => 'marketing_audience_activation.php', 'hint' => 'Make the audience usable before mapping a journey.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Connect journey work to campaign readiness.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Create the messages needed by journey steps.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check launch evidence before execution.'],
    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'hint' => 'Inspect expert journey relationships.'],
];

$pageTitle = 'Marketing Journeys - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-journeys-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Journey Map</h1>
                <p>Marketing Journeys: plan the customer path before content and launch work.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_journey_edit.php"><i class="fas fa-plus"></i> New Journey</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'deleted'): ?><div class="alert alert-success">Marketing journey deleted.</div><?php endif; ?>

        <section class="marketing-journey-shell">
            <div class="marketing-founder-summary marketing-journey-summary" aria-label="Journey map summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-journey-stage-grid" aria-label="Journey planning path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-journey-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-journey-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-journey-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-journey-layout">
                <main class="marketing-journey-main">
                    <section class="content-card marketing-journey-card-list">
                        <div class="premium-section-header">
                            <div>
                                <h2>Journey Board</h2>
                                <p>Keep the path clear enough to create content from it.</p>
                            </div>
                            <span class="badge <?php echo $journeyTotal > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $journeyTotal; ?> journeys</span>
                        </div>
                        <?php if ($journeys === []): ?>
                            <div class="empty-state">
                                <p>No journeys match this view.</p>
                                <a href="marketing_onboarding.php">Open setup</a>
                                <?php if ($canWriteMarketing): ?><a href="marketing_journey_edit.php">Create a journey</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-journey-card-grid">
                                <?php foreach ($journeys as $journey): ?>
                                    <?php
                                        $journeyStatus = $cleanStatus((string) ($journey['status'] ?? 'draft'));
                                        $stepCount = (int) ($journey['step_count'] ?? 0);
                                        $draftCount = (int) ($journey['draft_count'] ?? 0);
                                    ?>
                                    <article class="marketing-journey-card <?php echo htmlspecialchars($journeyStatus); ?>" tabindex="0" data-tooltip="Expert view: journey status, steps, drafts, audience, campaign, and goal.">
                                        <div class="marketing-journey-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-route" aria-hidden="true"></i>
                                            </div>
                                            <span class="badge <?php echo htmlspecialchars($badgeClass($journeyStatus)); ?>"><?php echo htmlspecialchars($labelize($journeyStatus)); ?></span>
                                        </div>
                                        <h3><?php echo htmlspecialchars((string) ($journey['name'] ?? 'Marketing journey')); ?></h3>
                                        <div class="marketing-journey-card-meta">
                                            <span><?php echo $stepCount; ?> steps</span>
                                            <span><?php echo $draftCount; ?> drafts</span>
                                            <?php if (!empty($journey['audience_segment_name'])): ?><span><?php echo htmlspecialchars((string) $journey['audience_segment_name']); ?></span><?php endif; ?>
                                            <?php if (!empty($journey['campaign_name'])): ?><span><?php echo htmlspecialchars((string) $journey['campaign_name']); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($journey['journey_goal'])): ?><p><?php echo htmlspecialchars((string) $journey['journey_goal']); ?></p><?php endif; ?>
                                        <a class="btn-premium-secondary marketing-journey-card-action" href="marketing_journey_view.php?id=<?php echo (int) $journey['id']; ?>">Review journey</a>
                                        <details class="marketing-journey-card-tools">
                                            <summary>More controls</summary>
                                            <div class="marketing-journey-card-tool-body">
                                                <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_journey_edit.php?id=<?php echo (int) $journey['id']; ?>">Edit journey</a><?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" onsubmit="return confirm('Delete this marketing journey and its planned steps?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $journey['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Delete</button>
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

                <aside class="content-card marketing-journey-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>One path decision at a time.</p>
                        </div>
                    </div>
                    <div class="marketing-journey-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketing-journey-score-card">
                        <div class="setup-score-ring" data-score="<?php echo min(100, max(0, $score)); ?>">
                            <span><?php echo $score; ?>%</span>
                        </div>
                        <strong>Audience Journey Cockpit</strong>
                        <small><?php echo htmlspecialchars($labelize($journeyCockpitStatus)); ?></small>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-journey-tools">
                <summary>
                    <span>More journey tools</span>
                    <small>Filters, Audience Journey Cockpit signals, and expert routes.</small>
                </summary>
                <div class="marketing-journey-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>">
                                <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <section class="content-card marketing-journey-filter-card">
                        <div class="premium-section-header"><h2>View filters</h2></div>
                        <form method="GET" class="marketing-journey-filter-form">
                            <div class="form-group"><label>Status</label><select name="status"><option value="">All</option><?php foreach (Marketing::JOURNEY_STATUSES as $journeyStatusOption): ?><option value="<?php echo htmlspecialchars($journeyStatusOption); ?>" <?php echo $selected($status, $journeyStatusOption); ?>><?php echo htmlspecialchars($labelize($journeyStatusOption)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Audience</label><select name="audience_segment_id"><option value="">All audiences</option><?php foreach ((array) ($options['audience_segments'] ?? []) as $segment): ?><option value="<?php echo (int) $segment['id']; ?>" <?php echo $selected($segmentId, $segment['id']); ?>><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">All campaigns</option><?php foreach ((array) ($options['campaigns'] ?? []) as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo $selected($campaignId, $campaign['id']); ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                            <button class="btn-premium-secondary" type="submit">Filter</button>
                            <a class="btn-premium-secondary" href="marketing_journeys.php">Reset</a>
                        </form>
                    </section>

                    <section class="content-card marketing-journey-cockpit-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Audience Journey Cockpit</h2>
                                <p>Expert readiness signals for journey planning.</p>
                            </div>
                            <span class="badge <?php echo htmlspecialchars($badgeClass($journeyCockpitStatus)); ?>"><?php echo htmlspecialchars($labelize($journeyCockpitStatus)); ?></span>
                        </div>
                        <div class="marketing-journey-cockpit-grid">
                            <article><span>Blocked</span><strong><?php echo $blockedJourneys; ?></strong></article>
                            <article><span>Audience gaps</span><strong><?php echo $missingAudience; ?></strong></article>
                            <article><span>Copy gaps</span><strong><?php echo $missingCopy; ?></strong></article>
                            <article><span>Ready segments</span><strong><?php echo $readySegments; ?>/<?php echo $totalSegments; ?></strong></article>
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
