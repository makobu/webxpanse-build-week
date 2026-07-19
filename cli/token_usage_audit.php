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
use CRM\Services\AITokenUsageAuditService;

$args = $argv ?? [];
$json = false;
$workspaceId = null;
$windowDays = 30;
$topLimit = 25;

foreach ($args as $arg) {
    $arg = (string) $arg;
    if ($arg === '--json') {
        $json = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Token Usage Audit\n";
        echo "Usage: php cli/token_usage_audit.php [--json] [--workspace-id=ID] [--days=N] [--top=N]\n";
        exit(0);
    }
    if (str_starts_with($arg, '--workspace-id=')) {
        $workspaceId = max(1, (int) substr($arg, 15));
        continue;
    }
    if (str_starts_with($arg, '--days=')) {
        $windowDays = max(1, (int) substr($arg, 7));
        continue;
    }
    if (str_starts_with($arg, '--top=')) {
        $topLimit = max(1, min(100, (int) substr($arg, 6)));
    }
}

Database::init(require __DIR__ . '/../config/database.php');

$audit = (new AITokenUsageAuditService())->audit([
    'workspace_id' => $workspaceId,
    'window_days' => $windowDays,
    'top_limit' => $topLimit,
]);

if ($json) {
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "Token Usage Audit\n";
echo sprintf("Generated: %s\n", (string) ($audit['generated_at'] ?? ''));
echo sprintf("Workspace Filter: %s\n", $workspaceId !== null ? (string) $workspaceId : 'all');
echo sprintf("Window: last %d day(s)\n", $windowDays);
echo sprintf("Top Limit: %d\n\n", $topLimit);

echo "Tables\n";
foreach ((array) ($audit['tables'] ?? []) as $table => $available) {
    echo sprintf("- %s: %s\n", (string) $table, !empty($available) ? 'available' : 'missing');
}

echo "\nUsage Windows\n";
foreach ((array) ($audit['windows'] ?? []) as $window => $totals) {
    echo sprintf(
        "- %s: requests=%s input=%s output=%s billable=%s avg=%s\n",
        label((string) $window),
        n($totals['request_count'] ?? 0),
        n($totals['input_tokens'] ?? 0),
        n($totals['output_tokens'] ?? 0),
        n($totals['billable_tokens'] ?? 0),
        n($totals['avg_billable_tokens'] ?? 0)
    );
}

echo "\nSetup vs Daily\n";
foreach ((array) ($audit['usage_buckets'] ?? []) as $bucket => $totals) {
    echo sprintf(
        "- %s: requests=%s billable=%s input=%s output=%s\n",
        label((string) $bucket),
        n($totals['request_count'] ?? 0),
        n($totals['billable_tokens'] ?? 0),
        n($totals['input_tokens'] ?? 0),
        n($totals['output_tokens'] ?? 0)
    );
}

echo "\nTop Modules / Feature Keys\n";
$features = (array) ($audit['by_feature'] ?? []);
if ($features === []) {
    echo "- No token usage recorded in this window.\n";
} else {
    foreach ($features as $row) {
        echo sprintf(
            "- %s: billable=%s requests=%s bucket=%s risk=%s avg=%s\n",
            (string) ($row['feature_key'] ?? 'general'),
            n($row['billable_tokens'] ?? 0),
            n($row['request_count'] ?? 0),
            (string) ($row['usage_bucket'] ?? 'daily'),
            (string) ($row['risk_tier'] ?? 'medium'),
            n($row['avg_billable_tokens'] ?? 0)
        );
    }
}

echo "\nDaily Breakdown\n";
$days = (array) ($audit['by_day'] ?? []);
if ($days === []) {
    echo "- No daily rows available.\n";
} else {
    foreach ($days as $row) {
        echo sprintf(
            "- %s: billable=%s requests=%s input=%s output=%s\n",
            (string) ($row['usage_date'] ?? ''),
            n($row['billable_tokens'] ?? 0),
            n($row['request_count'] ?? 0),
            n($row['input_tokens'] ?? 0),
            n($row['output_tokens'] ?? 0)
        );
    }
}

echo "\nProvider / Model\n";
$providers = (array) ($audit['by_provider_model'] ?? []);
if ($providers === []) {
    echo "- No provider/model usage recorded.\n";
} else {
    foreach ($providers as $row) {
        echo sprintf(
            "- %s/%s (%s): billable=%s requests=%s\n",
            (string) ($row['provider'] ?? ''),
            (string) ($row['model'] ?? ''),
            (string) ($row['provider_source'] ?? 'unknown'),
            n($row['billable_tokens'] ?? 0),
            n($row['request_count'] ?? 0)
        );
    }
}

echo "\nWallet Snapshot\n";
$wallets = (array) ($audit['wallets'] ?? []);
if ($wallets === []) {
    echo "- No workspace wallet rows available.\n";
} else {
    foreach ($wallets as $row) {
        echo sprintf(
            "- #%d %s: balance=%s reserved=%s available=%s lifetime_debited=%s\n",
            (int) ($row['workspace_id'] ?? 0),
            (string) ($row['workspace_name'] ?? ''),
            n($row['token_balance'] ?? 0),
            n($row['reserved_tokens'] ?? 0),
            n($row['available_tokens'] ?? 0),
            n($row['lifetime_debited_tokens'] ?? 0)
        );
    }
}

echo "\nProvider Configs\n";
$configs = (array) ($audit['provider_configs'] ?? []);
if ($configs === []) {
    echo "- No workspace AI provider configs found.\n";
} else {
    foreach ($configs as $row) {
        echo sprintf(
            "- #%d %s: mode=%s provider=%s model=%s shared_daily_cap=%s key_saved=%s verified=%s\n",
            (int) ($row['workspace_id'] ?? 0),
            (string) ($row['workspace_name'] ?? ''),
            (string) ($row['mode'] ?? ''),
            (string) ($row['provider_key'] ?? ''),
            (string) ($row['model'] ?? ''),
            n($row['shared_daily_token_cap'] ?? 0),
            !empty($row['has_key_fingerprint']) ? 'yes' : 'no',
            (string) ($row['last_verified_at'] ?? '')
        );
    }
}

echo "\nNotes\n";
foreach ((array) ($audit['notes'] ?? []) as $note) {
    echo sprintf("- %s\n", (string) $note);
}

function n(mixed $value): string
{
    if (is_float($value)) {
        return number_format($value, 2);
    }

    if (is_numeric($value)) {
        return number_format((float) $value, ((float) $value === (float) (int) $value) ? 0 : 2);
    }

    return (string) $value;
}

function label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}
