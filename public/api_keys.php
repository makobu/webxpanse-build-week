<?php
/**
 * API Keys Management Page
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
use CRM\Modules\ApiKeys;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.api_keys');

$apiKeysModule = new ApiKeys();

$error = null;
$success = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $apiKeysModule->delete((int) $_POST['api_key_id']);
            $success = 'API key deleted successfully.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $key = $apiKeysModule->getById((int) $_POST['api_key_id']);
            if ($key) {
                $apiKeysModule->update((int) $_POST['api_key_id'], [
                    'is_active' => !$key['is_active']
                ]);
                $success = 'API key status updated.';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get all API keys
$apiKeys = $apiKeysModule->getUserKeys();

$pageTitle = 'API Keys';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
        <div class="admin-hero">
            <div>
                <h1>API Keys</h1>
                <p>Manage API keys for programmatic access to your growth system</p>
            </div>
            <div class="admin-hero-actions">
                <a href="api_key_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New API Key
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="premium-banner premium-banner-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="premium-banner premium-banner-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($apiKeys)): ?>
            <div class="empty-state">
                <p>No API keys configured.</p>
                <a href="api_key_create.php" class="btn-premium-primary">
                    <i class="fas fa-key"></i>
                    Create Your First API Key
                </a>
            </div>
        <?php else: ?>
            <div class="admin-resource-grid">
                <?php foreach ($apiKeys as $key): ?>
                    <div class="admin-resource-card<?php echo !$key['is_active'] ? ' is-muted' : ''; ?>">
                        <div class="admin-resource-header">
                            <div class="admin-resource-title">
                                <div class="premium-inline-actions">
                                    <h2><?php echo htmlspecialchars($key['name']); ?></h2>
                                    <?php if ($key['is_active']): ?>
                                        <span class="admin-status-badge admin-status-badge--success">Active</span>
                                    <?php else: ?>
                                        <span class="admin-status-badge admin-status-badge--neutral">Inactive</span>
                                    <?php endif; ?>
                                    <?php if (!empty($key['expires_at']) && strtotime($key['expires_at']) < time()): ?>
                                        <span class="admin-status-badge admin-status-badge--danger">Expired</span>
                                    <?php endif; ?>
                                </div>
                                <p><span class="admin-code-inline"><?php echo htmlspecialchars($key['key_prefix']); ?>...</span></p>
                            </div>
                            <div class="admin-resource-actions">
                                <a href="api_key_logs.php?id=<?php echo $key['id']; ?>" class="btn-premium-secondary btn-premium-sm">
                                    <i class="fas fa-list"></i>
                                    Logs
                                </a>
                                <a href="api_key_edit.php?id=<?php echo $key['id']; ?>" class="btn-premium-secondary btn-premium-sm">
                                    <i class="fas fa-pen"></i>
                                    Edit
                                </a>
                                <form method="POST" class="admin-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="api_key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" name="toggle_active" class="<?php echo $key['is_active'] ? 'btn-premium-warning' : 'btn-premium-success'; ?> btn-premium-sm">
                                        <?php echo $key['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="POST" class="admin-inline-form" onsubmit="return confirm('Are you sure you want to delete this API key? This action cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="api_key_id" value="<?php echo $key['id']; ?>">
                                    <button type="submit" name="delete" class="btn-premium-danger btn-premium-sm">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="admin-meta-grid">
                            <div class="admin-meta-item">
                                <div class="admin-meta-label">Created</div>
                                <div class="admin-meta-value"><?php echo date('M j, Y g:i A', strtotime($key['created_at'])); ?></div>
                            </div>
                            <div class="admin-meta-item">
                                <div class="admin-meta-label">Expires</div>
                                <div class="admin-meta-value"><?php echo $key['expires_at'] ? date('M j, Y g:i A', strtotime($key['expires_at'])) : 'No expiration'; ?></div>
                            </div>
                            <div class="admin-meta-item">
                                <div class="admin-meta-label">Last Used</div>
                                <div class="admin-meta-value"><?php echo $key['last_used_at'] ? date('M j, Y g:i A', strtotime($key['last_used_at'])) : 'Never'; ?></div>
                            </div>
                        </div>
                        <?php if (!empty($key['usage_stats']) && $key['usage_stats']['total_requests'] > 0): ?>
                            <div class="admin-stat-list">
                                <span><strong><?php echo number_format($key['usage_stats']['total_requests']); ?></strong> total requests</span>
                                <span><strong><?php echo number_format($key['usage_stats']['successful_requests']); ?></strong> successful</span>
                                <span><strong><?php echo number_format($key['usage_stats']['failed_requests']); ?></strong> failed</span>
                                <?php if ($key['usage_stats']['avg_response_time_ms'] > 0): ?>
                                    <span><strong><?php echo number_format($key['usage_stats']['avg_response_time_ms'], 0); ?>ms</strong> avg response</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
