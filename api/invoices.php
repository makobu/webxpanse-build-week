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
use CRM\Concurrency;
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

Authorization::requirePermission('invoices.view', true);

$module = new Invoices();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$userId = (int) (Auth::user()['id'] ?? 0);

function requireInvoicesApiCsrf(array $payload = []): bool
{
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
    if (Security::validateCSRF((string) $csrfToken)) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    return false;
}

try {
    if ($method === 'GET') {
        if (!empty($_GET['id'])) {
            $invoice = $module->getById((int) $_GET['id']);
            if (!$invoice) {
                http_response_code(404);
                echo json_encode(['error' => 'Invoice not found']);
                exit;
            }
            echo json_encode(['success' => true, 'invoice' => $invoice], JSON_PRETTY_PRINT);
            exit;
        }

        $filters = [
            'document_type' => $_GET['document_type'] ?? '',
            'status' => $_GET['status'] ?? '',
            'deal_id' => $_GET['deal_id'] ?? '',
            'contact_id' => $_GET['contact_id'] ?? '',
            'company_id' => $_GET['company_id'] ?? '',
            'assigned_to' => $_GET['assigned_to'] ?? '',
            'search' => $_GET['search'] ?? '',
        ];
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        echo json_encode([
            'success' => true,
            'invoices' => $module->list($filters, $limit, $offset),
            'total' => $module->count($filters),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        Authorization::requirePermission('invoices.create', true);
        if (!requireInvoicesApiCsrf()) {
            exit;
        }

        $lineItems = json_decode((string) ($_POST['line_items_json'] ?? '[]'), true);
        if (!is_array($lineItems)) {
            throw new \InvalidArgumentException('Line items must be valid JSON.');
        }
        $invoiceId = $module->create([
            'document_type' => $_POST['document_type'] ?? 'invoice',
            'deal_id' => !empty($_POST['deal_id']) ? (int) $_POST['deal_id'] : null,
            'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
            'company_id' => !empty($_POST['company_id']) ? (int) $_POST['company_id'] : null,
            'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
            'created_by' => $userId,
            'currency' => $_POST['currency'] ?? 'USD',
            'issue_date' => $_POST['issue_date'] ?? date('Y-m-d'),
            'due_date' => $_POST['due_date'] ?? null,
            'valid_until' => $_POST['valid_until'] ?? null,
            'payment_terms_days' => $_POST['payment_terms_days'] ?? 14,
            'tax_mode' => $_POST['tax_mode'] ?? 'exclusive',
            'tax_rate' => $_POST['tax_rate'] ?? 0,
            'title' => $_POST['title'] ?? '',
            'intro_text' => $_POST['intro_text'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'terms' => $_POST['terms'] ?? '',
            'billing_name' => $_POST['billing_name'] ?? '',
            'billing_email' => $_POST['billing_email'] ?? '',
            'billing_phone' => $_POST['billing_phone'] ?? '',
            'billing_address' => $_POST['billing_address'] ?? '',
            'shipping_address' => $_POST['shipping_address'] ?? '',
            'line_items' => $lineItems,
        ]);
        echo json_encode(['success' => true, 'id' => $invoiceId]);
        exit;
    }

    if (in_array($method, ['PUT', 'PATCH'], true)) {
        Authorization::requirePermission('invoices.edit', true);
        parse_str(file_get_contents('php://input'), $payload);
        if (!requireInvoicesApiCsrf($payload)) {
            exit;
        }
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }
        $allowedUpdateFields = [
            'document_type', 'currency', 'issue_date', 'due_date', 'valid_until', 'payment_terms_days',
            'tax_mode', 'tax_rate', 'title', 'intro_text', 'notes', 'terms', 'billing_name',
            'billing_email', 'billing_phone', 'billing_address', 'shipping_address', 'assigned_to',
            'deal_id', 'contact_id', 'company_id',
        ];
        $updateData = [];
        foreach ($allowedUpdateFields as $field) {
            if (array_key_exists($field, $payload)) {
                $updateData[$field] = $payload[$field];
            }
        }
        if (array_key_exists('line_items_json', $payload)) {
            $lineItems = json_decode((string) $payload['line_items_json'], true);
            if (!is_array($lineItems)) {
                throw new \InvalidArgumentException('Line items must be valid JSON.');
            }
            $updateData['line_items'] = $lineItems;
        }
        if (array_key_exists('increment_revision', $payload)) {
            $updateData['increment_revision'] = !empty($payload['increment_revision']);
        }
        $updateData['expected_lock_version'] = $payload['expected_lock_version'] ?? null;
        $module->update($id, $updateData);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (\CRM\ConcurrencyConflictException $e) {
    http_response_code(409);
    echo json_encode(Concurrency::conflictPayload($e));
} catch (\PDOException $e) {
    error_log('Invoices database request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The invoice request could not be completed.']);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Invoices API request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The invoice request could not be completed.']);
}
