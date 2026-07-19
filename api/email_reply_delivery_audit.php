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
require_once __DIR__ . '/../includes/helpers.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\EmailIntegrationService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user() ?? [];
if (!Authorization::can('admin.audit_logs.view', $user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$communicationId = (int) ($_GET['communication_id'] ?? 0);
$emailId = (int) ($_GET['email_id'] ?? 0);
$where = [];
$params = [];

if ($communicationId > 0) {
    $where[] = 'source_communication_id = ?';
    $params[] = $communicationId;
}
if ($emailId > 0) {
    $where[] = 'email_id = ?';
    $params[] = $emailId;
}

$sql = "SELECT *
        FROM email_reply_delivery_audit";
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
$params[] = $limit;

$rows = Database::query($sql, $params);

$includeConfig = ((string) ($_GET['include_config'] ?? '')) === '1';
$config = null;
if ($includeConfig) {
    $config = [
        'system_mail' => (new EmailIntegrationService())->getMainProviderSummary(),
        'legacy_env_ignored' => [
            'smtp_host' => trim((string) ($_ENV['SMTP_HOST'] ?? '')),
            'smtp_port' => (int) ($_ENV['SMTP_PORT'] ?? 0),
            'smtp_encryption' => trim((string) ($_ENV['SMTP_ENCRYPTION'] ?? '')),
            'smtp_user_configured' => trim((string) ($_ENV['SMTP_USER'] ?? '')) !== '',
            'smtp_from_email' => trim((string) ($_ENV['SMTP_FROM_EMAIL'] ?? '')),
            'smtp_from_name' => trim((string) ($_ENV['SMTP_FROM_NAME'] ?? '')),
            'ignored' => true,
        ],
    ];
}

echo json_encode([
    'success' => true,
    'audit' => $rows,
    'config' => $config,
], JSON_UNESCAPED_SLASHES);
