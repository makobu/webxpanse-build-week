<?php
/**
 * Webhooks Management Page
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
use CRM\Modules\Webhooks;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.webhooks');

$webhooksModule = new Webhooks();

$error = null;
$success = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $webhooksModule->delete((int) $_POST['webhook_id']);
            $success = 'Webhook deleted successfully.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $webhook = $webhooksModule->getById((int) $_POST['webhook_id']);
            if ($webhook) {
                $webhooksModule->update((int) $_POST['webhook_id'], [
                    'is_active' => !$webhook['is_active']
                ]);
                $success = 'Webhook status updated.';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get all webhooks
$webhooks = $webhooksModule->getUserWebhooks();

$pageTitle = 'Webhooks';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-workspace">
            <div class="admin-hero">
                <div>
                    <h1>Webhooks</h1>
                    <p>Manage webhooks for real-time event notifications. Outbound delivery and test sends run synchronously.</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="webhook_create.php" class="btn-premium-primary">
                        <i class="fas fa-plus"></i>
                        New Webhook
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

            <?php if (empty($webhooks)): ?>
                <div class="empty-state">
                    <p>No webhooks configured.</p>
                    <a href="webhook_create.php" class="btn-premium-primary">
                        <i class="fas fa-satellite-dish"></i>
                        Create Your First Webhook
                    </a>
                </div>
            <?php else: ?>
                <div class="admin-resource-grid">
                    <?php foreach ($webhooks as $webhook): ?>
                        <?php $successRate = $webhook['total_calls'] > 0 ? ($webhook['success_calls'] / $webhook['total_calls']) * 100 : 0; ?>
                        <div class="admin-resource-card<?php echo !$webhook['is_active'] ? ' is-muted' : ''; ?>">
                            <div class="admin-resource-header">
                                <div class="admin-resource-title">
                                    <div class="premium-inline-actions">
                                        <h2><?php echo htmlspecialchars($webhook['name']); ?></h2>
                                        <?php if ($webhook['is_active']): ?>
                                            <span class="admin-status-badge admin-status-badge--success">Active</span>
                                        <?php else: ?>
                                            <span class="admin-status-badge admin-status-badge--neutral">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    <p><?php echo htmlspecialchars($webhook['url']); ?></p>
                                </div>
                                <div class="admin-resource-actions">
                                    <a href="webhook_logs.php?id=<?php echo $webhook['id']; ?>" class="btn-premium-secondary btn-premium-sm">
                                        <i class="fas fa-list"></i>
                                        Logs
                                    </a>
                                    <button onclick="testWebhook(<?php echo $webhook['id']; ?>, this)" class="btn-premium-primary btn-premium-sm">
                                        <i class="fas fa-paper-plane"></i>
                                        Test
                                    </button>
                                    <a href="webhook_edit.php?id=<?php echo $webhook['id']; ?>" class="btn-premium-secondary btn-premium-sm">
                                        <i class="fas fa-pen"></i>
                                        Edit
                                    </a>
                                    <form method="POST" class="admin-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="webhook_id" value="<?php echo $webhook['id']; ?>">
                                        <button type="submit" name="toggle_active" class="<?php echo $webhook['is_active'] ? 'btn-premium-warning' : 'btn-premium-success'; ?> btn-premium-sm">
                                            <?php echo $webhook['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="admin-inline-form" onsubmit="return confirm('Are you sure you want to delete this webhook?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="webhook_id" value="<?php echo $webhook['id']; ?>">
                                        <button type="submit" name="delete" class="btn-premium-danger btn-premium-sm">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="admin-meta-grid">
                                <div class="admin-meta-item">
                                    <div class="admin-meta-label">Method</div>
                                    <div class="admin-meta-value"><?php echo htmlspecialchars($webhook['method']); ?></div>
                                </div>
                                <div class="admin-meta-item">
                                    <div class="admin-meta-label">Events</div>
                                    <div class="admin-meta-value"><?php echo count($webhook['events']); ?> configured</div>
                                </div>
                                <div class="admin-meta-item">
                                    <div class="admin-meta-label">Total Calls</div>
                                    <div class="admin-meta-value"><?php echo number_format((int) $webhook['total_calls']); ?></div>
                                </div>
                                <div class="admin-meta-item">
                                    <div class="admin-meta-label">Success Rate</div>
                                    <div class="admin-meta-value"><?php echo $webhook['total_calls'] > 0 ? number_format($successRate, 1) . '%' : 'No calls yet'; ?></div>
                                </div>
                            </div>

                            <?php if ($webhook['total_calls'] > 0): ?>
                                <div class="admin-stat-list">
                                    <span><strong><?php echo number_format($webhook['success_calls']); ?></strong> successful</span>
                                    <span><strong><?php echo number_format($webhook['failed_calls']); ?></strong> failed</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($webhook['events'])): ?>
                                <div class="admin-tag-row" aria-label="Subscribed events">
                                    <?php foreach ($webhook['events'] as $event): ?>
                                        <span class="admin-tag"><?php echo htmlspecialchars($event); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
async function testWebhook(webhookId, btn) {
    if (!confirm('Send a test webhook to verify it\'s working?')) {
        return;
    }

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing...';

    try {
        const response = await fetch('../api/webhook_test.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                webhook_id: webhookId,
                csrf_token: '<?php echo Security::getCsrfToken(); ?>'
            })
        });

        const result = await response.json();
        const statusLabel = result.response_status ? `HTTP ${result.response_status}` : 'no HTTP status';
        const logLabel = result.log_id ? ` Log #${result.log_id}.` : '';
        const preview = result.response_preview ? `\n\nResponse preview:\n${result.response_preview}` : '';

        if (result.success) {
            alert(`Webhook test delivered successfully (${statusLabel}).${logLabel}${preview}`);
            location.reload();
        } else {
            const reason = result.error_message || result.message || result.error || 'Target did not return a successful response.';
            alert(`Webhook test completed but failed (${statusLabel}).${logLabel}\n\n${reason}${preview}`);
            if (result.log_id) {
                location.reload();
            }
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
