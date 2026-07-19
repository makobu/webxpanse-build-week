<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.calls.use');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
voiceApiRequireCsrf($voiceInput);

try {
    $service = new \CRM\Services\VoiceContactLinkService();
    $callId = max(1, (int) ($voiceInput['call_id'] ?? 0));
    $viewAll = voiceApiCan('voice.calls.view_all');
    if ((string) ($voiceInput['action'] ?? '') === 'create') {
        $call = $service->createAndLink($voiceWorkspaceId, $callId, $voiceInput, $voiceUserId, $viewAll);
    } else {
        $call = $service->linkExisting(
            $voiceWorkspaceId,
            $callId,
            max(1, (int) ($voiceInput['contact_id'] ?? 0)),
            $voiceUserId,
            $viewAll
        );
    }
    echo json_encode(['success' => true, 'call' => $call]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
