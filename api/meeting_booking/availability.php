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

use CRM\Database;
use CRM\Services\CalendarShareService;
use CRM\Services\MeetingAvailabilityService;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

try {
    $service = new MeetingAvailabilityService();
    $shareToken = trim((string) ($_GET['share'] ?? $_GET['share_token'] ?? ''));
    $workspaceSlug = (string) ($_GET['workspace'] ?? '');
    $profileSlug = (string) ($_GET['profile'] ?? 'default');
    $calendarShare = $shareToken !== ''
        ? (new CalendarShareService($service))->getPublicShareByToken($shareToken, false)
        : null;
    if ($shareToken !== '' && !$calendarShare) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Calendar invitation not found or no longer available.']);
        exit;
    }
    $profile = $calendarShare
        ? $service->getProfile((int) ($calendarShare['profile_id'] ?? 0))
        : $service->findPublicProfile($workspaceSlug, $profileSlug);
    if (!$profile) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Booking profile not found.']);
        exit;
    }

    $timezone = $service->normalizeTimezone((string) ($_GET['timezone'] ?? date_default_timezone_get()));
    $duration = $service->normalizeDuration(
        $calendarShare
            ? (int) ($calendarShare['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30)
            : (int) ($_GET['duration'] ?? $profile['default_duration_minutes'] ?? 30),
        $profile
    );
    $from = (string) ($_GET['from'] ?? date('Y-m-d'));
    $to = (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+14 days')));
    if ($calendarShare) {
        $shareFrom = (string) ($calendarShare['date_from'] ?? '');
        $shareTo = (string) ($calendarShare['date_to'] ?? '');
        if ($shareFrom !== '' && $from < $shareFrom) {
            $from = $shareFrom;
        }
        if ($shareTo !== '' && $to > $shareTo) {
            $to = $shareTo;
        }
        if ($to < $from) {
            $slots = [];
        }
    }
    $slots = $slots ?? $service->getSlots((int) $profile['id'], $from, $to, $timezone, $duration);

    echo json_encode([
        'success' => true,
        'profile' => [
            'id' => (int) $profile['id'],
            'workspace_slug' => (string) ($profile['workspace_slug'] ?? $workspaceSlug),
            'slug' => (string) $profile['slug'],
            'title' => (string) $profile['title'],
            'description' => (string) ($profile['description'] ?? ''),
            'timezone' => (string) $profile['timezone'],
            'default_duration_minutes' => (int) $profile['default_duration_minutes'],
            'allowed_durations' => array_values((array) $profile['allowed_durations']),
            'allowed_meeting_formats' => array_values((array) $profile['allowed_meeting_formats']),
        ],
        'timezone' => $timezone,
        'duration_minutes' => $duration,
        'share_constraints' => $calendarShare ? [
            'duration_minutes' => $duration,
            'date_from' => (string) ($calendarShare['date_from'] ?? ''),
            'date_to' => (string) ($calendarShare['date_to'] ?? ''),
        ] : null,
        'slots' => $slots,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
