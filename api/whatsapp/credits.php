<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceWhatsAppCreditService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$service = new WorkspaceWhatsAppCreditService();

try {
    if ($workspaceId <= 0) {
        throw new \RuntimeException('Workspace context is required.');
    }
    if (!(new WhatsAppFeatureGate())->managedBillingEnabled($workspaceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Managed WhatsApp billing is not enabled for this workspace.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'wallet' => $service->summary($workspaceId)]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!Security::validateCSRF((string) ($payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
        exit;
    }
    if (!Authorization::can('settings.whatsapp.manage_billing', Auth::user())) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'WhatsApp billing permission is required.']);
        exit;
    }

    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    if ($action === 'update_settings') {
        $dailyCap = trim((string) ($payload['daily_spend_cap'] ?? ''));
        $monthlyCap = trim((string) ($payload['monthly_spend_cap'] ?? ''));
        $autoEnabled = !empty($payload['auto_topup_enabled']) ? 1 : 0;
        $autoThreshold = trim((string) ($payload['auto_topup_threshold'] ?? ''));
        $autoAmount = trim((string) ($payload['auto_topup_amount'] ?? ''));
        $service->ensureWallet($workspaceId);
        Database::execute(
            "UPDATE workspace_whatsapp_credit_wallets
             SET daily_spend_cap = ?,
                 monthly_spend_cap = ?,
                 auto_topup_enabled = ?,
                 auto_topup_threshold = ?,
                 auto_topup_amount = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [
                $dailyCap !== '' ? (float) $dailyCap : null,
                $monthlyCap !== '' ? (float) $monthlyCap : null,
                $autoEnabled,
                $autoThreshold !== '' ? (float) $autoThreshold : null,
                $autoAmount !== '' ? (float) $autoAmount : null,
                $workspaceId,
            ]
        );
        echo json_encode(['success' => true, 'wallet' => $service->summary($workspaceId)]);
        exit;
    }

    if ($action !== 'grant') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported credit action.']);
        exit;
    }

    $amount = (float) ($payload['amount'] ?? 0);
    $currency = strtoupper(substr((string) ($payload['currency'] ?? 'KES'), 0, 3));
    $reference = trim((string) ($payload['reference'] ?? 'manual_whatsapp_credit:' . uniqid('', true)));
    $service->ensureWallet($workspaceId, null, $currency !== '' ? $currency : 'KES');
    $result = $service->grantCredits(
        $workspaceId,
        $amount,
        'manual',
        $reference,
        (int) (Auth::user()['id'] ?? 0),
        ['currency' => $currency !== '' ? $currency : 'KES'],
        (string) ($payload['description'] ?? 'WhatsApp Credits top-up')
    );
    echo json_encode(['success' => true, 'wallet' => $result]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
