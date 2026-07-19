<?php
/**
 * Workflow Trigger Webhook
 *
 * POST JSON with workflow_id or trigger_key, plus contact_id or email.
 * Requires Authorization: Bearer <crm_api_key> or X-API-Key.
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

use CRM\ApiAuth;
use CRM\Database;
use CRM\Modules\ApiKeys;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkflowWebhookTriggerService;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

$startedAt = microtime(true);
$apiKeyId = null;
$responseStatus = 200;

/**
 * @param array<string,mixed> $body
 */
function workflowTriggerRespond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
}

/**
 * @return array<string,string>
 */
function workflowTriggerRequestHeaders(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $rawHeaders = getallheaders();
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = (string) $value;
    }

    return $headers;
}

function workflowTriggerApiKeyFromHeaders(): ?string
{
    $headers = workflowTriggerRequestHeaders();
    $authorization = trim((string) ($headers['authorization'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    $apiKey = trim((string) ($headers['x-api-key'] ?? ''));
    return $apiKey !== '' ? $apiKey : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $responseStatus = 405;
        workflowTriggerRespond($responseStatus, [
            'success' => false,
            'error' => 'Method not allowed',
            'code' => 'method_not_allowed',
        ]);
        return;
    }

    $apiKey = workflowTriggerApiKeyFromHeaders();
    if ($apiKey === null) {
        $responseStatus = 401;
        workflowTriggerRespond($responseStatus, [
            'success' => false,
            'error' => 'Valid API key is required.',
            'code' => 'unauthorized',
        ]);
        return;
    }

    $apiKeysModule = new ApiKeys();
    $authResult = $apiKeysModule->authenticate($apiKey);
    if (!$authResult) {
        $responseStatus = 401;
        workflowTriggerRespond($responseStatus, [
            'success' => false,
            'error' => 'Valid API key is required.',
            'code' => 'unauthorized',
        ]);
        return;
    }

    $apiKeyId = (int) ($authResult['api_key_id'] ?? 0);
    $workspaceId = (int) ($authResult['workspace_id'] ?? 0);
    if ($workspaceId > 0) {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, (int) ($authResult['user_id'] ?? 0));
    }

    if (!$apiKeysModule->checkRateLimit($apiKeyId)) {
        $responseStatus = 429;
        workflowTriggerRespond($responseStatus, [
            'success' => false,
            'error' => 'Rate limit exceeded',
            'code' => 'rate_limit_exceeded',
        ]);
        return;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    if (!is_array($payload) || $payload === []) {
        $responseStatus = 422;
        workflowTriggerRespond($responseStatus, [
            'success' => false,
            'error' => 'JSON payload is required.',
            'code' => 'payload_required',
        ]);
        return;
    }

    $result = (new WorkflowWebhookTriggerService())->handle($payload, $authResult);
    $responseStatus = (int) ($result['status'] ?? 500);
    workflowTriggerRespond($responseStatus, $result['body'] ?? [
        'success' => false,
        'error' => 'Workflow trigger failed.',
        'code' => 'workflow_trigger_failed',
    ]);
} catch (\Throwable $e) {
    $responseStatus = 500;
    workflowTriggerRespond($responseStatus, [
        'success' => false,
        'error' => 'Workflow trigger failed.',
        'code' => 'internal_error',
    ]);
    error_log('Workflow trigger webhook failed: ' . $e->getMessage());
} finally {
    if ($apiKeyId !== null && $apiKeyId > 0) {
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        ApiAuth::logRequest($apiKeyId, '/api/webhooks/workflow_trigger.php', 'POST', $responseStatus, $elapsedMs);
    }
}
