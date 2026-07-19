<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_feature_helpers.php';

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

mobileJson([
    'success' => true,
    'data' => [
        'api_version' => 4,
        'generated_at' => gmdate('c'),
        'features' => mobileOperationCapabilities($workspaceId, $user),
    ],
]);
