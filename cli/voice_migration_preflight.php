<?php

require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

\CRM\Database::init(require __DIR__ . '/../config/database.php');
$databaseName = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '');
$tables = ['communications', 'conversation_threads'];
$report = [
    'database' => $databaseName,
    'checked_at' => gmdate(DATE_ATOM),
    'safe_to_schedule' => true,
    'requires_controlled_window' => false,
    'tables' => [],
    'warnings' => [],
];

foreach ($tables as $table) {
    $metadata = \CRM\Database::queryOne(
        'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE
         FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
        [$databaseName, $table]
    ) ?: [];
    $column = \CRM\Database::queryOne(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'channel' LIMIT 1",
        [$databaseName, $table]
    ) ?: [];
    $exactRows = (int) (\CRM\Database::queryOne("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
    $alreadyApplied = str_contains((string) ($column['COLUMN_TYPE'] ?? ''), "'voice'");
    $large = $exactRows >= 100000;
    $report['requires_controlled_window'] = $report['requires_controlled_window'] || (!$alreadyApplied && $large);
    $report['tables'][$table] = [
        'exact_rows' => $exactRows,
        'estimated_bytes' => (int) ($metadata['DATA_LENGTH'] ?? 0) + (int) ($metadata['INDEX_LENGTH'] ?? 0),
        'engine' => (string) ($metadata['ENGINE'] ?? ''),
        'channel_type' => (string) ($column['COLUMN_TYPE'] ?? ''),
        'voice_enum_applied' => $alreadyApplied,
        'large_table' => $large,
    ];
}

try {
    $longTransactions = \CRM\Database::query(
        'SELECT trx_id, trx_started, trx_rows_locked, trx_rows_modified
         FROM information_schema.INNODB_TRX
         WHERE trx_started < DATE_SUB(NOW(), INTERVAL 30 SECOND)
         ORDER BY trx_started ASC LIMIT 20'
    );
    $report['long_transactions'] = $longTransactions;
    if ($longTransactions !== []) {
        $report['safe_to_schedule'] = false;
        $report['warnings'][] = 'Long-running InnoDB transactions must clear before the enum migration window.';
    }
} catch (Throwable $e) {
    $report['long_transactions'] = null;
    $report['warnings'][] = 'The database user could not inspect INNODB_TRX; verify long transactions operationally.';
}

if ($report['requires_controlled_window']) {
    $report['warnings'][] = 'At least one pending enum change targets a large table. Schedule backup, maintenance window, and lock monitoring.';
}
$report['next_action'] = $report['safe_to_schedule']
    ? ($report['requires_controlled_window'] ? 'Schedule the controlled migration window.' : 'Preflight passed; retain backup and staging proof before production migration.')
    : 'Resolve the reported blockers and rerun this preflight.';

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['safe_to_schedule'] ? 0 : 2);
