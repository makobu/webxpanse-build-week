<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
use CRM\Session;
use CRM\Services\MeetingAvailabilityService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = (string) ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$actorUserId = (int) ($user['id'] ?? 0);
$action = (string) ($input['action'] ?? 'get_setup');
$service = new MeetingAvailabilityService();
$canRead = Authorization::isSuperAdmin($user)
    || Authorization::can('meeting_bookings.view', $user)
    || Authorization::can('meeting_bookings.manage', $user)
    || Authorization::can('meeting_availability.manage', $user)
    || Authorization::can('settings.calendar', $user)
    || Authorization::can('workspace.skills.manage', $user);
$canMutate = Authorization::isSuperAdmin($user)
    || Authorization::can('meeting_availability.manage', $user)
    || Authorization::can('settings.calendar', $user)
    || Authorization::can('workspace.skills.manage', $user);

if (!$canRead) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    if ($action === 'get_setup') {
        echo json_encode([
            'success' => true,
            'setup' => $service->getAvailabilitySetup($workspaceId, (int) ($input['profile_id'] ?? 0) ?: null, $actorUserId),
        ]);
        exit;
    }

    if (!$canMutate) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage meeting availability.']);
        exit;
    }

    if ($action === 'save_profile') {
        $profile = $service->saveBookingProfile($workspaceId, $actorUserId, (array) ($input['profile'] ?? $input));
        echo json_encode([
            'success' => true,
            'profile' => $profile,
            'setup' => $service->getAvailabilitySetup($workspaceId, (int) $profile['id'], $actorUserId),
        ]);
        exit;
    }

    if ($action === 'save_availability_sources') {
        $profileId = (int) ($input['profile_id'] ?? ($input['profile']['profile_id'] ?? 0));
        $result = $service->saveAvailabilitySources($workspaceId, $actorUserId, $profileId, (array) ($input['sources'] ?? $input['profile'] ?? $input));
        echo json_encode([
            'success' => true,
            'profile' => $result['profile'],
            'host_calendar_readiness' => $result['host_calendar_readiness'],
            'setup' => $service->getAvailabilitySetup($workspaceId, (int) ($result['profile']['id'] ?? $profileId), $actorUserId),
        ]);
        exit;
    }

    if ($action === 'save_round_robin_hosts') {
        $profileId = (int) ($input['profile_id'] ?? ($input['profile']['profile_id'] ?? 0));
        $hosts = $service->saveRoundRobinHosts($workspaceId, $actorUserId, $profileId, (array) ($input['hosts'] ?? $input));
        echo json_encode([
            'success' => true,
            'profile_hosts' => $hosts,
            'team_calendar_readiness' => $service->teamCalendarReadiness($workspaceId, $profileId),
            'setup' => $service->getAvailabilitySetup($workspaceId, $profileId, $actorUserId),
        ]);
        exit;
    }

    if ($action === 'save_availability') {
        $profileId = (int) ($input['profile_id'] ?? 0);
        $profile = $service->getProfile($profileId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new RuntimeException('Booking profile not found.');
        }
        $windows = $service->saveAvailabilityWindows($profileId, $actorUserId, (array) ($input['windows'] ?? []));
        echo json_encode([
            'success' => true,
            'availability_windows' => $windows,
            'setup' => $service->getAvailabilitySetup($workspaceId, $profileId, $actorUserId),
        ]);
        exit;
    }

    if ($action === 'create_blocked_time') {
        $blockedTime = $service->createBlockedTime($workspaceId, $actorUserId, (array) ($input['blocked_time'] ?? $input));
        $profileId = (int) ($input['profile_id'] ?? ($blockedTime['profile_id'] ?? 0));
        echo json_encode([
            'success' => true,
            'blocked_time' => $blockedTime,
            'blocked_times' => $service->listBlockedTimes($workspaceId, $profileId > 0 ? $profileId : null),
        ]);
        exit;
    }

    if ($action === 'delete_blocked_time') {
        $profileId = (int) ($input['profile_id'] ?? 0);
        $deleted = $service->deleteBlockedTime($workspaceId, $actorUserId, (int) ($input['blocked_time_id'] ?? 0));
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
            'blocked_times' => $service->listBlockedTimes($workspaceId, $profileId > 0 ? $profileId : null),
        ]);
        exit;
    }

    throw new RuntimeException('Unsupported meeting settings action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
