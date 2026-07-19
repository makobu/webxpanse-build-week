<?php
/**
 * View Enrichment Debug Log
 * Displays the most recent enrichment debug logs
 */

$logFile = __DIR__ . '/../.cursor/enrichment_debug.log';

if (!file_exists($logFile)) {
    echo "No enrichment debug log found.\n";
    echo "Log file location: {$logFile}\n";
    exit(1);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES);
$entries = [];

foreach ($lines as $line) {
    $entry = json_decode($line, true);
    if ($entry) {
        $entries[] = $entry;
    }
}

// Group by session ID
$sessions = [];
foreach ($entries as $entry) {
    $sessionId = $entry['session_id'] ?? 'unknown';
    if (!isset($sessions[$sessionId])) {
        $sessions[$sessionId] = [];
    }
    $sessions[$sessionId][] = $entry;
}

// Show most recent session first
$sessions = array_reverse($sessions, true);

echo "Enrichment Debug Log Viewer\n";
echo "==========================\n\n";

if (empty($sessions)) {
    echo "No log entries found.\n";
    exit(0);
}

$sessionCount = 0;
foreach ($sessions as $sessionId => $sessionEntries) {
    $sessionCount++;
    if ($sessionCount > 5) {
        echo "\n... (showing only 5 most recent sessions)\n";
        break;
    }
    
    echo "Session: {$sessionId}\n";
    echo str_repeat('-', 80) . "\n";
    
    foreach ($sessionEntries as $entry) {
        $timestamp = $entry['timestamp'] ?? 'unknown';
        $step = $entry['step'] ?? 'unknown';
        $message = $entry['message'] ?? 'no message';
        $data = $entry['data'] ?? [];
        
        echo "[{$timestamp}] {$step}: {$message}\n";
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }
                if (strlen($value) > 100) {
                    $value = substr($value, 0, 100) . '...';
                }
                echo "  {$key}: {$value}\n";
            }
        }
        echo "\n";
    }
    echo "\n";
}

echo "\nTotal sessions: " . count($sessions) . "\n";
echo "Log file: {$logFile}\n";
