<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.calls.use');

try {
    $agent = (new \CRM\Services\VoiceAgentService())->setPresence(
        $mobileVoiceWorkspaceId,
        $mobileVoiceUserId,
        (string) ($mobileVoiceInput['presence'] ?? 'offline')
    );
    mobileJson(['success' => true, 'data' => ['agent' => $agent]]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
