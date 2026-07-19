<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.calls.use');

try {
    $service = new \CRM\Services\VoiceCallControlService();
    $action = trim((string) ($mobileVoiceInput['action'] ?? ''));
    $callId = max(1, (int) ($mobileVoiceInput['call_id'] ?? 0));
    $viewAll = mobileVoiceCan('voice.calls.view_all');

    if ($action === 'end') {
        $call = $service->end($mobileVoiceWorkspaceId, $callId, $mobileVoiceUserId, $viewAll);
    } elseif ($action === 'transfer') {
        $call = $service->transfer(
            $mobileVoiceWorkspaceId,
            $callId,
            max(1, (int) ($mobileVoiceInput['agent_id'] ?? 0)),
            $mobileVoiceUserId,
            $viewAll
        );
    } elseif ($action === 'transfer_fallback') {
        $call = $service->transferToFallback($mobileVoiceWorkspaceId, $callId, $mobileVoiceUserId, $viewAll);
    } elseif ($action === 'disposition') {
        $call = $service->disposition(
            $mobileVoiceWorkspaceId,
            $callId,
            (string) ($mobileVoiceInput['disposition'] ?? ''),
            (string) ($mobileVoiceInput['notes'] ?? ''),
            $mobileVoiceUserId,
            $viewAll
        );
    } else {
        throw new InvalidArgumentException('Invalid voice control action.');
    }

    mobileJson(['success' => true, 'data' => ['call' => $call]]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
