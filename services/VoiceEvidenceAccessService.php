<?php

namespace CRM\Services;

use CRM\Database;

class VoiceEvidenceAccessService
{
    private WorkspaceVoiceConfigService $crypto;

    public function __construct(?WorkspaceVoiceConfigService $crypto = null)
    {
        $this->crypto = $crypto ?? new WorkspaceVoiceConfigService();
    }

    public function transcript(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $call = $this->authorizedCall($workspaceId, $callId, $userId, $viewAll);
        if ($call === []) {
            return [];
        }
        $row = Database::queryOne(
            "SELECT t.id, t.encrypted_transcript, t.language_code, t.speaker_segments_json, t.provider, t.model,
                    t.confidence, t.created_at, i.summary, i.sentiment, i.intent, i.next_step, i.confidence AS insight_confidence,
                    i.relationship_context, i.review_status, i.pains_json, i.goals_json, i.objections_json, i.commitments_json,
                    i.requested_actions_json, i.task_suggestions_json, i.contact_updates_json, i.deal_stage_suggestion_json,
                    i.evidence_json, i.applied_actions_json, i.skipped_actions_json
             FROM voice_call_transcripts t
             LEFT JOIN voice_call_insights i ON i.workspace_id = t.workspace_id AND i.call_id = t.call_id
             WHERE t.workspace_id = ? AND t.call_id = ? AND t.deleted_at IS NULL LIMIT 1",
            [$workspaceId, $callId]
        );
        if (!$row) {
            return [];
        }
        $json = static function ($value): array {
            $decoded = $value ? json_decode((string) $value, true) : [];
            return is_array($decoded) ? $decoded : [];
        };
        return [
            'id' => (int) $row['id'], 'call_id' => $callId,
            'transcript' => $this->crypto->decryptValue((string) $row['encrypted_transcript']),
            'language' => (string) ($row['language_code'] ?? ''), 'segments' => $json($row['speaker_segments_json'] ?? null),
            'provider' => (string) $row['provider'], 'model' => (string) $row['model'],
            'confidence' => $row['confidence'] !== null ? (float) $row['confidence'] : null,
            'created_at' => (string) $row['created_at'],
            'insight' => [
                'summary' => (string) ($row['summary'] ?? ''), 'sentiment' => (string) ($row['sentiment'] ?? ''),
                'intent' => (string) ($row['intent'] ?? ''), 'next_step' => (string) ($row['next_step'] ?? ''),
                'relationship_context' => (string) ($row['relationship_context'] ?? ''),
                'confidence' => $row['insight_confidence'] !== null ? (float) $row['insight_confidence'] : null,
                'review_status' => (string) ($row['review_status'] ?? ''), 'pains' => $json($row['pains_json'] ?? null),
                'goals' => $json($row['goals_json'] ?? null), 'objections' => $json($row['objections_json'] ?? null),
                'commitments' => $json($row['commitments_json'] ?? null), 'requested_actions' => $json($row['requested_actions_json'] ?? null),
                'task_suggestions' => $json($row['task_suggestions_json'] ?? null), 'contact_updates' => $json($row['contact_updates_json'] ?? null),
                'deal_stage_suggestion' => $json($row['deal_stage_suggestion_json'] ?? null),
                'evidence' => $json($row['evidence_json'] ?? null),
                'applied_actions' => $json($row['applied_actions_json'] ?? null),
                'skipped_actions' => $json($row['skipped_actions_json'] ?? null),
                'automation_policy' => (new VoicePolicyDecisionService())->forConfig((new WorkspaceVoiceConfigService())->get($workspaceId, false)),
            ],
        ];
    }

    /** @return array{path:string,mime:string,filename:string} */
    public function recording(int $workspaceId, int $recordingId, int $userId, bool $viewAll): array
    {
        $row = Database::queryOne(
            "SELECT r.*, c.agent_user_id, c.created_by_user_id
             FROM voice_recordings r INNER JOIN voice_calls c ON c.id = r.call_id AND c.workspace_id = r.workspace_id
             WHERE r.workspace_id = ? AND r.id = ? AND r.status = 'ready' AND r.deleted_at IS NULL LIMIT 1",
            [$workspaceId, $recordingId]
        );
        if (!$row || (!$viewAll && (int) $row['agent_user_id'] !== $userId && (int) $row['created_by_user_id'] !== $userId)) {
            return [];
        }
        $url = $this->crypto->decryptValue((string) $row['encrypted_provider_url']);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || $host !== strtolower((string) $row['provider_host'])
            || !($host === 'africastalking.com' || str_ends_with($host, '.africastalking.com'))) {
            throw new \RuntimeException('Recording failed the provider host policy.');
        }
        $path = tempnam(sys_get_temp_dir(), 'vcc_play_');
        if ($path === false) {
            throw new \RuntimeException('Could not prepare protected recording playback.');
        }
        $file = fopen($path, 'wb');
        if (!$file) {
            @unlink($path);
            throw new \RuntimeException('Could not prepare protected recording playback.');
        }
        $bytes = 0;
        $max = 24 * 1024 * 1024;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$bytes, $max): int {
                $bytes += strlen($chunk);
                return $bytes > $max ? 0 : (int) fwrite($file, $chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $mime = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
        curl_close($handle);
        fclose($file);
        if ($ok === false || $status < 200 || $status >= 300 || $bytes <= 0 || $bytes > $max || !str_starts_with($mime, 'audio/')) {
            @unlink($path);
            throw new \RuntimeException('Provider recording download failed validation.');
        }
        return ['path' => $path, 'mime' => $mime, 'filename' => 'voice-call-' . (int) $row['call_id'] . '.audio'];
    }

    public function reviewInsight(int $workspaceId, int $callId, int $userId, bool $viewAll, string $status): array
    {
        if (!in_array($status, ['accepted', 'dismissed'], true)) {
            throw new \InvalidArgumentException('Invalid insight review status.');
        }
        if ($this->authorizedCall($workspaceId, $callId, $userId, $viewAll) === []) {
            throw new \RuntimeException('Voice insight not found.');
        }
        if ($status === 'accepted') {
            return (new VoiceContextEnrichmentService())->applyStoredInsight($workspaceId, $callId, true, $userId);
        }
        $updated = Database::execute(
            "UPDATE voice_call_insights SET review_status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE workspace_id = ? AND call_id = ? AND review_status = 'pending'",
            ['dismissed', $userId, $workspaceId, $callId]
        );
        if ($updated < 1 && !Database::queryOne('SELECT id FROM voice_call_insights WHERE workspace_id = ? AND call_id = ?', [$workspaceId, $callId])) {
            throw new \RuntimeException('Voice insight not found.');
        }
        return ['applied' => [], 'skipped' => [], 'review_status' => 'dismissed'];
    }

    /** @return array{transcripts:int,recordings:int,jobs:int} */
    public function deleteCallEvidence(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        if ($this->authorizedCall($workspaceId, $callId, $userId, $viewAll) === []) {
            throw new \RuntimeException('Voice call not found or not available to this user.');
        }

        $deleted = '[deleted by an authorized workspace administrator]';
        Database::beginTransaction();
        try {
            $transcripts = Database::execute(
                'UPDATE voice_call_transcripts
                 SET encrypted_transcript = ?, transcript_fingerprint = ?, redacted_preview = NULL,
                     speaker_segments_json = NULL, deleted_at = NOW(), updated_at = NOW()
                 WHERE workspace_id = ? AND call_id = ? AND deleted_at IS NULL',
                [$this->crypto->encryptValue($deleted), hash('sha256', $deleted), $workspaceId, $callId]
            );
            $recordingRows = Database::query(
                'SELECT id FROM voice_recordings WHERE workspace_id = ? AND call_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$workspaceId, $callId]
            );
            $recordingIds = array_map('intval', array_column($recordingRows, 'id'));
            $jobs = 0;
            if ($recordingIds !== []) {
                $placeholders = implode(',', array_fill(0, count($recordingIds), '?'));
                $jobs = Database::execute(
                    "UPDATE voice_transcription_jobs
                     SET status = 'dead_letter', lease_token = NULL, leased_at = NULL,
                         last_error = 'Raw call evidence was deleted by an authorized workspace administrator.', updated_at = NOW()
                     WHERE workspace_id = ? AND recording_id IN ({$placeholders})
                       AND status IN ('pending','processing','failed')",
                    array_merge([$workspaceId], $recordingIds)
                );
            }
            $recordings = Database::execute(
                "UPDATE voice_recordings
                 SET encrypted_provider_url = NULL, status = 'deleted', deleted_at = NOW(),
                     last_error = 'CRM recording pointer removed by an authorized workspace administrator; provider-account retention remains authoritative.',
                     updated_at = NOW()
                 WHERE workspace_id = ? AND call_id = ? AND deleted_at IS NULL",
                [$workspaceId, $callId]
            );
            Database::execute(
                "UPDATE voice_calls
                 SET recording_status = CASE WHEN recording_status = 'disabled' THEN 'disabled' ELSE 'deleted' END,
                     transcription_status = CASE WHEN transcription_status = 'disabled' THEN 'disabled' ELSE 'deleted' END,
                     updated_by_user_id = ?, updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [$userId, $workspaceId, $callId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        (new VoiceCallService())->recordInternalEvent($workspaceId, $callId, 'voice.evidence.deleted', null);
        return ['transcripts' => $transcripts, 'recordings' => $recordings, 'jobs' => $jobs];
    }

    private function authorizedCall(int $workspaceId, int $callId, int $userId, bool $viewAll): array
    {
        $params = [$workspaceId, $callId];
        $scope = '';
        if (!$viewAll) {
            $scope = ' AND (agent_user_id = ? OR created_by_user_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }
        return Database::queryOne('SELECT id FROM voice_calls WHERE workspace_id = ? AND id = ?' . $scope . ' LIMIT 1', $params) ?: [];
    }
}
