<?php

require_once dirname(dirname(__DIR__)) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$input = mobileRequestBody();
$challengeToken = trim((string) ($input['challenge_token'] ?? ''));
$code = trim((string) ($input['code'] ?? ''));

if ($challengeToken === '' || $code === '') {
    mobileJson(['error' => 'Challenge token and verification code are required.'], 422);
}

try {
    $session = mobileService()->verifyTwoFactor($challengeToken, $code, [
        'device_id' => $input['device_id'] ?? null,
        'device_name' => $input['device_name'] ?? null,
        'platform' => $input['platform'] ?? null,
        'app_version' => $input['app_version'] ?? null,
        'push_token' => $input['push_token'] ?? null,
        'workspace_slug' => $input['workspace_slug'] ?? null,
        'workspace_id' => $input['workspace_id'] ?? null,
    ]);

    mobileJson(['success' => true, 'data' => $session]);
} catch (\Throwable $e) {
    mobileJson(['error' => $e->getMessage()], 401);
}
