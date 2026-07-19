<?php
/**
 * Calendar Export API (iCal format)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\CalendarService;
use CRM\Services\PresentationWorkspaceGuardService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canViewAllEvents = Authorization::can('events.view_all', $user);

$presentationGuard = new PresentationWorkspaceGuardService();
if ($presentationGuard->isBlocked('exports')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => $presentationGuard->message('exports')]);
    exit;
}

$calendarService = new CalendarService();

// Get event IDs from query string (optional)
$eventIds = null;
if (!empty($_GET['events'])) {
    $eventIds = array_map('intval', explode(',', $_GET['events']));
}

$hasAssignedToParam = array_key_exists('assigned_to', $_GET);
$assignedTo = null;
if (!$hasAssignedToParam) {
    $assignedTo = $userId > 0 ? $userId : null;
} elseif ($canViewAllEvents && ($_GET['assigned_to'] ?? '') === '') {
    $assignedTo = null;
} elseif ($canViewAllEvents && ctype_digit((string) ($_GET['assigned_to'] ?? ''))) {
    $assignedTo = (int) $_GET['assigned_to'];
} else {
    $assignedTo = $userId > 0 ? $userId : null;
}

$filters = ['assigned_to' => $assignedTo];
if (!empty($_GET['event_type'])) {
    $filters['event_type'] = (string) $_GET['event_type'];
}
if (!empty($_GET['date_from'])) {
    $filters['start_date'] = (string) $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['end_date'] = (string) $_GET['date_to'];
}

// Export to iCal
$ical = $calendarService->exportToICal($eventIds, $user, $filters);

// Set headers for download
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="crm-events-' . date('Y-m-d') . '.ics"');
header('Content-Length: ' . strlen($ical));

echo $ical;
