<?php
/**
 * WhatsApp Webhook Endpoint
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$possiblePaths = [
    dirname(__DIR__, 3) . '/.env',
    __DIR__ . '/../../.env',
    rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)), '/\\') . '/.env',
    dirname(__DIR__, 2) . '/.env',
];

foreach ($possiblePaths as $path) {
    if (!file_exists($path) || !is_readable($path)) {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '' || (array_key_exists($key, $_ENV) && trim((string) $_ENV[$key]) !== '')) {
            continue;
        }
        $_ENV[$key] = trim($value);
    }
    break;
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Services\WhatsAppWebhook;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\MetaWebhookSignatureVerifier;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

function whatsappWebhookDebugEnabled(): bool
{
    return strtolower((string) ($_ENV['APP_DEBUG'] ?? 'false')) === 'true'
        && strtolower((string) ($_ENV['WHATSAPP_VERBOSE_DEBUG'] ?? 'false')) === 'true';
}

function whatsappWebhookLog(string $message): void
{
    if (whatsappWebhookDebugEnabled()) {
        error_log($message);
    }
}

function whatsappWebhookFirstMetadata(array $payload): array
{
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryWabaId = (string) ($entry['id'] ?? '');
        foreach ((array) ($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }
            $value = (array) ($change['value'] ?? []);
            $metadata = (array) ($value['metadata'] ?? []);
            if (!isset($metadata['whatsapp_business_account_id']) && $entryWabaId !== '') {
                $metadata['whatsapp_business_account_id'] = $entryWabaId;
            }
            if ($metadata !== []) {
                return $metadata;
            }
        }
    }

    return [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = $_GET['hub.mode'] ?? $_GET['hub_mode'] ?? '';
    $token = trim((string) ($_GET['hub.verify_token'] ?? $_GET['hub_verify_token'] ?? ''));
    $challenge = (string) ($_GET['hub.challenge'] ?? $_GET['hub_challenge'] ?? '');
    $workspaceWebhookToken = trim((string) ($_GET['w'] ?? ''));

    whatsappWebhookLog('WhatsApp webhook verification request received.');

    if ($mode !== 'subscribe' || $workspaceWebhookToken === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Workspace webhook token is required']);
        exit;
    }

    $integration = (new WorkspaceConnectService())->verifyWhatsAppWebhookChallenge($workspaceWebhookToken, $token);
    if ($integration) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Verification failed']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$signatureHeader = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if (!(new MetaWebhookSignatureVerifier())->verify((string) $rawInput, $signatureHeader)) {
    whatsappWebhookLog('WhatsApp webhook rejected because the Meta signature was missing or invalid.');
    http_response_code(403);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

$payload = json_decode($rawInput, true);
if (!is_array($payload) && $_POST !== []) {
    $payload = $_POST;
}

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook object']);
    exit;
}

try {
    $connectService = new WorkspaceConnectService();
    $workspaceWebhookToken = trim((string) ($_GET['w'] ?? ''));
    $workspaceIdHint = null;
    $metadata = whatsappWebhookFirstMetadata($payload);

    if ($workspaceWebhookToken === '') {
        if ($metadata !== []) {
            $integrationByMetadata = $connectService->getWhatsAppIntegrationByWebhookMetadata($metadata);
            $metadataWorkspaceId = (int) ($integrationByMetadata['workspace_id'] ?? 0);
            if ($metadataWorkspaceId > 0) {
                $connectService->recordWhatsAppWebhookEvent($metadataWorkspaceId, 'missing_workspace_token', 'Webhook callback URL did not include the workspace token.');
            }
        }
        http_response_code(403);
        echo json_encode(['error' => 'Workspace webhook token is required']);
        exit;
    }

    $integration = $connectService->getWhatsAppIntegrationByWebhookToken($workspaceWebhookToken);
    if (!$integration) {
        if ($metadata !== []) {
            $integrationByMetadata = $connectService->getWhatsAppIntegrationByWebhookMetadata($metadata);
            $metadataWorkspaceId = (int) ($integrationByMetadata['workspace_id'] ?? 0);
            if ($metadataWorkspaceId > 0) {
                $connectService->recordWhatsAppWebhookEvent($metadataWorkspaceId, 'unknown_workspace_token', 'Webhook callback URL used an unknown workspace token.');
            }
        }
        http_response_code(403);
        echo json_encode(['error' => 'Unknown workspace webhook']);
        exit;
    }

    $workspaceIdHint = (int) ($integration['workspace_id'] ?? 0);
    $validation = $connectService->validateWhatsAppWebhookPayloadForWorkspace($workspaceIdHint, $payload);
    if (empty($validation['ok'])) {
        if ($workspaceIdHint > 0) {
            $connectService->recordWhatsAppWebhookEvent(
                $workspaceIdHint,
                (string) ($validation['status'] ?? 'metadata_mismatch'),
                (string) ($validation['error'] ?? 'Webhook payload metadata did not match this workspace.')
            );
        }
        http_response_code(403);
        echo json_encode(['error' => $validation['error'] ?? 'Webhook metadata mismatch']);
        exit;
    }

    (new WhatsAppWebhook($workspaceIdHint))->handle($payload);
    if ($workspaceIdHint !== null && $workspaceIdHint > 0) {
        $connectService->recordWhatsAppWebhookEvent($workspaceIdHint, 'received');
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('WhatsApp webhook exception: ' . $e->getMessage());
    if (!empty($workspaceIdHint)) {
        (new WorkspaceConnectService())->recordWhatsAppWebhookEvent((int) $workspaceIdHint, 'failed', $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['error' => 'Webhook handling failed']);
}
