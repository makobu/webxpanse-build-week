<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.insights.review');
voiceApiRequireCsrf($voiceInput);

try {
    $result = (new \CRM\Services\VoiceEvidenceAccessService())->reviewInsight(
        $voiceWorkspaceId,
        max(1, (int) ($voiceInput['call_id'] ?? 0)),
        $voiceUserId,
        voiceApiCan('voice.calls.view_all'),
        (string) ($voiceInput['status'] ?? '')
    );
    echo json_encode([
        'success' => true,
        'message' => 'Voice insight review saved.',
        'application' => $result,
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
