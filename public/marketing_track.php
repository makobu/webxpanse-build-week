<?php
/**
 * Public Marketing tracking endpoint.
 *
 * Records tokenized landing page views, CTA clicks, and conversion events.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Marketing;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    Database::init(require __DIR__ . '/../config/database.php');

    $rawBody = (string) file_get_contents('php://input');
    $json = [];
    if ($rawBody !== '' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $decoded = json_decode($rawBody, true);
        $json = is_array($decoded) ? $decoded : [];
    }

    $input = array_merge($_GET, $_POST, $json);
    $sessionKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_COOKIE['crm_marketing_vid'] ?? $input['session_key'] ?? '')) ?: '';
    if ($sessionKey === '') {
        $sessionKey = bin2hex(random_bytes(16));
    }
    setcookie('crm_marketing_vid', $sessionKey, [
        'expires' => time() + (86400 * 180),
        'path' => '/',
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
    ]);

    $input['session_key'] = $sessionKey;
    $input['ip_address'] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $input['user_agent'] = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $input['referrer'] = (string) ($input['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? '');

    $marketing = new Marketing();
    $result = $marketing->recordMarketingTrackingEvent($input);

    echo json_encode([
        'ok' => true,
        'event_id' => (int) ($result['event_id'] ?? 0),
        'visitor_session_id' => (int) ($result['visitor_session_id'] ?? 0),
        'event_type' => (string) ($result['event_type'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $message = $e->getMessage();
    http_response_code(str_contains(strtolower($message), 'not found') ? 404 : 400);
    echo json_encode([
        'ok' => false,
        'error' => str_contains(strtolower($message), 'not found')
            ? 'Tracking target was not found.'
            : 'Tracking event could not be recorded.',
    ], JSON_UNESCAPED_SLASHES);
}
