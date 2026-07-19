<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.calls.use');

try {
    $viewAll = voiceApiCan('voice.calls.view_all');
    $result = (new \CRM\Services\VoiceCallService())->list(
        $voiceWorkspaceId,
        $voiceUserId,
        $viewAll,
        max(0, (int) ($_GET['since_event_id'] ?? 0)),
        max(1, min(200, (int) ($_GET['limit'] ?? 100)))
    );
    $result['agent'] = (new \CRM\Services\VoiceAgentService())->findForUser($voiceWorkspaceId, $voiceUserId, false);
    $result['agents'] = array_values(array_filter(
        (new \CRM\Services\VoiceAgentService())->list($voiceWorkspaceId),
        static fn(array $agent): bool => !empty($agent['enabled'])
    ));
    $result['queues'] = (new \CRM\Services\VoiceQueueService())->runtimeSummary($voiceWorkspaceId);
    $result['entitlements'] = (new \CRM\Services\WorkspaceVoiceEntitlementService())->forWorkspace($voiceWorkspaceId);
    $result['readiness'] = (new \CRM\Services\WorkspaceVoiceConfigService())->readiness($voiceWorkspaceId);
    $result['provider_capabilities'] = (new \CRM\Services\AfricaTalkingVoiceProvider())->capabilities();
    $publicVoiceConfig = (new \CRM\Services\WorkspaceVoiceConfigService())->get($voiceWorkspaceId, false);
    $result['fallback_transfer_available'] = trim((string) (($publicVoiceConfig['settings']['fallback_number'] ?? ''))) !== '';
    echo json_encode(['success' => true] + $result);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
