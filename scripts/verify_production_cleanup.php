<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\ProductionCleanupVerificationService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/verify_production_cleanup.php [--strict] [--json]\n\n";
    echo "Verifies production cleanup risk across demo data, public setup artifacts, and staged generated files.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new ProductionCleanupVerificationService($root))->verify();
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
            'production_cleanup' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Production Cleanup Verification ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . number_format((int) ($summary['default_workspace_demo_rows'] ?? 0)) . " default demo/presentation row(s), "
    . number_format((int) ($summary['protected_demo_workspace_rows'] ?? 0)) . " protected demo row(s), "
    . number_format((int) ($summary['public_setup_artifacts'] ?? 0)) . " public setup artifact(s), "
    . number_format((int) ($summary['staged_generated_artifacts'] ?? 0)) . " staged generated artifact(s)\n";

$findings = (array) ($report['findings'] ?? []);
if ($findings === []) {
    echo "\nNo production cleanup findings.\n";
} else {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . (string) ($finding['target'] ?? 'production_cleanup')
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nProduction cleanup verification passed.\n";
} elseif ($exitCode === 2) {
    echo "\nProduction cleanup verification found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nProduction cleanup verification failed. Fix critical cleanup blockers before live upload.\n";
}

exit($exitCode);
