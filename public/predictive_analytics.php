<?php
/**
 * Predictive Analytics Dashboard
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
use CRM\Modules\PredictiveAnalytics;
use CRM\Modules\Currencies;
use CRM\Security;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
(new WorkspaceBusinessIntelligenceGateService())->enforceWeb($workspaceId, $user, 'Predictive Analytics');

$error = null;
$dashboardData = null;

// Get currency module
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();

try {
    $analytics = new PredictiveAnalytics();
    // Get dashboard data
    $dashboardData = $analytics->getDashboardData();
} catch (\Exception $e) {
    $error = $e->getMessage();
    // Log error
    if (class_exists('\CRM\Logger')) {
        $logger = new \CRM\Logger();
        $logger->error('Failed to load predictive analytics dashboard', ['error' => $e->getMessage()]);
    }
}

// Helper function to format currency
$formatCurrency = function($amount) use ($currenciesModule, $defaultCurrency) {
    if ($defaultCurrency) {
        return $currenciesModule->formatAmount($amount, $defaultCurrency['code']);
    }
    return '$' . number_format((float) $amount, 2);
};

$pageTitle = 'Predictive Analytics';
$predictiveAnalyticsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_PREDICTIVE_ANALYTICS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="predictive-analytics-title">
            <div>
                <h1 id="predictive-analytics-title">Predictive Analytics</h1>
                <p>Forecast conversion, churn risk, and revenue movement from CRM activity.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="analytics.php"><i class="fas fa-chart-line" aria-hidden="true"></i>Analytics</a>
                <a class="btn-premium-secondary" href="contacts.php"><i class="fas fa-users" aria-hidden="true"></i>Contacts</a>
                <?php if ($predictiveAnalyticsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_PREDICTIVE_ANALYTICS, 'Predictive Analytics page guide'); ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($error): ?>
            <div class="premium-banner premium-banner-error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <div class="content-card">
                <p class="premium-empty-compact">Please try refreshing the page or contact support if the issue persists.</p>
            </div>
        <?php elseif (!$dashboardData): ?>
            <div class="premium-banner premium-banner-error">
                <strong>Warning:</strong> Unable to load dashboard data. Please try again later.
            </div>
        <?php else: ?>
            <div class="stats-grid" aria-label="Predictive analytics summary">
                <div class="stat-card">
                    <div class="stat-label">High Probability Leads</div>
                    <div class="stat-value"><?php echo number_format((int) ($dashboardData['summary']['high_probability_leads'] ?? 0)); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">At Risk Customers</div>
                    <div class="stat-value"><?php echo number_format((int) ($dashboardData['summary']['at_risk_customers'] ?? 0)); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">6-Month Forecast</div>
                    <div class="stat-value"><?php echo $formatCurrency($dashboardData['summary']['forecasted_revenue_6m'] ?? 0); ?></div>
                </div>
            </div>

            <section class="table-card" aria-labelledby="conversion-predictions-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="conversion-predictions-title">Lead Conversion Predictions</h2>
                        <p>High-value leads ranked by forecasted conversion probability.</p>
                    </div>
                </div>
                <?php if (empty($dashboardData['conversion_predictions'])): ?>
                    <div class="empty-state">
                        <p>No high-value leads to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-card-scroll">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Contact</th>
                                    <th>Stage</th>
                                    <th>Conversion Probability</th>
                                    <th>Time to Conversion</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dashboardData['conversion_predictions'] as $prediction): ?>
                                    <?php $probability = max(0, min(100, (float) ($prediction['conversion_probability'] ?? 0))); ?>
                                    <tr>
                                        <td>
                                            <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) ($prediction['contact']['id'] ?? 0); ?>">
                                                <?php echo htmlspecialchars(trim((string) (($prediction['contact']['first_name'] ?? '') . ' ' . ($prediction['contact']['last_name'] ?? '')))); ?>
                                            </a>
                                            <div class="premium-inline-note"><?php echo htmlspecialchars((string) ($prediction['contact']['email'] ?? '')); ?></div>
                                        </td>
                                        <td>
                                            <span class="premium-status-badge is-info"><?php echo htmlspecialchars((string) ($prediction['contact']['stage'] ?? 'new')); ?></span>
                                        </td>
                                        <td>
                                            <div class="premium-progress-cell">
                                                <div class="premium-progress" style="--premium-progress-value: <?php echo $probability; ?>%;">
                                                    <div class="premium-progress-fill"></div>
                                                </div>
                                                <strong><?php echo number_format($probability, 1); ?>%</strong>
                                            </div>
                                        </td>
                                        <td><?php echo (int) ($prediction['time_to_conversion'] ?? 0); ?> days</td>
                                        <td>
                                            <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) ($prediction['contact']['id'] ?? 0); ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="table-card" aria-labelledby="churn-predictions-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="churn-predictions-title">Churn Risk Predictions</h2>
                        <p>Customers with retention risk signals and recommended review targets.</p>
                    </div>
                </div>
                <?php if (empty($dashboardData['churn_predictions'])): ?>
                    <div class="empty-state">
                        <p>No churn risk predictions to display.</p>
                    </div>
                <?php else: ?>
                    <div class="table-card-scroll">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Churn Probability</th>
                                    <th>Risk Factors</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dashboardData['churn_predictions'] as $prediction): ?>
                                    <?php $churnProbability = max(0, min(100, (float) ($prediction['churn_probability'] ?? 0))); ?>
                                    <tr>
                                        <td>
                                            <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) ($prediction['contact']['id'] ?? 0); ?>">
                                                <?php echo htmlspecialchars(trim((string) (($prediction['contact']['first_name'] ?? '') . ' ' . ($prediction['contact']['last_name'] ?? '')))); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div class="premium-progress-cell">
                                                <div class="premium-progress" style="--premium-progress-value: <?php echo $churnProbability; ?>%;">
                                                    <div class="premium-progress-fill is-danger"></div>
                                                </div>
                                                <strong><?php echo number_format($churnProbability, 1); ?>%</strong>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars(implode(', ', array_slice((array) ($prediction['risk_factors'] ?? []), 0, 3))); ?></td>
                                        <td>
                                            <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) ($prediction['contact']['id'] ?? 0); ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="table-card" aria-labelledby="revenue-forecast-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="revenue-forecast-title">Revenue Forecast</h2>
                        <p>
                            Historical average monthly revenue: <strong><?php echo $formatCurrency($dashboardData['revenue_forecast']['historical_avg_monthly'] ?? 0); ?></strong>
                            &middot; Current pipeline value: <strong><?php echo $formatCurrency($dashboardData['revenue_forecast']['current_pipeline_value'] ?? 0); ?></strong>
                        </p>
                    </div>
                </div>
                <?php if (empty($dashboardData['revenue_forecast']['forecast'])): ?>
                    <div class="empty-state">
                        <p>No revenue forecast rows are available yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-card-scroll">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Forecasted Revenue</th>
                                    <th>Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dashboardData['revenue_forecast']['forecast'] as $forecast): ?>
                                    <?php $confidence = max(0, min(100, ((float) ($forecast['confidence'] ?? 0)) * 100)); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('F Y', strtotime((string) ($forecast['month'] ?? '') . '-01'))); ?></td>
                                        <td><strong><?php echo $formatCurrency($forecast['forecasted_revenue'] ?? 0); ?></strong></td>
                                        <td>
                                            <div class="premium-progress-cell">
                                                <div class="premium-progress" style="--premium-progress-value: <?php echo $confidence; ?>%;">
                                                    <div class="premium-progress-fill is-success"></div>
                                                </div>
                                                <span><?php echo number_format($confidence, 0); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><strong>Total</strong></td>
                                    <td><strong><?php echo $formatCurrency($dashboardData['revenue_forecast']['total_forecasted'] ?? 0); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_PREDICTIVE_ANALYTICS, 'How to use Predictive Analytics', $predictiveAnalyticsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
