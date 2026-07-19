<?php
/**
 * Document Preview Page
 * 
 * Displays preview of images and PDFs
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
$documentId = (int) ($_GET['id'] ?? 0);

if (!$documentId) {
    http_response_code(404);
    die('Document not found');
}

$document = $documentsModule->getById($documentId);

if (!$document || !file_exists($document['file_path'])) {
    http_response_code(404);
    die('Document not found');
}

if (!$documentsModule->canUserAccessDocumentRecord(Auth::user(), $document, 'read')) {
    http_response_code(403);
    die('Access denied');
}

// Check if file can be previewed
if (!$documentsModule->canPreview($document['mime_type'])) {
    http_response_code(400);
    die('Preview not available for this file type');
}

// Security: Verify file is within upload directory
$filePath = $documentsModule->resolveManagedFilePath((string) $document['file_path']);
if (!$filePath) {
    http_response_code(403);
    die('Access denied');
}

// Set appropriate headers
$mimeType = $document['mime_type'] ?? mime_content_type($filePath);

if (strpos($mimeType, 'image/') === 0) {
    // Image preview
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . Security::sanitizeHeaderFilename($document['original_name'] ?? null) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
} elseif (strpos($mimeType, 'pdf') !== false || $mimeType === 'application/pdf') {
    // PDF preview
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . Security::sanitizeHeaderFilename($document['original_name'] ?? null) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
} else {
    http_response_code(400);
    die('Preview not available for this file type');
}
