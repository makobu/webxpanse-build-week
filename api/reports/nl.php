<?php
/**
 * Natural Language Report API
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
use CRM\Authorization;
use CRM\Modules\NLReportGenerator;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('reports.nl_generate', true);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$question = trim($input['question'] ?? $_GET['question'] ?? '');

if (empty($question)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'question required']);
    exit;
}

try {
    $generator = new NLReportGenerator();
    $result = $generator->generate($question, ['user' => Auth::user()]);
    echo json_encode([
        'success' => true,
        'answer' => $result['answer'],
        'report_type' => $result['report_type'] ?? null,
        'suggested_query' => $result['suggested_query'] ?? null,
    ]);
} catch (\Exception $e) {
    error_log('NL report API error: ' . $e->getMessage());
    $message = trim($e->getMessage());
    $status = stripos($message, 'workspace') !== false ? 422 : 500;
    $error = stripos($message, 'workspace') !== false
        ? 'An active workspace is required to generate this report.'
        : 'Failed to generate report.';
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $error]);
}
