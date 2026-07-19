<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\SecurityRoleHardeningAuditService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/security_hardening_audit.php [--strict] [--json]\n\n";
    echo "Audits RBAC defaults, Super Admin access, public endpoints, setup scripts, and export/admin actions.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new SecurityRoleHardeningAuditService(null, $root))->audit();
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
            'security_hardening' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Security Hardening Audit ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . (int) ($summary['roles_checked'] ?? 0) . " roles, "
    . (int) ($summary['permissions_checked'] ?? 0) . " permissions, "
    . (int) ($summary['public_php_files_checked'] ?? 0) . " public files, "
    . (int) ($summary['api_php_files_checked'] ?? 0) . " API files, "
    . (int) ($summary['critical'] ?? 0) . " critical, "
    . (int) ($summary['warning'] ?? 0) . " warnings\n";

$findings = (array) ($report['findings'] ?? []);
if ($findings === []) {
    echo "\nNo security hardening findings.\n";
} else {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $target = (string) (($finding['file'] ?? '') ?: ($finding['role_slug'] ?? '') ?: ($finding['permission_key'] ?? '') ?: ($finding['table'] ?? 'security'));
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . $target
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nSecurity hardening audit passed.\n";
} elseif ($exitCode === 2) {
    echo "\nSecurity hardening audit found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nSecurity hardening audit failed. Fix critical security blockers before live upload.\n";
}

exit($exitCode);
