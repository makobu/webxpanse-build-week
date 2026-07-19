<?php

require_once __DIR__ . '/_helpers.php';

$auth = mobileRequireAuth();
$input = mobileRequestBody();
$source = mobileSyncRequestSource($input, 'mobile_open');
$cooldownState = mobileSyncCooldownState($auth, 'notifications', 45, $source);

[$userId] = mobileSyncUserContext($auth);
$freshness = mobileSyncBuildNotificationFreshness($userId);
mobileSyncWarmCache("mobile_sync:notifications:$userId", $freshness, 120);

mobileJson([
    'success' => true,
    'data' => mobileSyncResponse('notifications', $cooldownState, $freshness),
]);
