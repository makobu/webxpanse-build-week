<?php

require_once __DIR__ . '/_helpers.php';

$auth = mobileRequireAuth();
$input = mobileRequestBody();
$source = mobileSyncRequestSource($input, 'mobile_open');
$cooldownState = mobileSyncCooldownState($auth, 'inbox', 45, $source);

[$userId, $user] = mobileSyncUserContext($auth);
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);
$freshness = mobileSyncBuildInboxFreshness($userId, $user, $conversationOwnerScope);
$workspaceId = mobileSyncWorkspaceId($auth);
mobileSyncWarmCache("mobile_sync:inbox:$workspaceId:$userId", $freshness, 180);

mobileJson([
    'success' => true,
    'data' => array_merge(
        mobileWorkspaceContextPayload($auth),
        mobileSyncResponse('inbox', $cooldownState, $freshness)
    ),
]);
