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

use CRM\Services\BusinessTakeoverConfidenceAuditService;

$args = $argv ?? [];
$json = false;
$scope = 'all';
$userId = null;

foreach ($args as $arg) {
    if ($arg === '--json') {
        $json = true;
        continue;
    }
    if (str_starts_with((string) $arg, '--scope=')) {
        $scope = (string) substr((string) $arg, 8);
        continue;
    }
    if (str_starts_with((string) $arg, '--user-id=')) {
        $userId = (int) substr((string) $arg, 10);
    }
}

$dbConfig = require __DIR__ . '/../config/database.php';
$service = new BusinessTakeoverConfidenceAuditService();
$audit = $service->audit([
    'db_config' => is_array($dbConfig) ? $dbConfig : [],
    'scope' => $scope,
    'user_id' => $userId,
]);
$exitCode = BusinessTakeoverConfidenceAuditService::exitCodeFor($audit);

if ($json) {
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

echo "Business Takeover Confidence Audit\n";
echo sprintf("Generated: %s\n", (string) ($audit['generated_at'] ?? ''));
echo sprintf("Scope: %s\n", (string) ($audit['scope'] ?? 'all'));
echo sprintf("Subject User ID: %s\n", (string) ($audit['subject_user_id'] ?? 0));
echo sprintf("Tenant Key: %s\n", (string) ($audit['tenant_key'] ?? 'global:default'));
echo sprintf("Overall Status: %s\n", strtoupper((string) ($audit['overall_status'] ?? 'block')));
echo sprintf("Failure Class: %s\n", (string) ($audit['failure_class'] ?? 'mixed'));
echo sprintf("Platform Score: %d\n", (int) ($audit['platform_score'] ?? 0));
echo sprintf("Takeover Ready: %s\n\n", !empty($audit['takeover_ready']) ? 'yes' : 'no');

echo "Domains\n";
foreach ((array) ($audit['domains'] ?? []) as $key => $domain) {
    echo sprintf(
        "- %s: status=%s score=%d classification=%s\n",
        (string) ($domain['label'] ?? $key),
        (string) ($domain['status'] ?? 'block'),
        (int) ($domain['score'] ?? 0),
        (string) ($domain['classification'] ?? 'product_regression')
    );
    $blockers = array_slice((array) ($domain['blockers'] ?? []), 0, 2);
    foreach ($blockers as $blocker) {
        echo sprintf("  blocker: %s\n", (string) $blocker);
    }
    $signals = array_slice((array) ($domain['signals'] ?? []), 0, 2);
    foreach ($signals as $signal) {
        echo sprintf("  signal: %s\n", (string) $signal);
    }
}

echo "\nHard Gates\n";
foreach ((array) ($audit['hard_gates'] ?? []) as $gate) {
    echo sprintf(
        "- %s: %s [%s] %s\n",
        (string) ($gate['label'] ?? ($gate['key'] ?? 'gate')),
        strtoupper((string) ($gate['status'] ?? 'block')),
        (string) ($gate['classification'] ?? 'product_regression'),
        (string) ($gate['reason'] ?? '')
    );
}

echo "\nRecommended Actions\n";
foreach ((array) ($audit['recommended_actions'] ?? []) as $action) {
    echo sprintf("- %s\n", (string) $action);
}

$snapshot = (array) ($audit['verification_snapshot'] ?? []);
$dbProbes = (array) ($snapshot['database_probes'] ?? []);
echo "\nVerification Snapshot\n";
foreach (['configured', 'localhost', 'loopback'] as $probeKey) {
    $probe = (array) ($dbProbes[$probeKey] ?? []);
    echo sprintf(
        "- db:%s => %s (%s)\n",
        $probeKey,
        !empty($probe['success']) ? 'ok' : 'fail',
        (string) ($probe['message'] ?? 'n/a')
    );
}
$providerProbe = (array) ($snapshot['provider_probe'] ?? []);
echo sprintf(
    "- provider => %s (%s)\n",
    !empty($providerProbe['core_ai_provider_ready']) ? 'ok' : 'fail',
    (string) ($providerProbe['message'] ?? 'n/a')
);
echo sprintf(
    "- transient_environment_risk => %s\n",
    !empty($snapshot['transient_environment_risk']) ? 'yes' : 'no'
);

exit($exitCode);
