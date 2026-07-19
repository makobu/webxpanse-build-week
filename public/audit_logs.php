<?php
/**
 * Audit Logs Viewing Page
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
use CRM\Modules\AuditLog;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require admin role
$currentUser = Auth::user();
if (!Authorization::can('admin.audit_logs.view', $currentUser)) {
    header('Location: dashboard.php');
    exit;
}

$auditLogModule = new AuditLog();

// Get filters from query parameters
$filters = [
    'user_id' => $_GET['user_id'] ?? null,
    'action' => $_GET['action'] ?? null,
    'entity_type' => $_GET['entity_type'] ?? null,
    'entity_id' => $_GET['entity_id'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null
];

// Remove empty filters
$filters = array_filter($filters, function($value) {
    return $value !== null && $value !== '';
});

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Get logs
$logs = $auditLogModule->getLogs($filters, $limit, $offset);
$totalLogs = $auditLogModule->getCount($filters);
$totalPages = ceil($totalLogs / $limit);

// Get statistics
$actionStats = $auditLogModule->getActionStats(30);
$entityTypeStats = $auditLogModule->getEntityTypeStats(30);

// Get all users for filter dropdown
$allUsers = Database::query("SELECT id, email, role FROM users ORDER BY email ASC");

// Get unique actions for filter dropdown
$allActions = Database::query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");

// Get unique entity types for filter dropdown
$allEntityTypes = Database::query("SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type ASC");

$pageTitle = 'Audit Logs - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">Audit trail</div>
                <h1>Audit Logs</h1>
                <p>Track user actions and system changes across the workspace.</p>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Logs</div>
                <div class="stat-value"><?php echo number_format($totalLogs); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Action Types</div>
                <div class="stat-value"><?php echo count($actionStats); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Entity Types</div>
                <div class="stat-value"><?php echo count($entityTypeStats); ?></div>
            </div>
        </section>

        <section class="ops-filter-card">
            <form method="GET" action="" class="ops-filter-grid">
                <div class="ops-field">
                    <label class="ops-field-label">User</label>
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo ($filters['user_id'] ?? '') == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['email']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ops-field">
                    <label class="ops-field-label">Action</label>
                    <select name="action">
                        <option value="">All Actions</option>
                        <?php foreach ($allActions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo ($filters['action'] ?? '') == $action['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($auditLogModule->formatAction($action['action'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ops-field">
                    <label class="ops-field-label">Entity Type</label>
                    <select name="entity_type">
                        <option value="">All Types</option>
                        <?php foreach ($allEntityTypes as $entityType): ?>
                            <option value="<?php echo htmlspecialchars($entityType['entity_type']); ?>" <?php echo ($filters['entity_type'] ?? '') == $entityType['entity_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($auditLogModule->formatEntityType($entityType['entity_type'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ops-field">
                    <label class="ops-field-label">Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                </div>

                <div class="ops-field">
                    <label class="ops-field-label">Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                </div>

                <div class="ops-action-row">
                    <button type="submit" class="btn-premium-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="audit_logs.php" class="btn-premium-secondary">Clear</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="premium-section-header">
                <h2 class="ops-table-title">Recent Activity</h2>
                <span class="ops-muted"><?php echo number_format($totalLogs); ?> total</span>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <p>No audit logs found matching your filters.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table ops-timeline-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php $entityUrl = $auditLogModule->getEntityUrl($log['entity_type'], $log['entity_id']); ?>
                                <tr>
                                    <td><?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <div class="ops-table-cell-main"><?php echo htmlspecialchars($log['user_email'] ?? 'System'); ?></div>
                                        <?php if ($log['user_role']): ?>
                                            <div class="ops-table-cell-sub"><?php echo htmlspecialchars($log['user_role']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ops-status-badge ops-status-badge--neutral">
                                            <?php echo htmlspecialchars($auditLogModule->formatAction($log['action'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['entity_type']): ?>
                                            <?php if ($entityUrl): ?>
                                                <a href="<?php echo $entityUrl; ?>" class="ops-link">
                                                    <?php echo htmlspecialchars($auditLogModule->formatEntityType($log['entity_type'])); ?>
                                                    <?php if ($log['entity_id']): ?> #<?php echo $log['entity_id']; ?><?php endif; ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="ops-table-cell-main">
                                                    <?php echo htmlspecialchars($auditLogModule->formatEntityType($log['entity_type'])); ?>
                                                    <?php if ($log['entity_id']): ?> #<?php echo $log['entity_id']; ?><?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ops-mono"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($log['old_values'] || $log['new_values']): ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $log['id']; ?>)" class="btn-premium-secondary btn-premium-sm">
                                                View Changes
                                            </button>
                                            <div id="details-<?php echo $log['id']; ?>" class="ops-detail-panel" style="display: none;">
                                                <?php if ($log['old_values']): ?>
                                                    <div class="ops-details">
                                                        <strong>Old Values:</strong>
                                                        <pre class="ops-code-block"><?php echo htmlspecialchars(json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT)); ?></pre>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($log['new_values']): ?>
                                                    <div class="ops-details">
                                                        <strong>New Values:</strong>
                                                        <pre class="ops-code-block"><?php echo htmlspecialchars(json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT)); ?></pre>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="ops-pagination">
                        <div class="ops-muted">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalLogs); ?> total)
                        </div>
                        <div class="ops-pagination-links">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="ops-pagination-link">Previous</a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="ops-pagination-link">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
function toggleDetails(logId) {
    const detailsDiv = document.getElementById('details-' + logId);
    if (detailsDiv.style.display === 'none') {
        detailsDiv.style.display = 'block';
    } else {
        detailsDiv.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
