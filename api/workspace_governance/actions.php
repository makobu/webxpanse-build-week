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
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid security token.']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'No active workspace selected.']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$service = new WorkspaceGovernanceService();
$action = trim((string) ($_POST['action'] ?? ''));

try {
    (new WorkspaceLaunchGuardrailService())->enforceGovernanceAction($workspaceId, $action);

    $result = null;
    $message = 'Workspace governance updated.';

    if ($action === 'invite_member') {
        $inviteFunctionIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['invite_function_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        $invitePrimaryFunctionId = (int) ($_POST['invite_primary_function_id'] ?? 0);
        $inviteFunctionAssignmentTypes = [];
        foreach ((array) ($_POST['invite_function_assignment_types'] ?? []) as $functionId => $assignmentType) {
            $inviteFunctionAssignmentTypes[(int) $functionId] = (string) $assignmentType;
        }

        $functionService = new OrganizationFunctionService();
        if ($functionService->tablesReady()) {
            $functionService->ensureDefaults($workspaceId);
            if ($functionService->listAssignableFunctions($workspaceId) !== [] && $inviteFunctionIds === []) {
                throw new RuntimeException('Assign at least one business function before creating an invite.');
            }
        }

        $result = $service->createInvite(
            $workspaceId,
            $userId,
            (string) ($_POST['invite_email'] ?? ''),
            (string) ($_POST['invite_role_slug'] ?? 'viewer'),
            $inviteFunctionIds,
            $invitePrimaryFunctionId,
            $inviteFunctionAssignmentTypes
        );
        $message = !empty($result['delivery']['success'])
            ? 'Workspace invite created and delivered.'
            : 'Workspace invite created. Delivery failed, so share the link or resend later.';
    } elseif ($action === 'resend_invite') {
        $result = $service->resendInvite(
            $workspaceId,
            (int) ($_POST['invite_id'] ?? 0),
            $userId
        );
        $message = !empty($result['delivery']['success'])
            ? 'Workspace invite resent.'
            : 'Workspace invite rotated, but delivery failed.';
    } elseif ($action === 'revoke_invite') {
        $result = ['invite' => $service->revokeInvite($workspaceId, (int) ($_POST['invite_id'] ?? 0), $userId)];
        $message = 'Workspace invite revoked.';
    } elseif ($action === 'retry_invite_delivery') {
        $result = $service->retryInviteDelivery(
            $workspaceId,
            (int) ($_POST['invite_id'] ?? 0),
            $userId
        );
        $message = !empty($result['delivery']['success'])
            ? 'Workspace invite delivery retried successfully.'
            : 'Workspace invite delivery retry failed.';
    } elseif ($action === 'update_member_role') {
        $result = ['membership' => $service->updateMemberRole(
            $workspaceId,
            (int) ($_POST['membership_id'] ?? 0),
            $userId,
            (string) ($_POST['role_slug'] ?? 'viewer')
        )];
        $message = 'Workspace member role updated.';
    } elseif ($action === 'update_member_status') {
        $result = ['membership' => $service->setMemberStatus(
            $workspaceId,
            (int) ($_POST['membership_id'] ?? 0),
            $userId,
            (string) ($_POST['membership_status'] ?? 'active')
        )];
        $message = 'Workspace member status updated.';
    } elseif ($action === 'transfer_ownership') {
        $result = $service->transferOwnership(
            $workspaceId,
            $userId,
            (int) ($_POST['target_membership_id'] ?? 0),
            !empty($_POST['retain_actor_ownership']),
            (string) ($_POST['demoted_actor_role'] ?? 'admin')
        );
        $message = 'Workspace ownership updated.';
    } elseif ($action === 'set_primary_slug') {
        if (!Authorization::isSuperAdmin(Auth::user())) {
            throw new RuntimeException('Only Super Admin can update workspace slugs.');
        }
        $result = $service->setPrimaryWorkspaceSlug(
            $workspaceId,
            $userId,
            (string) ($_POST['workspace_slug'] ?? '')
        );
        $message = 'Workspace slug updated.';
    } else {
        throw new RuntimeException('Unsupported workspace governance action.');
    }

    $data = $service->getWorkspaceGovernanceData($workspaceId, $userId, (string) ($_POST['history_filter'] ?? ''));

    echo json_encode([
        'success' => true,
        'message' => $message,
        'result' => $result,
        'data' => [
            'workspace' => $data['workspace'] ?? null,
            'members' => $data['members'] ?? [],
            'pending_invites' => $data['pending_invites'] ?? [],
            'invites' => $data['invites'] ?? [],
            'slugs' => $data['slugs'] ?? [],
            'slug_history' => $data['slug_history'] ?? [],
            'history' => $data['history'] ?? [],
            'history_filter' => $data['history_filter'] ?? 'all',
            'history_filters' => $data['history_filters'] ?? ['all'],
            'capabilities' => $data['capabilities'] ?? [],
            'actor_membership' => $data['actor_membership'] ?? null,
            'pending_invite_count' => (int) ($data['pending_invite_count'] ?? 0),
        ],
    ]);
} catch (WorkspaceLaunchReadinessException $e) {
    http_response_code(503);
    echo json_encode([
        'error' => $e->getMessage(),
        'readiness' => $e->readiness(),
    ]);
} catch (WorkspaceLaunchThrottleException $e) {
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfter());
    echo json_encode([
        'error' => $e->getMessage(),
        'retry_after' => $e->retryAfter(),
    ]);
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
}
