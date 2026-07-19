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
use CRM\Services\MeetingBookingService;
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
$action = (string) ($input['action'] ?? 'list');
$service = new MeetingBookingService();

try {
    if ($action === 'list') {
        if (!Authorization::can('meeting_bookings.view', $user) && !Authorization::can('meeting_bookings.manage', $user)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'bookings' => $service->listBookings($workspaceId, [
                'status' => (string) ($input['status'] ?? ''),
            ]),
        ]);
        exit;
    }

    Authorization::requirePermission('meeting_bookings.manage', true);
    $bookingId = (int) ($input['booking_id'] ?? 0);
    $actorUserId = (int) ($user['id'] ?? 0);
    $note = trim((string) ($input['note'] ?? '')) ?: null;

    $result = match ($action) {
        'approve' => $service->approveBooking($bookingId, $actorUserId, $note, $workspaceId),
        'decline' => $service->updateStatus($bookingId, 'declined', $actorUserId, $note, $workspaceId),
        'cancel' => $service->updateStatus($bookingId, 'cancelled', $actorUserId, $note, $workspaceId),
        'complete' => $service->updateStatus($bookingId, 'completed', $actorUserId, $note, $workspaceId),
        'reassign_host' => $service->reassignHost($bookingId, (int) ($input['host_user_id'] ?? 0), $actorUserId, $note, $workspaceId),
        default => throw new RuntimeException('Unsupported booking action.'),
    };

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(str_contains($e->getMessage(), 'not found') ? 404 : 400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
