<?php
/**
 * Document Version Restore Handler
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

try {
    $documentId = (int) ($_POST['document_id'] ?? 0);
    $versionNumber = (int) ($_POST['version'] ?? 0);
    
    if (!$documentId || !$versionNumber) {
        throw new \Exception("Document ID and version number are required");
    }
    
    // Check permissions (user must be admin or document owner)
    $document = $documentsModule->getById($documentId);
    if (!$document) {
        throw new \Exception("Document not found");
    }
    
    if (!$documentsModule->canUserAccessDocumentRecord($user, $document, 'write')) {
        throw new \Exception("You don't have permission to restore this document");
    }
    
    $documentsModule->restoreVersion(
        $documentId,
        $versionNumber,
        Concurrency::expectedVersionFromData($_POST)
    );
    
    $updatedDocument = $documentsModule->getById($documentId);
    
    echo json_encode([
        'success' => true,
        'document' => [
            'id' => $updatedDocument['id'],
            'current_version' => $updatedDocument['current_version'] ?? 1,
            'file_name' => $updatedDocument['original_name'],
            'lock_version' => (int) ($updatedDocument['lock_version'] ?? 0),
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
