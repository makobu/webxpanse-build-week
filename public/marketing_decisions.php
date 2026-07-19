<?php
/**
 * Marketing Decision Center.
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
        $action = (string) ($_POST['action'] ?? 'create_decision');
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to update Marketing decisions.');
        }

        if ($action === 'create_decision') {
            $marketing->createMarketingDecision([
                'title' => (string) ($_POST['title'] ?? ''),
                'decision_type' => (string) ($_POST['decision_type'] ?? 'custom'),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'source_type' => (string) ($_POST['source_type'] ?? 'custom'),
                'campaign_id' => (int) ($_POST['campaign_id'] ?? 0),
                'recommended_action' => (string) ($_POST['recommended_action'] ?? ''),
                'rationale' => (string) ($_POST['rationale'] ?? ''),
                'expected_impact' => (string) ($_POST['expected_impact'] ?? ''),
                'due_at' => (string) ($_POST['due_at'] ?? ''),
                'owner_user_id' => (int) ($_POST['owner_user_id'] ?? ($user['id'] ?? 0)),
                'created_by' => (int) ($user['id'] ?? 0),
                'evidence' => ['created_from' => 'marketing_decisions_page'],
            ]);
            header('Location: ' . getBasePath() . '/marketing_decisions.php?success=created');
            exit;
        }

        $decisionId = (int) ($_POST['decision_id'] ?? 0);
        if ($action === 'archive_decision') {
            if (!$canManageMarketing) {
                throw new RuntimeException('Only Marketing managers can archive decisions.');
            }
            $marketing->archiveMarketingDecision($decisionId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_decisions.php?success=archived');
            exit;
        }

        if ($action === 'decide_decision') {
            $marketing->decideMarketingDecision(
                $decisionId,
                (string) ($_POST['decision_status'] ?? 'open'),
                (string) ($_POST['decision_note'] ?? ''),
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_decisions.php?success=decided');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$statusFilter = (string) ($_GET['status'] ?? 'open');
$filters = [];
if ($statusFilter === 'all') {
    $filters = [];
} elseif ($statusFilter === 'open') {
    $filters = ['open' => true];
} else {
    $filters = ['decision_status' => $statusFilter];
}
$decisionCenter = $marketing->getMarketingDecisionCenter((int) ($user['id'] ?? 0), 8);
$decisions = $marketing->listMarketingDecisions($filters, 50, 0);
$campaigns = $marketing->listMarketingCampaignOptions(100);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$priorityClass = static function (string $priority): string {
    return match ($priority) {
        'urgent' => 'badge-danger',
        'high' => 'badge-warning',
        'low' => 'badge-default',
        default => 'badge-info',
    };
};
$statusClass = static function (string $status): string {
    return match ($status) {
        'accepted' => 'badge-success',
        'rejected', 'archived' => 'badge-danger',
        'deferred' => 'badge-warning',
        default => 'badge-info',
    };
};
$suggestedDecisions = (array) ($decisionCenter['suggested_decisions'] ?? []);
$counts = (array) ($decisionCenter['counts'] ?? []);
$openCount = (int) ($counts['open'] ?? 0);
$urgentCount = (int) ($counts['urgent'] ?? 0);
$deferredCount = (int) ($counts['deferred'] ?? 0);
$suggestedCount = count($suggestedDecisions);
$firstOpenDecision = $decisions[0] ?? [];
$firstSuggestedDecision = $suggestedDecisions[0] ?? [];
$todayActions = [];
if ($openCount > 0) {
    $todayActions[] = [
        'label' => 'Resolve one decision',
        'detail' => !empty($firstOpenDecision['title']) ? (string) $firstOpenDecision['title'] : 'Open decisions need a human choice.',
        'href' => '#decision-queue',
        'priority' => $urgentCount > 0 ? 'high' : 'normal',
        'tooltip' => 'Accept, reject, or defer the clearest open decision before launching more work.',
    ];
}
if ($suggestedCount > 0) {
    $todayActions[] = [
        'label' => 'Review suggestion',
        'detail' => !empty($firstSuggestedDecision['title']) ? (string) $firstSuggestedDecision['title'] : 'Marketing found a decision worth saving.',
        'href' => '#suggested-decisions',
        'priority' => 'normal',
        'tooltip' => 'Suggested decisions come from current launch, media, and system evidence.',
    ];
}
if ($canWriteMarketing) {
    $todayActions[] = [
        'label' => 'Capture a choice',
        'detail' => 'Log one product, launch, or operating decision.',
        'href' => '#create-decision',
        'priority' => 'normal',
        'tooltip' => 'Use this when a founder or operator must choose the next path.',
    ];
}
$todayActions[] = [
    'label' => 'Return to Marketing',
    'detail' => 'Go back to the guided command center.',
    'href' => 'marketing.php',
    'priority' => 'low',
    'tooltip' => 'Use the main Marketing map when the next decision is unclear.',
];
$todayActions = array_slice($todayActions, 0, 4);
$metricTiles = [
    [
        'label' => 'Open',
        'value' => $openCount,
        'icon' => 'fa-scale-balanced',
        'tooltip' => 'Decisions waiting for a founder or operator choice.',
    ],
    [
        'label' => 'Urgent',
        'value' => $urgentCount,
        'icon' => 'fa-bolt',
        'tooltip' => 'Choices that may block launch or create avoidable risk.',
    ],
    [
        'label' => 'Deferred',
        'value' => $deferredCount,
        'icon' => 'fa-clock',
        'tooltip' => 'Decisions parked for later review instead of blocking today.',
    ],
    [
        'label' => 'Suggested',
        'value' => $suggestedCount,
        'icon' => 'fa-wand-magic-sparkles',
        'tooltip' => 'AI or system-backed recommendations ready for human review.',
    ],
];
$advancedLinks = [
    ['label' => 'Command Center', 'href' => 'marketing.php', 'hint' => 'Return to the guided Marketing map.'],
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'hint' => 'Inspect campaign evidence before deciding.'],
    ['label' => 'Reports', 'href' => 'marketing_weekly_report.php', 'hint' => 'Review weekly evidence and outcomes.'],
];

$pageTitle = 'Marketing Decision Center - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-decisions-page"><div class="container">
    <div class="page-header">
        <div>
            <h1>Decision Center</h1>
            <p>Choose the next safe campaign move.</p>
        </div>
        <div class="page-header-actions">
            <a class="btn-premium-primary" href="#decision-queue"><i class="fas fa-arrow-down"></i> View Queue</a>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Decision was not saved.</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Decision created.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'decided'): ?><div class="alert alert-success">Decision status updated.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Decision archived.</div><?php endif; ?>

    <div class="marketing-decisions-summary">
        <?php foreach ($metricTiles as $tile): ?>
            <div class="marketing-summary-tile" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>" tabindex="0">
                <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                <div>
                    <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                    <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="marketing-decisions-layout">
        <div class="marketing-decisions-main">
            <div class="content-card marketing-decisions-board" id="decision-queue">
                <div class="premium-section-header">
                    <div><h2>Decision Board</h2><p>One queue for choices that need a human yes, no, or later.</p></div>
                    <form class="marketing-decisions-filter" method="GET">
                        <label>Status
                            <select name="status" class="form-control">
                                <?php foreach (['open', 'accepted', 'rejected', 'deferred', 'archived', 'all'] as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                    </form>
                </div>
                <?php if (empty($decisions)): ?>
                    <div class="empty-state"><p>No saved decisions match this filter. Create one manually or accept a suggested decision below.</p></div>
                <?php else: ?>
                    <div class="marketing-decisions-list">
                        <?php foreach ($decisions as $decision): ?>
                            <?php
                                $decisionTooltipParts = array_filter([
                                    (string) ($decision['recommended_action'] ?? ''),
                                    (string) ($decision['rationale'] ?? ''),
                                    (string) ($decision['expected_impact'] ?? ''),
                                ]);
                                $decisionTooltip = implode(' ', $decisionTooltipParts);
                                if ($decisionTooltip === '') {
                                    $decisionTooltip = 'Review this decision and choose the safest next move.';
                                }
                            ?>
                            <div class="marketing-decision-card" data-tooltip="<?php echo htmlspecialchars($decisionTooltip); ?>" tabindex="0">
                                <div class="marketing-decision-visual" aria-hidden="true">
                                    <i class="fas fa-scale-balanced"></i>
                                </div>
                                <div class="marketing-decision-body">
                                    <div class="marketing-decision-title-row">
                                        <strong><?php echo htmlspecialchars((string) $decision['title']); ?></strong>
                                        <span class="badge <?php echo htmlspecialchars($statusClass((string) $decision['decision_status'])); ?>"><?php echo htmlspecialchars($labelize((string) $decision['decision_status'])); ?></span>
                                        <span class="badge <?php echo htmlspecialchars($priorityClass((string) $decision['priority'])); ?>"><?php echo htmlspecialchars($labelize((string) $decision['priority'])); ?></span>
                                    </div>
                                    <div class="marketing-decision-meta">
                                        <span><?php echo htmlspecialchars($labelize((string) $decision['decision_type'])); ?></span>
                                        <?php if (!empty($decision['campaign_name'])): ?><span><?php echo htmlspecialchars((string) $decision['campaign_name']); ?></span><?php endif; ?>
                                        <?php if (!empty($decision['due_at'])): ?><span>Due <?php echo htmlspecialchars((string) $decision['due_at']); ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($decision['recommended_action'])): ?><p><?php echo htmlspecialchars((string) $decision['recommended_action']); ?></p><?php endif; ?>
                                    <?php if (!empty($decision['decision_note'])): ?><small>Note: <?php echo htmlspecialchars((string) $decision['decision_note']); ?></small><?php endif; ?>
                                </div>
                                <div class="marketing-decision-actions">
                                    <?php if ($canWriteMarketing && in_array((string) $decision['decision_status'], ['open', 'deferred'], true)): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="decide_decision">
                                            <input type="hidden" name="decision_id" value="<?php echo (int) $decision['id']; ?>">
                                            <input type="hidden" name="decision_status" value="accepted">
                                            <button class="btn-premium-primary" type="submit">Accept</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canWriteMarketing || ($canManageMarketing && (string) $decision['decision_status'] !== 'archived')): ?>
                                        <details class="marketing-decision-more">
                                            <summary>More choices</summary>
                                            <div>
                                                <?php if ($canWriteMarketing && in_array((string) $decision['decision_status'], ['open', 'deferred'], true)): ?>
                                                    <?php foreach (['rejected' => 'Reject', 'deferred' => 'Defer'] as $status => $label): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                            <input type="hidden" name="action" value="decide_decision">
                                                            <input type="hidden" name="decision_id" value="<?php echo (int) $decision['id']; ?>">
                                                            <input type="hidden" name="decision_status" value="<?php echo htmlspecialchars($status); ?>">
                                                            <button class="btn-premium-secondary" type="submit"><?php echo htmlspecialchars($label); ?></button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing && (string) $decision['decision_status'] !== 'archived'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="archive_decision">
                                                        <input type="hidden" name="decision_id" value="<?php echo (int) $decision['id']; ?>">
                                                        <button class="btn-premium-secondary" type="submit">Archive</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card marketing-decisions-suggestions" id="suggested-decisions">
                <div class="premium-section-header"><div><h2>Suggested Decisions</h2><p>System-backed choices you can save into the board.</p></div></div>
                <?php if (empty($suggestedDecisions)): ?>
                    <div class="empty-state"><p>No suggested decisions right now. Saved decisions and launch evidence are clear.</p></div>
                <?php else: ?>
                    <div class="marketing-decisions-list">
                        <?php foreach ($suggestedDecisions as $suggestion): ?>
                            <?php
                                $suggestionTooltip = trim((string) ($suggestion['rationale'] ?? '') . ' ' . (string) ($suggestion['expected_impact'] ?? ''));
                                if ($suggestionTooltip === '') {
                                    $suggestionTooltip = 'Save this suggestion when it should become a tracked decision.';
                                }
                            ?>
                            <div class="marketing-decision-card suggested" data-tooltip="<?php echo htmlspecialchars($suggestionTooltip); ?>" tabindex="0">
                                <div class="marketing-decision-visual" aria-hidden="true">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                </div>
                                <div class="marketing-decision-body">
                                    <div class="marketing-decision-title-row">
                                        <strong><?php echo htmlspecialchars((string) $suggestion['title']); ?></strong>
                                        <span class="badge <?php echo htmlspecialchars($priorityClass((string) $suggestion['priority'])); ?>"><?php echo htmlspecialchars($labelize((string) $suggestion['priority'])); ?></span>
                                    </div>
                                    <div class="marketing-decision-meta"><span><?php echo htmlspecialchars($labelize((string) $suggestion['decision_type'])); ?></span></div>
                                    <p><?php echo htmlspecialchars((string) ($suggestion['recommended_action'] ?? 'Review this decision.')); ?></p>
                                </div>
                                <div class="marketing-decision-actions">
                                    <?php if ($canWriteMarketing): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="create_decision">
                                            <input type="hidden" name="title" value="<?php echo htmlspecialchars((string) $suggestion['title']); ?>">
                                            <input type="hidden" name="decision_type" value="<?php echo htmlspecialchars((string) $suggestion['decision_type']); ?>">
                                            <input type="hidden" name="priority" value="<?php echo htmlspecialchars((string) $suggestion['priority']); ?>">
                                            <input type="hidden" name="source_type" value="<?php echo htmlspecialchars((string) ($suggestion['source_type'] ?? 'custom')); ?>">
                                            <input type="hidden" name="campaign_id" value="<?php echo (int) ($suggestion['campaign_id'] ?? 0); ?>">
                                            <input type="hidden" name="recommended_action" value="<?php echo htmlspecialchars((string) ($suggestion['recommended_action'] ?? '')); ?>">
                                            <input type="hidden" name="rationale" value="<?php echo htmlspecialchars((string) ($suggestion['rationale'] ?? '')); ?>">
                                            <input type="hidden" name="expected_impact" value="<?php echo htmlspecialchars((string) ($suggestion['expected_impact'] ?? '')); ?>">
                                            <button class="btn-premium-primary" type="submit">Save Decision</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <aside class="marketing-decisions-side">
            <div class="content-card marketing-decisions-today">
                <div class="premium-section-header"><div><h2>Today</h2><p>Keep the decision work procedural.</p></div></div>
                <div class="marketing-decisions-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <a class="marketing-decisions-today-action <?php echo htmlspecialchars((string) $action['priority']); ?>" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['tooltip']); ?>" tabindex="0">
                            <strong><?php echo htmlspecialchars((string) $action['label']); ?></strong>
                            <span><?php echo htmlspecialchars((string) $action['detail']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="content-card marketing-decisions-create" id="create-decision">
            <div class="premium-section-header"><div><h2>Create Decision</h2><p>Capture one choice that needs a human call.</p></div></div>
            <?php if (!$canWriteMarketing): ?>
                <div class="empty-state"><p>You can view Marketing decisions, but you do not have permission to create or update them.</p></div>
            <?php else: ?>
                <form class="marketing-decisions-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="create_decision">
                    <label>Title <input class="form-control" name="title" required placeholder="Decide launch path for Q3 nurture campaign"></label>
                    <label>Decision type
                        <select class="form-control" name="decision_type">
                            <?php foreach (Marketing::DECISION_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Priority
                        <select class="form-control" name="priority">
                            <?php foreach (Marketing::DECISION_PRIORITIES as $priority): ?><option value="<?php echo htmlspecialchars($priority); ?>"><?php echo htmlspecialchars($labelize($priority)); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Linked campaign
                        <select class="form-control" name="campaign_id">
                            <option value="">No campaign</option>
                            <?php foreach ($campaigns as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>"><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Due date <input class="form-control" type="datetime-local" name="due_at"></label>
                    <label>Recommended action <textarea class="form-control" name="recommended_action" placeholder="What should the operator decide or approve?"></textarea></label>
                    <label>Rationale <textarea class="form-control" name="rationale" placeholder="Why does this decision matter now?"></textarea></label>
                    <label>Expected impact <textarea class="form-control" name="expected_impact" placeholder="What gets better once this is decided?"></textarea></label>
                    <button class="btn-premium-primary" type="submit">Create Decision</button>
                </form>
            <?php endif; ?>
            </div>

            <details class="marketing-decisions-tools">
                <summary>More decision tools</summary>
                <div class="marketing-decisions-tools-body">
                    <div class="marketing-decisions-link-grid">
                        <?php foreach ($advancedLinks as $link): ?>
                            <a class="marketing-decisions-tool-link" href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>" tabindex="0">
                                <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="marketing-decisions-boundary">
                        <strong>Manual-first boundary</strong>
                        <span>Decisions do not send email, publish content, start ads, or call external channel APIs.</span>
                    </div>
                </div>
            </details>
        </aside>
    </div>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
