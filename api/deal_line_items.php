<?php
/**
 * Deal Line Items API
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

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Deals;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$deals = new Deals();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$dealId = !empty($_POST['deal_id']) ? (int) $_POST['deal_id'] : (!empty($_GET['deal_id']) ? (int) $_GET['deal_id'] : 0);

if (!$dealId) {
    http_response_code(400);
    echo json_encode(['error' => 'deal_id required']);
    exit;
}

$deal = $deals->getById($dealId);
if (!$deal) {
    http_response_code(404);
    echo json_encode(['error' => 'Deal not found']);
    exit;
}

if ($action === 'add') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    try {
        $id = $deals->addLineItem($dealId, [
            'product_id' => $_POST['product_id'] ?? null,
            'description' => $_POST['description'] ?? '',
            'quantity' => $_POST['quantity'] ?? 1,
            'unit_price' => $_POST['unit_price'] ?? 0,
            'discount_percent' => $_POST['discount_percent'] ?? 0
        ]);
        $items = $deals->getLineItems($dealId);
        $deal = $deals->getById($dealId);
        echo json_encode(['success' => true, 'id' => $id, 'line_items' => $items, 'deal_value' => $deal['value'] ?? 0]);
    } catch (\PDOException $e) {
        error_log('Deal line item database failure: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The deal line item could not be saved.']);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        error_log('Deal line item creation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The deal line item could not be saved.']);
    }
} elseif ($action === 'remove') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $lineItemId = (int) ($_POST['line_item_id'] ?? 0);
    if ($deals->removeLineItem($lineItemId)) {
        $items = $deals->getLineItems($dealId);
        $deal = $deals->getById($dealId);
        echo json_encode(['success' => true, 'line_items' => $items, 'deal_value' => $deal['value'] ?? 0]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to remove line item']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
