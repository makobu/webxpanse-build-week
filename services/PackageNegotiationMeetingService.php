<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class PackageNegotiationMeetingService
{
    public const PROFILE_SLUG = 'package-negotiation';
    public const SIGNAL_TYPE = 'package_negotiation_meeting_requested';
    private const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        private ?DefaultWorkspaceService $defaultWorkspace = null,
        private ?MeetingAvailabilityService $availability = null,
        private ?MeetingBookingService $bookings = null,
    ) {
        $this->defaultWorkspace = $this->defaultWorkspace ?: new DefaultWorkspaceService();
        $this->availability = $this->availability ?: new MeetingAvailabilityService();
        $this->bookings = $this->bookings ?: new MeetingBookingService($this->availability);
    }

    /**
     * @return array<string,mixed>
     */
    public function setupState(): array
    {
        $profile = $this->ensureProfile();

        return [
            'default_workspace_id' => (int) ($profile['workspace_id'] ?? $this->defaultWorkspace->id()),
            'profile' => $profile,
            'auto_approval_enabled' => (string) ($profile['approval_mode'] ?? 'manual') === 'auto_confirm_internal',
            'timezone' => (string) ($profile['timezone'] ?? $this->defaultTimezone()),
            'duration_minutes' => (int) ($profile['default_duration_minutes'] ?? self::DEFAULT_DURATION_MINUTES),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function setAutoApproval(bool $enabled, int $actorUserId): array
    {
        $profile = $this->ensureProfile($actorUserId);
        Database::execute(
            "UPDATE meeting_booking_profiles
             SET approval_mode = ?,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $enabled ? 'auto_confirm_internal' : 'manual',
                $actorUserId > 0 ? $actorUserId : null,
                (int) $profile['id'],
                (int) $profile['workspace_id'],
            ]
        );

        return $this->setupState();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function availableSlots(string $timezone = '', int $days = 21, int $limit = 60): array
    {
        $profile = $this->ensureProfile();
        $timezone = $this->availability->normalizeTimezone($timezone !== '' ? $timezone : (string) ($profile['timezone'] ?? $this->defaultTimezone()));
        $duration = (int) ($profile['default_duration_minutes'] ?? self::DEFAULT_DURATION_MINUTES);
        $viewerTimezone = new \DateTimeZone($timezone);
        $from = (new \DateTimeImmutable('today', $viewerTimezone))->format('Y-m-d');
        $to = (new \DateTimeImmutable('today +' . max(1, $days) . ' days', $viewerTimezone))->format('Y-m-d');

        $slots = array_values(array_filter(
            $this->availability->getSlots((int) $profile['id'], $from, $to, $timezone, $duration),
            static fn(array $slot): bool => !empty($slot['available'])
        ));

        return array_slice($slots, 0, max(1, $limit));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function bookPackageMeeting(
        int $ownerWorkspaceId,
        int $requesterUserId,
        ?int $sessionId,
        string $packageCode,
        string $startsAt,
        string $timezone,
        array $context = [],
        ?GuidedDemoPackageIntentService $intentService = null
    ): array {
        $profile = $this->ensureProfile();
        $defaultWorkspaceId = (int) ($profile['workspace_id'] ?? $this->defaultWorkspace->id());
        $timezone = $this->availability->normalizeTimezone($timezone !== '' ? $timezone : (string) ($profile['timezone'] ?? $this->defaultTimezone()));
        $requester = $this->requesterDetails($requesterUserId, $ownerWorkspaceId);
        $duration = (int) ($profile['default_duration_minutes'] ?? self::DEFAULT_DURATION_MINUTES);
        $description = $this->bookingDescription($packageCode, $requester, $context);

        $bookingMetadata = array_merge([
            'source' => 'package_negotiation',
            'source_surface' => 'billing_choose_package',
            'owner_workspace_id' => $ownerWorkspaceId,
            'owner_workspace_name' => (string) ($requester['workspace_name'] ?? ''),
            'requester_user_id' => $requesterUserId,
            'package_code' => $packageCode,
        ], $context);

        $bookingResult = $this->bookings->requestBooking([
            'profile_id' => (int) $profile['id'],
            'starts_at' => $startsAt,
            'timezone' => $timezone,
            'duration_minutes' => $duration,
            'requester_name' => (string) $requester['name'],
            'requester_email' => (string) $requester['email'],
            'requester_organization' => (string) ($requester['workspace_name'] ?? ''),
            'requester_role' => (string) ($requester['workspace_role'] ?? ''),
            'meeting_format' => 'google_meet',
            'inquiry_type' => 'Negotiated package',
            'inquiry_description' => $description,
            'metadata' => $bookingMetadata,
            'form_started_at' => time() - 10,
        ]);

        $bookingId = (int) ($bookingResult['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new \RuntimeException('Could not create the package meeting booking.');
        }

        $approvalResult = null;
        $freshProfile = $this->availability->getProfile((int) $profile['id']) ?: $profile;
        if ((string) ($freshProfile['approval_mode'] ?? 'manual') === 'auto_confirm_internal') {
            $actorUserId = $this->supportActorUserId($defaultWorkspaceId);
            $approvalResult = $this->bookings->approveBooking(
                $bookingId,
                $actorUserId,
                'Auto-approved from negotiated package meeting settings.',
                $defaultWorkspaceId
            );
            $bookingResult = array_merge($bookingResult, [
                'status' => 'confirmed',
                'event_id' => (int) ($approvalResult['event_id'] ?? 0),
                'approval' => $approvalResult,
            ]);
        }

        $booking = $this->bookings->getBooking($bookingId, $defaultWorkspaceId) ?: [];
        $intentMetadata = array_merge($bookingMetadata, [
            'meeting_booking_id' => $bookingId,
            'meeting_booking_status' => (string) ($booking['status'] ?? $bookingResult['status'] ?? 'pending'),
            'meeting_event_id' => !empty($booking['event_id']) ? (int) $booking['event_id'] : null,
            'default_workspace_id' => $defaultWorkspaceId,
            'package_negotiation_profile_id' => (int) $profile['id'],
            'requested_meeting' => [
                'starts_at' => (string) ($booking['scheduled_start'] ?? $startsAt),
                'ends_at' => (string) ($booking['scheduled_end'] ?? ''),
                'timezone' => $timezone,
                'duration_minutes' => $duration,
                'auto_picked_details' => [
                    'workspace_id' => $ownerWorkspaceId,
                    'workspace_name' => (string) ($requester['workspace_name'] ?? ''),
                    'user_id' => $requesterUserId,
                    'user_name' => (string) $requester['name'],
                    'user_email' => (string) $requester['email'],
                ],
            ],
        ]);

        $intentService = $intentService ?: new GuidedDemoPackageIntentService();
        $intentId = $intentService->recordIntent(
            $ownerWorkspaceId,
            $requesterUserId,
            $sessionId,
            $packageCode,
            'requested',
            'meeting',
            $this->intentNote($booking, $timezone),
            $intentMetadata
        );

        $opsEventId = $this->createPositiveIntentSignal($booking, $requester, $packageCode, $intentId, $intentMetadata);
        $this->notifyRequester($ownerWorkspaceId, $requesterUserId, $bookingId, $packageCode, $booking);
        $this->notifySupportOperators($defaultWorkspaceId, $opsEventId, $packageCode, $booking, $requester);

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'booking' => $booking,
            'booking_result' => $bookingResult,
            'approval_result' => $approvalResult,
            'intent_id' => $intentId,
            'ops_event_id' => $opsEventId,
            'profile' => $freshProfile,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureProfile(?int $actorUserId = null): array
    {
        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $ownerUserId = $actorUserId && $this->isWorkspaceMember($defaultWorkspaceId, $actorUserId)
            ? $actorUserId
            : $this->supportActorUserId($defaultWorkspaceId);

        $profile = Database::queryOne(
            "SELECT *
             FROM meeting_booking_profiles
             WHERE workspace_id = ?
               AND slug = ?
             LIMIT 1",
            [$defaultWorkspaceId, self::PROFILE_SLUG]
        );

        if (!$profile) {
            Database::execute(
                "INSERT INTO meeting_booking_profiles (
                    workspace_id, owner_user_id, slug, title, description, public_enabled, timezone,
                    default_duration_minutes, allowed_durations_json, buffer_before_minutes, buffer_after_minutes,
                    min_notice_hours, max_advance_days, allowed_meeting_formats_json, approval_mode, status, created_by
                ) VALUES (?, ?, ?, 'Package Negotiation', 'Book time with support to discuss a negotiated workspace package.', 1, ?, ?, ?, 15, 15, 24, 60, ?, 'manual', 'active', ?)",
                [
                    $defaultWorkspaceId,
                    $ownerUserId > 0 ? $ownerUserId : null,
                    self::PROFILE_SLUG,
                    $this->defaultTimezone(),
                    self::DEFAULT_DURATION_MINUTES,
                    json_encode([self::DEFAULT_DURATION_MINUTES]),
                    json_encode(['google_meet', 'zoom', 'phone_call']),
                    $ownerUserId > 0 ? $ownerUserId : null,
                ]
            );
            $profileId = (int) Database::lastInsertId();
        } else {
            $profileId = (int) $profile['id'];
            Database::execute(
                "UPDATE meeting_booking_profiles
                 SET title = 'Package Negotiation',
                     description = 'Book time with support to discuss a negotiated workspace package.',
                     public_enabled = 1,
                     status = 'active',
                     owner_user_id = COALESCE(owner_user_id, ?),
                     default_duration_minutes = ?,
                     allowed_durations_json = ?,
                     allowed_meeting_formats_json = ?,
                     updated_by = ?
                 WHERE id = ?
                   AND workspace_id = ?",
                [
                    $ownerUserId > 0 ? $ownerUserId : null,
                    self::DEFAULT_DURATION_MINUTES,
                    json_encode([self::DEFAULT_DURATION_MINUTES]),
                    json_encode(['google_meet', 'zoom', 'phone_call']),
                    $actorUserId && $actorUserId > 0 ? $actorUserId : null,
                    $profileId,
                    $defaultWorkspaceId,
                ]
            );
        }

        $this->ensureWeekdayWindows($profileId);
        $this->ensureProfileHost($profileId, $ownerUserId);

        return $this->availability->getProfile($profileId) ?: [];
    }

    private function ensureWeekdayWindows(int $profileId): void
    {
        $count = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM meeting_availability_windows
             WHERE profile_id = ?",
            [$profileId]
        );
        if ((int) ($count['count'] ?? 0) > 0) {
            return;
        }

        for ($day = 1; $day <= 5; $day++) {
            Database::execute(
                "INSERT INTO meeting_availability_windows (profile_id, day_of_week, start_time, end_time, is_enabled)
                 VALUES (?, ?, '09:00:00', '17:00:00', 1)",
                [$profileId, $day]
            );
        }
    }

    private function ensureProfileHost(int $profileId, int $ownerUserId): void
    {
        if ($profileId <= 0 || $ownerUserId <= 0 || !Database::tableExists('meeting_booking_profile_hosts')) {
            return;
        }

        Database::execute(
            "INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order)
             VALUES (?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE is_enabled = 1, updated_at = NOW()",
            [$profileId, $ownerUserId]
        );
    }

    /**
     * @return array{name:string,email:string,workspace_name:string,workspace_role:string}
     */
    private function requesterDetails(int $requesterUserId, int $workspaceId): array
    {
        $user = Database::queryOne(
            "SELECT id, first_name, last_name, email
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$requesterUserId]
        );
        $workspace = Database::queryOne(
            "SELECT id, name, slug
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );
        $membership = Database::queryOne(
            "SELECT role_slug
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $requesterUserId]
        );

        $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) ($user['email'] ?? 'Workspace user');
        }
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('Your account needs an email address before booking support.');
        }

        return [
            'name' => $name,
            'email' => $email,
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'workspace_role' => (string) ($membership['role_slug'] ?? ''),
        ];
    }

    private function supportActorUserId(int $defaultWorkspaceId): int
    {
        $member = Database::queryOne(
            "SELECT u.id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles global_role ON global_role.id = ur.role_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND (
                    wm.is_owner = 1
                    OR wm.role_slug IN ('superadmin', 'owner', 'admin')
                    OR global_role.slug = 'superadmin'
               )
             ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), u.email ASC
             LIMIT 1",
            [$defaultWorkspaceId]
        );
        if (!empty($member['id'])) {
            return (int) $member['id'];
        }

        $fallback = Database::queryOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        if (!empty($fallback['id'])) {
            return (int) $fallback['id'];
        }

        throw new \RuntimeException('Default workspace needs a support operator before package meetings can be booked.');
    }

    private function isWorkspaceMember(int $workspaceId, int $userId): bool
    {
        return Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        ) !== null;
    }

    /**
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $requester
     * @param array<string,mixed> $metadata
     */
    private function createPositiveIntentSignal(array $booking, array $requester, string $packageCode, int $intentId, array $metadata): int
    {
        $defaultWorkspaceId = (int) ($booking['workspace_id'] ?? $this->defaultWorkspace->id());
        $bookingId = (int) ($booking['id'] ?? $booking['booking_id'] ?? $metadata['meeting_booking_id'] ?? 0);
        $ownerWorkspaceId = (int) ($metadata['owner_workspace_id'] ?? 0);
        $ownerUserId = (int) ($metadata['requester_user_id'] ?? 0);
        $contactId = $this->ensureDefaultWorkspaceContact($defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId, $requester);
        $fingerprint = 'package_negotiation_meeting:' . $bookingId;
        $activeSignalKey = substr(self::SIGNAL_TYPE . ':' . $fingerprint, 0, 191);
        $subject = 'Negotiated package meeting requested';
        $message = $this->supportMessage($packageCode, $booking, $requester);
        $eventMetadata = array_merge($metadata, [
            'guided_demo_package_intent_id' => $intentId,
            'ops_signal_source' => 'package_negotiation_meeting',
        ]);

        $existing = Database::queryOne(
            "SELECT id
             FROM default_workspace_ops_events
             WHERE default_workspace_id = ?
               AND active_signal_key = ?
             LIMIT 1",
            [$defaultWorkspaceId, $activeSignalKey]
        );

        if ($existing) {
            $eventId = (int) $existing['id'];
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET owner_workspace_id = ?,
                     owner_user_id = ?,
                     contact_id = ?,
                     severity = 'info',
                     priority = 'high',
                     last_seen_at = NOW(),
                     metadata_json = ?,
                     owner_visible = CASE WHEN ? THEN 1 ELSE owner_visible END,
                     owner_subject = CASE WHEN ? THEN ? ELSE owner_subject END,
                     owner_category = CASE WHEN ? THEN 'billing' ELSE owner_category END,
                     updated_at = NOW()
                 WHERE id = ?
                   AND default_workspace_id = ?",
                [
                    $ownerWorkspaceId > 0 ? $ownerWorkspaceId : null,
                    $ownerUserId > 0 ? $ownerUserId : null,
                    $contactId > 0 ? $contactId : null,
                    json_encode($eventMetadata, JSON_UNESCAPED_SLASHES),
                    Database::columnExists('default_workspace_ops_events', 'owner_visible') ? 1 : 0,
                    Database::columnExists('default_workspace_ops_events', 'owner_subject') ? 1 : 0,
                    $subject,
                    Database::columnExists('default_workspace_ops_events', 'owner_category') ? 1 : 0,
                    $eventId,
                    $defaultWorkspaceId,
                ]
            );
        } else {
            $columns = [
                'default_workspace_id', 'owner_workspace_id', 'owner_user_id', 'contact_id',
                'signal_type', 'signal_fingerprint', 'active_signal_key', 'severity', 'priority',
                'status', 'detected_at', 'last_seen_at', 'metadata_json',
            ];
            $values = ['?', '?', '?', '?', '?', '?', '?', "'info'", "'high'", "'open'", 'NOW()', 'NOW()', '?'];
            $params = [
                $defaultWorkspaceId,
                $ownerWorkspaceId > 0 ? $ownerWorkspaceId : null,
                $ownerUserId > 0 ? $ownerUserId : null,
                $contactId > 0 ? $contactId : null,
                self::SIGNAL_TYPE,
                $fingerprint,
                $activeSignalKey,
                json_encode($eventMetadata, JSON_UNESCAPED_SLASHES),
            ];
            if (Database::columnExists('default_workspace_ops_events', 'owner_visible')) {
                $columns[] = 'owner_visible';
                $values[] = '1';
            }
            if (Database::columnExists('default_workspace_ops_events', 'owner_subject')) {
                $columns[] = 'owner_subject';
                $values[] = '?';
                $params[] = $subject;
            }
            if (Database::columnExists('default_workspace_ops_events', 'owner_category')) {
                $columns[] = 'owner_category';
                $values[] = "'billing'";
            }

            Database::execute(
                "INSERT INTO default_workspace_ops_events (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $values) . ")",
                $params
            );
            $eventId = (int) Database::lastInsertId();
        }

        $this->ensureOpsThread($defaultWorkspaceId, $contactId, $eventId, $subject, $message, $eventMetadata);

        return $eventId;
    }

    /**
     * @param array<string,mixed> $requester
     */
    private function ensureDefaultWorkspaceContact(int $defaultWorkspaceId, int $ownerWorkspaceId, int $ownerUserId, array $requester): int
    {
        if ($ownerWorkspaceId > 0 && $ownerUserId > 0) {
            try {
                $sync = (new DefaultWorkspaceOwnerContactService($this->defaultWorkspace))->syncOwnerForWorkspace($ownerWorkspaceId, $ownerUserId, $ownerUserId);
                if (!empty($sync['contact_id'])) {
                    return (int) $sync['contact_id'];
                }
                $status = (new DefaultWorkspaceOwnerContactService($this->defaultWorkspace))->statusForOwner($ownerUserId);
                if (!empty($status['id'])) {
                    return (int) $status['id'];
                }
            } catch (\Throwable) {
            }
        }

        $email = strtolower(trim((string) ($requester['email'] ?? '')));
        if ($email === '') {
            return 0;
        }

        $existing = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND LOWER(TRIM(email)) = ?
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $email]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        [$firstName, $lastName] = $this->splitName((string) ($requester['name'] ?? 'Workspace User'));
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, company, lead_source, stage, lead_score, metadata_json, created_at, updated_at)
             VALUES (?, UUID(), ?, ?, ?, ?, 'other', 'qualified', 80, ?, NOW(), NOW())",
            [
                $defaultWorkspaceId,
                $firstName,
                $lastName,
                $email,
                (string) ($requester['workspace_name'] ?? ''),
                json_encode([
                    'source' => 'package_negotiation_meeting',
                    'owner_workspace_id' => $ownerWorkspaceId,
                    'owner_user_id' => $ownerUserId,
                ], JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function ensureOpsThread(int $defaultWorkspaceId, int $contactId, int $eventId, string $subject, string $message, array $metadata): void
    {
        if ($contactId <= 0
            || !Database::tableExists('conversation_threads')
            || !Database::tableExists('communications')
            || !Database::columnExists('default_workspace_ops_events', 'conversation_thread_id')) {
            return;
        }

        $threadKey = 'owner_support:event:' . $eventId;
        $thread = Database::queryOne(
            "SELECT id
             FROM conversation_threads
             WHERE workspace_id = ?
               AND thread_key = ?
             LIMIT 1",
            [$defaultWorkspaceId, $threadKey]
        );

        if (!$thread) {
            Database::execute(
                "INSERT INTO conversation_threads
                    (workspace_id, contact_id, channel, thread_key, last_message_at, last_channel, status, message_count, is_resolved, metadata_json)
                 VALUES (?, ?, 'web_chat', ?, NOW(), 'web_chat', 'open', 0, 0, ?)",
                [
                    $defaultWorkspaceId,
                    $contactId,
                    $threadKey,
                    json_encode(['source' => 'package_negotiation_meeting', 'ops_event_id' => $eventId], JSON_UNESCAPED_SLASHES),
                ]
            );
            $threadId = (int) Database::lastInsertId();
        } else {
            $threadId = (int) $thread['id'];
        }

        $existingMessage = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ?
               AND thread_key = ?
               AND metadata LIKE ?
             LIMIT 1",
            [$defaultWorkspaceId, $threadKey, '%"ops_event_id":' . $eventId . '%']
        );
        if (!$existingMessage) {
            Database::execute(
                "INSERT INTO communications
                    (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, created_at)
                 VALUES (?, UUID(), ?, ?, 'web_chat', 'inbound', ?, ?, ?, 'sent', NOW())",
                [
                    $defaultWorkspaceId,
                    $contactId,
                    $threadKey,
                    $subject,
                    $message,
                    json_encode(array_merge($metadata, ['ops_event_id' => $eventId]), JSON_UNESCAPED_SLASHES),
                ]
            );
            Database::execute(
                "UPDATE conversation_threads
                 SET message_count = message_count + 1,
                     last_message_at = NOW(),
                     last_channel = 'web_chat',
                     last_inbound_at = NOW(),
                     updated_at = NOW()
                 WHERE workspace_id = ?
                   AND id = ?",
                [$defaultWorkspaceId, $threadId]
            );
        }

        Database::execute(
            "UPDATE default_workspace_ops_events
             SET conversation_thread_id = ?
             WHERE default_workspace_id = ?
               AND id = ?",
            [$threadId, $defaultWorkspaceId, $eventId]
        );
    }

    /**
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $requester
     */
    private function notifySupportOperators(int $defaultWorkspaceId, int $eventId, string $packageCode, array $booking, array $requester): void
    {
        foreach ((new DefaultWorkspaceOwnerSupportService($this->defaultWorkspace))->defaultWorkspaceMembers() as $member) {
            $userId = (int) ($member['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $this->insertNotification(
                $defaultWorkspaceId,
                $userId,
                'package_negotiation_meeting',
                'Negotiated package meeting requested',
                $this->supportMessage($packageCode, $booking, $requester),
                'default_workspace_ops_event',
                $eventId,
                'owner_support_admin.php?event_id=' . $eventId,
                'high'
            );
        }
    }

    /**
     * @param array<string,mixed> $booking
     */
    private function notifyRequester(int $workspaceId, int $userId, int $bookingId, string $packageCode, array $booking): void
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return;
        }

        $status = (string) ($booking['status'] ?? 'pending');
        $title = $status === 'confirmed' ? 'Package meeting confirmed' : 'Package meeting requested';
        $message = $status === 'confirmed'
            ? 'Your negotiated package meeting is confirmed.'
            : 'Support received your negotiated package meeting request.';

        $this->insertNotification(
            $workspaceId,
            $userId,
            'package_negotiation_meeting',
            $title,
            $message,
            'package_meeting_request',
            $bookingId,
            'billing_choose_package.php?selected=' . rawurlencode($packageCode),
            'info'
        );
    }

    private function insertNotification(
        int $workspaceId,
        int $userId,
        string $type,
        string $title,
        string $message,
        string $entityType,
        int $entityId,
        string $link,
        string $severity
    ): void {
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())",
            [$workspaceId, $userId, $type, substr($title, 0, 255), $message, $entityType, $entityId, $link, $severity]
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function bookingDescription(string $packageCode, array $requester, array $context): string
    {
        $packageName = (string) (($context['selected_package']['display_name'] ?? '') ?: $packageCode);
        $workspaceName = (string) ($requester['workspace_name'] ?? 'the workspace');

        return 'Negotiated package discussion for ' . $packageName . ' in ' . $workspaceName . '.';
    }

    /**
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $requester
     */
    private function supportMessage(string $packageCode, array $booking, array $requester): string
    {
        $workspaceName = (string) (($requester['workspace_name'] ?? '') ?: 'Workspace');
        $requesterName = (string) (($requester['name'] ?? '') ?: 'A workspace user');
        $start = (string) ($booking['scheduled_start'] ?? '');

        return $requesterName . ' from ' . $workspaceName . ' requested time to discuss ' . $packageCode
            . ($start !== '' ? ' at ' . $start . '.' : '.');
    }

    /**
     * @param array<string,mixed> $booking
     */
    private function intentNote(array $booking, string $timezone): string
    {
        $start = (string) ($booking['scheduled_start'] ?? '');
        $status = (string) ($booking['status'] ?? 'pending');
        if ($start === '') {
            return 'Support meeting requested.';
        }

        return 'Support meeting ' . ($status === 'confirmed' ? 'confirmed' : 'requested') . ' for ' . $start . ' ' . $timezone . '.';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $first = (string) ($parts[0] ?? 'Workspace');
        $last = (string) ($parts[1] ?? 'User');

        return [$first !== '' ? $first : 'Workspace', $last !== '' ? $last : 'User'];
    }

    private function defaultTimezone(): string
    {
        return date_default_timezone_get() ?: 'UTC';
    }
}
