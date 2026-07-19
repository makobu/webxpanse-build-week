<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('settings.email_assistant', true);

$mode = trim((string) ($_GET['mode'] ?? ''));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required.']);
    exit;
}

$sql = "SELECT * FROM email_assistant_runs";
$params = [$workspaceId];
$where = ["workspace_id = ?"];
if ($mode !== '') {
    $where[] = "mode = ?";
    $params[] = $mode;
}
if (Database::columnExists('email_assistant_runs', 'assistant_type')) {
    $where[] = "assistant_type = ?";
    $params[] = 'email';
}
$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit}";

echo json_encode([
    'success' => true,
    'runs' => Database::query($sql, $params),
]);
