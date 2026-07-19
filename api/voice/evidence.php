<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.settings.manage');
voiceApiRequireCsrf($voiceInput);

try {
    if ((string) ($voiceInput['action'] ?? '') !== 'delete') {
        throw new InvalidArgumentException('Unsupported evidence action.');
    }
    $callId = max(1, (int) ($voiceInput['call_id'] ?? 0));
    $deleted = (new \CRM\Services\VoiceEvidenceAccessService())->deleteCallEvidence(
        $voiceWorkspaceId,
        $callId,
        $voiceUserId,
        voiceApiCan('voice.calls.view_all')
    );
    echo json_encode([
        'success' => true,
        'message' => 'CRM-held raw voice evidence was deleted. Provider-account retention remains authoritative.',
        'deleted' => $deleted,
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
