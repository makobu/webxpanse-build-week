<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.insights.review');
if (empty($mobileVoiceEntitlements['transcription'])) {
    mobileJson(['success' => false, 'error' => 'Voice intelligence is not included for this workspace.'], 403);
}

try {
    $result = (new \CRM\Services\VoiceEvidenceAccessService())->reviewInsight(
        $mobileVoiceWorkspaceId,
        max(1, (int) ($mobileVoiceInput['call_id'] ?? 0)),
        $mobileVoiceUserId,
        mobileVoiceCan('voice.calls.view_all'),
        (string) ($mobileVoiceInput['status'] ?? '')
    );
    mobileJson([
        'success' => true,
        'data' => [
            'message' => 'Voice insight review saved.',
            'application' => $result,
        ],
    ]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
