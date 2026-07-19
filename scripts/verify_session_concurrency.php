<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$probe = realpath(__DIR__ . '/../tests/Support/session_lock_probe.php');
if ($probe === false) {
    fwrite(STDERR, "Session-lock probe is missing.\n");
    exit(2);
}

$sessionId = 'concurrency-' . bin2hex(random_bytes(12));
session_id($sessionId);
session_start();
$_SESSION['probe_seeded_at'] = time();
session_write_close();

$runBatch = static function (string $mode, int $workers, int $holdMilliseconds) use ($probe, $sessionId): array {
    $processes = [];
    $batchStartedAt = hrtime(true);
    for ($i = 0; $i < $workers; $i++) {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($probe)
            . ' ' . escapeshellarg($sessionId)
            . ' ' . escapeshellarg($mode)
            . ' ' . $holdMilliseconds;
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($probe));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start session-lock probe process.');
        }
        $processes[] = [$process, $pipes];
    }

    $children = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Session-lock probe failed: ' . trim((string) $stderr));
        }
        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Session-lock probe returned invalid output.');
        }
        $children[] = $decoded;
    }

    return [
        'mode' => $mode,
        'workers' => $workers,
        'hold_ms' => $holdMilliseconds,
        'wall_ms' => round((hrtime(true) - $batchStartedAt) / 1_000_000, 3),
        'max_child_ms' => round(max(array_map(static fn(array $row): float => (float) ($row['elapsed_ms'] ?? 0), $children)), 3),
    ];
};

try {
    $workers = 10;
    $holdMilliseconds = 250;
    $holding = $runBatch('hold', $workers, $holdMilliseconds);
    $released = $runBatch('release', $workers, $holdMilliseconds);
    $releaseCeilingMs = 1500;
    $passed = $released['wall_ms'] < $releaseCeilingMs
        && $released['wall_ms'] < ($holding['wall_ms'] * 0.6);
    $result = [
        'success' => $passed,
        'serialized_baseline' => $holding,
        'early_release' => $released,
        'acceptance' => [
            'release_wall_ceiling_ms' => $releaseCeilingMs,
            'release_under_60_percent_of_serialized' => true,
        ],
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($passed ? 0 : 1);
} finally {
    $sessionPath = session_save_path();
    if ($sessionPath !== '' && is_dir($sessionPath)) {
        $sessionFile = rtrim($sessionPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (is_file($sessionFile)) {
            @unlink($sessionFile);
        }
    }
}
