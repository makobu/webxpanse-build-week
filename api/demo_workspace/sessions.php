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

$workspaceId = (new DemoWorkspaceService())->id();
$sessions = Database::query(
    "SELECT id, session_uuid, user_id, guest_user_id, access_source, status, risk_score, message_count,
            consent_contact, consent_privacy, expires_at, last_seen_at, cleanup_status, created_at
     FROM demo_visitor_sessions
     WHERE workspace_id = ?
     ORDER BY created_at DESC
     LIMIT 100",
    [$workspaceId]
);

demoJsonResponse(['success' => true, 'sessions' => $sessions]);
