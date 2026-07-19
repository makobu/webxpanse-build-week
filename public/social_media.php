<?php

/** Social Media plugin operator workspace. */

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\SocialMediaService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
$canWrite = Authorization::can('marketing.write', $user);
$canManage = Authorization::isSuperAdmin($user)
    || Authorization::can('marketing.manage', $user)
    || Authorization::can('workspace.skills.manage', $user);
$social = new SocialMediaService($workspaceId);
$social->assertInstalled();
$error = '';
$notice = '';
$noticeLevel = 'success';
$variants = [];
$handoffContext = [];
$composer = [
    'campaign_id' => '',
    'content_item_id' => (string) ($_GET['content_item_id'] ?? ''),
    'distribution_post_id' => (string) ($_GET['distribution_post_id'] ?? ''),
    'utm_link_id' => (string) ($_GET['utm_link_id'] ?? ''),
    'objective' => '',
    'audience' => '',
    'offer' => '',
    'tone' => '',
    'cta' => '',
    'caption' => '',
    'link_url' => '',
    'media_url' => '',
    'media_type' => 'image',
    'scheduled_at' => date('Y-m-d\TH:i', time() + 3600),
    'account_ids' => [],
    'client_request_id' => bin2hex(random_bytes(16)),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your security token expired. Refresh the page and try again.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        $composer = array_merge($composer, [
            'campaign_id' => (string) ($_POST['campaign_id'] ?? ''),
            'content_item_id' => (string) ($_POST['content_item_id'] ?? ''),
            'distribution_post_id' => (string) ($_POST['distribution_post_id'] ?? ''),
            'utm_link_id' => (string) ($_POST['utm_link_id'] ?? ''),
            'objective' => (string) ($_POST['objective'] ?? ''),
            'audience' => (string) ($_POST['audience'] ?? ''),
            'offer' => (string) ($_POST['offer'] ?? ''),
            'tone' => (string) ($_POST['tone'] ?? ''),
            'cta' => (string) ($_POST['cta'] ?? ''),
            'caption' => (string) ($_POST['caption'] ?? ''),
            'link_url' => (string) ($_POST['link_url'] ?? ''),
            'media_url' => (string) ($_POST['media_url'] ?? ''),
            'media_type' => (string) ($_POST['media_type'] ?? 'image'),
            'scheduled_at' => (string) ($_POST['scheduled_at'] ?? ''),
            'account_ids' => array_values(array_filter(array_map('intval', (array) ($_POST['account_ids'] ?? [])))),
            'client_request_id' => (string) ($_POST['client_request_id'] ?? $composer['client_request_id']),
        ]);

        if (in_array($action, ['generate_variants', 'create_publish_jobs', 'cancel_job'], true) && !$canWrite) {
            throw new RuntimeException('Your access profile can view Social Media but cannot change publishing work.');
        }
        if (in_array($action, ['approve_job', 'retry_job', 'verify_account', 'disconnect_account'], true) && !$canManage) {
            throw new RuntimeException('Manager access is required for approvals, retries, and account controls.');
        }

        if ($action === 'generate_variants') {
            $variants = $social->generateVariants(
                (string) $composer['caption'],
                (array) $composer['account_ids'],
                $userId,
                !empty($_POST['use_ai']),
                [
                    'objective' => (string) $composer['objective'],
                    'audience' => (string) $composer['audience'],
                    'offer' => (string) $composer['offer'],
                    'tone' => (string) $composer['tone'],
                    'cta' => (string) $composer['cta'],
                ]
            );
            $generationStatus = $social->getLastVariantGenerationStatus();
            if (!empty($_POST['use_ai']) && empty($generationStatus['used_ai'])) {
                $noticeLevel = 'warning';
                $notice = 'AI content generation was unavailable, so safe local variants were created instead. '
                    . trim((string) ($generationStatus['message'] ?? 'Add the Content Generation key in AI API setup.'));
            } else {
                $notice = !empty($generationStatus['used_ai'])
                    ? 'AI channel variants are ready from the dedicated Content Generation route. Review every destination before scheduling.'
                    : 'Local channel variants are ready. Review every destination before scheduling.';
            }
        } elseif ($action === 'create_publish_jobs') {
            $media = trim((string) $composer['media_url']) !== '' ? [[
                'url' => (string) $composer['media_url'],
                'type' => (string) $composer['media_type'],
                'alt_text' => (string) ($_POST['media_alt_text'] ?? ''),
            ]] : [];
            $result = $social->createPublishJobs([
                'campaign_id' => (int) $composer['campaign_id'],
                'content_item_id' => (int) $composer['content_item_id'],
                'distribution_post_id' => (int) $composer['distribution_post_id'],
                'utm_link_id' => (int) $composer['utm_link_id'],
                'objective' => (string) $composer['objective'],
                'target_audience' => (string) $composer['audience'],
                'offer' => (string) $composer['offer'],
                'tone' => (string) $composer['tone'],
                'cta' => (string) $composer['cta'],
                'caption' => (string) $composer['caption'],
                'link_url' => (string) $composer['link_url'],
                'scheduled_at' => !empty($_POST['publish_now']) ? '' : (string) $composer['scheduled_at'],
                'account_ids' => (array) $composer['account_ids'],
                'variants' => (array) ($_POST['variants'] ?? []),
                'media' => $media,
                'client_request_id' => (string) $composer['client_request_id'],
            ], $userId);
            $message = !empty($result['approval_required']) ? 'created_for_approval' : 'scheduled';
            header('Location: social_media.php?tab=calendar&success=' . $message . '&count=' . (int) ($result['count'] ?? 0));
            exit;
        } elseif ($action === 'approve_job') {
            $social->approveJob((int) ($_POST['job_id'] ?? 0), $userId);
            header('Location: social_media.php?tab=calendar&success=approved');
            exit;
        } elseif ($action === 'cancel_job') {
            $social->cancelJob((int) ($_POST['job_id'] ?? 0), $userId);
            header('Location: social_media.php?tab=calendar&success=cancelled');
            exit;
        } elseif ($action === 'retry_job') {
            $social->retryJob((int) ($_POST['job_id'] ?? 0), $userId);
            header('Location: social_media.php?tab=calendar&success=retried');
            exit;
        } elseif ($action === 'verify_account') {
            $social->verifyAccount((int) ($_POST['account_id'] ?? 0), $userId);
            header('Location: social_media.php?tab=accounts&success=verified');
            exit;
        } elseif ($action === 'disconnect_account') {
            $social->disconnectAccount((int) ($_POST['account_id'] ?? 0), $userId);
            header('Location: social_media.php?tab=accounts&success=disconnected');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$handoffDistributionId = max(0, (int) ($composer['distribution_post_id'] ?? 0));
$handoffContentId = max(0, (int) ($composer['content_item_id'] ?? 0));
$handoffUtmId = max(0, (int) ($composer['utm_link_id'] ?? 0));
if ($handoffDistributionId > 0 || $handoffContentId > 0 || $handoffUtmId > 0) {
    $distribution = $handoffDistributionId > 0
        ? (Database::queryOne(
            'SELECT id, content_item_id, channel, planned_copy, status FROM marketing_distribution_posts WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $handoffDistributionId]
        ) ?: [])
        : [];
    if ($handoffDistributionId > 0 && $distribution === [] && $error === '') {
        $error = 'The prepared distribution handoff was not found in this workspace.';
    }
    if ($distribution !== []) {
        $distributionContentId = (int) ($distribution['content_item_id'] ?? 0);
        if ($handoffContentId > 0 && $handoffContentId !== $distributionContentId && $error === '') {
            $error = 'The prepared distribution handoff does not match the selected content item.';
        }
        $handoffContentId = $distributionContentId;
        $composer['content_item_id'] = (string) $handoffContentId;
    }

    $contentItem = $handoffContentId > 0
        ? (Database::queryOne(
            'SELECT id, title, campaign_id, objective, target_audience, draft_body FROM marketing_content_items WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $handoffContentId]
        ) ?: [])
        : [];
    if ($handoffContentId > 0 && $contentItem === [] && $error === '') {
        $error = 'The prepared content item was not found in this workspace.';
    }

    $utmLink = $handoffUtmId > 0
        ? (Database::queryOne(
            'SELECT id, campaign_id, content_item_id, generated_url FROM marketing_utm_links WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $handoffUtmId]
        ) ?: [])
        : [];
    if ($handoffUtmId > 0 && $utmLink === [] && $error === '') {
        $error = 'The prepared tracking link was not found in this workspace.';
    }
    if ($utmLink !== [] && $handoffContentId > 0 && (int) ($utmLink['content_item_id'] ?? 0) > 0
        && (int) $utmLink['content_item_id'] !== $handoffContentId && $error === '') {
        $error = 'The prepared tracking link does not match the selected content item.';
    }

    $lineage = [];
    if ($contentItem !== [] && Database::tableExists('marketing_generation_artifacts')) {
        $lineage = Database::queryOne(
            "SELECT a.id AS artifact_id, a.generation_run_id, a.channel, r.title AS run_title,
                    r.offer_text, r.cta_text
             FROM marketing_generation_artifacts a
             JOIN marketing_generation_runs r ON r.id = a.generation_run_id AND r.workspace_id = a.workspace_id
             WHERE a.workspace_id = ? AND a.accepted_content_item_id = ?
             ORDER BY a.accepted_at DESC, a.id DESC
             LIMIT 1",
            [$workspaceId, $handoffContentId]
        ) ?: [];
    }

    $handoffContext = [
        'content_title' => (string) ($contentItem['title'] ?? 'Prepared campaign content'),
        'run_title' => (string) ($lineage['run_title'] ?? ''),
        'channel' => (string) ($distribution['channel'] ?? $lineage['channel'] ?? ''),
        'distribution_status' => (string) ($distribution['status'] ?? ''),
        'tracked' => !empty($utmLink['generated_url']),
    ];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $error === '') {
        $composer['campaign_id'] = (string) ((int) ($contentItem['campaign_id'] ?? $utmLink['campaign_id'] ?? 0) ?: '');
        $composer['objective'] = (string) ($contentItem['objective'] ?? '');
        $composer['audience'] = (string) ($contentItem['target_audience'] ?? '');
        $composer['offer'] = (string) ($lineage['offer_text'] ?? '');
        $composer['cta'] = (string) ($lineage['cta_text'] ?? '');
        $composer['caption'] = (string) (($distribution['planned_copy'] ?? '') ?: ($contentItem['draft_body'] ?? ''));
        $composer['link_url'] = (string) ($utmLink['generated_url'] ?? '');
    }
}

$createdCount = max(0, (int) ($_GET['count'] ?? 0));
$destinationLabel = $createdCount === 1 ? 'destination' : 'destinations';
$successMessages = [
    'created_for_approval' => $createdCount > 0
        ? $createdCount . ' ' . $destinationLabel . ' created and waiting for approval.'
        : 'Post variants were created and are waiting for approval.',
    'scheduled' => $createdCount > 0
        ? $createdCount . ' ' . $destinationLabel . ' added to the publishing queue.'
        : 'Post variants were approved and added to the publishing queue.',
    'approved' => 'The post was approved and queued.',
    'cancelled' => 'The post was cancelled.',
    'retried' => 'The failed post was returned to the queue.',
    'verified' => 'The social account was verified.',
    'disconnected' => 'The social account was disconnected and its pending jobs were cancelled.',
];
if ($notice === '' && isset($successMessages[(string) ($_GET['success'] ?? '')])) {
    $notice = $successMessages[(string) $_GET['success']];
}

$settings = $social->getSettings();
$accounts = $social->listAccounts();
$activeAccounts = array_values(array_filter($accounts, static fn(array $account): bool => (string) ($account['status'] ?? '') === 'active'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $handoffContext !== [] && (array) $composer['account_ids'] === []) {
    $sourceChannel = (string) ($handoffContext['channel'] ?? '');
    $composer['account_ids'] = array_values(array_map(
        static fn(array $account): int => (int) $account['id'],
        array_filter($activeAccounts, static fn(array $account): bool => (string) ($account['channel'] ?? '') === $sourceChannel)
    ));
}
$summary = $social->dashboardSummary();
$jobs = $social->listJobs([], 120);
$events = $social->recentEvents(20);
$readiness = $social->platformReadiness();
$contentAiReadiness = $social->contentGenerationReadiness();
$contentAiReady = !empty($contentAiReadiness['available']);
$campaigns = Database::query(
    "SELECT id, name, status FROM campaigns WHERE workspace_id = ? ORDER BY updated_at DESC LIMIT 100",
    [$workspaceId]
);
$activeTab = strtolower(trim((string) ($_GET['tab'] ?? 'composer')));
if (!in_array($activeTab, ['overview', 'composer', 'calendar', 'insights', 'accounts'], true)) {
    $activeTab = 'composer';
}
$channelIcons = ['facebook' => 'fa-facebook-f', 'instagram' => 'fa-instagram', 'linkedin' => 'fa-linkedin-in'];
$statusLabels = [
    'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'queued' => 'Queued',
    'processing' => 'Publishing', 'published' => 'Published', 'failed' => 'Failed',
    'cancelled' => 'Cancelled', 'draft' => 'Draft',
];
$formatNumber = static fn($value): string => number_format((int) $value);
$pluginQuickStart = [
    'key' => 'social-media',
    'outcome' => 'Schedule your first on-brand post',
    'steps' => [
        ['label' => 'Connect a channel', 'complete' => (int) ($summary['active_accounts'] ?? 0) > 0, 'href' => 'workspace_skills.php?module=social_media&setup_tab=connections#setup', 'action_label' => 'Connect channel'],
        ['label' => 'Create your first post', 'complete' => (int) ($summary['total_jobs'] ?? 0) > 0, 'href' => 'social_media.php?tab=composer', 'action_label' => 'Create post'],
        ['label' => 'Schedule or publish', 'complete' => ((int) ($summary['queued'] ?? 0) + (int) ($summary['published'] ?? 0)) > 0, 'href' => 'social_media.php?tab=calendar', 'action_label' => 'Review post'],
    ],
    'completion_action' => ['href' => 'social_media.php?tab=insights', 'action_label' => 'Open insights'],
];
$pageTitle = 'Social Media - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/social-media.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/social-media.css') ?: 1); ?>">
<link rel="stylesheet" href="assets/css/plugin-workspaces.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/plugin-workspaces.css') ?: 1); ?>">
<script src="assets/js/plugin-workspaces.js?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/js/plugin-workspaces.js') ?: 1); ?>" defer></script>
<div class="page-premium social-media-page plugin-workspace-shell" data-social-workspace>
    <div class="container social-media-container">
        <header class="page-header social-media-header">
            <div>
                <h1>Social Media</h1>
                <p>Plan, approve and publish.</p>
            </div>
            <div class="page-header-actions">
                <a class="workspace-setup-link" href="workspace_skills.php?module=social_media&setup_tab=connections#setup">Plugin setup</a>
                <?php if ($canWrite): ?><button class="btn-premium-primary" type="button" data-social-open-composer><i class="fas fa-pen"></i> Create post</button><?php endif; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><strong>Social Media could not complete that action.</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $noticeLevel === 'warning' ? 'warning' : 'success'; ?>" role="status"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <section class="social-summary" aria-label="Social publishing summary">
            <article><span class="social-summary-icon is-blue"><i class="fas fa-link"></i></span><div><small>Accounts</small><strong><?php echo $formatNumber($summary['active_accounts'] ?? 0); ?></strong></div></article>
            <article><span class="social-summary-icon is-amber"><i class="fas fa-clock"></i></span><div><small>Approvals</small><strong><?php echo $formatNumber($summary['pending_approval'] ?? 0); ?></strong></div></article>
            <article><span class="social-summary-icon is-blue"><i class="fas fa-calendar"></i></span><div><small>Scheduled</small><strong><?php echo $formatNumber($summary['queued'] ?? 0); ?></strong></div></article>
            <article><span class="social-summary-icon is-green"><i class="fas fa-arrow-trend-up"></i></span><div><small>Published</small><strong><?php echo $formatNumber($summary['published'] ?? 0); ?></strong></div></article>
        </section>

        <?php include __DIR__ . '/../views/partials/plugin_product_quick_start.php'; ?>

        <nav class="social-tabs" aria-label="Social Media workspace sections" role="tablist">
            <?php foreach (['overview' => 'Overview', 'composer' => 'Composer', 'calendar' => 'Calendar', 'insights' => 'Insights', 'accounts' => 'Accounts'] as $key => $label): ?>
                <a id="social-tab-<?php echo $key; ?>" href="social_media.php?tab=<?php echo $key; ?>" role="tab" aria-controls="social-panel-<?php echo $key; ?>" aria-selected="<?php echo $activeTab === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $activeTab === $key ? '0' : '-1'; ?>" class="<?php echo $activeTab === $key ? 'is-active' : ''; ?>" data-social-tab="<?php echo $key; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>

        <section id="social-panel-overview" class="social-panel <?php echo $activeTab === 'overview' ? 'is-active' : ''; ?>" role="tabpanel" aria-labelledby="social-tab-overview" data-social-panel="overview" <?php echo $activeTab === 'overview' ? '' : 'hidden'; ?>>
            <div class="social-overview-layout">
                <div class="social-section-block">
                    <div class="social-section-heading"><div><h2>Overview</h2></div></div>
                    <div class="social-health-grid">
                        <div><span>Provider reach</span><strong><?php echo $formatNumber($summary['reach'] ?? 0); ?></strong><small>Latest 30-day snapshots</small></div>
                        <div><span>Tracked visitors</span><strong><?php echo $formatNumber($summary['visitors'] ?? 0); ?></strong><small>Organic social UTM traffic</small></div>
                        <div><span>CRM conversions</span><strong><?php echo $formatNumber($summary['conversions'] ?? 0); ?></strong><small>Form submits and conversions</small></div>
                    </div>
                    <div class="social-activity-list">
                        <?php if ($events === []): ?><p class="social-empty">Activity will appear after settings, connections, approvals, and publishing events.</p><?php endif; ?>
                        <?php foreach (array_slice($events, 0, 8) as $event): ?>
                            <div><span class="social-event-dot is-<?php echo htmlspecialchars((string) ($event['status'] ?? 'info')); ?>"></span><p><strong><?php echo htmlspecialchars((string) ($event['message'] ?? 'Social Media event')); ?></strong><small><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($event['created_at'] ?? 'now')))); ?></small></p></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <aside class="social-section-block social-next-step">
                    <h2>Ready to publish</h2>
                    <dl><div><dt>Brand</dt><dd><?php echo htmlspecialchars((string) (($settings['brand_name'] ?? '') ?: 'Needs setup')); ?></dd></div><div><dt>Approval</dt><dd><?php echo !empty($settings['approval_required']) ? 'Required' : 'Automatic'; ?></dd></div><div><dt>Timezone</dt><dd><?php echo htmlspecialchars((string) ($settings['timezone'] ?? 'Africa/Nairobi')); ?></dd></div></dl>
                    <a class="btn-premium-primary" href="workspace_skills.php?module=social_media&setup_tab=brand#setup">Review setup</a>
                </aside>
            </div>
        </section>

        <section id="social-panel-composer" class="social-panel <?php echo $activeTab === 'composer' ? 'is-active' : ''; ?>" role="tabpanel" aria-labelledby="social-tab-composer" data-social-panel="composer" <?php echo $activeTab === 'composer' ? '' : 'hidden'; ?>>
            <form method="POST" class="social-composer" data-social-composer>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                <input type="hidden" name="client_request_id" value="<?php echo htmlspecialchars((string) $composer['client_request_id']); ?>">
                <input type="hidden" name="content_item_id" value="<?php echo (int) $composer['content_item_id']; ?>">
                <input type="hidden" name="distribution_post_id" value="<?php echo (int) $composer['distribution_post_id']; ?>">
                <input type="hidden" name="utm_link_id" value="<?php echo (int) $composer['utm_link_id']; ?>">
                <div class="social-composer-main">
                    <?php if ($handoffContext !== []): ?>
                        <div class="social-ai-readiness is-ready" role="status">
                            <i class="fas fa-route"></i>
                            <div>
                                <strong>Prepared from Campaign Kit</strong>
                                <small><?php echo htmlspecialchars((string) $handoffContext['content_title']); ?><?php echo !empty($handoffContext['tracked']) ? ' · tracked destination ready' : ''; ?>. Choose accounts and timing, then submit when ready.</small>
                            </div>
                            <?php if ((int) ($lineage['generation_run_id'] ?? 0) > 0): ?><a href="marketing_campaign_kit.php?run=<?php echo (int) $lineage['generation_run_id']; ?>">Return to kit</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="social-ai-readiness <?php echo $contentAiReady ? 'is-ready' : 'is-missing'; ?>">
                        <i class="fas <?php echo $contentAiReady ? 'fa-circle-check' : 'fa-key'; ?>"></i>
                        <div><strong>Content AI <?php echo $contentAiReady ? 'ready' : 'needs a key'; ?></strong></div>
                        <a href="workspace_skills.php?module=ai_api&amp;setup_tab=provider#setup"><?php echo $contentAiReady ? 'Review key' : 'Add key'; ?></a>
                    </div>
                    <div class="social-form-field">
                        <label for="social-campaign">Campaign</label>
                        <select id="social-campaign" name="campaign_id" <?php echo $canWrite ? '' : 'disabled'; ?>>
                            <option value="">No campaign</option>
                            <?php foreach ($campaigns as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo (int) $composer['campaign_id'] === (int) $campaign['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="social-content-brief">
                        <div class="social-section-heading"><div><h2>Post brief</h2></div></div>
                        <div class="social-form-grid">
                            <div class="social-form-field"><label for="social-objective">Objective</label><input id="social-objective" type="text" name="objective" maxlength="500" value="<?php echo htmlspecialchars((string) $composer['objective']); ?>" placeholder="Drive demo bookings" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                            <div class="social-form-field"><label for="social-tone">Tone</label><input id="social-tone" type="text" name="tone" maxlength="180" value="<?php echo htmlspecialchars((string) $composer['tone']); ?>" placeholder="Confident, practical, warm" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                            <div class="social-form-field"><label for="social-audience">Audience</label><textarea id="social-audience" name="audience" rows="3" maxlength="2000" placeholder="Who should respond to this post?" <?php echo $canWrite ? '' : 'disabled'; ?>><?php echo htmlspecialchars((string) $composer['audience']); ?></textarea></div>
                            <div class="social-form-field"><label for="social-offer">Offer or product context</label><textarea id="social-offer" name="offer" rows="3" maxlength="2000" placeholder="What is being offered, and what is factually true?" <?php echo $canWrite ? '' : 'disabled'; ?>><?php echo htmlspecialchars((string) $composer['offer']); ?></textarea></div>
                            <div class="social-form-field"><label for="social-cta">Call to action</label><input id="social-cta" type="text" name="cta" maxlength="1000" value="<?php echo htmlspecialchars((string) $composer['cta']); ?>" placeholder="Book a demo" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                        </div>
                    </div>
                    <div class="social-form-field social-copy-field">
                        <div class="social-label-row"><label for="social-caption">Post copy</label><span data-social-character-count aria-live="polite"><?php echo mb_strlen((string) $composer['caption']); ?> characters</span></div>
                        <textarea id="social-caption" name="caption" rows="7" maxlength="6000" placeholder="Write the core message, offer, or update..." required <?php echo $canWrite ? '' : 'disabled'; ?>><?php echo htmlspecialchars((string) $composer['caption']); ?></textarea>
                    </div>
                    <div class="social-form-grid">
                        <div class="social-form-field"><label for="social-link">Destination link</label><input id="social-link" type="url" name="link_url" value="<?php echo htmlspecialchars((string) $composer['link_url']); ?>" placeholder="https://example.com/offer" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                        <div class="social-form-field"><label for="social-media-url">Media URL</label><input id="social-media-url" type="url" name="media_url" value="<?php echo htmlspecialchars((string) $composer['media_url']); ?>" placeholder="Public image or video URL" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                        <div class="social-form-field"><label for="social-media-type">Media type</label><select id="social-media-type" name="media_type" <?php echo $canWrite ? '' : 'disabled'; ?>><option value="image" <?php echo $composer['media_type'] === 'image' ? 'selected' : ''; ?>>Image</option><option value="video" <?php echo $composer['media_type'] === 'video' ? 'selected' : ''; ?>>Video</option></select></div>
                        <div class="social-form-field"><label for="social-scheduled">Publish date and time</label><input id="social-scheduled" type="datetime-local" name="scheduled_at" value="<?php echo htmlspecialchars((string) $composer['scheduled_at']); ?>" data-social-schedule data-social-editable="<?php echo $canWrite ? '1' : '0'; ?>" <?php echo $canWrite ? '' : 'disabled'; ?>></div>
                    </div>
                    <label class="social-checkbox social-publish-now"><input type="checkbox" name="publish_now" value="1" data-social-publish-now <?php echo $canWrite ? '' : 'disabled'; ?>> Publish as soon as the queue worker runs</label>
                    <div class="social-approval-note"><i class="fas fa-shield-halved"></i><span><strong><?php echo !empty($settings['approval_required']) ? 'Approval required' : 'Automatic approval'; ?></strong><?php echo !empty($settings['approval_required']) ? 'A marketing manager must approve each destination before it enters the live queue.' : 'Posts enter the live queue when saved.'; ?></span></div>
                </div>

                <div class="social-composer-destinations">
                    <div class="social-section-heading"><div><h2>Channels</h2></div><span><?php echo count($activeAccounts); ?> available</span></div>
                    <?php if ($activeAccounts === []): ?>
                        <div class="social-attention-state"><i class="fas fa-link-slash"></i><div><strong>Connect a destination</strong><a class="social-inline-action" href="workspace_skills.php?module=social_media&setup_tab=connections#setup">Open connections</a></div></div>
                    <?php endif; ?>
                    <div class="social-channel-list">
                        <?php foreach ($activeAccounts as $account): $accountId = (int) $account['id']; $channel = (string) $account['channel']; $checked = in_array($accountId, (array) $composer['account_ids'], true); ?>
                            <label class="social-channel-row">
                                <input type="checkbox" name="account_ids[]" value="<?php echo $accountId; ?>" <?php echo $checked ? 'checked' : ''; ?> <?php echo $canWrite ? '' : 'disabled'; ?>>
                                <span class="social-channel-icon is-<?php echo htmlspecialchars($channel); ?>"><i class="fab <?php echo htmlspecialchars($channelIcons[$channel] ?? 'fa-share-nodes'); ?>"></i></span>
                                <span><strong><?php echo htmlspecialchars(ucfirst($channel)); ?></strong><small><?php echo htmlspecialchars((string) $account['account_name']); ?><?php echo !empty($account['account_handle']) ? ' · ' . htmlspecialchars((string) $account['account_handle']) : ''; ?></small></span>
                                <em>Healthy</em>
                            </label>
                            <?php if (array_key_exists($accountId, $variants) || array_key_exists($channel, $variants)): ?>
                                <label class="social-variant"><span><?php echo htmlspecialchars(ucfirst($channel)); ?> variant</span><textarea name="variants[<?php echo $accountId; ?>]" rows="5" maxlength="6000"><?php echo htmlspecialchars((string) ($variants[$accountId] ?? $variants[$channel] ?? '')); ?></textarea></label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="social-composer-actions">
                            <button class="btn-premium-secondary" type="submit" name="action" value="generate_variants" <?php echo $activeAccounts === [] ? 'disabled' : ''; ?>><i class="fas fa-wand-magic-sparkles"></i> Generate variants</button>
                            <label class="social-ai-toggle"><input type="checkbox" name="use_ai" value="1" <?php echo ($activeAccounts === [] || !$contentAiReady) ? 'disabled' : 'checked'; ?>> Use Content Generation key</label>
                            <button class="btn-premium-primary" type="submit" name="action" value="create_publish_jobs" <?php echo $activeAccounts === [] ? 'disabled' : ''; ?>><i class="fas fa-calendar-check"></i> Schedule post</button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
            <aside class="social-composer-rail">
                <section><div class="social-section-heading"><div><h2>Account health</h2></div><a class="social-inline-action" href="social_media.php?tab=accounts">Manage</a></div>
                    <?php if ($accounts === []): ?><p class="social-empty">No social accounts connected.</p><?php endif; ?>
                    <?php foreach (array_slice($accounts, 0, 5) as $account): ?><div class="social-health-row"><span class="social-channel-icon is-<?php echo htmlspecialchars((string) $account['channel']); ?>"><i class="fab <?php echo htmlspecialchars($channelIcons[(string) $account['channel']] ?? 'fa-share-nodes'); ?>"></i></span><p><strong><?php echo htmlspecialchars((string) $account['account_name']); ?></strong><small><?php echo htmlspecialchars(ucfirst((string) $account['channel'])); ?></small></p><em class="is-<?php echo htmlspecialchars((string) $account['status']); ?>"><?php echo htmlspecialchars(ucfirst((string) $account['status'])); ?></em></div><?php endforeach; ?>
                </section>
                <section><div class="social-section-heading"><div><h2>Upcoming queue</h2></div><a class="social-inline-action" href="social_media.php?tab=calendar">View calendar</a></div>
                    <?php $upcoming = array_values(array_filter($jobs, static fn(array $job): bool => in_array((string) $job['status'], ['pending_approval','queued','processing'], true))); ?>
                    <?php if ($upcoming === []): ?><p class="social-empty">No posts are waiting in the queue.</p><?php endif; ?>
                    <?php foreach (array_slice($upcoming, 0, 6) as $job): ?><div class="social-queue-row"><span class="social-channel-icon is-<?php echo htmlspecialchars((string) $job['channel']); ?>"><i class="fab <?php echo htmlspecialchars($channelIcons[(string) $job['channel']] ?? 'fa-share-nodes'); ?>"></i></span><p><strong><?php echo htmlspecialchars(mb_strimwidth((string) $job['caption'], 0, 54, '…')); ?></strong><small><?php echo htmlspecialchars(date('M j · g:i A', strtotime((string) ($job['scheduled_at'] ?? $job['created_at'])))); ?></small></p><em><?php echo htmlspecialchars($statusLabels[(string) $job['status']] ?? ucfirst((string) $job['status'])); ?></em></div><?php endforeach; ?>
                </section>
            </aside>
        </section>

        <section id="social-panel-calendar" class="social-panel <?php echo $activeTab === 'calendar' ? 'is-active' : ''; ?>" role="tabpanel" aria-labelledby="social-tab-calendar" data-social-panel="calendar" <?php echo $activeTab === 'calendar' ? '' : 'hidden'; ?>>
            <div class="social-section-block">
                <div class="social-section-heading"><div><h2>Publishing timeline</h2><p>Approval, queue, provider result, and retry state by destination.</p></div><button class="btn-premium-secondary" type="button" data-social-open-composer>Create post</button></div>
                <?php if ($jobs === []): ?><div class="social-empty-large"><i class="fas fa-calendar-plus"></i><h3>No publishing jobs yet</h3><p>Create the first controlled post from Composer.</p></div><?php endif; ?>
                <div class="social-job-list">
                    <?php foreach ($jobs as $job): $status = (string) $job['status']; ?>
                        <article class="social-job-row">
                            <span class="social-channel-icon is-<?php echo htmlspecialchars((string) $job['channel']); ?>"><i class="fab <?php echo htmlspecialchars($channelIcons[(string) $job['channel']] ?? 'fa-share-nodes'); ?>"></i></span>
                            <div class="social-job-copy"><strong><?php echo htmlspecialchars(mb_strimwidth((string) $job['caption'], 0, 130, '…')); ?></strong><small><?php echo htmlspecialchars((string) $job['account_name']); ?> · <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($job['scheduled_at'] ?? $job['created_at'])))); ?></small><?php if (!empty($job['error_message'])): ?><p><?php echo htmlspecialchars((string) $job['error_message']); ?></p><?php endif; ?></div>
                            <span class="social-status is-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($statusLabels[$status] ?? ucfirst($status)); ?></span>
                            <div class="social-job-actions">
                                <?php if ($status === 'pending_approval' && $canManage): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="approve_job"><input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>"><button class="btn-premium-primary" type="submit">Approve</button></form><?php endif; ?>
                                <?php if ($status === 'failed' && $canManage): ?><form method="POST" <?php echo (string) ($job['error_class'] ?? '') === 'delivery_status_unknown' ? 'onsubmit="return confirm(\'Confirm the provider did not publish this post before retrying. Continue?\');"' : ''; ?>><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="retry_job"><input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>"><button class="btn-premium-secondary" type="submit"><?php echo (string) ($job['error_class'] ?? '') === 'delivery_status_unknown' ? 'Retry after check' : 'Retry'; ?></button></form><?php endif; ?>
                                <?php if (in_array($status, ['draft','pending_approval','approved','queued'], true) && $canWrite): ?><form method="POST" onsubmit="return confirm('Cancel this social publishing job?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="cancel_job"><input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>"><button class="btn-premium-secondary" type="submit">Cancel</button></form><?php endif; ?>
                                <?php if ($status === 'published' && !empty($job['provider_url'])): ?><a class="btn-premium-secondary" href="<?php echo htmlspecialchars((string) $job['provider_url']); ?>" target="_blank" rel="noopener">View post</a><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="social-panel-insights" class="social-panel <?php echo $activeTab === 'insights' ? 'is-active' : ''; ?>" role="tabpanel" aria-labelledby="social-tab-insights" data-social-panel="insights" <?php echo $activeTab === 'insights' ? '' : 'hidden'; ?>>
            <div class="social-section-block">
                <div class="social-section-heading"><div><h2>Recent performance</h2><p>Latest provider snapshot per published destination, plus CRM-attributed social outcomes.</p></div><span>Last 30 days</span></div>
                <div class="social-insight-strip"><div><small>Impressions</small><strong><?php echo $formatNumber($summary['impressions'] ?? 0); ?></strong></div><div><small>Reach</small><strong><?php echo $formatNumber($summary['reach'] ?? 0); ?></strong></div><div><small>Engagements</small><strong><?php echo $formatNumber($summary['engagements'] ?? 0); ?></strong></div><div><small>Clicks</small><strong><?php echo $formatNumber($summary['clicks'] ?? 0); ?></strong></div><div><small>CRM conversions</small><strong><?php echo $formatNumber($summary['conversions'] ?? 0); ?></strong></div></div>
                <div class="social-table-wrap"><table class="social-table"><thead><tr><th>Post</th><th>Channel</th><th>Published</th><th>Impressions</th><th>Engagements</th><th>Evidence</th></tr></thead><tbody>
                    <?php $publishedRows = array_values(array_filter($jobs, static fn(array $job): bool => (string) $job['status'] === 'published')); ?>
                    <?php if ($publishedRows === []): ?><tr><td colspan="6" class="social-empty">Performance appears after a provider confirms the first published post.</td></tr><?php endif; ?>
                    <?php foreach ($publishedRows as $job): ?><tr><td><strong><?php echo htmlspecialchars(mb_strimwidth((string) $job['caption'], 0, 76, '…')); ?></strong><small><?php echo htmlspecialchars((string) $job['account_name']); ?></small></td><td><?php echo htmlspecialchars(ucfirst((string) $job['channel'])); ?></td><td><?php echo htmlspecialchars(date('M j, Y', strtotime((string) ($job['published_at'] ?? $job['updated_at'])))); ?></td><td><?php echo $formatNumber($job['metric_impressions'] ?? 0); ?></td><td><?php echo $formatNumber($job['metric_engagements'] ?? 0); ?></td><td><?php echo !empty($job['metrics_captured_at']) ? 'Synced ' . htmlspecialchars(date('M j', strtotime((string) $job['metrics_captured_at']))) : 'Awaiting sync'; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
        </section>

        <section id="social-panel-accounts" class="social-panel <?php echo $activeTab === 'accounts' ? 'is-active' : ''; ?>" role="tabpanel" aria-labelledby="social-tab-accounts" data-social-panel="accounts" <?php echo $activeTab === 'accounts' ? '' : 'hidden'; ?>>
            <div class="social-accounts-layout">
                <div class="social-section-block">
                    <div class="social-section-heading"><div><h2>Connected accounts</h2><p>Tokens are encrypted; verification and publishing stay workspace-scoped.</p></div><a class="btn-premium-primary" href="workspace_skills.php?module=social_media&setup_tab=connections#setup">Connect account</a></div>
                    <?php if ($accounts === []): ?><div class="social-empty-large"><i class="fas fa-link"></i><h3>No destinations connected</h3><p>Open plugin setup to authorize Meta or LinkedIn.</p></div><?php endif; ?>
                    <?php foreach ($accounts as $account): ?><article class="social-account-card"><span class="social-channel-icon is-<?php echo htmlspecialchars((string) $account['channel']); ?>"><i class="fab <?php echo htmlspecialchars($channelIcons[(string) $account['channel']] ?? 'fa-share-nodes'); ?>"></i></span><div><strong><?php echo htmlspecialchars((string) $account['account_name']); ?></strong><p><?php echo htmlspecialchars(ucfirst((string) $account['channel'])); ?><?php echo !empty($account['account_handle']) ? ' · ' . htmlspecialchars((string) $account['account_handle']) : ''; ?></p><small><?php echo !empty($account['last_verified_at']) ? 'Verified ' . htmlspecialchars(date('M j, Y g:i A', strtotime((string) $account['last_verified_at']))) : 'Verification pending'; ?></small><?php if (!empty($account['last_error'])): ?><em><?php echo htmlspecialchars((string) $account['last_error']); ?></em><?php endif; ?></div><span class="social-status is-<?php echo htmlspecialchars((string) $account['status']); ?>"><?php echo htmlspecialchars(ucfirst((string) $account['status'])); ?></span><?php if ($canManage): ?><div class="social-account-actions"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="verify_account"><input type="hidden" name="account_id" value="<?php echo (int) $account['id']; ?>"><button class="btn-premium-secondary" type="submit">Verify</button></form><form method="POST" onsubmit="return confirm('Disconnect this account and cancel its pending posts?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="action" value="disconnect_account"><input type="hidden" name="account_id" value="<?php echo (int) $account['id']; ?>"><button class="btn-premium-secondary" type="submit">Disconnect</button></form></div><?php endif; ?></article><?php endforeach; ?>
                </div>
                <aside class="social-section-block"><h2>Platform configuration</h2><?php foreach (['meta','linkedin'] as $provider): $platform = (array) ($readiness[$provider] ?? []); ?><div class="social-platform-row"><span class="social-event-dot <?php echo !empty($platform['configured']) ? 'is-success' : 'is-warning'; ?>"></span><p><strong><?php echo htmlspecialchars((string) ($platform['label'] ?? ucfirst($provider))); ?></strong><small><?php echo htmlspecialchars((string) ($platform['message'] ?? 'Configuration unavailable.')); ?></small></p></div><?php endforeach; ?><p class="social-worker-note"><strong>Queue worker</strong><code><?php echo htmlspecialchars((string) ($readiness['worker_command'] ?? '')); ?></code></p><p class="social-worker-note"><strong>Metrics worker</strong><code><?php echo htmlspecialchars((string) ($readiness['metrics_command'] ?? '')); ?></code></p></aside>
            </div>
        </section>

        <details class="social-advanced-tools social-section-block" id="advanced-social-tools">
            <summary>
                <span><strong>Advanced Social Tools</strong><small>Open channel packaging, tracking, quality, and cross-channel production only when you need them.</small></span>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="social-advanced-tool-grid">
                <a href="marketing_content.php"><i class="fas fa-pen-nib"></i><strong>Content Studio</strong><span>Manage reusable messages and campaign content.</span></a>
                <a href="marketing_calendar.php"><i class="fas fa-calendar-days"></i><strong>Marketing Calendar</strong><span>Coordinate the wider content schedule.</span></a>
                <a href="marketing_quality.php"><i class="fas fa-shield-halved"></i><strong>Quality Checks</strong><span>Review content readiness before distribution.</span></a>
                <a href="marketing_distribution.php"><i class="fas fa-share-nodes"></i><strong>Send / Export</strong><span>Package approved work for manual channels.</span></a>
                <a href="marketing_channel_exports.php"><i class="fas fa-box-open"></i><strong>Channel Exports</strong><span>Prepare channel-specific delivery bundles.</span></a>
                <a href="marketing_utm_links.php"><i class="fas fa-link"></i><strong>UTM Links</strong><span>Prepare trackable destinations for social traffic.</span></a>
                <a href="marketing_email_runs.php"><i class="fas fa-envelope-open-text"></i><strong>Email Runs</strong><span>Coordinate related controlled email work.</span></a>
                <a href="marketing_operator_export_packs.php"><i class="fas fa-boxes-packing"></i><strong>Operator Export Packs</strong><span>Collect approved assets and instructions.</span></a>
            </div>
        </details>
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('[data-social-workspace]');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-social-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-social-panel]'));
    function activate(key, focus) {
        tabs.forEach(function (tab) {
            var selected = tab.getAttribute('data-social-tab') === key;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.setAttribute('tabindex', selected ? '0' : '-1');
            if (selected && focus) tab.focus();
        });
        panels.forEach(function (panel) {
            var selected = panel.getAttribute('data-social-panel') === key;
            panel.hidden = !selected;
            panel.classList.toggle('is-active', selected);
        });
        if (history.replaceState) history.replaceState({}, '', 'social_media.php?tab=' + encodeURIComponent(key));
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            activate(tab.getAttribute('data-social-tab'), false);
        });
        tab.addEventListener('keydown', function (event) {
            var targetIndex = null;
            if (event.key === 'ArrowRight') targetIndex = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') targetIndex = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') targetIndex = 0;
            if (event.key === 'End') targetIndex = tabs.length - 1;
            if (targetIndex === null) return;
            event.preventDefault();
            activate(tabs[targetIndex].getAttribute('data-social-tab'), true);
        });
    });
    root.querySelectorAll('[data-social-open-composer]').forEach(function (button) { button.addEventListener('click', function () { activate('composer', false); var field = document.getElementById('social-caption'); if (field) field.focus(); }); });
    var caption = document.getElementById('social-caption');
    var counter = root.querySelector('[data-social-character-count]');
    if (caption && counter) caption.addEventListener('input', function () { counter.textContent = caption.value.length + ' characters'; });
    var publishNow = root.querySelector('[data-social-publish-now]');
    var schedule = root.querySelector('[data-social-schedule]');
    function syncScheduleState() {
        if (!publishNow || !schedule) return;
        var disabled = publishNow.checked || schedule.getAttribute('data-social-editable') !== '1';
        schedule.disabled = disabled;
        schedule.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    }
    if (publishNow && schedule) {
        publishNow.addEventListener('change', syncScheduleState);
        syncScheduleState();
    }
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
