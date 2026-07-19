<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.calls.use');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit;
}
voiceApiRequireCsrf($voiceInput);

try {
    $service = new \CRM\Services\VoiceCallControlService();
    $action = (string) ($voiceInput['action'] ?? '');
    $callId = max(1, (int) ($voiceInput['call_id'] ?? 0));
    $viewAll = voiceApiCan('voice.calls.view_all');
    if ($action === 'end') {
        $call = $service->end($voiceWorkspaceId, $callId, $voiceUserId, $viewAll);
    } elseif ($action === 'transfer') {
        $call = $service->transfer($voiceWorkspaceId, $callId, max(1, (int) ($voiceInput['agent_id'] ?? 0)), $voiceUserId, $viewAll);
    } elseif ($action === 'transfer_fallback') {
        $call = $service->transferToFallback($voiceWorkspaceId, $callId, $voiceUserId, $viewAll);
    } elseif ($action === 'disposition') {
        $call = $service->disposition($voiceWorkspaceId, $callId, (string) ($voiceInput['disposition'] ?? ''), (string) ($voiceInput['notes'] ?? ''), $voiceUserId, $viewAll);
    } else {
        throw new InvalidArgumentException('Invalid voice control action.');
    }
    echo json_encode(['success' => true, 'call' => $call]);
} catch (Throwable $e) {
    http_response_code(422); echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
