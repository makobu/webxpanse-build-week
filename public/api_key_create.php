<?php
/**
 * Create API Key Page
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
$createdKey = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $name = trim($_POST['name'] ?? '');
            $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            $rateLimit = !empty($_POST['rate_limit_per_minute']) ? (int) $_POST['rate_limit_per_minute'] : 60;
            $permissions = [];

            // Parse permissions
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                $permissions = $_POST['permissions'];
            }

            if (empty($name)) {
                $error = 'API key name is required.';
            } else {
                $createdKey = $apiKeysModule->create([
                    'name' => $name,
                    'expires_at' => $expiresAt,
                    'rate_limit_per_minute' => $rateLimit,
                    'permissions' => $permissions
                ]);
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create API Key';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Create API Key</h1>
                    <p>Generate a new API key for programmatic access to your growth system</p>
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

            <?php if ($createdKey): ?>
                <div class="admin-danger-panel">
                    <div class="admin-form-section">
                        <div class="admin-form-section-header">
                            <h2>Important: Save Your API Key</h2>
                            <p>This is the only time you'll be able to see the full API key. Copy it now and store it securely.</p>
                        </div>
                        <div id="created-api-key" class="admin-code-block"><?php echo htmlspecialchars($createdKey['api_key']); ?></div>
                        <div class="admin-form-actions">
                            <button type="button" onclick="copyApiKey()" class="btn-premium-primary">
                                <i class="fas fa-copy"></i>
                                Copy API Key
                            </button>
                            <a href="api_keys.php" class="btn-premium-secondary">Back to API Keys</a>
                        </div>
                    </div>
                    <div class="admin-meta-grid">
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Key Name</div>
                            <div class="admin-meta-value"><?php echo htmlspecialchars($createdKey['name']); ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Key Prefix</div>
                            <div class="admin-meta-value"><span class="admin-code-inline"><?php echo htmlspecialchars($createdKey['key_prefix']); ?>...</span></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Expires</div>
                            <div class="admin-meta-value"><?php echo $createdKey['expires_at'] ? date('M j, Y g:i A', strtotime($createdKey['expires_at'])) : 'No expiration'; ?></div>
                        </div>
                        <div class="admin-meta-item">
                            <div class="admin-meta-label">Rate Limit</div>
                            <div class="admin-meta-value"><?php echo $createdKey['rate_limit_per_minute']; ?> requests per minute</div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" class="admin-form-card">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                    <div class="admin-form-section">
                        <div class="admin-form-section-header">
                            <h2>Identity</h2>
                            <p>Name the integration so it is easy to audit later.</p>
                        </div>
                        <div class="form-group">
                            <label for="name">API Key Name *</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                required
                                autofocus
                                placeholder="e.g., Production API Key, Development Key"
                                value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                            >
                            <span class="admin-help-text">A descriptive name to help you identify this API key.</span>
                        </div>
                    </div>

                    <div class="admin-form-section">
                        <div class="admin-form-section-header">
                            <h2>Limits</h2>
                            <p>Set expiration and request throttling for this key.</p>
                        </div>
                        <div class="admin-form-grid">
                            <div class="form-group">
                                <label for="expires_at">Expiration Date (Optional)</label>
                                <input
                                    type="datetime-local"
                                    id="expires_at"
                                    name="expires_at"
                                    value="<?php echo htmlspecialchars($_POST['expires_at'] ?? ''); ?>"
                                >
                                <span class="admin-help-text">Leave empty for no expiration.</span>
                            </div>
                            <div class="form-group">
                                <label for="rate_limit_per_minute">Rate Limit (requests per minute)</label>
                                <input
                                    type="number"
                                    id="rate_limit_per_minute"
                                    name="rate_limit_per_minute"
                                    min="1"
                                    max="10000"
                                    value="<?php echo htmlspecialchars($_POST['rate_limit_per_minute'] ?? '60'); ?>"
                                >
                                <span class="admin-help-text">Maximum requests per minute. Default: 60.</span>
                            </div>
                        </div>
                    </div>

                    <div class="admin-form-section">
                        <div class="admin-form-section-header">
                            <h2>Permissions</h2>
                            <p>Select which API endpoints this key can access. Leave empty for full access.</p>
                        </div>
                        <div class="admin-checkbox-grid">
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="contacts.read">
                                <strong>Read Contacts</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="contacts.write">
                                <strong>Write Contacts</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="deals.read">
                                <strong>Read Deals</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="deals.write">
                                <strong>Write Deals</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="tasks.read">
                                <strong>Read Tasks</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="tasks.write">
                                <strong>Write Tasks</strong>
                            </label>
                            <label class="admin-checkbox-card">
                                <input type="checkbox" name="permissions[]" value="workflows.trigger">
                                <strong>Trigger Workflows</strong>
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="api_keys.php" class="btn-premium-secondary">Cancel</a>
                        <button type="submit" class="btn-premium-primary">Create API Key</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyApiKey() {
    const apiKeyElement = document.getElementById('created-api-key');
    const apiKey = apiKeyElement ? apiKeyElement.textContent.trim() : '';
    if (!apiKey) {
        return;
    }

    navigator.clipboard.writeText(apiKey).then(function() {
        alert('API key copied to clipboard!');
    }, function(err) {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = apiKey;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('API key copied to clipboard!');
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
