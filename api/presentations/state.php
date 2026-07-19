<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Database;
use CRM\Services\PresentationDeliveryService;
use CRM\Services\PresentationSessionService;

presentationRequire('presentation.audit');

try {
    $sessions = new PresentationSessionService();
    $state = $sessions->state();
    $selectedPack = (string) ($_GET['seed_pack_key'] ?? '');
    if ($selectedPack !== '') {
        $state['scenarios'] = (new PresentationDeliveryService())->scenarioCatalog($selectedPack);
    }
    $state['recent_audit'] = Database::query(
        "SELECT a.id, a.presentation_session_id, a.workspace_id, a.actor_user_id, a.channel, a.delivery_kind,
                a.scenario_key, a.live_requested, a.live_sent, a.status, a.provider_message_id,
                a.subject, a.error_message, a.created_at
         FROM presentation_delivery_audit a
         ORDER BY a.created_at DESC
         LIMIT 40"
    );
    presentationJsonResponse($state);
} catch (\Throwable $e) {
    error_log('presentations/state failed: ' . $e->getMessage());
    presentationJsonError('Unable to load presentation workspace state.', 500);
}
