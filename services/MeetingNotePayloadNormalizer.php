<?php

namespace CRM\Services;

class MeetingNotePayloadNormalizer
{
    public function normalize(array $payload): array
    {
        $provider = strtolower(trim((string) ($payload['provider'] ?? 'generic')));

        return match ($provider) {
            'zoom' => $this->normalizeZoom($payload),
            'google_meet', 'google-meet', 'meet' => $this->normalizeGoogleMeet($payload),
            default => $this->normalizeGeneric($payload, $provider !== '' ? $provider : 'generic'),
        };
    }

    private function normalizeGeneric(array $payload, string $provider): array
    {
        $attendees = $this->normalizeAttendees($payload['attendees'] ?? []);
        $organizer = $this->normalizePerson($payload['organizer'] ?? ['email' => $payload['organizer_email'] ?? null]);

        return [
            'provider' => $provider,
            'external_meeting_id' => $this->stringOrNull($payload['external_meeting_id'] ?? null),
            'title' => $this->stringOrNull($payload['title'] ?? null),
            'transcript' => trim((string) ($payload['transcript'] ?? '')),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'attendees' => $attendees,
            'organizer' => $organizer,
            'started_at' => $this->stringOrNull($payload['started_at'] ?? null),
            'ended_at' => $this->stringOrNull($payload['ended_at'] ?? null),
            'contact_id' => !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null,
            'deal_id' => !empty($payload['deal_id']) ? (int) $payload['deal_id'] : null,
            'raw_payload' => $payload,
        ];
    }

    private function normalizeZoom(array $payload): array
    {
        $meeting = is_array($payload['meeting'] ?? null) ? $payload['meeting'] : [];
        $participants = $payload['participants'] ?? ($meeting['participants'] ?? []);
        $transcript = $payload['transcript'] ?? ($payload['transcript_text'] ?? ($meeting['transcript'] ?? ''));
        $summary = $payload['summary'] ?? ($meeting['summary'] ?? '');

        return [
            'provider' => 'zoom',
            'external_meeting_id' => $this->stringOrNull($payload['external_meeting_id'] ?? ($meeting['id'] ?? null)),
            'title' => $this->stringOrNull($payload['title'] ?? ($meeting['topic'] ?? null)),
            'transcript' => trim((string) $transcript),
            'summary' => trim((string) $summary),
            'attendees' => $this->normalizeAttendees($participants),
            'organizer' => $this->normalizePerson($payload['organizer'] ?? [
                'email' => $meeting['host_email'] ?? null,
                'name' => $meeting['host_name'] ?? null,
            ]),
            'started_at' => $this->stringOrNull($payload['started_at'] ?? ($meeting['start_time'] ?? null)),
            'ended_at' => $this->stringOrNull($payload['ended_at'] ?? ($meeting['end_time'] ?? null)),
            'contact_id' => !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null,
            'deal_id' => !empty($payload['deal_id']) ? (int) $payload['deal_id'] : null,
            'raw_payload' => $payload,
        ];
    }

    private function normalizeGoogleMeet(array $payload): array
    {
        $meeting = is_array($payload['meeting'] ?? null) ? $payload['meeting'] : [];
        $conference = is_array($payload['conference'] ?? null) ? $payload['conference'] : [];
        $transcript = $payload['transcript'] ?? ($payload['notes'] ?? ($meeting['transcript'] ?? ''));
        $summary = $payload['summary'] ?? ($meeting['summary'] ?? '');
        $attendees = $payload['attendees'] ?? ($meeting['attendees'] ?? ($conference['participants'] ?? []));

        return [
            'provider' => 'google_meet',
            'external_meeting_id' => $this->stringOrNull($payload['external_meeting_id'] ?? ($conference['conference_id'] ?? ($meeting['id'] ?? null))),
            'title' => $this->stringOrNull($payload['title'] ?? ($meeting['title'] ?? null)),
            'transcript' => trim((string) $transcript),
            'summary' => trim((string) $summary),
            'attendees' => $this->normalizeAttendees($attendees),
            'organizer' => $this->normalizePerson($payload['organizer'] ?? ($meeting['organizer'] ?? [])),
            'started_at' => $this->stringOrNull($payload['started_at'] ?? ($meeting['started_at'] ?? null)),
            'ended_at' => $this->stringOrNull($payload['ended_at'] ?? ($meeting['ended_at'] ?? null)),
            'contact_id' => !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null,
            'deal_id' => !empty($payload['deal_id']) ? (int) $payload['deal_id'] : null,
            'raw_payload' => $payload,
        ];
    }

    private function normalizeAttendees(mixed $attendees): array
    {
        if (!is_array($attendees)) {
            return [];
        }

        $normalized = [];
        foreach ($attendees as $attendee) {
            $person = $this->normalizePerson($attendee);
            if (($person['email'] ?? '') === '' && ($person['name'] ?? '') === '') {
                continue;
            }
            $normalized[] = $person;
        }
        return $normalized;
    }

    private function normalizePerson(mixed $person): array
    {
        if (is_string($person)) {
            $email = trim($person);
            return [
                'name' => '',
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '',
            ];
        }

        if (!is_array($person)) {
            return ['name' => '', 'email' => ''];
        }

        $email = trim((string) ($person['email'] ?? $person['mail'] ?? ''));
        return [
            'name' => trim((string) ($person['name'] ?? $person['display_name'] ?? $person['full_name'] ?? '')),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }
}
