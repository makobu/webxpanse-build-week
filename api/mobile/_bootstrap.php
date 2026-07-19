<?php

// CORS – allow Flutter web dev server and any localhost origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginPattern = '/^https?:\/\/localhost(:\d+)?$/';
if (preg_match($allowedOriginPattern, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';

loadEnvFile(__DIR__ . '/../../.env');

require_once __DIR__ . '/../../config/constants.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Services\MobileTokenAuthService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceBillingService;
use CRM\Services\WorkspaceLaunchReadinessException;

Database::init(require __DIR__ . '/../../config/database.php');

if (!defined('MOBILE_JSON_ERROR_HANDLERS')) {
    define('MOBILE_JSON_ERROR_HANDLERS', true);

    set_exception_handler(static function (\Throwable $exception): void {
        error_log('Mobile API uncaught exception: ' . $exception->getMessage());
        mobileJson([
            'error' => 'The workspace hit an unexpected server error.',
            'message' => 'Check the CRM logs for more detail.',
        ], 500);
    });

    register_shutdown_function(static function (): void {
        $lastError = error_get_last();
        if ($lastError === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) $lastError['type'], $fatalTypes, true)) {
            return;
        }

        error_log('Mobile API fatal error: ' . ($lastError['message'] ?? 'Unknown fatal error'));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => 'The workspace returned a fatal server error.',
            'message' => 'Check the CRM installation logs.',
        ], JSON_UNESCAPED_SLASHES);
    });
}

function mobileJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mobileWorkspaceContextPayload(array $auth): array
{
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));

    return [
        'workspace_id' => $workspaceId,
        'active_workspace_id' => $workspaceId,
        'workspace_version' => $workspaceId > 0 ? 'workspace-' . $workspaceId : 'workspace-none',
    ];
}

function mobileRequestBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ($_POST ?: []);
}

function mobileBearerToken(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    if (!is_string($authorization)) {
        return null;
    }

    if (preg_match('/Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function mobileService(): MobileTokenAuthService
{
    static $service = null;
    if ($service === null) {
        $service = new MobileTokenAuthService();
    }
    return $service;
}

function mobileRequireAuth(bool $allowRestricted = false): array
{
    $token = mobileBearerToken();
    if (!$token) {
        mobileJson([
            'error' => 'Unauthorized',
            'error_code' => 'missing_token',
            'message' => 'Missing bearer token.',
            'action' => 'force_login',
            'can_refresh' => false,
        ], 401);
    }

    $auth = mobileService()->authenticate($token);
    if (!$auth) {
        $reason = mobileService()->diagnoseFailure($token);
        $canRefresh = ($reason === 'expired');
        mobileJson([
            'error' => 'Unauthorized',
            'error_code' => 'token_' . $reason,
            'message' => $reason === 'revoked'
                ? 'You have been logged out.'
                : 'Session expired or invalid.',
            'action' => $canRefresh ? 'try_refresh' : 'force_login',
            'can_refresh' => $canRefresh,
        ], 401);
    }

    if (!$allowRestricted) {
        $user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($auth['user_id'] ?? 0)]) ?? [];
        $workspaceId = (int) ($auth['workspace_id'] ?? 0);
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
                error_log('Mobile API billing readiness warning: ' . $e->operatorMessage());
                $saasBilling['launch_readiness'] = $e->readiness();
            } catch (\Throwable $e) {
                error_log('Mobile API billing snapshot warning: ' . $e->getMessage());
                $saasBilling['launch_readiness'] = [
                    'ready' => false,
                    'customer_message' => 'Workspace billing status is temporarily unavailable.',
                    'operator_message' => $e->getMessage(),
                ];
            }
        }
        $workspaceBilling = mobileService()->shouldExposeWorkspaceBillingCompatibility()
            ? mobileService()->buildWorkspaceBillingCompatibility(
                (new WorkspaceBillingService())->getBillingStateForUser($user),
                $saasBilling
            )
            : null;
        if (!empty($saasBilling['billing_blocked'])) {
            mobileJson([
                'success' => true,
                'data' => [
                    'billing_blocked' => true,
                    'workspace_billing' => $workspaceBilling,
                    'saas_billing' => $saasBilling,
                ],
            ]);
        }
    }

    WorkspaceContext::activateRuntimeWorkspace(
        (int) ($auth['workspace_id'] ?? 0),
        (int) ($auth['user_id'] ?? 0)
    );

    return $auth;
}

function mobileRequestedAllScope($requestedScope): bool
{
    return strtolower(trim((string) $requestedScope)) === 'all';
}

function mobileResolveVisibilityScope(
    array $user,
    string $permissionKey,
    $requestedScope,
    string $defaultScope = 'mine'
): string {
    if (mobileRequestedAllScope($requestedScope) && Authorization::can($permissionKey, $user)) {
        return 'all';
    }

    return $defaultScope;
}

function mobileResolveConversationOwnerScope(array $user, $requestedScope): string
{
    return mobileResolveVisibilityScope($user, 'conversations.view_all', $requestedScope, 'mine_unassigned');
}

function mobileCanViewAllTasks(array $user): bool
{
    return Authorization::can('tasks.view_all', $user);
}

function mobileCanViewAllNotifications(array $user): bool
{
    return Authorization::can('notifications.view_all', $user);
}

function mobileCanAccessTask(array $task, int $viewerUserId, array $viewer): bool
{
    if (mobileCanViewAllTasks($viewer)) {
        return true;
    }

    return (int) ($task['assigned_to'] ?? 0) === $viewerUserId;
}
