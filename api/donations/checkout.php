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
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Modules\RateLimiter;
use CRM\Security;
use CRM\Services\BillingReturnUrlService;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Services\WorkspaceMembershipService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

if (!WorkspaceBillingSettings::donationsEnabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'Donation support is currently unavailable.']);
    exit;
}

try {
    $amount = (float) ($_POST['amount'] ?? 0);
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'KES')));
    $paymentMode = strtolower(trim((string) ($_POST['payment_mode'] ?? 'card')));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $isAuthenticated = Auth::check();
    $userId = null;
    $source = 'public_donation';
    $returnFallback = publicUrl('donate.php');
    $billingService = new SaaSBillingService();

    if ($isAuthenticated) {
        $user = Auth::user();
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $canCreateDonation = Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], $user);
        if (!$canCreateDonation && $workspaceId > 0) {
            $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, (int) ($user['id'] ?? 0));
            $workspaceRole = strtolower((string) ($membership['role_slug'] ?? ''));
            $canCreateDonation = !empty($membership['is_owner']) || in_array($workspaceRole, ['owner', 'admin'], true);
        }

        if (!$canCreateDonation) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }

        if ($workspaceId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'No active workspace selected.']);
            exit;
        }

        $userId = (int) ($user['id'] ?? 0);
        $source = 'workspace_donation';
        $returnFallback = publicUrl('billing_payment_required.php');
    } else {
        $workspaceId = (new DefaultWorkspaceService())->id();
    }

    if ($amount <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Enter a donation amount greater than zero.']);
        exit;
    }

    if ($currency === '') {
        $currency = 'KES';
    }

    if ($paymentMode === 'mpesa' && $currency !== 'KES') {
        http_response_code(422);
        echo json_encode(['error' => 'M-Pesa checkout is only available in KSh / KES.']);
        exit;
    }

    $availableDonationModes = (array) (($billingService->donationPaymentOptions(
        [$currency],
        true
    )['modes_by_currency'] ?? [])[$currency] ?? []);
    $selectedDonationMode = null;
    foreach ($availableDonationModes as $availableDonationMode) {
        if ((string) ($availableDonationMode['key'] ?? '') === $paymentMode) {
            $selectedDonationMode = (array) $availableDonationMode;
            break;
        }
    }

    if ($selectedDonationMode === null) {
        http_response_code(422);
        echo json_encode(['error' => 'The selected donation payment method is not available for this currency.']);
        exit;
    }

    $requiresPhone = !empty($selectedDonationMode['requires_phone']);
    if (!$isAuthenticated && !$requiresPhone && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Enter a valid email address for card checkout.']);
        exit;
    }

    if (!$isAuthenticated && $requiresPhone && $customerPhone === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Enter the M-Pesa phone number to start this donation.']);
        exit;
    }

    if (!$requiresPhone) {
        $customerPhone = '';
    }

    if ($isAuthenticated) {
        (new WorkspaceLaunchGuardrailService())->enforceCheckoutCreation($workspaceId);
    } else {
        $publicLimiter = new RateLimiter(
            'public_donation_checkout_' . max(0, $workspaceId),
            (int) ($_ENV['PUBLIC_DONATION_CHECKOUT_ATTEMPTS'] ?? 8),
            (int) ($_ENV['PUBLIC_DONATION_CHECKOUT_WINDOW'] ?? 600)
        );
        if ($publicLimiter->isLimited()) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $publicLimiter->getRetryAfterSeconds()));
            echo json_encode([
                'error' => 'Too many donation checkout attempts were made. Please wait a moment before trying again.',
                'retry_after' => max(1, $publicLimiter->getRetryAfterSeconds()),
            ]);
            exit;
        }
        $publicLimiter->recordAttempt();
    }

    $returnUrls = (new BillingReturnUrlService())->providerCallbackForWebReturn(
        is_string($_POST['return_to'] ?? null) ? (string) $_POST['return_to'] : null,
        $returnFallback
    );

    $checkout = $billingService->createDonationCheckout(
        $workspaceId,
        $amount,
        $currency,
        $userId,
        $returnUrls['callback_url'],
        [
            'payment_mode' => $paymentMode,
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            'return_to' => $returnUrls['return_to'],
            'message' => $message,
            'source' => $source,
        ]
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
