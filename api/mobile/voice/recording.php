<?php

require_once __DIR__ . '/_bootstrap.php';

mobileVoiceRequireMethod('GET');
mobileVoiceRequire('voice.recordings.listen');
if (empty($mobileVoiceEntitlements['recording'])) {
    mobileJson(['success' => false, 'error' => 'Voice recording is not included for this workspace.'], 403);
}

$recording = [];
try {
    $recording = (new \CRM\Services\VoiceEvidenceAccessService())->recording(
        $mobileVoiceWorkspaceId,
        max(1, (int) ($_GET['id'] ?? 0)),
        $mobileVoiceUserId,
        mobileVoiceCan('voice.calls.view_all')
    );
    if ($recording === []) {
        mobileJson(['success' => false, 'error' => 'Recording not found.'], 404);
    }

    header('Content-Type: ' . $recording['mime']);
    header('Content-Length: ' . filesize($recording['path']));
    header('Content-Disposition: inline; filename="' . $recording['filename'] . '"');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($recording['path']);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
} finally {
    if (!empty($recording['path']) && is_file($recording['path'])) {
        @unlink($recording['path']);
    }
}
