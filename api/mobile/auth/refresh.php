<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$input = mobileRequestBody();
$refreshToken = trim((string) ($input['refresh_token'] ?? ''));
if ($refreshToken === '') {
    mobileJson(['error' => 'Refresh token is required.'], 422);
}

try {
    $session = mobileService()->refresh($refreshToken);
    mobileJson(['success' => true, 'data' => $session]);
} catch (\Throwable $e) {
    error_log('Mobile auth refresh endpoint failed: ' . $e->getMessage());
    mobileJson(['error' => $e->getMessage()], 401);
}
