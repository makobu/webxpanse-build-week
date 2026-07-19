<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Services\CalendarShareService;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);

mobileRequireCalendarMeetingsRuntime($workspaceId, $user);
mobileRequireAnyPermission(
    $user,
    ['meeting_bookings.view', 'meeting_bookings.manage', 'meeting_availability.manage', 'settings.calendar'],
    'Calendar sharing is not available to this user.'
);

$service = new CalendarShareService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$actorUserId = (int) ($auth['user_id'] ?? 0);

try {
    if ($method === 'POST') {
        $input = mobileRequestBody();
        $action = strtolower(trim((string) ($input['action'] ?? 'create_share')));

        if ($action === 'create_share') {
            $payload = array_merge([
                'duration_minutes' => 30,
                'expires_in_days' => 30,
                'suggested_slot_count' => 3,
                'date_from' => date('Y-m-d'),
                'date_to' => (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d'),
            ], $input);
            $share = $service->createOrUpdateShare($workspaceId, $actorUserId, $payload);
            mobileJson([
                'success' => true,
                'data' => [
                    'share' => mobileCalendarShareSummary($share),
                ],
            ]);
        }

        if ($action === 'send_share_email') {
            mobileRequireAnyPermission(
                $user,
                ['meeting_bookings.manage', 'meeting_availability.manage', 'settings.calendar'],
                'Sending calendar share email is not available to this user.'
            );
            $shareId = (int) ($input['share_id'] ?? $input['id'] ?? 0);
            if ($shareId <= 0) {
                mobileJson(['error' => 'share_id is required.'], 422);
            }
            $subject = trim((string) ($input['subject'] ?? ''));
            $bodyText = trim((string) ($input['body_text'] ?? $input['body'] ?? ''));
            $bodyHtml = trim((string) ($input['body_html'] ?? ''));
            if ($subject === '' || ($bodyText === '' && $bodyHtml === '')) {
                $draft = $service->draftForShare($workspaceId, $actorUserId, $shareId, false);
                $subject = (string) ($draft['draft']['subject'] ?? $subject);
                $bodyText = (string) ($draft['draft']['body_text'] ?? $bodyText);
                $bodyHtml = (string) ($draft['draft']['body_html'] ?? $bodyHtml);
            }
            $result = $service->sendShareEmail($workspaceId, $actorUserId, $shareId, $subject, $bodyText, $bodyHtml);
            mobileJson([
                'success' => !empty($result['success']),
                'data' => [
                    'share' => mobileCalendarShareSummary((array) ($result['share'] ?? [])),
                    'send_result' => $result,
                ],
            ], empty($result['success']) ? 422 : 200);
        }

        if ($action === 'copy_logged') {
            try {
                $share = $service->recordCopyAction(
                    $workspaceId,
                    $actorUserId,
                    (int) ($input['share_id'] ?? $input['id'] ?? 0),
                    (string) ($input['copy_type'] ?? 'link')
                );
            } catch (Throwable $auditError) {
                mobileJson([
                    'success' => true,
                    'data' => [
                        'audit_logged' => false,
                        'audit_error' => 'The link was copied, but the audit event could not be saved.',
                    ],
                ]);
            }
            mobileJson([
                'success' => true,
                'data' => [
                    'audit_logged' => true,
                    'share' => mobileCalendarShareSummary($share),
                ],
            ]);
        }

        mobileJson(['error' => 'Unsupported action.'], 422);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 30, 100);
    $offset = mobileBoundedOffset($_GET['offset'] ?? 0);
    $items = array_map(
        'mobileCalendarShareSummary',
        $service->listShares($workspaceId, [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'contact_id' => !empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : null,
            'deal_id' => !empty($_GET['deal_id']) ? (int) $_GET['deal_id'] : null,
        ], $limit, $offset)
    );

    mobileJson([
        'success' => true,
        'data' => [
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'calendar_share_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
