<?php

declare(strict_types=1);

$options = getopt('', ['url::', 'levels::', 'p95-ms::', 'timeout-seconds::', 'cookie::']);
$url = (string) ($options['url'] ?? 'http://localhost/crm/api/health.php');
$levelsRaw = (string) ($options['levels'] ?? '10,25,50');
$levels = array_values(array_filter(array_map('intval', explode(',', $levelsRaw)), static fn(int $level): bool => $level > 0 && $level <= 200));
$p95CeilingMs = max(50, (int) ($options['p95-ms'] ?? 1000));
$timeoutSeconds = max(2, min(60, (int) ($options['timeout-seconds'] ?? 10)));
$cookie = trim((string) ($options['cookie'] ?? ''));
if (!filter_var($url, FILTER_VALIDATE_URL) || $levels === []) {
    fwrite(STDERR, "Usage: php scripts/run_concurrency_load.php [--url=http://...] [--levels=10,25,50] [--p95-ms=1000] [--cookie='name=value']\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The cURL extension is required.\n");
    exit(2);
}

$runLevel = static function (int $concurrency) use ($url, $timeoutSeconds, $cookie): array {
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $handle = curl_init($url . $separator . 'concurrency_probe=' . rawurlencode(bin2hex(random_bytes(6))));
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'],
        ]);
        if ($cookie !== '') {
            curl_setopt($handle, CURLOPT_COOKIE, $cookie);
        }
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    $batchStartedAt = hrtime(true);
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $wallMs = (hrtime(true) - $batchStartedAt) / 1_000_000;

    $latencies = [];
    $errors = [];
    foreach ($handles as $index => $handle) {
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $latencies[] = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
        $curlError = curl_error($handle);
        if ($curlError !== '' || $httpCode < 200 || $httpCode >= 400) {
            $errors[] = ['request' => $index + 1, 'http_code' => $httpCode, 'error' => $curlError];
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    sort($latencies);
    $p95Index = max(0, min(count($latencies) - 1, (int) ceil(count($latencies) * 0.95) - 1));

    return [
        'concurrency' => $concurrency,
        'requests' => count($latencies),
        'errors' => count($errors),
        'wall_ms' => round($wallMs, 3),
        'average_ms' => round(array_sum($latencies) / max(1, count($latencies)), 3),
        'p95_ms' => round($latencies[$p95Index] ?? 0, 3),
        'max_ms' => round(max($latencies ?: [0]), 3),
        'error_details' => array_slice($errors, 0, 5),
    ];
};

// Warm the PHP opcode/filesystem and database connection path before measuring.
@file_get_contents($url . (str_contains($url, '?') ? '&' : '?') . 'warmup=1');
$results = [];
$passed = true;
foreach ($levels as $level) {
    $result = $runLevel($level);
    $result['passed'] = $result['errors'] === 0 && $result['p95_ms'] <= $p95CeilingMs;
    $passed = $passed && $result['passed'];
    $results[] = $result;
}

echo json_encode([
    'success' => $passed,
    'url' => $url,
    'acceptance' => ['http_errors' => 0, 'p95_ms_at_or_below' => $p95CeilingMs],
    'levels' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($passed ? 0 : 1);
