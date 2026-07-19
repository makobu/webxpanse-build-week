<?php
/**
 * Marketing Command Center.
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
use CRM\Services\MarketingLaunchPacketService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\MarketingStageContextService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_MARKETING_PRO);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$marketingGate = new MarketingMarketplaceGateService();
try {
    $marketingNavigationState = $marketingGate->navigationStateForUser($user);
} catch (\Throwable $e) {
    $marketingNavigationState = [];
}
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$onboardingStatus = $marketing->getMarketingOnboardingStatus();
$summary = $marketing->getCachedDashboardSummary($onboardingStatus, 60);
$operatingRhythm = (array) ($summary['operating_rhythm'] ?? []);
$creativeReadiness = (array) ($summary['creative_readiness'] ?? []);
$integrationReadiness = (array) ($summary['integration_readiness'] ?? []);
$systemConnections = (array) ($integrationReadiness['system_connections'] ?? []);
$crmLifecycleProfile = (array) ($summary['crm_lifecycle_profile'] ?? []);
$automationReadiness = (array) ($summary['automation_readiness'] ?? []);
$automationLoops = (array) ($automationReadiness['loops'] ?? []);
$pageSpeedProfile = (array) ($summary['page_speed_profile'] ?? []);
$aiWorkspaceBrain = (array) ($summary['ai_workspace_brain'] ?? []);
$guidedRecommendations = (array) ($summary['guided_recommendations'] ?? []);
$actionRouter = (array) ($summary['action_router'] ?? []);
$decisionEngine = (array) ($summary['decision_engine'] ?? []);
$productionSummary = $marketing->getContentProductionSummary((int) ($user['id'] ?? 0));
$nextBestActions = $marketing->getMarketingNextBestActions((int) ($user['id'] ?? 0), 12, [
    'command_flow' => (array) ($summary['command_flow'] ?? []),
    'task_hub' => (array) ($summary['task_hub'] ?? []),
    'guided_workflows' => (array) ($summary['guided_workflows'] ?? []),
    'guided_recommendations' => $guidedRecommendations,
    'production' => $productionSummary,
]);
$founderCommandCenter = (new MarketingStageContextService())->build($summary, $onboardingStatus, $nextBestActions);
$founderSummary = (array) ($founderCommandCenter['summary'] ?? []);
$founderStages = (array) ($founderCommandCenter['stages'] ?? []);
$todayActions = (array) ($founderCommandCenter['today_actions'] ?? []);
$advancedToolGroups = (array) ($founderCommandCenter['advanced_tool_groups'] ?? []);
$advancedToolGroups = array_values(array_filter(
    $advancedToolGroups,
    static fn(array $group): bool => (string) ($group['feature'] ?? '') === MarketingMarketplaceGateService::FEATURE_MARKETING_PRO
));
if ($advancedToolGroups === [] && !empty($founderCommandCenter['advanced_tools'])) {
    $advancedToolGroups[] = [
        'label' => 'Campaign Manager',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
        'items' => (array) $founderCommandCenter['advanced_tools'],
    ];
}
$launchPacket = (new MarketingLaunchPacketService($marketing))->build($onboardingStatus, $summary);
$launchPacketMissing = (array) ($launchPacket['missing'] ?? []);
$launchPacketEvidence = (array) ($launchPacket['evidence'] ?? []);
$launchPacketTopMissing = (array) ($launchPacketMissing[0] ?? []);
$launchPacketStatus = $labelize((string) ($launchPacket['status'] ?? 'not_started'));
$launchPacketCtaLabel = (string) ($launchPacket['primary_action_label'] ?? 'Complete setup');
$launchPacketCtaUrl = (string) ($launchPacket['primary_action_url'] ?? 'marketing_onboarding.php');
$launchPacketProofUrl = (string) ($launchPacket['proof_url'] ?? '');
$launchPacketPacketUrl = (string) ($launchPacket['packet_url'] ?? '');
$launchPacketNeedsManualProof = !empty($launchPacket['needs_manual_proof']);
$recommendationLabels = array_values(array_filter(array_map(
    static fn($item): string => is_array($item) ? trim((string) ($item['label'] ?? $item['title'] ?? '')) : '',
    (array) ($guidedRecommendations['recommendations'] ?? [])
)));
$proceduralMarketingItems = static function (array $items) use ($recommendationLabels): array {
    return array_values(array_filter($items, static function ($item) use ($recommendationLabels): bool {
        if (!is_array($item)) {
            return true;
        }
        $itemLabel = trim((string) ($item['label'] ?? $item['title'] ?? ''));
        if ($itemLabel !== '' && in_array($itemLabel, $recommendationLabels, true)) {
            return false;
        }
        $recommendationKey = trim((string) ($item['recommendation_key'] ?? ''));
        if ($recommendationKey !== '') {
            return false;
        }
        $sourceText = strtolower(implode(' ', array_map('strval', [
            $item['source'] ?? '',
            $item['source_type'] ?? '',
            $item['kind'] ?? '',
            $item['type'] ?? '',
            $item['category'] ?? '',
        ])));

        return !str_contains($sourceText, 'recommendation');
    }));
};
foreach ((array) ($operatingRhythm['lanes'] ?? []) as $laneIndex => $lane) {
    $operatingRhythm['lanes'][$laneIndex]['items'] = $proceduralMarketingItems((array) ($lane['items'] ?? []));
}
$operatingRhythm['next_actions'] = $proceduralMarketingItems((array) ($operatingRhythm['next_actions'] ?? []));
$actionRouter['items'] = $proceduralMarketingItems((array) ($actionRouter['items'] ?? []));
$decisionEngine['decisions'] = $proceduralMarketingItems((array) ($decisionEngine['decisions'] ?? []));
$creativeBlockers = (int) ($creativeReadiness['counts']['blocked_media'] ?? 0)
    + (int) ($creativeReadiness['counts']['content_needing_media'] ?? 0)
    + (int) ($creativeReadiness['counts']['landing_pages_needing_media'] ?? 0);
$launchReady = (int) ($summary['counts']['launch_reviews_ready'] ?? 0)
    + (int) ($summary['counts']['launch_control_ready'] ?? 0)
    + (int) ($summary['counts']['launch_checklists_ready'] ?? 0);
$launchBlocked = (int) ($summary['counts']['launch_reviews_blocked'] ?? 0)
    + (int) ($summary['counts']['launch_control_blocked'] ?? 0);
$systemSignalCards = [
    [
        'label' => 'Operating Rhythm',
        'icon' => 'fa-list-check',
        'score' => (int) ($operatingRhythm['score'] ?? 0),
        'status' => $labelize((string) ($operatingRhythm['status'] ?? 'attention')),
        'detail' => (int) ($operatingRhythm['counts']['today_focus'] ?? 0) . ' today, ' . (int) ($operatingRhythm['counts']['blocked_work'] ?? 0) . ' blocked',
        'href' => 'marketing_operations.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
    ],
    [
        'label' => 'Creative Readiness',
        'icon' => 'fa-images',
        'score' => max(0, 100 - min(100, $creativeBlockers * 12)),
        'status' => $creativeBlockers > 0 ? 'Needs work' : 'Clear',
        'detail' => $creativeBlockers . ' media gap' . ($creativeBlockers === 1 ? '' : 's'),
        'href' => 'marketing_assets.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_DESIGN,
    ],
    [
        'label' => 'CRM Connections',
        'icon' => 'fa-diagram-project',
        'score' => (int) ($integrationReadiness['score'] ?? 0),
        'status' => $labelize((string) ($integrationReadiness['status'] ?? 'attention')),
        'detail' => (int) ($integrationReadiness['counts']['system_connections_ready'] ?? 0) . '/' . max(1, count($systemConnections)) . ' systems ready',
        'href' => 'marketing_system_map.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
    ],
    [
        'label' => 'Lifecycle Links',
        'icon' => 'fa-route',
        'score' => (int) ($crmLifecycleProfile['score'] ?? 0),
        'status' => $labelize((string) ($crmLifecycleProfile['status'] ?? 'missing')),
        'detail' => (int) ($crmLifecycleProfile['counts']['lead_handoffs_open'] ?? 0) . ' open handoff' . ((int) ($crmLifecycleProfile['counts']['lead_handoffs_open'] ?? 0) === 1 ? '' : 's'),
        'href' => 'marketing_handoffs.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
    ],
    [
        'label' => 'AI Context',
        'icon' => 'fa-brain',
        'score' => (int) ($aiWorkspaceBrain['score'] ?? 0),
        'status' => $labelize((string) ($aiWorkspaceBrain['status'] ?? 'thin')),
        'detail' => count((array) ($aiWorkspaceBrain['coverage'] ?? [])) . ' context areas tracked',
        'href' => 'marketing_assistants.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
    ],
    [
        'label' => 'Launch Control',
        'icon' => 'fa-tower-broadcast',
        'score' => $launchReady + $launchBlocked > 0 ? (int) round(($launchReady / max(1, $launchReady + $launchBlocked)) * 100) : 0,
        'status' => $launchBlocked > 0 ? 'Needs check' : 'Quiet',
        'detail' => $launchReady . ' ready, ' . $launchBlocked . ' blocked',
        'href' => 'marketing_launch_control.php',
        'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
    ],
];
foreach ($systemSignalCards as &$signalCard) {
    $feature = (string) ($signalCard['feature'] ?? '');
    if ($feature === '') {
        continue;
    }
    $featureState = (array) ($marketingNavigationState[$feature] ?? []);
    if ($featureState !== [] && empty($featureState['can_run'])) {
        $signalCard['href'] = (string) ($featureState['setup_url'] ?? ($marketingGate->marketplaceUrl($feature) . '#setup'));
        $signalCard['status'] = 'Setup needed';
        $signalCard['detail'] = 'Install ' . \CRM\Services\MarketingUi::pluginFamilyLabel($feature) . ' to open this signal.';
    }
}
unset($signalCard);

$pageTitle = 'Campaign Manager - ' . brandProductName();
$nextActionLabel = (string) ($founderSummary['next_action'] ?? 'Start Marketing Setup');
$compactNextActionLabel = trim((string) preg_replace('/\bMarketing\b\s*/i', '', $nextActionLabel));
$founderStagesByKey = [];
foreach ($founderStages as $founderStage) {
    $founderStagesByKey[(string) ($founderStage['key'] ?? '')] = $founderStage;
}
$marketingQuickStartStep = static function (string $key, string $label, string $fallbackHref, string $fallbackAction, string $lockedAction = '') use ($founderStagesByKey): array {
    $stage = (array) ($founderStagesByKey[$key] ?? []);
    $status = (string) ($stage['status'] ?? 'Setup needed');
    return [
        'label' => $label,
        'complete' => in_array($status, ['Ready', 'In use'], true),
        'href' => (string) ($stage['primary_action_url'] ?? $fallbackHref),
        'action_label' => $status === 'Locked' && $lockedAction !== ''
            ? $lockedAction
            : (string) ($stage['primary_action_label'] ?? $fallbackAction),
    ];
};
$pluginQuickStart = [
    'key' => 'marketing-pro',
    'outcome' => 'Build a launch-ready campaign plan',
    'steps' => [
        $marketingQuickStartStep('setup', 'Complete setup', 'marketing_onboarding.php', 'Complete setup'),
        $marketingQuickStartStep('audiences', 'Define an audience', 'marketing_segments.php', 'Define audience', 'Finish setup'),
        $marketingQuickStartStep('campaigns', 'Draft a campaign brief', 'marketing_briefs.php', 'Draft campaign brief', 'Define audience'),
    ],
    'completion_action' => ['href' => (string) ($founderStagesByKey['content']['primary_action_url'] ?? 'marketing_content.php'), 'action_label' => 'Continue to content'],
];
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/plugin-workspaces.css?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/css/plugin-workspaces.css') ?: 1); ?>">
<script src="assets/js/plugin-workspaces.js?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/js/plugin-workspaces.js') ?: 1); ?>" defer></script>

<div class="page-premium marketing-command-center-page plugin-workspace-shell">
    <div class="container">
        <section class="marketing-founder-shell" aria-label="Founder guided marketing command center">
            <div class="marketing-founder-hero page-header">
                <div>
                    <h1>Campaign Manager</h1>
                    <p>Plan, launch and improve.</p>
                </div>
                <div class="page-header-actions">
                    <a href="<?php echo htmlspecialchars((string) ($founderSummary['next_action_url'] ?? 'marketing_onboarding.php')); ?>" class="btn-premium-primary">
                        <i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($compactNextActionLabel); ?>
                    </a>
                </div>
            </div>

            <?php $summaryTooltips = (array) ($founderSummary['tooltips'] ?? []); ?>
            <div class="marketing-founder-summary" aria-label="Marketing path summary">
                <a href="<?php echo htmlspecialchars((string) ($founderSummary['next_action_url'] ?? 'marketing_onboarding.php')); ?>" class="marketing-summary-tile primary" data-tooltip="<?php echo htmlspecialchars((string) ($summaryTooltips['current_step'] ?? 'Current Marketing step.'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                    <span>Stage</span>
                    <strong><?php echo htmlspecialchars((string) ($founderSummary['current_step'] ?? 'Setup')); ?></strong>
                </a>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) ($summaryTooltips['ready_stages'] ?? 'Stages ready to use.'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-check" aria-hidden="true"></i>
                    <span>Ready</span>
                    <strong><?php echo (int) ($founderSummary['ready_stages'] ?? 0); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) ($summaryTooltips['blocked_stages'] ?? 'Stages waiting on prerequisites.'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                    <span>Blocked</span>
                    <strong><?php echo (int) ($founderSummary['blocked_stages'] ?? 0); ?></strong>
                </div>
                <a href="<?php echo htmlspecialchars((string) ($founderSummary['next_action_url'] ?? 'marketing_onboarding.php')); ?>" class="marketing-summary-tile action" data-tooltip="<?php echo htmlspecialchars((string) ($summaryTooltips['next_action'] ?? 'Next procedural action.'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    <span>Next</span>
                    <strong><?php echo htmlspecialchars($compactNextActionLabel); ?></strong>
                </a>
            </div>

            <?php include __DIR__ . '/../views/partials/plugin_product_quick_start.php'; ?>

            <section class="content-card marketing-launch-packet-card" aria-label="Manual Launch Packet">
                <div class="marketing-launch-packet-main">
                    <div class="marketing-launch-packet-score" data-status="<?php echo htmlspecialchars((string) ($launchPacket['status'] ?? 'not_started')); ?>">
                        <strong><?php echo (int) ($launchPacket['score'] ?? 0); ?>%</strong>
                        <span><?php echo htmlspecialchars($launchPacketStatus); ?></span>
                    </div>
                    <div class="marketing-launch-packet-copy">
                        <div class="premium-section-header">
                            <div>
                                <h2>Launch packet</h2>
                            </div>
                            <span class="badge <?php echo empty($launchPacketMissing) ? 'badge-success' : 'badge-warning'; ?>"><?php echo empty($launchPacketMissing) ? 'Packet ready' : 'Needs input'; ?></span>
                        </div>
                        <div class="marketing-launch-packet-highlight">
                            <?php if ($launchPacketTopMissing !== []): ?>
                                <span>Top missing item</span>
                                <strong><?php echo htmlspecialchars((string) ($launchPacketTopMissing['label'] ?? 'Setup')); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($launchPacketTopMissing['detail'] ?? 'Complete the next primary-path step.')); ?></p>
                            <?php else: ?>
                                <span>Ready item</span>
                                <strong>Manual package available</strong>
                                <p>Open the packet, use it outside the CRM, then record published proof when the manual step is done.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="marketing-launch-packet-footer">
                    <div class="marketing-launch-packet-lists">
                        <div>
                            <span>Ready evidence</span>
                            <?php if ($launchPacketEvidence === []): ?>
                                <p>No primary-path evidence yet.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($launchPacketEvidence, 0, 3) as $item): ?>
                                    <a href="<?php echo htmlspecialchars((string) ($item['href'] ?? 'marketing.php')); ?>"><?php echo htmlspecialchars((string) ($item['label'] ?? 'Evidence')); ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span>Missing</span>
                            <?php if ($launchPacketMissing === []): ?>
                                <p>No launch packet blockers.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($launchPacketMissing, 0, 3) as $item): ?>
                                    <a href="<?php echo htmlspecialchars((string) ($item['href'] ?? 'marketing_onboarding.php')); ?>"><?php echo htmlspecialchars((string) ($item['label'] ?? 'Next step')); ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="marketing-launch-packet-actions">
                        <a class="btn-premium-primary" href="<?php echo htmlspecialchars($launchPacketCtaUrl); ?>">
                            <i class="fas fa-box-open"></i> <?php echo htmlspecialchars($launchPacketCtaLabel); ?>
                        </a>
                        <?php if ($launchPacketNeedsManualProof && $launchPacketProofUrl !== ''): ?>
                            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($launchPacketProofUrl); ?>">
                                <i class="fas fa-square-check"></i> Record proof
                            </a>
                        <?php elseif ((string) ($launchPacket['status'] ?? '') === 'published_evidence' && $launchPacketPacketUrl !== ''): ?>
                            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($launchPacketPacketUrl); ?>">
                                <i class="fas fa-box-open"></i> Open packet
                            </a>
                        <?php endif; ?>
                        <span><i class="fas fa-shield-halved" aria-hidden="true"></i> No external send, publish, or channel API call.</span>
                    </div>
                </div>
            </section>

            <ol class="marketing-lifecycle-graph" aria-label="Marketing lifecycle path">
                <?php foreach ($founderStages as $stage): ?>
                    <?php
                        $stageStatus = (string) ($stage['status'] ?? 'Locked');
                        $stageClass = strtolower(str_replace(' ', '-', $stageStatus));
                    ?>
                    <li class="<?php echo htmlspecialchars($stageClass); ?>">
                        <a class="marketing-lifecycle-node" href="<?php echo htmlspecialchars((string) ($stage['primary_action_url'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($stage['tooltip'] ?? $stage['description'] ?? 'Marketing stage'), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="marketing-lifecycle-icon"><i class="fas <?php echo htmlspecialchars((string) ($stage['icon'] ?? 'fa-circle-dot')); ?>" aria-hidden="true"></i></span>
                            <span><?php echo htmlspecialchars((string) ($stage['founder_label'] ?? 'Marketing stage')); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="marketing-founder-layout">
                <div class="marketing-stage-grid">
                    <?php foreach ($founderStages as $stage): ?>
                        <?php
                            $stageStatus = (string) ($stage['status'] ?? 'Locked');
                            $stageClass = strtolower(str_replace(' ', '-', $stageStatus));
                        ?>
                        <article class="marketing-stage-card <?php echo htmlspecialchars($stageClass); ?>">
                            <div class="marketing-stage-visual" aria-hidden="true">
                                <i class="fas <?php echo htmlspecialchars((string) ($stage['icon'] ?? 'fa-circle-dot')); ?>"></i>
                                <span><?php echo (int) ($stage['score'] ?? 0); ?>%</span>
                            </div>
                            <div class="marketing-stage-body">
                                <div class="marketing-stage-title-row">
                                    <h2><?php echo htmlspecialchars((string) ($stage['founder_label'] ?? 'Marketing stage')); ?></h2>
                                    <span class="marketing-stage-badge <?php echo htmlspecialchars($stageClass); ?>"><?php echo htmlspecialchars($stageStatus); ?></span>
                                </div>
                                <button type="button" class="marketing-tooltip-trigger" data-tooltip="<?php echo htmlspecialchars((string) ($stage['tooltip'] ?? $stage['description'] ?? 'Move this marketing stage forward.'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars('About ' . (string) ($stage['founder_label'] ?? 'this stage'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-circle-question" aria-hidden="true"></i>
                                    <span>Details</span>
                                </button>
                            </div>
                            <a class="btn-premium-secondary marketing-stage-action" href="<?php echo htmlspecialchars((string) ($stage['primary_action_url'] ?? 'marketing.php')); ?>">
                                <?php echo htmlspecialchars((string) ($stage['primary_action_label'] ?? 'Open')); ?>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>

                <aside class="marketing-today-panel content-card" aria-label="Today marketing actions">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                        </div>
                    </div>
                    <div class="marketing-today-list">
                        <?php foreach (array_slice($todayActions, 0, 5) as $action): ?>
                            <a class="marketing-today-action <?php echo htmlspecialchars((string) ($action['priority'] ?? 'normal')); ?>" href="<?php echo htmlspecialchars((string) ($action['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($action['reason'] ?? 'Review this marketing workflow step.'), ENT_QUOTES, 'UTF-8'); ?>">
                                <strong><?php echo htmlspecialchars((string) ($action['label'] ?? 'Review marketing action')); ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools" id="advanced-marketing-operations">
                <summary>
                    <span>Advanced Marketing Operations</span>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="marketing-advanced-tools-grid">
                    <?php foreach ($advancedToolGroups as $toolGroup): ?>
                        <?php
                            $toolFeature = (string) ($toolGroup['feature'] ?? MarketingMarketplaceGateService::FEATURE_MARKETING_PRO);
                            $toolFeatureState = (array) ($marketingNavigationState[$toolFeature] ?? ['can_run' => true]);
                            $toolCanRun = !array_key_exists('can_run', $toolFeatureState) || !empty($toolFeatureState['can_run']);
                            $setupUrl = (string) ($toolFeatureState['setup_url'] ?? ($marketingGate->marketplaceUrl($toolFeature) . '#setup'));
                            $setupLabel = (string) ($toolFeatureState['setup_label'] ?? ('Set up ' . \CRM\Services\MarketingUi::pluginFamilyLabel($toolFeature)));
                        ?>
                        <section class="marketing-advanced-tools-group" aria-label="<?php echo htmlspecialchars((string) ($toolGroup['label'] ?? 'Marketing tools')); ?>">
                            <h3><?php echo htmlspecialchars((string) ($toolGroup['label'] ?? 'Marketing tools')); ?></h3>
                            <?php if (!$toolCanRun): ?>
                                <a class="marketing-advanced-tools-setup" href="<?php echo htmlspecialchars($setupUrl); ?>">
                                    <strong><?php echo htmlspecialchars($setupLabel); ?></strong>
                                </a>
                            <?php else: ?>
                                <?php foreach ((array) ($toolGroup['items'] ?? []) as $tool): ?>
                                    <a href="<?php echo htmlspecialchars((string) ($tool['href'] ?? 'marketing.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($tool['description'] ?? 'Open the marketing tool.'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <strong><?php echo htmlspecialchars((string) ($tool['label'] ?? 'Marketing tool')); ?></strong>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>

        <details class="marketing-detailed-systems">
            <summary>
                <span>Detailed system signals</span>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="marketing-detailed-systems-body">
                <div class="marketing-system-signal-board" aria-label="Marketing system signal summary">
                    <div class="marketing-system-signal-intro">
                        <span>System Signals</span>
                        <strong>Compact health view for the advanced marketing system.</strong>
                        <p>Use these cards to spot the area that needs attention, then open the matching tool only when you need the detail.</p>
                    </div>
                    <div class="marketing-system-signal-grid">
                        <?php foreach ($systemSignalCards as $signal): ?>
                            <?php
                                $signalScore = max(0, min(100, (int) ($signal['score'] ?? 0)));
                                $signalTone = $signalScore >= 75 ? 'ready' : ($signalScore >= 40 ? 'attention' : 'quiet');
                                $signalScoreClass = 'score-' . (string) (int) (ceil($signalScore / 10) * 10);
                            ?>
                            <a class="marketing-system-signal-card <?php echo htmlspecialchars($signalTone . ' ' . $signalScoreClass); ?>" href="<?php echo htmlspecialchars((string) ($signal['href'] ?? 'marketing.php')); ?>">
                                <span class="marketing-system-signal-icon"><i class="fas <?php echo htmlspecialchars((string) ($signal['icon'] ?? 'fa-circle-dot')); ?>" aria-hidden="true"></i></span>
                                <span class="marketing-system-signal-score"><?php echo $signalScore; ?>%</span>
                                <strong><?php echo htmlspecialchars((string) ($signal['label'] ?? 'System signal')); ?></strong>
                                <em><?php echo htmlspecialchars((string) ($signal['status'] ?? 'Review')); ?></em>
                                <span><?php echo htmlspecialchars((string) ($signal['detail'] ?? 'Open the related tool for detail.')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </details>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
