<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('GET');
mobileVoiceRequire('voice.calls.use');

try {
    $viewAll = mobileVoiceCan('voice.calls.view_all');
    $result = (new \CRM\Services\VoiceCallService())->list(
        $mobileVoiceWorkspaceId,
        $mobileVoiceUserId,
        $viewAll,
        max(0, (int) ($_GET['since_event_id'] ?? 0)),
        max(1, min(200, (int) ($_GET['limit'] ?? 100)))
    );
    $result['agent'] = (new \CRM\Services\VoiceAgentService())->findForUser(
        $mobileVoiceWorkspaceId,
        $mobileVoiceUserId,
        false
    );
    $result['agents'] = array_values(array_filter(
        (new \CRM\Services\VoiceAgentService())->list($mobileVoiceWorkspaceId),
        static fn (array $agent): bool => !empty($agent['enabled'])
    ));
    $result['queues'] = (new \CRM\Services\VoiceQueueService())->runtimeSummary($mobileVoiceWorkspaceId);
    $result['entitlements'] = $mobileVoiceEntitlements;
    $result['readiness'] = (new \CRM\Services\WorkspaceVoiceConfigService())->readiness($mobileVoiceWorkspaceId);
    $result['provider_capabilities'] = (new \CRM\Services\AfricaTalkingVoiceProvider())->capabilities();
    $config = (new \CRM\Services\WorkspaceVoiceConfigService())->get($mobileVoiceWorkspaceId, false);
    $result['fallback_transfer_available'] = trim((string) (($config['settings']['fallback_number'] ?? ''))) !== '';
    $result['permissions'] = [
        'view_all' => $viewAll,
        'listen_recordings' => mobileVoiceCan('voice.recordings.listen') && !empty($mobileVoiceEntitlements['recording']),
        'view_transcripts' => mobileVoiceCan('voice.transcripts.view') && !empty($mobileVoiceEntitlements['transcription']),
        'review_insights' => mobileVoiceCan('voice.insights.review') && !empty($mobileVoiceEntitlements['transcription']),
        'create_tasks' => mobileVoiceCan('tasks.write'),
    ];
    $result['server_time'] = gmdate('c');

    mobileJson(['success' => true, 'data' => $result]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
