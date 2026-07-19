<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\WorkspaceWhatsAppCreditService;

Database::init(require __DIR__ . '/../config/database.php');

$olderThanMinutes = max(15, (int) ($argv[1] ?? 1440));
$workspaceId = max(0, (int) ($argv[2] ?? 0));
$service = new WorkspaceWhatsAppCreditService();

$result = [
    'stale_reservations' => $service->releaseStaleReservations($olderThanMinutes, $workspaceId > 0 ? $workspaceId : null),
    'negative_wallets' => [],
    'low_balance_wallets' => [],
];

if (Database::tableExists('workspace_whatsapp_credit_wallets')) {
    $result['negative_wallets'] = Database::query(
        "SELECT workspace_id, credit_balance, reserved_credits, currency
         FROM workspace_whatsapp_credit_wallets
         WHERE credit_balance < 0
         ORDER BY credit_balance ASC
         LIMIT 50"
    );
    $result['low_balance_wallets'] = Database::query(
        "SELECT workspace_id, credit_balance, reserved_credits, low_balance_threshold, currency
         FROM workspace_whatsapp_credit_wallets
         WHERE (credit_balance - reserved_credits) <= low_balance_threshold
         ORDER BY (credit_balance - reserved_credits) ASC
         LIMIT 50"
    );
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
