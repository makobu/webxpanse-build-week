<?php

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';

$envFile = $baseDir . '/.env';
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

require_once $baseDir . '/config/constants.php';

use CRM\Database;
use CRM\Services\AIAutomationIncidentService;
use CRM\Services\AutomationJobHealthService;

$jobHealth = new AutomationJobHealthService();
$start = microtime(true);

try {
    Database::init(require $baseDir . '/config/database.php');

    $jobHealth->markStarted('ai_incident_check');
    $service = new AIAutomationIncidentService();
    $result = $service->evaluateAndAlert();

    $durationMs = (int) round((microtime(true) - $start) * 1000);
    $message = sprintf(
        'Evaluated %d incidents, created %d alerts.',
        count($result['incidents'] ?? []),
        count($result['alerts_created'] ?? [])
    );
    $jobHealth->markSuccess('ai_incident_check', $message, $durationMs, [
        'incident_count' => count($result['incidents'] ?? []),
        'alerts_created' => count($result['alerts_created'] ?? []),
        'skipped' => (array) ($result['skipped'] ?? []),
    ]);

    echo date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $start) * 1000);
    $jobHealth->markFailure('ai_incident_check', $e->getMessage(), $durationMs);
    echo date('Y-m-d H:i:s') . ' - Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
