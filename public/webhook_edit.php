<?php
/**
 * Edit Webhook Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\Webhooks;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.webhooks');

$webhooksModule = new Webhooks();
$availableEvents = $webhooksModule->getAvailableEvents();

$error = null;
$webhook = null;

$webhookId = (int) ($_GET['id'] ?? 0);
if ($webhookId) {
    $webhook = $webhooksModule->getById($webhookId);
    if (!$webhook) {
        header('Location: webhooks.php?error=not_found');
        exit;
    }
} else {
    header('Location: webhooks.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $method = $_POST['method'] ?? 'POST';
            $secret = trim($_POST['secret'] ?? '');
            $clearSecret = isset($_POST['clear_secret']);
            $events = $_POST['events'] ?? [];
            $headers = [];
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            // Parse custom headers
            if (!empty($_POST['custom_headers'])) {
                $headerLines = explode("\n", $_POST['custom_headers']);
                foreach ($headerLines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    if (strpos($line, ':') !== false) {
                        list($key, $value) = explode(':', $line, 2);
                        $headers[trim($key)] = trim($value);
                    }
                }
            }

            if (empty($name)) {
                $error = 'Webhook name is required.';
            } elseif (empty($url)) {
                $error = 'Webhook URL is required.';
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                $error = 'Invalid URL format.';
            } elseif (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $error = 'Webhook URL must use http or https.';
            } elseif (empty($events)) {
                $error = 'Please select at least one event.';
            } elseif ($secret !== '' && $clearSecret) {
                $error = 'Choose either a new secret or clear the current secret, not both.';
            } else {
                $updateData = [
                    'name' => $name,
                    'url' => $url,
                    'method' => $method,
                    'events' => $events,
                    'headers' => $headers,
                    'is_active' => $isActive
                ];

                if ($clearSecret) {
                    $updateData['secret'] = null;
                } elseif ($secret !== '') {
                    $updateData['secret'] = $secret;
                }

                $webhooksModule->update($webhookId, $updateData);

                header('Location: webhooks.php?success=updated');
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Format headers for display
$headersText = '';
if (!empty($webhook['headers'])) {
    $headerLines = [];
    foreach ($webhook['headers'] as $key => $value) {
        $headerLines[] = "$key: $value";
    }
    $headersText = implode("\n", $headerLines);
}

$pageTitle = 'Edit Webhook';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page admin-form-page--wide">
            <div class="admin-hero">
                <div>
                    <h1>Edit Webhook</h1>
                    <p>Update webhook configuration</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="webhooks.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Webhooks
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Endpoint</h2>
                        <p>Update the delivery target and request method.</p>
                    </div>
                    <div class="admin-form-grid">
                        <div class="form-group">
                            <label for="name">Webhook Name *</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                required
                                autofocus
                                value="<?php echo htmlspecialchars($_POST['name'] ?? $webhook['name']); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="method">HTTP Method *</label>
                            <select id="method" name="method" required>
                                <option value="POST" <?php echo ($_POST['method'] ?? $webhook['method']) === 'POST' ? 'selected' : ''; ?>>POST</option>
                                <option value="GET" <?php echo ($_POST['method'] ?? $webhook['method']) === 'GET' ? 'selected' : ''; ?>>GET</option>
                                <option value="PUT" <?php echo ($_POST['method'] ?? $webhook['method']) === 'PUT' ? 'selected' : ''; ?>>PUT</option>
                                <option value="PATCH" <?php echo ($_POST['method'] ?? $webhook['method']) === 'PATCH' ? 'selected' : ''; ?>>PATCH</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="url">Webhook URL *</label>
                        <input
                            type="url"
                            id="url"
                            name="url"
                            required
                            value="<?php echo htmlspecialchars($_POST['url'] ?? $webhook['url']); ?>"
                        >
                    </div>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Events</h2>
                        <p>Select at least one event stream for this endpoint.</p>
                    </div>
                    <?php
                    $selectedEvents = $_POST['events'] ?? $webhook['events'];
                    $eventGroups = [
                        'Contacts' => array_filter($availableEvents, fn($e) => strpos($e, 'contact.') === 0),
                        'Deals' => array_filter($availableEvents, fn($e) => strpos($e, 'deal.') === 0),
                        'Tasks' => array_filter($availableEvents, fn($e) => strpos($e, 'task.') === 0),
                        'Events' => array_filter($availableEvents, fn($e) => strpos($e, 'event.') === 0),
                        'Email' => array_filter($availableEvents, fn($e) => strpos($e, 'email.') === 0),
                        'Other' => array_filter($availableEvents, fn($e) =>
                            strpos($e, 'contact.') !== 0 &&
                            strpos($e, 'deal.') !== 0 &&
                            strpos($e, 'task.') !== 0 &&
                            strpos($e, 'event.') !== 0 &&
                            strpos($e, 'email.') !== 0
                        )
                    ];

                    foreach ($eventGroups as $groupName => $groupEvents):
                        if (empty($groupEvents)) continue;
                    ?>
                        <div class="admin-event-group">
                            <h3><?php echo htmlspecialchars($groupName); ?></h3>
                            <div class="admin-checkbox-grid">
                                <?php foreach ($groupEvents as $event): ?>
                                    <label class="admin-checkbox-card">
                                        <input
                                            type="checkbox"
                                            name="events[]"
                                            value="<?php echo htmlspecialchars($event); ?>"
                                            <?php echo in_array($event, $selectedEvents) ? 'checked' : ''; ?>
                                        >
                                        <strong><?php echo htmlspecialchars($event); ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Security</h2>
                        <p>Leave the secret blank to keep the current secret.</p>
                    </div>
                    <div class="form-group">
                        <label for="secret">Secret (Optional)</label>
                        <input
                            type="text"
                            id="secret"
                            name="secret"
                            placeholder="Leave empty to keep current secret"
                            value="<?php echo htmlspecialchars($_POST['secret'] ?? ''); ?>"
                        >
                        <span class="admin-help-text">Enter a new secret only when you want to rotate it.</span>
                    </div>
                    <?php if (!empty($webhook['secret'])): ?>
                        <label class="admin-toggle-row">
                            <input
                                type="checkbox"
                                name="clear_secret"
                                value="1"
                                <?php echo isset($_POST['clear_secret']) ? 'checked' : ''; ?>
                            >
                            <strong>Clear current secret</strong>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Headers</h2>
                        <p>Add optional request headers, one per line.</p>
                    </div>
                    <div class="form-group">
                        <label for="custom_headers">Custom Headers (Optional)</label>
                        <textarea
                            id="custom_headers"
                            name="custom_headers"
                            rows="4"
                            placeholder="Authorization: Bearer token&#10;X-Custom-Header: value"
                        ><?php echo htmlspecialchars($_POST['custom_headers'] ?? $headersText); ?></textarea>
                    </div>
                </div>

                <div class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Status</h2>
                        <p>Control whether this webhook receives new events.</p>
                    </div>
                    <label class="admin-toggle-row">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?php echo (isset($_POST['is_active']) ? $_POST['is_active'] : $webhook['is_active']) ? 'checked' : ''; ?>
                        >
                        <strong>Activate webhook</strong>
                    </label>
                </div>

                <div class="form-actions">
                    <a href="webhooks.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">Update Webhook</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
