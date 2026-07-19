<?php
/**
 * Marketing campaign briefs list.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingWorkflowNextStepsUi;
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
$filters = [];
$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') {
    $filters['status'] = $status;
}
$briefs = $marketing->listCampaignBriefs($filters, 100, 0);
$workflowNextSteps = $marketing->getMarketingWorkflowNextStepCenter((int) ($user['id'] ?? 0), 'campaigns', 6);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$briefCount = count($briefs);
$activeBriefCount = count(array_filter($briefs, static fn(array $brief): bool => in_array((string) ($brief['status'] ?? ''), ['draft', 'active'], true)));
$readyBriefCount = count(array_filter($briefs, static fn(array $brief): bool => (int) ($brief['readiness_score'] ?? $brief['context_score'] ?? 0) >= 70));
$linkedAudienceCount = count(array_filter($briefs, static fn(array $brief): bool => (int) ($brief['audience_segment_id'] ?? 0) > 0 || trim((string) ($brief['audience'] ?? '')) !== ''));
$linkedCampaignCount = count(array_filter($briefs, static fn(array $brief): bool => (int) ($brief['campaign_id'] ?? 0) > 0));
$todayActions = array_slice((array) ($workflowNextSteps['actions'] ?? []), 0, 5);
$primaryActionUrl = $canWriteMarketing ? 'marketing_brief_edit.php' : 'marketing_briefs.php#brief-library';
$primaryActionLabel = $briefCount > 0 ? 'Review briefs' : 'New brief';
$briefSignals = [
    [
        'label' => 'Plans',
        'count' => $briefCount,
        'status' => $briefCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-clipboard-list',
        'href' => 'marketing_briefs.php#brief-library',
        'tooltip' => 'Campaign briefs turn audience, offer, message, and timeline into one working plan.',
    ],
    [
        'label' => 'In motion',
        'count' => $activeBriefCount,
        'status' => $activeBriefCount > 0 ? 'in-use' : 'setup-needed',
        'icon' => 'fa-route',
        'href' => 'marketing_briefs.php#brief-library',
        'tooltip' => 'Draft or active briefs that can guide content and launch work.',
    ],
    [
        'label' => 'Ready',
        'count' => $readyBriefCount,
        'status' => $readyBriefCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-circle-check',
        'href' => 'marketing_content.php',
        'tooltip' => 'Briefs with enough evidence to move into content work.',
    ],
    [
        'label' => 'Audiences',
        'count' => $linkedAudienceCount,
        'status' => $linkedAudienceCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-users',
        'href' => 'marketing_segments.php',
        'tooltip' => 'Briefs connected to a saved audience segment or written audience definition.',
    ],
    [
        'label' => 'Campaigns',
        'count' => $linkedCampaignCount,
        'status' => $linkedCampaignCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-bullhorn',
        'href' => 'marketing_briefs.php#brief-library',
        'tooltip' => 'Briefs anchored to campaign records for planning.',
    ],
];
$statusTone = static function (string $status): string {
    return match ($status) {
        'active', 'approved', 'launched_manual', 'ready' => 'ready',
        'draft', 'in_review' => 'setup-needed',
        'archived', 'blocked' => 'locked',
        default => 'setup-needed',
    };
};
$pageTitle = 'Marketing Briefs - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-briefs-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Campaign Plan Board</h1>
                <p>Shape the campaign before content work starts.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_segments.php">Audience Builder</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>"><?php echo htmlspecialchars($primaryActionLabel); ?></a><?php endif; ?>
            </div>
        </div>

        <section class="marketing-briefs-shell" aria-label="Campaign plan board">
            <div class="marketing-founder-summary marketing-briefs-summary">
                <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>" data-tooltip="The next planning action in the founder path.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo htmlspecialchars($primaryActionLabel); ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Draft and active plans that can guide production.">
                    <i class="fas fa-route"></i><span>In motion</span><strong><?php echo $activeBriefCount; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Briefs with enough strategy evidence to move toward launch checks.">
                    <i class="fas fa-circle-check"></i><span>Ready</span><strong><?php echo $readyBriefCount; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Briefs connected to a clear audience.">
                    <i class="fas fa-users"></i><span>Audience</span><strong><?php echo $linkedAudienceCount; ?></strong>
                </div>
            </div>

            <div class="marketing-brief-signal-grid">
                <?php foreach ($briefSignals as $signal): ?>
                    <?php $signalStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($signal['status'] ?? 'setup-needed')); ?>
                    <?php $signalStatusLabel = $labelize(str_replace('-', '_', $signalStatus)); ?>
                    <a class="marketing-brief-signal-card <?php echo htmlspecialchars($signalStatus); ?>" href="<?php echo htmlspecialchars((string) ($signal['href'] ?? 'marketing_briefs.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($signal['tooltip'] ?? 'Campaign planning signal.')); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($signal['icon'] ?? 'fa-clipboard-list')); ?>"></i>
                            <span><?php echo number_format((int) ($signal['count'] ?? 0)); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($signal['label'] ?? 'Signal')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($signalStatus); ?>"><?php echo htmlspecialchars($signalStatusLabel); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="marketing-founder-layout marketing-briefs-layout">
                <div class="content-card marketing-briefs-list" id="brief-library">
                    <div class="premium-section-header">
                        <div>
                            <h2>Campaign Briefs</h2>
                            <p><?php echo $briefCount; ?> saved</p>
                        </div>
                    </div>

                    <?php if (empty($briefs)): ?>
                        <div class="empty-state marketing-brief-empty">
                            <p>No campaign plans match this view.</p>
                            <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_brief_edit.php">Create campaign plan</a><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="marketing-brief-card-list">
                            <?php foreach ($briefs as $brief): ?>
                                <?php $briefStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($brief['status'] ?? 'draft')); ?>
                                <?php $tone = $statusTone($briefStatus); ?>
                                <?php $score = (int) ($brief['readiness_score'] ?? $brief['context_score'] ?? 0); ?>
                                <?php $audienceLabel = (string) ($brief['audience_segment_name'] ?? $brief['audience'] ?? 'Audience needed'); ?>
                                <article class="marketing-brief-card <?php echo htmlspecialchars($tone); ?>" data-tooltip="<?php echo htmlspecialchars('Expert object: Campaign brief. Readiness: ' . $score . '%.'); ?>">
                                    <div class="marketing-stage-visual">
                                        <i class="fas fa-clipboard-check"></i>
                                        <span><?php echo $score; ?>%</span>
                                    </div>
                                    <div class="marketing-brief-card-body">
                                        <div class="marketing-stage-title-row">
                                            <h2><?php echo htmlspecialchars((string) $brief['title']); ?></h2>
                                            <span class="marketing-stage-badge <?php echo htmlspecialchars($tone); ?>"><?php echo htmlspecialchars($labelize($briefStatus)); ?></span>
                                        </div>
                                        <div class="marketing-meta">
                                            <span><?php echo htmlspecialchars((string) ($brief['campaign_name'] ?? 'Campaign needed')); ?></span>
                                            <span><?php echo htmlspecialchars($audienceLabel); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($brief['offer_title'] ?? 'Offer needed')); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($brief['start_date'] ?? 'No start')); ?></span>
                                        </div>
                                    </div>
                                    <a class="btn-premium-secondary marketing-brief-action" href="marketing_brief_view.php?id=<?php echo (int) $brief['id']; ?>">Review plan</a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="content-card marketing-briefs-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>One plan, then production.</p>
                        </div>
                    </div>
                    <div class="marketing-brief-next-list">
                        <?php if ($todayActions === []): ?>
                            <a class="marketing-today-action high" href="<?php echo htmlspecialchars($primaryActionUrl); ?>" data-tooltip="Start with one campaign plan before creating more marketing assets.">
                                <i class="fas fa-arrow-right"></i><strong><?php echo htmlspecialchars($primaryActionLabel); ?></strong><span>Make one campaign clear enough to execute.</span>
                            </a>
                        <?php else: ?>
                            <?php foreach ($todayActions as $action): ?>
                                <?php $priority = preg_replace('/[^a-z0-9_-]/i', '', (string) ($action['priority'] ?? 'normal')); ?>
                                <a class="marketing-today-action <?php echo htmlspecialchars($priority); ?>" href="<?php echo htmlspecialchars((string) ($action['href'] ?? $primaryActionUrl)); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($action['reason'] ?? $action['detail'] ?? 'Recommended planning step.')); ?>">
                                    <i class="fas fa-arrow-right"></i><strong><?php echo htmlspecialchars((string) ($action['label'] ?? $action['title'] ?? 'Next action')); ?></strong><span><?php echo htmlspecialchars((string) ($action['reason'] ?? $action['detail'] ?? 'Keep the campaign plan moving.')); ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-briefs-tools" id="brief-tools">
                <summary>More brief tools <i class="fas fa-chevron-down"></i></summary>
                <div class="marketing-briefs-tools-body">
                    <form class="marketing-brief-filter" method="GET">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All</option>
                                <?php foreach (Marketing::BRIEF_STATUSES as $briefStatus): ?><option value="<?php echo htmlspecialchars($briefStatus); ?>" <?php echo $selected($status, $briefStatus); ?>><?php echo htmlspecialchars($labelize($briefStatus)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                        <a class="btn-premium-secondary" href="marketing_briefs.php">Reset</a>
                    </form>

                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_campaign_workspace.php" data-tooltip="Connect campaigns, briefs, audiences, and execution work."><strong>Campaign Workspace</strong><span>Plan system</span></a>
                        <a href="marketing_playbooks.php" data-tooltip="Start from repeatable campaign kits when the path is known."><strong>Playbooks</strong><span>Reusable kits</span></a>
                        <a href="marketing_launch_checklists.php" data-tooltip="Check campaign, audience, content, destination, and channel evidence."><strong>Launch Checks</strong><span>Release gate</span></a>
                    </div>

                    <?php echo MarketingWorkflowNextStepsUi::render($workflowNextSteps); ?>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
