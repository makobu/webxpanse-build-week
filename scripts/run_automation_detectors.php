<?php
declare(strict_types=1);

use CRM\Services\AutomationDetectorExecutionService;
use CRM\Services\AutomationReviewQueueService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);
$actorUserId = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--actor-user-id=')) {
        $actorUserId = max(1, (int) substr($arg, strlen('--actor-user-id=')));
    }
}

if ($help) {
    echo "Usage: php scripts/run_automation_detectors.php [--dry-run] [--json] [--actor-user-id=ID]\n\n";
    echo "Runs the read-only Super Admin production readiness detector set.\n";
    echo "--dry-run          Evaluate detectors without recording catalog runs.\n";
    echo "--json             Print machine-readable JSON instead of text.\n";
    echo "--actor-user-id=ID Attribute recorded runs to a Super Admin user.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$service = new AutomationDetectorExecutionService();
$result = $dryRun
    ? $service->evaluateProductionReadinessDetectors()
    : $service->runProductionReadinessDetectors($actorUserId);

if (!$dryRun) {
    $queue = new AutomationReviewQueueService();
    $result['escalation'] = $queue->applyEscalationPolicy($actorUserId);
    $result['notifications'] = $queue->dispatchEscalationNotifications($actorUserId);
}

if ($json) {
    echo json_encode(['ok' => true, 'dry_run' => $dryRun] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$summary = (array) ($result['summary'] ?? []);

echo "=== CRM Super Admin Automation Detectors ===\n";
echo "Mode: " . ($dryRun ? 'dry-run' : 'record') . "\n";
echo "Checked at: " . (string) ($result['checked_at'] ?? 'unknown') . "\n";
echo "Readiness status: " . strtoupper((string) ($result['readiness_status'] ?? 'unknown')) . "\n";
echo "Evaluated: " . (int) ($summary['evaluated'] ?? 0) . "\n";
echo "Findings: " . (int) ($summary['findings'] ?? 0) . "\n";
echo "Critical: " . (int) ($summary['critical'] ?? 0) . "\n";
echo "Warnings: " . (int) ($summary['warning'] ?? 0) . "\n";

if (!$dryRun) {
    echo "Recorded: " . (int) ($summary['recorded'] ?? 0) . "\n";
    echo "Skipped: " . (int) ($summary['skipped'] ?? 0) . "\n";
    $escalation = (array) ($result['escalation'] ?? []);
    $notifications = (array) ($result['notifications'] ?? []);
    echo "Escalated: " . (int) ($escalation['newly_escalated'] ?? 0) . "\n";
    echo "Notifications: " . (int) ($notifications['notifications_created'] ?? 0) . "\n";
}

echo "\nDetectors:\n";
foreach ((array) ($result['evaluations'] ?? []) as $evaluation) {
    if (!is_array($evaluation)) {
        continue;
    }
    $label = (string) ($evaluation['automation_key'] ?? 'unknown');
    $severity = strtoupper((string) ($evaluation['severity'] ?? 'info'));
    $finding = !empty($evaluation['finding']) ? 'finding' : 'clear';
    $runStatus = (string) ($evaluation['run_status'] ?? ($dryRun ? 'dry-run' : 'not-recorded'));
    $skipReason = (string) ($evaluation['skip_reason'] ?? '');
    echo '  - [' . $severity . '] ' . $label . ': ' . $finding . ' / ' . $runStatus;
    if ($skipReason !== '') {
        echo ' (' . $skipReason . ')';
    }
    echo "\n";
}

exit(0);
