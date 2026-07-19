<?php
/**
 * Marketing Campaign Workspace.
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
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to update campaign workspaces.');
        }
        $action = (string) ($_POST['action'] ?? 'refresh_workspace');
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        if ($action === 'save_action_snapshot') {
            $marketing->createCampaignExecutionActionSnapshot($campaignId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_campaign_workspace.php?campaign_id=' . $campaignId . '&success=action_snapshot');
            exit;
        }
        if ($action === 'create_operator_export_pack') {
            $packId = $marketing->createOperatorExportPack($campaignId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_operator_export_packs.php?id=' . $packId . '&success=created');
            exit;
        }
        $marketing->refreshMarketingCampaignWorkspace($campaignId, (int) ($user['id'] ?? 0));
        header('Location: ' . getBasePath() . '/marketing_campaign_workspace.php?campaign_id=' . $campaignId . '&success=refreshed');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$summary = $marketing->getMarketingCampaignWorkspaceSummary();
$workspaces = $marketing->listMarketingCampaignWorkspaces(['open' => true], 100, 0);
$workspace = $campaignId > 0 ? $marketing->getMarketingCampaignWorkspace($campaignId) : null;
$operatingRoom = $workspace !== null ? $marketing->getCampaignOperatingRoom($campaignId) : null;
$executionReadiness = $workspace !== null ? $marketing->getCampaignExecutionReadiness($campaignId) : null;
$relationshipReadiness = $workspace !== null ? $marketing->getCampaignRelationshipReadiness($campaignId) : null;
$launchScorecard = $workspace !== null ? $marketing->getCampaignLaunchScorecard($campaignId) : null;
$launchPacket = $workspace !== null ? $marketing->getCampaignLaunchPacket($campaignId) : null;
$launchWorkspaceV2 = $workspace !== null ? $marketing->getCampaignLaunchWorkspaceV2Summary($campaignId) : null;
$executionActionCenter = $workspace !== null ? $marketing->getCampaignExecutionActionCenter($campaignId, (int) ($user['id'] ?? 0)) : null;
$unifiedCampaignMap = $workspace !== null ? $marketing->getUnifiedCampaignWorkspaceMap($campaignId) : null;
$campaignExecutionBriefing = $workspace !== null ? $marketing->getCampaignExecutionBriefing($campaignId, (int) ($user['id'] ?? 0)) : null;
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'launched', 'completed', 'ready_for_manual_launch', 'ready_for_manual_handoff' => 'badge-success',
        'blocked' => 'badge-danger',
        'active', 'draft', 'warning', 'needs_attention', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$linkedSections = [
    'briefs' => ['label' => 'Briefs', 'href' => 'marketing_briefs.php'],
    'audience_activations' => ['label' => 'Audience', 'href' => 'marketing_audience_activation.php'],
    'landing_pages' => ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php'],
    'content_items' => ['label' => 'Content', 'href' => 'marketing_content.php'],
    'distribution_posts' => ['label' => 'Distribution', 'href' => 'marketing_distribution.php'],
    'channel_exports' => ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php'],
    'email_runs' => ['label' => 'Email Runs', 'href' => 'marketing_email_runs.php'],
    'utm_links' => ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php'],
    'launch_readiness' => ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php'],
    'launch_control' => ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php'],
    'launch_checklists' => ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php'],
    'calendar' => ['label' => 'Calendar', 'href' => 'marketing_calendar.php'],
    'planning_queue' => ['label' => 'Planning Queue', 'href' => 'marketing_operations.php'],
    'budgets' => ['label' => 'Budget/ROI', 'href' => 'marketing_performance.php'],
    'experiments' => ['label' => 'Experiments', 'href' => 'marketing_performance.php'],
];

$selectedWorkspace = $workspace !== null;
$linked = $selectedWorkspace ? (array) ($workspace['linked_summary_json'] ?? []) : [];
$linkedCount = static function (string $key) use ($linked): int {
    $section = (array) ($linked[$key] ?? []);
    return (int) ($section['count'] ?? count((array) ($section['records'] ?? [])));
};
$workspaceName = $selectedWorkspace ? (string) ($workspace['campaign_name'] ?? $workspace['workspace_name'] ?? 'Campaign workspace') : 'Campaign queue';
$workspaceHealth = $selectedWorkspace ? (int) ($workspace['health_score'] ?? 0) : (int) ($summary['average_health'] ?? 0);
$missingCount = $selectedWorkspace ? count((array) ($workspace['missing_requirements_json'] ?? [])) : (int) ($summary['counts']['blocked'] ?? 0);
$warningCount = $selectedWorkspace ? count((array) ($workspace['warning_json'] ?? [])) : 0;
$workspaceStatus = $selectedWorkspace ? (string) ($workspace['status'] ?? 'draft') : ((int) ($summary['counts']['ready'] ?? 0) > 0 ? 'ready' : 'setup_needed');
$queueCount = count($workspaces);
$readyCount = (int) ($summary['counts']['ready'] ?? 0);
$blockedCount = (int) ($summary['counts']['blocked'] ?? 0);
$summaryNextActions = array_values((array) ($summary['next_actions'] ?? []));
$workspaceNextActions = $selectedWorkspace ? array_values((array) ($workspace['next_actions_json'] ?? [])) : [];
$todayActions = array_slice($selectedWorkspace ? $workspaceNextActions : $summaryNextActions, 0, 5);
if ($todayActions === []) {
    $todayActions = $selectedWorkspace ? [
        ['label' => 'Refresh campaign evidence', 'href' => 'marketing_campaign_workspace.php?campaign_id=' . (int) ($workspace['campaign_id'] ?? 0), 'reason' => 'Pull the latest linked campaign records into this workspace.'],
        ['label' => 'Review launch readiness', 'href' => 'marketing_launch_readiness.php?campaign_id=' . (int) ($workspace['campaign_id'] ?? 0), 'reason' => 'Check the campaign before launch.'],
    ] : [
        ['label' => 'Open a campaign workspace', 'href' => 'marketing_campaign_workspace.php', 'reason' => 'Choose one campaign to focus the workspace.'],
        ['label' => 'Create a campaign brief', 'href' => 'marketing_brief_edit.php', 'reason' => 'Start with the campaign plan.'],
    ];
}

$statusFromCount = static fn(int $count): string => $count > 0 ? 'ready' : 'setup_needed';
$selectedCampaignId = $selectedWorkspace ? (int) ($workspace['campaign_id'] ?? 0) : 0;
$stageCards = $selectedWorkspace ? [
    [
        'label' => 'Plan',
        'icon' => 'fa-clipboard-list',
        'status' => $statusFromCount($linkedCount('briefs')),
        'sentence' => 'The campaign has a plan to work from.',
        'tooltip' => 'Expert view: campaign briefs, objective, offer, audience, and planning evidence.',
        'href' => 'marketing_brief_edit.php?campaign_id=' . $selectedCampaignId,
        'action' => $linkedCount('briefs') > 0 ? 'Review plan' : 'Add plan',
        'score' => $linkedCount('briefs'),
    ],
    [
        'label' => 'Audience',
        'icon' => 'fa-users',
        'status' => $statusFromCount($linkedCount('audience_activations')),
        'sentence' => 'The campaign knows who it is for.',
        'tooltip' => 'Expert view: audience activation, CRM segment, contacts, and targeting evidence.',
        'href' => 'marketing_audience_activation.php?campaign_id=' . $selectedCampaignId,
        'action' => $linkedCount('audience_activations') > 0 ? 'Review audience' : 'Choose audience',
        'score' => $linkedCount('audience_activations'),
    ],
    [
        'label' => 'Message',
        'icon' => 'fa-comment-dots',
        'status' => $statusFromCount($linkedCount('content_items')),
        'sentence' => 'The message is ready to create with.',
        'tooltip' => 'Expert view: content items, landing page copy, creative assets, and content readiness.',
        'href' => 'marketing_content_edit.php?campaign_id=' . $selectedCampaignId,
        'action' => $linkedCount('content_items') > 0 ? 'Review message' : 'Create message',
        'score' => $linkedCount('content_items'),
    ],
    [
        'label' => 'Channels',
        'icon' => 'fa-share-nodes',
        'status' => $statusFromCount($linkedCount('distribution_posts') + $linkedCount('channel_exports') + $linkedCount('email_runs')),
        'sentence' => 'The campaign has a route to market.',
        'tooltip' => 'Expert view: distribution posts, channel exports, email runs, and calendar work.',
        'href' => 'marketing_distribution.php?campaign_id=' . $selectedCampaignId,
        'action' => 'Prepare channels',
        'score' => $linkedCount('distribution_posts') + $linkedCount('channel_exports') + $linkedCount('email_runs'),
    ],
    [
        'label' => 'Launch',
        'icon' => 'fa-rocket',
        'status' => $missingCount === 0 && $selectedWorkspace ? 'ready' : 'blocked',
        'sentence' => 'The launch checks show what is still missing.',
        'tooltip' => 'Expert view: readiness gates, launch controls, checklists, operator packs, and handoff evidence.',
        'href' => 'marketing_launch_readiness.php?campaign_id=' . $selectedCampaignId,
        'action' => 'Check launch',
        'score' => max(0, 100 - ($missingCount * 10)),
    ],
    [
        'label' => 'Learn',
        'icon' => 'fa-chart-line',
        'status' => $statusFromCount($linkedCount('utm_links') + $linkedCount('budgets') + $linkedCount('experiments')),
        'sentence' => 'Tracking and learning loops are connected.',
        'tooltip' => 'Expert view: UTM links, experiments, ROI, budget, and performance records.',
        'href' => 'marketing_performance.php?campaign_id=' . $selectedCampaignId,
        'action' => 'Review learning',
        'score' => $linkedCount('utm_links') + $linkedCount('budgets') + $linkedCount('experiments'),
    ],
] : [
    [
        'label' => 'Choose Campaign',
        'icon' => 'fa-bullhorn',
        'status' => $queueCount > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Pick one campaign to focus the workspace.',
        'tooltip' => 'Expert view: open CRM campaigns become campaign workspaces.',
        'href' => $queueCount > 0 ? '#campaign-queue' : 'campaigns.php',
        'action' => $queueCount > 0 ? 'Choose campaign' : 'Create campaign',
        'score' => $queueCount,
    ],
    [
        'label' => 'Plan',
        'icon' => 'fa-clipboard-list',
        'status' => 'setup_needed',
        'sentence' => 'Turn the idea into a campaign brief.',
        'tooltip' => 'Expert view: campaign brief, offer, audience, message, and launch window.',
        'href' => 'marketing_briefs.php',
        'action' => 'Open plans',
        'score' => 0,
    ],
    [
        'label' => 'Audience',
        'icon' => 'fa-users',
        'status' => 'setup_needed',
        'sentence' => 'Choose who this campaign is for.',
        'tooltip' => 'Expert view: audience segment and activation evidence.',
        'href' => 'marketing_segments.php',
        'action' => 'Open audience',
        'score' => 0,
    ],
    [
        'label' => 'Content',
        'icon' => 'fa-pen-nib',
        'status' => 'setup_needed',
        'sentence' => 'Create the message before channels.',
        'tooltip' => 'Expert view: content items, landing pages, assets, and approval checks.',
        'href' => 'marketing_content.php',
        'action' => 'Open content',
        'score' => 0,
    ],
    [
        'label' => 'Launch',
        'icon' => 'fa-rocket',
        'status' => $blockedCount > 0 ? 'blocked' : 'setup_needed',
        'sentence' => 'Check readiness before launch.',
        'tooltip' => 'Expert view: launch readiness, controls, checklists, and operator export packs.',
        'href' => 'marketing_launch_control.php',
        'action' => 'Open launch',
        'score' => $blockedCount,
    ],
    [
        'label' => 'Learn',
        'icon' => 'fa-chart-line',
        'status' => 'setup_needed',
        'sentence' => 'Connect tracking once the campaign is live.',
        'tooltip' => 'Expert view: UTM, experiments, budgets, ROI, and performance learning.',
        'href' => 'marketing_performance.php',
        'action' => 'Open learning',
        'score' => 0,
    ],
];
$summaryTiles = [
    [
        'icon' => 'fa-route',
        'label' => 'Current step',
        'value' => $selectedWorkspace ? 'Work campaign' : 'Choose campaign',
        'tooltip' => $selectedWorkspace ? 'This workspace is focused on one campaign.' : 'Open a campaign to see the guided workspace.',
    ],
    [
        'icon' => 'fa-circle-check',
        'label' => 'Ready',
        'value' => $selectedWorkspace ? count(array_filter($stageCards, static fn(array $stage): bool => $stage['status'] === 'ready')) : $readyCount,
        'tooltip' => 'Ready stages already have enough evidence to move forward.',
    ],
    [
        'icon' => 'fa-triangle-exclamation',
        'label' => 'Needs work',
        'value' => $selectedWorkspace ? $missingCount : $blockedCount,
        'tooltip' => 'Missing evidence stays visible, but detail is kept in the advanced drawer.',
    ],
    [
        'icon' => 'fa-gauge-high',
        'label' => 'Health',
        'value' => $workspaceHealth . '%',
        'tooltip' => 'Overall campaign readiness based on linked records and launch gates.',
    ],
];
$advancedLinks = [
    ['label' => 'Command Center', 'href' => 'marketing.php', 'hint' => 'Return to the Marketing operating map.'],
    ['label' => 'Campaigns', 'href' => 'campaigns.php', 'hint' => 'Open CRM campaign automation.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Review campaign plans and briefs.'],
    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'hint' => 'Review manual launch controls.'],
    ['label' => 'Task Hub', 'href' => 'marketing_task_hub.php', 'hint' => 'Open operator tasks.'],
    ['label' => 'System Map', 'href' => 'marketing_system_map.php', 'hint' => 'Inspect advanced Marketing relationships.'],
    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'hint' => 'Inspect connected campaign records.'],
    ['label' => 'Operator Exports', 'href' => 'marketing_operator_export_packs.php' . ($selectedCampaignId > 0 ? '?campaign_id=' . $selectedCampaignId : ''), 'hint' => 'Prepare manual handoff packs.'],
];

$pageTitle = 'Marketing Campaign Workspace - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-campaign-workspace-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Campaign Workspace</h1>
                <p>Marketing Campaign Workspace: focus one campaign, see what is ready, and choose the next step.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <a class="btn-premium-secondary" href="marketing_briefs.php"><i class="fas fa-clipboard-list"></i> Campaign Plans</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'refreshed'): ?><div class="alert alert-success">Campaign workspace refreshed from current Marketing records.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'action_snapshot'): ?><div class="alert alert-success">Execution action snapshot saved for operator handoff.</div><?php endif; ?>

        <section class="marketing-campaign-workspace-shell">
            <div class="marketing-founder-summary marketing-campaign-workspace-summary" aria-label="Campaign workspace summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-campaign-stage-map" aria-label="Campaign lifecycle map">
                <?php foreach ($stageCards as $index => $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-campaign-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <div class="marketing-campaign-stage-body">
                            <div>
                                <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                                <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            </div>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-campaign-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-campaign-workspace-layout">
                <main class="marketing-campaign-workspace-main">
                    <?php if (!$selectedWorkspace): ?>
                        <section id="campaign-queue" class="content-card marketing-campaign-queue-card-list">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Campaign Queue</h2>
                                    <p>Choose one campaign workspace to work through.</p>
                                </div>
                                <span class="badge <?php echo $queueCount > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo (int) $queueCount; ?> open</span>
                            </div>
                            <?php if ($workspaces === []): ?>
                                <div class="empty-state">
                                    <p>No active campaign workspaces are available yet.</p>
                                    <a href="campaigns.php">Open campaigns</a>
                                </div>
                            <?php else: ?>
                                <div class="marketing-campaign-queue-grid">
                                    <?php foreach (array_slice($workspaces, 0, 8) as $item): ?>
                                        <?php
                                            $itemStatus = (string) ($item['status'] ?? 'draft');
                                            $itemHealth = min(100, max(0, (int) ($item['health_score'] ?? 0)));
                                            $itemMissing = count((array) ($item['missing_requirements_json'] ?? []));
                                        ?>
                                        <article class="marketing-campaign-queue-card" tabindex="0" data-tooltip="Open this campaign to see its plan, audience, content, launch checks, and expert evidence.">
                                            <div class="marketing-campaign-queue-top">
                                                <div class="marketing-stage-visual">
                                                    <i class="fas fa-bullhorn" aria-hidden="true"></i>
                                                </div>
                                                <span class="badge <?php echo htmlspecialchars($badgeClass($itemStatus)); ?>"><?php echo htmlspecialchars($labelize($itemStatus)); ?></span>
                                            </div>
                                            <h3><?php echo htmlspecialchars((string) ($item['campaign_name'] ?? $item['workspace_name'] ?? 'Campaign workspace')); ?></h3>
                                            <progress class="marketing-campaign-meter" max="100" value="<?php echo $itemHealth; ?>" aria-label="Campaign health score"></progress>
                                            <div class="marketing-campaign-queue-meta">
                                                <span><?php echo $itemHealth; ?>% health</span>
                                                <span><?php echo $itemMissing; ?> missing</span>
                                            </div>
                                            <a class="btn-premium-secondary" href="marketing_campaign_workspace.php?campaign_id=<?php echo (int) $item['campaign_id']; ?>">Open workspace</a>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php else: ?>
                        <section class="content-card marketing-campaign-focus-card">
                            <div class="premium-section-header">
                                <div>
                                    <h2><?php echo htmlspecialchars($workspaceName); ?></h2>
                                    <p>Work from the campaign evidence, then move only the next useful step.</p>
                                </div>
                                <span class="badge <?php echo htmlspecialchars($badgeClass($workspaceStatus)); ?>"><?php echo htmlspecialchars($labelize($workspaceStatus)); ?></span>
                            </div>
                            <div class="marketing-campaign-focus-grid">
                                <div class="marketing-campaign-focus-score">
                                    <div class="setup-score-ring" data-score="<?php echo min(100, max(0, $workspaceHealth)); ?>">
                                        <span><?php echo $workspaceHealth; ?>%</span>
                                    </div>
                                    <strong>Campaign health</strong>
                                    <small><?php echo $missingCount; ?> missing, <?php echo $warningCount; ?> warning(s)</small>
                                </div>
                                <div class="marketing-campaign-focus-next">
                                    <span>Next best action</span>
                                    <strong><?php echo htmlspecialchars((string) ($todayActions[0]['label'] ?? 'Review campaign')); ?></strong>
                                    <p><?php echo htmlspecialchars((string) ($todayActions[0]['reason'] ?? 'Use the campaign evidence to choose what to do next.')); ?></p>
                                    <a class="btn-premium-primary" href="<?php echo htmlspecialchars((string) ($todayActions[0]['href'] ?? 'marketing_campaign_workspace.php?campaign_id=' . $selectedCampaignId)); ?>">Start next step</a>
                                </div>
                            </div>
                        </section>

                        <section class="content-card marketing-campaign-evidence-strip">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Evidence Snapshot</h2>
                                    <p>Plain-language view of the expert records connected to this campaign.</p>
                                </div>
                            </div>
                            <div class="marketing-campaign-evidence-grid">
                                <?php foreach (array_slice($linkedSections, 0, 6, true) as $key => $section): ?>
                                    <?php $sectionCount = $linkedCount((string) $key); ?>
                                    <a class="marketing-campaign-evidence-card" href="<?php echo htmlspecialchars($section['href']); ?>" data-tooltip="<?php echo htmlspecialchars('Expert view: ' . $section['label'] . ' records connected to this campaign.'); ?>">
                                        <span><?php echo htmlspecialchars($section['label']); ?></span>
                                        <strong><?php echo $sectionCount; ?></strong>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </main>

                <aside class="content-card marketing-campaign-today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Keep the work to one short list.</p>
                        </div>
                    </div>
                    <div class="marketing-campaign-today-list">
                        <?php foreach (array_slice($todayActions, 0, 5) as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) ($action['href'] ?? 'marketing_campaign_workspace.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($action['reason'] ?? 'Open this campaign step.')); ?>">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars((string) ($action['label'] ?? 'Review campaign')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($selectedWorkspace && $canWriteMarketing): ?>
                        <div class="marketing-campaign-command-row">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="refresh_workspace">
                                <input type="hidden" name="campaign_id" value="<?php echo $selectedCampaignId; ?>">
                                <button class="btn-premium-secondary" type="submit"><i class="fas fa-rotate"></i> Refresh</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="save_action_snapshot">
                                <input type="hidden" name="campaign_id" value="<?php echo $selectedCampaignId; ?>">
                                <button class="btn-premium-secondary" type="submit"><i class="fas fa-camera"></i> Snapshot</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-campaign-tools">
                <summary>
                    <span>Advanced campaign tools</span>
                    <small>Expert routes, launch evidence, graph checks, and operator handoff.</small>
                </summary>
                <div class="marketing-campaign-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <?php foreach ($advancedLinks as $link): ?>
                            <a href="<?php echo htmlspecialchars((string) $link['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $link['hint']); ?>">
                                <strong><?php echo htmlspecialchars((string) $link['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $link['hint']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($selectedWorkspace): ?>
                        <div class="marketing-campaign-tools-grid">
                            <section class="marketing-campaign-tool-card">
                                <h3>Launch Readiness</h3>
                                <p><?php echo $launchScorecard !== null ? htmlspecialchars((string) ($launchScorecard['status'] ?? 'needs_attention')) : 'No launch scorecard yet.'; ?></p>
                                <strong><?php echo $launchScorecard !== null ? (int) ($launchScorecard['score'] ?? 0) . '%' : 'Not scored'; ?></strong>
                            </section>
                            <section class="marketing-campaign-tool-card">
                                <h3>Execution Center</h3>
                                <p><?php echo $executionActionCenter !== null ? htmlspecialchars((string) ($executionActionCenter['status'] ?? 'needs_attention')) : 'No action center yet.'; ?></p>
                                <strong><?php echo $executionActionCenter !== null ? (int) ($executionActionCenter['counts']['urgent_actions'] ?? 0) . ' urgent' : 'No queue'; ?></strong>
                            </section>
                            <section class="marketing-campaign-tool-card">
                                <h3>Relationship Graph</h3>
                                <p><?php echo $relationshipReadiness !== null ? htmlspecialchars((string) ($relationshipReadiness['status'] ?? 'needs_attention')) : 'No graph readout yet.'; ?></p>
                                <strong><?php echo $relationshipReadiness !== null ? (int) ($relationshipReadiness['score'] ?? 0) . '%' : 'Not scored'; ?></strong>
                            </section>
                            <section class="marketing-campaign-tool-card">
                                <h3>Launch Packet</h3>
                                <p><?php echo $launchPacket !== null ? htmlspecialchars((string) ($launchPacket['status'] ?? 'needs_attention')) : 'No packet yet.'; ?></p>
                                <strong><?php echo $launchPacket !== null ? (int) ($launchPacket['score'] ?? 0) . '%' : 'Not built'; ?></strong>
                            </section>
                        </div>

                        <div class="marketing-campaign-linked-tools">
                            <?php foreach ($linkedSections as $key => $section): ?>
                                <a href="<?php echo htmlspecialchars($section['href']); ?>">
                                    <span><?php echo htmlspecialchars($section['label']); ?></span>
                                    <strong><?php echo $linkedCount((string) $key); ?></strong>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($canWriteMarketing): ?>
                            <div class="marketing-campaign-command-row marketing-campaign-command-row-wide">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="refresh_workspace">
                                    <input type="hidden" name="campaign_id" value="<?php echo $selectedCampaignId; ?>">
                                    <button class="btn-premium-secondary" type="submit">Refresh workspace</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="create_operator_export_pack">
                                    <input type="hidden" name="campaign_id" value="<?php echo $selectedCampaignId; ?>">
                                    <button class="btn-premium-primary" type="submit">Create export pack</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="save_action_snapshot">
                                    <input type="hidden" name="campaign_id" value="<?php echo $selectedCampaignId; ?>">
                                    <button class="btn-premium-secondary" type="submit">Save action snapshot</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
