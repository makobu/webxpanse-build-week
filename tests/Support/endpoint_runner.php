<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php endpoint_runner.php <config-json> <result-json>\n");
    exit(1);
}

$configPath = $argv[1];
$resultPath = $argv[2];
$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config)) {
    file_put_contents($resultPath, json_encode([
        'status' => 500,
        'headers' => [],
        'body' => '',
        'stdout' => '',
        'error' => 'Invalid endpoint runner config.',
    ], JSON_PRETTY_PRINT));
    exit(1);
}

$endpoint = (string) ($config['endpoint'] ?? '');
$server = is_array($config['server'] ?? null) ? $config['server'] : [];
$query = is_array($config['query'] ?? null) ? $config['query'] : [];
$post = is_array($config['post'] ?? null) ? $config['post'] : [];
$files = is_array($config['files'] ?? null) ? $config['files'] : [];
$cookie = is_array($config['cookie'] ?? null) ? $config['cookie'] : [];
$headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
$dbConfig = is_array($config['db_config'] ?? null) ? $config['db_config'] : [];
$envOverrides = is_array($config['env'] ?? null) ? $config['env'] : [];
$rawBody = (string) ($config['raw_body'] ?? '');
$session = is_array($config['session'] ?? null) ? $config['session'] : null;
$sessionId = trim((string) ($config['session_id'] ?? ''));
$sessionSavePath = dirname($resultPath);

$_GET = $query;
$_POST = $post;
$_FILES = $files;
$_REQUEST = array_merge($query, $post);
$_COOKIE = $cookie;
$_SERVER = array_merge([
    'REQUEST_METHOD' => (string) ($config['method'] ?? 'GET'),
    'REQUEST_URI' => (string) ($server['REQUEST_URI'] ?? '/'),
    'SCRIPT_NAME' => (string) ($server['SCRIPT_NAME'] ?? basename($endpoint)),
    'HTTP_HOST' => (string) ($server['HTTP_HOST'] ?? 'localhost'),
    'REMOTE_ADDR' => (string) ($server['REMOTE_ADDR'] ?? '127.0.0.1'),
], $server);

foreach ($headers as $name => $value) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $name));
    $_SERVER[$serverKey] = (string) $value;
    if (strcasecmp((string) $name, 'Content-Type') === 0) {
        $_SERVER['CONTENT_TYPE'] = (string) $value;
    }
}

if ($rawBody !== '' && $_POST === []) {
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $_POST = $decoded;
            $_REQUEST = array_merge($_GET, $_POST);
        }
    }
}

foreach ([
    'DB_HOST' => (string) ($dbConfig['host'] ?? 'localhost'),
    'DB_NAME' => (string) ($dbConfig['name'] ?? 'crm_test'),
    'DB_USER' => (string) ($dbConfig['user'] ?? 'root'),
    'DB_PASS' => (string) ($dbConfig['pass'] ?? ''),
    'DB_CHARSET' => (string) ($dbConfig['charset'] ?? 'utf8mb4'),
] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

foreach ($envOverrides as $key => $value) {
    $key = trim((string) $key);
    if ($key === '') {
        continue;
    }

    $stringValue = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
    $_ENV[$key] = $stringValue;
    putenv($key . '=' . $stringValue);
}

try {
    $dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'crm_test');
    $charset = trim((string) ($_ENV['DB_CHARSET'] ?? ''));
    if ($charset !== '') {
        $dsn .= ';charset=' . $charset;
    }

    new PDO(
        $dsn,
        (string) ($_ENV['DB_USER'] ?? 'root'),
        (string) ($_ENV['DB_PASS'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
} catch (Throwable $e) {
    file_put_contents($resultPath, json_encode([
        'status' => 500,
        'headers' => [],
        'body' => json_encode([
            'success' => false,
            'error' => 'Endpoint runner DB bootstrap failed: ' . $e->getMessage(),
        ]),
    ], JSON_PRETTY_PRINT));
    exit(1);
}

if ($sessionId !== '') {
    $_COOKIE[session_name()] = $sessionId;
}

ob_start();

if ($sessionId !== '' && $session !== null) {
    ini_set('session.save_path', $sessionSavePath);
    session_save_path($sessionSavePath);
    session_id($sessionId);
    session_start();
    $_SESSION = $session;
    session_write_close();
}

register_shutdown_function(static function () use ($resultPath): void {
    $body = ob_get_contents();
    if ($body === false) {
        $body = '';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $status = http_response_code();
    if ($status === false || $status === 0) {
        $status = 200;
    }

    $result = [
        'status' => $status,
        'headers' => headers_list(),
        'body' => $body,
    ];

    $lastError = error_get_last();
    if ($lastError !== null) {
        $result['fatal_error'] = $lastError;
    }

    file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT));
});

require $endpoint;
