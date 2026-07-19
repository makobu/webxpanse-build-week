<?php
/**
 * Document Version Download Handler
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
use CRM\Security;
use CRM\Modules\Documents;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    http_response_code(403);
    die('Access denied');
}

$documentsModule = new Documents();
$documentId = (int) ($_GET['document_id'] ?? 0);
$versionNumber = (int) ($_GET['version'] ?? 0);

if (!$documentId || !$versionNumber) {
    http_response_code(400);
    die('Document ID and version number are required');
}

$version = $documentsModule->getVersion($documentId, $versionNumber);

if (!$version || !file_exists($version['file_path'])) {
    http_response_code(404);
    die('Version not found');
}

$document = $documentsModule->getById($documentId);
if (!$document || !$documentsModule->canUserAccessDocumentRecord(Auth::user(), $document, 'read')) {
    http_response_code(403);
    die('Access denied');
}

// Security: Verify file is within upload directory
$filePath = $documentsModule->resolveManagedFilePath((string) $version['file_path']);
if (!$filePath) {
    http_response_code(403);
    die('Access denied');
}

// Set headers for download
header('Content-Type: ' . ($version['mime_type'] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . Security::sanitizeHeaderFilename($version['original_name'] ?? null) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
