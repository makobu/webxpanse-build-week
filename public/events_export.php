<?php
/**
 * Export Events to CSV
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\Events;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$eventsModule = new Events();

// Get filters
$eventType = $_GET['event_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$hasAssignedToParam = array_key_exists('assigned_to', $_GET);
$assignedTo = null;
if (!$hasAssignedToParam) {
    $assignedTo = (int) ($user['id'] ?? 0);
} elseif ($eventsModule->canViewAll($user) && ($_GET['assigned_to'] ?? '') === '') {
    $assignedTo = null;
} elseif ($eventsModule->canViewAll($user) && ctype_digit((string) ($_GET['assigned_to'] ?? ''))) {
    $assignedTo = (int) $_GET['assigned_to'];
} else {
    $assignedTo = (int) ($user['id'] ?? 0);
}

$filters = ['assigned_to' => $assignedTo];
if ($eventType !== '') {
    $filters['event_type'] = $eventType;
}
if ($dateFrom !== '') {
    $filters['start_date'] = $dateFrom;
}
if ($dateTo !== '') {
    $filters['end_date'] = $dateTo;
}

$events = $eventsModule->getAllForUser($filters, $user, 5000, 0, 'DESC');

// Generate CSV
$filename = 'events_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, ['ID', 'Title', 'Description', 'Contact', 'Event Type', 'Start Time', 'End Time', 'Location', 'Assigned To', 'Created At']);

// Data
foreach ($events as $event) {
    $contactName = trim(($event['first_name'] ?? '') . ' ' . ($event['last_name'] ?? ''));
    fputcsv($output, [
        $event['id'],
        $event['title'] ?? '-',
        $event['description'] ?? '-',
        $contactName ?: '-',
        $event['event_type'] ?? '-',
        $event['start_time'] ?? '-',
        $event['end_time'] ?? '-',
        $event['location'] ?? '-',
        $event['assigned_to_email'] ?? '-',
        $event['created_at'] ?? '-'
    ]);
}

fclose($output);
exit;
