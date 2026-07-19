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
    $call = (new \CRM\Services\VoiceCallService())->requestOutbound(
        $voiceWorkspaceId,
        $voiceUserId,
        (string) ($voiceInput['destination'] ?? ''),
        !empty($voiceInput['contact_id']) ? (int) $voiceInput['contact_id'] : null,
        voiceApiCan('contacts.view_all'),
        !empty($voiceInput['manual_destination_confirmed'])
    );
    http_response_code(202);
    echo json_encode(['success' => true, 'call' => $call, 'message' => 'Call queued. The verified agent endpoint will ring first.']);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
