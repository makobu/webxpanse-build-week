<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

use CRM\Database;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$auth = mobileRequireAuth(true);
$input = mobileRequestBody();
$workspaceId = (int) ($input['workspace_id'] ?? 0);
$workspaceSlug = trim((string) ($input['workspace_slug'] ?? ''));

if ($workspaceId <= 0 && $workspaceSlug === '') {
    mobileJson(['error' => 'Select a workspace to switch to.'], 422);
}

$user = Database::queryOne(
    'SELECT * FROM users WHERE id = ? LIMIT 1',
    [(int) ($auth['user_id'] ?? 0)]
) ?? [];

if (!$user) {
    mobileJson(['error' => 'The user for this session was not found.'], 401);
}

try {
    $session = mobileService()->issueTokenPair($user, [
        'device_id' => $input['device_id'] ?? ($auth['device_id'] ?? null),
        'device_name' => $input['device_name'] ?? ($auth['device_name'] ?? null),
        'platform' => $input['platform'] ?? ($auth['platform'] ?? null),
        'app_version' => $input['app_version'] ?? ($auth['app_version'] ?? null),
        'push_token' => $input['push_token'] ?? ($auth['push_token'] ?? null),
        'push_provider' => $auth['push_provider'] ?? null,
        'push_preferences_json' => $auth['push_preferences_json'] ?? null,
        'device_locale' => $auth['device_locale'] ?? null,
        'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
        'workspace_slug' => $workspaceSlug !== '' ? $workspaceSlug : null,
    ]);

    mobileService()->revokeByAccessToken(mobileBearerToken() ?? '');
    $activeWorkspace = (array) ($session['user']['active_workspace'] ?? []);
    $activeWorkspaceId = (int) ($activeWorkspace['id'] ?? 0);
    mobileJson([
        'success' => true,
        'data' => array_merge($session, [
            'workspace_switched' => true,
            'workspace_id' => $activeWorkspaceId,
            'active_workspace_id' => $activeWorkspaceId,
            'workspace_version' => $activeWorkspaceId > 0 ? 'workspace-' . $activeWorkspaceId : 'workspace-none',
            'invalidate_scopes' => [
                'home',
                'inbox',
                'conversations',
                'contacts',
                'deals',
                'tasks',
                'notifications',
                'search',
                'ai_feed',
            ],
            'client_action' => 'replace_session_and_clear_workspace_state',
        ]),
    ]);
} catch (\Throwable $e) {
    mobileJson(['error' => $e->getMessage()], 403);
}
