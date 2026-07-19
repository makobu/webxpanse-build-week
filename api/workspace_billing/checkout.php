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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\BillingReturnUrlService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], Auth::user())) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $planPriceId = (int) ($_POST['billing_plan_price_id'] ?? 0);
    $tokenPackPriceId = (int) ($_POST['token_pack_price_id'] ?? 0);
    $paymentMode = (string) ($_POST['payment_mode'] ?? 'card');
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));

    if ($workspaceId <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'No active workspace selected.']);
        exit;
    }

    if ($planPriceId <= 0 && $tokenPackPriceId <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Select a workspace package and/or token pack before checkout.']);
        exit;
    }

    (new WorkspaceLaunchGuardrailService())->enforceCheckoutCreation($workspaceId);
    $returnUrls = (new BillingReturnUrlService())->providerCallbackForWebReturn(
        is_string($_POST['return_to'] ?? null) ? (string) $_POST['return_to'] : null
    );

    $checkout = (new SaaSBillingService())->createCheckout(
        $workspaceId,
        [
            'billing_plan_price_id' => $planPriceId > 0 ? $planPriceId : null,
            'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
            'payment_mode' => $paymentMode,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'return_to' => $returnUrls['return_to'],
        ],
        (int) (Auth::user()['id'] ?? 0),
        $returnUrls['callback_url']
    );

    echo json_encode(['success' => true, 'data' => $checkout]);
} catch (WorkspaceLaunchThrottleException $e) {
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfter());
    echo json_encode([
        'error' => $e->getMessage(),
        'retry_after' => $e->retryAfter(),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
