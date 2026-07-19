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

use CRM\Database;
use CRM\Services\WorkspacePackageBillingInvoiceService;

Database::init(require __DIR__ . '/../config/database.php');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$limit = 1000;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string) $arg, $matches)) {
        $limit = max(1, min(10000, (int) $matches[1]));
    }
}

if (!Database::tableExists('billing_invoices')) {
    fwrite(STDERR, "billing_invoices table is missing. Run php database/migrations/migrate.php first.\n");
    exit(1);
}

$rows = Database::query(
    "SELECT bt.id
     FROM billing_transactions bt
     LEFT JOIN billing_invoices bi ON bi.billing_transaction_id = bt.id
     WHERE bt.transaction_type = 'subscription_charge'
       AND bt.transaction_status = 'succeeded'
       AND bi.id IS NULL
     ORDER BY bt.id ASC
     LIMIT {$limit}"
);

$service = new WorkspacePackageBillingInvoiceService();
$created = 0;
$skipped = 0;

foreach ($rows as $row) {
    $transactionId = (int) ($row['id'] ?? 0);
    if ($transactionId <= 0) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $created++;
        continue;
    }

    $invoice = $service->issueForSubscriptionTransaction($transactionId);
    if ($invoice) {
        $created++;
    } else {
        $skipped++;
    }
}

$mode = $dryRun ? 'would_create' : 'created';
echo json_encode([
    'success' => true,
    'mode' => $dryRun ? 'dry_run' : 'apply',
    'scanned' => count($rows),
    $mode => $created,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
