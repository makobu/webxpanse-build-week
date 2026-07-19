<?php
/**
 * Document Version Upload Handler
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
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Documents;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$documentsModule = new Documents();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

try {
    $documentId = (int) ($_POST['document_id'] ?? 0);
    $description = $_POST['description'] ?? '';
    
    if (!$documentId) {
        throw new \Exception("Document ID is required");
    }

    if (!$documentsModule->canUserAccessDocument($user, $documentId, 'write')) {
        http_response_code(403);
        throw new \Exception("You do not have permission to upload a new version for this document");
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception("File upload failed");
    }
    
    $versionId = $documentsModule->uploadVersion(
        $documentId,
        $_FILES['file'],
        [
            'description' => $description,
            'uploaded_by' => $userId,
            'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
        ]
    );
    
    $document = $documentsModule->getById($documentId);
    $versionHistory = $documentsModule->getVersionHistory($documentId);
    
    echo json_encode([
        'success' => true,
        'document' => [
            'id' => $document['id'],
            'current_version' => $document['current_version'] ?? 1,
            'file_name' => $document['original_name'],
            'file_size' => $documentsModule->formatFileSize($document['file_size']),
            'mime_type' => $document['mime_type'],
            'lock_version' => (int) ($document['lock_version'] ?? 0),
            'version_count' => count($versionHistory)
        ]
    ]);
} catch (ConcurrencyConflictException $e) {
    http_response_code(409);
    $payload = Concurrency::conflictPayload($e);
    $payload['error'] = $payload['message'];
    echo json_encode($payload);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
