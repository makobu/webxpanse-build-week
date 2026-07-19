<?php

require_once __DIR__ . '/_helpers.php';

$auth = mobileRequireAuth(true);
$input = mobileRequestBody();
$source = mobileSyncRequestSource($input, 'mobile_open');
$cooldownState = mobileSyncCooldownState($auth, 'pulse', 90, $source);

[$userId, $user] = mobileSyncUserContext($auth);
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);
$freshness = mobileSyncBuildPulseFreshness($userId, $user, $conversationOwnerScope);

mobileJson([
    'success' => true,
    'data' => mobileSyncResponse('pulse', $cooldownState, $freshness),
]);
