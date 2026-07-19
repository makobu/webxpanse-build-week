<?php

declare(strict_types=1);

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
use CRM\Services\PaystackGateway;
use CRM\Services\WorkspaceBillingService;

Database::init(require __DIR__ . '/../config/database.php');

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$secretKey = trim((string) ($_ENV['PAYSTACK_SECRET_KEY'] ?? $_ENV['PAYSTACK_SECRET'] ?? ''));
if ($secretKey === '') {
    try {
        $settings = (new WorkspaceBillingService())->getSettings();
        $secretKey = trim((string) ($settings['paystack_secret_key'] ?? ''));
    } catch (Throwable) {
        $secretKey = '';
    }
}

if ($secretKey === '' && !$dryRun) {
    fwrite(STDERR, "Paystack secret key is not configured. Use --dry-run to preview changes.\n");
    exit(1);
}

$rows = Database::query(
    "SELECT bpp.id, bpp.price_code, bpp.currency, bpp.interval_unit, bpp.amount, bpp.provider_plan_code,
            bp.code AS plan_code, bp.name AS plan_name, bp.description
     FROM billing_plan_prices bpp
     JOIN billing_plans bp ON bp.id = bpp.plan_id
     WHERE bp.code IN ('solo-launch', 'founder-plus', 'growth-studio')
       AND bp.billing_type = 'subscription'
       AND bp.is_active = 1
       AND bpp.is_active = 1
       AND bpp.amount > 0
       AND bpp.interval_unit IN ('monthly', 'yearly')
     ORDER BY FIELD(bp.code, 'solo-launch', 'founder-plus', 'growth-studio'), bpp.interval_unit"
);

$gateway = $dryRun ? null : new PaystackGateway($secretKey);
$results = [];

foreach ($rows as $row) {
    $priceId = (int) ($row['id'] ?? 0);
    $existingCode = trim((string) ($row['provider_plan_code'] ?? ''));
    $cadence = (string) ($row['interval_unit'] ?? 'monthly');
    $interval = $cadence === 'yearly' ? 'annually' : 'monthly';
    $name = trim((string) ($row['plan_name'] ?? 'Launch Plan')) . ' ' . ($cadence === 'yearly' ? 'Annual' : 'Monthly');
    $payload = [
        'name' => $name,
        'amount' => (int) round(((float) ($row['amount'] ?? 0)) * 100),
        'interval' => $interval,
        'currency' => strtoupper((string) ($row['currency'] ?? 'KES')),
        'description' => (string) ($row['description'] ?? ''),
        'send_invoices' => true,
        'send_sms' => false,
    ];

    if ($existingCode !== '' && !$force) {
        $results[] = [
            'price_id' => $priceId,
            'price_code' => (string) ($row['price_code'] ?? ''),
            'status' => 'skipped_existing',
            'provider_plan_code' => $existingCode,
        ];
        continue;
    }

    if ($dryRun) {
        $results[] = [
            'price_id' => $priceId,
            'price_code' => (string) ($row['price_code'] ?? ''),
            'status' => 'dry_run',
            'payload' => $payload,
        ];
        continue;
    }

    $response = $gateway->createPlan($payload);
    $data = (array) ($response['data'] ?? []);
    $planCode = trim((string) ($data['plan_code'] ?? ''));
    if ($planCode === '') {
        throw new RuntimeException('Paystack did not return a plan_code for ' . (string) ($row['price_code'] ?? 'price'));
    }

    Database::execute(
        "UPDATE billing_plan_prices
         SET provider = 'paystack',
             provider_plan_code = ?,
             provider_plan_id = ?,
             provider_plan_status = ?,
             provider_plan_synced_at = NOW(),
             updated_at = NOW()
         WHERE id = ?",
        [
            $planCode,
            isset($data['id']) ? (string) $data['id'] : null,
            (string) (($data['status'] ?? '') ?: 'active'),
            $priceId,
        ]
    );

    $results[] = [
        'price_id' => $priceId,
        'price_code' => (string) ($row['price_code'] ?? ''),
        'status' => 'synced',
        'provider_plan_code' => $planCode,
    ];
}

echo json_encode([
    'dry_run' => $dryRun,
    'force' => $force,
    'synced_at' => date('c'),
    'plans' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
