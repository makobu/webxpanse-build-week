<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Services\BillingReturnUrlService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceBillingService;

$auth = mobileRequireAuth(true);
$billingService = new WorkspaceBillingService();
$saasBillingService = new SaaSBillingService();
$mobileService = mobileService();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($auth['user_id'] ?? 0)]) ?? [];
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));

if (!Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], $user)) {
    mobileJson(['error' => 'Insufficient permissions.'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = mobileRequestBody();
    $action = (string) ($input['action'] ?? '');
    if ($action !== 'checkout') {
        mobileJson(['error' => 'Unsupported action.'], 422);
    }

    try {
        $planPriceId = (int) ($input['billing_plan_price_id'] ?? 0);
        $tokenPackPriceId = (int) ($input['token_pack_price_id'] ?? 0);
        $paymentMode = (string) ($input['payment_mode'] ?? 'card');
        $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
        $settings = $billingService->getSettings();
        $returnUrl = (new BillingReturnUrlService())->sanitizeMobileReturnUrl(
            (string) ($input['return_url'] ?? ($settings['mobile_return_url'] ?? '')),
            (string) ($settings['mobile_return_url'] ?? '')
        );

        if ($workspaceId <= 0) {
            mobileJson(['error' => 'No active workspace selected.'], 422);
        }

        if ($planPriceId <= 0 && $tokenPackPriceId <= 0) {
            mobileJson(['error' => 'Select a workspace package and/or token pack before checkout.'], 422);
        }

        (new WorkspaceLaunchGuardrailService())->enforceCheckoutCreation($workspaceId);

        $checkout = $saasBillingService->createCheckout(
            $workspaceId,
            [
                'billing_plan_price_id' => $planPriceId > 0 ? $planPriceId : null,
                'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
                'payment_mode' => $paymentMode,
                'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                'return_to' => $returnUrl,
                'mobile_return_url' => true,
                'configured_mobile_return_url' => (string) ($settings['mobile_return_url'] ?? ''),
            ],
            (int) ($auth['user_id'] ?? 0),
            $returnUrl
        );

        mobileJson(['success' => true, 'data' => $checkout]);
    } catch (WorkspaceLaunchReadinessException $e) {
        mobileJson([
            'error' => $e->getMessage(),
            'readiness' => $e->readiness(),
        ], 503);
    } catch (WorkspaceLaunchThrottleException $e) {
        header('Retry-After: ' . $e->retryAfter());
        mobileJson([
            'error' => $e->getMessage(),
            'retry_after' => $e->retryAfter(),
        ], 429);
    } catch (\Throwable $e) {
        mobileJson(['error' => $e->getMessage()], 400);
    }
}

$saasBilling = [
    'snapshot' => [
        'subscription_status' => 'inactive',
        'token_balance' => 0,
        'available_tokens' => 0,
        'billing_blocked' => false,
        'ai_blocked_reason' => null,
    ],
    'plans' => [],
    'token_packs' => [],
    'ledger' => [],
    'transactions' => [],
    'checkout_sessions' => [],
    'ai_usage' => ['overview' => ['total_billable_tokens' => 0, 'total_provider_cost' => 0], 'by_feature' => [], 'by_user' => []],
];
if ($workspaceId > 0) {
    try {
        $saasBilling = $saasBillingService->getWorkspaceBillingPortalData($workspaceId);
    } catch (WorkspaceLaunchReadinessException $e) {
        $saasBilling['readiness'] = $e->readiness();
    }
}
$workspaceBilling = $mobileService->shouldExposeWorkspaceBillingCompatibility()
    ? $mobileService->buildWorkspaceBillingCompatibility(
        $billingService->getBillingStateForUser($user),
        (array) ($saasBilling['snapshot'] ?? [])
    )
    : null;

mobileJson([
    'success' => true,
    'data' => [
        'workspace_billing' => $workspaceBilling,
        'saas_billing' => $saasBilling,
        'billing_blocked' => !empty($saasBilling['snapshot']['billing_blocked']),
        'subscription_status' => (string) ($saasBilling['snapshot']['subscription_status'] ?? 'inactive'),
        'token_balance' => (int) ($saasBilling['snapshot']['token_balance'] ?? 0),
        'available_tokens' => (int) ($saasBilling['snapshot']['available_tokens'] ?? 0),
        'ai_blocked_reason' => $saasBilling['snapshot']['ai_blocked_reason'] ?? null,
    ],
]);
