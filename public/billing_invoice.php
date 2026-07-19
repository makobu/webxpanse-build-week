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
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspacePackageBillingInvoiceService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], $user)) {
    http_response_code(403);
    echo 'Insufficient permissions.';
    exit;
}

$invoiceId = (int) ($_GET['id'] ?? 0);
$service = new WorkspacePackageBillingInvoiceService();
$invoice = Authorization::isSuperAdmin($user)
    ? $service->findById($invoiceId)
    : $service->findForWorkspace($invoiceId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0));

if (!$invoice) {
    http_response_code(404);
    echo 'Package receipt not found.';
    exit;
}

echo $service->renderHtml($invoice);
exit;
