<?php
/**
 * SMS Webhook Handler (Twilio)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Services\SMSWebhookAuthenticationException;
use CRM\Services\SMSWebhookService;
use CRM\Services\WorkspaceSmsChannelConfigService;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: text/xml');

try {
    $configuredUrl = (new WorkspaceSmsChannelConfigService())->webhookUrl();
    if ($configuredUrl !== '') {
        $requestUrl = $configuredUrl;
    } else {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || !preg_match('/^[a-z0-9.:-]+$/i', $host)) {
            throw new SMSWebhookAuthenticationException('Unable to establish the public SMS webhook URL.');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $requestUrl = $scheme . '://' . $host . (string) ($_SERVER['REQUEST_URI'] ?? '/api/webhooks/sms.php');
    }
    $signature = trim((string) ($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''));

    (new SMSWebhookService())->handle($_POST, $requestUrl, $signature);

    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
} catch (SMSWebhookAuthenticationException $e) {
    http_response_code(403);
    error_log('SMS webhook authentication rejected: ' . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("SMS webhook error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}
