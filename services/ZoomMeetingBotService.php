<?php

namespace CRM\Services;

class ZoomMeetingBotService
{
    public function evaluateEligibility(array $payload, array $config): array
    {
        $externalMeetingId = trim((string) ($payload['external_meeting_id'] ?? ''));
        $joinUrl = trim((string) ($payload['join_url'] ?? ''));
        $errors = [];

        if ($externalMeetingId === '' && $joinUrl === '') {
            $errors[] = 'missing_external_meeting_reference';
        }

        if ((string) ($config['provider'] ?? 'zoom') !== 'zoom') {
            $errors[] = 'provider_not_supported';
        }

        if ((string) ($config['join_policy'] ?? 'manual_invite_only') === 'auto_join_eligible') {
            if (trim((string) ($config['zoom_client_id'] ?? '')) === '' || trim((string) ($config['zoom_client_secret'] ?? '')) === '') {
                $errors[] = 'zoom_credentials_missing';
            }
        }

        return [
            'eligible' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function normalizeWebhook(array $payload): array
    {
        $event = strtolower(trim((string) ($payload['event'] ?? $payload['type'] ?? '')));
        $object = is_array($payload['payload']['object'] ?? null) ? $payload['payload']['object'] : (is_array($payload['object'] ?? null) ? $payload['object'] : []);
        $participants = $object['participants'] ?? ($payload['participants'] ?? []);
        $transcriptText = $payload['transcript'] ?? ($payload['transcript_text'] ?? ($object['transcript'] ?? ''));

        return [
            'provider' => 'zoom',
            'event_type' => $this->mapEventType($event, $payload, $object),
            'external_event_id' => trim((string) ($payload['event_id'] ?? $payload['uuid'] ?? '')),
            'external_meeting_id' => trim((string) ($payload['external_meeting_id'] ?? ($object['id'] ?? $payload['meeting_id'] ?? ''))),
            'title' => trim((string) ($payload['title'] ?? ($object['topic'] ?? ''))),
            'organizer_email' => trim((string) ($payload['organizer_email'] ?? ($object['host_email'] ?? ''))),
            'join_url' => trim((string) ($payload['join_url'] ?? ($object['join_url'] ?? ''))),
            'started_at' => $payload['started_at'] ?? ($object['start_time'] ?? null),
            'ended_at' => $payload['ended_at'] ?? ($object['end_time'] ?? null),
            'participants' => is_array($participants) ? $participants : [],
            'transcript' => trim((string) $transcriptText),
            'summary' => trim((string) ($payload['summary'] ?? ($object['summary'] ?? ''))),
            'raw_payload' => $payload,
        ];
    }

    public function buildInviteInstructions(array $config, string $displayName): string
    {
        $base = 'Invite ' . $displayName . ' to the Zoom meeting and make sure recording/transcript permissions are enabled.';
        return match ((string) ($config['join_policy'] ?? 'manual_invite_only')) {
            'calendar_suggested' => $base . ' Calendar-linked meetings can be registered automatically when a Zoom meeting ID or join URL is detected.',
            'auto_join_eligible' => $base . ' Eligible meetings may be scheduled automatically when Zoom credentials and meeting metadata are present.',
            default => $base,
        };
    }

    private function mapEventType(string $event, array $payload, array $object): string
    {
        if ($event !== '') {
            return match ($event) {
                'meeting.started', 'meeting.start' => 'meeting_started',
                'meeting.participant_joined', 'meeting.joined', 'bot.joined' => 'participant_joined',
                'recording.started' => 'recording_started',
                'recording.completed' => 'recording_completed',
                'transcript.completed', 'recording.transcript_completed' => 'transcript_completed',
                default => $event,
            };
        }

        if (trim((string) ($payload['transcript'] ?? '')) !== '' || trim((string) ($payload['transcript_text'] ?? '')) !== '' || trim((string) ($object['transcript'] ?? '')) !== '') {
            return 'transcript_completed';
        }

        return 'unknown';
    }
}
