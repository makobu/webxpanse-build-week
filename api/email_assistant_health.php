<?php
/**
 * Email Assistant Health Check API
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\AIRuntimeConfig;
use CRM\Services\EmailAssistantDigestService;

header('Content-Type: application/json');

try {
    Database::init(require __DIR__ . '/../config/database.php');
    Auth::requireAuth();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'EMAIL_ASSISTANT_HEALTH_BOOTSTRAP_FAILED',
    ], JSON_PRETTY_PRINT);
    exit;
}

Authorization::requirePermission('settings.email_assistant', true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$readiness = AIRuntimeConfig::validate();
$assistant = $readiness['email_assistant'];
$provider = $readiness['provider'];
$digestReadiness = (new EmailAssistantDigestService())->validateDigestConfig();

echo json_encode([
    'success' => true,
    'readiness' => $readiness,
    'core_ai_provider_ready' => $readiness['core_ai_provider_ready'],
    'email_assistant_outbound_ready' => $readiness['email_assistant_outbound_ready'],
    'email_assistant_inbound_ready' => $readiness['email_assistant_inbound_ready'],
    'fallback_only_mode' => $readiness['fallback_only_mode'],
    'provider' => [
        'normalized_api_url' => $provider['normalized_api_url'],
        'provider_type' => $provider['provider_type'],
        'model' => $provider['model'],
    ],
    'outbound_ok' => $assistant['outbound_ok'],
    'inbound_ok' => $assistant['inbound_ok'],
    'identity_ok' => $assistant['identity_ok'],
    'outbound_missing' => $assistant['outbound_missing'],
    'inbound_missing' => $assistant['inbound_missing'],
    'identity_missing' => $assistant['identity_missing'],
    'ready_for_digest' => $assistant['outbound_ok'] && $assistant['identity_ok'] && ($digestReadiness['delivery_verified'] !== false),
    'digest_delivery' => $digestReadiness,
    'ready_for_inbound' => $assistant['inbound_ok'],
    'message' => $readiness['message'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
