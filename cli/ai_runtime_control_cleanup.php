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
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AutomationJobHealthService;

Database::init(require __DIR__ . '/../config/database.php');

$jobHealth = new AutomationJobHealthService();
$jobHealth->markStarted('ai_runtime_control_cleanup');
$startedAt = microtime(true);

try {
    $service = new AIRuntimeControlService();
    $cleared = $service->clearExpiredControls();
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markSuccess(
        'ai_runtime_control_cleanup',
        sprintf('Cleared %d expired runtime control(s).', $cleared),
        $durationMs,
        ['cleared' => $cleared]
    );

    echo "Cleared {$cleared} expired runtime control(s).\n";
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markFailure('ai_runtime_control_cleanup', $e->getMessage(), $durationMs);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
