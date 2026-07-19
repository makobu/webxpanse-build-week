<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.transcripts.view');

try {
    $callId = max(1, (int) ($_GET['call_id'] ?? 0));
    $result = (new \CRM\Services\VoiceEvidenceAccessService())->transcript(
        $voiceWorkspaceId,
        $callId,
        $voiceUserId,
        voiceApiCan('voice.calls.view_all')
    );
    if ($result === []) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Transcript not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
