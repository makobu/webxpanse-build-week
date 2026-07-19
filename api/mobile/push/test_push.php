<?php
/**
 * POST /api/mobile/push/test_push.php
 * Admin-only endpoint – sends a test FCM push to the authenticated user's device(s).
 * Also retries the most recent failed deliveries when ?retry=1 is passed.
 *
 * Usage:
 *   curl -X POST "http://localhost$(php -r \"require 'config/constants.php'; echo apiUrl('mobile/push/test_push.php');\")" \
 *        -H "Authorization: Bearer <token>"
 */

require_once __DIR__ . '/../../../api/mobile/_bootstrap.php';

use CRM\Database;
use CRM\Services\FirebaseAccessTokenService;
use CRM\Services\MobilePushNotificationService;

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);

// Verify FCM config
$fcmTokenService = new FirebaseAccessTokenService();
$projectId = $fcmTokenService->projectId();

if ($projectId === '') {
    mobileJson([
        'success' => false,
        'error' => 'FCM not configured',
        'detail' => 'FCM_PROJECT_ID is not set in .env',
        'fix' => 'Add FCM_PROJECT_ID=webxpanse-app and FCM_SERVICE_ACCOUNT_PATH=/path/to/service-account.json to .env',
    ], 500);
}

try {
    $accessToken = $fcmTokenService->getAccessToken();
} catch (\Throwable $e) {
    mobileJson([
        'success' => false,
        'error' => 'FCM credentials invalid',
        'detail' => $e->getMessage(),
        'fix' => 'Download the service account JSON from Firebase Console → Project Settings → Service Accounts → Generate new private key, then set FCM_SERVICE_ACCOUNT_PATH in .env',
    ], 500);
}

$body = mobileRequestBody();
$retry = !empty($body['retry']) || !empty($_GET['retry']);

if ($retry) {
    // Retry all recent failed deliveries for this user
    $failed = Database::query(
        "SELECT d.notification_id
         FROM mobile_push_deliveries d
         WHERE d.user_id = ? AND d.status IN ('failed','pending')
           AND d.attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY d.id DESC LIMIT 20",
        [$userId]
    );

    $retried = 0;
    $svc = new MobilePushNotificationService();
    foreach ($failed as $row) {
        try {
            $svc->sendForNotification((int) $row['notification_id']);
            $retried++;
        } catch (\Throwable $ignored) {}
    }

    mobileJson([
        'success' => true,
        'action' => 'retry',
        'retried_count' => $retried,
        'message' => "Retried {$retried} failed deliveries.",
    ]);
}

// Create a test notification for this user and send it
$notificationId = Database::execute(
    "INSERT INTO notifications (user_id, type, title, message, entity_type, created_at)
     VALUES (?, 'system', 'Push test', 'If you see this — FCM push is working! ✅', 'system', NOW())",
    [$userId]
);
$notificationId = (int) Database::lastInsertId();

$svc = new MobilePushNotificationService();
$svc->sendForNotification($notificationId);

// Return result
$delivery = Database::queryOne(
    "SELECT status, provider_error_message, provider_message_id
     FROM mobile_push_deliveries
     WHERE notification_id = ?
     ORDER BY id DESC LIMIT 1",
    [$notificationId]
);

mobileJson([
    'success' => ($delivery['status'] ?? '') === 'sent',
    'notification_id' => $notificationId,
    'delivery' => $delivery,
    'fcm_project' => $projectId,
]);
