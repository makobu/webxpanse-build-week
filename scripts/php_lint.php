<?php

$root = dirname(__DIR__);
$tracked = [];
exec('git ls-files -- ' . escapeshellarg('*.php'), $gitOutput, $gitExitCode);
if ($gitExitCode === 0 && $gitOutput !== []) {
    $tracked = $gitOutput;
}

if ($tracked === []) {
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $current): bool {
            if (!$current->isDir()) {
                return true;
            }

            return !in_array($current->getFilename(), ['.git', 'cache', 'node_modules', 'tmp', 'vendor', 'zcompre'], true);
        }
    );
    $iterator = new RecursiveIteratorIterator($filter);
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $tracked[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}

$failures = [];
$running = [];
$maxProcesses = max(2, min(8, (int) getenv('PHP_LINT_PROCESSES') ?: 4));

foreach ($tracked as $relativePath) {
    $relativePath = str_replace('\\', '/', (string) $relativePath);
    if ($relativePath === '' || preg_match('#^(vendor|node_modules|cache|tmp|zcompre)/#', $relativePath)) {
        continue;
    }

    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        continue;
    }

    $running[] = startLintProcess($path);

    while (count($running) >= $maxProcesses) {
        collectFinished($running, $failures);
        if (count($running) >= $maxProcesses) {
            usleep(10000);
        }
    }
}

while ($running !== []) {
    collectFinished($running, $failures);
    usleep(10000);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n\n", $failures) . "\n");
    exit(1);
}

echo "PHP lint passed.\n";

function startLintProcess(string $path): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-l', $path],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return ['process' => null, 'pipes' => [], 'path' => $path, 'done' => true, 'output' => 'Unable to start lint process for ' . $path];
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    return ['process' => $process, 'pipes' => $pipes, 'path' => $path, 'output' => ''];
}

/**
 * @param array<int,array<string,mixed>> $running
 * @param array<int,string> $failures
 */
function collectFinished(array &$running, array &$failures): void
{
    foreach ($running as $index => $task) {
        if (($task['process'] ?? null) === null) {
            $failures[] = (string) ($task['output'] ?? '');
            unset($running[$index]);
            continue;
        }

        $status = proc_get_status($task['process']);
        foreach ($task['pipes'] as $pipe) {
            $task['output'] .= stream_get_contents($pipe);
        }

        if ($status['running']) {
            $running[$index] = $task;
            continue;
        }

        foreach ($task['pipes'] as $pipe) {
            fclose($pipe);
        }
        // On Unix, proc_close() may return -1 after proc_get_status() has
        // already observed the child exit. Preserve the status exit code so a
        // successful lint is not reported as a CI failure.
        $statusExitCode = (int) ($status['exitcode'] ?? -1);
        $closeExitCode = proc_close($task['process']);
        $exitCode = $statusExitCode >= 0 ? $statusExitCode : $closeExitCode;
        if ($exitCode !== 0) {
            $failures[] = trim((string) $task['output']) ?: 'Syntax check failed for ' . (string) $task['path'];
        }
        unset($running[$index]);
    }

    $running = array_values($running);
}
