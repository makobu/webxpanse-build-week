<?php

if (!function_exists('loadEnvFile')) {
    /**
     * Load simple KEY=VALUE pairs into $_ENV.
     */
    function loadEnvFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $first = substr($value, 0, 1);
            $last = substr($value, -1);
            if ($value !== '' && (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
        }
    }
}
