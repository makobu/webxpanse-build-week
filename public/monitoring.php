<?php
/**
 * Monitoring Dashboard
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
use CRM\Modules\SystemMonitor;
use CRM\Modules\PerformanceMonitor;
use CRM\Modules\ErrorTracker;
use CRM\Modules\AuditLog;
use CRM\Logger;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
if (!Authorization::can('settings.monitoring', Auth::user())) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Format bytes to human-readable format
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        if ($bytes == 0 || $bytes === null) {
            return '0 B';
        }
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

$systemMonitor = new SystemMonitor();
$performanceMonitor = new PerformanceMonitor();
$errorTracker = new ErrorTracker();
$auditLog = new AuditLog();
$logger = new Logger();

// Get monitoring data
$healthStatus = $systemMonitor->getHealthStatus();
$queryStats = $performanceMonitor->getQueryStats(24);
$apiStats = $performanceMonitor->getAPIStats(24);
$cacheStats = $performanceMonitor->getCacheStats();
$errorStats = $errorTracker->getErrorStats(7);
$logStats = $logger->getStats(7);

// User activity metrics
$activeUsers24h = $auditLog->getActiveUsersCount(24);
$topPages = $auditLog->getTopPages(24, 10);
$apiUsageByUser = Database::query(
    "SELECT u.email, COUNT(*) as api_calls
     FROM api_key_logs akl
     JOIN api_keys ak ON akl.api_key_id = ak.id
     JOIN users u ON ak.user_id = u.id
     WHERE akl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     GROUP BY u.id, u.email
     ORDER BY api_calls DESC
     LIMIT 10"
);

$statusClass = static function (string $status): string {
    return match ($status) {
        'healthy' => 'is-success',
        'warning' => 'is-warning',
        default => 'is-danger',
    };
};

$pageTitle = 'System Monitoring';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="system-monitoring-title">
            <div>
                <h1 id="system-monitoring-title">System Monitoring</h1>
                <p>Operational health, performance, activity, and error signals.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-success" href="email_import_audit.php"><i class="fas fa-envelope-open-text" aria-hidden="true"></i>Email Import Audit</a>
                <a class="btn-premium-primary" href="error_logs.php"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>Error Logs</a>
            </div>
        </section>

        <section class="content-card" aria-labelledby="system-health-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="system-health-title">System Health</h2>
                    <p>Current availability and queue status.</p>
                </div>
                <span class="premium-status-badge <?php echo $statusClass((string) ($healthStatus['status'] ?? 'error')); ?>">
                    <?php echo htmlspecialchars(strtoupper((string) ($healthStatus['status'] ?? 'unknown'))); ?>
                </span>
            </div>
            <div class="premium-mini-metrics">
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Database</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((float) ($healthStatus['database']['response_time_ms'] ?? 0), 2); ?>ms</div>
                    <span class="premium-status-badge <?php echo $statusClass((string) ($healthStatus['database']['status'] ?? 'error')); ?>"><?php echo htmlspecialchars((string) ($healthStatus['database']['status'] ?? 'unknown')); ?></span>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Queue</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($healthStatus['queue']['pending'] ?? 0)); ?></div>
                    <span class="premium-status-badge <?php echo $statusClass((string) ($healthStatus['queue']['status'] ?? 'error')); ?>">pending</span>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Errors Today</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($errorStats['errors_today'] ?? 0)); ?></div>
                    <span class="premium-status-badge <?php echo ((int) ($errorStats['errors_today'] ?? 0)) > 0 ? 'is-danger' : 'is-success'; ?>"><?php echo ((int) ($errorStats['errors_today'] ?? 0)) > 0 ? 'attention' : 'clear'; ?></span>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Active Users</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) $activeUsers24h); ?></div>
                    <span class="premium-inline-note">last 24h</span>
                </div>
            </div>
        </section>

        <div class="premium-dashboard-grid">
            <section class="content-card" aria-labelledby="query-performance-title">
                <h2 class="premium-card-title" id="query-performance-title">Query Performance (24h)</h2>
                <div class="premium-mini-metrics">
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Total Queries</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((int) ($queryStats['total'] ?? 0)); ?></div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Avg Time</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((float) ($queryStats['avg_time'] ?? 0) * 1000, 2); ?>ms</div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Max Time</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((float) ($queryStats['max_time'] ?? 0) * 1000, 2); ?>ms</div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Slow Queries</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((int) ($queryStats['slow_queries'] ?? 0)); ?></div>
                    </div>
                </div>
            </section>

            <section class="content-card" aria-labelledby="api-performance-title">
                <h2 class="premium-card-title" id="api-performance-title">API Performance (24h)</h2>
                <div class="premium-mini-metrics">
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Total Requests</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((int) ($apiStats['total'] ?? 0)); ?></div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Avg Response</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((float) ($apiStats['avg_time'] ?? 0) * 1000, 2); ?>ms</div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Max Response</div>
                        <div class="premium-mini-metric-value"><?php echo number_format((float) ($apiStats['max_time'] ?? 0) * 1000, 2); ?>ms</div>
                    </div>
                    <div class="premium-mini-metric">
                        <div class="premium-mini-metric-label">Status Codes</div>
                        <div class="premium-inline-actions">
                            <?php foreach (array_slice((array) ($apiStats['by_status'] ?? []), 0, 3, true) as $status => $count): ?>
                                <span class="premium-status-badge is-info"><?php echo htmlspecialchars((string) $status); ?>: <?php echo number_format((int) $count); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="premium-dashboard-grid premium-dashboard-grid--thirds">
            <section class="content-card" aria-labelledby="cache-performance-title">
                <h2 class="premium-card-title" id="cache-performance-title">Cache Performance</h2>
                <div class="premium-detail-list">
                    <div class="premium-detail-row"><span>Hit Rate</span><strong><?php echo number_format((float) ($cacheStats['hit_rate'] ?? 0), 1); ?>%</strong></div>
                    <div class="premium-detail-row"><span>Hits / Misses</span><strong><?php echo number_format((int) ($cacheStats['hits'] ?? 0)); ?> / <?php echo number_format((int) ($cacheStats['misses'] ?? 0)); ?></strong></div>
                </div>
            </section>

            <section class="content-card" aria-labelledby="disk-usage-title">
                <?php $diskPercent = max(0, min(100, (float) ($healthStatus['disk']['percent'] ?? 0))); ?>
                <h2 class="premium-card-title" id="disk-usage-title">Disk Usage</h2>
                <div class="premium-detail-list">
                    <div class="premium-detail-row"><span>Used</span><strong><?php echo number_format($diskPercent, 1); ?>%</strong></div>
                    <div class="premium-progress premium-progress--tall" style="--premium-progress-value: <?php echo $diskPercent; ?>%;">
                        <div class="premium-progress-fill <?php echo $statusClass((string) ($healthStatus['disk']['status'] ?? 'error')); ?>"></div>
                    </div>
                    <div class="premium-inline-note"><?php echo formatBytes($healthStatus['disk']['used'] ?? 0); ?> / <?php echo formatBytes($healthStatus['disk']['total'] ?? 0); ?></div>
                </div>
            </section>

            <section class="content-card" aria-labelledby="memory-usage-title">
                <?php $memoryPercent = max(0, min(100, (float) ($healthStatus['memory']['percent'] ?? 0))); ?>
                <h2 class="premium-card-title" id="memory-usage-title">Memory Usage</h2>
                <div class="premium-detail-list">
                    <div class="premium-detail-row"><span>Used</span><strong><?php echo number_format($memoryPercent, 1); ?>%</strong></div>
                    <div class="premium-progress premium-progress--tall" style="--premium-progress-value: <?php echo $memoryPercent; ?>%;">
                        <div class="premium-progress-fill <?php echo $statusClass((string) ($healthStatus['memory']['status'] ?? 'error')); ?>"></div>
                    </div>
                    <div class="premium-inline-note"><?php echo formatBytes($healthStatus['memory']['used'] ?? 0); ?> / <?php echo formatBytes($healthStatus['memory']['limit'] ?? 0); ?></div>
                </div>
            </section>
        </div>

        <div class="premium-dashboard-grid">
            <section class="content-card" aria-labelledby="user-activity-title">
                <h2 class="premium-card-title" id="user-activity-title">User Activity (24h)</h2>
                <div class="premium-detail-list">
                    <div class="premium-detail-row"><span>Active Users</span><strong><?php echo number_format((int) $activeUsers24h); ?></strong></div>
                    <div>
                        <h3 class="premium-card-title">Top Pages</h3>
                        <?php if (empty($topPages)): ?>
                            <p class="premium-empty-compact">No page view data yet.</p>
                        <?php else: ?>
                            <div class="premium-detail-list">
                                <?php foreach ($topPages as $row): ?>
                                    <div class="premium-detail-row">
                                        <span><?php echo htmlspecialchars((string) $row['page']); ?></span>
                                        <strong><?php echo number_format((int) $row['views']); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="content-card" aria-labelledby="api-usage-title">
                <h2 class="premium-card-title" id="api-usage-title">API Usage by User</h2>
                <?php if (empty($apiUsageByUser)): ?>
                    <p class="premium-empty-compact">No API calls in the last 24 hours.</p>
                <?php else: ?>
                    <div class="premium-detail-list">
                        <?php foreach ($apiUsageByUser as $row): ?>
                            <div class="premium-detail-row">
                                <span><?php echo htmlspecialchars((string) $row['email']); ?></span>
                                <strong><?php echo number_format((int) $row['api_calls']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <section class="content-card" aria-labelledby="error-statistics-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="error-statistics-title">Error Statistics (7 days)</h2>
                    <p>Application errors and unresolved issue pressure.</p>
                </div>
            </div>
            <div class="premium-mini-metrics">
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Total Errors</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($errorStats['total_errors'] ?? 0)); ?></div>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Unresolved</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($errorStats['unresolved_errors'] ?? 0)); ?></div>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">Today</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($errorStats['errors_today'] ?? 0)); ?></div>
                </div>
                <div class="premium-mini-metric">
                    <div class="premium-mini-metric-label">This Week</div>
                    <div class="premium-mini-metric-value"><?php echo number_format((int) ($errorStats['errors_this_week'] ?? 0)); ?></div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
