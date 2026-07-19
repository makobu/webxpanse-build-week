<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\ManagedWhatsAppProvisioningService;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceWhatsAppCreditService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        throw new \RuntimeException('Workspace context is required.');
    }

    $featureGate = new WhatsAppFeatureGate();
    $service = new ManagedWhatsAppProvisioningService();
    $connection = (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId);
    $summary = $featureGate->managedBillingEnabled($workspaceId) && Database::tableExists('workspace_whatsapp_credit_wallets')
        ? (new WorkspaceWhatsAppCreditService())->summary($workspaceId)
        : [];

    echo json_encode([
        'success' => true,
        'readiness' => $service->platformReadiness($workspaceId),
        'feature_flags' => $featureGate->snapshot($workspaceId),
        'connection' => $connection ? [
            'connection_mode' => (string) ($connection['connection_mode'] ?? 'self_managed'),
            'connection_status' => (string) ($connection['connection_status'] ?? ''),
            'managed_status' => (string) ($connection['managed_status'] ?? ''),
            'managed_billing_status' => (string) ($connection['managed_billing_status'] ?? ''),
            'phone_number_id' => (string) ($connection['phone_number_id'] ?? ''),
            'display_phone_number' => (string) ($connection['display_phone_number'] ?? ''),
            'whatsapp_business_account_id' => (string) ($connection['whatsapp_business_account_id'] ?? ''),
            'webhook_last_status' => (string) ($connection['webhook_last_status'] ?? ''),
            'managed_last_health_status' => (string) ($connection['managed_last_health_status'] ?? ''),
        ] : null,
        'wallet' => $summary,
        'can_manage_connection' => (new WorkspaceConnectService())->canManageWhatsAppConnection(Auth::user(), $workspaceId),
        'can_manage_billing' => Authorization::can('settings.whatsapp.manage_billing', Auth::user()),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
