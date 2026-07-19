<?php
declare(strict_types=1);

use CRM\Database;
use CRM\Services\TemplateValidationService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/validate_templates.php [--strict] [--json]\n\n";
    echo "Scans production email and workflow templates for empty bodies, broken placeholders, demo language, and inactive required templates.\n";
    echo "--strict  Exit non-zero on warnings as well as critical findings.\n";
    echo "--json    Print machine-readable JSON instead of text.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$config = require $root . '/config/database.php';
Database::init($config);

$report = (new TemplateValidationService())->validate();
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
            'template_validation' => $report,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit($exitCode);
}

echo "=== CRM Template Validation ===\n";
echo "Status: " . strtoupper($status) . "\n";
echo "Checked at: " . (string) ($report['checked_at'] ?? 'unknown') . "\n";
echo "Mode: " . ($strict ? 'strict' : 'standard') . "\n\n";

$summary = (array) ($report['summary'] ?? []);
echo "Summary: "
    . (int) ($summary['email_templates_checked'] ?? 0) . " email templates, "
    . (int) ($summary['workflow_templates_checked'] ?? 0) . " workflow templates, "
    . (int) ($summary['critical'] ?? 0) . " critical, "
    . (int) ($summary['warning'] ?? 0) . " warnings\n";

$findings = (array) ($report['findings'] ?? []);
if ($findings === []) {
    echo "\nNo template validation findings.\n";
} else {
    echo "\nFindings:\n";
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $template = (string) (($finding['template_key'] ?? '') ?: ($finding['slug'] ?? '') ?: ($finding['name'] ?? 'template'));
        echo '- [' . strtoupper((string) ($finding['severity'] ?? 'warning')) . '] '
            . (string) ($finding['rule'] ?? 'unknown')
            . ' on '
            . $template
            . ': '
            . (string) ($finding['message'] ?? '')
            . "\n";
    }
}

if ($exitCode === 0) {
    echo "\nTemplate validation passed.\n";
} elseif ($exitCode === 2) {
    echo "\nTemplate validation found warnings. Re-run without --strict to allow warnings, or fix them before live upload.\n";
} else {
    echo "\nTemplate validation failed. Fix critical template blockers before live upload.\n";
}

exit($exitCode);
