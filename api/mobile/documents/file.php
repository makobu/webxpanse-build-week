<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

use CRM\Modules\Documents;
use CRM\Security;

$auth = mobileRequireAuth();
$documentId = (int) ($_GET['id'] ?? 0);
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'download')));

if ($documentId <= 0) {
    mobileJson(['error' => 'Document not found.'], 404);
}

$documents = new Documents();
$document = $documents->getById($documentId);
if (!$document || !file_exists((string) ($document['file_path'] ?? ''))) {
    mobileJson(['error' => 'Document not found.'], 404);
}

$user = [
    'id' => (int) ($auth['user_id'] ?? 0),
    'role' => (string) ($auth['role'] ?? ''),
];

if (!$documents->canUserAccessDocumentRecord($user, $document, 'read')) {
    mobileJson(['error' => 'Document not accessible.'], 403);
}

$filePath = realpath((string) $document['file_path']);
$uploadDir = realpath(__DIR__ . '/../../../uploads/documents');
$uploadDirPrefix = $uploadDir ? rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : null;
if (!$filePath || !$uploadDirPrefix || strpos($filePath, $uploadDirPrefix) !== 0) {
    mobileJson(['error' => 'Document access denied.'], 403);
}

$mimeType = (string) ($document['mime_type'] ?? 'application/octet-stream');
$disposition = ($mode === 'preview' && $documents->canPreview($mimeType)) ? 'inline' : 'attachment';
$fileName = Security::sanitizeHeaderFilename($document['original_name'] ?? null);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
