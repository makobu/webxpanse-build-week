<?php
declare(strict_types=1);

use CRM\Services\SystemReadinessService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/production_preflight.php [--strict] [--json]\n\n";
    echo "Runs the same production readiness checks used by System Health.\n";
    echo "--strict  Exit non-zero on warnings as well as critical blockers.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
$service = new SystemReadinessService($config, $root);
$readiness = $service->check();
$preflight = findCheck($readiness, 'production_preflight');
$overallStatus = (string) ($readiness['status'] ?? 'unknown');
$preflightStatus = (string) ($preflight['status'] ?? 'unknown');
$hasCritical = $overallStatus === 'critical' || $preflightStatus === 'critical';
$hasWarning = in_array($overallStatus, ['warning', 'unknown'], true)
    || in_array($preflightStatus, ['warning', 'unknown'], true);
$exitCode = $hasCritical ? 1 : (($strict && $hasWarning) ? 2 : 0);

if ($json) {
    echo json_encode(
        [
            'ok' => $exitCode === 0,
            'strict' => $strict,
            'exit_code' => $exitCode,
            'status' => $overallStatus,
            'checked_at' => $readiness['checked_at'] ?? null,
            'summary' => $readiness['summary'] ?? [],
            'production_preflight' => $preflight,
            'checks' => $readiness['checks'] ?? [],
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Production Preflight ===\n";
echo "Status: " . strtoupper($overallStatus) . "\n";
echo "Checked at: " . (string) ($readiness['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($readiness['summary'] ?? []);
echo "Summary: "
    . (int) ($summary['ok'] ?? 0) . " ok, "
    . (int) ($summary['warning'] ?? 0) . " warnings, "
    . (int) ($summary['critical'] ?? 0) . " critical, "
    . (int) ($summary['unknown'] ?? 0) . " unknown\n\n";

foreach ((array) ($readiness['checks'] ?? []) as $check) {
    if (!is_array($check)) {
        continue;
    }

    $status = strtoupper((string) ($check['status'] ?? 'unknown'));
    $label = (string) ($check['label'] ?? $check['key'] ?? 'Check');
    $message = (string) ($check['message'] ?? '');
    echo '[' . $status . '] ' . $label . ': ' . $message . "\n";
}

$metadata = is_array($preflight['metadata'] ?? null) ? $preflight['metadata'] : [];
printList('Blockers', (array) ($metadata['blockers'] ?? []));
printList('Warnings', (array) ($metadata['warnings'] ?? []));
printList('Manual confirmations', (array) ($metadata['manual_confirmations'] ?? []));

if ($exitCode === 0) {
    echo "\nProduction preflight passed.\n";
} elseif ($exitCode === 2) {
    echo "\nProduction preflight found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nProduction preflight failed. Fix critical blockers before live upload.\n";
}

exit($exitCode);

/**
 * @param array<string,mixed> $readiness
 * @return array<string,mixed>
 */
function findCheck(array $readiness, string $key): array
{
    foreach ((array) ($readiness['checks'] ?? []) as $check) {
        if (is_array($check) && ($check['key'] ?? '') === $key) {
            return $check;
        }
    }

    return [];
}

/**
 * @param array<int|string,mixed> $items
 */
function printList(string $title, array $items): void
{
    echo "\n" . $title . ":\n";
    if ($items === []) {
        echo "  - none\n";
        return;
    }

    foreach ($items as $item) {
        echo '  - ' . (string) $item . "\n";
    }
}
