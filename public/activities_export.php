<?php
/**
 * Export Activities to CSV
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
use CRM\Modules\Activities;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$presentationGuard = new PresentationWorkspaceGuardService();
if ($presentationGuard->isBlocked('exports', $workspaceId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $presentationGuard->message('exports');
    exit;
}

// Get filters
$contactId = !empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : null;
$activityType = trim((string) ($_GET['type'] ?? $_GET['activity_type'] ?? ''));
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

if ($activityType !== '') {
    try {
        $activityType = Activities::normalizeActivityType($activityType);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid activity type.';
        exit;
    }
}

// Build query
$where = ["a.workspace_id = ?"];
$params = [$workspaceId];

if ($contactId) {
    $where[] = "a.contact_id = ?";
    $params[] = $contactId;
}

if ($activityType) {
    $where[] = "a.activity_type = ?";
    $params[] = $activityType;
}

if ($dateFrom) {
    $where[] = "DATE(a.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "DATE(a.created_at) <= ?";
    $params[] = $dateTo;
}

$sql = "SELECT a.*, c.first_name, c.last_name, c.email, u.email as user_email
        FROM activities a
        JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
        LEFT JOIN users u ON a.user_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY a.created_at DESC";

$activities = Database::query($sql, $params);

// Generate CSV
$filename = 'activities_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, ['ID', 'Contact', 'Email', 'Activity Type', 'Description', 'User', 'Created At']);

// Data
foreach ($activities as $activity) {
    $contactName = trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? ''));
    fputcsv($output, [
        $activity['id'],
        $contactName ?: '-',
        $activity['email'] ?? '-',
        $activity['activity_type'] ?? '-',
        $activity['description'] ?? '-',
        $activity['user_email'] ?? '-',
        $activity['created_at'] ?? '-'
    ]);
}

fclose($output);
exit;
