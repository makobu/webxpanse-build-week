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
    $agent = (new \CRM\Services\VoiceAgentService())->setPresence($voiceWorkspaceId, $voiceUserId, (string) ($voiceInput['presence'] ?? 'offline'));
    echo json_encode(['success' => true, 'agent' => $agent]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
