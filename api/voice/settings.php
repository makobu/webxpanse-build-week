<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.settings.manage');

try {
    $service = new \CRM\Services\WorkspaceVoiceConfigService();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        voiceApiRequireCsrf($voiceInput);
        $config = $service->save($voiceWorkspaceId, $voiceInput, $voiceUserId);
        echo json_encode(['success' => true, 'config' => $config, 'readiness' => $service->readiness($voiceWorkspaceId)]);
        exit;
    }
    echo json_encode(['success' => true, 'config' => $service->get($voiceWorkspaceId, false), 'readiness' => $service->readiness($voiceWorkspaceId)]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
