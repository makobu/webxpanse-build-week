<?php
/**
 * Export Emails to CSV
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

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Get filters
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$where = [];
$params = [];

if ($status) {
    $where[] = "e.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $where[] = "DATE(e.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = "DATE(e.created_at) <= ?";
    $params[] = $dateTo;
}

$sql = "SELECT e.*, c.first_name, c.last_name, c.email as contact_email
        FROM emails e
        LEFT JOIN contacts c ON e.contact_id = c.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY e.created_at DESC";

$emails = Database::query($sql, $params);

// Generate CSV
$filename = 'emails_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, ['ID', 'Subject', 'To Email', 'Contact', 'Status', 'Opened', 'Clicked', 'Created At']);

// Data
foreach ($emails as $email) {
    $contactName = trim(($email['first_name'] ?? '') . ' ' . ($email['last_name'] ?? ''));
    fputcsv($output, [
        $email['id'],
        $email['subject'] ?? '-',
        $email['to_email'] ?? '-',
        $contactName ?: '-',
        $email['status'] ?? '-',
        $email['opened_at'] ? 'Yes' : 'No',
        $email['clicked_at'] ? 'Yes' : 'No',
        $email['created_at'] ?? '-'
    ]);
}

fclose($output);
exit;
