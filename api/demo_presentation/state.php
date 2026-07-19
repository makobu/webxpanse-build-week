<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Database;
use CRM\Services\DemoPresentationDeliveryService;
use CRM\Services\DemoPresentationSessionService;

demoPresentationRequire('demo.presentation.audit');

try {
    $sessions = new DemoPresentationSessionService();
    $state = $sessions->state();
    $state['scenarios'] = (new DemoPresentationDeliveryService())->scenarioCatalog();

    $sessionId = ctype_digit((string) ($_GET['session_id'] ?? '')) ? (int) $_GET['session_id'] : 0;
    if ($sessionId > 0) {
        $state['selected_session_id'] = $sessionId;
        $state['recipients'] = $sessions->recipients($sessionId);
        $state['audit'] = Database::query(
            "SELECT id, channel, delivery_kind, scenario_key, live_requested, live_sent, status,
                    provider_message_id, subject, error_message, created_at
             FROM demo_presentation_delivery_audit
             WHERE demo_session_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 50",
            [$sessionId]
        );
    }

    demoJsonResponse($state);
} catch (\Throwable $e) {
    error_log('demo_presentation/state failed: ' . $e->getMessage());
    demoJsonError('Unable to load presentation demo state.', 500);
}
