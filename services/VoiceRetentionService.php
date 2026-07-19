<?php

namespace CRM\Services;

use CRM\Database;

class VoiceRetentionService
{
    private WorkspaceVoiceConfigService $crypto;

    public function __construct(?WorkspaceVoiceConfigService $crypto = null)
    {
        $this->crypto = $crypto ?? new WorkspaceVoiceConfigService();
    }

    /** @return array{transcripts:int,recordings:int,jobs:int} */
    public function cleanup(?int $workspaceId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $scope = $workspaceId && $workspaceId > 0 ? ' AND workspace_id = ?' : '';
        $params = $workspaceId && $workspaceId > 0 ? [$workspaceId] : [];
        $transcripts = Database::query(
            "SELECT id FROM voice_call_transcripts WHERE deleted_at IS NULL AND retained_until IS NOT NULL AND retained_until <= NOW(){$scope} ORDER BY retained_until ASC LIMIT {$limit}",
            $params
        );
        $transcriptCount = 0;
        foreach ($transcripts as $row) {
            $deleted = '[deleted by voice retention policy]';
            $transcriptCount += Database::execute(
                'UPDATE voice_call_transcripts SET encrypted_transcript = ?, transcript_fingerprint = ?, redacted_preview = NULL, speaker_segments_json = NULL, deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL',
                [$this->crypto->encryptValue($deleted), hash('sha256', $deleted), (int) $row['id']]
            );
        }

        $recordings = Database::query(
            "SELECT id FROM voice_recordings WHERE deleted_at IS NULL AND retained_until IS NOT NULL AND retained_until <= NOW(){$scope} ORDER BY retained_until ASC LIMIT {$limit}",
            $params
        );
        $recordingCount = 0;
        $jobCount = 0;
        foreach ($recordings as $row) {
            $jobCount += Database::execute(
                "UPDATE voice_transcription_jobs SET status = 'dead_letter', lease_token = NULL, leased_at = NULL,
                    last_error = 'Recording expired before transcription completed.'
                 WHERE recording_id = ? AND status IN ('pending','processing','failed')",
                [(int) $row['id']]
            );
            $recordingCount += Database::execute(
                "UPDATE voice_recordings SET encrypted_provider_url = NULL, status = 'deleted', deleted_at = NOW(),
                    last_error = 'CRM recording pointer removed by retention policy; provider-account retention remains authoritative.'
                 WHERE id = ? AND deleted_at IS NULL",
                [(int) $row['id']]
            );
        }
        return ['transcripts' => $transcriptCount, 'recordings' => $recordingCount, 'jobs' => $jobCount];
    }
}
