<?php
/**
 * Edit API Key Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\ApiKeys;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.api_keys');

$apiKeysModule = new ApiKeys();

$error = null;
$apiKey = null;

$apiKeyId = (int) ($_GET['id'] ?? 0);
if ($apiKeyId) {
    $apiKey = $apiKeysModule->getById($apiKeyId);
    if (!$apiKey) {
        header('Location: api_keys.php?error=not_found');
        exit;
    }
} else {
    header('Location: api_keys.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $name = trim($_POST['name'] ?? '');
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $rateLimit = !empty($_POST['rate_limit_per_minute']) ? (int) $_POST['rate_limit_per_minute'] : 60;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $permissions = [];

            // Parse permissions
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                $permissions = $_POST['permissions'];
            }

            if (empty($name)) {
                $error = 'API key name is required.';
            } else {
                $apiKeysModule->update($apiKeyId, [
                    'name' => $name,
                    'expires_at' => $expiresAt,
                    'rate_limit_per_minute' => $rateLimit,
                    'is_active' => $isActive,
                    'permissions' => $permissions
                ]);

                header('Location: api_keys.php?success=updated');
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit API Key';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Edit API Key</h1>
                    <p>Update API key configuration</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="api_keys.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to API Keys
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="premium-banner premium-banner-warning">
                The full API key cannot be displayed for security reasons. Only the key prefix is shown:
                <span class="admin-code-inline"><?php echo htmlspecialchars($apiKey['key_prefix']); ?>...</span>
            </div>

            <form method="POST" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Identity</h2>
                        <p>Keep the key name clear enough for later audits.</p>
                    </div>
                    <div class="form-group">
                        <label for="name">API Key Name *</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            autofocus
                            value="<?php echo htmlspecialchars($_POST['name'] ?? $apiKey['name']); ?>"
                        >
                    </div>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Limits</h2>
                        <p>Adjust expiry and request throttling.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="expires_at">Expiration Date (Optional)</label>
                            <input
                                type="datetime-local"
                                id="expires_at"
                                name="expires_at"
                                value="<?php echo htmlspecialchars($_POST['expires_at'] ?? ($apiKey['expires_at'] ? date('Y-m-d\TH:i', strtotime($apiKey['expires_at'])) : '')); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="rate_limit_per_minute">Rate Limit (requests per minute)</label>
                            <input
                                type="number"
                                id="rate_limit_per_minute"
                                name="rate_limit_per_minute"
                                min="1"
                                max="10000"
                                value="<?php echo htmlspecialchars($_POST['rate_limit_per_minute'] ?? $apiKey['rate_limit_per_minute']); ?>"
                            >
                        </div>
                    </div>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Permissions</h2>
                        <p>Restrict this key to specific API endpoints, or leave all unchecked for full access.</p>
                    </div>
                    <div class="admin-checkbox-grid">
                        <?php
                        $currentPermissions = $apiKey['permissions'] ?? [];
                        $allPermissions = [
                            'contacts.read' => 'Read Contacts',
                            'contacts.write' => 'Write Contacts',
                            'deals.read' => 'Read Deals',
                            'deals.write' => 'Write Deals',
                            'tasks.read' => 'Read Tasks',
                            'tasks.write' => 'Write Tasks',
                            'workflows.trigger' => 'Trigger Workflows',
                        ];
                        foreach ($allPermissions as $perm => $label):
                        ?>
                            <label class="admin-checkbox-card">
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value="<?php echo htmlspecialchars($perm); ?>"
                                    <?php echo in_array($perm, $currentPermissions) ? 'checked' : ''; ?>
                                >
                                <strong><?php echo htmlspecialchars($label); ?></strong>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-form-section">
                    <label class="admin-toggle-row">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?php echo (isset($_POST['is_active']) ? $_POST['is_active'] : $apiKey['is_active']) ? 'checked' : ''; ?>
                        >
                        <strong>Activate API key</strong>
                    </label>
                </div>

                <div class="form-actions">
                    <a href="api_keys.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Update API Key</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
