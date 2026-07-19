<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.settings.manage');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
voiceApiRequireCsrf($voiceInput);

try {
    $configs = new \CRM\Services\WorkspaceVoiceConfigService();
    $result = (new \CRM\Services\AfricaTalkingVoiceProvider())->verifyConfiguration($configs->get($voiceWorkspaceId, true));
    $configs->markVerified(
        $voiceWorkspaceId,
        !empty($result['ok']),
        empty($result['ok']) ? (string) $result['message'] : '',
        (array) ($result['metadata'] ?? [])
    );
    echo json_encode(['success' => !empty($result['ok']), 'result' => $result, 'readiness' => $configs->readiness($voiceWorkspaceId)]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
