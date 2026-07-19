<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Authorization;
use CRM\Services\MeetingBookingService;

function mobileBookingStatusForFilter(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'confirmed', 'completed', 'declined', 'cancelled'], true) ? $status : '';
}

function mobileBookingAllowedActions(string $status, bool $canManage): array
{
    if (!$canManage) {
        return [];
    }

    return match (strtolower(trim($status))) {
        'pending' => ['approve', 'decline', 'cancel'],
        'confirmed' => ['cancel', 'complete'],
        default => [],
    };
}

function mobileBookingForUser(array $booking, bool $canManage): array
{
    $item = mobileMeetingBookingSummary($booking);
    $item['allowed_actions'] = mobileBookingAllowedActions((string) ($item['status'] ?? ''), $canManage);
    return $item;
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);

mobileRequireCalendarMeetingsRuntime($workspaceId, $user);
mobileRequireAnyPermission($user, ['meeting_bookings.view', 'meeting_bookings.manage'], 'Meeting bookings are not available to this user.');

$service = new MeetingBookingService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$canManageBookings = Authorization::isSuperAdmin($user) || mobileCanAny($user, ['meeting_bookings.manage']);

try {
    if ($method === 'POST') {
        mobileRequireAnyPermission($user, ['meeting_bookings.manage'], 'Meeting booking actions are not available to this user.');
        $input = mobileRequestBody();
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $bookingId = (int) ($input['booking_id'] ?? $input['id'] ?? 0);
        $note = trim((string) ($input['note'] ?? '')) ?: null;
        $actorUserId = (int) ($auth['user_id'] ?? 0);
        if ($bookingId <= 0) {
            mobileJson(['error' => 'booking_id is required.'], 422);
        }

        $booking = $service->getBooking($bookingId, $workspaceId);
        if (!$booking) {
            mobileJson(['error' => 'Booking not found.'], 404);
        }
        if (!in_array($action, mobileBookingAllowedActions((string) ($booking['status'] ?? ''), $canManageBookings), true)) {
            mobileJson([
                'error' => 'This action is not available for the booking status.',
                'error_code' => 'booking_action_not_allowed',
            ], 409);
        }

        $result = match ($action) {
            'approve' => $service->approveBooking($bookingId, $actorUserId, $note, $workspaceId),
            'decline' => $service->updateStatus($bookingId, 'declined', $actorUserId, $note, $workspaceId),
            'cancel' => $service->updateStatus($bookingId, 'cancelled', $actorUserId, $note, $workspaceId),
            'complete' => $service->updateStatus($bookingId, 'completed', $actorUserId, $note, $workspaceId),
            default => throw new RuntimeException('Unsupported booking action.'),
        };

        mobileJson([
            'success' => true,
            'data' => $result + [
                'booking' => mobileBookingForUser($service->getBooking($bookingId, $workspaceId) ?? [], $canManageBookings),
            ],
        ]);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 50, 100);
    $offset = mobileBoundedOffset($_GET['offset'] ?? 0);
    $status = mobileBookingStatusForFilter((string) ($_GET['status'] ?? ''));
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));
    if (strtolower((string) ($_GET['queue'] ?? '')) === 'today' || strtolower((string) ($_GET['status'] ?? '')) === 'today') {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        $status = '';
    }
    if (strtolower((string) ($_GET['status'] ?? '')) === 'needs_review') {
        $status = 'pending';
    }

    $rows = $service->listBookings($workspaceId, [
        'status' => $status,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'offset' => $offset,
    ], $limit);
    $items = array_map(
        static fn(array $booking): array => mobileBookingForUser($booking, $canManageBookings),
        $rows
    );

    mobileJson([
        'success' => true,
        'data' => [
            'items' => $items,
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'meeting_booking_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
