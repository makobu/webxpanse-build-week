<?php

require_once __DIR__ . '/_helpers.php';

$auth = mobileRequireAuth();
$input = mobileRequestBody();
$source = mobileSyncRequestSource($input, 'mobile_open');
$cooldownState = mobileSyncCooldownState($auth, 'reminders', 60, $source);

[$userId] = mobileSyncUserContext($auth);
$freshness = mobileSyncBuildReminderFreshness($userId);
mobileSyncWarmCache("mobile_sync:reminders:$userId", $freshness, 120);

mobileJson([
    'success' => true,
    'data' => mobileSyncResponse('reminders', $cooldownState, $freshness),
]);
