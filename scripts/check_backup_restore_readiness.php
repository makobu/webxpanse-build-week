<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\BackupRestoreReadinessService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/check_backup_restore_readiness.php [--strict] [--json]\n\n";
    echo "Checks backup freshness, restore tooling, offsite coverage, and live-server recovery expectations.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new BackupRestoreReadinessService(Database::getInstance(), $root))->check();
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
            'backup_restore_readiness' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Backup & Restore Readiness ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . number_format((int) ($summary['db_backup_count'] ?? 0)) . " database backup(s), "
    . number_format((int) ($summary['uploads_backup_count'] ?? 0)) . " uploads backup(s), "
    . number_format((int) ($summary['critical'] ?? 0)) . " critical, "
    . number_format((int) ($summary['warning'] ?? 0)) . " warnings\n";

if (!empty($summary['latest_db_backup_at'])) {
    echo "Latest DB backup: " . (string) $summary['latest_db_backup_at'] . " ("
        . (string) ($summary['db_backup_age_hours'] ?? '?') . " hour(s) old)\n";
}
if (!empty($summary['latest_uploads_backup_at'])) {
    echo "Latest uploads backup: " . (string) $summary['latest_uploads_backup_at'] . " ("
        . (string) ($summary['uploads_backup_age_hours'] ?? '?') . " hour(s) old)\n";
}

$findings = (array) ($report['findings'] ?? []);
if ($findings === []) {
    echo "\nNo backup and restore readiness findings.\n";
} else {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . (string) ($finding['target'] ?? 'backup_restore')
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nBackup and restore readiness passed.\n";
} elseif ($exitCode === 2) {
    echo "\nBackup and restore readiness found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nBackup and restore readiness failed. Fix critical backup or restore gaps before live upload.\n";
}

exit($exitCode);
