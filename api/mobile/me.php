<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Database;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth(true);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($auth['user_id'] ?? 0)]) ?? [];
$mobileService = mobileService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
$saasBilling = [
    'subscription_status' => 'inactive',
    'token_balance' => 0,
    'available_tokens' => 0,
    'billing_blocked' => false,
    'ai_blocked_reason' => null,
];
if ($workspaceId > 0) {
    try {
        $saasBilling = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId, $user);
    } catch (WorkspaceLaunchReadinessException $e) {
        $saasBilling['launch_readiness'] = $e->readiness();
    }
}
$workspaceBilling = $mobileService->shouldExposeWorkspaceBillingCompatibility()
    ? $mobileService->buildWorkspaceBillingCompatibility([], $saasBilling)
    : null;

mobileJson([
    'success' => true,
    'data' => [
        'user' => mobileService()->buildUserPayload($auth),
        'workspace' => mobileService()->bootstrapPayload()['app'],
        'workspace_billing' => $workspaceBilling,
        'saas_billing' => $saasBilling,
        'billing_blocked' => !empty($saasBilling['billing_blocked']),
        'subscription_status' => (string) ($saasBilling['subscription_status'] ?? 'inactive'),
        'token_balance' => (int) ($saasBilling['token_balance'] ?? 0),
        'available_tokens' => (int) ($saasBilling['available_tokens'] ?? 0),
        'ai_blocked_reason' => $saasBilling['ai_blocked_reason'] ?? null,
    ],
]);
