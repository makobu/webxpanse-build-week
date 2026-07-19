<?php
/**
 * Dedicated deal stage page template.
 *
 * Expected variables before include:
 * - $fixedStage
 * - $activeDealPage
 * - $pageHeading
 * - $pageSubheading
 */

if (!isset($fixedStage, $activeDealPage, $pageHeading, $pageSubheading)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Modules\ClarityPackageCatalog;
use CRM\Modules\Currencies;
use CRM\Modules\Deals;
use CRM\Modules\WorkspaceLaunchSettings;
use CRM\Security;
use CRM\Services\DealAutomationReadinessService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$dealsModule = new Deals();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$user = Auth::user();
$dealAutomationState = (new DealAutomationReadinessService())->getState();
$dealAutomationConfig = (array) ($dealAutomationState['config'] ?? []);

$search = $_GET['search'] ?? '';
$assignedTo = !empty($_GET['assigned_to']) ? (int) $_GET['assigned_to'] : null;
$viewMode = 'list';

$stageLabels = [
    'prospecting' => 'Prospecting',
    'qualification' => 'Qualification',
    'proposal' => 'Proposal',
    'negotiation' => 'Negotiation',
    'closed_won' => 'Won',
    'closed_lost' => 'Lost',
];
$stageColors = [
    'prospecting' => '#64748b',
    'qualification' => '#2563eb',
    'proposal' => '#f59e0b',
    'negotiation' => '#16a34a',
    'closed_won' => '#059669',
    'closed_lost' => '#dc2626',
];
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

$filters = ['stage' => $fixedStage];
if ($search !== '') {
    $filters['search'] = $search;
}
if ($assignedTo) {
    $filters['assigned_to'] = $assignedTo;
}

$allDeals = $dealsModule->getAll(100, 0, $filters);
$allUsers = Database::query('SELECT id, email FROM users ORDER BY email ASC');
$launchCatalog = new ClarityPackageCatalog();
$launchSettings = (new WorkspaceLaunchSettings())->get();
$launchNiche = $launchCatalog->getNicheProfile((string) ($launchSettings['target_niche'] ?? ClarityPackageCatalog::NICHE_INTERIORS_CONTRACTORS));
$emptyStateCopy = $launchNiche['empty_state_copy'] ?? [];
$defaultEmptyStateCopy = 'Create a sample opportunity so the team can see how the next steps map into the pipeline.';
$dealEmptyStateCopy = is_array($emptyStateCopy)
    ? (string) ($emptyStateCopy['deals'] ?? $defaultEmptyStateCopy)
    : (string) ($emptyStateCopy !== '' ? $emptyStateCopy : $defaultEmptyStateCopy);
$defaultCurrencyCode = strtoupper((string) ($defaultCurrency['code'] ?? 'USD'));
$renderDealValue = static function (array $deal) use ($currenciesModule, $defaultCurrencyCode): string {
    return $currenciesModule->formatAmount((float) ($deal['value'] ?? 0), $defaultCurrencyCode);
};
$stageDealCount = count($allDeals);
$stageDealValue = array_reduce(
    $allDeals,
    static fn (float $carry, array $deal): float => $carry + (float) ($deal['value'] ?? 0),
    0.0
);
$pageTitle = $pageHeading . ' - ' . brandProductName();
$clearHref = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
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
    .deal-transition-select,
    .deal-transition-button {
        min-height: 2.25rem;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        font-size: 0.8rem;
    }
    .deal-transition-select {
        padding: 0.45rem 0.6rem;
        background: white;
    }
    .deal-transition-button {
        padding: 0.45rem 0.8rem;
        background: #e2e8f0;
        color: #0f172a;
        font-weight: 600;
        cursor: pointer;
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
</style>

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($pageHeading); ?></h1>
                <p><?php echo htmlspecialchars($pageSubheading); ?></p>
            </div>
            <div class="page-header-actions">
                <a href="deal_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Deal
                </a>
                <a href="deals.php?view=pipeline" class="btn-premium-secondary">
                    <i class="fas fa-columns"></i>
                    Pipeline View
                </a>
            </div>
        </div>

        <?php include __DIR__ . '/../views/partials/deals_stage_tabs.php'; ?>

        <div id="deal-page-message" class="deal-page-message"></div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label"><?php echo htmlspecialchars($stageLabels[$fixedStage] ?? $fixedStage); ?> Deals</div>
                <div class="stat-value"><?php echo number_format($stageDealCount); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><?php echo htmlspecialchars($stageLabels[$fixedStage] ?? $fixedStage); ?> Value</div>
                <div class="stat-value"><?php echo htmlspecialchars($currenciesModule->formatAmount($stageDealValue, $defaultCurrencyCode)); ?></div>
            </div>
        </div>

        <div class="filters-card">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search <?php echo htmlspecialchars(strtolower($stageLabels[$fixedStage] ?? 'deals')); ?> deals..."
                    >
                </div>
                <div class="filter-group">
                    <label for="assigned_to">Assigned To</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">All Users</option>
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
                    <?php if ($search !== '' || $assignedTo): ?>
                        <a href="<?php echo htmlspecialchars($clearHref); ?>" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php include __DIR__ . '/../views/partials/deals_list_table.php'; ?>
    </div>
</div>

<?php include __DIR__ . '/../views/partials/deals_transition_modal.php'; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
