<?php
/**
 * Form Submission AI Analysis API
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
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\AIFormRouter;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
// Load the user once so the active workspace context is initialized.
Auth::user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$submissionId = (int) ($input['submission_id'] ?? $_GET['submission_id'] ?? 0);
$action = $input['action'] ?? $_GET['action'] ?? 'analyze';
$csrfToken = (string) ($input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

if (!$submissionId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'submission_id required']);
    exit;
}

$submissionWhere = 'id = ?';
$submissionParams = [$submissionId];
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($activeWorkspaceId > 0 && Database::columnExists('form_submissions', 'workspace_id')) {
    $submissionWhere .= ' AND (workspace_id = ? OR workspace_id IS NULL)';
    $submissionParams[] = $activeWorkspaceId;
}

$sub = Database::queryOne("SELECT * FROM form_submissions WHERE {$submissionWhere}", $submissionParams);
if (!$sub) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Submission not found']);
    exit;
}

$formData = is_string($sub['form_data'] ?? '') ? json_decode($sub['form_data'], true) : ($sub['form_data'] ?? []);
$formData = is_array($formData) ? $formData : [];

try {
    $router = new AIFormRouter();
    $analysis = $router->analyzeSubmission($formData, (int) ($sub['form_definition_id'] ?? 0));

    if ($action === 'apply' && !empty($analysis['tags']) && $sub['contact_id']) {
        $contactId = (int) $sub['contact_id'];
        if (!(new Contacts())->getById($contactId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Linked contact is not accessible']);
            exit;
        }

        try {
            $router->saveAnalysis($submissionId, $analysis);
        } catch (\Throwable $e) { /* columns may not exist */ }
        $tagsModule = new \CRM\Modules\Tags();
        foreach ($analysis['tags'] as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;
            $tag = $tagsModule->getByName($tagName);
            if (!$tag) {
                $tagId = $tagsModule->create(['name' => $tagName]);
                $tag = ['id' => $tagId];
            } else {
                $tagId = (int) $tag['id'];
            }
            try {
                $tagsModule->assign($tagId, 'contact', $contactId);
            } catch (\Exception $e) { /* may already be assigned */ }
        }
        echo json_encode(['success' => true, 'applied' => true, 'tags' => $analysis['tags']]);
    } else {
        try {
            $router->saveAnalysis($submissionId, $analysis);
        } catch (\Throwable $e) {
            // Columns may not exist yet
        }
        echo json_encode([
            'success' => true,
            'tags' => $analysis['tags'],
            'suggested_stage' => $analysis['suggested_stage'],
            'follow_up_suggestion' => $analysis['follow_up_suggestion'],
        ]);
    }
} catch (\Exception $e) {
    error_log('Form submission analyze error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
