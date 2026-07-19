<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoWorkspaceService;

if (!Auth::check() || !(new DemoSessionScopeService())->canOperateDemo(Auth::user())) {
    demoJsonError('Forbidden', 403);
}

$workspace = (new DemoWorkspaceService())->resolve();
$workspaceId = (int) ($workspace['id'] ?? 0);
$summary = Database::queryOne(
    "SELECT
        SUM(status = 'active' AND expires_at > NOW()) AS active_sessions,
        SUM(status IN ('ended', 'expired', 'revoked')) AS inactive_sessions,
        SUM(cleanup_status = 'failed') AS cleanup_failures,
        COALESCE(SUM(message_count), 0) AS simulated_messages
     FROM demo_visitor_sessions
     WHERE workspace_id = ?",
    [$workspaceId]
) ?: [];

demoJsonResponse(['success' => true, 'workspace' => $workspace, 'summary' => $summary]);
