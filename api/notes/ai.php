<?php
/**
 * Notes AI API
 * Summarize notes, extract action items
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
use CRM\Modules\AINoteProcessor;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'summarize') {
    $noteIds = $input['note_ids'] ?? [];
    if (!is_array($noteIds)) {
        $noteIds = array_filter(array_map('intval', explode(',', (string) $noteIds)));
    } else {
        $noteIds = array_map('intval', $noteIds);
    }
    if (empty($noteIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'note_ids required']);
        exit;
    }
    try {
        $processor = new AINoteProcessor();
        $summary = $processor->summarizeNotes($noteIds);
        echo json_encode(['success' => true, 'summary' => $summary]);
    } catch (\Exception $e) {
        error_log('Notes AI summarize error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'extract_actions') {
    $content = $input['content'] ?? '';
    if (empty(trim($content))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'content required']);
        exit;
    }
    try {
        $processor = new AINoteProcessor();
        $actions = $processor->extractActionItems($content);
        echo json_encode(['success' => true, 'actions' => $actions]);
    } catch (\Exception $e) {
        error_log('Notes AI extract_actions error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action required: summarize or extract_actions']);
}
