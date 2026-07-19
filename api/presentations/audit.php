<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Database;

presentationRequire('presentation.audit');

try {
    $sessionId = (int) ($_GET['session_id'] ?? 0);
    $where = [];
    $params = [];
    if ($sessionId > 0) {
        $where[] = 'a.presentation_session_id = ?';
        $params[] = $sessionId;
    }

    $sql = "SELECT a.id, a.presentation_session_id, a.workspace_id, a.actor_user_id, a.recipient_id,
                   a.channel, a.delivery_kind, a.scenario_key, a.live_requested, a.live_sent, a.status,
                   a.provider_message_id, a.recipient_hash, a.payload_hash, a.subject, a.error_message,
                   a.metadata_json, a.created_at
            FROM presentation_delivery_audit a";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY a.created_at DESC LIMIT 100';

    presentationJsonResponse(['success' => true, 'audit' => Database::query($sql, $params)]);
} catch (\Throwable $e) {
    error_log('presentations/audit failed: ' . $e->getMessage());
    presentationJsonError('Unable to load presentation audit history.', 500);
}
