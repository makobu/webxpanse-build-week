<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$token = mobileBearerToken();
if (!$token) {
    mobileJson(['error' => 'Missing bearer token.'], 401);
}

mobileService()->revokeByAccessToken($token);
mobileJson([
    'success' => true,
    'action' => 'force_login',
    'message' => 'Logged out successfully.',
]);
