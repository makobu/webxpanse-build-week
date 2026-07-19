<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\MeetingBotConfig;

class MeetingBotService
{
    private ?int $workspaceId = null;

    public function __construct(
        private ?MeetingBotConfig $configModule = null,
        private ?MeetingBotIdentityService $identity = null,
        private ?ZoomMeetingBotService $zoom = null,
        private ?GoogleMeetMeetingBotService $googleMeet = null,
        private ?MeetingNoteTakerService $noteTaker = null,
        ?int $workspaceId = null,
    ) {
        $this->configModule = $this->configModule ?? new MeetingBotConfig();
        $this->identity = $this->identity ?? new MeetingBotIdentityService($this->configModule);
        $this->zoom = $this->zoom ?? new ZoomMeetingBotService();
        $this->googleMeet = $this->googleMeet ?? new GoogleMeetMeetingBotService();
        $this->noteTaker = $this->noteTaker ?? new MeetingNoteTakerService();
        $this->workspaceId = $workspaceId;
        if ($workspaceId !== null && method_exists($this->noteTaker, 'setWorkspaceId')) {
            $this->noteTaker->setWorkspaceId($workspaceId);
        }
    }

    public function setWorkspaceId(?int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        if ($workspaceId !== null && method_exists($this->noteTaker, 'setWorkspaceId')) {
            $this->noteTaker->setWorkspaceId($workspaceId);
        }
    }

    public function buildSettingsState(): array
    {
        $config = $this->configModule->get($this->workspaceId());
        $displayName = $this->identity->resolveDisplayName($config);

        return [
            'config' => $config,
            'resolved_display_name' => $displayName,
            'invite_instructions' => $this->buildInviteInstructions($config, $displayName),
        ];
    }

    public function registerMeeting(array $payload, ?int $actorUserId = null): array
    {
        $workspaceId = $this->workspaceId();
        $config = $this->configModule->get($workspaceId);
        $displayName = $this->identity->resolveDisplayName($config);
        if (empty($config['enabled'])) {
            return [
                'success' => false,
                'run_id' => null,
                'status' => 'blocked',
                'provider' => (string) ($config['provider'] ?? 'zoom'),
                'bot_display_name' => $displayName,
                'invite_instructions' => $this->buildInviteInstructions($config, $displayName),
                'reasons' => ['meeting_bot_disabled'],
            ];
        }
        $provider = $this->resolveProvider($payload, $config);
        $eligibility = $this->evaluateEligibilityForProvider($provider, $payload, $config);
        $status = $eligibility['eligible'] ? 'scheduled' : 'failed';

        $runId = $this->createRun([
            'provider' => $provider,
            'external_meeting_id' => $this->resolveMeetingId($provider, $payload),
            'external_event_id' => $this->resolveEventId($provider, $payload),
            'event_id' => !empty($payload['event_id']) ? (int) $payload['event_id'] : null,
            'title' => trim((string) ($payload['title'] ?? '')),
            'join_url' => trim((string) ($payload['join_url'] ?? '')),
            'bot_display_name_used' => $displayName,
            'join_request_source' => trim((string) ($payload['join_request_source'] ?? 'manual')) ?: 'manual',
            'join_policy_snapshot' => (string) ($config['join_policy'] ?? 'manual_invite_only'),
            'status' => $status,
            'recording_status' => !empty($config['transcript_required']) ? 'requested' : 'not_requested',
            'transcript_status' => !empty($config['transcript_required']) ? 'pending' : 'not_required',
            'consent_status' => trim((string) ($config['consent_notice'] ?? '')) !== '' ? 'notified' : 'not_required',
            'organizer_email' => trim((string) ($payload['organizer_email'] ?? '')),
            'failure_reason' => $eligibility['eligible'] ? null : implode(', ', $eligibility['errors']),
            'normalized_attendees_json' => $payload['participants'] ?? $payload['attendees'] ?? [],
            'provider_payload_json' => $payload,
            'scheduled_for' => $payload['scheduled_for'] ?? ($payload['started_at'] ?? null),
            'started_at' => $payload['started_at'] ?? null,
            'ended_at' => $payload['ended_at'] ?? null,
            'created_by' => $actorUserId,
            'workspace_id' => $workspaceId,
        ]);

        return [
            'success' => $eligibility['eligible'],
            'run_id' => $runId,
            'status' => $status,
            'provider' => $provider,
            'bot_display_name' => $displayName,
            'workspace_id' => $workspaceId,
            'invite_instructions' => $this->buildInviteInstructions($config, $displayName),
            'reasons' => $eligibility['errors'],
        ];
    }

    public function handleWebhook(array $payload): array
    {
        $workspaceId = $this->workspaceId();
        $config = $this->configModule->get($workspaceId);
        if (empty($config['enabled'])) {
            return [
                'success' => false,
                'run_id' => null,
                'status' => 'blocked',
                'provider' => (string) ($config['provider'] ?? 'zoom'),
                'reasons' => ['meeting_bot_disabled'],
            ];
        }
        $provider = $this->resolveProvider($payload, $config);
        $event = $this->normalizeWebhookForProvider($provider, $payload);
        $run = $this->findLatestRunByMeeting(
            $provider,
            (string) ($event['external_meeting_id'] ?? ''),
            (string) ($event['external_event_id'] ?? '')
        );

        if (!$run) {
            $runId = $this->createRun([
                'provider' => $provider,
                'external_meeting_id' => $event['external_meeting_id'],
                'external_event_id' => $event['external_event_id'],
                'title' => $event['title'],
                'join_url' => $event['join_url'],
                'bot_display_name_used' => $this->identity->resolveDisplayName($config),
                'join_request_source' => 'webhook',
                'join_policy_snapshot' => (string) ($config['join_policy'] ?? 'manual_invite_only'),
                'status' => 'scheduled',
                'recording_status' => 'not_requested',
                'transcript_status' => !empty($config['transcript_required']) ? 'pending' : 'not_required',
                'consent_status' => trim((string) ($config['consent_notice'] ?? '')) !== '' ? 'notified' : 'not_required',
                'organizer_email' => $event['organizer_email'],
                'normalized_attendees_json' => $event['participants'],
                'provider_payload_json' => $payload,
                'scheduled_for' => $event['started_at'],
                'started_at' => $event['started_at'],
                'workspace_id' => $workspaceId,
            ]);
            $run = $this->getRunById($runId);
        }

        if (!$run) {
            throw new \RuntimeException('Meeting bot run could not be created.');
        }

        $runId = (int) $run['id'];
        return match ((string) ($event['event_type'] ?? 'unknown')) {
            'meeting_started' => $this->handleMeetingStarted($runId, $payload, $event, $run),
            'participant_joined' => $this->handleParticipantJoined($runId, $payload, $event),
            'recording_started' => $this->handleRecordingStarted($runId, $payload),
            'recording_completed' => $this->handleRecordingCompleted($runId, $payload, $event),
            'meeting_scheduled' => $this->handleMeetingScheduled($runId, $payload, $event),
            'transcript_completed' => $this->handleTranscriptCompleted($runId, $payload, $event, $run, $config, $provider),
            default => ['success' => true, 'run_id' => $runId, 'status' => (string) ($event['event_type'] ?? 'ignored'), 'provider' => $provider],
        };
    }

    public function handleZoomWebhook(array $payload): array
    {
        $payload['provider'] = 'zoom';
        return $this->handleWebhook($payload);
    }

    public function getRecentRuns(int $limit = 20): array
    {
        $workspaceId = $this->workspaceId();
        return Database::query(
            "SELECT r.*, n.apply_status AS meeting_note_apply_status, n.confidence AS meeting_note_confidence
             FROM meeting_bot_runs r
             LEFT JOIN meeting_note_taker_runs n ON n.id = r.meeting_note_taker_run_id
             WHERE r.workspace_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT " . max(1, (int) $limit),
            [$workspaceId]
        );
    }

    public function getRunById(int $runId): ?array
    {
        $workspaceId = $this->workspaceId();
        return Database::queryOne(
            "SELECT r.*, n.apply_status AS meeting_note_apply_status, n.confidence AS meeting_note_confidence
             FROM meeting_bot_runs r
             LEFT JOIN meeting_note_taker_runs n ON n.id = r.meeting_note_taker_run_id
             WHERE r.id = ?
               AND r.workspace_id = ?",
            [$runId, $workspaceId]
        );
    }

    public function retryRunProcessing(int $runId): array
    {
        $run = $this->getRunById($runId);
        if (!$run) {
            throw new \RuntimeException('Meeting bot run not found.');
        }
        if (trim((string) ($run['transcript_excerpt'] ?? '')) === '') {
            throw new \RuntimeException('This run does not have transcript content to retry.');
        }
        if (!empty($run['meeting_note_taker_run_id'])) {
            return [
                'success' => true,
                'run_id' => $runId,
                'status' => (string) ($run['status'] ?? 'processed'),
                'meeting_note_taker_run_id' => (int) $run['meeting_note_taker_run_id'],
                'provider' => (string) ($run['provider'] ?? 'zoom'),
                'duplicate' => true,
            ];
        }

        return $this->handleWebhook([
            'provider' => $run['provider'] ?? 'zoom',
            'event' => 'transcript.completed',
            'external_meeting_id' => $run['external_meeting_id'] ?? null,
            'external_event_id' => $run['external_event_id'] ?? null,
            'title' => $run['title'] ?? null,
            'organizer_email' => $run['organizer_email'] ?? null,
            'started_at' => $run['started_at'] ?? null,
            'ended_at' => $run['ended_at'] ?? null,
            'participants' => json_decode((string) ($run['normalized_attendees_json'] ?? ''), true) ?: [],
            'transcript' => $run['transcript_excerpt'] ?? '',
        ]);
    }

    private function handleMeetingStarted(int $runId, array $payload, array $event, array $run): array
    {
        $this->updateRun($runId, [
            'status' => 'joining',
            'recording_status' => (string) ($run['recording_status'] ?? 'requested'),
            'started_at' => $event['started_at'] ?? date('Y-m-d H:i:s'),
            'provider_payload_json' => $payload,
        ]);

        return ['success' => true, 'run_id' => $runId, 'status' => 'joining', 'provider' => (string) ($run['provider'] ?? 'zoom')];
    }

    private function handleParticipantJoined(int $runId, array $payload, array $event): array
    {
        $this->updateRun($runId, [
            'status' => 'joined',
            'joined_at' => date('Y-m-d H:i:s'),
            'normalized_attendees_json' => $event['participants'],
            'provider_payload_json' => $payload,
        ]);

        return ['success' => true, 'run_id' => $runId, 'status' => 'joined', 'provider' => (string) ($event['provider'] ?? 'zoom')];
    }

    private function handleRecordingStarted(int $runId, array $payload): array
    {
        $this->updateRun($runId, [
            'status' => 'recording',
            'recording_status' => 'recording',
            'provider_payload_json' => $payload,
        ]);

        return ['success' => true, 'run_id' => $runId, 'status' => 'recording', 'provider' => (string) ($payload['provider'] ?? 'zoom')];
    }

    private function handleRecordingCompleted(int $runId, array $payload, array $event): array
    {
        $this->updateRun($runId, [
            'recording_status' => 'completed',
            'provider_payload_json' => $payload,
            'ended_at' => $event['ended_at'] ?? null,
        ]);

        return ['success' => true, 'run_id' => $runId, 'status' => 'recording_completed', 'provider' => (string) ($event['provider'] ?? ($payload['provider'] ?? 'zoom'))];
    }

    private function handleMeetingScheduled(int $runId, array $payload, array $event): array
    {
        $this->updateRun($runId, [
            'status' => 'scheduled',
            'provider_payload_json' => $payload,
            'scheduled_for' => $event['started_at'] ?? null,
            'normalized_attendees_json' => $event['participants'],
        ]);

        return ['success' => true, 'run_id' => $runId, 'status' => 'scheduled', 'provider' => (string) ($event['provider'] ?? 'google_meet')];
    }

    private function handleTranscriptCompleted(int $runId, array $payload, array $event, array $run, array $config, string $provider): array
    {
        if (!empty($run['meeting_note_taker_run_id'])) {
            return [
                'success' => true,
                'run_id' => $runId,
                'status' => (string) ($run['status'] ?? 'processed'),
                'provider' => $provider,
                'duplicate' => true,
            ];
        }

        $transcript = trim((string) ($event['transcript'] ?? ''));
        if ($transcript === '' && !empty($config['transcript_required'])) {
            $this->updateRun($runId, [
                'status' => 'failed',
                'transcript_status' => 'failed',
                'failure_reason' => 'transcript_missing',
                'provider_payload_json' => $payload,
            ]);
            return [
                'success' => false,
                'run_id' => $runId,
                'status' => 'failed',
                'provider' => $provider,
                'reasons' => ['transcript_missing'],
            ];
        }

        $this->updateRun($runId, [
            'status' => 'transcript_ready',
            'transcript_status' => 'received',
            'transcript_received_at' => date('Y-m-d H:i:s'),
            'transcript_excerpt' => substr($transcript, 0, 2000),
            'normalized_attendees_json' => $event['participants'],
            'provider_payload_json' => $payload,
        ]);

        $noteResult = $this->noteTaker->ingest([
            'provider' => $provider,
            'external_meeting_id' => $event['external_meeting_id'],
            'external_event_id' => $event['external_event_id'],
            'title' => $event['title'],
            'transcript' => $transcript,
            'summary' => $event['summary'],
            'participants' => $provider === 'zoom' ? $event['participants'] : [],
            'attendees' => $provider === 'google_meet' ? $event['participants'] : [],
            'organizer' => ['email' => $event['organizer_email']],
            'started_at' => $event['started_at'],
            'ended_at' => $event['ended_at'],
            'meeting_bot_run_id' => $runId,
            'meeting_bot_display_name' => (string) ($run['bot_display_name_used'] ?? $this->identity->resolveDisplayName($config)),
            'meeting_note_title' => (string) ($run['bot_display_name_used'] ?? 'Meeting Assistant') . ' summary',
            'source_surface' => 'meeting_bot',
            'auto_apply_mode_ceiling' => (string) ($config['auto_apply_mode'] ?? 'auto_safe'),
        ], (int) ($run['created_by'] ?? 0));

        $finalStatus = match ((string) ($noteResult['status'] ?? 'processed')) {
            'applied' => 'processed',
            'partial', 'review_required' => 'partial',
            'blocked', 'failed' => 'failed',
            default => 'partial',
        };
        $noteTakerRunId = !empty($noteResult['run_id']) ? (int) $noteResult['run_id'] : null;

        $this->updateRun($runId, [
            'status' => $finalStatus,
            'transcript_status' => $finalStatus === 'processed' ? 'processed' : ($finalStatus === 'partial' ? 'partial' : 'failed'),
            'meeting_note_taker_run_id' => $noteTakerRunId,
            'note_taker_status' => (string) ($noteResult['status'] ?? 'unknown'),
            'failure_reason' => $finalStatus === 'failed' ? implode(', ', (array) ($noteResult['reasons'] ?? [])) : null,
        ]);

        return [
            'success' => true,
            'run_id' => $runId,
            'status' => $finalStatus,
            'provider' => $provider,
            'meeting_note_taker_run_id' => $noteTakerRunId,
        ];
    }

    private function createRun(array $data): int
    {
        $workspaceId = !empty($data['workspace_id']) ? (int) $data['workspace_id'] : $this->workspaceId();
        Database::execute(
            "INSERT INTO meeting_bot_runs (
                workspace_id, provider, external_meeting_id, external_event_id, event_id, title, join_url, bot_display_name_used,
                join_request_source, join_policy_snapshot, status, recording_status, transcript_status, consent_status,
                organizer_email, transcript_excerpt, failure_reason, note_taker_status, normalized_attendees_json,
                provider_payload_json, scheduled_for, joined_at, started_at, ended_at, transcript_received_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                (string) ($data['provider'] ?? 'zoom'),
                $data['external_meeting_id'] ?: null,
                $data['external_event_id'] ?: null,
                $data['event_id'] ?? null,
                $data['title'] ?: null,
                $data['join_url'] ?: null,
                (string) ($data['bot_display_name_used'] ?? 'Meeting Assistant'),
                (string) ($data['join_request_source'] ?? 'manual'),
                (string) ($data['join_policy_snapshot'] ?? 'manual_invite_only'),
                (string) ($data['status'] ?? 'scheduled'),
                (string) ($data['recording_status'] ?? 'not_requested'),
                (string) ($data['transcript_status'] ?? 'pending'),
                (string) ($data['consent_status'] ?? 'pending'),
                $data['organizer_email'] ?: null,
                $data['transcript_excerpt'] ?? null,
                $data['failure_reason'] ?? null,
                $data['note_taker_status'] ?? null,
                json_encode($data['normalized_attendees_json'] ?? []),
                json_encode($data['provider_payload_json'] ?? []),
                $data['scheduled_for'] ?? null,
                $data['joined_at'] ?? null,
                $data['started_at'] ?? null,
                $data['ended_at'] ?? null,
                $data['transcript_received_at'] ?? null,
                $data['created_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function updateRun(int $runId, array $data): void
    {
        $allowed = [
            'external_event_id', 'event_id', 'meeting_note_taker_run_id', 'title', 'join_url', 'status',
            'recording_status', 'transcript_status', 'consent_status', 'organizer_email', 'transcript_excerpt',
            'failure_reason', 'note_taker_status', 'normalized_attendees_json', 'provider_payload_json',
            'scheduled_for', 'joined_at', 'started_at', 'ended_at', 'transcript_received_at',
        ];
        $updates = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $updates[] = "{$field} = ?";
            $params[] = in_array($field, ['normalized_attendees_json', 'provider_payload_json'], true)
                ? json_encode($data[$field] ?? [])
                : $data[$field];
        }

        if ($updates === []) {
            return;
        }

        $params[] = $runId;
        $params[] = $this->workspaceId();
        Database::execute(
            "UPDATE meeting_bot_runs SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ? AND workspace_id = ?",
            $params
        );
    }

    private function findLatestRunByMeeting(string $provider, string $externalMeetingId, string $externalEventId = ''): ?array
    {
        $externalMeetingId = trim($externalMeetingId);
        if ($externalMeetingId !== '') {
            $run = Database::queryOne(
                "SELECT * FROM meeting_bot_runs
                 WHERE provider = ?
                   AND external_meeting_id = ?
                   AND workspace_id = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$provider, $externalMeetingId, $this->workspaceId()]
            );
            if ($run) {
                return $run;
            }
        }

        $externalEventId = trim($externalEventId);
        if ($externalEventId === '') {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM meeting_bot_runs
             WHERE provider = ?
               AND external_event_id = ?
               AND workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$provider, $externalEventId, $this->workspaceId()]
        );
    }

    private function workspaceId(): int
    {
        if ($this->workspaceId !== null && $this->workspaceId > 0) {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId($this->workspaceId);
        }

        try {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function resolveProvider(array $payload, array $config): string
    {
        $provider = strtolower(trim((string) ($payload['provider'] ?? $config['provider'] ?? 'zoom')));
        return in_array($provider, ['zoom', 'google_meet'], true) ? $provider : 'zoom';
    }

    private function evaluateEligibilityForProvider(string $provider, array $payload, array $config): array
    {
        return $provider === 'google_meet'
            ? $this->googleMeet->evaluateEligibility($payload, $config)
            : $this->zoom->evaluateEligibility($payload, $config);
    }

    private function normalizeWebhookForProvider(string $provider, array $payload): array
    {
        return $provider === 'google_meet'
            ? $this->googleMeet->normalizeWebhook($payload)
            : $this->zoom->normalizeWebhook($payload);
    }

    private function buildInviteInstructions(array $config, string $displayName): string
    {
        return ((string) ($config['provider'] ?? 'zoom')) === 'google_meet'
            ? $this->googleMeet->buildInviteInstructions($config, $displayName)
            : $this->zoom->buildInviteInstructions($config, $displayName);
    }

    private function resolveMeetingId(string $provider, array $payload): string
    {
        if ($provider === 'google_meet') {
            return trim((string) ($payload['external_meeting_id'] ?? ($payload['conference_id'] ?? '')));
        }
        return trim((string) ($payload['external_meeting_id'] ?? ''));
    }

    private function resolveEventId(string $provider, array $payload): string
    {
        if ($provider === 'google_meet') {
            return trim((string) ($payload['external_event_id'] ?? ($payload['calendar_event_id'] ?? '')));
        }
        return trim((string) ($payload['external_event_id'] ?? ''));
    }
}
