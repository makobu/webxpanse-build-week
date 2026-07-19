<?php
/**
 * Error Logs Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\ErrorTracker;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.monitoring');

$errorTracker = new ErrorTracker();

// Get filter parameters
$filters = [
    'resolved' => isset($_GET['resolved']) ? (bool) $_GET['resolved'] : null,
    'error_type' => $_GET['error_type'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null
];

// Remove null filters
$filters = array_filter($filters, fn($v) => $v !== null);

$limit = 50;
$offset = (int) ($_GET['offset'] ?? 0);

// Handle resolve action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $errorId = (int) ($_POST['error_id'] ?? 0);
            $errorTracker->resolveError($errorId, \CRM\Auth::userId());
            $success = 'Error marked as resolved.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get errors
$errors = $errorTracker->getErrors($filters, $limit, $offset);
$errorStats = $errorTracker->getErrorStats(7);

$pageTitle = 'Error Logs';
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">Runtime monitoring</div>
                <h1>Error Logs</h1>
                <p>Review captured application errors and mark operational issues as resolved.</p>
            </div>
            <div class="ops-hero-actions">
                <a href="monitoring.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Back to Monitoring</a>
            </div>
        </section>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="premium-banner premium-banner-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Errors (7d)</div>
                <div class="stat-value"><?php echo $errorStats['total_errors']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unresolved</div>
                <div class="stat-value ops-stat-value--danger"><?php echo $errorStats['unresolved_errors']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today</div>
                <div class="stat-value"><?php echo $errorStats['errors_today']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">This Week</div>
                <div class="stat-value"><?php echo $errorStats['errors_this_week']; ?></div>
            </div>
        </section>

        <section class="ops-filter-card">
            <form method="GET" class="ops-filter-grid">
                <div class="ops-field">
                    <label for="resolved" class="ops-field-label">Status</label>
                    <select id="resolved" name="resolved">
                        <option value="">All</option>
                        <option value="0" <?php echo (isset($_GET['resolved']) && $_GET['resolved'] === '0') ? 'selected' : ''; ?>>Unresolved</option>
                        <option value="1" <?php echo (isset($_GET['resolved']) && $_GET['resolved'] === '1') ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </div>
                <div class="ops-field">
                    <label for="error_type" class="ops-field-label">Error Type</label>
                    <input type="text" id="error_type" name="error_type" value="<?php echo htmlspecialchars($_GET['error_type'] ?? ''); ?>" placeholder="e.g., Exception">
                </div>
                <div class="ops-field">
                    <label for="date_from" class="ops-field-label">From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div class="ops-field">
                    <label for="date_to" class="ops-field-label">To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div class="ops-action-row">
                    <button type="submit" class="btn-premium-primary"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>
        </section>

        <?php if (empty($errors)): ?>
            <div class="empty-state content-card">
                <p>No errors found.</p>
            </div>
        <?php else: ?>
            <section class="ops-log-list">
                <?php foreach ($errors as $error): ?>
                    <article class="ops-log-card <?php echo $error['resolved'] ? 'ops-log-card--success' : 'ops-log-card--danger'; ?>">
                        <div class="ops-card-header">
                            <div>
                                <h2 class="ops-log-title"><?php echo htmlspecialchars(substr($error['message'], 0, 100)); ?></h2>
                                <div class="ops-meta-grid">
                                    <span><strong>Type:</strong> <?php echo htmlspecialchars($error['error_type'] ?? 'Unknown'); ?></span>
                                    <span><strong>File:</strong> <?php echo htmlspecialchars(basename($error['file'] ?? 'Unknown')); ?>:<?php echo $error['line'] ?? '?'; ?></span>
                                    <span><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($error['created_at'])); ?></span>
                                    <?php if ($error['user_email']): ?>
                                        <span><strong>User:</strong> <?php echo htmlspecialchars($error['user_email']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ops-action-row">
                                <?php if ($error['resolved']): ?>
                                    <span class="ops-status-badge ops-status-badge--success">Resolved</span>
                                <?php else: ?>
                                    <span class="ops-status-badge ops-status-badge--danger">Unresolved</span>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="error_id" value="<?php echo $error['id']; ?>">
                                        <button type="submit" name="resolve" class="btn-premium-success btn-premium-sm">Mark Resolved</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($error['url']): ?>
                            <div class="ops-muted">
                                <strong>URL:</strong> <?php echo htmlspecialchars($error['url']); ?> (<?php echo htmlspecialchars($error['method'] ?? 'GET'); ?>)
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error['context'])): ?>
                            <details class="ops-details">
                                <summary>View Context</summary>
                                <pre class="ops-code-block"><?php echo htmlspecialchars(json_encode($error['context'], JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>

                        <?php if ($error['trace']): ?>
                            <details class="ops-details">
                                <summary>View Stack Trace</summary>
                                <pre class="ops-code-block ops-code-block--scroll"><?php echo htmlspecialchars($error['trace']); ?></pre>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
