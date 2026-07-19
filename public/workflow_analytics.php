<?php
/**
 * Workflow Analytics Page
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
use CRM\Modules\WorkflowAnalytics;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require admin role
$user = Auth::user();
if (!Authorization::can('workflows.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$workflowId = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$analyticsModule = new WorkflowAnalytics();
$analyticsWorkspace = new AnalyticsWorkspaceService();
$workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();

if ($workflowId) {
    $analytics = $analyticsModule->getWorkflowAnalytics($workflowId, $startDate, $endDate);
    $workflow = Database::queryOne("SELECT name FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]);
} else {
    $analytics = $analyticsModule->getAllWorkflowsAnalytics($startDate, $endDate);
    $workflow = null;
}

$pageTitle = 'Workflow Analytics - ' . brandProductName();
$workflowAnalyticsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_WORKFLOW_ANALYTICS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="workflow-analytics-title">
            <div>
                <h1 id="workflow-analytics-title">Workflow Analytics</h1>
                <p>Performance metrics, execution reliability, queue lag, and failure hotspots.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="ai_automation_diagnostics.php?source=workflow&date_from=<?php echo urlencode($startDate); ?>&date_to=<?php echo urlencode($endDate); ?>"><i class="fas fa-stethoscope" aria-hidden="true"></i>Open Diagnostics</a>
                <?php if ($workflowId): ?>
                    <a class="btn-premium-secondary" href="workflow_analytics.php"><i class="fas fa-layer-group" aria-hidden="true"></i>View All</a>
                <?php endif; ?>
                <?php if ($workflowAnalyticsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_WORKFLOW_ANALYTICS, 'Workflow Analytics page guide'); ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="filters-card" aria-label="Workflow analytics filters">
            <form class="filters-form" method="GET" action="workflow_analytics.php">
                <?php if ($workflowId): ?>
                    <input type="hidden" name="workflow_id" value="<?php echo (int) $workflowId; ?>">
                <?php endif; ?>
                <div class="filter-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="filter-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="filter-actions">
                    <button class="btn-premium-primary" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>Apply</button>
                </div>
            </form>
        </section>

        <?php if ($workflowId && $workflow): ?>
            <section class="content-card" aria-labelledby="selected-workflow-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="selected-workflow-title"><?php echo htmlspecialchars($workflow['name']); ?></h2>
                        <p>Workflow performance for <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?>.</p>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Executions</div>
                        <div class="stat-value"><?php echo number_format((int) ($analytics['stats']['total_executions'] ?? 0)); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Success Rate</div>
                        <div class="stat-value"><?php echo number_format((float) ($analytics['stats']['success_rate'] ?? 0), 1); ?>%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Failed Executions</div>
                        <div class="stat-value"><?php echo number_format((int) ($analytics['stats']['failed_executions'] ?? 0)); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Avg Execution Time</div>
                        <div class="stat-value"><?php echo number_format((float) ($analytics['stats']['avg_execution_time_ms'] ?? 0), 0); ?>ms</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Avg Queue Lag</div>
                        <div class="stat-value"><?php echo number_format((float) ($analytics['stats']['avg_queue_latency_ms'] ?? 0), 0); ?>ms</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Retries</div>
                        <div class="stat-value"><?php echo number_format((int) ($analytics['stats']['retry_count'] ?? 0)); ?></div>
                    </div>
                </div>

                <?php if (!empty($analytics['top_errors'])): ?>
                    <section class="content-card" aria-labelledby="top-errors-title">
                        <h3 class="premium-card-title" id="top-errors-title">Top Errors</h3>
                        <div class="premium-list-card">
                            <?php foreach ($analytics['top_errors'] as $error): ?>
                                <div class="premium-rule-card">
                                    <strong><?php echo htmlspecialchars((string) $error['error_message']); ?></strong>
                                    <div class="premium-inline-note">Occurred <?php echo number_format((int) $error['count']); ?> time(s)</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($analytics['node_hotspots'])): ?>
                    <section class="content-card" aria-labelledby="node-hotspots-title">
                        <h3 class="premium-card-title" id="node-hotspots-title">Node Failure Hotspots</h3>
                        <div class="premium-detail-list">
                            <?php foreach ($analytics['node_hotspots'] as $node): ?>
                                <div class="premium-detail-row">
                                    <strong><?php echo htmlspecialchars((string) ($node['node_label'] ?: $node['node_type'])); ?></strong>
                                    <span><?php echo number_format((int) $node['run_count']); ?> runs / <?php echo number_format((int) $node['failed_count']); ?> failed / <?php echo number_format((float) $node['avg_duration_ms'], 0); ?>ms avg</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <?php if (empty($analytics)): ?>
                <section class="content-card">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
                        <p>No workflows found.</p>
                        <a class="btn-premium-primary" href="workflow_create.php"><i class="fas fa-plus" aria-hidden="true"></i>Create Workflow</a>
                    </div>
                </section>
            <?php else: ?>
                <div class="premium-card-grid">
                    <?php foreach ($analytics as $workflowAnalytics): ?>
                        <?php
                        $wfName = Database::queryOne("SELECT name FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowAnalytics['workflow_id']]);
                        $workflowLabel = (string) ($wfName['name'] ?? 'Workflow #' . $workflowAnalytics['workflow_id']);
                        ?>
                        <section class="content-card" aria-label="<?php echo htmlspecialchars($workflowLabel); ?>">
                            <div class="premium-section-header">
                                <div>
                                    <h2>
                                        <a class="premium-muted-link" href="?workflow_id=<?php echo (int) $workflowAnalytics['workflow_id']; ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>">
                                            <?php echo htmlspecialchars($workflowLabel); ?>
                                        </a>
                                    </h2>
                                </div>
                            </div>
                            <div class="premium-mini-metrics">
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Executions</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($workflowAnalytics['stats']['total_executions'] ?? 0)); ?></div>
                                </div>
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Success Rate</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((float) ($workflowAnalytics['stats']['success_rate'] ?? 0), 1); ?>%</div>
                                </div>
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Failed</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($workflowAnalytics['stats']['failed_executions'] ?? 0)); ?></div>
                                </div>
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Avg Time</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((float) ($workflowAnalytics['stats']['avg_execution_time_ms'] ?? 0), 0); ?>ms</div>
                                </div>
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Queue Lag</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((float) ($workflowAnalytics['stats']['avg_queue_latency_ms'] ?? 0), 0); ?>ms</div>
                                </div>
                                <div class="premium-mini-metric">
                                    <div class="premium-mini-metric-label">Retries</div>
                                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($workflowAnalytics['stats']['retry_count'] ?? 0)); ?></div>
                                </div>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_WORKFLOW_ANALYTICS, 'How to use Workflow Analytics', $workflowAnalyticsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
