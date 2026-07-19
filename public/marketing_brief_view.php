<?php
/**
 * Marketing campaign brief detail.
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
$id = (int) ($_GET['id'] ?? 0);
$brief = $marketing->getCampaignBrief($id);
if (!$brief) {
    http_response_code(404);
    echo 'Marketing campaign brief not found.';
    exit;
}

$contentItems = $marketing->listContentItems(['campaign_brief_id' => $id], 50, 0);
$milestones = $marketing->listCalendarMilestones(['campaign_brief_id' => $id], 50, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$valueOrDash = static fn($value): string => trim((string) $value) !== '' ? (string) $value : '-';
$listOrDash = static fn($value): string => !empty($value) ? implode(', ', (array) $value) : '-';
$missingStrategy = (array) ($brief['missing_strategy_json'] ?? []);
$readinessScore = max(0, min(100, (int) ($brief['readiness_score'] ?? $brief['context_score'] ?? 0)));
$isFilled = static fn($value): bool => trim((string) $value) !== '';
$goalReady = $isFilled($brief['objective'] ?? '');
$audienceReady = $isFilled($brief['audience'] ?? '') || (int) ($brief['audience_segment_id'] ?? 0) > 0;
$offerReady = $isFilled($brief['offer_text'] ?? '') || (int) ($brief['offer_context_item_id'] ?? 0) > 0;
$messageReady = $isFilled($brief['key_message'] ?? '');
$launchReady = !empty($brief['channels_json']) || !empty($brief['channel_plan_json']) || !empty($brief['launch_timeline_json']);
$linkedCount = count(array_filter([
    (int) ($brief['campaign_id'] ?? 0) > 0,
    (int) ($brief['campaign_playbook_id'] ?? 0) > 0,
    (int) ($brief['audience_segment_id'] ?? 0) > 0,
    (int) ($brief['persona_id'] ?? 0) > 0,
    (int) ($brief['offer_context_item_id'] ?? 0) > 0,
    (int) ($brief['content_pillar_context_item_id'] ?? 0) > 0,
    (int) ($brief['landing_page_id'] ?? 0) > 0,
])) + count($contentItems) + count($milestones);
$decisionCards = [
    [
        'label' => 'Goal',
        'value' => $goalReady ? 'Set' : 'Need',
        'status' => $goalReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-bullseye',
        'href' => '#brief-evidence',
        'tooltip' => 'The result this campaign is meant to create.',
    ],
    [
        'label' => 'Audience',
        'value' => $audienceReady ? 'Set' : 'Need',
        'status' => $audienceReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-users',
        'href' => !empty($brief['audience_segment_id']) ? 'marketing_segment_view.php?id=' . (int) $brief['audience_segment_id'] : '#brief-evidence',
        'tooltip' => 'The people this campaign is for.',
    ],
    [
        'label' => 'Offer',
        'value' => $offerReady ? 'Set' : 'Need',
        'status' => $offerReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-gift',
        'href' => '#brief-evidence',
        'tooltip' => 'The promise, product, or value being presented.',
    ],
    [
        'label' => 'Message',
        'value' => $messageReady ? 'Set' : 'Need',
        'status' => $messageReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-message',
        'href' => '#brief-message',
        'tooltip' => 'The simple campaign message the founder can review.',
    ],
    [
        'label' => 'Launch',
        'value' => $launchReady ? 'Set' : 'Need',
        'status' => $launchReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-calendar-check',
        'href' => '#brief-evidence',
        'tooltip' => 'Channels, timeline, milestones, and launch handoff evidence.',
    ],
];
$pageTitle = (string) $brief['title'] . ' - Marketing Brief - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-brief-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $brief['title']); ?></h1>
                <p>Campaign Brief Review</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_briefs.php">Campaign Plans</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_brief_edit.php?id=<?php echo (int) $brief['id']; ?>">Edit brief</a><?php endif; ?>
            </div>
        </div>

        <?php if (($_GET['success'] ?? '') === 'saved'): ?><div class="alert alert-success">Campaign brief saved.</div><?php endif; ?>

        <section class="marketing-brief-view-shell" aria-label="Campaign Brief Review">
            <div class="marketing-founder-summary marketing-brief-view-summary">
                <a class="marketing-summary-tile primary" href="<?php echo $canWriteMarketing ? 'marketing_content_edit.php?campaign_brief_id=' . (int) $brief['id'] : '#brief-message'; ?>" data-tooltip="The procedural next step after the brief is usable.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo $canWriteMarketing ? 'Create content' : 'Review message'; ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Current brief status.">
                    <i class="fas fa-circle-check"></i><span>Status</span><strong><?php echo htmlspecialchars($labelize((string) $brief['status'])); ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Readiness comes from the core campaign decisions and evidence.">
                    <i class="fas fa-gauge-high"></i><span>Ready</span><strong><?php echo $readinessScore; ?>%</strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Linked campaign, audience, content, and launch evidence.">
                    <i class="fas fa-link"></i><span>Linked</span><strong><?php echo $linkedCount; ?></strong>
                </div>
            </div>

            <div class="marketing-brief-review-grid">
                <?php foreach ($decisionCards as $card): ?>
                    <?php $cardStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($card['status'] ?? 'setup-needed')); ?>
                    <a class="marketing-brief-review-card <?php echo htmlspecialchars($cardStatus); ?>" href="<?php echo htmlspecialchars((string) ($card['href'] ?? '#brief-evidence')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($card['tooltip'] ?? 'Campaign brief decision.')); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($card['icon'] ?? 'fa-clipboard-list')); ?>"></i>
                            <span><?php echo htmlspecialchars((string) ($card['value'] ?? '')); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($card['label'] ?? 'Decision')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($cardStatus); ?>"><?php echo htmlspecialchars($labelize(str_replace('-', '_', $cardStatus))); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="marketing-founder-layout marketing-brief-view-layout">
                <div class="marketing-brief-view-main">
                    <div class="content-card marketing-brief-message-card" id="brief-message">
                        <div class="premium-section-header">
                            <div>
                                <h2>Key Message</h2>
                                <p><?php echo htmlspecialchars((string) ($brief['campaign_name'] ?? 'Campaign plan')); ?></p>
                            </div>
                        </div>
                        <?php if ($messageReady): ?>
                            <div class="marketing-brief-message-text"><?php echo nl2br(htmlspecialchars((string) $brief['key_message'])); ?></div>
                        <?php else: ?>
                            <div class="empty-state"><p>No key message yet.</p><?php if ($canWriteMarketing): ?><a href="marketing_brief_edit.php?id=<?php echo (int) $brief['id']; ?>">Add message</a><?php endif; ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="marketing-brief-decision-list">
                        <article class="marketing-brief-decision-item">
                            <span>Goal</span>
                            <strong><?php echo htmlspecialchars($valueOrDash($brief['objective'] ?? '')); ?></strong>
                        </article>
                        <article class="marketing-brief-decision-item">
                            <span>Audience</span>
                            <strong><?php echo htmlspecialchars($valueOrDash($brief['audience_segment_name'] ?? $brief['audience'] ?? '')); ?></strong>
                        </article>
                        <article class="marketing-brief-decision-item">
                            <span>Offer</span>
                            <strong><?php echo htmlspecialchars($valueOrDash($brief['offer_title'] ?? $brief['offer_text'] ?? '')); ?></strong>
                        </article>
                    </div>
                </div>

                <aside class="content-card marketing-brief-view-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Use the plan.</p>
                        </div>
                    </div>
                    <div class="marketing-brief-score-card">
                        <div class="setup-score-ring" data-score="<?php echo $readinessScore; ?>"><span><?php echo $readinessScore; ?>%</span></div>
                        <strong><?php echo $readinessScore >= 70 ? 'Ready for production' : 'Needs one more decision'; ?></strong>
                    </div>
                    <div class="marketing-brief-next-list">
                        <?php if ($canWriteMarketing): ?>
                            <a class="marketing-today-action high" href="marketing_content_edit.php?campaign_brief_id=<?php echo (int) $brief['id']; ?>" data-tooltip="Create the first campaign message from this brief.">
                                <i class="fas fa-pen-nib"></i><strong>Create content</strong><span><?php echo count($contentItems); ?> item<?php echo count($contentItems) === 1 ? '' : 's'; ?> linked.</span>
                            </a>
                            <a class="marketing-today-action" href="marketing_brief_edit.php?id=<?php echo (int) $brief['id']; ?>" data-tooltip="Adjust the decisions before production.">
                                <i class="fas fa-pen-to-square"></i><strong>Edit brief</strong><span><?php echo count($missingStrategy); ?> gap<?php echo count($missingStrategy) === 1 ? '' : 's'; ?> visible.</span>
                            </a>
                        <?php endif; ?>
                        <a class="marketing-today-action" href="#brief-evidence" data-tooltip="Open the detailed campaign evidence and linked records.">
                            <i class="fas fa-folder-open"></i><strong>Check evidence</strong><span><?php echo $linkedCount; ?> linked signal<?php echo $linkedCount === 1 ? '' : 's'; ?>.</span>
                        </a>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-brief-view-tools" id="brief-evidence">
                <summary>More brief evidence <i class="fas fa-chevron-down"></i></summary>
                <div class="marketing-brief-view-tools-body">
                    <?php if (!empty($missingStrategy)): ?><div class="alert alert-warning">Missing strategy inputs: <?php echo htmlspecialchars(implode(', ', array_map($labelize, $missingStrategy))); ?></div><?php endif; ?>

                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_briefs.php" data-tooltip="Return to the campaign plan board."><strong>Campaign Plans</strong><span>All briefs</span></a>
                        <?php if (!empty($brief['audience_segment_id'])): ?><a href="marketing_segment_view.php?id=<?php echo (int) $brief['audience_segment_id']; ?>" data-tooltip="Review the audience behind this brief."><strong>Audience Preview</strong><span><?php echo htmlspecialchars($valueOrDash($brief['audience_segment_name'] ?? '')); ?></span></a><?php endif; ?>
                        <a href="marketing_launch_checklists.php" data-tooltip="Check campaign, audience, content, destination, and channel evidence."><strong>Launch Checks</strong><span>Release gate</span></a>
                    </div>

                    <div class="content-card marketing-brief-evidence-card">
                        <div class="premium-section-header"><div><h2>Brief Evidence</h2><p>Expert details stay here when needed.</p></div></div>
                        <div class="marketing-segment-stat-grid">
                            <div class="marketing-segment-stat"><span>Campaign</span><strong><?php echo htmlspecialchars($valueOrDash($brief['campaign_name'] ?? '')); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Playbook</span><strong><?php echo htmlspecialchars($valueOrDash($brief['campaign_playbook_name'] ?? '')); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Persona</span><strong><?php echo htmlspecialchars($valueOrDash($brief['persona_name'] ?? '')); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Window</span><strong><?php echo htmlspecialchars($valueOrDash(trim((string) ($brief['start_date'] ?? '') . ' to ' . (string) ($brief['end_date'] ?? '')))); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Channels</span><strong><?php echo htmlspecialchars(implode(', ', (array) ($brief['channels_json'] ?? [])) ?: '-'); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Budget</span><strong><?php echo ($brief['budget_estimate'] ?? null) !== null ? htmlspecialchars(number_format((float) $brief['budget_estimate'], 2)) : '-'; ?></strong></div>
                        </div>
                    </div>

                    <div class="content-card marketing-brief-evidence-card">
                        <div class="premium-section-header"><div><h2>Launch Notes</h2><p>Metrics, channels, and timeline.</p></div></div>
                        <div class="marketing-brief-note-grid">
                            <article><span>Success Metrics</span><strong><?php echo nl2br(htmlspecialchars($valueOrDash($brief['success_metrics'] ?? ''))); ?></strong></article>
                            <article><span>Channel Plan</span><strong><?php echo htmlspecialchars($listOrDash($brief['channel_plan_json'] ?? [])); ?></strong></article>
                            <article><span>Launch Timeline</span><strong><?php echo htmlspecialchars($listOrDash($brief['launch_timeline_json'] ?? [])); ?></strong></article>
                        </div>
                    </div>

                    <div class="content-card marketing-brief-evidence-card">
                        <div class="premium-section-header"><div><h2>Linked Work</h2><p>Production items connected to this brief.</p></div></div>
                        <div class="marketing-segment-linked-grid">
                            <div class="marketing-segment-linked-card"><span>Content</span><strong><?php echo count($contentItems); ?></strong></div>
                            <div class="marketing-segment-linked-card"><span>Milestones</span><strong><?php echo count($milestones); ?></strong></div>
                            <div class="marketing-segment-linked-card"><span>Landing</span><strong><?php echo $isFilled($brief['landing_page_title'] ?? '') ? '1' : '0'; ?></strong></div>
                        </div>
                        <div class="marketing-segment-quality-list">
                            <?php foreach (array_slice($contentItems, 0, 5) as $item): ?>
                                <a class="marketing-segment-quality-item" href="marketing_content_view.php?id=<?php echo (int) $item['id']; ?>"><span>Content</span><strong><?php echo htmlspecialchars((string) $item['title']); ?></strong></a>
                            <?php endforeach; ?>
                            <?php foreach (array_slice($milestones, 0, 5) as $milestone): ?>
                                <div class="marketing-segment-quality-item"><span><?php echo htmlspecialchars((string) $milestone['milestone_date']); ?></span><strong><?php echo htmlspecialchars((string) $milestone['title']); ?></strong></div>
                            <?php endforeach; ?>
                            <?php if (empty($contentItems) && empty($milestones)): ?><div class="empty-state"><p>No production work is linked yet.</p></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
