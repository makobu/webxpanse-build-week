<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.calls.use');

try {
    $service = new \CRM\Services\VoiceContactLinkService();
    $callId = max(1, (int) ($mobileVoiceInput['call_id'] ?? 0));
    $viewAll = mobileVoiceCan('voice.calls.view_all');
    if ((string) ($mobileVoiceInput['action'] ?? '') === 'create') {
        $call = $service->createAndLink(
            $mobileVoiceWorkspaceId,
            $callId,
            $mobileVoiceInput,
            $mobileVoiceUserId,
            $viewAll
        );
    } else {
        $call = $service->linkExisting(
            $mobileVoiceWorkspaceId,
            $callId,
            max(1, (int) ($mobileVoiceInput['contact_id'] ?? 0)),
            $mobileVoiceUserId,
            $viewAll
        );
    }

    mobileJson(['success' => true, 'data' => ['call' => $call]]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
