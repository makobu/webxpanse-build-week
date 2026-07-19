<?php

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
use CRM\Security;
use CRM\Services\BillingReturnUrlService;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

if (!Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], Auth::user())) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo 'Invalid security token.';
    exit;
}

try {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        throw new \RuntimeException('No active workspace selected.');
    }
    (new PresentationWorkspaceGuardService())->assertAllowed('billing', $workspaceId);

    $billingPlanPriceId = (int) ($_POST['billing_plan_price_id'] ?? 0);
    $tokenPackPriceId = (int) ($_POST['token_pack_price_id'] ?? 0);
    $paymentMode = (string) ($_POST['payment_mode'] ?? 'card');
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    if ($billingPlanPriceId <= 0 && $tokenPackPriceId <= 0) {
        throw new \RuntimeException('Choose a workspace package or token pack before checkout.');
    }

    (new WorkspaceLaunchGuardrailService())->enforceCheckoutCreation($workspaceId);

    $returnUrls = (new BillingReturnUrlService())->providerCallbackForWebReturn(
        is_string($_POST['return_to'] ?? null) ? (string) $_POST['return_to'] : null
    );
    $returnTo = $returnUrls['return_to'];

    $checkout = (new SaaSBillingService())->createCheckout(
        $workspaceId,
        [
            'billing_plan_price_id' => $billingPlanPriceId > 0 ? $billingPlanPriceId : null,
            'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
            'payment_mode' => $paymentMode,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'return_to' => $returnTo,
        ],
        (int) (Auth::user()['id'] ?? 0),
        $returnUrls['callback_url']
    );

    if (!empty($checkout['authorization_url'])) {
        header('Location: ' . $checkout['authorization_url']);
        exit;
    }

    if (($checkout['flow_type'] ?? '') === 'free' || ((float) ($checkout['amount'] ?? 0) <= 0 && ($checkout['checkout_type'] ?? '') === 'subscription')) {
        Session::set('billing_checkout_feedback', [
            'status' => 'success',
            'payment_mode' => 'free',
            'flow_type' => 'free',
            'message' => (string) ($checkout['display_text'] ?? 'Compass Free is active for this workspace.'),
            'instructions' => [],
            'reference' => (string) ($checkout['reference'] ?? ''),
            'expires_at' => null,
        ]);
        header('Location: ' . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'billing_status=success');
        exit;
    }

    Session::set('billing_checkout_feedback', [
        'status' => 'pending',
        'payment_mode' => (string) ($checkout['payment_mode'] ?? $paymentMode),
        'flow_type' => (string) ($checkout['flow_type'] ?? 'offline_charge'),
        'message' => (string) ($checkout['display_text'] ?? 'Follow the payment instructions to complete this checkout.'),
        'instructions' => (array) ($checkout['instructions'] ?? []),
        'reference' => (string) ($checkout['reference'] ?? ''),
        'expires_at' => $checkout['expires_at'] ?? null,
    ]);
    header('Location: ' . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'billing_status=pending');
    exit;
} catch (WorkspaceLaunchThrottleException $e) {
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfter());
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
} catch (\Throwable $e) {
    http_response_code(400);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
