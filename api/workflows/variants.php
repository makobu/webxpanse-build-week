<?php
/**
 * Workflow Variants API (A/B Testing)
 * List, create, update variants for a workflow
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
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('workflows.manage', true);

$workflowId = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : null;
if (!$workflowId) {
    http_response_code(400);
    echo json_encode(['error' => 'workflow_id required']);
    exit;
}

try {
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $workflow = Database::queryOne(
        "SELECT id FROM workflows WHERE workspace_id = ? AND id = ? LIMIT 1",
        [$workspaceId, $workflowId]
    );
    if (!$workflow) {
        http_response_code(404);
        echo json_encode(['error' => 'Workflow not found']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $variants = Database::query(
            "SELECT id, name, variant_key, actions, traffic_percent, is_control FROM workflow_variants WHERE workflow_id = ? ORDER BY id ASC",
            [$workflowId]
        );
        foreach ($variants as &$v) {
            $v['actions'] = json_decode($v['actions'], true) ?? [];
        }
        echo json_encode(['success' => true, 'variants' => $variants]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $name = $input['name'] ?? 'Variant';
        $variantKey = $input['variant_key'] ?? 'B';
        $actions = $input['actions'] ?? [];
        $trafficPercent = (int)($input['traffic_percent'] ?? 50);
        $isControl = !empty($input['is_control']);

        Database::execute(
            "INSERT INTO workflow_variants (workflow_id, name, variant_key, actions, traffic_percent, is_control) VALUES (?, ?, ?, ?, ?, ?)",
            [$workflowId, $name, $variantKey, json_encode($actions), $trafficPercent, $isControl ? 1 : 0]
        );
        echo json_encode(['success' => true, 'variant_id' => (int)Database::lastInsertId()]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
