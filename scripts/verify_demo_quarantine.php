<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\DemoQuarantineVerificationService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/verify_demo_quarantine.php [--strict] [--json]\n\n";
    echo "Verifies protected demo/session/presentation data cannot pollute the default production workspace.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new DemoQuarantineVerificationService())->verify();
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
            'demo_quarantine' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Demo Quarantine Verification ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . number_format((int) ($summary['default_demo_scoped_rows'] ?? 0)) . " default demo-scoped row(s), "
    . number_format((int) ($summary['default_demo_marker_rows'] ?? 0)) . " default demo-marker row(s), "
    . number_format((int) ($summary['default_presentation_rows'] ?? 0)) . " default presentation row(s), "
    . number_format((int) ($summary['critical'] ?? 0)) . " critical, "
    . number_format((int) ($summary['warning'] ?? 0)) . " warnings\n";

$findings = (array) ($report['findings'] ?? []);
if ($findings === []) {
    echo "\nNo demo quarantine findings.\n";
} else {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . (string) ($finding['target'] ?? $finding['table'] ?? 'demo_quarantine')
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nDemo quarantine verification passed.\n";
} elseif ($exitCode === 2) {
    echo "\nDemo quarantine verification found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nDemo quarantine verification failed. Fix critical default-workspace contamination before live upload.\n";
}

exit($exitCode);
