<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Services\WorkspaceBillingService;

Database::init(require __DIR__ . '/../../config/database.php');

$rawPayload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null;
$decodedPayload = json_decode($rawPayload, true);
$reference = is_array($decodedPayload) ? trim((string) (($decodedPayload['data']['reference'] ?? '') ?: '')) : null;

try {
    (new WorkspaceLaunchGuardrailService())->enforcePaystackWebhook($signature, $reference);

    $saasResult = (new SaaSBillingService())->processWebhook($rawPayload, $signature);
    $result = $saasResult;

    if (empty($saasResult['handled']) && !empty($saasResult['accepted'])) {
        $legacyBilling = new WorkspaceBillingService();
        if ($reference !== '' && $legacyBilling->hasPaymentIntentReference($reference)) {
            $result = $legacyBilling->processWebhook($rawPayload, $signature);
        }
    }

    http_response_code(!empty($result['accepted']) ? 200 : 400);
    header('Content-Type: application/json');
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(
        $e instanceof WorkspaceLaunchReadinessException ? 503
            : ($e instanceof WorkspaceLaunchThrottleException ? 429 : 500)
    );
    if ($e instanceof WorkspaceLaunchThrottleException) {
        header('Retry-After: ' . $e->retryAfter());
    }
    header('Content-Type: application/json');
    echo json_encode([
        'accepted' => false,
        'message' => $e instanceof WorkspaceLaunchReadinessException
            ? $e->getMessage()
            : $e->getMessage(),
        'retry_after' => $e instanceof WorkspaceLaunchThrottleException ? $e->retryAfter() : null,
        'readiness' => $e instanceof WorkspaceLaunchReadinessException ? $e->readiness() : null,
    ]);
}
