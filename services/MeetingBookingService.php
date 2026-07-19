<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Events;
use CRM\Security;

class MeetingBookingService
{
    public function __construct(
        private ?MeetingAvailabilityService $availability = null,
        private ?CalendarSyncService $sync = null,
        private ?MeetingBotService $bot = null,
    ) {
        $this->availability = $this->availability ?? new MeetingAvailabilityService();
        $this->sync = $this->sync ?? new CalendarSyncService();
        $this->bot = $this->bot ?? new MeetingBotService();
    }

    public function requestBooking(array $payload): array
    {
        $profile = $this->resolveProfile($payload);
        if (!$profile || empty($profile['public_enabled']) || (string) ($profile['status'] ?? '') !== 'active') {
            throw new \RuntimeException('This booking page is not available.');
        }
        $calendarShare = $this->calendarShareFromPayload($payload, (int) ($profile['id'] ?? 0));

        $this->assertHumanSubmission($payload);
        $workspaceId = (int) $profile['workspace_id'];
        $timezone = $this->availability->normalizeTimezone((string) ($payload['timezone'] ?? $profile['timezone'] ?? 'UTC'));
        $duration = $this->availability->normalizeDuration(
            !empty($calendarShare['id'])
                ? (int) ($calendarShare['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30)
                : (int) ($payload['duration_minutes'] ?? $profile['default_duration_minutes'] ?? 30),
            $profile
        );
        $startsAt = trim((string) ($payload['starts_at'] ?? $payload['scheduled_start'] ?? ''));
        if ($startsAt === '') {
            throw new \RuntimeException('Choose an available time.');
        }

        $requesterName = Security::sanitizeInput((string) ($payload['requester_name'] ?? $payload['name'] ?? ''), 'string');
        $requesterEmail = Security::sanitizeInput((string) ($payload['requester_email'] ?? $payload['email'] ?? ''), 'email');
        if ($requesterName === '' || $requesterEmail === null) {
            throw new \RuntimeException('Name and a valid email address are required.');
        }

        $meetingFormat = $this->normalizeMeetingFormat((string) ($payload['meeting_format'] ?? 'google_meet'), $profile);
        $inquiryType = Security::sanitizeInput((string) ($payload['inquiry_type'] ?? 'General meeting'), 'string') ?: 'General meeting';
        $description = Security::sanitizeInput((string) ($payload['inquiry_description'] ?? $payload['description'] ?? ''), 'string');
        $phone = Security::sanitizeInput((string) ($payload['requester_phone'] ?? $payload['phone'] ?? ''), 'string');
        $organization = Security::sanitizeInput((string) ($payload['requester_organization'] ?? $payload['organization'] ?? ''), 'string');
        $role = Security::sanitizeInput((string) ($payload['requester_role'] ?? $payload['role_title'] ?? ''), 'string');
        $ipHash = $this->hashIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $viewerTimezone = new \DateTimeZone($timezone);
        $profileTimezone = new \DateTimeZone((string) $profile['timezone']);
        $start = (new \DateTimeImmutable($startsAt, $viewerTimezone))->setTimezone($profileTimezone);
        $end = $start->modify('+' . $duration . ' minutes');
        $this->assertCalendarShareConstraints($calendarShare, $start, $duration);
        $dedupeHash = hash('sha256', implode('|', [
            $workspaceId,
            (int) $profile['id'],
            strtolower((string) $requesterEmail),
            $start->format('Y-m-d H:i:s'),
            $duration,
        ]));

        $existing = $this->duplicateBookingResponse($workspaceId, $dedupeHash);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertRateLimit($workspaceId, $requesterEmail, $ipHash);

        $slot = $this->availability->slotIsAvailable((int) $profile['id'], $startsAt, $timezone, $duration);
        if (empty($slot['available'])) {
            throw new \RuntimeException('That time is no longer available. Choose another slot.');
        }
        $start = (new \DateTimeImmutable((string) $slot['starts_at'], $viewerTimezone))->setTimezone($profileTimezone);
        $end = (new \DateTimeImmutable((string) $slot['ends_at'], $viewerTimezone))->setTimezone($profileTimezone);

        Database::beginTransaction();
        try {
            $profile = $this->lockProfileForBooking((int) $profile['id'], $workspaceId);
            if (empty($profile['public_enabled']) || (string) ($profile['status'] ?? '') !== 'active') {
                throw new \RuntimeException('This booking page is not available.');
            }
            $existing = $this->duplicateBookingResponse($workspaceId, $dedupeHash);
            if ($existing !== null) {
                Database::commit();
                return $existing;
            }

            if (!empty($calendarShare['id'])) {
                $calendarShare = $this->calendarShareFromPayload($payload, (int) $profile['id']);
            }

            $this->lockCandidateHostsForBooking($profile);

            $slot = $this->availability->slotIsAvailable((int) $profile['id'], $startsAt, $timezone, $duration);
            if (empty($slot['available'])) {
                throw new \RuntimeException('That time is no longer available. Choose another slot.');
            }
            $start = (new \DateTimeImmutable((string) $slot['starts_at'], $viewerTimezone))->setTimezone($profileTimezone);
            $end = (new \DateTimeImmutable((string) $slot['ends_at'], $viewerTimezone))->setTimezone($profileTimezone);
            $this->assertCalendarShareConstraints($calendarShare, $start, $duration);

            $assignment = $this->assignmentForSlot($profile, (string) $slot['starts_at'], $timezone, $duration);

            $publicToken = bin2hex(random_bytes(32));
            $bookingMetadata = $this->normalizeMetadata($payload['metadata'] ?? $payload['metadata_json'] ?? []);
            $bookingMetadata['profile_timezone'] = (string) $profile['timezone'];
            $bookingMetadata['source'] = (string) ($bookingMetadata['source'] ?? (!empty($calendarShare['id']) ? 'calendar_share' : 'public_booking'));
            if (!empty($calendarShare['id'])) {
                $bookingMetadata['calendar_share_id'] = (int) $calendarShare['id'];
                $bookingMetadata['calendar_share_token'] = (string) ($calendarShare['token'] ?? '');
            }

            $columns = ['workspace_id', 'profile_id'];
            $values = ['?', '?'];
            $params = [$workspaceId, (int) $profile['id']];
            if (!empty($calendarShare['id']) && Database::columnExists('meeting_booking_requests', 'calendar_share_id')) {
                $columns[] = 'calendar_share_id';
                $values[] = '?';
                $params[] = (int) $calendarShare['id'];
            }
            if (!empty($calendarShare['contact_id']) && Database::columnExists('meeting_booking_requests', 'contact_id')) {
                $columns[] = 'contact_id';
                $values[] = '?';
                $params[] = (int) $calendarShare['contact_id'];
            }
            if (!empty($calendarShare['deal_id']) && Database::columnExists('meeting_booking_requests', 'deal_id')) {
                $columns[] = 'deal_id';
                $values[] = '?';
                $params[] = (int) $calendarShare['deal_id'];
            }
            foreach ([
                'assigned_host_user_id' => $assignment['host_user_id'] > 0 ? $assignment['host_user_id'] : null,
                'assignment_strategy' => $assignment['strategy'],
                'assignment_metadata_json' => json_encode($assignment['metadata']),
                'requester_name' => $requesterName,
                'requester_email' => $requesterEmail,
                'requester_phone' => $phone ?: null,
                'requester_organization' => $organization ?: null,
                'requester_role' => $role ?: null,
                'meeting_format' => $meetingFormat,
                'inquiry_type' => $inquiryType,
                'inquiry_description' => $description ?: null,
                'timezone' => $timezone,
                'scheduled_start' => $start->format('Y-m-d H:i:s'),
                'scheduled_end' => $end->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration,
                'dedupe_hash' => $dedupeHash,
                'public_token' => $publicToken,
                'request_ip_hash' => $ipHash ?: null,
                'metadata_json' => json_encode($bookingMetadata),
            ] as $column => $value) {
                $columns[] = $column;
                $values[] = '?';
                $params[] = $value;
            }
            $columns[] = 'assigned_at';
            $values[] = 'NOW()';
            $columns[] = 'status';
            $values[] = "'pending'";

            Database::execute(
                "INSERT INTO meeting_booking_requests (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $values) . ")",
                $params
            );
            $bookingId = (int) Database::lastInsertId();
            if ((string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin' && (int) $assignment['host_user_id'] > 0) {
                Database::execute(
                    "UPDATE meeting_booking_profile_hosts
                     SET last_assigned_at = NOW(),
                         updated_at = NOW()
                     WHERE profile_id = ?
                       AND user_id = ?",
                    [(int) $profile['id'], (int) $assignment['host_user_id']]
                );
            }
            if (!empty($calendarShare['id'])) {
                (new CalendarShareService($this->availability))->markBooked((int) $calendarShare['id'], $bookingId);
            }
            Database::commit();
        } catch (\PDOException $e) {
            Database::rollBack();
            $existing = $this->duplicateBookingResponse($workspaceId, $dedupeHash);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return [
            'success' => true,
            'booking_id' => $bookingId,
            'status' => 'pending',
            'public_token' => $publicToken,
        ];
    }

    public function approveBooking(int $bookingId, int $actorUserId, ?string $note = null, ?int $workspaceId = null): array
    {
        $workspaceId = $this->requireWorkspaceId($workspaceId);
        Database::beginTransaction();
        try {
            $booking = $this->lockBookingForMutation($bookingId, $workspaceId);
            $currentStatus = (string) ($booking['status'] ?? '');
            if ($currentStatus === 'confirmed') {
                Database::commit();
                return [
                    'success' => true,
                    'booking_id' => $bookingId,
                    'event_id' => (int) ($booking['event_id'] ?? 0),
                    'status' => 'confirmed',
                    'duplicate' => true,
                    'sync' => ['success' => true, 'reason' => 'already_confirmed'],
                    'bot' => ['success' => true, 'reason' => 'already_confirmed'],
                ];
            }
            if ($currentStatus !== 'pending') {
                throw new \RuntimeException('Only pending bookings can be approved.');
            }

            $eventId = !empty($booking['event_id']) ? (int) $booking['event_id'] : 0;
            if ($eventId <= 0) {
                $eventId = (int) AsyncWorkspaceRunner::runWithWorkspace($workspaceId, function () use ($booking, $actorUserId): int {
                    $events = new Events();
                    $eventId = $events->create([
                        'title' => $this->eventTitle($booking),
                        'description' => $this->eventDescription($booking),
                        'event_type' => 'meeting',
                        'contact_id' => !empty($booking['contact_id']) ? (int) $booking['contact_id'] : null,
                        'assigned_to' => $this->eventAssignee($booking, $actorUserId),
                        'created_by' => $actorUserId,
                        'start_time' => (string) $booking['scheduled_start'],
                        'end_time' => (string) $booking['scheduled_end'],
                        'location' => $this->eventLocation($booking),
                        'status' => 'scheduled',
                    ]);
                    $this->markEventBookingContext($eventId, $booking);

                    return $eventId;
                }, $actorUserId);
            }

            $updated = Database::execute(
                "UPDATE meeting_booking_requests
                 SET status = 'confirmed',
                     event_id = ?,
                     approval_note = ?,
                     approved_by = ?,
                     approved_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?
                   AND status = 'pending'",
                [$eventId ?: null, $note, $actorUserId, $bookingId, $workspaceId]
            );
            if ($updated <= 0) {
                throw new \RuntimeException('Booking request is no longer pending.');
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $syncResult = $eventId > 0 ? $this->sync->syncOutboundForEvent($workspaceId, $eventId) : ['success' => false, 'reason' => 'event_missing'];
        $booking = $this->getBooking($bookingId, $workspaceId) ?: $booking;
        $botResult = $this->scheduleBotIfEligible($booking, $eventId, $actorUserId);

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'event_id' => $eventId,
            'status' => 'confirmed',
            'sync' => $syncResult,
            'bot' => $botResult,
        ];
    }

    public function updateStatus(int $bookingId, string $status, int $actorUserId, ?string $note = null, ?int $workspaceId = null): array
    {
        $workspaceId = $this->requireWorkspaceId($workspaceId);
        $allowed = ['declined', 'cancelled', 'completed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported booking status.');
        }
        Database::beginTransaction();
        try {
            $booking = $this->lockBookingForMutation($bookingId, $workspaceId);
            $currentStatus = (string) ($booking['status'] ?? '');
            if ($currentStatus === $status) {
                Database::commit();
                return ['success' => true, 'booking_id' => $bookingId, 'status' => $status, 'duplicate' => true];
            }

            $transitions = [
                'pending' => ['declined', 'cancelled'],
                'confirmed' => ['cancelled', 'completed'],
            ];
            if (!in_array($status, $transitions[$currentStatus] ?? [], true)) {
                throw new \RuntimeException(sprintf('A %s booking cannot be changed to %s.', $currentStatus ?: 'missing', $status));
            }

            $fieldPrefix = $status === 'declined' ? 'declined' : ($status === 'cancelled' ? 'cancelled' : 'completed');
            $updates = ["status = ?", "approval_note = COALESCE(?, approval_note)"];
            $params = [$status, $note];
            if ($status === 'completed') {
                $updates[] = "completed_at = NOW()";
            } else {
                $updates[] = "{$fieldPrefix}_by = ?";
                $updates[] = "{$fieldPrefix}_at = NOW()";
                $params[] = $actorUserId;
            }
            $params[] = $bookingId;
            $params[] = $workspaceId;
            $params[] = $currentStatus;

            $updated = Database::execute(
                "UPDATE meeting_booking_requests SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ? AND status = ?",
                $params
            );
            if ($updated <= 0) {
                throw new \RuntimeException('Booking status changed while the action was being applied.');
            }

            $eventId = (int) ($booking['event_id'] ?? 0);
            if ($eventId > 0 && in_array($status, ['cancelled', 'completed'], true)) {
                Database::execute(
                    "UPDATE events
                     SET status = ?
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$status, $eventId, $workspaceId]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'status' => $status,
            'event_id' => (int) ($booking['event_id'] ?? 0),
        ];
    }

    public function reassignHost(int $bookingId, int $hostUserId, int $actorUserId, ?string $note = null, ?int $workspaceId = null): array
    {
        $workspaceId = $this->requireWorkspaceId($workspaceId);
        Database::beginTransaction();
        try {
            $booking = $this->lockBookingForMutation($bookingId, $workspaceId);
            if ((string) ($booking['booking_mode'] ?? 'single_host') !== 'round_robin') {
                throw new \RuntimeException('Only round-robin bookings can be reassigned.');
            }
            if ((string) ($booking['status'] ?? '') !== 'pending') {
                throw new \RuntimeException('Only pending bookings can be reassigned before approval.');
            }

            $profile = $this->lockProfileForBooking((int) $booking['profile_id'], $workspaceId);
            if ((string) ($profile['booking_mode'] ?? 'single_host') !== 'round_robin') {
                throw new \RuntimeException('Only round-robin bookings can be reassigned.');
            }
            if ($hostUserId <= 0 || !$this->roundRobinHostIsEnabled((int) $booking['profile_id'], $hostUserId)) {
                throw new \RuntimeException('Choose an enabled round-robin host.');
            }
            $this->lockHostUsers([$hostUserId]);

            $timezone = (string) ($booking['profile_timezone'] ?? $booking['timezone'] ?? 'UTC');
            $isAvailable = $this->availability->hostIsAvailableForSlot(
                (int) $booking['profile_id'],
                $hostUserId,
                (string) $booking['scheduled_start'],
                $timezone,
                (int) $booking['duration_minutes'],
                $bookingId
            );
            if (!$isAvailable) {
                throw new \RuntimeException('That host is no longer available for the selected slot.');
            }

            $updated = Database::execute(
                "UPDATE meeting_booking_requests
                 SET assigned_host_user_id = ?,
                     assignment_strategy = 'manual_reassign',
                     assigned_at = NOW(),
                     assignment_metadata_json = ?,
                     approval_note = COALESCE(?, approval_note),
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?
                   AND status = 'pending'",
                [
                    $hostUserId,
                    json_encode([
                        'strategy' => 'manual_reassign',
                        'reassigned_by' => $actorUserId,
                        'previous_host_user_id' => (int) ($booking['assigned_host_user_id'] ?? 0),
                    ]),
                    $note,
                    $bookingId,
                    $workspaceId,
                ]
            );
            if ($updated <= 0) {
                throw new \RuntimeException('Booking request is no longer pending.');
            }
            Database::execute(
                "UPDATE meeting_booking_profile_hosts
                 SET last_assigned_at = NOW(),
                     updated_at = NOW()
                 WHERE profile_id = ?
                   AND user_id = ?",
                [(int) $booking['profile_id'], $hostUserId]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'assigned_host_user_id' => $hostUserId,
            'booking' => $this->getBooking($bookingId, $workspaceId),
        ];
    }

    public function getBooking(int $bookingId, ?int $workspaceId = null): ?array
    {
        $where = ['b.id = ?'];
        $params = [$bookingId];
        if ($workspaceId !== null) {
            $where[] = 'b.workspace_id = ?';
            $params[] = $workspaceId;
        }

        return Database::queryOne(
            "SELECT b.*, p.owner_user_id, p.booking_mode, p.title AS profile_title, p.timezone AS profile_timezone, p.slug AS profile_slug,
                    host.email AS assigned_host_email,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name, d.title AS deal_title
             FROM meeting_booking_requests b
             JOIN meeting_booking_profiles p ON p.id = b.profile_id AND p.workspace_id = b.workspace_id
             LEFT JOIN users host ON host.id = b.assigned_host_user_id
             LEFT JOIN contacts c ON c.id = b.contact_id AND c.workspace_id = b.workspace_id
             LEFT JOIN deals d ON d.id = b.deal_id AND d.workspace_id = b.workspace_id
             WHERE " . implode(' AND ', $where) . "
             LIMIT 1",
            $params
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBookings(int $workspaceId, array $filters = [], int $limit = 100): array
    {
        $where = ['b.workspace_id = ?'];
        $params = [$workspaceId];
        if (!empty($filters['status'])) {
            $where[] = 'b.status = ?';
            $params[] = (string) $filters['status'];
        }
        if ($this->isDateFilter((string) ($filters['start_date'] ?? ''))) {
            $where[] = 'b.scheduled_start >= ?';
            $params[] = (string) $filters['start_date'] . ' 00:00:00';
        }
        if ($this->isDateFilter((string) ($filters['end_date'] ?? ''))) {
            $where[] = 'b.scheduled_start <= ?';
            $params[] = (string) $filters['end_date'] . ' 23:59:59';
        }
        if (!empty($filters['assigned_host_user_id'])) {
            $where[] = 'b.assigned_host_user_id = ?';
            $params[] = (int) $filters['assigned_host_user_id'];
        }
        $limit = max(1, min(250, $limit));
        $offset = max(0, min(100000, (int) ($filters['offset'] ?? 0)));

        return Database::query(
            "SELECT b.*, p.owner_user_id, p.booking_mode, p.title AS profile_title, p.slug AS profile_slug,
                    p.timezone AS profile_timezone,
                    host.email AS assigned_host_email, e.title AS event_title,
                    bot.id AS meeting_bot_run_id, bot.status AS meeting_bot_status,
                    note.id AS meeting_note_taker_run_id, note.apply_status AS meeting_note_apply_status,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name, d.title AS deal_title
             FROM meeting_booking_requests b
             JOIN meeting_booking_profiles p ON p.id = b.profile_id AND p.workspace_id = b.workspace_id
             LEFT JOIN users host ON host.id = b.assigned_host_user_id
             LEFT JOIN events e ON e.id = b.event_id AND e.workspace_id = b.workspace_id
             LEFT JOIN meeting_bot_runs bot ON bot.id = (
                 SELECT MAX(bot_latest.id)
                 FROM meeting_bot_runs bot_latest
                 WHERE bot_latest.event_id = b.event_id
                   AND bot_latest.workspace_id = b.workspace_id
             )
             LEFT JOIN meeting_note_taker_runs note ON note.id = (
                 SELECT MAX(note_latest.id)
                 FROM meeting_note_taker_runs note_latest
                 WHERE note_latest.meeting_bot_run_id = bot.id
                   AND note_latest.workspace_id = b.workspace_id
             )
             LEFT JOIN contacts c ON c.id = b.contact_id AND c.workspace_id = b.workspace_id
             LEFT JOIN deals d ON d.id = b.deal_id AND d.workspace_id = b.workspace_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY FIELD(b.status, 'pending', 'confirmed', 'completed', 'declined', 'cancelled'), b.scheduled_start ASC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /**
     * @return array{success:bool,booking_id:int,status:string,public_token:string,duplicate:bool}|null
     */
    private function duplicateBookingResponse(int $workspaceId, string $dedupeHash): ?array
    {
        $existing = Database::queryOne(
            "SELECT id, status, public_token
             FROM meeting_booking_requests
             WHERE workspace_id = ?
               AND dedupe_hash = ?
             LIMIT 1",
            [$workspaceId, $dedupeHash]
        );
        if (!$existing) {
            return null;
        }

        return [
            'success' => true,
            'booking_id' => (int) $existing['id'],
            'status' => (string) $existing['status'],
            'public_token' => (string) $existing['public_token'],
            'duplicate' => true,
        ];
    }

    private function requireWorkspaceId(?int $workspaceId): int
    {
        $workspaceId = (int) ($workspaceId ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace scope is required.');
        }

        return $workspaceId;
    }

    private function resolveProfile(array $payload): ?array
    {
        $shareToken = trim((string) ($payload['calendar_share_token'] ?? $payload['share_token'] ?? $payload['share'] ?? ''));
        if ($shareToken !== '') {
            $share = (new CalendarShareService($this->availability))->getPublicShareByToken($shareToken, false);
            if (!$share) {
                throw new \RuntimeException('This calendar share is no longer available.');
            }
            return $this->availability->getProfile((int) ($share['profile_id'] ?? 0));
        }

        if (!empty($payload['profile_id'])) {
            return $this->availability->getProfile((int) $payload['profile_id']);
        }

        return $this->availability->findPublicProfile(
            (string) ($payload['workspace'] ?? $payload['workspace_slug'] ?? ''),
            (string) ($payload['profile'] ?? $payload['profile_slug'] ?? 'default')
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function calendarShareFromPayload(array $payload, int $profileId): ?array
    {
        $shareToken = trim((string) ($payload['calendar_share_token'] ?? $payload['share_token'] ?? $payload['share'] ?? ''));
        if ($shareToken === '') {
            return null;
        }

        $share = (new CalendarShareService($this->availability))->getPublicShareByToken($shareToken, false);
        if (!$share) {
            throw new \RuntimeException('This calendar share is no longer available.');
        }
        if ((int) ($share['profile_id'] ?? 0) !== $profileId) {
            throw new \RuntimeException('This calendar share is not valid for the selected booking profile.');
        }

        return $share;
    }

    /**
     * @return array<string,mixed>
     */
    private function lockProfileForBooking(int $profileId, int $workspaceId): array
    {
        $locked = Database::queryOne(
            "SELECT *
             FROM meeting_booking_profiles
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1
             FOR UPDATE",
            [$profileId, $workspaceId]
        );
        if (!$locked) {
            throw new \RuntimeException('Booking profile not found.');
        }

        return $this->availability->getProfile($profileId) ?: $locked;
    }

    /**
     * @return array<string,mixed>
     */
    private function lockBookingForMutation(int $bookingId, int $workspaceId): array
    {
        $locked = Database::queryOne(
            "SELECT id
             FROM meeting_booking_requests
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1
             FOR UPDATE",
            [$bookingId, $workspaceId]
        );
        if (!$locked) {
            throw new \RuntimeException('Booking request not found.');
        }

        return $this->getBooking($bookingId, $workspaceId) ?: throw new \RuntimeException('Booking request not found.');
    }

    /**
     * Lock every possible host before rechecking availability so two profiles cannot
     * concurrently assign the same person to overlapping requests.
     *
     * @param array<string,mixed> $profile
     */
    private function lockCandidateHostsForBooking(array $profile): void
    {
        $workspaceId = (int) ($profile['workspace_id'] ?? 0);
        $hostIds = [];
        if ((string) ($profile['booking_mode'] ?? 'single_host') === 'round_robin') {
            $rows = Database::query(
                "SELECT ph.user_id
                 FROM meeting_booking_profile_hosts ph
                 JOIN workspace_memberships wm ON wm.user_id = ph.user_id
                    AND wm.workspace_id = ?
                    AND wm.membership_status = 'active'
                 WHERE ph.profile_id = ?
                   AND ph.is_enabled = 1
                 ORDER BY ph.user_id ASC
                 FOR UPDATE",
                [$workspaceId, (int) ($profile['id'] ?? 0)]
            );
            $hostIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $rows);
        } else {
            $hostIds = [(int) ($profile['owner_user_id'] ?? 0)];
        }

        $hostIds = array_values(array_unique(array_filter($hostIds, static fn(int $id): bool => $id > 0)));
        if ($hostIds === []) {
            throw new \RuntimeException('This booking profile has no active host.');
        }
        $this->lockHostUsers($hostIds);
    }

    /**
     * @param list<int> $hostIds
     */
    private function lockHostUsers(array $hostIds): void
    {
        $hostIds = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn(int $id): bool => $id > 0)));
        sort($hostIds);
        if ($hostIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($hostIds), '?'));
        Database::query(
            "SELECT id
             FROM users
             WHERE id IN ($placeholders)
             ORDER BY id ASC
             FOR UPDATE",
            $hostIds
        );
    }

    /**
     * @param array<string,mixed>|null $calendarShare
     */
    private function assertCalendarShareConstraints(?array $calendarShare, \DateTimeImmutable $profileStart, int $duration): void
    {
        if (empty($calendarShare['id'])) {
            return;
        }

        $shareDuration = (int) ($calendarShare['duration_minutes'] ?? 0);
        if ($shareDuration > 0 && $duration !== $shareDuration) {
            throw new \RuntimeException('This invitation is limited to a ' . $shareDuration . '-minute meeting.');
        }

        $date = $profileStart->format('Y-m-d');
        $dateFrom = trim((string) ($calendarShare['date_from'] ?? ''));
        $dateTo = trim((string) ($calendarShare['date_to'] ?? ''));
        if (($dateFrom !== '' && $date < $dateFrom) || ($dateTo !== '' && $date > $dateTo)) {
            throw new \RuntimeException('Choose a time inside the date window set for this invitation.');
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{host_user_id:int,strategy:string,metadata:array<string,mixed>}
     */
    private function assignmentForSlot(array $profile, string $startsAt, string $timezone, int $duration): array
    {
        if ((string) ($profile['booking_mode'] ?? 'single_host') !== 'round_robin') {
            $ownerUserId = (int) ($profile['owner_user_id'] ?? 0);
            return [
                'host_user_id' => $ownerUserId,
                'strategy' => 'single_host',
                'metadata' => [
                    'booking_mode' => 'single_host',
                    'host_source' => $ownerUserId > 0 ? 'profile_owner' : 'none',
                ],
            ];
        }

        return $this->selectRoundRobinHost($profile, $startsAt, $timezone, $duration);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{host_user_id:int,strategy:string,metadata:array<string,mixed>}
     */
    private function selectRoundRobinHost(array $profile, string $startsAt, string $timezone, int $duration): array
    {
        $workspaceId = (int) ($profile['workspace_id'] ?? 0);
        $profileId = (int) ($profile['id'] ?? 0);
        $candidates = $this->availability->availableRoundRobinHostsForSlot($profileId, $startsAt, $timezone, $duration);
        if ($candidates === []) {
            throw new \RuntimeException('That time is no longer available for the team. Choose another slot.');
        }

        $candidateIds = array_values(array_map(static fn(array $host): int => (int) ($host['user_id'] ?? $host['id'] ?? 0), $candidates));
        $loads = $this->assignmentLoadCounts($workspaceId, $profileId, $candidateIds);
        foreach ($candidates as &$candidate) {
            $hostId = (int) ($candidate['user_id'] ?? $candidate['id'] ?? 0);
            $candidate['assignment_load_30d'] = (int) ($loads[$hostId] ?? 0);
        }
        unset($candidate);

        usort($candidates, static function (array $a, array $b): int {
            $aHostId = (int) ($a['user_id'] ?? $a['id'] ?? 0);
            $bHostId = (int) ($b['user_id'] ?? $b['id'] ?? 0);
            $aAssigned = strtotime((string) ($a['last_assigned_at'] ?? '')) ?: 0;
            $bAssigned = strtotime((string) ($b['last_assigned_at'] ?? '')) ?: 0;

            return ((int) ($a['assignment_load_30d'] ?? 0) <=> (int) ($b['assignment_load_30d'] ?? 0))
                ?: ($aAssigned <=> $bAssigned)
                ?: ((int) ($a['sort_order'] ?? 999) <=> (int) ($b['sort_order'] ?? 999))
                ?: ($aHostId <=> $bHostId);
        });

        $selected = $candidates[0];
        $selectedHostId = (int) ($selected['user_id'] ?? $selected['id'] ?? 0);

        return [
            'host_user_id' => $selectedHostId,
            'strategy' => 'least_recent_load',
            'metadata' => [
                'booking_mode' => 'round_robin',
                'candidate_count' => count($candidates),
                'load_window_days' => 30,
                'selected_load_30d' => (int) ($selected['assignment_load_30d'] ?? 0),
                'selected_sort_order' => (int) ($selected['sort_order'] ?? 0),
            ],
        ];
    }

    /**
     * @param list<int> $hostIds
     * @return array<int,int>
     */
    private function assignmentLoadCounts(int $workspaceId, int $profileId, array $hostIds): array
    {
        $hostIds = array_values(array_unique(array_filter(array_map('intval', $hostIds), static fn(int $id): bool => $id > 0)));
        if ($hostIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($hostIds), '?'));
        $rows = Database::query(
            "SELECT assigned_host_user_id, COUNT(*) AS count
             FROM meeting_booking_requests
             WHERE workspace_id = ?
               AND profile_id = ?
               AND assigned_host_user_id IN ($placeholders)
               AND status IN ('pending', 'confirmed')
               AND COALESCE(assigned_at, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY assigned_host_user_id",
            array_merge([$workspaceId, $profileId], $hostIds)
        );

        $counts = array_fill_keys($hostIds, 0);
        foreach ($rows as $row) {
            $counts[(int) ($row['assigned_host_user_id'] ?? 0)] = (int) ($row['count'] ?? 0);
        }

        return $counts;
    }

    private function roundRobinHostIsEnabled(int $profileId, int $hostUserId): bool
    {
        if ($profileId <= 0 || $hostUserId <= 0 || !Database::tableExists('meeting_booking_profile_hosts')) {
            return false;
        }

        return (bool) Database::queryOne(
            "SELECT 1
             FROM meeting_booking_profile_hosts
             WHERE profile_id = ?
               AND user_id = ?
               AND is_enabled = 1
             LIMIT 1",
            [$profileId, $hostUserId]
        );
    }

    private function assertHumanSubmission(array $payload): void
    {
        if (trim((string) ($payload['booking_website'] ?? $payload['website'] ?? '')) !== '') {
            throw new \RuntimeException('Booking request rejected.');
        }
        $startedAt = (int) ($payload['form_started_at'] ?? 0);
        if ($startedAt > 0 && time() - $startedAt < 2) {
            throw new \RuntimeException('Please review the booking details before submitting.');
        }
    }

    private function assertRateLimit(int $workspaceId, string $email, string $ipHash): void
    {
        $recent = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM meeting_booking_requests
             WHERE workspace_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
               AND (requester_email = ? OR request_ip_hash = ?)",
            [$workspaceId, $email, $ipHash]
        );
        if ((int) ($recent['count'] ?? 0) >= 8) {
            throw new \RuntimeException('Too many booking requests. Try again later.');
        }
    }

    private function normalizeMeetingFormat(string $format, array $profile): string
    {
        $allowed = array_map('strval', (array) ($profile['allowed_meeting_formats'] ?? []));
        $format = in_array($format, ['phone_call', 'zoom', 'google_meet', 'in_person'], true) ? $format : 'google_meet';
        return in_array($format, $allowed, true) ? $format : (string) ($allowed[0] ?? 'google_meet');
    }

    private function hashIp(string $ip): string
    {
        $ip = trim($ip);
        return $ip === '' ? '' : hash('sha256', $ip . '|' . (string) ($_ENV['APP_KEY'] ?? 'crm'));
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function isDateFilter(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0));
    }

    private function eventTitle(array $booking): string
    {
        return 'Meeting with ' . (string) $booking['requester_name'] . ' - ' . (string) $booking['inquiry_type'];
    }

    private function eventAssignee(array $booking, int $fallbackUserId): int
    {
        $assignedHostId = (int) ($booking['assigned_host_user_id'] ?? 0);
        if ($assignedHostId > 0) {
            return $assignedHostId;
        }

        $ownerUserId = (int) ($booking['owner_user_id'] ?? 0);
        return $ownerUserId > 0 ? $ownerUserId : $fallbackUserId;
    }

    private function eventDescription(array $booking): string
    {
        $lines = [
            'Booking request #' . (int) $booking['id'],
            'Requester: ' . (string) $booking['requester_name'] . ' <' . (string) $booking['requester_email'] . '>',
            'Format: ' . ucwords(str_replace('_', ' ', (string) $booking['meeting_format'])),
        ];
        if (!empty($booking['requester_phone'])) {
            $lines[] = 'Phone: ' . (string) $booking['requester_phone'];
        }
        if (!empty($booking['requester_organization'])) {
            $lines[] = 'Organization: ' . (string) $booking['requester_organization'];
        }
        if (!empty($booking['inquiry_description'])) {
            $lines[] = '';
            $lines[] = (string) $booking['inquiry_description'];
        }

        return implode("\n", $lines);
    }

    private function eventLocation(array $booking): string
    {
        return match ((string) $booking['meeting_format']) {
            'zoom' => 'Zoom',
            'google_meet' => 'Google Meet',
            'phone_call' => 'Phone call',
            'in_person' => 'In person',
            default => 'Meeting',
        };
    }

    /**
     * @param array<string,mixed> $booking
     */
    private function markEventBookingContext(int $eventId, array $booking): void
    {
        $workspaceId = (int) ($booking['workspace_id'] ?? 0);
        if ($eventId <= 0 || $workspaceId <= 0) {
            return;
        }
        if (Database::columnExists('events', 'sync_origin')) {
            Database::execute(
                "UPDATE events
                 SET sync_origin = 'booking'
                 WHERE id = ?
                   AND workspace_id = ?",
                [$eventId, $workspaceId]
            );
        }
        if (Database::columnExists('events', 'custom_fields')) {
            $event = Database::queryOne(
                "SELECT custom_fields FROM events WHERE id = ? AND workspace_id = ? LIMIT 1",
                [$eventId, $workspaceId]
            ) ?: [];
            $customFields = [];
            if (!empty($event['custom_fields'])) {
                $decoded = json_decode((string) $event['custom_fields'], true);
                $customFields = is_array($decoded) ? $decoded : [];
            }
            $customFields = array_merge($customFields, [
                'meeting_booking_id' => (int) ($booking['id'] ?? 0),
                'meeting_format' => (string) ($booking['meeting_format'] ?? ''),
                'timezone' => (string) ($booking['profile_timezone'] ?? $booking['timezone'] ?? date_default_timezone_get()),
            ]);
            Database::execute(
                "UPDATE events SET custom_fields = ? WHERE id = ? AND workspace_id = ?",
                [json_encode($customFields), $eventId, $workspaceId]
            );
        }
    }

    private function scheduleBotIfEligible(array $booking, int $eventId, int $actorUserId): array
    {
        if ($eventId <= 0) {
            return ['success' => false, 'reason' => 'event_missing'];
        }

        $meetingFormat = (string) ($booking['meeting_format'] ?? '');
        if (!in_array($meetingFormat, ['google_meet', 'zoom'], true)) {
            return ['success' => false, 'reason' => 'meeting_format_not_supported'];
        }

        $event = Database::queryOne(
            "SELECT external_event_id, location
             FROM events
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1",
            [$eventId, (int) ($booking['workspace_id'] ?? 0)]
        ) ?: [];
        $joinUrl = filter_var((string) ($event['location'] ?? ''), FILTER_VALIDATE_URL)
            ? (string) $event['location']
            : '';
        $externalEventId = trim((string) ($event['external_event_id'] ?? ''));
        if ($joinUrl === '' && $externalEventId === '') {
            return ['success' => false, 'reason' => 'meeting_reference_missing'];
        }

        try {
            $this->bot->setWorkspaceId((int) $booking['workspace_id']);
            return $this->bot->registerMeeting([
                'event_id' => $eventId,
                'provider' => $meetingFormat === 'google_meet' ? 'google_meet' : 'zoom',
                'external_event_id' => $externalEventId,
                'join_url' => $joinUrl,
                'title' => $this->eventTitle($booking),
                'scheduled_for' => (string) $booking['scheduled_start'],
                'organizer_email' => (string) $booking['requester_email'],
                'join_request_source' => 'calendar',
                'participants' => [[
                    'name' => (string) $booking['requester_name'],
                    'email' => (string) $booking['requester_email'],
                ]],
            ], $actorUserId);
        } catch (\Throwable $e) {
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }
}
