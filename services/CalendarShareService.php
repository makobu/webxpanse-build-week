<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\EmailDraftGenerator;

class CalendarShareService
{
    private const DEFAULT_SUGGESTED_SLOT_COUNT = 3;
    private const MAX_SUGGESTED_SLOT_COUNT = 5;

    private const PURPOSE_LABELS = [
        'consultation' => 'Consultation',
        'follow_up' => 'Follow-up',
        'demo' => 'Demo',
        'reschedule' => 'Reschedule',
        'site_visit' => 'Site visit',
        'support_call' => 'Support call',
        'custom' => 'Meeting',
    ];

    public function __construct(private ?MeetingAvailabilityService $availability = null, private ?EmailService $emailService = null)
    {
        $this->availability = $this->availability ?? new MeetingAvailabilityService();
        $this->emailService = $this->emailService ?? new EmailService();
    }

    /**
     * @return array<string,string>
     */
    public function purposeOptions(): array
    {
        return self::PURPOSE_LABELS;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createOrUpdateShare(int $workspaceId, int $actorUserId, array $payload): array
    {
        $this->assertStorageAvailable();
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace scope is required.');
        }

        $profile = $this->resolveProfile($workspaceId, $actorUserId, (int) ($payload['profile_id'] ?? 0));
        if (empty($profile['public_enabled']) || (string) ($profile['status'] ?? '') !== 'active') {
            throw new \RuntimeException('The selected booking profile must be active and public before it can be shared.');
        }

        $deal = $this->resolveDeal($workspaceId, (int) ($payload['deal_id'] ?? 0));
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($contactId <= 0 && $deal !== null) {
            $contactId = (int) ($deal['contact_id'] ?? 0);
        }
        $contact = $this->resolveContact($workspaceId, $contactId);
        if ($contact === null) {
            throw new \RuntimeException('Choose a contact before sharing calendar availability.');
        }
        if ($deal !== null && (int) ($deal['contact_id'] ?? 0) > 0 && (int) $deal['contact_id'] !== (int) $contact['id']) {
            throw new \RuntimeException('The selected deal belongs to a different contact.');
        }

        $purpose = $this->normalizePurpose((string) ($payload['meeting_purpose'] ?? $payload['purpose'] ?? 'consultation'));
        $customPurpose = trim((string) ($payload['custom_purpose'] ?? ''));
        $duration = $this->availability->normalizeDuration(
            (int) ($payload['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30),
            $profile
        );
        $dateRange = $this->normalizeDateRange($payload, $profile);
        $suggestedSlotCount = $this->boundedInt(
            $payload['suggested_slot_count'] ?? self::DEFAULT_SUGGESTED_SLOT_COUNT,
            1,
            self::MAX_SUGGESTED_SLOT_COUNT,
            self::DEFAULT_SUGGESTED_SLOT_COUNT
        );
        $suggestedSlots = $this->suggestedSlots(
            (int) $profile['id'],
            $dateRange['date_from'],
            $dateRange['date_to'],
            (string) $profile['timezone'],
            $duration,
            $suggestedSlotCount
        );
        $expiresAt = $this->normalizeExpiry($payload);
        $shareId = (int) ($payload['share_id'] ?? $payload['id'] ?? 0);
        $existing = $shareId > 0 ? $this->getShare($workspaceId, $shareId) : null;
        if ($existing !== null && in_array((string) ($existing['status'] ?? ''), ['booked', 'cancelled', 'expired'], true)) {
            throw new \RuntimeException('This calendar share can no longer be edited.');
        }

        $token = $existing !== null ? (string) $existing['token'] : bin2hex(random_bytes(32));
        $bookingUrl = $this->bookingUrl($token);
        $metadata = $this->mergeMetadata((array) ($existing['metadata_json_decoded'] ?? []), [
            'suggested_slot_count' => $suggestedSlotCount,
            'last_prepared_by' => $actorUserId,
            'last_prepared_at' => date('c'),
        ]);

        if ($existing !== null) {
            Database::execute(
                "UPDATE meeting_calendar_shares
                 SET profile_id = ?,
                     contact_id = ?,
                     deal_id = ?,
                     meeting_purpose = ?,
                     custom_purpose = ?,
                     duration_minutes = ?,
                     date_from = ?,
                     date_to = ?,
                     suggested_slots_json = ?,
                     booking_url = ?,
                     expires_at = ?,
                     status = CASE WHEN status IN ('sent','viewed') THEN status ELSE 'ready' END,
                     metadata_json = ?,
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [
                    (int) $profile['id'],
                    (int) $contact['id'],
                    $deal !== null ? (int) $deal['id'] : null,
                    $purpose,
                    $customPurpose !== '' ? $customPurpose : null,
                    $duration,
                    $dateRange['date_from'],
                    $dateRange['date_to'],
                    json_encode($suggestedSlots),
                    $bookingUrl,
                    $expiresAt,
                    json_encode($metadata),
                    $shareId,
                    $workspaceId,
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO meeting_calendar_shares (
                    workspace_id, profile_id, contact_id, deal_id, created_by, token, meeting_purpose,
                    custom_purpose, duration_minutes, date_from, date_to, suggested_slots_json,
                    booking_url, expires_at, status, metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready', ?)",
                [
                    $workspaceId,
                    (int) $profile['id'],
                    (int) $contact['id'],
                    $deal !== null ? (int) $deal['id'] : null,
                    $actorUserId > 0 ? $actorUserId : null,
                    $token,
                    $purpose,
                    $customPurpose !== '' ? $customPurpose : null,
                    $duration,
                    $dateRange['date_from'],
                    $dateRange['date_to'],
                    json_encode($suggestedSlots),
                    $bookingUrl,
                    $expiresAt,
                    json_encode($metadata),
                ]
            );
            $shareId = (int) Database::lastInsertId();
        }

        return $this->getShare($workspaceId, $shareId) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getShare(int $workspaceId, int $shareId): ?array
    {
        if ($workspaceId <= 0 || $shareId <= 0 || !Database::tableExists('meeting_calendar_shares')) {
            return null;
        }

        $share = Database::queryOne(
            "SELECT s.*, p.title AS profile_title, p.slug AS profile_slug, p.timezone AS profile_timezone,
                    p.workspace_id AS profile_workspace_id,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name,
                    c.email AS contact_email, c.phone AS contact_phone, c.company AS contact_company,
                    d.title AS deal_title
             FROM meeting_calendar_shares s
             JOIN meeting_booking_profiles p ON p.id = s.profile_id AND p.workspace_id = s.workspace_id
             LEFT JOIN contacts c ON c.id = s.contact_id AND c.workspace_id = s.workspace_id
             LEFT JOIN deals d ON d.id = s.deal_id AND d.workspace_id = s.workspace_id
             WHERE s.workspace_id = ?
               AND s.id = ?
             LIMIT 1",
            [$workspaceId, $shareId]
        );

        return $share ? $this->hydrateShare($share) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listShares(int $workspaceId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('meeting_calendar_shares')) {
            return [];
        }

        $where = ['s.workspace_id = ?'];
        $params = [$workspaceId];
        if (!empty($filters['status'])) {
            $where[] = 's.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 's.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['deal_id'])) {
            $where[] = 's.deal_id = ?';
            $params[] = (int) $filters['deal_id'];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = Database::query(
            "SELECT s.*, p.title AS profile_title, p.slug AS profile_slug, p.timezone AS profile_timezone,
                    p.workspace_id AS profile_workspace_id,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name,
                    c.email AS contact_email, c.phone AS contact_phone, c.company AS contact_company,
                    d.title AS deal_title
             FROM meeting_calendar_shares s
             JOIN meeting_booking_profiles p ON p.id = s.profile_id AND p.workspace_id = s.workspace_id
             LEFT JOIN contacts c ON c.id = s.contact_id AND c.workspace_id = s.workspace_id
             LEFT JOIN deals d ON d.id = s.deal_id AND d.workspace_id = s.workspace_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return array_map(fn(array $row): array => $this->hydrateShare($row), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPublicShareByToken(string $token, bool $markViewed = true): ?array
    {
        $token = trim($token);
        if ($token === '' || !Database::tableExists('meeting_calendar_shares')) {
            return null;
        }

        $share = Database::queryOne(
            "SELECT s.*, p.title AS profile_title, p.slug AS profile_slug, p.timezone AS profile_timezone,
                    p.workspace_id AS profile_workspace_id, w.slug AS workspace_slug, w.name AS workspace_name,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name,
                    c.email AS contact_email, c.phone AS contact_phone, c.company AS contact_company,
                    d.title AS deal_title
             FROM meeting_calendar_shares s
             JOIN meeting_booking_profiles p ON p.id = s.profile_id AND p.workspace_id = s.workspace_id
             JOIN workspaces w ON w.id = s.workspace_id
             LEFT JOIN contacts c ON c.id = s.contact_id AND c.workspace_id = s.workspace_id
             LEFT JOIN deals d ON d.id = s.deal_id AND d.workspace_id = s.workspace_id
             WHERE s.token = ?
               AND p.public_enabled = 1
               AND p.status = 'active'
             LIMIT 1",
            [$token]
        );
        if (!$share) {
            return null;
        }

        $status = (string) ($share['status'] ?? '');
        if (strtotime((string) ($share['expires_at'] ?? '')) < time()) {
            Database::execute(
                "UPDATE meeting_calendar_shares
                 SET status = 'expired',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status NOT IN ('booked','cancelled')",
                [(int) $share['id']]
            );
            return null;
        }
        if (!in_array($status, ['draft', 'ready', 'sent', 'viewed'], true)) {
            return null;
        }

        if ($markViewed) {
            Database::execute(
                "UPDATE meeting_calendar_shares
                 SET viewed_at = COALESCE(viewed_at, NOW()),
                     status = CASE WHEN status IN ('booked','cancelled','expired') THEN status ELSE 'viewed' END,
                     updated_at = NOW()
                 WHERE id = ?",
                [(int) $share['id']]
            );
            $share['status'] = 'viewed';
            $share['viewed_at'] = $share['viewed_at'] ?: date('Y-m-d H:i:s');
        }

        return $this->hydrateShare($share);
    }

    /**
     * @return array<string,mixed>
     */
    public function draftForShare(int $workspaceId, int $actorUserId, int $shareId, bool $useAi = true): array
    {
        $share = $this->getShare($workspaceId, $shareId);
        if (!$share) {
            throw new \RuntimeException('Calendar share not found.');
        }

        $fallback = $this->fallbackDraft($share);
        $draft = $fallback;
        $source = 'calendar_share_fallback';

        if ($useAi) {
            try {
                $generator = new EmailDraftGenerator();
                $intention = $this->aiIntention($share);
                $aiDraft = $generator->generateDraftFromIntention(
                    (int) ($share['contact_id'] ?? 0) > 0 ? (int) $share['contact_id'] : null,
                    $intention,
                    'friendly',
                    [
                        'surface' => 'calendar_share',
                        'purpose' => 'meeting',
                        'mode' => 'draft_from_intention',
                        'length' => 'short',
                        'style' => 'paragraph',
                        'call_to_action' => 'Use the booking link to choose a time.',
                        'custom_instructions' => 'Preserve the booking link exactly and do not mention private calendar details, busy reasons, internal event names, or other clients.',
                    ]
                );
                if (trim((string) ($aiDraft['body_text'] ?? $aiDraft['body_html'] ?? '')) !== '') {
                    $draft = [
                        'subject' => trim((string) ($aiDraft['subject'] ?? $fallback['subject'])) ?: $fallback['subject'],
                        'body_text' => trim((string) ($aiDraft['body_text'] ?? '')),
                        'body_html' => trim((string) ($aiDraft['body_html'] ?? '')),
                    ];
                    $source = (string) ($aiDraft['source'] ?? 'calendar_share_ai');
                }
            } catch (\Throwable $e) {
                $source = 'calendar_share_fallback';
            }
        }

        $draft = $this->enforceBookingLink($draft, (string) $share['booking_url']);
        if ($draft['body_html'] === '') {
            $draft['body_html'] = $this->textToHtml($draft['body_text']);
        }

        Database::execute(
            "UPDATE meeting_calendar_shares
             SET last_draft_subject = ?,
                 last_draft_body = ?,
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $draft['subject'],
                $draft['body_text'],
                json_encode($this->mergeMetadata((array) ($share['metadata_json_decoded'] ?? []), [
                    'last_draft_source' => $source,
                    'last_draft_by' => $actorUserId,
                    'last_draft_at' => date('c'),
                ])),
                $shareId,
                $workspaceId,
            ]
        );

        return [
            'share' => $this->getShare($workspaceId, $shareId) ?: $share,
            'draft' => $draft + ['source' => $source],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sendShareEmail(int $workspaceId, int $actorUserId, int $shareId, string $subject, string $bodyText, string $bodyHtml = ''): array
    {
        $share = $this->getShare($workspaceId, $shareId);
        if (!$share) {
            throw new \RuntimeException('Calendar share not found.');
        }
        if ((int) ($share['contact_id'] ?? 0) <= 0 || trim((string) ($share['contact_email'] ?? '')) === '') {
            throw new \RuntimeException('The selected contact needs an email address before this share can be sent.');
        }

        $subject = trim($subject);
        $bodyText = trim($bodyText);
        $bodyHtml = trim($bodyHtml);
        if ($subject === '' || ($bodyText === '' && $bodyHtml === '')) {
            throw new \RuntimeException('Review the subject and message before sending.');
        }
        $draft = $this->enforceBookingLink([
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
        ], (string) $share['booking_url']);

        $result = $this->emailService->sendImmediateDetailed(
            (int) $share['contact_id'],
            (string) $share['contact_email'],
            $draft['subject'],
            $draft['body_text'],
            [
                'body_html' => $draft['body_html'] !== '' ? $draft['body_html'] : $this->textToHtml($draft['body_text']),
                'workspace_id' => $workspaceId,
                'user_id' => $actorUserId > 0 ? $actorUserId : null,
                'sender_profile' => 'outreach',
                'draft_source' => 'calendar_share',
                'draft_intention' => 'Share calendar availability link',
                'learning_source' => 'calendar_share_send',
                'learning_intent_key' => 'calendar_share',
                'calendar_share_id' => $shareId,
            ]
        );

        if (empty($result['success'])) {
            return $result + ['share' => $share];
        }

        $emailUuid = (string) ($result['email_uuid'] ?? '');
        Database::execute(
            "UPDATE meeting_calendar_shares
             SET status = 'sent',
                 sent_at = NOW(),
                 sent_email_uuid = ?,
                 last_draft_subject = ?,
                 last_draft_body = ?,
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $emailUuid !== '' ? $emailUuid : null,
                $draft['subject'],
                $draft['body_text'],
                json_encode($this->mergeMetadata((array) ($share['metadata_json_decoded'] ?? []), [
                    'last_sent_by' => $actorUserId,
                    'last_sent_at' => date('c'),
                ])),
                $shareId,
                $workspaceId,
            ]
        );

        return $result + ['share' => $this->getShare($workspaceId, $shareId)];
    }

    /**
     * @return array<string,mixed>
     */
    public function recordCopyAction(int $workspaceId, int $actorUserId, int $shareId, string $copyType): array
    {
        $share = $this->getShare($workspaceId, $shareId);
        if (!$share) {
            throw new \RuntimeException('Calendar share not found.');
        }
        $copyType = in_array($copyType, ['link', 'message'], true) ? $copyType : 'link';
        $metadata = (array) ($share['metadata_json_decoded'] ?? []);
        $copyCounts = (array) ($metadata['copy_counts'] ?? []);
        $copyCounts[$copyType] = (int) ($copyCounts[$copyType] ?? 0) + 1;
        $metadata = $this->mergeMetadata($metadata, [
            'copy_counts' => $copyCounts,
            'last_copy_type' => $copyType,
            'last_copied_by' => $actorUserId,
            'last_copied_at' => date('c'),
        ]);

        Database::execute(
            "UPDATE meeting_calendar_shares
             SET copied_at = NOW(),
                 status = CASE WHEN status = 'draft' THEN 'ready' ELSE status END,
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [json_encode($metadata), $shareId, $workspaceId]
        );

        return $this->getShare($workspaceId, $shareId) ?: $share;
    }

    public function markBooked(int $shareId, int $bookingId): void
    {
        if ($shareId <= 0 || !Database::tableExists('meeting_calendar_shares')) {
            return;
        }
        $share = Database::queryOne("SELECT metadata_json FROM meeting_calendar_shares WHERE id = ? LIMIT 1", [$shareId]) ?: [];
        $metadata = [];
        if (!empty($share['metadata_json'])) {
            $decoded = json_decode((string) $share['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        $metadata['booking_id'] = $bookingId;
        $metadata['booked_at'] = date('c');

        Database::execute(
            "UPDATE meeting_calendar_shares
             SET status = 'booked',
                 booked_at = NOW(),
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND status NOT IN ('cancelled','expired')",
            [json_encode($metadata), $shareId]
        );
    }

    /**
     * @return array<string,string>
     */
    public function publicPrefill(array $share): array
    {
        $name = trim((string) (($share['contact_first_name'] ?? '') . ' ' . ($share['contact_last_name'] ?? '')));

        return [
            'name' => $name,
            'email' => (string) ($share['contact_email'] ?? ''),
            'phone' => (string) ($share['contact_phone'] ?? ''),
            'organization' => (string) ($share['contact_company'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveProfile(int $workspaceId, int $actorUserId, int $profileId): array
    {
        $profile = $profileId > 0
            ? $this->availability->getProfile($profileId)
            : $this->availability->getDefaultProfile($workspaceId, $actorUserId);
        if (!$profile || (int) ($profile['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Booking profile not found.');
        }

        return $profile;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveContact(int $workspaceId, int $contactId): ?array
    {
        if ($contactId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT id, first_name, last_name, email, phone, company
             FROM contacts
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        ) ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveDeal(int $workspaceId, int $dealId): ?array
    {
        if ($dealId <= 0) {
            return null;
        }

        $deal = Database::queryOne(
            "SELECT id, title, contact_id
             FROM deals
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$workspaceId, $dealId]
        );
        if (!$deal) {
            throw new \RuntimeException('Deal not found.');
        }

        return $deal;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $profile
     * @return array{date_from:string,date_to:string}
     */
    private function normalizeDateRange(array $payload, array $profile): array
    {
        $timezone = new \DateTimeZone($this->availability->normalizeTimezone((string) ($profile['timezone'] ?? 'UTC')));
        $today = new \DateTimeImmutable('today', $timezone);
        $from = trim((string) ($payload['date_from'] ?? ''));
        $to = trim((string) ($payload['date_to'] ?? ''));

        $fromDate = $from !== '' ? $this->dateFromInput($from, $timezone) : $today;
        $toDate = $to !== '' ? $this->dateFromInput($to, $timezone) : $fromDate->modify('+14 days');
        if ($toDate < $fromDate) {
            $toDate = $fromDate->modify('+14 days');
        }

        return [
            'date_from' => $fromDate->format('Y-m-d'),
            'date_to' => $toDate->format('Y-m-d'),
        ];
    }

    private function dateFromInput(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        try {
            return (new \DateTimeImmutable($value, $timezone))->setTime(0, 0);
        } catch (\Throwable $e) {
            return new \DateTimeImmutable('today', $timezone);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function normalizeExpiry(array $payload): string
    {
        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
        if ($expiresAt !== '') {
            try {
                return (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
            }
        }

        $days = $this->boundedInt($payload['expires_in_days'] ?? 30, 1, 120, 30);
        return (new \DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function suggestedSlots(int $profileId, string $from, string $to, string $timezone, int $duration, int $count): array
    {
        try {
            $slots = $this->availability->getSlots($profileId, $from, $to, $timezone, $duration);
        } catch (\Throwable $e) {
            return [];
        }

        $available = [];
        foreach ($slots as $slot) {
            if (empty($slot['available'])) {
                continue;
            }
            $available[] = [
                'starts_at' => (string) ($slot['starts_at'] ?? ''),
                'ends_at' => (string) ($slot['ends_at'] ?? ''),
                'starts_at_iso' => (string) ($slot['starts_at_iso'] ?? ''),
                'ends_at_iso' => (string) ($slot['ends_at_iso'] ?? ''),
                'timezone' => (string) ($slot['timezone'] ?? $timezone),
                'duration_minutes' => (int) ($slot['duration_minutes'] ?? $duration),
            ];
            if (count($available) >= $count) {
                break;
            }
        }

        return $available;
    }

    /**
     * @param array<string,mixed> $share
     * @return array{subject:string,body_text:string,body_html:string}
     */
    private function fallbackDraft(array $share): array
    {
        $contactName = trim((string) (($share['contact_first_name'] ?? '') . ' ' . ($share['contact_last_name'] ?? '')));
        $firstName = trim((string) ($share['contact_first_name'] ?? ''));
        $greetingName = $firstName !== '' ? $firstName : ($contactName !== '' ? $contactName : 'there');
        $purposeLabel = $this->purposeLabel($share);
        $bookingUrl = (string) ($share['booking_url'] ?? '');
        $timezone = (string) ($share['profile_timezone'] ?? 'your timezone');
        $duration = (int) ($share['duration_minutes'] ?? 30);
        $slotLines = $this->slotLabels((array) ($share['suggested_slots'] ?? []), $timezone);

        $subject = 'Choose a time for our ' . strtolower($purposeLabel);
        $lines = [
            'Hi ' . $greetingName . ',',
            '',
            'I would like to schedule ' . $this->indefiniteArticle($purposeLabel) . ' ' . strtolower($purposeLabel) . ' with you. You can choose any available time using this link:',
            $bookingUrl,
            '',
        ];
        if ($slotLines !== []) {
            $lines[] = 'A few available options are:';
            foreach ($slotLines as $line) {
                $lines[] = '- ' . $line;
            }
            $lines[] = '';
        }
        $lines[] = 'The booking page will show times in your timezone, and the meeting is set for ' . $duration . ' minutes.';
        $lines[] = '';
        $lines[] = 'Thanks,';

        $bodyText = implode("\n", $lines);
        $html = [
            '<p>Hi ' . htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8') . ',</p>',
            '<p>I would like to schedule ' . htmlspecialchars($this->indefiniteArticle($purposeLabel) . ' ' . strtolower($purposeLabel), ENT_QUOTES, 'UTF-8') . ' with you. You can choose any available time using this link:</p>',
            '<p><a href="' . htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') . '</a></p>',
        ];
        if ($slotLines !== []) {
            $html[] = '<p>A few available options are:</p>';
            $html[] = '<ul><li>' . implode('</li><li>', array_map(static fn(string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), $slotLines)) . '</li></ul>';
        }
        $html[] = '<p>The booking page will show times in your timezone, and the meeting is set for ' . $duration . ' minutes.</p>';
        $html[] = '<p>Thanks,</p>';

        return [
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => implode('', $html),
        ];
    }

    /**
     * @param array<string,mixed> $share
     */
    private function aiIntention(array $share): string
    {
        $purposeLabel = $this->purposeLabel($share);
        $contactName = trim((string) (($share['contact_first_name'] ?? '') . ' ' . ($share['contact_last_name'] ?? '')));
        $slotLabels = $this->slotLabels((array) ($share['suggested_slots'] ?? []), (string) ($share['profile_timezone'] ?? 'UTC'));

        return trim(implode("\n", array_filter([
            'Write a short, friendly email inviting ' . ($contactName !== '' ? $contactName : 'the client') . ' to book ' . strtolower($purposeLabel) . '.',
            'Preserve this booking link exactly: ' . (string) ($share['booking_url'] ?? ''),
            'Duration: ' . (int) ($share['duration_minutes'] ?? 30) . ' minutes.',
            'Timezone note: the booking page shows times in the client browser timezone.',
            $slotLabels !== [] ? 'Suggested available options: ' . implode('; ', $slotLabels) . '.' : 'No suggested slots are required; invite them to choose any available time.',
            'Do not reveal event names, busy reasons, internal notes, staff workload, or other client details.',
        ])));
    }

    /**
     * @param array<string,mixed> $draft
     * @return array{subject:string,body_text:string,body_html:string}
     */
    private function enforceBookingLink(array $draft, string $bookingUrl): array
    {
        $subject = trim((string) ($draft['subject'] ?? 'Choose a time'));
        $bodyText = trim((string) ($draft['body_text'] ?? ''));
        $bodyHtml = trim((string) ($draft['body_html'] ?? ''));
        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)), ENT_QUOTES, 'UTF-8'));
        }
        if ($bookingUrl !== '' && !str_contains($bodyText, $bookingUrl) && !str_contains($bodyHtml, $bookingUrl)) {
            $bodyText = rtrim($bodyText) . "\n\nBook a time here: " . $bookingUrl;
            if ($bodyHtml !== '') {
                $bodyHtml .= '<p>Book a time here: <a href="' . htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';
            }
        }

        return [
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
        ];
    }

    /**
     * @param list<array<string,mixed>> $slots
     * @return list<string>
     */
    private function slotLabels(array $slots, string $fallbackTimezone): array
    {
        $labels = [];
        foreach ($slots as $slot) {
            $startsAt = (string) ($slot['starts_at_iso'] ?? $slot['starts_at'] ?? '');
            if ($startsAt === '') {
                continue;
            }
            $timezone = $this->availability->normalizeTimezone((string) ($slot['timezone'] ?? $fallbackTimezone));
            try {
                $date = new \DateTimeImmutable($startsAt);
                $date = $date->setTimezone(new \DateTimeZone($timezone));
                $labels[] = $date->format('D, M j \a\t g:i A') . ' ' . $timezone;
            } catch (\Throwable $e) {
            }
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $share
     */
    private function purposeLabel(array $share): string
    {
        $custom = trim((string) ($share['custom_purpose'] ?? ''));
        if ((string) ($share['meeting_purpose'] ?? '') === 'custom' && $custom !== '') {
            return $custom;
        }

        return self::PURPOSE_LABELS[(string) ($share['meeting_purpose'] ?? 'consultation')] ?? 'Meeting';
    }

    private function indefiniteArticle(string $label): string
    {
        return preg_match('/^[aeiou]/i', trim($label)) ? 'an' : 'a';
    }

    private function bookingUrl(string $token): string
    {
        $path = function_exists('publicUrl')
            ? publicUrl('meeting_schedule.php?share=' . urlencode($token))
            : '/meeting_schedule.php?share=' . urlencode($token);

        return $this->absoluteUrl($path);
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $appUrl = trim((string) ($_ENV['APP_URL'] ?? ''));
        if ($appUrl !== '') {
            $scheme = parse_url($appUrl, PHP_URL_SCHEME);
            $host = parse_url($appUrl, PHP_URL_HOST);
            $port = parse_url($appUrl, PHP_URL_PORT);
            if ($scheme && $host) {
                return $scheme . '://' . $host . ($port ? ':' . $port : '') . $path;
            }
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $host . $path;
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $share
     * @return array<string,mixed>
     */
    private function hydrateShare(array $share): array
    {
        $decodedSlots = [];
        if (!empty($share['suggested_slots_json'])) {
            $decoded = json_decode((string) $share['suggested_slots_json'], true);
            $decodedSlots = is_array($decoded) ? $decoded : [];
        }
        $decodedMetadata = [];
        if (!empty($share['metadata_json'])) {
            $decoded = json_decode((string) $share['metadata_json'], true);
            $decodedMetadata = is_array($decoded) ? $decoded : [];
        }
        $share['suggested_slots'] = $decodedSlots;
        $share['metadata_json_decoded'] = $decodedMetadata;
        if (empty($share['booking_url']) && !empty($share['token'])) {
            $share['booking_url'] = $this->bookingUrl((string) $share['token']);
        }

        return $share;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    private function mergeMetadata(array $metadata, array $patch): array
    {
        return array_merge($metadata, $patch);
    }

    private function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $escaped = htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8');
            $escaped = nl2br($escaped, false);
            if ($escaped !== '') {
                $html[] = '<p>' . $escaped . '</p>';
            }
        }

        return implode('', $html);
    }

    private function normalizePurpose(string $purpose): string
    {
        return array_key_exists($purpose, self::PURPOSE_LABELS) ? $purpose : 'consultation';
    }

    private function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return $default;
        }

        return max($min, min($max, (int) $int));
    }

    private function assertStorageAvailable(): void
    {
        if (!Database::tableExists('meeting_calendar_shares')) {
            throw new \RuntimeException('Calendar sharing tables are not available. Run migrations first.');
        }
    }
}
