<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\IntegrationReadinessService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/check_integration_readiness.php [--strict] [--json]\n\n";
    echo "Checks SMTP/email, WhatsApp, Google/calendar, payment, AI, jobs, and queue readiness.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new IntegrationReadinessService(null, $root))->check();
$status = (string) ($report['status'] ?? 'unknown');
$hasCritical = $status === 'critical';
$hasWarning = in_array($status, ['warning', 'unknown'], true);
$exitCode = $hasCritical ? 1 : (($strict && $hasWarning) ? 2 : 0);

if ($json) {
    echo json_encode(
        [
            'ok' => $exitCode === 0,
            'strict' => $strict,
            'exit_code' => $exitCode,
            'status' => $status,
            'checked_at' => $report['checked_at'] ?? null,
            'summary' => $report['summary'] ?? [],
            'integration_readiness' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Integration Readiness ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . (int) ($summary['ready'] ?? 0) . " ready, "
    . (int) ($summary['warning'] ?? 0) . " warning domains, "
    . (int) ($summary['critical'] ?? 0) . " critical domains, "
    . (int) ($summary['findings'] ?? 0) . " finding(s)\n";

foreach ((array) ($report['domains'] ?? []) as $domain) {
    if (!is_array($domain)) {
        continue;
    }
    echo '[' . strtoupper((string) ($domain['status'] ?? 'unknown')) . '] '
        . (string) ($domain['label'] ?? $domain['key'] ?? 'Domain')
        . "\n";
}

$findings = (array) ($report['findings'] ?? []);
if ($findings !== []) {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . (string) ($finding['target'] ?? $finding['domain'] ?? 'integration')
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nIntegration readiness check passed.\n";
} elseif ($exitCode === 2) {
    echo "\nIntegration readiness found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nIntegration readiness failed. Fix critical integration blockers before live upload.\n";
}

exit($exitCode);
