<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$auth = mobileRequireAuth();
$input = mobileRequestBody();

try {
    mobileService()->registerPushToken(
        (int) $auth['id'],
        $input['push_token'] ?? null,
        $input['app_version'] ?? null,
        $input['push_provider'] ?? null,
        isset($input['preferences']) && is_array($input['preferences']) ? $input['preferences'] : null,
        $input['device_locale'] ?? null
    );
} catch (\Throwable $e) {
    error_log('Mobile push registration failed for token_row_id=' . (int) ($auth['id'] ?? 0) . ': ' . $e->getMessage());
    mobileJson(['error' => 'Could not register this device for notifications.'], 422);
}

mobileJson([
    'success' => true,
    'data' => [
        'registered' => true,
        'push_token_present' => !empty($input['push_token']),
        'app_version' => (string) ($input['app_version'] ?? ''),
        'push_provider' => (string) ($input['push_provider'] ?? ''),
        'preferences' => isset($input['preferences']) && is_array($input['preferences']) ? $input['preferences'] : null,
    ],
]);
