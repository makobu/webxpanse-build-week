<?php

require_once dirname(dirname(dirname(__DIR__))) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$input = mobileRequestBody();
$challengeToken = trim((string) ($input['challenge_token'] ?? ''));

if ($challengeToken === '') {
    mobileJson(['error' => 'Challenge token is required.'], 422);
}

try {
    mobileJson([
        'success' => true,
        'data' => mobileService()->startTwoFactorSetup($challengeToken),
    ]);
} catch (\Throwable $e) {
    mobileJson(['error' => $e->getMessage()], 401);
}
