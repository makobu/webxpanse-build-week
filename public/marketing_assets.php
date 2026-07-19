<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\MarketingMediaReadinessUi;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to save marketing assets.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $action = (string) ($_POST['action'] ?? 'create_asset');
        if ($action === 'media_governance') {
            if (!$canManageMarketing) { throw new RuntimeException('You do not have permission to govern marketing media.'); }
            $marketing->updateMediaGovernance((int) ($_POST['media_file_id'] ?? 0), $_POST, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_assets.php?success=governance'); exit;
        }
        if ($action === 'ai_creative') {
            $marketing->runAiCreativeAction((string) ($_POST['creative_action'] ?? ''), [
                'media_file_id' => (int) ($_POST['media_file_id'] ?? 0),
                'notes' => $_POST['notes'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_assets.php?success=creative'); exit;
        }
        if ($action === 'ai_media_generate') {
            $marketing->generateAiMediaRequest($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_assets.php?success=ai_media'); exit;
        }
        if ($action === 'ai_media_accept') {
            $marketing->acceptAiMediaOutput((int) ($_POST['output_id'] ?? 0), $_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            header('Location: ' . getBasePath() . '/marketing_assets.php?success=ai_media_accept'); exit;
        }
        if ($action === 'create_media') {
            $file = $_FILES['media_upload'] ?? [];
            $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasUpload) {
                $marketing->createMediaFileFromUpload($file, $_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            } else {
                $marketing->createMediaFile($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
            }
            header('Location: ' . getBasePath() . '/marketing_assets.php?success=media'); exit;
        }
        $marketing->createAsset($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_assets.php?success=asset'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$assets = $marketing->listAssets();
$mediaFiles = $marketing->listMediaFiles();
$mediaWorkflow = $marketing->getMarketingMediaWorkflowSummary();
$mediaOperations = $marketing->getMarketingMediaOperationsSummary();
$mediaReadiness = $marketing->getMarketingMediaOperationalReadiness((int) ($user['id'] ?? 0), 8);
$creativeRuns = $marketing->listAiCreativeRuns([], 6, 0);
$aiMediaRequests = $marketing->listAiMediaRequests([], 6, 0);
$contentOptions = $canWriteMarketing ? $marketing->listContentItems(['exclude_status' => 'archived'], 50, 0) : [];
$landingPageOptions = $canWriteMarketing ? $marketing->listLandingPages(['exclude_status' => 'archived'], 50, 0) : [];
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mediaCounts = (array) ($mediaOperations['counts'] ?? []);
$workflowCounts = (array) ($mediaWorkflow['counts'] ?? []);
$readinessCounts = (array) ($mediaReadiness['counts'] ?? []);
$totalMedia = (int) ($mediaCounts['total'] ?? count($mediaFiles));
$activeInUse = (int) ($mediaCounts['active_in_use'] ?? 0);
$needsAccessibility = (int) ($mediaCounts['needs_accessibility'] ?? $workflowCounts['missing_alt_text'] ?? 0);
$exportBlocked = (int) ($mediaCounts['export_blocked'] ?? $workflowCounts['blocked'] ?? 0);
$aiRequestCount = count((array) $aiMediaRequests);
$summaryTiles = [
    ['icon' => 'fa-images', 'label' => 'Media', 'value' => (string) $totalMedia, 'tooltip' => 'Reusable images, video, files, and creative references in this workspace.'],
    ['icon' => 'fa-link', 'label' => 'In Use', 'value' => (string) $activeInUse, 'tooltip' => 'Media already connected to content, landing pages, distribution, or channel kits.'],
    ['icon' => 'fa-universal-access', 'label' => 'Access', 'value' => (string) $needsAccessibility, 'tooltip' => 'Media needing alt text, captions, transcripts, or other accessibility metadata.'],
    ['icon' => 'fa-ban', 'label' => 'Blocked', 'value' => (string) $exportBlocked, 'tooltip' => 'Media blocked by governance, rights, expiry, restriction, or archive status.'],
];
$stageCards = [
    ['label' => 'Add Media', 'icon' => 'fa-plus', 'status' => $totalMedia > 0 ? 'ready' : 'setup_needed', 'sentence' => $totalMedia > 0 ? $totalMedia . ' saved.' : 'Start library.', 'tooltip' => 'Add a URL reference or upload a reusable image, video, audio, document, or file.', 'href' => '#add-media', 'action' => 'Add'],
    ['label' => 'Approve Rights', 'icon' => 'fa-shield-halved', 'status' => $exportBlocked > 0 ? 'blocked' : 'ready', 'sentence' => $exportBlocked > 0 ? $exportBlocked . ' blocked.' : 'Rights clear.', 'tooltip' => 'Governance stays manager-only. Use it to set approval, license, expiry, and blocked reasons.', 'href' => '#media-readiness', 'action' => 'Review'],
    ['label' => 'Fix Access', 'icon' => 'fa-universal-access', 'status' => $needsAccessibility > 0 ? 'warning' : 'ready', 'sentence' => $needsAccessibility > 0 ? $needsAccessibility . ' gaps.' : 'Ready.', 'tooltip' => 'Accessibility gaps are moved into visual cards and tooltips instead of dense rows.', 'href' => '#media-readiness', 'action' => 'Open'],
    ['label' => 'Map Usage', 'icon' => 'fa-diagram-project', 'status' => (int) ($workflowCounts['unused'] ?? 0) > 0 ? 'warning' : 'ready', 'sentence' => (int) ($workflowCounts['unused'] ?? 0) . ' unused.', 'tooltip' => 'Usage maps show where media supports content, landing pages, assets, distribution, and channel kits.', 'href' => '#media-usage', 'action' => 'Map'],
    ['label' => 'AI Briefs', 'icon' => 'fa-wand-magic-sparkles', 'status' => $aiRequestCount > 0 ? 'ready' : 'setup_needed', 'sentence' => $aiRequestCount > 0 ? $aiRequestCount . ' briefs.' : 'Advisory only.', 'tooltip' => 'AI media bridge creates advisory prompts and concepts only. Nothing becomes a media record until accepted.', 'href' => '#ai-media-brief', 'action' => 'Open'],
];
$mediaRecommendations = array_slice((array) ($mediaWorkflow['recommendations'] ?? []), 0, 5);
$readinessActions = array_slice((array) ($mediaReadiness['actions'] ?? []), 0, 5);
$todayActions = array_slice(array_values(array_filter([
    $exportBlocked > 0 ? ['label' => 'Resolve blocked media', 'href' => '#media-readiness', 'description' => 'Check governance and rights before launch export.'] : null,
    $needsAccessibility > 0 ? ['label' => 'Fix accessibility', 'href' => '#media-readiness', 'description' => 'Add missing alt text, captions, or transcripts.'] : null,
    $mediaRecommendations[0] ?? null,
    $readinessActions[0] ?? null,
    ['label' => 'Create AI media brief', 'href' => '#ai-media-brief', 'description' => 'Draft an advisory image, video, or storyboard concept.'],
])), 0, 5);
$expertLinks = [
    ['label' => 'Channel Kits', 'href' => 'marketing_channel_exports.php', 'hint' => 'Build manual channel media packages.'],
    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'hint' => 'Attach media to content items.'],
    ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'hint' => 'Place media in landing page plans.'],
    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'hint' => 'Connect media to distribution packages.'],
    ['label' => 'Creative', 'href' => 'marketing_creative.php', 'hint' => 'Open the creative AI workspace.'],
    ['label' => 'Admin Diagnostics', 'href' => 'marketing_admin.php', 'hint' => 'Review advanced media diagnostics.'],
];
$pageTitle = 'Marketing Assets - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-assets-page"><div class="container">
    <div class="page-header marketing-page-header" id="media-board">
        <div><h1>Marketing Assets</h1><p>Browse and manage reusable campaign files.</p></div>
        <div class="page-header-actions">
            <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
            <a class="btn-premium-primary marketing-assets-workspace-toggle" href="#media-tools" data-asset-workspace-toggle><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> <span>Asset tools</span></a>
        </div>
    </div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'asset'): ?><div class="alert alert-success">Marketing asset saved.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'media'): ?><div class="alert alert-success">Marketing media saved.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'governance'): ?><div class="alert alert-success">Marketing media governance updated.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'creative'): ?><div class="alert alert-success">AI creative suggestion saved for review. Media records were not overwritten.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'ai_media'): ?><div class="alert alert-success">AI media bridge output saved for review. No media record was created yet.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'ai_media_accept'): ?><div class="alert alert-success">AI media output accepted into the media library for manual review.</div><?php endif; ?>

    <section class="marketing-assets-shell">
        <div class="marketing-assets-layout" data-asset-library-view>
            <main class="marketing-assets-main">
                <section class="content-card marketing-assets-library" id="media-library">
                    <div class="premium-section-header">
                        <div>
                            <h2>Asset Library</h2>
                            <p>Your reusable images, videos, documents, and campaign files.</p>
                        </div>
                        <div class="marketing-assets-library-actions">
                            <span class="marketing-assets-library-count"><?php echo $totalMedia; ?> <?php echo $totalMedia === 1 ? 'asset' : 'assets'; ?></span>
                        </div>
                    </div>
                    <?php if (empty($mediaFiles)): ?>
                        <div class="empty-state"><p>No marketing media yet. Add a URL reference or upload an image/video to start building richer campaigns.</p></div>
                    <?php else: ?>
                        <div class="marketing-assets-media-grid">
                            <?php foreach ($mediaFiles as $media): ?>
                                <?php $mediaUrl = $marketing->mediaDisplayUrl($media); ?>
                                <article class="marketing-assets-media-card" id="media-<?php echo (int) $media['id']; ?>">
                                    <div class="marketing-assets-preview">
                                        <?php if ($mediaUrl !== '' && (string) $media['media_type'] === 'image'): ?><img src="<?php echo $h($mediaUrl); ?>" alt="<?php echo $h($media['alt_text'] ?? $media['title']); ?>">
                                        <?php elseif ($mediaUrl !== '' && (string) $media['media_type'] === 'video'): ?><video src="<?php echo $h($mediaUrl); ?>" muted preload="metadata"></video>
                                        <?php else: ?><span><?php echo $h($labelize((string) $media['media_type'])); ?></span><?php endif; ?>
                                    </div>
                                    <h3><?php echo $h($media['title']); ?></h3>
                                    <div class="marketing-assets-meta">
                                        <span><?php echo $h($labelize((string) $media['media_type'])); ?></span>
                                        <span><?php echo $h($labelize((string) $media['source_type'])); ?></span>
                                        <span><?php echo $h($labelize((string) ($media['approval_status'] ?? 'pending'))); ?></span>
                                        <span><?php echo $h($labelize((string) ($media['license_status'] ?? 'unknown'))); ?></span>
                                    </div>
                                    <?php if ($mediaUrl !== ''): ?><a class="btn-premium-secondary marketing-assets-card-action" href="<?php echo $h($mediaUrl); ?>" target="_blank" rel="noopener">Open media</a><?php endif; ?>
                                    <details class="marketing-assets-media-tools">
                                        <summary>Media controls</summary>
                                        <div class="marketing-assets-media-tools-body">
                                            <?php if (!empty($media['caption'])): ?><p><?php echo $h($media['caption']); ?></p><?php endif; ?>
                                            <?php if (!empty($media['blocked_reason'])): ?><p class="marketing-assets-warning"><?php echo $h($media['blocked_reason']); ?></p><?php endif; ?>
                                            <?php if ($canWriteMarketing): ?>
                                                <form method="POST" class="marketing-assets-inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                    <input type="hidden" name="action" value="ai_creative">
                                                    <input type="hidden" name="media_file_id" value="<?php echo (int) $media['id']; ?>">
                                                    <select name="creative_action"><option value="alt_caption">Write Alt/Caption</option><option value="image_prompt">Image Prompt</option><option value="media_readiness_review">Readiness Review</option></select>
                                                    <button class="btn-premium-secondary" type="submit">AI Assist</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($canManageMarketing): ?>
                                                <form method="POST" class="marketing-assets-governance-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                                    <input type="hidden" name="action" value="media_governance">
                                                    <input type="hidden" name="media_file_id" value="<?php echo (int) $media['id']; ?>">
                                                    <select name="approval_status"><?php foreach (Marketing::MEDIA_APPROVAL_STATUSES as $status): ?><option value="<?php echo $h($status); ?>" <?php echo (string) ($media['approval_status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo $h($labelize($status)); ?></option><?php endforeach; ?></select>
                                                    <select name="license_status"><?php foreach (Marketing::MEDIA_LICENSE_STATUSES as $status): ?><option value="<?php echo $h($status); ?>" <?php echo (string) ($media['license_status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo $h($labelize($status)); ?></option><?php endforeach; ?></select>
                                                    <input type="date" name="expiry_date" value="<?php echo $h($media['expiry_date'] ?? ''); ?>">
                                                    <input type="text" name="blocked_reason" placeholder="Blocked reason" value="<?php echo $h($media['blocked_reason'] ?? ''); ?>">
                                                    <button class="btn-premium-secondary" type="submit">Update Governance</button>
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

            <aside class="marketing-assets-sidebar" aria-label="Asset library overview">
                <section class="content-card marketing-assets-overview">
                    <div class="premium-section-header"><div><h2>Library overview</h2><p>Health at a glance.</p></div></div>
                    <div class="marketing-founder-summary marketing-assets-summary" aria-label="Marketing asset summary">
                        <?php foreach ($summaryTiles as $tile): ?>
                            <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo $h($tile['tooltip']); ?>">
                                <i class="fas <?php echo $h($tile['icon']); ?>" aria-hidden="true"></i>
                                <span><?php echo $h($tile['label']); ?></span>
                                <strong><?php echo $h($tile['value']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <details class="content-card marketing-assets-reporting">
                    <summary>
                        <span><i class="fas fa-chart-simple" aria-hidden="true"></i> Reporting &amp; readiness</span>
                        <span class="badge <?php echo $exportBlocked > 0 ? 'badge-danger' : ($needsAccessibility > 0 ? 'badge-warning' : 'badge-success'); ?>"><?php echo ($exportBlocked + $needsAccessibility) > 0 ? ($exportBlocked + $needsAccessibility) . ' to review' : 'Clear'; ?></span>
                    </summary>
                    <div class="marketing-assets-reporting-body">
                        <section class="marketing-assets-board" aria-label="Media operations reporting">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Media Operations Board</h2>
                                    <p>Readiness before channel export.</p>
                                </div>
                                <span class="badge <?php echo $exportBlocked > 0 ? 'badge-danger' : 'badge-success'; ?>"><?php echo $exportBlocked > 0 ? 'Export Blocked' : 'Export Clear'; ?></span>
                            </div>
                            <div class="marketing-assets-ops-strip">
                                <div><span>Safe To Archive</span><strong><?php echo (int) ($mediaCounts['safe_to_archive'] ?? 0); ?></strong></div>
                                <div><span>Needs Accessibility</span><strong><?php echo $needsAccessibility; ?></strong></div>
                                <div><span>Export Blocked</span><strong><?php echo $exportBlocked; ?></strong></div>
                            </div>
                            <?php if (empty($mediaOperations['operations'])): ?>
                                <div class="empty-state"><p>No media operations yet. Add image or video records to start tracking accessibility, rights, and launch readiness.</p></div>
                            <?php else: ?>
                                <div class="marketing-assets-operation-grid">
                                    <?php foreach (array_slice((array) $mediaOperations['operations'], 0, 6) as $operation): ?>
                                        <?php
                                        $isBlocked = (bool) ($operation['export_blocked'] ?? false);
                                        $needsAttention = $isBlocked || (bool) ($operation['needs_accessibility'] ?? false) || (bool) ($operation['rights_attention'] ?? false) || (bool) ($operation['safe_to_archive'] ?? false);
                                        ?>
                                        <a class="marketing-assets-operation-card <?php echo $isBlocked ? 'blocked' : ($needsAttention ? 'warning' : 'ready'); ?>" href="<?php echo $h($operation['href'] ?? 'marketing_assets.php'); ?>" data-tooltip="<?php echo $h($operation['recommended_action'] ?? 'Review media readiness.'); ?>">
                                            <div class="marketing-stage-visual">
                                                <i class="fas <?php echo $isBlocked ? 'fa-ban' : ($needsAttention ? 'fa-circle-exclamation' : 'fa-circle-check'); ?>" aria-hidden="true"></i>
                                            </div>
                                            <h3><?php echo $h($operation['title'] ?? 'Media file'); ?></h3>
                                            <div class="marketing-assets-meta">
                                                <span><?php echo $h($labelize((string) ($operation['media_type'] ?? 'other'))); ?></span>
                                                <span><?php echo (int) ($operation['usage_count'] ?? 0); ?> use(s)</span>
                                                <span><?php echo $h($labelize((string) ($operation['approval_status'] ?? 'pending'))); ?></span>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="marketing-assets-workflow" aria-label="Media workflow shortcuts">
                            <div class="premium-section-header"><div><h2>Workflow shortcuts</h2><p>Open a focused media task.</p></div></div>
                            <div class="marketing-assets-stage-grid">
                                <?php foreach ($stageCards as $stage): ?>
                                    <?php $stageStatus = (string) $stage['status']; ?>
                                    <a class="marketing-assets-stage-card <?php echo $h($stageStatus); ?>" href="<?php echo $h($stage['href']); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>">
                                        <i class="fas <?php echo $h($stage['icon']); ?>" aria-hidden="true"></i>
                                        <span><strong><?php echo $h($stage['label']); ?></strong><small><?php echo $h($stage['sentence']); ?></small></span>
                                        <span class="badge <?php echo $stageStatus === 'blocked' ? 'badge-danger' : ($stageStatus === 'ready' ? 'badge-success' : 'badge-warning'); ?>"><?php echo $h($labelize($stageStatus)); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>
                </details>

                <section class="content-card marketing-assets-today" aria-label="Next action">
                    <div class="premium-section-header"><div><h2>Next up</h2><p>Keep the library ready.</p></div></div>
                    <div class="marketing-today-list">
                        <?php foreach (array_slice($todayActions, 0, 2) as $action): ?>
                            <a class="marketing-today-item" href="<?php echo $h($action['href'] ?? 'marketing_assets.php'); ?>">
                                <strong><?php echo $h($action['label'] ?? 'Review media'); ?></strong>
                                <span><?php echo $h($action['description'] ?? $action['reason'] ?? 'Review media readiness.'); ?></span>
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>

        <section class="content-card marketing-assets-tool-hub" id="media-tools" data-asset-tool-hub hidden>
            <div class="premium-section-header marketing-assets-tool-hub-head">
                <div>
                    <h2>Asset tools</h2>
                    <p>Choose one focused task. The rest stay out of the way.</p>
                </div>
                <div class="marketing-assets-tool-hub-actions">
                    <span class="marketing-assets-tool-hint"><i class="fas fa-layer-group" aria-hidden="true"></i> One tool at a time</span>
                    <a class="btn-premium-secondary" href="#media-library" data-asset-library-link><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to library</a>
                </div>
            </div>
            <div class="marketing-assets-tool-layout">
                <div class="marketing-assets-tool-tabs" role="tablist" aria-label="Asset tools" aria-orientation="vertical">
                    <button class="marketing-assets-tool-tab is-active" id="asset-tool-tab-add" type="button" role="tab" aria-selected="true" aria-controls="asset-tool-panel-add" tabindex="0" data-asset-tool-tab data-asset-tool-target="add-media">
                        <i class="fas fa-plus" aria-hidden="true"></i><span><strong>Add media</strong><small>Upload or link a file</small></span>
                    </button>
                    <button class="marketing-assets-tool-tab" id="asset-tool-tab-readiness" type="button" role="tab" aria-selected="false" aria-controls="asset-tool-panel-readiness" tabindex="-1" data-asset-tool-tab data-asset-tool-target="media-readiness">
                        <i class="fas fa-shield-halved" aria-hidden="true"></i><span><strong>Readiness</strong><small>Rights and accessibility</small></span>
                    </button>
                    <button class="marketing-assets-tool-tab" id="asset-tool-tab-usage" type="button" role="tab" aria-selected="false" aria-controls="asset-tool-panel-usage" tabindex="-1" data-asset-tool-tab data-asset-tool-target="media-usage">
                        <i class="fas fa-diagram-project" aria-hidden="true"></i><span><strong>Usage map</strong><small>See where files are used</small></span>
                    </button>
                    <button class="marketing-assets-tool-tab" id="asset-tool-tab-ai" type="button" role="tab" aria-selected="false" aria-controls="asset-tool-panel-ai" tabindex="-1" data-asset-tool-tab data-asset-tool-target="ai-media-brief">
                        <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span><strong>AI briefs</strong><small>Plan and review concepts</small></span>
                    </button>
                    <button class="marketing-assets-tool-tab" id="asset-tool-tab-records" type="button" role="tab" aria-selected="false" aria-controls="asset-tool-panel-records" tabindex="-1" data-asset-tool-tab data-asset-tool-target="asset-records">
                        <i class="fas fa-box-archive" aria-hidden="true"></i><span><strong>Asset records</strong><small>Package reusable assets</small></span>
                    </button>
                    <button class="marketing-assets-tool-tab" id="asset-tool-tab-connections" type="button" role="tab" aria-selected="false" aria-controls="asset-tool-panel-connections" tabindex="-1" data-asset-tool-tab data-asset-tool-target="connected-tools">
                        <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span><strong>Connections</strong><small>Open related workspaces</small></span>
                    </button>
                </div>

                <div class="marketing-assets-tool-panels">
                    <section class="marketing-assets-tool-panel" id="asset-tool-panel-add" role="tabpanel" aria-labelledby="asset-tool-tab-add" data-asset-tool-panel>
                        <section class="marketing-assets-add-media-card" id="add-media">
                            <div class="premium-section-header"><div><h2>Add Media</h2><p>Add one reusable file to the library.</p></div></div>
                            <?php if ($canWriteMarketing): ?><form method="POST" enctype="multipart/form-data" class="marketing-assets-form"><input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="create_media">
                                <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
                                <div class="form-group"><label>Media Type</label><select name="media_type"><?php foreach (Marketing::MEDIA_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Upload Image/Video/File</label><input type="file" name="media_upload" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.ppt,.pptx"></div>
                                <div class="form-group"><label>Or Source URL</label><input type="url" name="source_url" placeholder="https://example.com/asset.png"></div>
                                <div class="form-group"><label>Alt Text</label><input type="text" name="alt_text"></div>
                                <div class="form-group"><label>Tags</label><input type="text" name="tags" placeholder="launch, hero, testimonial"></div>
                                <div class="form-group marketing-assets-wide-field"><label>Caption</label><textarea name="caption" rows="3"></textarea></div>
                                <div class="form-group marketing-assets-wide-field"><label>Usage Rights</label><textarea name="usage_rights" rows="3"></textarea></div>
                                <button class="btn-premium-primary" type="submit">Save Media</button>
                            </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
                        </section>
                    </section>

                    <section class="marketing-assets-tool-panel" id="asset-tool-panel-readiness" role="tabpanel" aria-labelledby="asset-tool-tab-readiness" data-asset-tool-panel hidden>
                        <section class="marketing-assets-readiness-card" id="media-readiness">
                            <?php echo MarketingMediaReadinessUi::render($mediaReadiness); ?>
                        </section>
                    </section>

                    <section class="marketing-assets-tool-panel" id="asset-tool-panel-usage" role="tabpanel" aria-labelledby="asset-tool-tab-usage" data-asset-tool-panel hidden>
                <section class="marketing-assets-usage-card" id="media-usage">
                    <div class="premium-section-header"><div><h2>Media Usage Map</h2><p>Where images and files are connected.</p></div><a class="btn-premium-secondary" href="marketing_channel_exports.php">Build Channel Kits</a></div>
                    <div class="marketing-assets-ops-strip">
                        <div><span>Media Files</span><strong><?php echo (int) ($workflowCounts['media_files'] ?? 0); ?></strong></div>
                        <div><span>Unused</span><strong><?php echo (int) ($workflowCounts['unused'] ?? 0); ?></strong></div>
                        <div><span>Missing Alt</span><strong><?php echo (int) ($workflowCounts['missing_alt_text'] ?? 0); ?></strong></div>
                        <div><span>Blocked</span><strong><?php echo (int) ($workflowCounts['blocked'] ?? 0); ?></strong></div>
                        <div><span>Rights Expiring</span><strong><?php echo (int) ($workflowCounts['expiring'] ?? 0); ?></strong></div>
                    </div>
                    <?php if (empty($mediaWorkflow['top_used_media'])): ?><div class="empty-state"><p>No media usage is mapped yet. Add media, attach it to content or a landing page, then build channel kits.</p></div><?php else: ?>
                        <div class="marketing-assets-usage-list">
                            <?php foreach ((array) $mediaWorkflow['top_used_media'] as $workflowMedia): $workflowMediaId = (int) ($workflowMedia['id'] ?? 0); $usageMap = (array) (($mediaWorkflow['usage_maps'][$workflowMediaId] ?? []) ?: []); ?>
                                <div class="marketing-assets-usage-item">
                                    <strong><?php echo $h($workflowMedia['title'] ?? 'Media file'); ?></strong>
                                    <div class="marketing-assets-meta"><span><?php echo $h($labelize((string) ($workflowMedia['media_type'] ?? 'other'))); ?></span><span><?php echo (int) ($usageMap['usage_count'] ?? 0); ?> linked use(s)</span><?php if (!empty($workflowMedia['approval_status'])): ?><span><?php echo $h($labelize((string) $workflowMedia['approval_status'])); ?></span><?php endif; ?></div>
                                    <?php if (!empty($usageMap['usages'])): ?><div class="marketing-assets-meta"><?php foreach (array_slice((array) $usageMap['usages'], 0, 4) as $usage): ?><a href="<?php echo $h($usage['href'] ?? 'marketing_assets.php'); ?>"><?php echo $h($labelize((string) ($usage['type'] ?? 'usage'))); ?>: <?php echo $h($usage['title'] ?? 'Linked record'); ?></a><?php endforeach; ?></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="premium-section-header marketing-assets-subhead"><h2>Recommended Media Actions</h2></div>
                    <div class="marketing-assets-usage-list">
                        <?php foreach ((array) ($mediaWorkflow['recommendations'] ?? []) as $recommendation): ?>
                            <a class="marketing-assets-usage-item" href="<?php echo $h($recommendation['href'] ?? 'marketing_assets.php'); ?>"><strong><?php echo $h($recommendation['label'] ?? 'Review media'); ?></strong><span><?php echo $h($recommendation['reason'] ?? 'Review campaign media readiness.'); ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </section>
                    </section>

                    <section class="marketing-assets-tool-panel marketing-assets-tool-panel-ai" id="asset-tool-panel-ai" role="tabpanel" aria-labelledby="asset-tool-tab-ai" data-asset-tool-panel hidden>
                <details class="marketing-assets-tool-subsection marketing-assets-ai-card">
                    <summary><span><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Existing AI briefs</span><small><?php echo $aiRequestCount; ?> saved</small></summary>
                    <section>
                    <div class="premium-section-header"><div><h2>AI Media Bridge</h2><p>Advisory concepts only until accepted.</p></div></div>
                    <?php if (empty($aiMediaRequests)): ?><div class="empty-state"><p>No AI media bridge requests yet.</p></div><?php else: foreach ($aiMediaRequests as $request): $outputs = $marketing->listAiMediaOutputs((int) $request['id']); ?>
                        <article class="marketing-assets-ai-request">
                            <strong><?php echo $h($request['title']); ?></strong>
                            <div class="marketing-assets-meta"><span><?php echo $h($labelize((string) $request['request_type'])); ?></span><span><?php echo $h($labelize((string) $request['status'])); ?></span><span><?php echo (int) ($request['output_count'] ?? 0); ?> output(s)</span><?php if (!empty($request['accepted_media_title'])): ?><span>Accepted: <?php echo $h($request['accepted_media_title']); ?></span><?php endif; ?></div>
                            <?php foreach ($outputs as $output): ?>
                                <div class="marketing-assets-ai-output">
                                    <strong><?php echo $h($output['title'] ?? $labelize((string) $output['output_type'])); ?></strong>
                                    <div class="marketing-assets-meta"><span><?php echo $h($labelize((string) $output['output_type'])); ?></span><span><?php echo $h($labelize((string) $output['status'])); ?></span></div>
                                    <?php if (!empty($output['prompt_text'])): ?><pre><?php echo $h($output['prompt_text']); ?></pre><?php endif; ?>
                                    <?php if ($canWriteMarketing && (string) ($output['status'] ?? '') !== 'accepted'): ?><form method="POST" class="marketing-assets-inline-form"><input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="ai_media_accept"><input type="hidden" name="output_id" value="<?php echo (int) $output['id']; ?>"><input type="text" name="title" value="<?php echo $h($output['title'] ?? $request['title']); ?>" aria-label="Accepted media title"><button class="btn-premium-secondary" type="submit">Accept to Media Library</button></form><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </article>
                    <?php endforeach; endif; ?>
                    </section>
                </details>

                <details class="marketing-assets-tool-subsection marketing-assets-recent-ai-card">
                    <summary><span><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Recent AI activity</span><small><?php echo count($creativeRuns); ?> runs</small></summary>
                    <section>
                    <div class="premium-section-header"><h2>Recent AI Creative Runs</h2></div>
                    <?php if (empty($creativeRuns)): ?><div class="empty-state"><p>No AI creative runs yet.</p></div><?php else: ?><div class="marketing-assets-usage-list"><?php foreach ($creativeRuns as $run): ?>
                        <div class="marketing-assets-usage-item"><strong><?php echo $h($labelize((string) $run['action'])); ?></strong><div class="marketing-assets-meta"><span><?php echo $h($run['media_title'] ?? $run['content_title'] ?? $run['landing_page_title'] ?? 'General'); ?></span><span><?php echo $h($run['created_at']); ?></span></div></div>
                    <?php endforeach; ?></div><?php endif; ?>
                    </section>
                </details>

                <section class="marketing-assets-create-ai-card" id="ai-media-brief">
                    <div class="premium-section-header"><div><h2>Generate AI Media Brief</h2><p>Draft one advisory concept for review.</p></div></div>
                    <?php if ($canWriteMarketing): ?><form method="POST" class="marketing-assets-form"><input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="ai_media_generate">
                        <div class="form-group"><label>Title</label><input type="text" name="title" required placeholder="Launch hero concept"></div>
                        <div class="form-group"><label>Request Type</label><select name="request_type"><?php foreach (Marketing::AI_MEDIA_REQUEST_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group marketing-assets-wide-field"><label>Prompt Context</label><textarea name="prompt_text" rows="4" placeholder="What visual, video, or storyboard should marketing plan?"></textarea></div>
                        <div class="form-group"><label>Linked Content</label><select name="content_item_id"><option value="">None</option><?php foreach ($contentOptions as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo $h($item['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Linked Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($landingPageOptions as $page): ?><option value="<?php echo (int) $page['id']; ?>"><?php echo $h($page['title']); ?></option><?php endforeach; ?></select></div>
                        <button class="btn-premium-primary" type="submit">Create AI Media Brief</button>
                    </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
                </section>
                    </section>

                    <section class="marketing-assets-tool-panel marketing-assets-tool-panel-records" id="asset-tool-panel-records" role="tabpanel" aria-labelledby="asset-tool-tab-records" data-asset-tool-panel hidden>
                <section class="marketing-assets-add-asset-card" id="asset-records">
                    <div class="premium-section-header"><div><h2>Add Asset Record</h2><p>Package a reusable file for a channel or workflow.</p></div></div>
                    <?php if ($canWriteMarketing): ?><form method="POST" class="marketing-assets-form"><input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="create_asset">
                        <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
                        <div class="form-group"><label>Asset Type</label><select name="asset_type"><?php foreach (Marketing::ASSET_TYPES as $type): ?><option value="<?php echo $h($type); ?>"><?php echo $h($labelize($type)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Media File</label><select name="media_file_id"><option value="">None</option><?php foreach ($mediaFiles as $media): ?><option value="<?php echo (int) $media['id']; ?>"><?php echo $h($media['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>URL or Path</label><input type="text" name="asset_url"></div>
                        <div class="form-group"><label>Channel</label><input type="text" name="channel"></div>
                        <div class="form-group marketing-assets-wide-field"><label>Usage Rights</label><textarea name="usage_rights" rows="3"></textarea></div>
                        <button class="btn-premium-primary" type="submit">Save Asset</button>
                    </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
                </section>

                <details class="marketing-assets-tool-subsection marketing-assets-asset-list-card">
                    <summary><span><i class="fas fa-box-archive" aria-hidden="true"></i> Existing asset records</span><small><?php echo count($assets); ?> saved</small></summary>
                    <section>
                    <div class="premium-section-header"><h2>Assets</h2></div>
                    <?php if (empty($assets)): ?><div class="empty-state"><p>No marketing assets yet.</p></div><?php else: ?><div class="marketing-assets-usage-list"><?php foreach ($assets as $asset): ?>
                        <div class="marketing-assets-usage-item"><strong><?php echo $h($asset['title']); ?></strong><div class="marketing-assets-meta"><span><?php echo $h($labelize((string) $asset['asset_type'])); ?></span><span><?php echo $h($asset['channel'] ?? 'Any channel'); ?></span><?php if (!empty($asset['media_title'])): ?><span>Media: <?php echo $h($asset['media_title']); ?></span><?php endif; ?></div><?php if (!empty($asset['asset_url'])): ?><a href="<?php echo $h($asset['asset_url']); ?>" target="_blank" rel="noopener">Open</a><?php endif; ?></div>
                    <?php endforeach; ?></div><?php endif; ?>
                    </section>
                </details>
                    </section>

                    <section class="marketing-assets-tool-panel" id="asset-tool-panel-connections" role="tabpanel" aria-labelledby="asset-tool-tab-connections" data-asset-tool-panel hidden>
                <section class="marketing-assets-expert-card" id="connected-tools">
                    <div class="premium-section-header"><div><h2>Advanced Marketing Tools</h2><p>Expert routes remain available without crowding the media board.</p></div></div>
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($expertLinks as $link): ?>
                            <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>"><strong><?php echo $h($link['label']); ?></strong><span><?php echo $h($link['hint']); ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </section>
                    </section>
                </div>
            </div>
        </section>
    </section>
</div></div>
<script>
(function () {
    var hub = document.querySelector('[data-asset-tool-hub]');
    var libraryView = document.querySelector('[data-asset-library-view]');
    var workspaceToggle = document.querySelector('[data-asset-workspace-toggle]');
    if (!hub || !libraryView || !workspaceToggle) { return; }

    var tabList = hub.querySelector('[role="tablist"]');
    var tabs = Array.prototype.slice.call(hub.querySelectorAll('[data-asset-tool-tab]'));
    var panels = Array.prototype.slice.call(hub.querySelectorAll('[data-asset-tool-panel]'));
    var workspaceToggleIcon = workspaceToggle.querySelector('i');
    var workspaceToggleLabel = workspaceToggle.querySelector('span');

    function setWorkspace(mode) {
        var toolsVisible = mode === 'tools';
        libraryView.hidden = toolsVisible;
        hub.hidden = !toolsVisible;
        workspaceToggle.setAttribute('href', toolsVisible ? '#media-library' : '#media-tools');
        workspaceToggle.setAttribute('aria-label', toolsVisible ? 'View asset library' : 'Open asset tools');
        if (workspaceToggleLabel) {
            workspaceToggleLabel.textContent = toolsVisible ? 'View library' : 'Asset tools';
        }
        if (workspaceToggleIcon) {
            workspaceToggleIcon.className = toolsVisible ? 'fas fa-images' : 'fas fa-screwdriver-wrench';
        }
    }

    function activateTab(tab, moveFocus) {
        if (!tab) { return; }

        tabs.forEach(function (candidate) {
            var selected = candidate === tab;
            candidate.classList.toggle('is-active', selected);
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
            candidate.setAttribute('tabindex', selected ? '0' : '-1');
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.id !== tab.getAttribute('aria-controls');
        });

        if (moveFocus) { tab.focus(); }
    }

    function syncToolHash(tab) {
        var targetId = tab ? tab.getAttribute('data-asset-tool-target') : '';
        if (!targetId) { return; }
        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(null, '', '#' + targetId);
        }
    }

    function openParentDisclosures(target) {
        var disclosure = target.matches('details') ? target : target.closest('details');
        while (disclosure) {
            disclosure.open = true;
            disclosure = disclosure.parentElement ? disclosure.parentElement.closest('details') : null;
        }
    }

    function revealHashTarget() {
        var hash = window.location.hash;
        if (!hash || hash.length < 2 || hash === '#media-board' || hash === '#media-library') {
            setWorkspace('library');
            if (hash === '#media-library') {
                window.requestAnimationFrame(function () {
                    var library = document.getElementById('media-library');
                    if (library) { library.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
                });
            }
            return;
        }

        var target = document.getElementById(hash.slice(1));
        if (!target) {
            setWorkspace('library');
            return;
        }

        var toolTarget = target === hub || hub.contains(target);
        if (toolTarget) {
            setWorkspace('tools');
            var panel = target.matches('[data-asset-tool-panel]') ? target : target.closest('[data-asset-tool-panel]');
            var tab = tabs.find(function (candidate) {
                return panel && candidate.getAttribute('aria-controls') === panel.id;
            });
            if (tab) { activateTab(tab, false); }

            openParentDisclosures(target);
            window.requestAnimationFrame(function () {
                hub.scrollIntoView({ block: 'start', behavior: 'smooth' });
            });
            return;
        }

        setWorkspace('library');
        openParentDisclosures(target);
        window.requestAnimationFrame(function () {
            target.scrollIntoView({ block: 'start', behavior: 'smooth' });
        });
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateTab(tab, false);
            syncToolHash(tab);
        });
        tab.addEventListener('keydown', function (event) {
            var nextIndex = index;
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') { nextIndex = (index + 1) % tabs.length; }
            else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') { nextIndex = (index - 1 + tabs.length) % tabs.length; }
            else if (event.key === 'Home') { nextIndex = 0; }
            else if (event.key === 'End') { nextIndex = tabs.length - 1; }
            else { return; }

            event.preventDefault();
            activateTab(tabs[nextIndex], true);
            syncToolHash(tabs[nextIndex]);
        });
    });

    var mobileTools = window.matchMedia('(max-width: 820px)');
    function syncTabOrientation() {
        tabList.setAttribute('aria-orientation', mobileTools.matches ? 'horizontal' : 'vertical');
    }
    syncTabOrientation();
    if (typeof mobileTools.addEventListener === 'function') {
        mobileTools.addEventListener('change', syncTabOrientation);
    } else if (typeof mobileTools.addListener === 'function') {
        mobileTools.addListener(syncTabOrientation);
    }

    window.addEventListener('hashchange', revealHashTarget);
    revealHashTarget();
})();
</script>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
