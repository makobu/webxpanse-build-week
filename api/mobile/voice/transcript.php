<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('GET');
mobileVoiceRequire('voice.transcripts.view');
if (empty($mobileVoiceEntitlements['transcription'])) {
    mobileJson(['success' => false, 'error' => 'Voice transcription is not included for this workspace.'], 403);
}

try {
    $result = (new \CRM\Services\VoiceEvidenceAccessService())->transcript(
        $mobileVoiceWorkspaceId,
        max(1, (int) ($_GET['call_id'] ?? 0)),
        $mobileVoiceUserId,
        mobileVoiceCan('voice.calls.view_all')
    );
    if ($result === []) {
        mobileJson(['success' => false, 'error' => 'Transcript not found.'], 404);
    }
    mobileJson(['success' => true, 'data' => $result]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
