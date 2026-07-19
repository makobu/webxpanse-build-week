<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.calls.use');

try {
    $call = (new \CRM\Services\VoiceCallService())->requestOutbound(
        $mobileVoiceWorkspaceId,
        $mobileVoiceUserId,
        (string) ($mobileVoiceInput['destination'] ?? ''),
        !empty($mobileVoiceInput['contact_id']) ? (int) $mobileVoiceInput['contact_id'] : null,
        mobileVoiceCan('contacts.view_all'),
        !empty($mobileVoiceInput['manual_destination_confirmed'])
    );

    mobileJson([
        'success' => true,
        'data' => [
            'call' => $call,
            'message' => 'Call queued. Your verified agent endpoint will ring first.',
        ],
    ], 202);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
