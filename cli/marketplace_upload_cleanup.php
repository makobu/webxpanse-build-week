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
use CRM\Services\MarketplaceUploadCleanupService;

/**
 * @return array{delete:bool,older_than_days:int,videos_only:bool,json:bool,help:bool}
 */
function marketplaceCleanupParseArgs(array $argv): array
{
    $options = [
        'delete' => false,
        'older_than_days' => 7,
        'videos_only' => false,
        'json' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--delete') {
            $options['delete'] = true;
            continue;
        }
        if ($arg === '--videos-only') {
            $options['videos_only'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--older-than-days') {
            $i++;
            if (!isset($argv[$i])) {
                throw new InvalidArgumentException('--older-than-days requires a numeric value.');
            }
            $options['older_than_days'] = marketplaceCleanupParseDays((string) $argv[$i]);
            continue;
        }
        if (str_starts_with($arg, '--older-than-days=')) {
            $options['older_than_days'] = marketplaceCleanupParseDays(substr($arg, strlen('--older-than-days=')));
            continue;
        }

        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

function marketplaceCleanupParseDays(string $value): int
{
    $value = trim($value);
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        throw new InvalidArgumentException('--older-than-days must be a non-negative integer.');
    }

    return (int) $value;
}

function marketplaceCleanupUsage(): string
{
    return <<<TXT
Marketplace upload cleanup

Usage:
  php cli/marketplace_upload_cleanup.php [options]

Options:
  --delete                 Delete old orphaned marketplace uploads. Omit for dry-run.
  --older-than-days=N      Only candidate unreferenced files older than N days. Default: 7.
  --videos-only            Only candidate video files.
  --json                   Print the full report as JSON.
  --help                   Show this help message.

Examples:
  php cli/marketplace_upload_cleanup.php
  php cli/marketplace_upload_cleanup.php --older-than-days=14
  php cli/marketplace_upload_cleanup.php --videos-only
  php cli/marketplace_upload_cleanup.php --delete --older-than-days=7
  php cli/marketplace_upload_cleanup.php --json

TXT;
}

function marketplaceCleanupFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    if ($unit === 0) {
        return (string) $bytes . ' B';
    }

    return number_format($value, 1) . ' ' . $units[$unit];
}

/**
 * @param array<string,mixed> $report
 */
function marketplaceCleanupPrintText(array $report): void
{
    $summary = (array) ($report['summary'] ?? []);
    echo 'Marketplace upload cleanup' . PHP_EOL;
    echo 'Mode: ' . (string) ($report['mode'] ?? 'dry-run') . PHP_EOL;
    echo 'Root: ' . (string) ($report['root'] ?? '') . PHP_EOL;
    echo 'Retention: ' . (int) ($report['older_than_days'] ?? 7) . ' days' . PHP_EOL;
    echo 'Videos only: ' . ((bool) ($report['videos_only'] ?? false) ? 'yes' : 'no') . PHP_EOL;
    echo PHP_EOL;
    echo 'Scanned: ' . (int) ($summary['scanned_files'] ?? 0) . ' files (' . marketplaceCleanupFormatBytes((int) ($summary['scanned_bytes'] ?? 0)) . ')' . PHP_EOL;
    echo 'Referenced: ' . (int) ($summary['referenced_files'] ?? 0) . ' files (' . marketplaceCleanupFormatBytes((int) ($summary['referenced_bytes'] ?? 0)) . ')' . PHP_EOL;
    echo 'Candidates: ' . (int) ($summary['candidate_files'] ?? 0) . ' files (' . marketplaceCleanupFormatBytes((int) ($summary['candidate_bytes'] ?? 0)) . ')' . PHP_EOL;
    echo 'Deleted: ' . (int) ($summary['deleted_files'] ?? 0) . ' files (' . marketplaceCleanupFormatBytes((int) ($summary['deleted_bytes'] ?? 0)) . ')' . PHP_EOL;
    echo 'Skipped: ' . (int) ($summary['skipped_files'] ?? 0) . ' files' . PHP_EOL;
    echo 'Errors: ' . (int) ($summary['errors'] ?? 0) . PHP_EOL;
    echo PHP_EOL;

    $candidates = array_values(array_filter(
        (array) ($report['files'] ?? []),
        static fn(array $file): bool => (bool) ($file['candidate'] ?? false)
    ));
    if ($candidates === []) {
        echo 'No deletion candidates found.' . PHP_EOL;
    } else {
        echo 'Candidate files:' . PHP_EOL;
        foreach ($candidates as $file) {
            $label = (string) ($report['mode'] ?? 'dry-run') === 'delete'
                ? ((bool) ($file['deleted'] ?? false) ? 'deleted' : 'not deleted')
                : 'would delete';
            echo '  [' . $label . '] '
                . (string) ($file['path'] ?? '')
                . ' - ' . marketplaceCleanupFormatBytes((int) ($file['size'] ?? 0))
                . ', modified ' . date('Y-m-d H:i:s', (int) ($file['mtime'] ?? 0))
                . ', reason: ' . (string) ($file['reason'] ?? '')
                . PHP_EOL;
        }
        if ((string) ($report['mode'] ?? 'dry-run') === 'dry-run') {
            echo PHP_EOL . 'Run with --delete to remove these candidates.' . PHP_EOL;
        }
    }

    $errors = (array) ($report['errors'] ?? []);
    if ($errors !== []) {
        echo PHP_EOL . 'Errors:' . PHP_EOL;
        foreach ($errors as $error) {
            echo '  - ' . (string) ($error['message'] ?? 'Unknown error');
            if (!empty($error['path'])) {
                echo ' (' . (string) $error['path'] . ')';
            } elseif (!empty($error['table'])) {
                echo ' (' . (string) $error['table'] . ')';
            }
            echo PHP_EOL;
        }
    }
}

try {
    $options = marketplaceCleanupParseArgs($argv);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . PHP_EOL . marketplaceCleanupUsage());
    exit(1);
}

if ($options['help']) {
    echo marketplaceCleanupUsage();
    exit(0);
}

Database::init(require __DIR__ . '/../config/database.php');

$service = new MarketplaceUploadCleanupService(dirname(__DIR__));
$report = $service->scan([
    'delete' => $options['delete'],
    'older_than_days' => $options['older_than_days'],
    'videos_only' => $options['videos_only'],
]);

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    marketplaceCleanupPrintText($report);
}

exit(((int) (($report['summary']['errors'] ?? 0)) > 0) ? 1 : 0);
