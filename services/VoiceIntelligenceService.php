<?php

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;

class VoiceIntelligenceService
{
    private VoiceTranscriptionProviderInterface $transcriber;
    private ConversationInsightPipeline $insights;
    private WorkspaceVoiceConfigService $configs;
    private VoiceContextEnrichmentService $enrichment;
    private VoicePolicyDecisionService $policies;

    public function __construct(
        ?VoiceTranscriptionProviderInterface $transcriber = null,
        ?ConversationInsightPipeline $insights = null,
        ?WorkspaceVoiceConfigService $configs = null,
        ?VoiceContextEnrichmentService $enrichment = null,
        ?VoicePolicyDecisionService $policies = null
    ) {
        $this->transcriber = $transcriber ?? new OpenAIVoiceTranscriptionProvider();
        $this->insights = $insights ?? new ConversationInsightPipeline();
        $this->configs = $configs ?? new WorkspaceVoiceConfigService();
        $this->enrichment = $enrichment ?? new VoiceContextEnrichmentService();
        $this->policies = $policies ?? new VoicePolicyDecisionService();
    }

    public function processNext(?int $workspaceId = null): ?array
    {
        $job = $this->claimJob($workspaceId);
        if (!$job) {
            return null;
        }
        $temporaryPath = '';
        try {
            $config = $this->configs->get((int) $job['workspace_id'], true);
            $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace((int) $job['workspace_id']);
            if (empty($config['transcription_enabled']) || empty($entitlements['transcription'])) {
                throw new \RuntimeException('Workspace transcription is disabled.');
            }
            if (empty($config['recording_acknowledgement_valid'])) {
                throw new \RuntimeException('Workspace recording acknowledgement is not current.');
            }
            if ((string) $job['consent_status'] !== 'granted') {
                throw new \RuntimeException('Call recording consent was not granted.');
            }
            $recordingUrl = $this->configs->decryptValue((string) $job['encrypted_provider_url']);
            $temporaryPath = $this->downloadRecording($recordingUrl, (string) $job['provider_host']);
            $transcript = $this->transcriber->transcribe((int) $job['workspace_id'], $temporaryPath, (string) $config['transcription_model']);
            $transcriptId = $this->persistTranscript($job, $config, $transcript);
            $insightId = 0;
            if (!empty($config['ai_application_enabled'])) {
                $analysis = $this->insights->analyze((int) $job['workspace_id'], (string) $transcript['text'], [
                    'call_id' => (int) $job['call_id'], 'direction' => (string) $job['direction'],
                    'contact_id' => !empty($job['contact_id']) ? (int) $job['contact_id'] : null,
                    'call_date' => (string) ($job['completed_at'] ?? $job['call_created_at'] ?? ''),
                ]);
                $insightId = $this->persistInsight($job, $analysis);
                try {
                    $this->enrichment->apply((int) $job['workspace_id'], (int) $job['call_id'], $analysis);
                } catch (\Throwable $e) {
                    error_log('Voice CRM context enrichment failed without failing transcription: ' . $e->getMessage());
                    $this->appendSkippedAction((int) $job['workspace_id'], (int) $job['call_id'], [
                        'type' => 'crm_enrichment',
                        'reason' => 'application_failed',
                        'error' => mb_substr($e->getMessage(), 0, 240),
                    ]);
                }
                $customerVoiceDecision = $this->policies->decide($config, 'customer_voice', false, (float) ($analysis['confidence'] ?? 0));
                if (!empty($entitlements['customer_voice']) && (string) $customerVoiceDecision['decision'] !== 'block') {
                    try {
                        (new CustomerVoiceAggregationService())->aggregateInsight((int) $job['workspace_id'], $insightId);
                    } catch (\Throwable $e) {
                        error_log('Customer Voice aggregation failed without failing transcription: ' . $e->getMessage());
                    }
                }
            }
            Database::execute(
                "UPDATE voice_transcription_jobs SET status = 'completed', completed_at = NOW(), lease_token = NULL, leased_at = NULL, last_error = NULL WHERE id = ? AND lease_token = ?",
                [(int) $job['id'], (string) $job['lease_token']]
            );
            Database::execute("UPDATE voice_calls SET transcription_status = 'ready' WHERE workspace_id = ? AND id = ?", [(int) $job['workspace_id'], (int) $job['call_id']]);
            EventBus::publish('voice.transcript.ready', ['workspace_id' => (int) $job['workspace_id'], 'call_id' => (int) $job['call_id'], 'transcript_id' => $transcriptId]);
            if ($insightId > 0) {
                EventBus::publish('voice.insight.ready', ['workspace_id' => (int) $job['workspace_id'], 'call_id' => (int) $job['call_id'], 'insight_id' => $insightId]);
            }
            (new VoiceMobileNotificationService())->notifyIntelligenceReady(
                (int) $job['workspace_id'],
                (int) $job['call_id'],
                $insightId > 0
            );
            return ['job_id' => (int) $job['id'], 'call_id' => (int) $job['call_id'], 'transcript_id' => $transcriptId, 'insight_id' => $insightId, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->failJob($job, $e->getMessage());
            throw $e;
        } finally {
            if ($temporaryPath !== '' && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function syncCrmContextForCall(int $workspaceId, int $callId): void
    {
        $row = Database::queryOne(
            "SELECT review_status FROM voice_call_insights WHERE workspace_id = ? AND call_id = ? LIMIT 1",
            [$workspaceId, $callId]
        );
        if (!$row) {
            (new VoiceCrmContextService())->sync($workspaceId, $callId);
            return;
        }
        $approved = in_array((string) ($row['review_status'] ?? ''), ['reviewed', 'applied'], true);
        $this->enrichment->applyStoredInsight($workspaceId, $callId, $approved);
    }

    private function claimJob(?int $workspaceId): ?array
    {
        Database::beginTransaction();
        try {
            $where = $workspaceId && $workspaceId > 0 ? 'AND j.workspace_id = ?' : '';
            $params = $workspaceId && $workspaceId > 0 ? [$workspaceId] : [];
            $job = Database::queryOne(
                "SELECT j.*, r.encrypted_provider_url, r.provider_host, r.mime_type, r.size_bytes,
                        c.contact_id, c.direction, c.consent_status, c.completed_at, c.created_at AS call_created_at
                 FROM voice_transcription_jobs j
                 INNER JOIN voice_recordings r ON r.id = j.recording_id AND r.workspace_id = j.workspace_id AND r.status = 'ready'
                 INNER JOIN voice_calls c ON c.id = j.call_id AND c.workspace_id = j.workspace_id
                 WHERE j.status IN ('pending','failed') AND j.available_at <= NOW()
                   AND (j.leased_at IS NULL OR j.leased_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) {$where}
                 ORDER BY j.available_at ASC, j.id ASC LIMIT 1 FOR UPDATE",
                $params
            );
            if (!$job) {
                Database::commit();
                return null;
            }
            $token = bin2hex(random_bytes(24));
            Database::execute(
                "UPDATE voice_transcription_jobs SET status = 'processing', attempt_count = attempt_count + 1, lease_token = ?, leased_at = NOW(), last_error = NULL WHERE id = ?",
                [$token, (int) $job['id']]
            );
            Database::execute("UPDATE voice_calls SET transcription_status = 'processing' WHERE workspace_id = ? AND id = ?", [(int) $job['workspace_id'], (int) $job['call_id']]);
            Database::commit();
            $job['lease_token'] = $token;
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            return $job;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function downloadRecording(string $url, string $expectedHost): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || $host !== strtolower($expectedHost)
            || !($host === 'africastalking.com' || str_ends_with($host, '.africastalking.com'))
        ) {
            throw new \RuntimeException('Recording URL failed the provider host policy.');
        }
        $path = tempnam(sys_get_temp_dir(), 'vcc_');
        if ($path === false) {
            throw new \RuntimeException('Could not allocate a temporary recording file.');
        }
        $file = fopen($path, 'wb');
        if (!$file) {
            @unlink($path);
            throw new \RuntimeException('Could not open the temporary recording file.');
        }
        $bytes = 0;
        $max = 24 * 1024 * 1024;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($file, &$bytes, $max): int {
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $max) return 0;
                return (int) fwrite($file, $chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
        $error = curl_error($handle);
        curl_close($handle);
        fclose($file);
        if ($ok === false || $error !== '' || $status < 200 || $status >= 300 || $bytes <= 0 || $bytes > $max || !str_starts_with($contentType, 'audio/')) {
            @unlink($path);
            throw new \RuntimeException('Provider recording download failed validation.');
        }
        return $path;
    }

    private function persistTranscript(array $job, array $config, array $transcript): int
    {
        $text = (string) $transcript['text'];
        $retainedUntil = date('Y-m-d H:i:s', time() + max(1, (int) $config['transcript_retention_days']) * 86400);
        Database::execute(
            "INSERT INTO voice_call_transcripts (workspace_id, call_id, encrypted_transcript, transcript_fingerprint, redacted_preview,
                language_code, speaker_segments_json, provider, model, confidence, retained_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE encrypted_transcript = VALUES(encrypted_transcript), transcript_fingerprint = VALUES(transcript_fingerprint),
                redacted_preview = VALUES(redacted_preview), language_code = VALUES(language_code), speaker_segments_json = VALUES(speaker_segments_json),
                provider = VALUES(provider), model = VALUES(model), confidence = VALUES(confidence), retained_until = VALUES(retained_until), deleted_at = NULL",
            [(int) $job['workspace_id'], (int) $job['call_id'], $this->configs->encryptValue($text), hash('sha256', $text),
                $this->redactedPreview($text), (string) $transcript['language'], json_encode($transcript['segments'], JSON_UNESCAPED_SLASHES),
                (string) $transcript['provider'], (string) $transcript['model'], $transcript['confidence'], $retainedUntil]
        );
        return (int) (Database::queryOne('SELECT id FROM voice_call_transcripts WHERE workspace_id = ? AND call_id = ?', [(int) $job['workspace_id'], (int) $job['call_id']])['id'] ?? 0);
    }

    private function persistInsight(array $job, array $analysis): int
    {
        $json = static fn($value): string => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        Database::execute(
            "INSERT INTO voice_call_insights (workspace_id, call_id, contact_id, summary, relationship_context, sentiment, intent,
                pains_json, goals_json, objections_json, commitments_json, requested_actions_json, next_step,
                contact_updates_json, task_suggestions_json, deal_stage_suggestion_json, confidence, evidence_json,
                extracted_actions_json, applied_actions_json, skipped_actions_json, review_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', ?, 'pending')
             ON DUPLICATE KEY UPDATE contact_id = VALUES(contact_id), summary = VALUES(summary), relationship_context = VALUES(relationship_context),
                sentiment = VALUES(sentiment), intent = VALUES(intent), pains_json = VALUES(pains_json), goals_json = VALUES(goals_json),
                objections_json = VALUES(objections_json), commitments_json = VALUES(commitments_json), requested_actions_json = VALUES(requested_actions_json),
                next_step = VALUES(next_step), contact_updates_json = VALUES(contact_updates_json), task_suggestions_json = VALUES(task_suggestions_json),
                deal_stage_suggestion_json = VALUES(deal_stage_suggestion_json), confidence = VALUES(confidence), evidence_json = VALUES(evidence_json),
                extracted_actions_json = VALUES(extracted_actions_json), skipped_actions_json = VALUES(skipped_actions_json)",
            [(int) $job['workspace_id'], (int) $job['call_id'], !empty($job['contact_id']) ? (int) $job['contact_id'] : null,
                (string) $analysis['summary'], (string) $analysis['relationship_context'], (string) $analysis['sentiment'], (string) $analysis['intent'],
                $json($analysis['pains']), $json($analysis['goals']), $json($analysis['objections']), $json($analysis['commitments']),
                $json($analysis['requested_actions']), (string) $analysis['next_step'], $json($analysis['contact_updates']),
                $json($analysis['task_suggestions']), $json($analysis['deal_stage_suggestion']), (float) $analysis['confidence'],
                $json($analysis['evidence']), $json($analysis), $json([])]
        );
        return (int) (Database::queryOne('SELECT id FROM voice_call_insights WHERE workspace_id = ? AND call_id = ?', [(int) $job['workspace_id'], (int) $job['call_id']])['id'] ?? 0);
    }

    private function appendSkippedAction(int $workspaceId, int $callId, array $action): void
    {
        $row = Database::queryOne(
            'SELECT skipped_actions_json FROM voice_call_insights WHERE workspace_id = ? AND call_id = ? LIMIT 1',
            [$workspaceId, $callId]
        );
        $actions = $row && !empty($row['skipped_actions_json']) ? json_decode((string) $row['skipped_actions_json'], true) : [];
        $actions = is_array($actions) ? $actions : [];
        $actions[] = $action;
        Database::execute(
            'UPDATE voice_call_insights SET skipped_actions_json = ?, updated_at = NOW() WHERE workspace_id = ? AND call_id = ?',
            [json_encode($actions, JSON_UNESCAPED_SLASHES), $workspaceId, $callId]
        );
    }

    private function failJob(array $job, string $message): void
    {
        $attempt = (int) ($job['attempt_count'] ?? 1);
        $dead = $attempt >= (int) ($job['max_attempts'] ?? 5);
        $delayMinutes = min(60, 2 ** max(0, $attempt - 1));
        Database::execute(
            "UPDATE voice_transcription_jobs SET status = ?, available_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), lease_token = NULL,
                leased_at = NULL, last_error = ? WHERE id = ? AND lease_token = ?",
            [$dead ? 'dead_letter' : 'failed', $delayMinutes, substr($message, 0, 500), (int) $job['id'], (string) $job['lease_token']]
        );
        Database::execute("UPDATE voice_calls SET transcription_status = 'failed' WHERE workspace_id = ? AND id = ?", [(int) $job['workspace_id'], (int) $job['call_id']]);
    }

    private function redactedPreview(string $text): string
    {
        $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 500);
        $preview = preg_replace('/\b[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}\b/u', '[email]', $preview) ?? '';
        return preg_replace('/\+?[0-9][0-9\s().-]{7,}[0-9]/u', '[phone]', $preview) ?? '';
    }

    private function uuid(): string
    {
        $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
