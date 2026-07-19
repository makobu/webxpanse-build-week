<?php
/**
 * Deals List Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\ClarityPackageCatalog;
use CRM\Modules\Deals;
use CRM\Modules\Currencies;
use CRM\Modules\WorkspaceLaunchSettings;
use CRM\Security;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\DealAutomationReadinessService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$dealsModule = new Deals();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$dealAutomationState = (new DealAutomationReadinessService())->getState();
$dealAutomationConfig = (array) ($dealAutomationState['config'] ?? []);
$isDefaultWorkspaceDealsBoard = WorkspaceContext::isDefaultWorkspace($workspaceId);

// Get view mode (pipeline or list)
$viewMode = $_GET['view'] ?? 'list';

// Get filters
$search = $_GET['search'] ?? '';
$stage = $_GET['stage'] ?? '';
$stages = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
if ($stage !== '' && !in_array($stage, $stages, true)) {
    $stage = '';
}
$hasAssignedToParam = array_key_exists('assigned_to', $_GET);
$assignedToRaw = $_GET['assigned_to'] ?? null;
$assignedTo = null;
if (!$hasAssignedToParam) {
    $assignedTo = $userId > 0 ? $userId : null;
} elseif ($assignedToRaw !== null && $assignedToRaw !== '') {
    $assignedTo = (int) $assignedToRaw;
}

$dealFilters = [];
if ($search) {
    $dealFilters['search'] = $search;
}
if ($stage) {
    $dealFilters['stage'] = $stage;
}
if ($assignedTo !== null) {
    $dealFilters['assigned_to'] = $assignedTo;
}

// Get pipeline statistics
$pipelineStats = $dealsModule->getPipelineStats($dealFilters);

// Get deals by stage for pipeline view
$stageLabels = [
    'prospecting' => 'Prospecting',
    'qualification' => 'Qualification',
    'proposal' => 'Proposal',
    'negotiation' => 'Negotiation',
    'closed_won' => 'Won',
    'closed_lost' => 'Lost'
];
$stageColors = [
    'prospecting' => '#64748b',
    'qualification' => '#2563eb',
    'proposal' => '#f59e0b',
    'negotiation' => '#16a34a',
    'closed_won' => '#059669',
    'closed_lost' => '#dc2626',
];
$buildDealsUrl = static function (array $overrides = [], array $remove = []) use ($viewMode, $search, $stage, $hasAssignedToParam, $assignedToRaw, $assignedTo): string {
    $params = ['view' => $viewMode];
    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($stage !== '') {
        $params['stage'] = $stage;
    }
    if ($hasAssignedToParam) {
        $params['assigned_to'] = $assignedToRaw ?? '';
    } elseif ($assignedTo !== null) {
        $params['assigned_to'] = $assignedTo;
    }
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    return 'deals.php?' . http_build_query($params);
};
$viewToggleUrl = $buildDealsUrl(['view' => $viewMode === 'pipeline' ? 'list' : 'pipeline']);
$clearFilterUrl = $buildDealsUrl([], ['search', 'assigned_to']);
$stageChipItems = ['' => ['label' => 'All Deals', 'icon' => 'fa-briefcase']];
foreach ($stageLabels as $stageKey => $stageLabel) {
    $stageChipItems[$stageKey] = [
        'label' => $stageLabel,
        'icon' => match ($stageKey) {
            'prospecting' => 'fa-binoculars',
            'qualification' => 'fa-user-check',
            'proposal' => 'fa-file-signature',
            'negotiation' => 'fa-handshake',
            'closed_won' => 'fa-trophy',
            'closed_lost' => 'fa-ban',
            default => 'fa-briefcase',
        },
    ];
}
$stageOrder = array_values(array_keys($stageLabels));
$allowMultiStageJump = !empty($dealAutomationConfig['allow_multi_stage_jump']);
$allowedDestinations = static function (string $currentStage) use ($stageOrder, $allowMultiStageJump): array {
    $currentIndex = array_search($currentStage, $stageOrder, true);
    if ($allowMultiStageJump || $currentIndex === false) {
        return array_values(array_filter($stageOrder, static fn ($stage): bool => $stage !== $currentStage));
    }

    $destinations = [];
    foreach ([$currentIndex - 1, $currentIndex + 1] as $index) {
        if (isset($stageOrder[$index]) && $stageOrder[$index] !== $currentStage) {
            $destinations[] = $stageOrder[$index];
        }
    }
    return $destinations;
};

$dealsByStage = [];
$visibleStages = $stage !== '' ? [$stage] : $stages;
if ($viewMode === 'pipeline') {
    foreach ($visibleStages as $stageName) {
        $dealsByStage[$stageName] = $dealsModule->getByStage($stageName, $dealFilters);
    }
} else {
    $allDeals = $dealsModule->getAll(100, 0, $dealFilters);
}

// Keep the assignee filter within the active workspace instead of exposing every platform user.
$allUsers = (new ContactAssignmentAccessService())->getAssignableUsers($workspaceId);
$launchCatalog = new ClarityPackageCatalog();
$launchSettings = (new WorkspaceLaunchSettings())->get();
$launchNiche = $launchCatalog->getNicheProfile((string) ($launchSettings['target_niche'] ?? ClarityPackageCatalog::NICHE_INTERIORS_CONTRACTORS));
$emptyStateCopy = $launchNiche['empty_state_copy'] ?? [];
$dealEmptyStateCopy = is_array($emptyStateCopy)
    ? (string) ($emptyStateCopy['deals'] ?? 'Create a sample opportunity so the team can see how the next steps map into the pipeline.')
    : (string) ($emptyStateCopy !== '' ? $emptyStateCopy : 'Create a sample opportunity so the team can see how the next steps map into the pipeline.');
$defaultCurrencyCode = strtoupper((string) ($defaultCurrency['code'] ?? 'USD'));
$renderAggregateValue = static function (float $amount) use ($currenciesModule, $defaultCurrencyCode): string {
    return $currenciesModule->formatAmount($amount, $defaultCurrencyCode);
};
$renderDealValue = static function (array $deal) use ($currenciesModule, $defaultCurrencyCode): string {
    return $currenciesModule->formatAmount((float) ($deal['value'] ?? 0), $defaultCurrencyCode);
};
$decodeDealCustomFields = static function (array $deal): array {
    $raw = $deal['custom_fields'] ?? null;
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};
$conversionDealBadges = static function (array $deal) use ($isDefaultWorkspaceDealsBoard, $decodeDealCustomFields): array {
    if (!$isDefaultWorkspaceDealsBoard) {
        return [];
    }
    $custom = $decodeDealCustomFields($deal);
    if (empty($custom['default_workspace_pipeline'])) {
        return [];
    }

    $stage = (string) ($deal['stage'] ?? '');
    $signal = (string) ($custom['conversion_signal'] ?? '');
    $badges = [];

    if ($stage === 'closed_won' || $signal === 'paid_subscription') {
        $badges[] = ['label' => 'Paid', 'tone' => 'success'];
    } elseif ($stage === 'closed_lost' || in_array($signal, ['subscription_inactive', 'workspace_inactive'], true)) {
        $badges[] = ['label' => 'Expired', 'tone' => 'danger'];
    } else {
        if ($signal === 'package_active') {
            $badges[] = ['label' => 'Package active', 'tone' => 'info'];
        }
        if ($signal === 'payment_started') {
            $badges[] = ['label' => 'Payment started', 'tone' => 'warning'];
        } elseif ($signal === 'payment_failed') {
            $badges[] = ['label' => 'Payment failed', 'tone' => 'danger'];
        }
    }

    $seen = [];
    return array_values(array_filter($badges, static function (array $badge) use (&$seen): bool {
        $label = (string) ($badge['label'] ?? '');
        if ($label === '' || isset($seen[$label])) {
            return false;
        }
        $seen[$label] = true;
        return true;
    }));
};

$pageTitle = 'Deals - ' . brandProductName();
$dealsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_DEALS);
$dealsForGuidance = $viewMode === 'pipeline' ? [] : ($allDeals ?? []);
$dealsByStageForGuidance = $viewMode === 'pipeline' ? ($dealsByStage ?? []) : [];
$dealsExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $dealsExperienceMode,
    'surface' => 'deals',
    'current_page' => 'deals.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'deals' => $dealsForGuidance,
    'deals_by_stage' => $dealsByStageForGuidance,
    'total_count' => count($dealsForGuidance),
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php echo PageGuideVideoUi::assets(); ?>
<style>
    .deal-automation-strip {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }
    .deals-control-section {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        flex-wrap: wrap;
    }
    .deals-stage-chips {
        flex: 1 1 auto;
    }
    .deal-automation-strip-label {
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .deal-automation-modes {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .deal-mode-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    .deal-mode-chip.active {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #1d4ed8;
    }
    .deals-toolbar-card {
        padding: 1.15rem 1.25rem;
        margin-bottom: 1rem;
    }
    .deals-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(7.5rem, auto));
        gap: 0.85rem;
        margin-left: auto;
        min-width: 28rem;
    }
    .deals-summary-item {
        border-left: 1px solid #e2e8f0;
        padding-left: 0.85rem;
        min-width: 0;
    }
    .deals-summary-label {
        color: #64748b;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        line-height: 1.25;
        text-transform: uppercase;
    }
    .deals-summary-value {
        color: #0f172a;
        font-size: 1.2rem;
        font-weight: 800;
        line-height: 1.25;
        margin-top: 0.25rem;
        word-break: break-word;
    }
    .deals-summary-value.success {
        color: #10b981;
    }
    .deals-summary-value.danger {
        color: #ef4444;
    }
    .deals-summary-subvalue {
        color: #64748b;
        font-size: 0.75rem;
        margin-top: 0.15rem;
    }
    .pipeline-dropzone {
        min-height: 3rem;
        border-radius: 12px;
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    .pipeline-board {
        overflow-x: visible;
        padding-bottom: 0;
        margin-bottom: 0.25rem;
    }
    .pipeline-columns {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 1rem;
        align-items: start;
        min-width: 0;
    }
    .pipeline-column {
        min-width: 0;
        padding: 1.1rem;
    }
    .pipeline-column-header {
        margin-bottom: 1rem;
        padding-bottom: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
    }
    .pipeline-column-meta {
        color: #64748b;
        font-size: 0.8rem;
        line-height: 1.45;
    }
    .pipeline-dropzone.drag-active {
        background: #eff6ff;
        outline: 2px dashed #60a5fa;
        outline-offset: 4px;
    }
    .deal-card {
        display: grid;
        gap: 0.75rem;
        padding: 1rem;
        border: 1px solid #dbe3ef;
        border-radius: 16px;
        background: #fff;
        transition: all 0.2s ease;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }
    .deal-card[draggable="true"] {
        cursor: grab;
    }
    .deal-card-header {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: flex-start;
    }
    .deal-card-title {
        color: #0f172a;
        font-weight: 600;
        text-decoration: none;
        font-size: 1rem;
        line-height: 1.4;
        flex: 1 1 auto;
        min-width: 0;
        word-break: break-word;
    }
    .deal-stage-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.6rem;
        border-radius: 999px;
        color: white;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .deal-card-body {
        display: grid;
        gap: 0.65rem;
    }
    .deal-lifecycle-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }
    .deal-lifecycle-badge {
        display: inline-flex;
        align-items: center;
        min-height: 1.55rem;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #334155;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .deal-lifecycle-badge.info {
        border-color: #bfdbfe;
        background: #eff6ff;
        color: #1d4ed8;
    }
    .deal-lifecycle-badge.warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #92400e;
    }
    .deal-lifecycle-badge.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #166534;
    }
    .deal-lifecycle-badge.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #991b1b;
    }
    .deal-card-value {
        color: #4f46e5;
        font-weight: 700;
        font-size: 1.35rem;
        line-height: 1.2;
    }
    .deal-card-meta {
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.5;
    }
    .deal-card-meta strong {
        color: #334155;
        font-weight: 600;
    }
    .deal-probability {
        display: grid;
        gap: 0.35rem;
    }
    .deal-probability-track {
        background: #e2e8f0;
        height: 7px;
        border-radius: 999px;
        overflow: hidden;
    }
    .deal-probability-bar {
        background: #667eea;
        height: 100%;
    }
    .deal-probability-label {
        color: #64748b;
        font-size: 0.75rem;
    }
    .deal-transition-controls {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0.5rem;
        margin-top: 0.1rem;
        align-items: stretch;
    }
    .deal-transition-select,
    .deal-transition-button {
        min-height: 2.25rem;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        font-size: 0.8rem;
        width: 100%;
    }
    .deal-transition-select {
        padding: 0.45rem 0.6rem;
        background: white;
        min-width: 0;
    }
    .deal-transition-button {
        padding: 0.45rem 0.8rem;
        background: #e2e8f0;
        color: #0f172a;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }
    .deal-page-message {
        display: none;
        margin-bottom: 1rem;
        padding: 0.875rem 1rem;
        border-radius: 12px;
        font-size: 0.875rem;
        font-weight: 600;
    }
    .deal-page-message.show { display: block; }
    .deal-page-message.success { background: #dcfce7; color: #166534; }
    .deal-page-message.error { background: #fee2e2; color: #991b1b; }
    .deal-transition-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        z-index: 1000;
    }
    .deal-transition-modal.open { display: flex; }
    .deal-transition-dialog {
        width: min(100%, 32rem);
        background: white;
        border-radius: 18px;
        padding: 1.25rem;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25);
    }
    .deal-transition-dialog textarea,
    .deal-transition-dialog input {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        padding: 0.75rem 0.875rem;
        margin-top: 0.35rem;
    }
    .deal-transition-dialog-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-top: 1rem;
    }
    .deal-transition-dialog-actions button {
        border: none;
        border-radius: 12px;
        padding: 0.7rem 1rem;
        font-weight: 600;
        cursor: pointer;
    }
    .deal-transition-dialog-actions .secondary {
        background: #e2e8f0;
        color: #0f172a;
    }
    .deal-transition-dialog-actions .primary {
        background: #2563eb;
        color: white;
    }
    .deal-stage-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    .deal-stage-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.7rem 1rem;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #475569;
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    .deal-stage-tab:hover,
    .deal-stage-tab.active {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #1d4ed8;
    }
    @media (max-width: 1500px) {
        .pipeline-board {
            overflow-x: auto;
            padding-bottom: 0.75rem;
        }
        .pipeline-columns {
            grid-auto-flow: column;
            grid-auto-columns: minmax(280px, 320px);
            grid-template-columns: none;
            min-width: max-content;
        }
    }
    @media (max-width: 900px) {
        .deals-stage-chips,
        .deals-control-section {
            width: 100%;
        }
        .deals-summary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-left: 0;
            min-width: 0;
            width: 100%;
        }
        .pipeline-columns {
            grid-auto-columns: minmax(260px, 85vw);
        }
        .deal-card-header {
            flex-direction: column;
        }
        .deal-transition-controls {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 640px) {
        .deals-summary {
            grid-template-columns: 1fr;
            gap: 0.65rem;
        }
        .deals-summary-item {
            border-left: 0;
            border-top: 1px solid #e2e8f0;
            padding-left: 0;
            padding-top: 0.65rem;
        }
    }
</style>

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Deals</h1>
                <p>Manage your sales pipeline and opportunities</p>
            </div>
            <div class="page-header-actions">
                <?php if ($dealsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_DEALS, 'Deals page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="deal_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Deal
                </a>
                <a href="<?php echo htmlspecialchars($viewToggleUrl); ?>" class="btn-premium-secondary">
                    <i class="fas fa-<?php echo $viewMode === 'pipeline' ? 'list' : 'columns'; ?>"></i>
                    <?php echo $viewMode === 'pipeline' ? 'List View' : 'Pipeline View'; ?>
                </a>
            </div>
        </div>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>
        <div id="deal-page-message" class="deal-page-message"></div>

        <div class="deal-automation-strip" aria-label="Deal filters, automation mode, and pipeline summary">
            <div class="deals-control-section deals-stage-chips" aria-label="Deal stage filter">
                <?php foreach ($stageChipItems as $stageKey => $stageChip): ?>
                    <?php
                    $isStageActive = $stage === (string) $stageKey;
                    $stageHref = $stageKey === ''
                        ? $buildDealsUrl([], ['stage'])
                        : $buildDealsUrl(['stage' => $stageKey]);
                    ?>
                    <a
                        href="<?php echo htmlspecialchars($stageHref); ?>"
                        class="deal-stage-tab <?php echo $isStageActive ? 'active' : ''; ?>"
                    >
                        <i class="fas <?php echo htmlspecialchars((string) $stageChip['icon']); ?>"></i>
                        <?php echo htmlspecialchars((string) $stageChip['label']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="deals-control-section" aria-label="Deal automation mode">
                <span class="deal-automation-strip-label">Automation mode</span>
                <div class="deal-automation-modes">
                    <?php foreach (['manual' => 'Manual', 'suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $modeKey => $modeLabel): ?>
                        <span class="deal-mode-chip <?php echo ($dealAutomationState['current_mode'] ?? 'manual') === $modeKey ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars($modeLabel); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="deals-summary" aria-label="Pipeline summary">
                <div class="deals-summary-item">
                    <div class="deals-summary-label">Pipeline value</div>
                    <div class="deals-summary-value">
                        <?php echo htmlspecialchars($renderAggregateValue((float) $pipelineStats['total_pipeline_value'])); ?>
                    </div>
                </div>
                <div class="deals-summary-item">
                    <div class="deals-summary-label">Won deals</div>
                    <div class="deals-summary-value success">
                        <?php echo number_format($pipelineStats['won']['count']); ?>
                    </div>
                    <div class="deals-summary-subvalue">
                        <?php echo htmlspecialchars($renderAggregateValue((float) $pipelineStats['won']['value'])); ?>
                    </div>
                </div>
                <div class="deals-summary-item">
                    <div class="deals-summary-label">Lost deals</div>
                    <div class="deals-summary-value danger">
                        <?php echo number_format($pipelineStats['lost']['count']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card deals-toolbar-card">
            <form method="GET" action="" class="filters-form">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
                <?php if ($stage !== ''): ?>
                    <input type="hidden" name="stage" value="<?php echo htmlspecialchars($stage); ?>">
                <?php endif; ?>

                <div class="filter-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search deals..."
                    >
                </div>

                <div class="filter-group">
                    <label for="assigned_to">Assigned To</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">All workspace members</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $assignedTo === (int) $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['email']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search !== '' || $hasAssignedToParam): ?>
                        <a href="<?php echo htmlspecialchars($clearFilterUrl); ?>" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($viewMode === 'pipeline'): ?>
            <!-- Pipeline View - all stages visible, no carousel -->
            <div class="pipeline-board">
            <div class="pipeline-columns">
                <?php foreach ($visibleStages as $stageName):
                    $deals = $dealsByStage[$stageName] ?? [];
                    $stageStat = null;
                    foreach ($pipelineStats['by_stage'] as $stat) {
                        if ($stat['stage'] === $stageName) {
                            $stageStat = $stat;
                            break;
                        }
                    }
                ?>
                    <div class="pipeline-column content-card">
                        <div class="pipeline-column-header">
                            <h3 style="color: #0f172a; font-size: 1rem; margin: 0 0 0.25rem 0; font-weight: 600;">
                                <?php echo $stageLabels[$stageName]; ?>
                            </h3>
                            <div class="pipeline-column-meta">
                                <?php echo count($deals); ?> deal<?php echo count($deals) !== 1 ? 's' : ''; ?>
                                <?php if ($stageStat): ?>
                                    • <?php 
                                    echo htmlspecialchars($renderAggregateValue((float) ($stageStat['total_value'] ?? 0)));
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="pipeline-dropzone" data-stage="<?php echo htmlspecialchars($stageName); ?>" style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 600px; overflow-y: auto; padding-right: 0.15rem;">
                            <?php if (empty($deals)): ?>
                                <div class="empty-state" style="padding: 1rem;">
                                    <p style="margin: 0; font-size: 0.875rem;">No deals</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($deals as $deal): ?>
                                    <?php
                                        $destinations = $allowedDestinations((string) $deal['stage']);
                                        $lifecycleBadges = $conversionDealBadges($deal);
                                    ?>
                                    <div class="deal-card"
                                         draggable="true"
                                         data-deal-id="<?php echo (int) $deal['id']; ?>"
                                         data-current-stage="<?php echo htmlspecialchars((string) $deal['stage']); ?>"
                                         data-deal-title="<?php echo htmlspecialchars((string) $deal['title']); ?>">
                                        <div class="deal-card-header">
                                            <a href="deal_view.php?id=<?php echo $deal['id']; ?>" class="deal-card-title">
                                                <?php echo htmlspecialchars($deal['title']); ?>
                                            </a>
                                            <span class="deal-stage-pill" data-stage-pill style="background: <?php echo htmlspecialchars($stageColors[(string) $deal['stage']] ?? '#64748b'); ?>;">
                                                <?php echo htmlspecialchars($stageLabels[(string) $deal['stage']] ?? str_replace('_', ' ', (string) $deal['stage'])); ?>
                                            </span>
                                        </div>
                                        <div class="deal-card-body">
                                        <?php if ($lifecycleBadges): ?>
                                            <div class="deal-lifecycle-badges" aria-label="Default workspace lifecycle">
                                                <?php foreach ($lifecycleBadges as $badge): ?>
                                                    <span class="deal-lifecycle-badge <?php echo htmlspecialchars((string) ($badge['tone'] ?? '')); ?>">
                                                        <?php echo htmlspecialchars((string) ($badge['label'] ?? '')); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($deal['value'] > 0): ?>
                                            <div class="deal-card-value">
                                                <?php 
                                                echo htmlspecialchars($renderDealValue($deal));
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($deal['contact_first_name'] || $deal['contact_last_name']): ?>
                                            <div class="deal-card-meta">
                                                <strong>Contact:</strong>
                                                <?php echo htmlspecialchars(($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? '')); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($deal['probability'] > 0): ?>
                                            <div class="deal-probability">
                                                <div class="deal-probability-track">
                                                    <div class="deal-probability-bar" style="width: <?php echo $deal['probability']; ?>%;"></div>
                                                </div>
                                                <div class="deal-probability-label">
                                                    <?php echo $deal['probability']; ?>% probability
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($deal['expected_close_date']): ?>
                                            <div class="deal-card-meta">
                                                <strong><i class="fas fa-calendar"></i> Close:</strong> <?php echo date('M d, Y', strtotime($deal['expected_close_date'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        </div>
                                        <div class="deal-transition-controls">
                                            <select class="deal-transition-select" data-transition-select>
                                                <option value="">Move to...</option>
                                                <?php foreach ($destinations as $destination): ?>
                                                    <option value="<?php echo htmlspecialchars($destination); ?>">
                                                        <?php echo htmlspecialchars($stageLabels[$destination] ?? ucfirst(str_replace('_', ' ', $destination))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="deal-transition-button" data-transition-button>Move</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        <?php else: ?>
            <!-- List View -->
            <div class="table-card">
                <?php if (empty($allDeals)): ?>
                    <div class="empty-state">
                        <p>No deals found.</p>
                        <p style="color:#64748b;"><?php echo htmlspecialchars($dealEmptyStateCopy); ?></p>
                        <a href="deal_create.php">
                            Create your first deal →
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Deal</th>
                                <th>Contact</th>
                                <th>Stage</th>
                                <th style="text-align: right;">Value</th>
                                <th style="text-align: center;">Probability</th>
                                <th>Assigned To</th>
                                <th>Expected Close</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allDeals as $deal): ?>
                                <tr>
                                    <td>
                                        <a href="deal_view.php?id=<?php echo $deal['id']; ?>" style="color: #0f172a; text-decoration: none; font-weight: 500;">
                                            <?php echo htmlspecialchars($deal['title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($deal['contact_id']): ?>
                                            <a href="contact_view.php?id=<?php echo $deal['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                                <?php echo htmlspecialchars(($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? '')); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #64748b;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-default" data-list-stage-badge="<?php echo (int) $deal['id']; ?>">
                                            <?php echo htmlspecialchars($stageLabels[(string) $deal['stage']] ?? str_replace('_', ' ', (string) $deal['stage'])); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; font-weight: 600; color: #0f172a;">
                                        <?php 
                                        echo htmlspecialchars($renderDealValue($deal));
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($deal['probability'] > 0): ?>
                                            <div style="display: inline-block; width: 60px; background: rgba(0, 0, 0, 0.1); height: 8px; border-radius: 4px; overflow: hidden;">
                                                <div style="background: #667eea; height: 100%; width: <?php echo $deal['probability']; ?>%;"></div>
                                            </div>
                                            <div style="color: #64748b; font-size: 0.6875rem; margin-top: 4px;">
                                                <?php echo $deal['probability']; ?>%
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #64748b;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($deal['assigned_to_email'] ?? 'Unassigned'); ?>
                                    </td>
                                    <td>
                                        <?php echo $deal['expected_close_date'] ? date('M d, Y', strtotime($deal['expected_close_date'])) : '-'; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php $destinations = $allowedDestinations((string) $deal['stage']); ?>
                                        <select class="deal-transition-select"
                                                data-transition-select
                                                data-deal-id="<?php echo (int) $deal['id']; ?>"
                                                data-current-stage="<?php echo htmlspecialchars((string) $deal['stage']); ?>"
                                                data-deal-title="<?php echo htmlspecialchars((string) $deal['title']); ?>"
                                                style="margin-right:0.5rem;">
                                            <option value="">Move to...</option>
                                            <?php foreach ($destinations as $destination): ?>
                                                <option value="<?php echo htmlspecialchars($destination); ?>">
                                                    <?php echo htmlspecialchars($stageLabels[$destination] ?? ucfirst(str_replace('_', ' ', $destination))); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button"
                                                class="deal-transition-button"
                                                data-transition-button
                                                data-deal-id="<?php echo (int) $deal['id']; ?>"
                                                data-current-stage="<?php echo htmlspecialchars((string) $deal['stage']); ?>"
                                                data-deal-title="<?php echo htmlspecialchars((string) $deal['title']); ?>"
                                                style="margin-right:0.5rem;">
                                            Move
                                        </button>
                                        <a href="deal_edit.php?id=<?php echo $deal['id']; ?>" style="color: #64748b; text-decoration: none; font-size: 0.875rem; margin-right: 0.75rem;">Edit</a>
                                        <a href="deal_delete.php?id=<?php echo $deal['id']; ?>" onclick="return confirm('Are you sure you want to delete this deal?');" style="color: #ef4444; text-decoration: none; font-size: 0.875rem;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="deal-transition-modal" class="deal-transition-modal" aria-hidden="true">
    <div class="deal-transition-dialog">
        <h3 id="deal-transition-modal-title" style="margin:0 0 0.4rem;color:#0f172a;">Confirm stage transition</h3>
        <p id="deal-transition-modal-copy" style="margin:0 0 1rem;color:#475569;font-size:0.875rem;"></p>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Close date</span>
            <input type="date" id="deal-transition-close-date">
        </label>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Reason</span>
            <input type="text" id="deal-transition-reason" placeholder="Required for Lost, useful for Won">
        </label>
        <label style="display:block;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Close note</span>
            <textarea id="deal-transition-close-note" rows="4" placeholder="Summarize the outcome for future context, AI, and automation."></textarea>
        </label>
        <div class="deal-transition-dialog-actions">
            <button type="button" class="secondary" id="deal-transition-cancel">Cancel</button>
            <button type="button" class="primary" id="deal-transition-confirm">Confirm transition</button>
        </div>
    </div>
</div>

<script>
(() => {
    const csrfToken = <?php echo json_encode(Security::getCsrfToken()); ?>;
    const stageLabels = <?php echo json_encode($stageLabels); ?>;
    const stageColors = <?php echo json_encode($stageColors); ?>;
    const currentView = <?php echo json_encode($viewMode); ?>;
    const pageMessage = document.getElementById('deal-page-message');
    const modal = document.getElementById('deal-transition-modal');
    const modalTitle = document.getElementById('deal-transition-modal-title');
    const modalCopy = document.getElementById('deal-transition-modal-copy');
    const closeDateInput = document.getElementById('deal-transition-close-date');
    const reasonInput = document.getElementById('deal-transition-reason');
    const closeNoteInput = document.getElementById('deal-transition-close-note');
    const confirmButton = document.getElementById('deal-transition-confirm');
    const cancelButton = document.getElementById('deal-transition-cancel');
    let pendingTransition = null;
    let draggedCard = null;

    const showMessage = (message, type = 'success') => {
        if (!pageMessage) return;
        pageMessage.className = `deal-page-message show ${type}`;
        pageMessage.textContent = message;
    };

    const getControlContext = (trigger) => {
        const card = trigger.closest('.deal-card');
        if (card) {
            return {
                dealId: parseInt(card.dataset.dealId || '0', 10),
                currentStage: card.dataset.currentStage || '',
                title: card.dataset.dealTitle || '',
                card,
                select: card.querySelector('[data-transition-select]'),
            };
        }
        return {
            dealId: parseInt(trigger.dataset.dealId || '0', 10),
            currentStage: trigger.dataset.currentStage || '',
            title: trigger.dataset.dealTitle || '',
            card: null,
            select: trigger.parentElement?.querySelector('[data-transition-select]'),
        };
    };

    const updateStageUi = (dealId, toStage, card) => {
        if (card) {
            card.dataset.currentStage = toStage;
            const pill = card.querySelector('[data-stage-pill]');
            if (pill) {
                pill.textContent = stageLabels[toStage] || toStage;
                pill.style.background = stageColors[toStage] || '#64748b';
            }
            const destinationColumn = document.querySelector(`.pipeline-dropzone[data-stage="${toStage}"]`);
            if (destinationColumn && currentView === 'pipeline') {
                destinationColumn.appendChild(card);
            }
            const select = card.querySelector('[data-transition-select]');
            if (select) {
                select.value = '';
            }
        }
        const rowBadge = document.querySelector(`[data-list-stage-badge="${dealId}"]`);
        if (rowBadge) {
            rowBadge.textContent = stageLabels[toStage] || toStage;
        }
        document.querySelectorAll(`[data-deal-id="${dealId}"]`).forEach((el) => {
            if (el.dataset) {
                el.dataset.currentStage = toStage;
            }
        });
    };

    const submitTransition = async (payload, context) => {
        try {
            const response = await fetch('api/deals/stage_transition.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ ...payload, csrf_token: csrfToken }),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Transition failed');
            }
            updateStageUi(context.dealId, result.to_stage, context.card);
            showMessage(result.message || 'Deal stage updated.');
        } catch (error) {
            showMessage(error.message || 'Unable to update deal stage.', 'error');
        }
    };

    const closeModal = () => {
        pendingTransition = null;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        closeDateInput.value = '';
        reasonInput.value = '';
        closeNoteInput.value = '';
    };

    const openTerminalModal = (context, toStage) => {
        pendingTransition = { context, toStage };
        modalTitle.textContent = `Confirm move to ${stageLabels[toStage] || toStage}`;
        modalCopy.textContent = toStage === 'closed_lost'
            ? `Capture why "${context.title}" was lost so notes, AI context, and follow-up automations stay accurate.`
            : `Capture the closing context for "${context.title}" so reporting, AI context, and automations stay accurate.`;
        closeDateInput.value = new Date().toISOString().slice(0, 10);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    };

    document.querySelectorAll('[data-transition-button]').forEach((button) => {
        button.addEventListener('click', () => {
            const context = getControlContext(button);
            const toStage = context.select?.value || '';
            if (!context.dealId || !toStage) {
                showMessage('Choose a destination stage first.', 'error');
                return;
            }
            if (toStage === 'closed_won' || toStage === 'closed_lost') {
                openTerminalModal(context, toStage);
                return;
            }
            submitTransition({ deal_id: context.dealId, to_stage: toStage }, context);
        });
    });

    document.querySelectorAll('.deal-card').forEach((card) => {
        card.addEventListener('dragstart', () => {
            draggedCard = card;
            card.style.opacity = '0.55';
        });
        card.addEventListener('dragend', () => {
            card.style.opacity = '1';
            draggedCard = null;
            document.querySelectorAll('.pipeline-dropzone').forEach((zone) => zone.classList.remove('drag-active'));
        });
    });

    document.querySelectorAll('.pipeline-dropzone').forEach((zone) => {
        zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('drag-active');
        });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-active'));
        zone.addEventListener('drop', (event) => {
            event.preventDefault();
            zone.classList.remove('drag-active');
            if (!draggedCard) return;
            const context = {
                dealId: parseInt(draggedCard.dataset.dealId || '0', 10),
                currentStage: draggedCard.dataset.currentStage || '',
                title: draggedCard.dataset.dealTitle || '',
                card: draggedCard,
            };
            const toStage = zone.dataset.stage || '';
            if (!context.dealId || !toStage || toStage === context.currentStage) {
                return;
            }
            if (toStage === 'closed_won' || toStage === 'closed_lost') {
                openTerminalModal(context, toStage);
                return;
            }
            submitTransition({ deal_id: context.dealId, to_stage: toStage }, context);
        });
    });

    confirmButton?.addEventListener('click', () => {
        if (!pendingTransition) {
            return;
        }
        const payload = {
            deal_id: pendingTransition.context.dealId,
            to_stage: pendingTransition.toStage,
            close_date: closeDateInput.value,
            reason: reasonInput.value,
            close_note: closeNoteInput.value,
        };
        submitTransition(payload, pendingTransition.context);
        closeModal();
    });

    cancelButton?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

})();
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_DEALS, 'How to use Deals', $dealsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
