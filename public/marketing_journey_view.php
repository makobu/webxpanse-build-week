<?php
/**
 * Marketing journey detail.
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
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$journey = $id > 0 ? $marketing->getJourney($id) : null;
if (!$journey) {
    http_response_code(404);
    echo 'Marketing journey not found.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'generate_draft') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to generate journey drafts.');
            }
            $marketing->generateJourneyDraft([
                'journey_id' => $id,
                'prompt' => $_POST['prompt'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_journey_view.php?id=' . $id . '&success=draft');
            exit;
        }
        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete marketing journeys.');
            }
            $marketing->deleteJourney($id);
            header('Location: ' . getBasePath() . '/marketing_journeys.php?success=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$journey = $marketing->getJourney($id) ?? $journey;
$steps = (array) ($journey['steps'] ?? []);
$drafts = (array) ($journey['drafts'] ?? []);
$readiness = $marketing->getJourneyReadiness($id);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$formatJson = static fn($value): string => is_array($value) && $value !== [] ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
$status = (string) ($journey['status'] ?? 'draft');
$readinessStatus = (string) ($readiness['status'] ?? 'blocked');
$score = (int) ($readiness['score'] ?? 0);
$readySteps = (int) ($readiness['counts']['ready_steps'] ?? 0);
$blockedSteps = (int) ($readiness['counts']['blocked_steps'] ?? 0);
$copyGaps = (int) ($readiness['counts']['missing_step_copy'] ?? 0);
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'active', 'completed' => 'badge-success',
        'blocked' => 'badge-danger',
        'warning', 'draft', 'planned', 'paused', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$summaryTiles = [
    ['icon' => 'fa-route', 'label' => 'Status', 'value' => $labelize($status), 'tooltip' => 'Marketing Journey status for this path.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $score . '%', 'tooltip' => 'Journey Readiness score from audience, copy, timing, branching, and manual safety checks.'],
    ['icon' => 'fa-list-check', 'label' => 'Steps', 'value' => (string) count($steps), 'tooltip' => 'Planned steps in this customer path.'],
    ['icon' => 'fa-wand-magic-sparkles', 'label' => 'Drafts', 'value' => (string) count($drafts), 'tooltip' => 'AI draft recommendations saved for manual review.'],
];
$reviewCards = [
    ['label' => 'Audience', 'icon' => 'fa-users-viewfinder', 'status' => !empty($journey['audience_segment_name']) ? 'ready' : 'setup_needed', 'sentence' => !empty($journey['audience_segment_name']) ? 'Audience is linked.' : 'Audience is missing.', 'tooltip' => 'Expert view: audience segment relationship.', 'href' => !empty($journey['audience_segment_id']) ? 'marketing_segment_view.php?id=' . (int) $journey['audience_segment_id'] : 'marketing_segments.php', 'action' => 'Review audience'],
    ['label' => 'Path', 'icon' => 'fa-route', 'status' => count($steps) > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Review the customer movement.', 'tooltip' => 'Expert view: ordered journey steps, waits, branches, and instructions.', 'href' => '#journey-timeline', 'action' => 'View path'],
    ['label' => 'Message', 'icon' => 'fa-comment-dots', 'status' => $copyGaps > 0 ? 'warning' : 'ready', 'sentence' => $copyGaps > 0 ? 'Some steps need copy.' : 'Step copy looks usable.', 'tooltip' => 'Expert view: missing step copy and linked content status.', 'href' => 'marketing_content.php', 'action' => 'Open content'],
    ['label' => 'Readiness', 'icon' => 'fa-shield-halved', 'status' => $readinessStatus, 'sentence' => 'Check blockers before launch.', 'tooltip' => 'Expert view: Journey Readiness checks and recommendations.', 'href' => '#journey-readiness', 'action' => 'Check readiness'],
    ['label' => 'Next Draft', 'icon' => 'fa-wand-magic-sparkles', 'status' => count($drafts) > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Draft help is optional.', 'tooltip' => 'Expert view: AI draft recommendation history.', 'href' => '#journey-drafts', 'action' => 'Review drafts'],
];
$todayActions = array_slice(array_values(array_filter([
    $blockedSteps > 0 || $copyGaps > 0 ? ['label' => 'Fix readiness gap', 'href' => 'marketing_journey_edit.php?id=' . (int) $id, 'reason' => 'Edit the journey to resolve missing copy, audience, timing, or branch details.'] : null,
    ['label' => 'Review timeline', 'href' => '#journey-timeline', 'reason' => 'Check the customer path in order.'],
    $canWriteMarketing ? ['label' => 'Edit journey', 'href' => 'marketing_journey_edit.php?id=' . (int) $id, 'reason' => 'Change the path, audience, goal, or linked work.'] : null,
    ['label' => 'Open launch readiness', 'href' => 'marketing_launch_readiness.php', 'reason' => 'Use the journey only after launch evidence is ready.'],
])), 0, 5);
$pageTitle = (string) $journey['name'] . ' - Marketing Journey - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-journey-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $journey['name']); ?></h1>
                <p>Journey Readiness: review the customer path before content and launch work.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_journeys.php"><i class="fas fa-arrow-left"></i> Journeys</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_journey_edit.php?id=<?php echo (int) $id; ?>"><i class="fas fa-pen"></i> Edit</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'saved'): ?><div class="alert alert-success">Marketing journey saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'draft'): ?><div class="alert alert-success">Journey draft recommendation created.</div><?php endif; ?>

        <section class="marketing-journey-view-shell">
            <div class="marketing-founder-summary marketing-journey-view-summary" aria-label="Journey review summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-journey-review-grid" aria-label="Journey review path">
                <?php foreach ($reviewCards as $card): ?>
                    <?php $cardStatus = (string) $card['status']; ?>
                    <article class="marketing-journey-review-card <?php echo htmlspecialchars($cardStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-journey-review-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($cardStatus)); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $card['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $card['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-journey-review-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-journey-view-layout">
                <main class="marketing-journey-view-main">
                    <section class="content-card marketing-journey-readiness-card" id="journey-readiness">
                        <div class="premium-section-header">
                            <div>
                                <h2>Journey Readiness</h2>
                                <p>Plain-language launch blockers.</p>
                            </div>
                            <span class="badge <?php echo htmlspecialchars($badgeClass($readinessStatus)); ?>"><?php echo $score; ?>% <?php echo htmlspecialchars($labelize($readinessStatus)); ?></span>
                        </div>
                        <div class="marketing-journey-readiness-grid">
                            <article><span>Ready steps</span><strong><?php echo $readySteps; ?></strong></article>
                            <article><span>Blocked</span><strong><?php echo $blockedSteps; ?></strong></article>
                            <article><span>Copy gaps</span><strong><?php echo $copyGaps; ?></strong></article>
                        </div>
                        <?php if (!empty($readiness['recommendations'])): ?>
                            <div class="marketing-journey-recommendation-list">
                                <?php foreach (array_slice((array) ($readiness['recommendations'] ?? []), 0, 3) as $recommendation): ?>
                                    <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing_journey_edit.php?id=' . (int) $id)); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($recommendation['reason'] ?? 'Review journey readiness.')); ?>">
                                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                        <span><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Review journey')); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-journey-timeline-card" id="journey-timeline">
                        <div class="premium-section-header">
                            <div>
                                <h2>Journey Timeline</h2>
                                <p>Each card is a planned manual step.</p>
                            </div>
                        </div>
                        <?php if ($steps === []): ?>
                            <div class="empty-state"><p>No steps are configured yet.</p><?php if ($canWriteMarketing): ?><a href="marketing_journey_edit.php?id=<?php echo (int) $id; ?>">Add steps</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="marketing-journey-timeline-grid">
                                <?php foreach ($steps as $index => $step): ?>
                                    <article class="marketing-journey-timeline-step">
                                        <div class="marketing-journey-step-head">
                                            <span><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                            <strong><?php echo htmlspecialchars((string) ($step['title'] ?? 'Journey step')); ?></strong>
                                        </div>
                                        <div class="marketing-journey-card-meta">
                                            <span><?php echo htmlspecialchars($labelize((string) ($step['step_type'] ?? 'step'))); ?></span>
                                            <?php if (($step['wait_days'] ?? '') !== null && ($step['wait_days'] ?? '') !== ''): ?><span><?php echo (int) $step['wait_days']; ?> wait days</span><?php endif; ?>
                                            <?php if (!empty($step['content_title'])): ?><span>Content linked</span><?php endif; ?>
                                            <?php if (!empty($step['email_run_name'])): ?><span>Email linked</span><?php endif; ?>
                                            <?php if (!empty($step['task_title'])): ?><span>Task linked</span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($step['instructions'])): ?><p><?php echo htmlspecialchars((string) $step['instructions']); ?></p><?php endif; ?>
                                        <details class="marketing-journey-timeline-tools">
                                            <summary>More step evidence</summary>
                                            <div class="marketing-journey-timeline-tools-body">
                                                <?php if (!empty($step['content_title'])): ?><div><span>Content</span><strong><?php echo htmlspecialchars((string) $step['content_title']); ?></strong></div><?php endif; ?>
                                                <?php if (!empty($step['email_run_name'])): ?><div><span>Email run</span><strong><?php echo htmlspecialchars((string) $step['email_run_name']); ?></strong></div><?php endif; ?>
                                                <?php if (!empty($step['task_title'])): ?><div><span>Task</span><strong><?php echo htmlspecialchars((string) $step['task_title']); ?></strong></div><?php endif; ?>
                                                <?php if ($formatJson($step['condition_json'] ?? []) !== ''): ?><pre><?php echo htmlspecialchars($formatJson($step['condition_json'])); ?></pre><?php endif; ?>
                                                <?php if ($formatJson($step['branch_json'] ?? []) !== ''): ?><pre><?php echo htmlspecialchars($formatJson($step['branch_json'])); ?></pre><?php endif; ?>
                                            </div>
                                        </details>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="content-card marketing-journey-view-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Review one path decision.</p>
                        </div>
                    </div>
                    <div class="marketing-journey-view-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketing-journey-context-card">
                        <div class="mini-row"><span>Audience</span><strong><?php echo htmlspecialchars((string) ($journey['audience_segment_name'] ?? 'Not linked')); ?></strong></div>
                        <div class="mini-row"><span>Campaign</span><strong><?php echo htmlspecialchars((string) ($journey['campaign_name'] ?? 'Not linked')); ?></strong></div>
                        <div class="mini-row"><span>Owner</span><strong><?php echo htmlspecialchars((string) ($journey['owner_email'] ?? 'Unassigned')); ?></strong></div>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-journey-view-tools">
                <summary>
                    <span>More journey evidence</span>
                    <small>Drafts, detailed checks, context, and controls.</small>
                </summary>
                <div class="marketing-journey-view-tools-body">
                    <section class="content-card marketing-journey-checks-card">
                        <div class="premium-section-header"><h2>Detailed Journey Readiness</h2></div>
                        <div class="marketing-journey-check-list">
                            <?php foreach ((array) ($readiness['checks'] ?? []) as $check): ?>
                                <article class="marketing-journey-check <?php echo htmlspecialchars((string) ($check['status'] ?? 'warning')); ?>">
                                    <strong><?php echo (int) ($check['step_order'] ?? 0); ?>. <?php echo htmlspecialchars((string) ($check['title'] ?? 'Journey step')); ?></strong>
                                    <span><?php echo htmlspecialchars($labelize((string) ($check['step_type'] ?? 'step'))); ?> · <?php echo htmlspecialchars($labelize((string) ($check['status'] ?? 'warning'))); ?></span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="content-card marketing-journey-drafts-card" id="journey-drafts">
                        <div class="premium-section-header">
                            <div><h2>Journey Drafts</h2><p>Draft-side recommendations only.</p></div>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-journey-draft-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="generate_draft">
                                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                <div class="form-group"><label>Draft Prompt</label><textarea name="prompt" rows="3" placeholder="Suggest a reactivation path for inactive leads"></textarea></div>
                                <button class="btn-premium-primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Generate Draft Recommendation</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($drafts === []): ?>
                            <div class="empty-state"><p>No journey drafts yet.</p></div>
                        <?php else: ?>
                            <div class="marketing-journey-draft-list">
                                <?php foreach ($drafts as $draft): ?>
                                    <article class="marketing-journey-draft-block">
                                        <div class="marketing-journey-card-meta">
                                            <span><?php echo htmlspecialchars($labelize((string) ($draft['draft_type'] ?? 'manual'))); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($draft['status'] ?? 'draft'))); ?></span>
                                            <span><?php echo htmlspecialchars((string) ($draft['created_at'] ?? '')); ?></span>
                                        </div>
                                        <?php if (!empty($draft['prompt'])): ?><p><?php echo htmlspecialchars((string) $draft['prompt']); ?></p><?php endif; ?>
                                        <pre><?php echo htmlspecialchars(json_encode($draft['draft_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="content-card marketing-journey-controls-card">
                        <div class="premium-section-header"><h2>Controls</h2></div>
                        <p>Journeys are planning records. Publishing, sending, and connector execution remain disabled.</p>
                        <?php if ($canManageMarketing): ?>
                            <form method="POST" onsubmit="return confirm('Delete this marketing journey and its planned steps?');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                <button class="btn-premium-secondary manage-only" type="submit">Delete Journey</button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
