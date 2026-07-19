<?php
/**
 * Document AI Extraction API
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AIDocumentExtractor;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$documentId = (int) ($input['document_id'] ?? $_GET['document_id'] ?? 0);

if (!$documentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'document_id required']);
    exit;
}

try {
    $extractor = new AIDocumentExtractor();
    $result = $extractor->extractFromDocument($documentId);
    echo json_encode([
        'success' => true,
        'entities' => $result['entities'],
        'suggested_tags' => $result['suggested_tags'],
        'summary' => $result['summary'],
    ]);
} catch (\Exception $e) {
    error_log('Document extract API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
