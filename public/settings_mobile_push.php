<?php
/**
 * Mobile Push Notification Settings
 * Configure Firebase Cloud Messaging (FCM) for mobile push delivery.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\FirebaseAccessTokenService;
use CRM\Services\MobilePushNotificationService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$user = Auth::user();
if (!$user || !Authorization::can('settings.general', $user)) {
    header('Location: dashboard.php');
    exit;
}

$success = '';
$error   = '';
$saPath  = __DIR__ . '/../config/firebase-service-account.json';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_credentials') {
        $jsonText = trim($_POST['service_account_json'] ?? '');

        // Accept file upload
        if (empty($jsonText) && !empty($_FILES['service_account_file']['tmp_name'])) {
            $jsonText = trim(file_get_contents($_FILES['service_account_file']['tmp_name']));
        }

        if ($jsonText === '') {
            $error = 'Please paste the service account JSON or upload the file.';
        } else {
            $decoded = json_decode($jsonText, true);
            if (!is_array($decoded) || empty($decoded['private_key']) || empty($decoded['client_email'])) {
                $error = 'The JSON does not appear to be a valid Firebase service account key. Ensure it has "private_key" and "client_email" fields.';
            } else {
                // Save the JSON to a config file
                file_put_contents($saPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                chmod($saPath, 0600);

                // Update .env: set FCM_PROJECT_ID and FCM_SERVICE_ACCOUNT_PATH, clear inline JSON
                $envContent = file_get_contents($envFile);
                $projectId  = (string)($decoded['project_id'] ?? 'webxpanse-app');
                $relPath    = realpath($saPath);

                foreach ([
                    'FCM_PROJECT_ID'            => $projectId,
                    'FCM_SERVICE_ACCOUNT_PATH'  => $relPath,
                    'FCM_SERVICE_ACCOUNT_JSON'  => '',
                ] as $envKey => $envVal) {
                    $pattern = '/^' . preg_quote($envKey, '/') . '=.*$/m';
                    if (preg_match($pattern, $envContent)) {
                        $envContent = preg_replace($pattern, $envKey . '=' . $envVal, $envContent);
                    } else {
                        $envContent .= "\n{$envKey}={$envVal}";
                    }
                }
                file_put_contents($envFile, $envContent);
                $_ENV['FCM_PROJECT_ID']           = $projectId;
                $_ENV['FCM_SERVICE_ACCOUNT_PATH'] = $relPath;
                $_ENV['FCM_SERVICE_ACCOUNT_JSON'] = '';

                $success = 'Firebase service account saved. Push notifications are now enabled.';
            }
        }
    } elseif ($action === 'test_push') {
        try {
            $svc = new MobilePushNotificationService();
            $notificationId = Database::execute(
                "INSERT INTO notifications (user_id, type, title, message, entity_type, created_at)
                 VALUES (?, 'system', 'Push test', 'FCM push is working - you should see this on your device!', 'system', NOW())",
                [(int)$user['id']]
            );
            $notificationId = (int)Database::lastInsertId();
            $svc->sendForNotification($notificationId);

            $delivery = Database::queryOne(
                "SELECT status, provider_error_message FROM mobile_push_deliveries WHERE notification_id = ? ORDER BY id DESC LIMIT 1",
                [$notificationId]
            );
            if (($delivery['status'] ?? '') === 'sent') {
                $success = 'Test push sent successfully. Check your device.';
            } else {
                $error = 'Push failed: ' . ($delivery['provider_error_message'] ?? 'unknown error');
            }
        } catch (\Throwable $e) {
            $error = 'Error: ' . htmlspecialchars($e->getMessage());
        }
    } elseif ($action === 'retry_failed') {
        $failed = Database::query(
            "SELECT DISTINCT notification_id FROM mobile_push_deliveries
             WHERE user_id = ? AND status IN ('failed','pending')
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
             ORDER BY id DESC LIMIT 50",
            [(int)$user['id']]
        );
        $svc     = new MobilePushNotificationService();
        $retried = 0;
        foreach ($failed as $row) {
            try { $svc->sendForNotification((int)$row['notification_id']); $retried++; } catch (\Throwable $ignored) {}
        }
        $success = "Retried {$retried} failed push deliveries. Check the delivery log below.";
    } elseif ($action === 'remove_credentials') {
        if (file_exists($saPath)) unlink($saPath);
        $envContent = file_get_contents($envFile);
        foreach (['FCM_SERVICE_ACCOUNT_PATH', 'FCM_SERVICE_ACCOUNT_JSON'] as $k) {
            $envContent = preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', $k . '=', $envContent);
        }
        file_put_contents($envFile, $envContent);
        $_ENV['FCM_SERVICE_ACCOUNT_PATH'] = '';
        $_ENV['FCM_SERVICE_ACCOUNT_JSON'] = '';
        $success = 'Credentials removed. Push notifications are disabled until re-configured.';
    }
}

// Status
$fcmConfigured  = false;
$fcmProjectId   = $_ENV['FCM_PROJECT_ID'] ?? '';
$fcmStatus      = 'Not configured';
$fcmStatusClass = 'danger';

try {
    $svc2         = new FirebaseAccessTokenService();
    $testProject  = $svc2->projectId();
    if ($testProject !== '') {
        $svc2->getAccessToken(); // throws if credentials are bad
        $fcmConfigured  = true;
        $fcmStatus      = 'Connected - project: ' . htmlspecialchars($testProject);
        $fcmStatusClass = 'success';
    }
} catch (\Throwable $e) {
    $fcmStatus      = 'Error: ' . htmlspecialchars($e->getMessage());
    $fcmStatusClass = 'danger';
}

// Delivery summary
$deliverySummary = Database::query(
    "SELECT status, COUNT(*) as cnt FROM mobile_push_deliveries
     WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY status ORDER BY cnt DESC"
);

// Registered tokens
$registeredTokens = Database::query(
    "SELECT t.id, t.user_id,
            CONCAT(u.first_name, ' ', u.last_name) as user_name, u.email,
            t.push_disabled_at, t.last_push_sent_at, t.last_push_error,
            LEFT(t.push_token,32) as token_preview
     FROM mobile_auth_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.push_token IS NOT NULL AND t.push_token <> ''
     ORDER BY t.id DESC LIMIT 20"
);

// Recent deliveries
$recentDeliveries = Database::query(
    "SELECT d.id, d.notification_id,
            CONCAT(u.first_name, ' ', u.last_name) as user_name, d.status,
            d.title, LEFT(d.provider_error_message,120) as err, d.attempted_at
     FROM mobile_push_deliveries d
     JOIN users u ON u.id = d.user_id
     ORDER BY d.id DESC LIMIT 30"
);

$pageTitle = 'Mobile Push - Settings';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/settings-ui.css?v=20260526c">

<div class="page-premium settings-page settings-aux-page">
<div class="container settings-container--narrow">

  <div class="settings-inline-header">
    <a href="settings.php" class="settings-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> Settings</a>
    <h1><i class="fas fa-mobile-screen-button" aria-hidden="true"></i> Mobile Push Notifications</h1>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Status Card -->
  <div class="card mb-4">
    <div class="card-body">
      <h5 class="card-title">FCM Status</h5>
      <span class="badge bg-<?= $fcmStatusClass ?> fs-6"><?= $fcmStatus ?></span>

      <?php if ($fcmConfigured): ?>
        <div class="settings-action-row" style="margin-top:1rem;">
          <form method="POST">
            <input type="hidden" name="action" value="test_push">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-bell" aria-hidden="true"></i> Send Test Push</button>
          </form>
          <form method="POST">
            <input type="hidden" name="action" value="retry_failed">
            <button class="btn btn-outline-warning btn-sm" type="submit"><i class="fas fa-rotate" aria-hidden="true"></i> Retry Failed (48 h)</button>
          </form>
          <form method="POST" onsubmit="return confirm('Remove FCM credentials?')">
            <input type="hidden" name="action" value="remove_credentials">
            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fas fa-trash" aria-hidden="true"></i> Remove Credentials</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Configure Credentials -->
  <?php if (!$fcmConfigured): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Configure Firebase Service Account</strong></div>
    <div class="card-body">
      <p class="text-muted">To enable push notifications, you need a Firebase Admin SDK service account key.</p>
      <ol class="settings-instruction-list">
        <li>Open <a href="https://console.firebase.google.com/project/webxpanse-app/settings/serviceaccounts/adminsdk" target="_blank">Firebase Console &gt; Project Settings &gt; Service Accounts</a></li>
        <li>Click <strong>"Generate new private key"</strong> and download the JSON file.</li>
        <li>Upload the file <strong>or</strong> paste its contents below.</li>
      </ol>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_credentials">

        <div class="mb-3">
          <label class="form-label">Upload service account JSON file</label>
          <input type="file" name="service_account_file" class="form-control" accept=".json">
        </div>

        <div class="mb-3">
          <label class="form-label">OR paste JSON content</label>
          <textarea name="service_account_json" class="form-control font-monospace"
                    rows="6" placeholder='{"type":"service_account","project_id":"...","private_key":"...",...}'></textarea>
        </div>

        <button type="submit" class="btn btn-success">Save &amp; Enable Push</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <!-- Already configured - allow replacing -->
  <div class="card mb-4">
    <div class="card-header"><strong>Replace Service Account Credentials</strong></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_credentials">
        <div class="mb-3">
          <label class="form-label">Upload new service account JSON</label>
          <input type="file" name="service_account_file" class="form-control" accept=".json">
        </div>
        <div class="mb-3">
          <label class="form-label">OR paste JSON</label>
          <textarea name="service_account_json" class="form-control font-monospace"
                    rows="4" placeholder='{"type":"service_account",...}'></textarea>
        </div>
        <button type="submit" class="btn btn-outline-primary btn-sm">Update Credentials</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Delivery Summary -->
  <?php if (!empty($deliverySummary)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Delivery Summary - Last 7 Days</strong></div>
    <div class="card-body">
      <div class="settings-stat-row">
        <?php foreach ($deliverySummary as $row): ?>
          <?php
            $cls = match($row['status']) {
              'sent'          => 'success',
              'failed'        => 'danger',
              'invalid_token' => 'warning',
              default         => 'secondary',
            };
          ?>
          <div class="settings-stat">
            <div class="settings-stat-value"><?= (int)$row['cnt'] ?></div>
            <span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($row['status']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Registered Tokens -->
  <div class="card mb-4">
    <div class="card-header"><strong>Registered Device Tokens (<?= count($registeredTokens) ?>)</strong></div>
    <?php if (empty($registeredTokens)): ?>
      <div class="card-body text-muted">No devices registered yet. Open the mobile app and sign in to register.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr>
          <th>User</th><th>Token (preview)</th><th>Disabled</th><th>Last push sent</th><th>Last error</th>
        </tr></thead>
        <tbody>
          <?php foreach ($registeredTokens as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['user_name'] . ' <' . $t['email'] . '>') ?></td>
            <td><code><?= htmlspecialchars($t['token_preview']) ?><span class="settings-ellipsis">...</span></code></td>
            <td><?= $t['push_disabled_at'] ? '<span class="badge bg-danger">Yes</span>' : '<span class="badge bg-success">No</span>' ?></td>
            <td><?= htmlspecialchars((string)($t['last_push_sent_at'] ?? 'Never')) ?></td>
            <td class="text-danger small"><?= htmlspecialchars((string)($t['last_push_error'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent Deliveries -->
  <?php if (!empty($recentDeliveries)): ?>
  <div class="card mb-4">
    <div class="card-header"><strong>Recent Deliveries</strong></div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>ID</th><th>Notif</th><th>User</th><th>Title</th><th>Status</th><th>Error</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($recentDeliveries as $d): ?>
            <?php $cls = match($d['status']) {'sent'=>'success','failed'=>'danger','invalid_token'=>'warning',default=>'secondary'}; ?>
            <tr>
              <td><?= (int)$d['id'] ?></td>
              <td><?= (int)$d['notification_id'] ?></td>
              <td><?= htmlspecialchars((string)$d['user_name']) ?></td>
              <td><?= htmlspecialchars((string)$d['title']) ?></td>
              <td><span class="badge bg-<?= $cls ?>"><?= htmlspecialchars($d['status']) ?></span></td>
              <td class="text-danger small"><?= htmlspecialchars((string)$d['err']) ?></td>
              <td class="small text-muted"><?= htmlspecialchars((string)$d['attempted_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->
</div><!-- /page-premium -->

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
