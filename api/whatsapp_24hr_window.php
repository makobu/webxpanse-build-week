<?php
/**
 * WhatsApp 24-Hour Window Check API
 * GET ?contact_id=123 returns { "within_24h": true|false }
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Services\WhatsAppService;

header('Content-Type: application/json');
Auth::requireAuth();

$contactId = (int) ($_GET['contact_id'] ?? 0);
if ($contactId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'contact_id required', 'within_24h' => false]);
    exit;
}

try {
    $whatsApp = new WhatsAppService();
    $within = $whatsApp->isWithin24HourWindow($contactId);
    echo json_encode(['within_24h' => $within]);
} catch (\Throwable $e) {
    error_log("whatsapp_24hr_window: " . $e->getMessage());
    echo json_encode(['within_24h' => false, 'error' => $e->getMessage()]);
}
