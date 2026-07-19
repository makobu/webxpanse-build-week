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
use CRM\Modules\Invoices;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$action = $_POST['action'] ?? 'transition';
$userId = (int) (Auth::user()['id'] ?? 0);

$module = new Invoices();

try {
    if ($action === 'mark_paid') {
        Authorization::requirePermission('invoices.mark_paid', true);
        $module->markPaid(
            $invoiceId,
            (float) ($_POST['amount'] ?? 0),
            !empty($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d H:i:s'),
            $userId,
            'user',
            trim((string) ($_POST['reason'] ?? 'Manual payment update'))
        );
    } else {
        $targetStatus = (string) ($_POST['status'] ?? 'draft');
        if ($targetStatus === 'finalized') {
            Authorization::requirePermission('invoices.finalize', true);
        } elseif (in_array($targetStatus, ['partially_paid', 'paid'], true)) {
            Authorization::requirePermission('invoices.mark_paid', true);
        } else {
            Authorization::requirePermission('invoices.edit', true);
        }
        $module->transitionStatus(
            $invoiceId,
            $targetStatus,
            $userId,
            'user',
            trim((string) ($_POST['reason'] ?? ''))
        );
    }

    echo json_encode(['success' => true, 'invoice' => $module->getById($invoiceId)]);
} catch (\PDOException $e) {
    error_log('Invoice status database update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The invoice status could not be updated.']);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Invoice status update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The invoice status could not be updated.']);
}
