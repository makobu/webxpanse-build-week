<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Modules\MeetingBotConfig;
use CRM\Services\MeetingBotService;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$headerSecret = trim((string) ($_SERVER['HTTP_X_MEETING_BOT_KEY'] ?? ''));
$querySecret = !empty($_ENV['ALLOW_MEETING_QUERY_SECRET_AUTH'])
    ? trim((string) ($_GET['key'] ?? ''))
    : '';
$providedSecret = $headerSecret !== '' ? $headerSecret : $querySecret;
$config = (new MeetingBotConfig())->findByWebhookSecret($providedSecret);

if (!$config) {
    error_log('Meeting bot webhook unauthorized attempt from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($querySecret !== '' && $headerSecret === '') {
    error_log('Meeting bot webhook used deprecated query-string secret for workspace ' . (string) ($config['workspace_id'] ?? 'unknown'));
}

try {
    $result = (new MeetingBotService(workspaceId: (int) ($config['workspace_id'] ?? 0)))->handleWebhook($input);
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
