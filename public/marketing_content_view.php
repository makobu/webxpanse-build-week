<?php
/**
 * Marketing content detail view.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

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
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_status') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update marketing content.');
            }
            $marketing->updateContentItem($id, ['status' => (string) ($_POST['status'] ?? '')]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=status');
            exit;
        }
        if ($action === 'update_production') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update marketing production.');
            }
            $marketing->updateContentProduction($id, [
                'production_stage' => $_POST['production_stage'] ?? '',
                'production_due_at' => $_POST['production_due_at'] ?? '',
                'dependency_status' => $_POST['dependency_status'] ?? '',
                'dependency_notes' => $_POST['dependency_notes'] ?? '',
                'production_checklist' => $_POST['production_checklist'] ?? '',
                'blocked_reason' => $_POST['blocked_reason'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=production');
            exit;
        }
        if ($action === 'add_dependency') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to add production dependencies.');
            }
            $marketing->createContentDependency([
                'content_item_id' => $id,
                'depends_on_content_item_id' => (int) ($_POST['depends_on_content_item_id'] ?? 0),
                'dependency_type' => $_POST['dependency_type'] ?? 'other',
                'title' => $_POST['dependency_title'] ?? '',
                'status' => $_POST['dependency_item_status'] ?? 'waiting',
                'due_at' => $_POST['dependency_due_at'] ?? '',
                'owner_user_id' => (int) ($_POST['dependency_owner_user_id'] ?? 0),
                'notes' => $_POST['dependency_notes_item'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=dependency');
            exit;
        }
        if ($action === 'update_dependency') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update production dependencies.');
            }
            $marketing->updateContentDependency((int) ($_POST['dependency_id'] ?? 0), [
                'status' => $_POST['dependency_item_status'] ?? 'waiting',
                'due_at' => $_POST['dependency_due_at'] ?? '',
                'owner_user_id' => (int) ($_POST['dependency_owner_user_id'] ?? 0),
                'notes' => $_POST['dependency_notes_item'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=dependency');
            exit;
        }
        if ($action === 'delete_dependency') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete production dependencies.');
            }
            $marketing->deleteContentDependency((int) ($_POST['dependency_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=dependency');
            exit;
        }
        if ($action === 'request_review') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to request review.');
            }
            $reviewerId = (int) ($_POST['reviewer_user_id'] ?? 0);
            $marketing->requestContentReview($id, (int) ($user['id'] ?? 0), $reviewerId > 0 ? $reviewerId : null, (string) ($_POST['review_due_at'] ?? ''));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=review');
            exit;
        }
        if ($action === 'add_comment') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to comment on marketing content.');
            }
            $marketing->addContentComment($id, (string) ($_POST['body'] ?? ''), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=comment');
            exit;
        }
        if ($action === 'resolve_comment') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to resolve comments.');
            }
            $marketing->resolveContentComment((int) ($_POST['comment_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=comment');
            exit;
        }
        if ($action === 'content_tool') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to run content tools.');
            }
            $marketing->runContentTool($id, (string) ($_POST['tool_action'] ?? ''), [
                'channel' => $_POST['tool_channel'] ?? '',
                'channels' => $_POST['tool_channels'] ?? '',
                'tone' => $_POST['tool_tone'] ?? '',
                'cta' => $_POST['tool_cta'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=tool');
            exit;
        }
        if ($action === 'apply_content_tool_run') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to apply content tool suggestions.');
            }
            $marketing->applyContentToolRun((int) ($_POST['tool_run_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=tool_applied');
            exit;
        }
        if ($action === 'detach_media') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to detach content media.');
            }
            $marketing->detachContentMedia((int) ($_POST['attachment_id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=media');
            exit;
        }
        if ($action === 'ai_creative') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to run AI creative tools.');
            }
            $postItem = $marketing->getContentItem($id) ?: [];
            $marketing->runAiCreativeAction((string) ($_POST['creative_action'] ?? ''), [
                'content_item_id' => $id,
                'media_file_id' => (int) ($_POST['media_file_id'] ?? 0),
                'channel' => $postItem['channel'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=creative');
            exit;
        }
        if ($action === 'decide_approval') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to approve marketing content.');
            }
            $marketing->decideContentApproval((int) ($_POST['approval_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['decision_note'] ?? ''), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=approval');
            exit;
        }
        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete marketing content.');
            }
            $marketing->deleteContentItem($id);
            header('Location: ' . getBasePath() . '/marketing_content.php?success=deleted');
            exit;
        }
        if ($action === 'archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive marketing content.');
            }
            $marketing->updateContentItem($id, ['status' => 'archived', 'change_summary' => 'Content archived']);
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=status');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$item = $id > 0 ? $marketing->getContentItem($id) : null;
if (!$item) {
    http_response_code(404);
    echo 'Marketing content item not found.';
    exit;
}

if (($_GET['success'] ?? '') === 'saved') {
    $notice = 'Marketing content saved.';
} elseif (($_GET['success'] ?? '') === 'status') {
    $notice = 'Marketing content status updated.';
} elseif (($_GET['success'] ?? '') === 'review') {
    $notice = 'Marketing content sent for review.';
} elseif (($_GET['success'] ?? '') === 'comment') {
    $notice = 'Comment thread updated.';
} elseif (($_GET['success'] ?? '') === 'approval') {
    $notice = 'Marketing approval updated.';
} elseif (($_GET['success'] ?? '') === 'tool') {
    $notice = 'Content tool completed. Review the saved suggestion, score, or distribution variants before taking the next step.';
} elseif (($_GET['success'] ?? '') === 'tool_applied') {
    $notice = 'Content tool suggestion applied to the draft and recorded as a new version.';
} elseif (($_GET['success'] ?? '') === 'media') {
    $notice = 'Content media attachments updated.';
} elseif (($_GET['success'] ?? '') === 'creative') {
    $notice = 'AI creative suggestion saved for review. No draft or media record was overwritten.';
} elseif (($_GET['success'] ?? '') === 'production') {
    $notice = 'Content production workflow updated.';
} elseif (($_GET['success'] ?? '') === 'dependency') {
    $notice = 'Production dependencies updated.';
}

$comments = $marketing->listContentComments((int) $item['id']);
$versions = $marketing->listContentVersions((int) $item['id']);
$approvals = $marketing->listContentApprovals(['content_item_id' => (int) $item['id']], 20, 0);
$toolRuns = $marketing->listContentToolRuns(['content_item_id' => (int) $item['id']], 12, 0);
$creativeRuns = $marketing->listAiCreativeRuns(['content_item_id' => (int) $item['id']], 8, 0);
$dependencies = $marketing->listContentDependencies((int) $item['id']);
$productionEvents = $marketing->listContentProductionEvents((int) $item['id'], 12, 0);
$options = $marketing->optionData();
$warnings = (array) ($item['required_context_warnings_json'] ?? []);
$mediaWarnings = (array) ($item['_media_readiness']['warnings'] ?? []);

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$valueOrDash = static fn($value): string => trim((string) $value) !== '' ? (string) $value : '-';
$renderMedia = static function (array $attachment, Marketing $marketing): string {
    $url = (string) ($attachment['display_url'] ?? '');
    if ($url === '') {
        return '';
    }
    $caption = htmlspecialchars((string) ($attachment['effective_caption'] ?? ''));
    if ((string) ($attachment['media_type'] ?? '') === 'video') {
        $html = '<figure class="content-media-preview"><video controls preload="metadata" src="' . htmlspecialchars($url) . '"></video>';
    } else {
        $alt = htmlspecialchars((string) ($attachment['effective_alt_text'] ?? $attachment['media_title'] ?? 'Content media'));
        $html = '<figure class="content-media-preview"><img src="' . htmlspecialchars($url) . '" alt="' . $alt . '">';
    }
    if ($caption !== '') {
        $html .= '<figcaption>' . $caption . '</figcaption>';
    }

    return $html . '</figure>';
};
$statusClass = static function (string $status): string {
    $normalized = strtolower(str_replace(' ', '_', $status));
    if (in_array($normalized, ['ready', 'approved', 'published', 'complete', 'completed', 'clear'], true)) {
        return 'ready';
    }
    if (in_array($normalized, ['blocked', 'rejected', 'overdue', 'missing'], true)) {
        return 'blocked';
    }

    return 'attention';
};
$readinessScore = max(0, min(100, (int) ($item['readiness_score'] ?? 0)));
$productionScore = max(0, min(100, (int) ($item['production_score'] ?? 0)));
$mediaCount = count((array) ($item['_media'] ?? []));
$openComments = count(array_filter($comments, static fn(array $comment): bool => empty($comment['resolved_at'])));
$pendingApprovals = count(array_filter($approvals, static fn(array $approval): bool => (string) ($approval['status'] ?? '') === 'pending'));
$approvedApprovals = count(array_filter($approvals, static fn(array $approval): bool => (string) ($approval['status'] ?? '') === 'approved'));
$openDependencies = count(array_filter($dependencies, static function (array $dependency): bool {
    return !in_array((string) ($dependency['status'] ?? ''), ['done', 'complete', 'completed', 'approved', 'resolved'], true);
}));
$productionIsBlocked = !empty($item['blocked_reason']) || ((string) ($item['dependency_status'] ?? 'clear') !== 'clear') || $openDependencies > 0;
$draftReady = trim((string) ($item['draft_body'] ?? '')) !== '';
$reviewLabel = $pendingApprovals > 0 ? $pendingApprovals . ' pending' : ($approvedApprovals > 0 ? 'Approved' : 'Not requested');
$mediaLabel = $mediaCount > 0 ? $mediaCount . ' attached' : 'No media';
$contentSummaryTiles = [
    ['icon' => 'fa-circle-check', 'label' => 'Status', 'value' => $labelize((string) ($item['status'] ?? 'draft')), 'tooltip' => 'Current publishing state for this content item.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => $readinessScore . '%', 'tooltip' => 'How much of the needed campaign and audience context is present.'],
    ['icon' => 'fa-list-check', 'label' => 'Production', 'value' => $productionScore . '%', 'tooltip' => 'Progress through production checks, dependencies, and blockers.'],
    ['icon' => 'fa-clipboard-check', 'label' => 'Review', 'value' => $reviewLabel, 'tooltip' => 'Approval or review activity for the current draft.'],
    ['icon' => 'fa-photo-film', 'label' => 'Media', 'value' => $mediaLabel, 'tooltip' => 'Attached images or videos and their readiness warnings.'],
];
$contentCards = [
    [
        'icon' => 'fa-file-lines',
        'title' => 'Review Draft',
        'status' => $draftReady ? 'ready' : 'attention',
        'metric' => $draftReady ? 'Draft available' : 'Needs copy',
        'action' => $canWriteMarketing ? 'Edit Draft' : 'Read Draft',
        'href' => $canWriteMarketing ? 'marketing_content_edit.php?id=' . (int) $item['id'] : '#draft-body',
        'tooltip' => 'Read or edit the working copy before production moves forward.',
    ],
    [
        'icon' => 'fa-diagram-project',
        'title' => 'Clear Production',
        'status' => $productionIsBlocked ? 'blocked' : ($productionScore >= 70 ? 'ready' : 'attention'),
        'metric' => $productionIsBlocked ? $openDependencies . ' blockers' : $productionScore . '% ready',
        'action' => 'Open Evidence',
        'href' => '#content-evidence',
        'tooltip' => 'Check production stage, dependency health, blockers, and next action evidence.',
    ],
    [
        'icon' => 'fa-image',
        'title' => 'Check Media',
        'status' => !empty($mediaWarnings) ? 'attention' : ($mediaCount > 0 ? 'ready' : 'attention'),
        'metric' => $mediaLabel,
        'action' => 'Check Media',
        'href' => '#content-media',
        'tooltip' => 'Review attached media, captions, alt text, crop notes, and creative suggestions.',
    ],
    [
        'icon' => 'fa-user-check',
        'title' => 'Ask For Review',
        'status' => $pendingApprovals > 0 ? 'attention' : ($approvedApprovals > 0 ? 'ready' : 'attention'),
        'metric' => $reviewLabel,
        'action' => 'Request Review',
        'href' => '#content-evidence',
        'tooltip' => 'Send the draft to a reviewer once the core copy is ready.',
    ],
    [
        'icon' => 'fa-wand-magic-sparkles',
        'title' => 'Use Content Tools',
        'status' => count($toolRuns) > 0 ? 'ready' : 'attention',
        'metric' => count($toolRuns) . ' saved runs',
        'action' => 'Open Tools',
        'href' => '#content-tools',
        'tooltip' => 'Use AI-assisted draft, rewrite, SEO, CTA, and variant tools without changing the draft automatically.',
    ],
    [
        'icon' => 'fa-comments',
        'title' => 'Close Feedback',
        'status' => $openComments > 0 || $pendingApprovals > 0 ? 'attention' : 'ready',
        'metric' => $openComments . ' open notes',
        'action' => 'Review Notes',
        'href' => '#content-review',
        'tooltip' => 'Read comments, approvals, saved versions, and management controls.',
    ],
];
$todayActions = array_slice([
    ['icon' => 'fa-pen', 'label' => $canWriteMarketing ? 'Edit draft' : 'Read draft', 'href' => $canWriteMarketing ? 'marketing_content_edit.php?id=' . (int) $item['id'] : '#draft-body', 'tooltip' => 'Open the working content draft.'],
    ['icon' => 'fa-clipboard-check', 'label' => 'Request review', 'href' => '#content-evidence', 'tooltip' => 'Open the review request controls.'],
    ['icon' => 'fa-list-check', 'label' => 'Clear blockers', 'href' => '#content-evidence', 'tooltip' => 'Review production checks and dependency evidence.'],
    ['icon' => 'fa-photo-film', 'label' => 'Check media', 'href' => '#content-media', 'tooltip' => 'Review attached media and creative suggestions.'],
    ['icon' => 'fa-wand-magic-sparkles', 'label' => 'Use tools', 'href' => '#content-tools', 'tooltip' => 'Open content creation and rewrite tools.'],
], 0, 5);
$pageTitle = (string) $item['title'] . ' - Marketing - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/marketing-ui.css">

<div class="page-premium marketing-ui-page marketing-content-view-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1><?php echo htmlspecialchars((string) $item['title']); ?></h1>
                <p>Review the draft, clear blockers, and move it forward.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_content_edit.php?id=<?php echo (int) $item['id']; ?>"><i class="fas fa-edit"></i> Edit</a><?php endif; ?>
                <a class="btn-premium-secondary" href="marketing_content.php"><i class="fas fa-arrow-left"></i> Content Studio</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <section class="marketing-content-view-summary" aria-label="Content review summary">
            <?php foreach ($contentSummaryTiles as $tile): ?>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tile['tooltip']); ?>">
                    <i class="fas <?php echo htmlspecialchars($tile['icon']); ?>" aria-hidden="true"></i>
                    <div><span><?php echo htmlspecialchars($tile['label']); ?></span><strong><?php echo htmlspecialchars($tile['value']); ?></strong></div>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="marketing-content-view-layout">
            <main class="marketing-content-view-main">
                <section class="content-card marketing-content-view-board">
                    <div class="premium-section-header"><div><h2>Content Review Board</h2><p>One clear path from draft to ready.</p></div></div>
                    <div class="marketing-content-view-card-grid">
                        <?php foreach ($contentCards as $card): ?>
                            <article class="marketing-content-view-card <?php echo htmlspecialchars($statusClass((string) $card['status'])); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($card['tooltip']); ?>">
                                <div class="marketing-content-view-visual"><i class="fas <?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i></div>
                                <div class="marketing-content-view-card-body">
                                    <div class="marketing-content-view-card-title"><strong><?php echo htmlspecialchars($card['title']); ?></strong><span class="marketing-content-view-status <?php echo htmlspecialchars($statusClass((string) $card['status'])); ?>"><?php echo htmlspecialchars($labelize((string) $card['status'])); ?></span></div>
                                    <span><?php echo htmlspecialchars($card['metric']); ?></span>
                                </div>
                                <a class="btn-premium-primary marketing-content-view-card-action" href="<?php echo htmlspecialchars($card['href']); ?>"><?php echo htmlspecialchars($card['action']); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </main>
            <aside class="marketing-content-view-side">
                <section class="content-card marketing-content-view-today">
                    <div class="premium-section-header"><div><h2>Today</h2><p>Keep the next move small.</p></div></div>
                    <div class="marketing-content-view-today-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-content-view-today-action" href="<?php echo htmlspecialchars($action['href']); ?>" data-tooltip="<?php echo htmlspecialchars($action['tooltip']); ?>">
                                <i class="fas <?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
                                <strong><?php echo htmlspecialchars($action['label']); ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>

        <details class="marketing-content-view-tools" id="content-evidence">
            <summary>More content evidence</summary>
            <div class="marketing-content-view-tools-body">

        <div class="content-card marketing-content-view-section">
            <div class="marketing-detail-grid">
                <div class="marketing-detail-card"><div class="marketing-detail-label">Status</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) $item['status'])); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Funnel Stage</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize($valueOrDash($item['funnel_stage'] ?? ''))); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Owner</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['owner_email'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Reviewer</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['reviewer_email'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Review Due</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['review_due_at'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Readiness</div><div class="marketing-detail-value"><?php echo (int) ($item['readiness_score'] ?? 0); ?>%</div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Production Stage</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) ($item['production_stage'] ?? 'idea'))); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Production Due</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['production_due_at'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Production Score</div><div class="marketing-detail-value"><?php echo (int) ($item['production_score'] ?? 0); ?>%</div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Audience</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['target_audience'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Scheduled</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['scheduled_at'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Published</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['published_at'] ?? '')); ?></div></div>
            </div>

            <?php if (!empty($item['blocked_reason'])): ?><div class="alert alert-warning">Blocked: <?php echo htmlspecialchars((string) $item['blocked_reason']); ?></div><?php endif; ?>
            <?php if (($item['dependency_status'] ?? 'clear') !== 'clear'): ?><div class="alert alert-warning">Dependencies: <?php echo htmlspecialchars($labelize((string) $item['dependency_status'])); ?><?php if (!empty($item['dependency_notes'])): ?> - <?php echo htmlspecialchars((string) $item['dependency_notes']); ?><?php endif; ?></div><?php endif; ?>
            <?php if (!empty($item['next_action'])): ?><div class="alert alert-info">Next action: <?php echo htmlspecialchars((string) $item['next_action']); ?></div><?php endif; ?>
            <?php if (!empty($warnings)): ?><div class="alert alert-warning">Required context warnings: <?php echo htmlspecialchars(implode(', ', array_map($labelize, $warnings))); ?></div><?php endif; ?>
            <?php if (!empty($mediaWarnings)): ?><div class="alert alert-warning">Media readiness: <?php echo htmlspecialchars(implode(', ', array_map($labelize, $mediaWarnings))); ?></div><?php endif; ?>

            <div class="marketing-detail-card marketing-content-view-section">
                <div class="marketing-detail-label">Objective</div>
                <div><?php echo htmlspecialchars($valueOrDash($item['objective'] ?? '')); ?></div>
            </div>

            <div class="marketing-detail-grid">
                <div class="marketing-detail-card">
                    <div class="marketing-detail-label">Campaign</div>
                    <div class="marketing-detail-value">
                        <?php if (!empty($item['campaign_id'])): ?><a href="campaign_view.php?id=<?php echo (int) $item['campaign_id']; ?>"><?php echo htmlspecialchars((string) ($item['campaign_name'] ?? 'Campaign #' . $item['campaign_id'])); ?></a><?php else: ?>-<?php endif; ?>
                    </div>
                </div>
                <div class="marketing-detail-card">
                    <div class="marketing-detail-label">Campaign Brief</div>
                    <div class="marketing-detail-value">
                        <?php if (!empty($item['campaign_brief_id'])): ?><a href="marketing_brief_view.php?id=<?php echo (int) $item['campaign_brief_id']; ?>"><?php echo htmlspecialchars((string) ($item['campaign_brief_title'] ?? 'Brief #' . $item['campaign_brief_id'])); ?></a><?php else: ?>-<?php endif; ?>
                    </div>
                </div>
                <div class="marketing-detail-card">
                    <div class="marketing-detail-label">Form</div>
                    <div class="marketing-detail-value">
                        <?php if (!empty($item['form_id'])): ?><a href="form_submissions.php?form_id=<?php echo (int) $item['form_id']; ?>"><?php echo htmlspecialchars((string) ($item['form_name'] ?? 'Form #' . $item['form_id'])); ?></a><?php else: ?>-<?php endif; ?>
                    </div>
                </div>
                <div class="marketing-detail-card">
                    <div class="marketing-detail-label">Email Template / Task</div>
                    <div class="marketing-detail-value">
                        <?php echo htmlspecialchars($valueOrDash($item['email_template_name'] ?? $item['task_title'] ?? '')); ?>
                    </div>
                </div>
            </div>

            <div class="marketing-detail-grid marketing-content-view-spaced">
                <div class="marketing-detail-card"><div class="marketing-detail-label">Brand Profile</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['brand_profile_name'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Persona</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['persona_name'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">SEO Topic</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['seo_topic_keyword'] ?? '')); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Landing Page</div><div class="marketing-detail-value"><?php if (!empty($item['landing_page_id'])): ?><a href="marketing_landing_page_view.php?id=<?php echo (int) $item['landing_page_id']; ?>"><?php echo htmlspecialchars((string) ($item['landing_page_title'] ?? 'Landing Page #' . $item['landing_page_id'])); ?></a><?php else: ?>-<?php endif; ?></div></div>
            </div>

            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="marketing-content-view-form-row marketing-content-view-spaced">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div class="form-group marketing-content-view-field">
                        <label>Status</label>
                        <select name="status">
                            <?php foreach (Marketing::STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($item['status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn-premium-primary" type="submit">Update Status</button>
                </form>
                <form method="POST" class="marketing-content-view-subform">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <input type="hidden" name="action" value="request_review">
                    <div class="marketing-content-view-form-row marketing-content-view-form-row-block">
                        <div class="form-group marketing-content-view-field">
                            <label>Reviewer</label>
                            <select name="reviewer_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($options['users'] as $reviewer): ?><option value="<?php echo (int) $reviewer['id']; ?>"><?php echo htmlspecialchars((string) $reviewer['email']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group marketing-content-view-field">
                            <label>Review Due</label>
                            <input type="datetime-local" name="review_due_at">
                        </div>
                    </div>
                    <button class="btn-premium-secondary" type="submit"><i class="fas fa-clipboard-check"></i> Request Review</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="content-card marketing-content-view-section">
            <div class="premium-section-header"><h2>Content Production Pipeline</h2></div>
            <div class="marketing-detail-grid">
                <div class="marketing-detail-card"><div class="marketing-detail-label">Stage</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) ($item['production_stage'] ?? 'idea'))); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Dependency Health</div><div class="marketing-detail-value"><?php echo htmlspecialchars($labelize((string) ($item['dependency_status'] ?? 'clear'))); ?></div></div>
                <div class="marketing-detail-card"><div class="marketing-detail-label">Next Action</div><div class="marketing-detail-value"><?php echo htmlspecialchars($valueOrDash($item['next_action'] ?? '')); ?></div></div>
            </div>
            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="marketing-content-view-spaced">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <input type="hidden" name="action" value="update_production">
                    <div class="marketing-detail-grid">
                        <div class="form-group">
                            <label>Production Stage</label>
                            <select name="production_stage">
                                <?php foreach (Marketing::CONTENT_PRODUCTION_STAGES as $stage): ?><option value="<?php echo htmlspecialchars($stage); ?>" <?php echo $selected($item['production_stage'] ?? 'idea', $stage); ?>><?php echo htmlspecialchars($labelize($stage)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Production Due</label>
                            <input type="datetime-local" name="production_due_at" value="<?php echo !empty($item['production_due_at']) ? htmlspecialchars(str_replace(' ', 'T', substr((string) $item['production_due_at'], 0, 16))) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Dependency Health</label>
                            <select name="dependency_status">
                                <?php foreach (Marketing::CONTENT_DEPENDENCY_HEALTH_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($item['dependency_status'] ?? 'clear', $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="marketing-detail-grid">
                        <div class="form-group">
                            <label>Production Checklist</label>
                            <textarea name="production_checklist" rows="4"><?php echo htmlspecialchars(implode("\n", (array) ($item['production_checklist_json'] ?? []))); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Dependency Notes</label>
                            <textarea name="dependency_notes" rows="4"><?php echo htmlspecialchars((string) ($item['dependency_notes'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Blocked Reason</label>
                            <textarea name="blocked_reason" rows="4"><?php echo htmlspecialchars((string) ($item['blocked_reason'] ?? '')); ?></textarea>
                        </div>
                    </div>
                    <button class="btn-premium-primary" type="submit">Update Production Workflow</button>
                </form>
            <?php endif; ?>

            <div class="marketing-content-view-spaced">
                <div class="premium-section-header"><h2>Dependencies</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST" class="marketing-content-view-form-grid marketing-content-view-section">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="action" value="add_dependency">
                        <div class="form-group marketing-content-view-field"><label>Title</label><input type="text" name="dependency_title" required placeholder="Example: Final product screenshot"></div>
                        <div class="form-group marketing-content-view-field"><label>Type</label><select name="dependency_type"><?php foreach (Marketing::CONTENT_DEPENDENCY_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group marketing-content-view-field"><label>Status</label><select name="dependency_item_status"><?php foreach (Marketing::CONTENT_DEPENDENCY_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group marketing-content-view-field"><label>Due</label><input type="datetime-local" name="dependency_due_at"></div>
                        <div class="form-group marketing-content-view-field"><label>Owner</label><select name="dependency_owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>"><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group marketing-content-view-field"><label>Depends On Content</label><select name="depends_on_content_item_id"><option value="">No content dependency</option><?php foreach ($marketing->listContentItems(['exclude_status' => 'archived'], 50, 0) as $candidate): if ((int) $candidate['id'] === (int) $item['id']) { continue; } ?><option value="<?php echo (int) $candidate['id']; ?>"><?php echo htmlspecialchars((string) $candidate['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group marketing-content-view-field marketing-content-view-span-2"><label>Notes</label><input type="text" name="dependency_notes_item" placeholder="What must happen before production can move?"></div>
                        <button class="btn-premium-secondary" type="submit">Add Dependency</button>
                    </form>
                <?php endif; ?>
                <?php if (empty($dependencies)): ?>
                    <div class="empty-state"><p>No production dependencies are tracked for this content item.</p></div>
                <?php else: ?>
                    <table class="premium-table">
                        <thead><tr><th>Dependency</th><th>Status</th><th>Due</th><th>Owner</th><th>Notes</th><?php if ($canWriteMarketing): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($dependencies as $dependency): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string) $dependency['title']); ?></strong><div class="marketing-content-view-muted"><?php echo htmlspecialchars($labelize((string) $dependency['dependency_type'])); ?><?php if (!empty($dependency['depends_on_content_title'])): ?> - waits on <?php echo htmlspecialchars((string) $dependency['depends_on_content_title']); ?><?php endif; ?></div></td>
                                <td><span class="badge badge-default"><?php echo htmlspecialchars($labelize((string) $dependency['status'])); ?></span></td>
                                <td><?php echo htmlspecialchars($valueOrDash($dependency['due_at'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($valueOrDash($dependency['owner_email'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($valueOrDash($dependency['notes'] ?? '')); ?></td>
                                <?php if ($canWriteMarketing): ?>
                                    <td>
                                        <form method="POST" class="marketing-content-view-form-row marketing-content-view-form-row-compact">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="dependency_id" value="<?php echo (int) $dependency['id']; ?>">
                                            <input type="hidden" name="action" value="update_dependency">
                                            <select name="dependency_item_status"><?php foreach (Marketing::CONTENT_DEPENDENCY_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($dependency['status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select>
                                            <input type="datetime-local" name="dependency_due_at" value="<?php echo !empty($dependency['due_at']) ? htmlspecialchars(str_replace(' ', 'T', substr((string) $dependency['due_at'], 0, 16))) : ''; ?>">
                                            <select name="dependency_owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($dependency['owner_user_id'] ?? '', $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select>
                                            <input type="text" name="dependency_notes_item" value="<?php echo htmlspecialchars((string) ($dependency['notes'] ?? '')); ?>" placeholder="Notes">
                                            <button class="btn-premium-secondary" type="submit">Update</button>
                                        </form>
                                        <?php if ($canManageMarketing): ?>
                                            <form method="POST" class="marketing-content-view-mini-form" onsubmit="return confirm('Delete this production dependency?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                                <input type="hidden" name="action" value="delete_dependency">
                                                <input type="hidden" name="dependency_id" value="<?php echo (int) $dependency['id']; ?>">
                                                <button class="btn-premium-secondary marketing-content-view-danger-button" type="submit">Delete Dependency</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="marketing-content-view-spaced">
                <div class="premium-section-header"><h2>Production History</h2></div>
                <?php if (empty($productionEvents)): ?>
                    <div class="empty-state"><p>No production events recorded yet.</p></div>
                <?php else: ?>
                    <?php foreach ($productionEvents as $event): ?>
                        <div class="marketing-detail-card marketing-content-view-card-gap">
                            <div class="marketing-detail-value"><?php echo htmlspecialchars((string) $event['summary']); ?></div>
                            <div class="marketing-detail-label"><?php echo htmlspecialchars($labelize((string) $event['event_type'])); ?> <?php echo htmlspecialchars((string) $event['created_at']); ?> <?php if (!empty($event['actor_email'])): ?>by <?php echo htmlspecialchars((string) $event['actor_email']); ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><h2 id="draft-body">Draft Body</h2></div>
            <?php if (trim((string) ($item['draft_body'] ?? '')) === ''): ?>
                <div class="empty-state"><p>No draft body yet.</p></div>
            <?php else: ?>
                <div class="marketing-draft"><?php echo htmlspecialchars((string) $item['draft_body']); ?></div>
            <?php endif; ?>
        </div>

        <div class="content-card marketing-content-view-spaced" id="content-media">
            <div class="premium-section-header"><h2>Attached Media</h2><?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_content_edit.php?id=<?php echo (int) $item['id']; ?>">Edit Media</a><?php endif; ?></div>
            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="marketing-content-view-form-row marketing-content-view-section">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <input type="hidden" name="action" value="ai_creative">
                    <div class="form-group marketing-content-view-field"><label>Creative Tool</label><select name="creative_action">
                        <option value="landing_visual_direction">Suggest Visuals</option>
                        <option value="image_prompt">Generate Image Prompt</option>
                        <option value="video_storyboard">Create Video Storyboard</option>
                        <option value="thumbnail_concept">Thumbnail Concept</option>
                        <option value="ad_creative_concept">Ad Creative Concept</option>
                        <option value="media_readiness_review">Review Media Readiness</option>
                    </select></div>
                    <div class="form-group marketing-content-view-field"><label>Media</label><select name="media_file_id"><option value="">Use attached/context</option><?php foreach ((array) $item['_media'] as $attachment): ?><option value="<?php echo (int) $attachment['media_file_id']; ?>"><?php echo htmlspecialchars((string) ($attachment['media_title'] ?? 'Media')); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group marketing-content-view-field"><label>Notes</label><input type="text" name="notes" placeholder="Optional creative direction"></div>
                    <button class="btn-premium-secondary" type="submit">Run Creative Tool</button>
                </form>
            <?php endif; ?>
            <?php if (empty($item['_media'])): ?>
                <div class="empty-state"><p>No media is attached to this content item yet.</p></div>
            <?php else: ?>
                <div class="content-media-grid">
                    <?php foreach ((array) $item['_media'] as $attachment): ?>
                        <div class="marketing-detail-card">
                            <div class="marketing-detail-label"><?php echo htmlspecialchars($labelize((string) ($attachment['role'] ?? 'attachment'))); ?> <?php if (!empty($attachment['channel'])): ?>- <?php echo htmlspecialchars($labelize((string) $attachment['channel'])); ?><?php endif; ?></div>
                            <div class="marketing-detail-value"><?php echo htmlspecialchars((string) ($attachment['media_title'] ?? 'Media')); ?></div>
                            <?php echo $renderMedia($attachment, $marketing); ?>
                            <?php if (!empty($attachment['placement_notes'])): ?><div class="marketing-content-view-note"><?php echo htmlspecialchars((string) $attachment['placement_notes']); ?></div><?php endif; ?>
                            <?php if (!empty($attachment['crop_guidance'])): ?><div class="marketing-detail-label marketing-content-view-note">Crop: <?php echo htmlspecialchars((string) $attachment['crop_guidance']); ?></div><?php endif; ?>
                            <?php if ($canWriteMarketing): ?>
                                <form method="POST" class="marketing-content-view-mini-form" onsubmit="return confirm('Detach this media from the content item?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="action" value="detach_media">
                                    <input type="hidden" name="attachment_id" value="<?php echo (int) $attachment['id']; ?>">
                                    <button class="btn-premium-secondary" type="submit">Detach</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="marketing-content-view-spaced">
                <div class="premium-section-header"><h2>AI Creative Runs</h2></div>
                <?php if (empty($creativeRuns)): ?>
                    <div class="empty-state"><p>No AI creative suggestions yet.</p></div>
                <?php else: ?>
                    <?php foreach ($creativeRuns as $run): $result = (array) ($run['result_json'] ?? []); ?>
                        <div class="tool-run">
                            <strong><?php echo htmlspecialchars($labelize((string) $run['action'])); ?></strong>
                            <span class="marketing-detail-label"><?php echo htmlspecialchars((string) $run['created_at']); ?> &middot; <?php echo htmlspecialchars((string) ($run['prompt_key'] ?? '')); ?></span>
                            <div class="tool-run-body"><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

            </div>
        </details>

        <details class="marketing-content-view-tools" id="content-review">
            <summary>Tools and review workflow</summary>
            <div class="marketing-content-view-tools-body">

        <div class="content-card marketing-content-view-spaced" id="content-tools">
            <div class="premium-section-header"><h2>Content Creation Tools</h2></div>
            <?php if ($canWriteMarketing): ?>
                <div class="tool-grid">
                    <?php foreach ([
                        'outline' => 'Outline',
                        'first_draft' => 'First Draft',
                        'shorten' => 'Shorten',
                        'expand' => 'Expand',
                        'add_cta' => 'Add CTA',
                        'seo_score' => 'SEO Score',
                        'brand_persona_score' => 'Brand/Persona Score',
                    ] as $toolAction => $toolLabel): ?>
                        <form class="tool-form" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                            <input type="hidden" name="action" value="content_tool">
                            <input type="hidden" name="tool_action" value="<?php echo htmlspecialchars($toolAction); ?>">
                            <?php if ($toolAction === 'add_cta'): ?><label>CTA</label><input type="text" name="tool_cta" placeholder="Book a demo"><?php endif; ?>
                            <button class="btn-premium-secondary" type="submit"><?php echo htmlspecialchars($toolLabel); ?></button>
                        </form>
                    <?php endforeach; ?>
                    <form class="tool-form" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="action" value="content_tool">
                        <input type="hidden" name="tool_action" value="rewrite_channel">
                        <label>Rewrite For</label>
                        <select name="tool_channel"><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select>
                        <button class="btn-premium-secondary" type="submit">Rewrite</button>
                    </form>
                    <form class="tool-form" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="action" value="content_tool">
                        <input type="hidden" name="tool_action" value="change_tone">
                        <label>Tone</label>
                        <input type="text" name="tool_tone" value="clear and confident">
                        <button class="btn-premium-secondary" type="submit">Change Tone</button>
                    </form>
                    <form class="tool-form" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="action" value="content_tool">
                        <input type="hidden" name="tool_action" value="repurpose">
                        <label>Channels</label>
                        <input type="text" name="tool_channels" value="linkedin, email, whatsapp">
                        <button class="btn-premium-primary" type="submit">Create Variants</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="empty-state"><p>Read-only access.</p></div>
            <?php endif; ?>

            <div class="marketing-content-view-spaced">
                <div class="premium-section-header"><h2>Recent Tool Runs</h2></div>
                <?php if (empty($toolRuns)): ?>
                    <div class="empty-state"><p>No content tool runs yet.</p></div>
                <?php else: ?>
                    <?php foreach ($toolRuns as $run): ?>
                        <div class="tool-run">
                            <strong><?php echo htmlspecialchars($labelize((string) $run['action'])); ?></strong>
                            <span class="marketing-detail-label"><?php echo htmlspecialchars($labelize((string) $run['status'])); ?> <?php echo htmlspecialchars((string) $run['created_at']); ?></span>
                            <?php $result = (array) ($run['result_json'] ?? []); ?>
                            <?php if (isset($result['score'])): ?><div>Score: <?php echo (int) $result['score']; ?>%</div><?php endif; ?>
                            <?php if (!empty($result['variants'])): ?><div><?php echo count((array) $result['variants']); ?> distribution variants created.</div><?php endif; ?>
                            <?php $suggestedBody = trim((string) ($result['body'] ?? '')); ?>
                            <?php if ($suggestedBody !== ''): ?>
                                <div class="tool-run-body"><?php echo htmlspecialchars($suggestedBody); ?></div>
                                <?php if ($canWriteMarketing && (string) $run['status'] === 'completed'): ?>
                                    <form class="tool-run-actions" method="POST" onsubmit="return confirm('Apply this saved suggestion to the working draft?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                        <input type="hidden" name="action" value="apply_content_tool_run">
                                        <input type="hidden" name="tool_run_id" value="<?php echo (int) $run['id']; ?>">
                                        <button class="btn-premium-primary" type="submit">Apply Suggestion</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($result['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string) $result['error']); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="marketing-detail-grid marketing-content-view-spaced">
            <div class="content-card">
                <div class="premium-section-header"><h2>Approvals</h2></div>
                <?php if (empty($approvals)): ?>
                    <div class="empty-state"><p>No approval requests yet.</p></div>
                <?php else: ?>
                    <?php foreach ($approvals as $approval): ?>
                        <div class="marketing-detail-card marketing-content-view-card-gap">
                            <div class="marketing-detail-label"><?php echo htmlspecialchars($labelize((string) $approval['status'])); ?></div>
                            <div>Requested by <?php echo htmlspecialchars($valueOrDash($approval['requested_by_email'] ?? '')); ?></div>
                            <?php if ($canManageMarketing && (string) $approval['status'] === 'pending'): ?>
                                <form method="POST" class="marketing-content-view-form-row marketing-content-view-subform">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="action" value="decide_approval">
                                    <input type="hidden" name="approval_id" value="<?php echo (int) $approval['id']; ?>">
                                    <input type="text" name="decision_note" placeholder="Optional note">
                                    <button class="btn-premium-primary" type="submit" name="decision" value="approved">Approve</button>
                                    <button class="btn-premium-secondary" type="submit" name="decision" value="rejected">Reject</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Comments</h2></div>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST" class="marketing-content-view-section">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <input type="hidden" name="action" value="add_comment">
                        <textarea name="body" rows="3" placeholder="Add review feedback or production notes."></textarea>
                        <button class="btn-premium-secondary marketing-content-view-subform" type="submit">Add Comment</button>
                    </form>
                <?php endif; ?>
                <?php if (empty($comments)): ?>
                    <div class="empty-state"><p>No comments yet.</p></div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="marketing-detail-card marketing-content-view-card-gap">
                            <div><?php echo htmlspecialchars((string) $comment['body']); ?></div>
                            <div class="marketing-detail-label marketing-content-view-note"><?php echo htmlspecialchars((string) ($comment['created_by_email'] ?? 'Team')); ?> <?php echo htmlspecialchars((string) $comment['created_at']); ?></div>
                            <?php if ($canWriteMarketing && empty($comment['resolved_at'])): ?>
                                <form method="POST" class="marketing-content-view-mini-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="action" value="resolve_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>">
                                    <button class="btn-premium-secondary" type="submit">Resolve</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Versions</h2></div>
                <?php if (empty($versions)): ?>
                    <div class="empty-state"><p>No saved versions yet.</p></div>
                <?php else: ?>
                    <?php foreach ($versions as $version): ?>
                        <div class="marketing-detail-card marketing-content-view-card-gap">
                            <div class="marketing-detail-value">Version <?php echo (int) $version['version_number']; ?></div>
                            <div><?php echo htmlspecialchars((string) ($version['change_summary'] ?? 'Content updated')); ?></div>
                            <div class="marketing-detail-label marketing-content-view-note"><?php echo htmlspecialchars($labelize((string) $version['status'])); ?> <?php echo htmlspecialchars((string) $version['created_at']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManageMarketing): ?>
            <div class="content-card marketing-content-view-danger marketing-content-view-spaced">
                <form method="POST" class="marketing-content-view-manage-form" onsubmit="return confirm('Apply this manage action to the marketing content item?');">
                    <div>
                        <strong>Manage Content</strong>
                        <div class="marketing-content-view-danger-text">Deleting removes this planning record only. Linked campaigns, forms, templates, and tasks stay intact.</div>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn-premium-secondary" type="submit" name="action" value="archive">Archive</button>
                    <button class="btn-premium-secondary marketing-content-view-danger-button" type="submit">Delete</button>
                </form>
            </div>
        <?php endif; ?>

            </div>
        </details>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
