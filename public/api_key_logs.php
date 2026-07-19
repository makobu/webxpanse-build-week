<?php
/**
 * API Key Logs Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\ApiKeys;
use CRM\Auth;
use CRM\Authorization;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.api_keys');

$apiKeysModule = new ApiKeys();

$apiKeyId = (int) ($_GET['id'] ?? 0);
if (!$apiKeyId) {
    header('Location: api_keys.php');
    exit;
}

$apiKey = $apiKeysModule->getById($apiKeyId);
if (!$apiKey) {
    header('Location: api_keys.php?error=not_found');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$logs = $apiKeysModule->getUsageLogs($apiKeyId, $limit, $offset);
$stats = $apiKey['usage_stats'] ?? [];

$pageTitle = 'API Key Logs';
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">API usage</div>
                <h1>API Key Usage Logs</h1>
                <p>
                    <strong><?php echo htmlspecialchars($apiKey['name']); ?></strong>
                    <span class="ops-muted"> - <?php echo htmlspecialchars($apiKey['key_prefix']); ?>...</span>
                </p>
            </div>
            <div class="ops-hero-actions">
                <a href="api_keys.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Back to API Keys</a>
            </div>
        </section>

        <?php if (!empty($stats) && $stats['total_requests'] > 0): ?>
            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Requests</div>
                    <div class="stat-value"><?php echo number_format($stats['total_requests']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Successful</div>
                    <div class="stat-value ops-stat-value--success"><?php echo number_format($stats['successful_requests']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Failed</div>
                    <div class="stat-value ops-stat-value--danger"><?php echo number_format($stats['failed_requests']); ?></div>
                </div>
                <?php if ($stats['avg_response_time_ms'] > 0): ?>
                    <div class="stat-card">
                        <div class="stat-label">Avg Response Time</div>
                        <div class="stat-value"><?php echo number_format($stats['avg_response_time_ms'], 0); ?>ms</div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="table-card">
            <div class="premium-section-header">
                <h2 class="ops-table-title">Request Timeline</h2>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <p>No API usage logs found yet.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table ops-timeline-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Endpoint</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Response Time</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $status = (int) ($log['response_status'] ?? 0);
                                $statusClass = $status >= 200 && $status < 300
                                    ? 'ops-status-badge--success'
                                    : ($status >= 400 ? 'ops-status-badge--danger' : ($status > 0 ? 'ops-status-badge--warning' : 'ops-status-badge--pending'));
                                $statusLabel = $status > 0
                                    ? ($status . ($status >= 200 && $status < 300 ? ' OK' : ($status >= 400 ? ' Error' : '')))
                                    : '-';
                                ?>
                                <tr>
                                    <td><?php echo date('M j, Y g:i:s A', strtotime($log['created_at'])); ?></td>
                                    <td><code class="ops-code-inline"><?php echo htmlspecialchars($log['endpoint']); ?></code></td>
                                    <td><span class="ops-status-badge ops-status-badge--neutral"><?php echo htmlspecialchars($log['method']); ?></span></td>
                                    <td><span class="ops-status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                    <td><?php echo $log['response_time_ms'] ? number_format($log['response_time_ms']) . 'ms' : '-'; ?></td>
                                    <td class="ops-mono"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
