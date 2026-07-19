<?php

namespace CRM\Tests\Support;

trait EndpointHarness
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function runEndpointScript(string $relativePath, array $options = []): array
    {
        $root = dirname(__DIR__, 2);
        $endpointPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $runnerPath = __DIR__ . DIRECTORY_SEPARATOR . 'endpoint_runner.php';

        $query = (array) ($options['query'] ?? []);
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $requestUri = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($query !== []) {
            $requestUri .= '?' . http_build_query($query);
        }

        $config = [
            'endpoint' => $endpointPath,
            'method' => $method,
            'query' => $query,
            'post' => (array) ($options['post'] ?? []),
            'files' => (array) ($options['files'] ?? []),
            'cookie' => (array) ($options['cookie'] ?? []),
            'headers' => (array) ($options['headers'] ?? []),
            'raw_body' => (string) ($options['raw_body'] ?? ''),
            'session' => $options['session'] ?? null,
            'session_id' => (string) ($options['session_id'] ?? ''),
            'server' => array_merge([
                'REQUEST_URI' => $requestUri,
                'SCRIPT_NAME' => '/' . ltrim(str_replace('\\', '/', $relativePath), '/'),
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
            ], (array) ($options['server'] ?? [])),
            'env' => (array) ($options['env'] ?? []),
            'db_config' => method_exists($this, 'currentTestDatabaseConfig')
                ? $this->currentTestDatabaseConfig()
                : [
                    'host' => getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost'),
                    'name' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : ($_ENV['DB_NAME'] ?? 'crm_test'),
                    'user' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root'),
                    'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ''),
                    'charset' => getenv('DB_CHARSET') !== false ? (string) getenv('DB_CHARSET') : ($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
                ],
        ];

        $configPath = tempnam(sys_get_temp_dir(), 'crm-endpoint-config-');
        $resultPath = tempnam(sys_get_temp_dir(), 'crm-endpoint-result-');
        if ($configPath === false || $resultPath === false) {
            $this->fail('Could not allocate temporary files for endpoint runner.');
        }

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));

        $command = [PHP_BINARY, $runnerPath, $configPath, $resultPath];

        $descriptorSpec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $dbConfig = (array) ($config['db_config'] ?? []);
        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $env = array_merge($baseEnv, $_ENV, [
            'DB_HOST' => (string) ($dbConfig['host'] ?? 'localhost'),
            'DB_NAME' => (string) ($dbConfig['name'] ?? 'crm_test'),
            'DB_USER' => (string) ($dbConfig['user'] ?? 'root'),
            'DB_PASS' => (string) ($dbConfig['pass'] ?? ''),
            'DB_CHARSET' => (string) ($dbConfig['charset'] ?? 'utf8mb4'),
        ], (array) ($config['env'] ?? []));

        $process = proc_open($command, $descriptorSpec, $pipes, $root, $env);
        if (!is_resource($process)) {
            @unlink($configPath);
            @unlink($resultPath);
            $this->fail('Could not start endpoint runner process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $result = json_decode((string) file_get_contents($resultPath), true);

        @unlink($configPath);
        @unlink($resultPath);

        if (!is_array($result)) {
            $this->fail('Endpoint runner produced no result. STDERR: ' . trim((string) $stderr));
        }

        $result['stdout'] = (string) $stdout;
        $result['stderr'] = (string) $stderr;
        $result['exit_code'] = $exitCode;

        return $result;
    }

    /**
     * @param array<string,mixed> $sessionData
     * @return array<string,mixed>
     */
    protected function runWebEndpoint(string $relativePath, array $sessionData, array $options = []): array
    {
        $sessionId = 'crmtest' . bin2hex(random_bytes(8));

        return $this->runEndpointScript($relativePath, array_merge($options, [
            'session_id' => $sessionId,
            'session' => $sessionData,
            'cookie' => array_merge((array) ($options['cookie'] ?? []), [
                session_name() => $sessionId,
            ]),
        ]));
    }
}
