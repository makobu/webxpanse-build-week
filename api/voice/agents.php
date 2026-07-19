<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.agents.manage');

try {
    $service = new \CRM\Services\VoiceAgentService();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        voiceApiRequireCsrf($voiceInput);
        $agent = $service->save($voiceWorkspaceId, (int) ($voiceInput['user_id'] ?? 0), $voiceInput, $voiceUserId);
        echo json_encode(['success' => true, 'agent' => $agent, 'agents' => $service->list($voiceWorkspaceId)]);
        exit;
    }
    echo json_encode(['success' => true, 'agents' => $service->list($voiceWorkspaceId)]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
