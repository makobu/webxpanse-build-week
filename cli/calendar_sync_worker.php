<?php
/**
 * Calendar Sync Worker
 * Run periodically (e.g. every 15 min) to sync calendar integrations
 * Usage: php cli/calendar_sync_worker.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Services\CalendarBusyCacheService;
use CRM\Services\CalendarSyncService;

Database::init(require __DIR__ . '/../config/database.php');

$busyResult = (new CalendarBusyCacheService())->refreshAll();
$integrations = Database::query("SELECT * FROM calendar_integrations WHERE sync_enabled = 1");

$synced = 0;
$errors = [];

foreach ($integrations as $int) {
    try {
        $result = (new CalendarSyncService())->syncIntegration((int) $int['id']);
        $synced += (int) ($result['imported'] ?? 0) + (int) ($result['exported'] ?? 0);
    } catch (\Exception $e) {
        $errors[] = "Integration {$int['id']} ({$int['provider']}): sync failed - " . $e->getMessage();
    }
}

$busyErrors = (array) ($busyResult['errors'] ?? []);
foreach ($busyErrors as $busyError) {
    $errors[] = (string) $busyError;
}

if (!empty($errors)) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
}

echo "Synced $synced events. Refreshed " . (int) ($busyResult['updated'] ?? 0) . " busy interval(s). " . (count($errors) ? count($errors) . " error(s)." : "OK.") . "\n";
