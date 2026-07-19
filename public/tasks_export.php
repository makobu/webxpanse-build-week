<?php
/**
 * Export Tasks to CSV
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
use CRM\Authorization;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('tasks.read');

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceScope = new WorkspaceScopeService();
$workspaceId = $workspaceScope->requireActiveWorkspaceId();
$presentationGuard = new PresentationWorkspaceGuardService();
if ($presentationGuard->isBlocked('exports', $workspaceId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $presentationGuard->message('exports');
    exit;
}

// Get filters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$assignedTo = !empty($_GET['assigned_to']) ? (int) $_GET['assigned_to'] : null;
if ($assignedTo !== null && $assignedTo !== $userId && !Authorization::can('tasks.view_all', $user)) {
    $assignedTo = $userId;
} elseif ($assignedTo === null && !Authorization::can('tasks.view_all', $user)) {
    $assignedTo = $userId;
}

// Build query
$workspace = $workspaceScope->workspaceClause('t.');
$where = [$workspace['sql']];
$params = $workspace['params'];

if ($status) {
    $where[] = "t.status = ?";
    $params[] = $status;
}

if ($priority) {
    $where[] = "t.priority = ?";
    $params[] = $priority;
}

if ($assignedTo) {
    $where[] = "t.assigned_to = ?";
    $params[] = $assignedTo;
}

$sql = "SELECT t.*, c.first_name, c.last_name, c.email as contact_email, 
               u1.email as assigned_to_email, u2.email as created_by_email
        FROM tasks t
        LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
        LEFT JOIN users u1 ON t.assigned_to = u1.id
        LEFT JOIN users u2 ON t.created_by = u2.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.created_at DESC";

$tasks = Database::query($sql, $params);

// Generate CSV
$filename = 'tasks_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, ['ID', 'Title', 'Description', 'Contact', 'Status', 'Priority', 'Assigned To', 'Due Date', 'Created At']);

// Data
foreach ($tasks as $task) {
    $contactName = trim(($task['first_name'] ?? '') . ' ' . ($task['last_name'] ?? ''));
    fputcsv($output, [
        $task['id'],
        $task['title'] ?? '-',
        $task['description'] ?? '-',
        $contactName ?: '-',
        $task['status'] ?? '-',
        $task['priority'] ?? '-',
        $task['assigned_to_email'] ?? '-',
        $task['due_date'] ?? '-',
        $task['created_at'] ?? '-'
    ]);
}

fclose($output);
exit;
