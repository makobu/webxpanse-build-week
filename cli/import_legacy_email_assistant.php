<?php

require_once __DIR__ . '/../vendor/autoload.php';

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($value);
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\LegacyEmailAssistantPluginImporter;

Database::init(require __DIR__ . '/../config/database.php');

try {
    $result = (new LegacyEmailAssistantPluginImporter())->importDefaultWorkspace();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Email Assistant import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
