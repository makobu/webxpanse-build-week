<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../../../config/constants.php';
\CRM\Database::init(require __DIR__ . '/../../../config/database.php');
header('Content-Type: text/xml; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Reject /></Response>';
    exit;
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
    http_response_code(415);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Reject /></Response>';
    exit;
}
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 65536) {
    http_response_code(413);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Reject /></Response>';
    exit;
}

try {
    $result = (new \CRM\Services\VoiceWebhookService())->handle(
        (string) ($_GET['token'] ?? ''),
        $_POST,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        function_exists('getallheaders') ? (array) getallheaders() : []
    );
    http_response_code((int) $result['status']);
    echo (string) $result['xml'];
} catch (Throwable $e) {
    error_log('Voice webhook error: ' . $e->getMessage());
    http_response_code(200);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Reject /></Response>';
}
