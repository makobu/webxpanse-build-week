<?php

namespace CRM\Services;

use CRM\Database;

class VoiceTranscriptionJobService
{
    public function deadLetters(int $workspaceId, int $limit = 20): array
    {
        return Database::query(
            "SELECT j.id, j.call_id, j.attempt_count, j.max_attempts, j.last_error, j.updated_at,
                    c.from_number_masked, c.to_number_masked, c.direction
             FROM voice_transcription_jobs j
             INNER JOIN voice_calls c ON c.id = j.call_id AND c.workspace_id = j.workspace_id
             WHERE j.workspace_id = ? AND j.status = 'dead_letter'
             ORDER BY j.updated_at DESC LIMIT " . max(1, min(100, $limit)),
            [$workspaceId]
        );
    }

    public function retry(int $workspaceId, int $jobId): void
    {
        Database::beginTransaction();
        try {
            $job = Database::queryOne(
                "SELECT id, call_id FROM voice_transcription_jobs
                 WHERE workspace_id = ? AND id = ? AND status IN ('dead_letter','failed') FOR UPDATE",
                [$workspaceId, $jobId]
            );
            if (!$job) {
                throw new \RuntimeException('Failed transcription job not found.');
            }
            Database::execute(
                "UPDATE voice_transcription_jobs SET status = 'pending', attempt_count = 0, available_at = NOW(),
                    lease_token = NULL, leased_at = NULL, completed_at = NULL, last_error = NULL
                 WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $jobId]
            );
            Database::execute(
                "UPDATE voice_calls SET transcription_status = 'pending', updated_at = NOW() WHERE workspace_id = ? AND id = ?",
                [$workspaceId, (int) $job['call_id']]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        (new VoiceCallService())->recordInternalEvent($workspaceId, (int) $job['call_id'], 'voice.transcription.retry_requested', null);
    }
}
