<?php
/**
 * Manual outbound webhook test endpoint.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Webhooks;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

/**
 * @param array<string,mixed> $body
 */
function webhookTestRespond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
}

function webhookTestHeader(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return (string) $_SERVER[$serverKey];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return (string) $value;
                }
            }
        }
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        webhookTestRespond(405, [
            'success' => false,
            'error' => 'Method not allowed',
        ]);
        return;
    }

    if (!Auth::check()) {
        webhookTestRespond(401, [
            'success' => false,
            'error' => 'Unauthorized',
        ]);
        return;
    }

    Authorization::requirePermission('settings.webhooks', true);

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $csrfToken = trim((string) ($input['csrf_token'] ?? webhookTestHeader('X-CSRF-Token') ?? ''));
    if (!Security::validateCSRF($csrfToken)) {
        webhookTestRespond(419, [
            'success' => false,
            'error' => 'Invalid security token.',
        ]);
        return;
    }

    $webhookId = (int) ($input['webhook_id'] ?? 0);
    if ($webhookId <= 0) {
        webhookTestRespond(422, [
            'success' => false,
            'error' => 'webhook_id is required.',
        ]);
        return;
    }

    $result = (new Webhooks())->test($webhookId);
    webhookTestRespond(200, [
        'success' => (bool) ($result['success'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
        'event_type' => $result['event_type'] ?? 'webhook.test',
        'response_status' => $result['response_status'] ?? null,
        'response_preview' => $result['response_preview'] ?? null,
        'error_message' => $result['error_message'] ?? null,
        'log_id' => $result['log_id'] ?? null,
        'synchronous' => (bool) ($result['synchronous'] ?? true),
    ]);
} catch (\Exception $e) {
    $status = $e->getMessage() === 'Webhook not found' ? 404 : 500;
    webhookTestRespond($status, [
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
