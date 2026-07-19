<?php
/**
 * Document Download Handler
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
    header('Location: login.php');
    exit;
}

$documentsModule = new Documents();
$docId = (int) ($_GET['id'] ?? 0);

if (!$docId) {
    http_response_code(404);
    die('Document not found');
}

$document = $documentsModule->getById($docId);

if (!$document || !file_exists($document['file_path'])) {
    http_response_code(404);
    die('Document not found');
}

if (!$documentsModule->canUserAccessDocumentRecord(Auth::user(), $document, 'read')) {
    http_response_code(403);
    die('Access denied');
}

$filePath = $documentsModule->resolveManagedFilePath((string) $document['file_path']);
if (!$filePath) {
    http_response_code(403);
    die('Access denied');
}

// Set headers for download
header('Content-Type: ' . ($document['mime_type'] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . Security::sanitizeHeaderFilename($document['original_name'] ?? null) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

// Output file
readfile($filePath);
exit;
