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

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="voice-call-' . $callId . '-evidence.json"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'exported_at' => gmdate(DATE_ATOM),
        'call_id' => $callId,
        'transcript' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
