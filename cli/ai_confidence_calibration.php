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

use CRM\Database;
use CRM\Modules\UserPreferences;
use CRM\Services\AIConfidenceCalibrationService;
use CRM\Services\AutomationJobHealthService;

Database::init(require __DIR__ . '/../config/database.php');

$jobHealth = new AutomationJobHealthService();
$jobHealth->markStarted('ai_confidence_calibration');
$startedAt = microtime(true);

try {
    $service = new AIConfidenceCalibrationService();
    $prefs = new UserPreferences();

    echo "Running AI confidence calibration...\n";
    $result = $service->applyAutonomousTuning();
    $prefs->setAICalibrationLastRunAt(1, date('Y-m-d H:i:s'));

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markSuccess(
        'ai_confidence_calibration',
        'Applied changes: ' . count((array) ($result['applied'] ?? [])) . '; skipped entries: ' . count((array) ($result['skipped'] ?? [])),
        $durationMs,
        [
            'applied' => count((array) ($result['applied'] ?? [])),
            'skipped' => count((array) ($result['skipped'] ?? [])),
        ]
    );

    echo 'Applied changes: ' . count((array) ($result['applied'] ?? [])) . PHP_EOL;
    echo 'Skipped entries: ' . count((array) ($result['skipped'] ?? [])) . PHP_EOL;
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markFailure('ai_confidence_calibration', $e->getMessage(), $durationMs);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
