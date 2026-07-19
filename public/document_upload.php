<?php
/**
 * Document Upload Handler
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
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int) ($_POST['entity_id'] ?? 0);
    $description = $_POST['description'] ?? '';
    $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
    
    if (empty($entityType) || !$entityId) {
        throw new \Exception("Entity type and ID are required");
    }

    if (!$documentsModule->canUserAccessEntity($user, (string) $entityType, $entityId, 'write')) {
        http_response_code(403);
        throw new \Exception("You do not have permission to attach documents to this record");
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new \Exception("File upload failed");
    }
    
    $docId = $documentsModule->upload(
        $_FILES['file'],
        $entityType,
        $entityId,
        [
            'description' => $description,
            'category_id' => $categoryId,
            'uploaded_by' => $userId
        ]
    );
    
    $document = $documentsModule->getById($docId);
    
    echo json_encode([
        'success' => true,
        'document' => [
            'id' => $document['id'],
            'file_name' => $document['original_name'],
            'file_size' => $documentsModule->formatFileSize($document['file_size']),
            'mime_type' => $document['mime_type'],
            'icon' => $documentsModule->getFileIcon($document['mime_type']),
            'category_name' => $document['category_name'] ?? null,
            'category_color' => $document['category_color'] ?? null,
            'can_preview' => $documentsModule->canPreview($document['mime_type']),
            'preview_url' => $documentsModule->canPreview($document['mime_type']) ? $documentsModule->getPreviewUrl($document['id']) : null,
            'uploaded_by' => $document['uploaded_by_email'],
            'created_at' => $document['created_at']
        ]
    ]);
} catch (\PDOException $e) {
    error_log('Document upload database failure: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The document could not be uploaded.']);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Document upload failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The document could not be uploaded.']);
}
