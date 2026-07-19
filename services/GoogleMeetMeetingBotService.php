<?php

namespace CRM\Services;

class GoogleMeetMeetingBotService
{
    public function evaluateEligibility(array $payload, array $config): array
    {
        $externalMeetingId = trim((string) ($payload['external_meeting_id'] ?? ($payload['conference_id'] ?? '')));
        $externalEventId = trim((string) ($payload['external_event_id'] ?? ($payload['calendar_event_id'] ?? '')));
        $errors = [];

        if ($externalMeetingId === '' && $externalEventId === '') {
            $errors[] = 'missing_google_meet_reference';
        }

        if ((string) ($config['provider'] ?? 'zoom') !== 'google_meet') {
            $errors[] = 'provider_not_supported';
        }

        return [
            'eligible' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function normalizeWebhook(array $payload): array
    {
        $event = strtolower(trim((string) ($payload['event'] ?? $payload['type'] ?? '')));
        $meeting = is_array($payload['meeting'] ?? null) ? $payload['meeting'] : [];
        $conference = is_array($payload['conference'] ?? null) ? $payload['conference'] : [];
        $attendees = $payload['attendees'] ?? ($meeting['attendees'] ?? ($conference['participants'] ?? []));
        $transcript = $payload['transcript'] ?? ($payload['notes'] ?? ($meeting['transcript'] ?? ''));

        return [
            'provider' => 'google_meet',
            'event_type' => $this->mapEventType($event, $payload, $meeting),
            'external_event_id' => trim((string) ($payload['external_event_id'] ?? ($payload['calendar_event_id'] ?? ($meeting['calendar_event_id'] ?? '')))),
            'external_meeting_id' => trim((string) ($payload['external_meeting_id'] ?? ($conference['conference_id'] ?? ($meeting['id'] ?? '')))),
            'title' => trim((string) ($payload['title'] ?? ($meeting['title'] ?? ''))),
            'organizer_email' => trim((string) ($payload['organizer_email'] ?? (($meeting['organizer']['email'] ?? '')))),
            'join_url' => trim((string) ($payload['join_url'] ?? ($conference['url'] ?? ($meeting['join_url'] ?? '')))),
            'started_at' => $payload['started_at'] ?? ($meeting['started_at'] ?? null),
            'ended_at' => $payload['ended_at'] ?? ($meeting['ended_at'] ?? null),
            'participants' => is_array($attendees) ? $attendees : [],
            'transcript' => trim((string) $transcript),
            'summary' => trim((string) ($payload['summary'] ?? ($meeting['summary'] ?? ''))),
            'raw_payload' => $payload,
        ];
    }

    public function buildInviteInstructions(array $config, string $displayName): string
    {
        $mode = (string) ($config['google_transcript_mode'] ?? 'manual_ingest');
        $base = $displayName . ' uses transcript-first Google Meet capture in v1.';
        if ($mode === 'workspace_export') {
            return $base . ' Connect Google Workspace metadata and post transcript or notes exports into the meeting bot webhook once the meeting completes.';
        }
        return $base . ' Register the Meet session with the calendar event ID or conference ID, then post transcript or notes exports into the meeting bot webhook after the meeting.';
    }

    private function mapEventType(string $event, array $payload, array $meeting): string
    {
        if ($event !== '') {
            return match ($event) {
                'transcript.completed', 'meeting.notes_ready', 'google_meet.transcript_completed' => 'transcript_completed',
                'meeting.scheduled', 'calendar.event_registered' => 'meeting_scheduled',
                default => $event,
            };
        }

        if (trim((string) ($payload['transcript'] ?? '')) !== '' || trim((string) ($payload['notes'] ?? '')) !== '' || trim((string) ($meeting['transcript'] ?? '')) !== '') {
            return 'transcript_completed';
        }

        return 'unknown';
    }
}
