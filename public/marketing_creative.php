<?php
/**
 * Creative asset production workbench.
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
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to manage creative production.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'brief');
        if ($action === 'ai_creative') {
            $marketing->runAiCreativeAction((string) ($_POST['creative_action'] ?? ''), [
                'content_item_id' => (int) ($_POST['content_item_id'] ?? 0),
                'landing_page_id' => (int) ($_POST['landing_page_id'] ?? 0),
                'media_file_id' => (int) ($_POST['media_file_id'] ?? 0),
                'creative_brief_id' => (int) ($_POST['creative_brief_id'] ?? 0),
                'asset_request_id' => (int) ($_POST['asset_request_id'] ?? 0),
                'subject' => $_POST['subject'] ?? '',
                'channel' => $_POST['channel'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_creative.php?success=creative');
            exit;
        }
        if ($action === 'request') {
            $marketing->createAssetRequest([
                'title' => $_POST['title'] ?? '',
                'content_item_id' => $_POST['content_item_id'] ?? 0,
                'creative_brief_id' => $_POST['creative_brief_id'] ?? 0,
                'requested_asset_type' => $_POST['requested_asset_type'] ?? 'image',
                'channel' => $_POST['channel'] ?? '',
                'description' => $_POST['description'] ?? '',
                'due_at' => $_POST['due_at'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_creative.php?success=request');
            exit;
        }
        if ($action === 'campaign_requirement') {
            $requirementId = $marketing->createCampaignCreativeRequirement([
                'campaign_id' => $_POST['campaign_id'] ?? 0,
                'title' => $_POST['title'] ?? '',
                'requirement_type' => $_POST['requirement_type'] ?? 'image',
                'channel' => $_POST['channel'] ?? '',
                'placement' => $_POST['placement'] ?? '',
                'priority' => $_POST['priority'] ?? 'normal',
                'creative_brief_id' => $_POST['creative_brief_id'] ?? 0,
                'asset_request_id' => $_POST['asset_request_id'] ?? 0,
                'media_file_id' => $_POST['media_file_id'] ?? 0,
                'content_item_id' => $_POST['content_item_id'] ?? 0,
                'landing_page_id' => $_POST['landing_page_id'] ?? 0,
                'channel_export_bundle_id' => $_POST['channel_export_bundle_id'] ?? 0,
                'due_at' => $_POST['due_at'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'owner_user_id' => (int) ($user['id'] ?? 0),
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_creative.php?campaign_id=' . (int) ($_POST['campaign_id'] ?? 0) . '&success=requirement#campaign-creative-requirements');
            exit;
        }
        if ($action === 'media_work') {
            $marketing->createMediaProductionWorkItem([
                'title' => $_POST['title'] ?? '',
                'work_type' => $_POST['work_type'] ?? 'design',
                'priority' => $_POST['priority'] ?? 'normal',
                'status' => $_POST['status'] ?? 'requested',
                'campaign_id' => $_POST['campaign_id'] ?? 0,
                'content_item_id' => $_POST['content_item_id'] ?? 0,
                'landing_page_id' => $_POST['landing_page_id'] ?? 0,
                'channel_export_bundle_id' => $_POST['channel_export_bundle_id'] ?? 0,
                'creative_brief_id' => $_POST['creative_brief_id'] ?? 0,
                'asset_request_id' => $_POST['asset_request_id'] ?? 0,
                'campaign_creative_requirement_id' => $_POST['campaign_creative_requirement_id'] ?? 0,
                'media_file_id' => $_POST['media_file_id'] ?? 0,
                'channel' => $_POST['channel'] ?? '',
                'placement' => $_POST['placement'] ?? '',
                'requested_format' => $_POST['requested_format'] ?? '',
                'dimensions' => $_POST['dimensions'] ?? '',
                'due_at' => $_POST['due_at'] ?? '',
                'assigned_to' => $_POST['assigned_to'] ?? 0,
                'owner_user_id' => $_POST['owner_user_id'] ?? ($user['id'] ?? 0),
                'checklist' => $_POST['checklist'] ?? '',
                'production_notes' => $_POST['production_notes'] ?? '',
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            header('Location: ' . getBasePath() . '/marketing_creative.php?success=media_work#media-production-workflow');
            exit;
        }
        if ($action === 'media_work_update') {
            $marketing->updateMediaProductionWorkItem((int) ($_POST['work_item_id'] ?? 0), [
                'status' => $_POST['status'] ?? 'requested',
                'priority' => $_POST['priority'] ?? 'normal',
                'assigned_to' => $_POST['assigned_to'] ?? 0,
                'media_file_id' => $_POST['media_file_id'] ?? 0,
                'blocked_reason' => $_POST['blocked_reason'] ?? '',
                'production_notes' => $_POST['production_notes'] ?? '',
                'checklist' => $_POST['checklist'] ?? '',
            ]);
            header('Location: ' . getBasePath() . '/marketing_creative.php?success=media_work_update#media-production-workflow');
            exit;
        }
        if ($action === 'media_work_archive') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive media production work.');
            }
            $marketing->archiveMediaProductionWorkItem((int) ($_POST['work_item_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_creative.php?success=media_work_archive#media-production-workflow');
            exit;
        }
        $marketing->createCreativeBrief([
            'title' => $_POST['title'] ?? '',
            'content_item_id' => $_POST['content_item_id'] ?? 0,
            'asset_type' => $_POST['asset_type'] ?? 'image',
            'channel' => $_POST['channel'] ?? '',
            'objective' => $_POST['objective'] ?? '',
            'specs' => ['format' => $_POST['format'] ?? '', 'dimensions' => $_POST['dimensions'] ?? ''],
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        header('Location: ' . getBasePath() . '/marketing_creative.php?success=brief');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$briefs = $marketing->listCreativeBriefs(['open' => true], 50, 0);
$requests = $marketing->listAssetRequests([], 50, 0);
$items = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$landingPages = $marketing->listLandingPages([], 100, 0);
$mediaFiles = $marketing->listMediaFiles([], 100, 0);
$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$campaignWorkspaces = $marketing->listMarketingCampaignWorkspaces(['open' => true], 100, 0);
$channelBundles = $marketing->listChannelExportBundles([], 100, 0);
$campaignCreativeRequirements = $marketing->listCampaignCreativeRequirements($campaignId > 0 ? ['campaign_id' => $campaignId, 'open' => true] : ['open' => true], 50, 0);
$campaignCreativeReadiness = $campaignId > 0 ? $marketing->getCampaignCreativeReadinessSummary($campaignId) : null;
$mediaProductionSummary = $marketing->getMediaProductionWorkflowSummary((int) ($user['id'] ?? 0), 100);
$mediaProductionItems = $marketing->listMediaProductionWorkItems(['open' => true], 50, 0);
$optionData = $marketing->optionData();
$creativeWorkspace = $marketing->getAiCreativeWorkspaceSummary((int) ($user['id'] ?? 0));
$creativeRuns = $marketing->listAiCreativeRuns([], 8, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$creativeActions = [
    'image_prompt' => 'Generate Image Prompt',
    'video_storyboard' => 'Create Video Storyboard',
    'thumbnail_concept' => 'Thumbnail Concept',
    'landing_visual_direction' => 'Landing Visual Direction',
    'ad_creative_concept' => 'Ad Creative Concept',
    'alt_caption' => 'Alt Text And Caption',
    'media_readiness_review' => 'Review Media Readiness',
];
$previewJson = static function (array $value): string {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }

    return strlen($json) > 520 ? substr($json, 0, 520) . "\n..." : $json;
};
$creativeCounts = (array) ($creativeWorkspace['counts'] ?? []);
$mediaCounts = (array) ($mediaProductionSummary['counts'] ?? []);
$toneForCount = static fn(int $count, bool $negative = false): string => $negative ? ($count > 0 ? 'blocked' : 'ready') : ($count > 0 ? 'attention' : 'ready');
$toneForScore = static function (int $score): string {
    if ($score >= 80) { return 'ready'; }
    if ($score >= 45) { return 'attention'; }
    return 'blocked';
};
$summaryTiles = [
    ['icon' => 'fa-lightbulb', 'label' => 'Briefs', 'value' => (string) ($creativeCounts['open_briefs'] ?? 0), 'tooltip' => 'Open creative briefs that need asset direction.'],
    ['icon' => 'fa-inbox', 'label' => 'Requests', 'value' => (string) ($creativeCounts['open_asset_requests'] ?? 0), 'tooltip' => 'Open asset requests waiting for production or assignment.'],
    ['icon' => 'fa-photo-film', 'label' => 'Media Work', 'value' => (string) ($mediaCounts['open'] ?? 0), 'tooltip' => 'Open media production work items.'],
    ['icon' => 'fa-triangle-exclamation', 'label' => 'Blocked', 'value' => (string) ($mediaCounts['blocked'] ?? 0), 'tooltip' => 'Media work blocked by missing decisions, media, or ownership.'],
    ['icon' => 'fa-wand-magic-sparkles', 'label' => 'AI Runs', 'value' => (string) ($creativeCounts['recent_ai_runs'] ?? 0), 'tooltip' => 'Recent AI creative suggestions saved for review only.'],
];
$creativeCards = [
    [
        'icon' => 'fa-clipboard-list',
        'title' => 'Write Brief',
        'status' => ((int) ($creativeCounts['open_briefs'] ?? 0)) > 0 ? 'In use' : 'Ready',
        'tone' => $toneForCount((int) ($creativeCounts['open_briefs'] ?? 0)),
        'copy' => 'Describe what the visual must do.',
        'tooltip' => 'Creative briefs turn founder intent into visual direction before production starts.',
        'href' => '#creative-create-tools',
        'action' => 'Create Brief',
    ],
    [
        'icon' => 'fa-inbox',
        'title' => 'Request Asset',
        'status' => ((int) ($creativeCounts['open_asset_requests'] ?? 0)) > 0 ? 'In use' : 'Ready',
        'tone' => $toneForCount((int) ($creativeCounts['open_asset_requests'] ?? 0)),
        'copy' => 'Ask for the exact asset needed.',
        'tooltip' => 'Asset requests keep format, channel, due date, and linked content in one place.',
        'href' => '#creative-create-tools',
        'action' => 'Request Asset',
    ],
    [
        'icon' => 'fa-photo-film',
        'title' => 'Produce Media',
        'status' => ((int) ($mediaCounts['open'] ?? 0)) > 0 ? 'In use' : 'Ready',
        'tone' => $toneForCount((int) ($mediaCounts['open'] ?? 0)),
        'copy' => 'Track design, video, and format work.',
        'tooltip' => 'Media work items keep ownership, blockers, due dates, checklist, and linked media visible.',
        'href' => '#creative-evidence-tools',
        'action' => 'Open Work',
    ],
    [
        'icon' => 'fa-bullhorn',
        'title' => 'Campaign Needs',
        'status' => count($campaignCreativeRequirements) > 0 ? 'In use' : 'Ready',
        'tone' => $toneForCount(count($campaignCreativeRequirements)),
        'copy' => 'Check required campaign visuals.',
        'tooltip' => 'Campaign requirements show the hero, social, email, video, proof, and export media needed before launch.',
        'href' => '#creative-evidence-tools',
        'action' => 'Review Needs',
    ],
    [
        'icon' => 'fa-wand-magic-sparkles',
        'title' => 'Use AI Help',
        'status' => ((int) ($creativeCounts['recent_ai_runs'] ?? 0)) > 0 ? 'In use' : 'Ready',
        'tone' => $toneForCount((int) ($creativeCounts['recent_ai_runs'] ?? 0)),
        'copy' => 'Generate suggestions, not automatic changes.',
        'tooltip' => 'AI creative tools save recommendations only; they do not overwrite media, content, landing pages, exports, sends, or publishing.',
        'href' => '#creative-create-tools',
        'action' => 'Run AI Tool',
    ],
    [
        'icon' => 'fa-universal-access',
        'title' => 'Check Access',
        'status' => ((int) ($creativeCounts['media_needing_accessibility'] ?? 0)) > 0 ? 'Setup needed' : 'Ready',
        'tone' => $toneForCount((int) ($creativeCounts['media_needing_accessibility'] ?? 0), true),
        'copy' => 'Clear alt text and caption gaps.',
        'tooltip' => 'Accessibility gaps remain visible as launch evidence without crowding the first viewport.',
        'href' => 'marketing_assets.php',
        'action' => 'Open Assets',
    ],
];
$todayActions = [];
foreach (array_slice((array) ($creativeWorkspace['recommendations'] ?? []), 0, 3) as $recommendation) {
    $todayActions[] = [
        'icon' => 'fa-list-check',
        'label' => (string) ($recommendation['label'] ?? 'Review creative work'),
        'hint' => (string) ($recommendation['reason'] ?? 'Review the next creative action.'),
        'href' => (string) ($recommendation['href'] ?? '#creative-evidence-tools'),
        'tooltip' => 'Pulled from the creative workspace recommendations.',
    ];
}
if ((int) ($mediaCounts['blocked'] ?? 0) > 0) {
    $todayActions[] = ['icon' => 'fa-triangle-exclamation', 'label' => 'Clear blockers', 'hint' => (int) ($mediaCounts['blocked'] ?? 0) . ' media work item(s) are blocked.', 'href' => '#media-production-workflow', 'tooltip' => 'Opens the media production workflow evidence.'];
}
if ($canWriteMarketing) {
    $todayActions[] = ['icon' => 'fa-plus', 'label' => 'Create media work', 'hint' => 'Start one visual production item.', 'href' => '#creative-create-tools', 'tooltip' => 'Keeps creation available without making the first screen a form wall.'];
}
if (empty($todayActions)) {
    $todayActions[] = ['icon' => 'fa-photo-film', 'label' => 'Review creative queue', 'hint' => 'Open the detailed creative evidence drawer.', 'href' => '#creative-evidence-tools', 'tooltip' => 'Detailed queues stay one intentional step away.'];
}
$todayActions = array_slice($todayActions, 0, 5);
$pageTitle = 'Creative Production - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-ui-page marketing-creative-page"><div class="container">
    <div class="page-header">
        <div><h1>Creative Production</h1><p>Turn visual gaps into one clear creative action.</p></div>
        <div class="page-header-actions"><?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="#creative-create-tools">Create</a><?php endif; ?></div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'brief'): ?><div class="alert alert-success">Creative brief created.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'request'): ?><div class="alert alert-success">Asset request created.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'requirement'): ?><div class="alert alert-success">Campaign creative requirement created.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'media_work'): ?><div class="alert alert-success">Media production work item created.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'media_work_update'): ?><div class="alert alert-success">Media production work item updated.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'media_work_archive'): ?><div class="alert alert-success">Media production work item archived.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'creative'): ?><div class="alert alert-success">AI creative suggestion saved for review. No creative records were overwritten.</div><?php endif; ?>

    <section class="marketing-creative-summary" aria-label="Creative summary">
        <?php foreach ($summaryTiles as $tile): ?>
            <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>"></i>
                <div>
                    <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                    <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="marketing-creative-layout" aria-label="Creative workspace">
        <div class="marketing-creative-main">
            <div class="content-card marketing-creative-board">
                <div class="premium-section-header">
                    <div>
                        <h2>Creative Board</h2>
                        <p>Six visual jobs, one action each.</p>
                    </div>
                    <span class="badge badge-default">Manual first</span>
                </div>
                <div class="marketing-creative-card-grid">
                    <?php foreach ($creativeCards as $card): ?>
                        <article class="marketing-creative-stage-card <?php echo htmlspecialchars((string) $card['tone']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                            <div class="marketing-creative-visual"><i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>"></i></div>
                            <div class="marketing-creative-card-body">
                                <div class="marketing-creative-card-title">
                                    <strong><?php echo htmlspecialchars((string) $card['title']); ?></strong>
                                    <span class="marketing-creative-status <?php echo htmlspecialchars((string) $card['tone']); ?>"><?php echo htmlspecialchars((string) $card['status']); ?></span>
                                </div>
                                <span><?php echo htmlspecialchars((string) $card['copy']); ?></span>
                            </div>
                            <a class="btn-premium-secondary marketing-creative-card-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <aside class="content-card marketing-creative-today" aria-label="Today">
            <div class="premium-section-header">
                <div><h2>Today</h2><p>Next useful moves.</p></div>
            </div>
            <div class="marketing-creative-today-list">
                <?php foreach ($todayActions as $action): ?>
                    <a class="marketing-creative-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $action['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $action['icon']); ?>"></i>
                        <span><strong><?php echo htmlspecialchars((string) $action['label']); ?></strong><?php echo htmlspecialchars((string) $action['hint']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </section>

    <details class="marketing-creative-tools" id="creative-evidence-tools">
        <summary>Creative evidence and queues</summary>
        <div class="marketing-creative-tools-body">

    <div class="content-card">
        <div class="premium-section-header">
            <div>
                <h2>AI Creative Workspace</h2>
                <p>Coordinate visual prompts, storyboards, captions, and readiness reviews without external generation or automatic overwrites.</p>
            </div>
            <a class="btn-premium-secondary" href="marketing_assets.php">Open Media Library</a>
        </div>
        <div class="creative-workspace-grid">
            <div class="creative-stat"><span>Open Briefs</span><strong><?php echo (int) ($creativeWorkspace['counts']['open_briefs'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Asset Requests</span><strong><?php echo (int) ($creativeWorkspace['counts']['open_asset_requests'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Content Needs Media</span><strong><?php echo (int) ($creativeWorkspace['counts']['content_needing_media'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Landing Needs Media</span><strong><?php echo (int) ($creativeWorkspace['counts']['landing_pages_needing_media'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Alt/Caption Gaps</span><strong><?php echo (int) ($creativeWorkspace['counts']['media_needing_accessibility'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Recent AI Runs</span><strong><?php echo (int) ($creativeWorkspace['counts']['recent_ai_runs'] ?? 0); ?></strong></div>
        </div>
        <div class="premium-section-header creative-section-spacer">
            <div>
                <h2>Creative Production Pipeline</h2>
                <p>Track the path from brief to request, due production, accessibility, and export-ready media.</p>
            </div>
        </div>
        <div class="creative-pipeline-grid">
            <?php foreach ((array) ($creativeWorkspace['pipeline_stages'] ?? []) as $stage): ?>
                <?php $stageStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($stage['status'] ?? 'empty')); ?>
                <a class="creative-pipeline-stage <?php echo htmlspecialchars($stageStatus); ?>" href="<?php echo htmlspecialchars((string) ($stage['href'] ?? 'marketing_creative.php')); ?>">
                    <strong><?php echo htmlspecialchars((string) ($stage['label'] ?? 'Pipeline Stage')); ?></strong>
                    <span class="stage-count"><?php echo (int) ($stage['count'] ?? 0); ?></span>
                    <em><?php echo htmlspecialchars((string) ($stage['detail'] ?? 'Review creative production status.')); ?></em>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="creative-recommendations">
            <?php foreach ((array) ($creativeWorkspace['recommendations'] ?? []) as $recommendation): ?>
                <a class="creative-recommendation" href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing_creative.php')); ?>">
                    <strong><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Review creative work')); ?></strong>
                    <span class="creative-meta"><?php echo htmlspecialchars((string) ($recommendation['reason'] ?? 'Review the next creative action.')); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="creative-meta creative-guardrail">Manual-first guardrail: AI creative outputs are saved as recommendations only; no media, content, landing page, export, send, or publish record is changed automatically.</p>
    </div>

    <div class="content-card" id="media-production-workflow">
        <div class="premium-section-header">
            <div>
                <h2>Media Production Workflow</h2>
                <p>Turn creative gaps into assigned work with format requirements, due dates, blockers, media links, and readiness checks.</p>
            </div>
            <a class="btn-premium-secondary" href="marketing_channel_exports.php">Channel Exports</a>
        </div>
        <div class="creative-workspace-grid">
            <div class="creative-stat"><span>Open Work</span><strong><?php echo (int) ($mediaProductionSummary['counts']['open'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Assigned To Me</span><strong><?php echo (int) ($mediaProductionSummary['counts']['assigned_to_me'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Overdue</span><strong><?php echo (int) ($mediaProductionSummary['counts']['overdue'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Blocked</span><strong><?php echo (int) ($mediaProductionSummary['counts']['blocked'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Ready</span><strong><?php echo (int) ($mediaProductionSummary['counts']['ready'] ?? 0); ?></strong></div>
            <div class="creative-stat"><span>Total</span><strong><?php echo (int) ($mediaProductionSummary['counts']['total'] ?? 0); ?></strong></div>
        </div>
        <?php if (empty($mediaProductionItems)): ?>
            <div class="empty-state"><p>No active media production work is assigned yet. Create a work item when content, landing pages, campaign requirements, or exports need visuals.</p></div>
        <?php else: ?>
            <div class="creative-alert-list">
                <?php foreach ($mediaProductionItems as $workItem): ?>
                    <?php
                        $workStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($workItem['status'] ?? 'requested'));
                        $readiness = (array) ($workItem['readiness_json'] ?? []);
                        $workChecklist = implode("\n", (array) ($workItem['checklist_json'] ?? []));
                    ?>
                    <div class="creative-alert <?php echo !empty($workItem['is_overdue']) ? 'overdue' : htmlspecialchars($workStatus); ?>">
                        <span class="creative-alert-badge <?php echo !empty($workItem['is_overdue']) ? 'overdue' : htmlspecialchars($workStatus); ?>"><?php echo htmlspecialchars($labelize((string) ($workItem['status'] ?? 'requested'))); ?></span>
                        <strong class="creative-alert-title"><?php echo htmlspecialchars((string) ($workItem['title'] ?? 'Media production work')); ?></strong>
                        <div class="creative-meta">
                            <?php echo htmlspecialchars($labelize((string) ($workItem['work_type'] ?? 'design'))); ?> &middot;
                            <?php echo htmlspecialchars($labelize((string) ($workItem['priority'] ?? 'normal'))); ?> &middot;
                            <?php echo (int) ($readiness['score'] ?? 0); ?>% ready
                            <?php if (!empty($workItem['campaign_name'])): ?> &middot; <?php echo htmlspecialchars((string) $workItem['campaign_name']); ?><?php endif; ?>
                            <?php if (!empty($workItem['assigned_to_email'])): ?> &middot; <?php echo htmlspecialchars((string) $workItem['assigned_to_email']); ?><?php endif; ?>
                        </div>
                        <div class="creative-meta"><strong>Next:</strong> <?php echo htmlspecialchars((string) ($readiness['message'] ?? 'Review production readiness.')); ?></div>
                        <div class="creative-meta">
                            <?php if (!empty($workItem['requested_format'])): ?><span><?php echo htmlspecialchars((string) $workItem['requested_format']); ?></span><?php endif; ?>
                            <?php if (!empty($workItem['dimensions'])): ?> &middot; <span><?php echo htmlspecialchars((string) $workItem['dimensions']); ?></span><?php endif; ?>
                            <?php if (!empty($workItem['due_at'])): ?> &middot; <span>Due <?php echo htmlspecialchars((string) $workItem['due_at']); ?></span><?php endif; ?>
                            <?php if (!empty($workItem['media_title'])): ?> &middot; <span><?php echo htmlspecialchars((string) $workItem['media_title']); ?></span><?php endif; ?>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="creative-update-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="media_work_update">
                                <input type="hidden" name="work_item_id" value="<?php echo (int) $workItem['id']; ?>">
                                <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::MEDIA_PRODUCTION_WORK_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo (string) ($workItem['status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::MEDIA_PRODUCTION_WORK_PRIORITIES as $priority): ?><option value="<?php echo htmlspecialchars($priority); ?>" <?php echo (string) ($workItem['priority'] ?? '') === $priority ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Assignee</label><select name="assigned_to"><option value="">Unassigned</option><?php foreach ((array) ($optionData['users'] ?? []) as $workspaceUser): ?><option value="<?php echo (int) $workspaceUser['id']; ?>" <?php echo (int) ($workItem['assigned_to'] ?? 0) === (int) $workspaceUser['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $workspaceUser['email']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Media</label><select name="media_file_id"><option value="">No media yet</option><?php foreach ($mediaFiles as $media): ?><option value="<?php echo (int) $media['id']; ?>" <?php echo (int) ($workItem['media_file_id'] ?? 0) === (int) $media['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $media['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group creative-form-wide"><label>Blocked Reason</label><input name="blocked_reason" value="<?php echo htmlspecialchars((string) ($workItem['blocked_reason'] ?? '')); ?>"></div>
                                <div class="form-group creative-form-wide"><label>Checklist</label><textarea name="checklist" rows="2"><?php echo htmlspecialchars($workChecklist); ?></textarea></div>
                                <div class="form-group creative-form-wide"><label>Production Notes</label><textarea name="production_notes" rows="2"><?php echo htmlspecialchars((string) ($workItem['production_notes'] ?? '')); ?></textarea></div>
                                <button class="btn-premium-secondary" type="submit">Update Work</button>
                            </form>
                            <?php if ($canManageMarketing): ?>
                                <form method="POST" class="creative-archive-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="media_work_archive">
                                    <input type="hidden" name="work_item_id" value="<?php echo (int) $workItem['id']; ?>">
                                    <button class="btn-premium-secondary" type="submit">Archive Work Item</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="creative-meta creative-guardrail"><?php echo htmlspecialchars((string) ($mediaProductionSummary['guardrails']['message'] ?? 'Media production workflow is manual-first and does not call external publishing or generation APIs.')); ?></p>
    </div>

    <div class="content-card" id="campaign-creative-requirements">
        <div class="premium-section-header">
            <div>
                <h2>Campaign Creative Requirements</h2>
                <p>Define the campaign-level hero, social, email, video, proof, and export media needed before manual launch.</p>
            </div>
            <a class="btn-premium-secondary" href="marketing_campaign_workspace.php<?php echo $campaignId > 0 ? '?campaign_id=' . (int) $campaignId : ''; ?>">Campaign Workspace</a>
        </div>
        <?php if ($campaignCreativeReadiness !== null): ?>
            <div class="creative-workspace-grid">
                <div class="creative-stat"><span>Readiness</span><strong><?php echo (int) ($campaignCreativeReadiness['readiness_score'] ?? 0); ?>%</strong></div>
                <div class="creative-stat"><span>Total</span><strong><?php echo (int) ($campaignCreativeReadiness['counts']['total'] ?? 0); ?></strong></div>
                <div class="creative-stat"><span>Ready</span><strong><?php echo (int) ($campaignCreativeReadiness['counts']['ready'] ?? 0); ?></strong></div>
                <div class="creative-stat"><span>Blocked</span><strong><?php echo (int) ($campaignCreativeReadiness['counts']['blocked'] ?? 0); ?></strong></div>
                <div class="creative-stat"><span>Missing Media</span><strong><?php echo (int) ($campaignCreativeReadiness['counts']['missing_media'] ?? 0); ?></strong></div>
                <div class="creative-stat"><span>Accessibility</span><strong><?php echo (int) ($campaignCreativeReadiness['counts']['needs_accessibility'] ?? 0); ?></strong></div>
            </div>
            <p class="creative-meta"><?php echo htmlspecialchars((string) ($campaignCreativeReadiness['guardrails']['message'] ?? 'Manual-first creative readiness only.')); ?></p>
        <?php endif; ?>
        <?php if (empty($campaignCreativeRequirements)): ?>
            <div class="empty-state"><p>No campaign creative requirements match this view yet.</p><?php if ($campaignId <= 0): ?><a href="marketing_campaign_workspace.php">Choose campaign</a><?php endif; ?></div>
        <?php else: ?>
            <div class="creative-alert-list">
                <?php foreach ($campaignCreativeRequirements as $requirement): ?>
                    <div class="creative-alert <?php echo htmlspecialchars((string) ($requirement['status'] ?? 'planned')); ?>">
                        <span class="creative-alert-badge <?php echo htmlspecialchars((string) ($requirement['status'] ?? 'planned')); ?>"><?php echo htmlspecialchars($labelize((string) ($requirement['status'] ?? 'planned'))); ?></span>
                        <strong class="creative-alert-title"><?php echo htmlspecialchars((string) ($requirement['title'] ?? 'Creative requirement')); ?></strong>
                        <div class="creative-meta">
                            <?php echo htmlspecialchars((string) ($requirement['campaign_name'] ?? 'Campaign')); ?> &middot;
                            <?php echo htmlspecialchars($labelize((string) ($requirement['requirement_type'] ?? 'image'))); ?> &middot;
                            <?php echo htmlspecialchars($labelize((string) ($requirement['priority'] ?? 'normal'))); ?>
                            <?php if (!empty($requirement['media_title'])): ?> &middot; <?php echo htmlspecialchars((string) $requirement['media_title']); ?><?php endif; ?>
                        </div>
                        <div class="creative-meta"><strong>Readiness:</strong> <?php echo htmlspecialchars((string) ($requirement['readiness_json']['message'] ?? 'Review creative readiness.')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

        </div>
    </details>

    <details class="marketing-creative-tools" id="creative-create-tools">
        <summary>Create and manage creative work</summary>
        <div class="marketing-creative-tools-body">

    <div class="creative-grid">
        <div>
            <div class="content-card">
                <div class="premium-section-header"><h2>Creative Attention Queue</h2></div>
                <div class="creative-alert-list">
                    <?php foreach ((array) ($creativeWorkspace['creative_queue'] ?? []) as $queueItem): ?>
                        <?php $severity = preg_replace('/[^a-z0-9_-]/i', '', (string) ($queueItem['severity'] ?? 'warning')); ?>
                        <a class="creative-alert <?php echo htmlspecialchars($severity); ?>" href="<?php echo htmlspecialchars((string) ($queueItem['href'] ?? 'marketing_creative.php')); ?>">
                            <span class="creative-alert-badge <?php echo htmlspecialchars($severity); ?>"><?php echo htmlspecialchars((string) ($queueItem['label'] ?? 'Creative gap')); ?></span>
                            <strong class="creative-alert-title"><?php echo htmlspecialchars((string) ($queueItem['title'] ?? 'Creative item')); ?></strong>
                            <div class="creative-meta"><?php echo htmlspecialchars((string) ($queueItem['detail'] ?? 'Review this creative production item.')); ?></div>
                            <div class="creative-meta"><strong>Next:</strong> <?php echo htmlspecialchars((string) ($queueItem['next_action'] ?? 'Review')); ?><?php if (!empty($queueItem['meta'])): ?> &middot; <?php echo htmlspecialchars(implode(' / ', (array) $queueItem['meta'])); ?><?php endif; ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($creativeWorkspace['creative_queue'])): ?>
                        <div class="empty-state"><p>No urgent creative gaps found. Package approved media into channel exports when the campaign is ready.</p></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="content-card">
                <div class="premium-section-header"><h2>Creative Briefs</h2></div>
                <?php if (empty($briefs)): ?><div class="empty-state"><p>No active creative briefs yet.</p></div><?php else: foreach ($briefs as $brief): ?>
                    <div class="creative-row"><div><strong><?php echo htmlspecialchars((string) $brief['title']); ?></strong><div class="creative-meta"><?php echo htmlspecialchars($labelize((string) $brief['asset_type'])); ?> &middot; <?php echo htmlspecialchars($labelize((string) $brief['status'])); ?><?php if (!empty($brief['content_title'])): ?> &middot; <?php echo htmlspecialchars((string) $brief['content_title']); ?><?php endif; ?></div></div><span><?php echo htmlspecialchars((string) ($brief['channel'] ?? '')); ?></span></div>
                <?php endforeach; endif; ?>
            </div>

            <div class="content-card">
                <div class="premium-section-header"><h2>Asset Requests</h2></div>
                <?php if (empty($requests)): ?><div class="empty-state"><p>No creative asset requests yet.</p></div><?php else: foreach ($requests as $request): ?>
                    <div class="creative-row"><div><strong><?php echo htmlspecialchars((string) $request['title']); ?></strong><div class="creative-meta"><?php echo htmlspecialchars($labelize((string) $request['requested_asset_type'])); ?> &middot; <?php echo htmlspecialchars($labelize((string) $request['status'])); ?><?php if (!empty($request['content_title'])): ?> &middot; <?php echo htmlspecialchars((string) $request['content_title']); ?><?php endif; ?></div></div><span><?php echo htmlspecialchars((string) ($request['due_at'] ?? '')); ?></span></div>
                <?php endforeach; endif; ?>
            </div>
            <div class="content-card creative-section-spacer">
                <div class="premium-section-header"><h2>AI Creative Runs</h2></div>
                <?php if (empty($creativeRuns)): ?><div class="empty-state"><p>No AI creative runs yet.</p></div><?php else: foreach ($creativeRuns as $run): ?>
                    <div class="creative-row"><div><strong><?php echo htmlspecialchars($labelize((string) $run['action'])); ?></strong><div class="creative-meta"><?php echo htmlspecialchars((string) ($run['content_title'] ?? $run['landing_page_title'] ?? $run['creative_brief_title'] ?? $run['asset_request_title'] ?? $run['media_title'] ?? 'General')); ?> &middot; <?php echo htmlspecialchars((string) $run['created_at']); ?></div><?php if (!empty($run['result_json'])): ?><div class="creative-run-preview"><span class="creative-meta">Saved recommendation preview</span><pre><?php echo htmlspecialchars($previewJson((array) $run['result_json'])); ?></pre></div><?php endif; ?></div><span><?php echo htmlspecialchars($labelize((string) $run['status'])); ?></span></div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="content-card">
            <div class="premium-section-header"><h2>Create</h2></div>
            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="creative-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="media_work">
                    <div class="form-group"><label>Media Production Work</label><input name="title" required placeholder="Design hero visual, edit launch video..."></div>
                    <div class="form-group"><label>Type</label><select name="work_type"><?php foreach (Marketing::MEDIA_PRODUCTION_WORK_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::MEDIA_PRODUCTION_WORK_PRIORITIES as $priority): ?><option value="<?php echo htmlspecialchars($priority); ?>"><?php echo htmlspecialchars($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">Optional</option><?php foreach ($campaignWorkspaces as $workspace): ?><option value="<?php echo (int) ($workspace['campaign_id'] ?? 0); ?>" <?php echo $campaignId === (int) ($workspace['campaign_id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($workspace['campaign_name'] ?? $workspace['workspace_name'] ?? 'Campaign')); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">Optional</option><?php foreach ($landingPages as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Campaign Requirement</label><select name="campaign_creative_requirement_id"><option value="">Optional</option><?php foreach ($campaignCreativeRequirements as $requirement): ?><option value="<?php echo (int) $requirement['id']; ?>"><?php echo htmlspecialchars((string) $requirement['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Creative Brief</label><select name="creative_brief_id"><option value="">Optional</option><?php foreach ($briefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Asset Request</label><select name="asset_request_id"><option value="">Optional</option><?php foreach ($requests as $request): ?><option value="<?php echo (int) $request['id']; ?>"><?php echo htmlspecialchars((string) $request['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Channel Export</label><select name="channel_export_bundle_id"><option value="">Optional</option><?php foreach ($channelBundles as $bundle): ?><option value="<?php echo (int) $bundle['id']; ?>"><?php echo htmlspecialchars((string) ($bundle['content_title'] ?? 'Channel export')); ?> - <?php echo htmlspecialchars((string) ($bundle['channel'] ?? '')); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Media</label><select name="media_file_id"><option value="">Optional</option><?php foreach ($mediaFiles as $media): ?><option value="<?php echo (int) $media['id']; ?>"><?php echo htmlspecialchars((string) $media['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Assignee</label><select name="assigned_to"><option value="">Unassigned</option><?php foreach ((array) ($optionData['users'] ?? []) as $workspaceUser): ?><option value="<?php echo (int) $workspaceUser['id']; ?>"><?php echo htmlspecialchars((string) $workspaceUser['email']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Channel</label><input name="channel" placeholder="Instagram, Email, Website"></div>
                    <div class="form-group"><label>Placement</label><input name="placement" placeholder="Hero, thumbnail, email banner"></div>
                    <div class="form-group"><label>Format</label><input name="requested_format" placeholder="PNG, MP4, 1:1 social crop"></div>
                    <div class="form-group"><label>Dimensions</label><input name="dimensions" placeholder="1080x1080, 16:9"></div>
                    <div class="form-group"><label>Due</label><input type="datetime-local" name="due_at"></div>
                    <div class="form-group"><label>Checklist</label><textarea name="checklist" rows="2" placeholder="Alt text&#10;Usage rights&#10;Approved crop"></textarea></div>
                    <div class="form-group"><label>Notes</label><textarea name="production_notes" rows="2"></textarea></div>
                    <button class="btn-premium-primary" type="submit">Create Media Work</button>
                </form>
                <form method="POST" class="creative-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="campaign_requirement">
                    <div class="form-group"><label>Campaign Requirement</label><input name="title" required placeholder="Hero image, launch video, email banner..."></div>
                    <div class="form-group"><label>Campaign</label><select name="campaign_id" required><option value="">Select campaign</option><?php foreach ($campaignWorkspaces as $workspace): ?><option value="<?php echo (int) ($workspace['campaign_id'] ?? 0); ?>" <?php echo $campaignId === (int) ($workspace['campaign_id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($workspace['campaign_name'] ?? $workspace['workspace_name'] ?? 'Campaign')); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Type</label><select name="requirement_type"><?php foreach (Marketing::CAMPAIGN_CREATIVE_REQUIREMENT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Priority</label><select name="priority"><?php foreach (Marketing::CAMPAIGN_CREATIVE_REQUIREMENT_PRIORITIES as $priority): ?><option value="<?php echo htmlspecialchars($priority); ?>"><?php echo htmlspecialchars($labelize($priority)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Channel</label><input name="channel" placeholder="Instagram, Email, Website"></div>
                    <div class="form-group"><label>Placement</label><input name="placement" placeholder="Hero, thumbnail, proof block"></div>
                    <div class="form-group"><label>Media</label><select name="media_file_id"><option value="">Optional</option><?php foreach ($mediaFiles as $media): ?><option value="<?php echo (int) $media['id']; ?>"><?php echo htmlspecialchars((string) $media['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">Optional</option><?php foreach ($landingPages as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Creative Brief</label><select name="creative_brief_id"><option value="">Optional</option><?php foreach ($briefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Asset Request</label><select name="asset_request_id"><option value="">Optional</option><?php foreach ($requests as $request): ?><option value="<?php echo (int) $request['id']; ?>"><?php echo htmlspecialchars((string) $request['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Channel Export</label><select name="channel_export_bundle_id"><option value="">Optional</option><?php foreach ($channelBundles as $bundle): ?><option value="<?php echo (int) $bundle['id']; ?>"><?php echo htmlspecialchars((string) ($bundle['content_title'] ?? 'Channel export')); ?> - <?php echo htmlspecialchars((string) ($bundle['channel'] ?? '')); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Due</label><input type="datetime-local" name="due_at"></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
                    <button class="btn-premium-primary" type="submit">Add Campaign Requirement</button>
                </form>
                <form method="POST" class="creative-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="ai_creative">
                    <div class="form-group"><label>AI Creative Tool</label><select name="creative_action"><?php foreach ($creativeActions as $actionValue => $actionLabel): ?><option value="<?php echo htmlspecialchars($actionValue); ?>"><?php echo htmlspecialchars($actionLabel); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">Optional</option><?php foreach ($landingPages as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>"><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Media File</label><select name="media_file_id"><option value="">Optional</option><?php foreach ($mediaFiles as $media): ?><option value="<?php echo (int) $media['id']; ?>"><?php echo htmlspecialchars((string) $media['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Creative Brief</label><select name="creative_brief_id"><option value="">Optional</option><?php foreach ($briefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Asset Request</label><select name="asset_request_id"><option value="">Optional</option><?php foreach ($requests as $request): ?><option value="<?php echo (int) $request['id']; ?>"><?php echo htmlspecialchars((string) $request['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Subject / Notes</label><input name="subject" placeholder="Optional visual direction"></div>
                    <button class="btn-premium-secondary" type="submit">Run AI Creative Tool</button>
                </form>
                <form method="POST" class="creative-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="brief">
                    <div class="form-group"><label>Brief Title</label><input name="title" required></div>
                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Asset Type</label><select name="asset_type"><?php foreach (Marketing::ASSET_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Channel</label><input name="channel"></div>
                    <div class="form-group"><label>Objective</label><textarea name="objective" rows="3"></textarea></div>
                    <div class="form-group"><label>Format</label><input name="format"></div>
                    <div class="form-group"><label>Dimensions</label><input name="dimensions"></div>
                    <button class="btn-premium-primary" type="submit">Create Brief</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="request">
                    <div class="form-group"><label>Request Title</label><input name="title" required></div>
                    <div class="form-group"><label>Content</label><select name="content_item_id"><option value="">Optional</option><?php foreach ($items as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars((string) $item['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Linked Brief</label><select name="creative_brief_id"><option value="">Optional</option><?php foreach ($briefs as $brief): ?><option value="<?php echo (int) $brief['id']; ?>"><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Asset Type</label><select name="requested_asset_type"><?php foreach (Marketing::ASSET_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Due</label><input type="datetime-local" name="due_at"></div>
                    <div class="form-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>
                    <button class="btn-premium-secondary" type="submit">Create Request</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><p>Read-only access.</p></div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </details>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
