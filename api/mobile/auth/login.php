<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$input = mobileRequestBody();
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($email === '' || $password === '') {
    mobileJson(['error' => 'Email and password are required.'], 422);
}

try {
    $result = mobileService()->login($email, $password, [
        'device_id' => $input['device_id'] ?? null,
        'device_name' => $input['device_name'] ?? null,
        'platform' => $input['platform'] ?? null,
        'app_version' => $input['app_version'] ?? null,
        'push_token' => $input['push_token'] ?? null,
        'workspace_slug' => $input['workspace_slug'] ?? null,
        'workspace_id' => $input['workspace_id'] ?? null,
    ]);

    mobileJson(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    error_log('Mobile auth login endpoint failed for email=' . strtolower($email) . ': ' . $e->getMessage());
    mobileJson(['error' => $e->getMessage()], 401);
}
