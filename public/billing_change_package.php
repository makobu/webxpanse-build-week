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

$returnUrls = (new BillingReturnUrlService())->providerCallbackForWebReturn(
    is_string($_POST['return_to'] ?? null) ? (string) $_POST['return_to'] : 'billing_payment_required.php?tab=packages'
);
$returnTo = $returnUrls['return_to'];
$redirectWithStatus = static function (string $status) use ($returnTo): void {
    header('Location: ' . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'billing_status=' . rawurlencode($status));
    exit;
};

try {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        throw new \RuntimeException('No active workspace selected.');
    }
    (new PresentationWorkspaceGuardService())->assertAllowed('billing', $workspaceId);

    $action = trim((string) ($_POST['billing_change_action'] ?? ''));
    $service = new SaaSBillingService();
    if ($action === 'schedule_downgrade') {
        $billingPlanPriceId = (int) ($_POST['billing_plan_price_id'] ?? 0);
        if ($billingPlanPriceId <= 0) {
            throw new \RuntimeException('Choose a package before scheduling a downgrade.');
        }
        $result = $service->scheduleWorkspacePackageDowngrade(
            $workspaceId,
            $billingPlanPriceId,
            (int) (Auth::user()['id'] ?? 0)
        );
        Session::set('billing_checkout_feedback', [
            'status' => 'success',
            'payment_mode' => 'package_change',
            'flow_type' => 'scheduled_change',
            'message' => (string) ($result['message'] ?? 'Workspace downgrade scheduled for renewal.'),
            'instructions' => [],
            'reference' => '',
            'expires_at' => null,
        ]);
        $redirectWithStatus('success');
    }

    if ($action === 'cancel_scheduled_change') {
        $result = $service->cancelScheduledPackageChange($workspaceId, (int) (Auth::user()['id'] ?? 0));
        Session::set('billing_checkout_feedback', [
            'status' => 'success',
            'payment_mode' => 'package_change',
            'flow_type' => 'cancel_scheduled_change',
            'message' => (string) ($result['message'] ?? 'Scheduled package change cancelled.'),
            'instructions' => [],
            'reference' => '',
            'expires_at' => null,
        ]);
        $redirectWithStatus('success');
    }

    throw new \RuntimeException('Unsupported package change action.');
} catch (\Throwable $e) {
    Session::set('billing_checkout_feedback', [
        'status' => 'error',
        'payment_mode' => 'package_change',
        'flow_type' => 'package_change_error',
        'message' => $e->getMessage(),
        'instructions' => [],
        'reference' => '',
        'expires_at' => null,
    ]);
    $redirectWithStatus('error');
}
