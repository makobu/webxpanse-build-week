<?php
/**
 * Attribution Reports Page
 */

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
use CRM\Session;
use CRM\Modules\AttributionReports;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (($_ENV['ATTRIBUTION_ENABLED'] ?? '1') !== '1') {
    http_response_code(404);
    echo 'Attribution reporting is disabled.';
    exit;
}

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$reports = new AttributionReports();
$models = [];
$modelSlug = 'last_touch';
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;
$revenueByCampaign = [];
$channelContribution = [];
$journeys = [];
$error = null;

try {
    $models = $reports->getModelOptions();
    $modelSlug = $_GET['model'] ?? (($models[0]['slug'] ?? 'last_touch'));
    $revenueByCampaign = $reports->getRevenueByCampaign($modelSlug, $dateFrom, $dateTo);
    $channelContribution = $reports->getChannelContribution($modelSlug, $dateFrom, $dateTo);
    $journeys = $reports->getTopJourneys($modelSlug, 15);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Attribution Reports - ' . brandProductName();
$attributionReportsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_ATTRIBUTION_REPORTS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium marketing-attribution-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Attribution Reports</h1>
                <p>Compare campaign and channel contribution by attribution model.</p>
            </div>
        </div>

        <div class="filters-card">
            <?php if ($error !== null): ?>
                <div class="report-banner report-banner-error marketing-legacy-spaced">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label for="attribution_model">Model</label>
                    <select id="attribution_model" name="model">
                        <?php foreach ($models as $model): ?>
                            <option value="<?php echo htmlspecialchars($model['slug']); ?>" <?php echo $modelSlug === $model['slug'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($model['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="date_from">From</label>
                    <input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars((string) $dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to">To</label>
                    <input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars((string) $dateTo); ?>">
                </div>
                <div class="filter-actions">
                    <button class="btn-premium-primary" type="submit">
                        <i class="fas fa-filter"></i>
                        Apply
                    </button>
                    <?php if ($dateFrom || $dateTo || $modelSlug !== (($models[0]['slug'] ?? 'last_touch'))): ?>
                        <a href="attribution_reports.php" class="btn-premium-secondary">Reset</a>
                    <?php endif; ?>
                    <?php if ($attributionReportsGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_ATTRIBUTION_REPORTS, 'Attribution Reports page guide', 'btn-premium-secondary'); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-card">
            <div class="premium-section-header"><h2>Revenue by Campaign</h2></div>
            <?php if (empty($revenueByCampaign)): ?>
                <div class="empty-state"><p>No attributed revenue for this filter.</p></div>
            <?php else: ?>
                <?php foreach ($revenueByCampaign as $row): ?>
                    <div class="premium-list-row">
                        <span class="premium-list-title"><?php echo htmlspecialchars($row['campaign_name'] ?? 'Unassigned Campaign'); ?></span>
                        <span class="marketing-legacy-value">$<?php echo number_format((float) ($row['credited_revenue'] ?? 0), 2); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="table-card marketing-legacy-section-spaced">
            <div class="premium-section-header"><h2>Channel Contribution</h2></div>
            <?php if (empty($channelContribution)): ?>
                <div class="empty-state"><p>No channel contribution for this filter.</p></div>
            <?php else: ?>
                <?php foreach ($channelContribution as $row): ?>
                    <div class="premium-list-row">
                        <span class="premium-list-title"><?php echo htmlspecialchars($row['channel']); ?></span>
                        <span class="marketing-legacy-value">$<?php echo number_format((float) ($row['credited_revenue'] ?? 0), 2); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="table-card marketing-legacy-section-spaced">
            <div class="premium-section-header"><h2>Top Journeys</h2></div>
            <?php if (empty($journeys)): ?>
                <div class="empty-state"><p>No customer journeys are available yet.</p></div>
            <?php else: ?>
                <?php foreach ($journeys as $row): ?>
                    <div class="premium-list-row">
                        <div class="premium-list-main">
                            <div class="premium-list-title">Deal #<?php echo (int) $row['deal_id']; ?> - $<?php echo number_format((float) ($row['credited_value'] ?? 0), 2); ?></div>
                            <div class="premium-list-meta"><?php echo htmlspecialchars((string) ($row['journey'] ?? '')); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_ATTRIBUTION_REPORTS, 'How to use Attribution Reports', $attributionReportsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
