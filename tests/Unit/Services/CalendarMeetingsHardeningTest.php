<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\MeetingAvailabilityService;
use CRM\Services\MeetingBookingService;
use CRM\Tests\DatabaseTestCase;

class CalendarMeetingsHardeningTest extends DatabaseTestCase
{
    private MeetingAvailabilityService $availability;
    private MeetingBookingService $bookings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new MeetingAvailabilityService();
        $this->bookings = new MeetingBookingService($this->availability);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.42';
    }

    public function testBookingMutationsRequireWorkspaceScope(): void
    {
        $actorId = $this->createUser('calendar-actor@example.test');
        $hostId = $this->createUser('calendar-foreign-host@example.test');
        $this->ensureWorkspace(8101, 'calendar-home', 'Calendar Home');
        $this->ensureWorkspace(8102, 'calendar-foreign', 'Calendar Foreign');
        $this->addMembership(8101, $actorId);
        $this->addMembership(8102, $hostId);

        $foreignProfileId = $this->createProfile(8102, $hostId, ['booking_mode' => 'round_robin']);
        $this->addAvailabilityWindow($foreignProfileId);
        $this->addRoundRobinHost($foreignProfileId, $hostId);
        $foreignBookingId = $this->createBooking(8102, $foreignProfileId, $hostId);

        foreach ([
            'approve' => fn() => $this->bookings->approveBooking($foreignBookingId, $actorId, null, 8101),
            'decline' => fn() => $this->bookings->updateStatus($foreignBookingId, 'declined', $actorId, null, 8101),
            'cancel' => fn() => $this->bookings->updateStatus($foreignBookingId, 'cancelled', $actorId, null, 8101),
            'complete' => fn() => $this->bookings->updateStatus($foreignBookingId, 'completed', $actorId, null, 8101),
            'reassign' => fn() => $this->bookings->reassignHost($foreignBookingId, $hostId, $actorId, null, 8101),
        ] as $action => $callback) {
            try {
                $callback();
                $this->fail($action . ' should not mutate a booking from another workspace.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not found', strtolower($e->getMessage()));
            }
        }

        $status = Database::queryOne(
            'SELECT status FROM meeting_booking_requests WHERE id = ? AND workspace_id = ?',
            [$foreignBookingId, 8102]
        );
        $this->assertSame('pending', (string) ($status['status'] ?? ''));
    }

    public function testSameWorkspaceStatusMutationStillSucceeds(): void
    {
        $actorId = $this->createUser('calendar-same-actor@example.test');
        $this->ensureWorkspace(8111, 'calendar-same', 'Calendar Same');
        $this->addMembership(8111, $actorId);
        $profileId = $this->createProfile(8111, $actorId);
        $this->addAvailabilityWindow($profileId);
        $bookingId = $this->createBooking(8111, $profileId, $actorId);

        $result = $this->bookings->updateStatus($bookingId, 'cancelled', $actorId, 'cancelled in test', 8111);

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $result['status']);
        $row = Database::queryOne('SELECT status, cancelled_by FROM meeting_booking_requests WHERE id = ?', [$bookingId]);
        $this->assertSame('cancelled', (string) ($row['status'] ?? ''));
        $this->assertSame($actorId, (int) ($row['cancelled_by'] ?? 0));
    }

    public function testDuplicatePublicBookingReturnsExistingRequestEvenWhenSlotIsNowBusy(): void
    {
        $hostId = $this->createUser('calendar-duplicate-host@example.test');
        $this->ensureWorkspace(8121, 'calendar-duplicate', 'Calendar Duplicate');
        $this->addMembership(8121, $hostId);
        $profileId = $this->createProfile(8121, $hostId, [
            'slug' => 'duplicate',
            'timezone' => 'UTC',
            'public_enabled' => 1,
            'min_notice_hours' => 0,
        ]);
        $this->addAvailabilityWindow($profileId);

        $payload = [
            'workspace' => 'calendar-duplicate',
            'profile' => 'duplicate',
            'starts_at' => $this->nextMonday() . ' 09:00:00',
            'timezone' => 'UTC',
            'duration_minutes' => 30,
            'requester_name' => 'Duplicate Client',
            'requester_email' => 'duplicate-client@example.test',
            'meeting_format' => 'google_meet',
            'inquiry_type' => 'Intro',
            'form_started_at' => time() - 10,
        ];

        $first = $this->bookings->requestBooking($payload);
        $second = $this->bookings->requestBooking($payload);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertTrue((bool) ($second['duplicate'] ?? false));
        $this->assertSame((int) $first['booking_id'], (int) $second['booking_id']);
    }

    public function testPublicRoundRobinProfileRequiresEnabledHost(): void
    {
        $actorId = $this->createUser('calendar-nohost-actor@example.test');
        $this->ensureWorkspace(8131, 'calendar-nohost', 'Calendar No Host');
        $profileId = $this->createProfile(8131, null, [
            'public_enabled' => 0,
            'booking_mode' => 'single_host',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Select at least one round-robin host');

        $this->availability->saveBookingProfile(8131, $actorId, [
            'profile_id' => $profileId,
            'title' => 'No Host Team',
            'slug' => 'no-host-team',
            'timezone' => 'UTC',
            'booking_mode' => 'round_robin',
            'public_enabled' => 1,
            'allowed_durations' => [30],
            'allowed_meeting_formats' => ['google_meet'],
        ]);
    }

    public function testPublicRoundRobinProfileCannotRemoveAllHosts(): void
    {
        $actorId = $this->createUser('calendar-remove-actor@example.test');
        $hostId = $this->createUser('calendar-remove-host@example.test');
        $this->ensureWorkspace(8141, 'calendar-remove-hosts', 'Calendar Remove Hosts');
        $this->addMembership(8141, $actorId);
        $this->addMembership(8141, $hostId);
        $profileId = $this->createProfile(8141, $actorId, [
            'booking_mode' => 'round_robin',
            'public_enabled' => 1,
        ]);
        $this->addRoundRobinHost($profileId, $hostId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Select at least one round-robin host');

        $this->availability->saveRoundRobinHosts(8141, $actorId, $profileId, ['host_user_ids' => []]);
    }

    public function testRoundRobinSelectedIntegrationsUsesEachEnabledHostCalendar(): void
    {
        $ownerId = $this->createUser('calendar-selected-owner@example.test');
        $hostId = $this->createUser('calendar-selected-host@example.test');
        $this->ensureWorkspace(8151, 'calendar-selected', 'Calendar Selected');
        $this->addMembership(8151, $ownerId);
        $this->addMembership(8151, $hostId);
        $ownerIntegrationId = $this->createCalendarIntegration(8151, $ownerId, 'owner@example.test');
        $hostIntegrationId = $this->createCalendarIntegration(8151, $hostId, 'host@example.test');
        $profileId = $this->createProfile(8151, $ownerId, [
            'booking_mode' => 'round_robin',
            'availability_source_mode' => 'selected_integrations',
            'availability_integration_ids_json' => json_encode([$ownerIntegrationId]),
            'timezone' => 'UTC',
            'min_notice_hours' => 0,
        ]);
        $this->addAvailabilityWindow($profileId);
        $this->addRoundRobinHost($profileId, $ownerId, 1);
        $this->addRoundRobinHost($profileId, $hostId, 2);
        $this->addBusyCache(8151, $hostIntegrationId, $this->nextMonday() . ' 09:00:00', $this->nextMonday() . ' 09:30:00');

        $slots = $this->availability->getSlots($profileId, $this->nextMonday(), $this->nextMonday(), 'UTC', 30);
        $slot = $this->slotStartingAt($slots, $this->nextMonday() . ' 09:00:00');

        $this->assertNotNull($slot);
        $this->assertTrue((bool) ($slot['available'] ?? false));
        $this->assertSame(1, (int) ($slot['available_host_count'] ?? 0));
    }

    public function testUtcBusyCacheBlocksCorrectProfileTimezoneSlot(): void
    {
        $hostId = $this->createUser('calendar-utc-host@example.test');
        $this->ensureWorkspace(8161, 'calendar-utc', 'Calendar UTC');
        $this->addMembership(8161, $hostId);
        $integrationId = $this->createCalendarIntegration(8161, $hostId, 'utc-host@example.test');
        $profileId = $this->createProfile(8161, $hostId, [
            'timezone' => 'America/New_York',
            'min_notice_hours' => 0,
        ]);
        $this->addAvailabilityWindow($profileId);

        $localStart = new \DateTimeImmutable($this->nextMonday() . ' 09:00:00', new \DateTimeZone('America/New_York'));
        $utcStart = $localStart->setTimezone(new \DateTimeZone('UTC'));
        $this->addBusyCache(
            8161,
            $integrationId,
            $utcStart->format('Y-m-d H:i:s'),
            $utcStart->modify('+30 minutes')->format('Y-m-d H:i:s')
        );

        $slots = $this->availability->getSlots($profileId, $this->nextMonday(), $this->nextMonday(), 'America/New_York', 30);
        $slot = $this->slotStartingAt($slots, $this->nextMonday() . ' 09:00:00');

        $this->assertNotNull($slot);
        $this->assertFalse((bool) ($slot['available'] ?? true));
        $this->assertSame('external_busy', (string) ($slot['unavailable_reason'] ?? ''));
    }

    public function testManualRoundRobinReassignmentRevalidatesHostAvailability(): void
    {
        $actorId = $this->createUser('calendar-reassign-actor@example.test');
        $currentHostId = $this->createUser('calendar-reassign-current@example.test');
        $blockedHostId = $this->createUser('calendar-reassign-blocked@example.test');
        $this->ensureWorkspace(8171, 'calendar-reassign', 'Calendar Reassign');
        $this->addMembership(8171, $actorId);
        $this->addMembership(8171, $currentHostId);
        $this->addMembership(8171, $blockedHostId);
        $profileId = $this->createProfile(8171, $actorId, [
            'booking_mode' => 'round_robin',
            'timezone' => 'UTC',
            'min_notice_hours' => 0,
        ]);
        $this->addAvailabilityWindow($profileId);
        $this->addRoundRobinHost($profileId, $currentHostId, 1);
        $this->addRoundRobinHost($profileId, $blockedHostId, 2);
        $bookingId = $this->createBooking(8171, $profileId, $currentHostId);
        $this->addBlockedTime(8171, $profileId, $blockedHostId, $this->nextMonday() . ' 09:00:00', $this->nextMonday() . ' 09:30:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('host is no longer available');

        $this->bookings->reassignHost($bookingId, $blockedHostId, $actorId, null, 8171);
    }

    public function testHostBookingBlocksSameSlotAcrossDifferentProfiles(): void
    {
        $hostId = $this->createUser('calendar-cross-profile-host@example.test');
        $this->ensureWorkspace(8181, 'calendar-cross-profile', 'Calendar Cross Profile');
        $this->addMembership(8181, $hostId);
        $firstProfileId = $this->createProfile(8181, $hostId, ['slug' => 'first-profile']);
        $secondProfileId = $this->createProfile(8181, $hostId, ['slug' => 'second-profile']);
        $this->addAvailabilityWindow($firstProfileId);
        $this->addAvailabilityWindow($secondProfileId);
        $this->createBooking(8181, $firstProfileId, $hostId);

        $slots = $this->availability->getSlots($secondProfileId, $this->nextMonday(), $this->nextMonday(), 'UTC', 30);
        $slot = $this->slotStartingAt($slots, $this->nextMonday() . ' 09:00:00');

        $this->assertNotNull($slot);
        $this->assertFalse((bool) ($slot['available'] ?? true));
        $this->assertSame('booking', (string) ($slot['unavailable_reason'] ?? ''));
    }

    public function testBookingTransitionsAreEnforcedAndApprovalIsIdempotent(): void
    {
        $actorId = $this->createUser('calendar-transition-actor@example.test');
        $this->ensureWorkspace(8191, 'calendar-transitions', 'Calendar Transitions');
        $this->addMembership(8191, $actorId);
        $profileId = $this->createProfile(8191, $actorId, ['timezone' => 'Africa/Nairobi']);
        $this->addAvailabilityWindow($profileId);
        $bookingId = $this->createBooking(8191, $profileId, $actorId);

        $this->assertRuntimeFailure(
            fn() => $this->bookings->updateStatus($bookingId, 'completed', $actorId, null, 8191),
            'cannot be changed to completed'
        );

        $firstApproval = $this->bookings->approveBooking($bookingId, $actorId, 'Approved in test', 8191);
        $secondApproval = $this->bookings->approveBooking($bookingId, $actorId, 'Duplicate approval', 8191);

        $this->assertSame('confirmed', (string) ($firstApproval['status'] ?? ''));
        $this->assertTrue((bool) ($secondApproval['duplicate'] ?? false));
        $this->assertSame((int) ($firstApproval['event_id'] ?? 0), (int) ($secondApproval['event_id'] ?? 0));
        $eventCount = Database::queryOne(
            "SELECT COUNT(*) AS count FROM events WHERE workspace_id = ? AND id = ?",
            [8191, (int) ($firstApproval['event_id'] ?? 0)]
        );
        $this->assertSame(1, (int) ($eventCount['count'] ?? 0));
        $event = Database::queryOne(
            "SELECT status, custom_fields FROM events WHERE workspace_id = ? AND id = ?",
            [8191, (int) ($firstApproval['event_id'] ?? 0)]
        );
        $this->assertSame('scheduled', (string) ($event['status'] ?? ''));
        $customFields = json_decode((string) ($event['custom_fields'] ?? ''), true);
        $this->assertSame('Africa/Nairobi', (string) ($customFields['timezone'] ?? ''));

        $this->assertRuntimeFailure(
            fn() => $this->bookings->updateStatus($bookingId, 'declined', $actorId, null, 8191),
            'cannot be changed to declined'
        );
        $cancelled = $this->bookings->updateStatus($bookingId, 'cancelled', $actorId, 'Cancelled in test', 8191);
        $this->assertSame('cancelled', (string) ($cancelled['status'] ?? ''));
        $event = Database::queryOne(
            "SELECT status FROM events WHERE workspace_id = ? AND id = ?",
            [8191, (int) ($firstApproval['event_id'] ?? 0)]
        );
        $this->assertSame('cancelled', (string) ($event['status'] ?? ''));
    }

    public function testEmptyAvailabilityStaysDisabledAndPartialProfileSaveKeepsPublicState(): void
    {
        $actorId = $this->createUser('calendar-empty-availability@example.test');
        $this->ensureWorkspace(8201, 'calendar-empty-availability', 'Calendar Empty Availability');
        $this->addMembership(8201, $actorId);
        $profileId = $this->createProfile(8201, $actorId, ['public_enabled' => 1]);
        $this->addAvailabilityWindow($profileId);

        $updated = $this->availability->saveBookingProfile(8201, $actorId, [
            'profile_id' => $profileId,
            'title' => 'Still public',
        ]);
        $this->assertSame(1, (int) ($updated['public_enabled'] ?? 0));

        $windows = $this->availability->saveAvailabilityWindows($profileId, $actorId, []);
        $this->assertCount(7, $windows);
        $this->assertSame(0, array_sum(array_map(static fn(array $window): int => (int) ($window['is_enabled'] ?? 0), $windows)));
        $slots = $this->availability->getSlots($profileId, $this->nextMonday(), $this->nextMonday(), 'UTC', 30);
        $this->assertSame([], $slots);
    }

    public function testAvailabilityRejectsOverlappingWindowsAndOversizedRanges(): void
    {
        $actorId = $this->createUser('calendar-window-validation@example.test');
        $this->ensureWorkspace(8211, 'calendar-window-validation', 'Calendar Window Validation');
        $this->addMembership(8211, $actorId);
        $profileId = $this->createProfile(8211, $actorId);

        $this->assertRuntimeFailure(function () use ($profileId, $actorId): void {
            $this->availability->saveAvailabilityWindows($profileId, $actorId, [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'is_enabled' => 1],
                ['day_of_week' => 1, 'start_time' => '10:30', 'end_time' => '12:00', 'is_enabled' => 1],
            ]);
        }, 'cannot overlap');

        $this->assertRuntimeFailure(
            fn() => $this->availability->getSlots($profileId, '2026-01-01', '2026-12-31', 'UTC', 30),
            'at most 62 days'
        );
    }

    public function testBlockedTimeRejectsUserOutsideWorkspace(): void
    {
        $actorId = $this->createUser('calendar-block-actor@example.test');
        $foreignUserId = $this->createUser('calendar-block-foreign@example.test');
        $this->ensureWorkspace(8221, 'calendar-block-home', 'Calendar Block Home');
        $this->ensureWorkspace(8222, 'calendar-block-foreign', 'Calendar Block Foreign');
        $this->addMembership(8221, $actorId);
        $this->addMembership(8222, $foreignUserId);
        $profileId = $this->createProfile(8221, $actorId);

        $this->assertRuntimeFailure(function () use ($profileId, $actorId, $foreignUserId): void {
            $this->availability->createBlockedTime(8221, $actorId, [
                'profile_id' => $profileId,
                'user_id' => $foreignUserId,
                'block_type' => 'custom',
                'timezone' => 'UTC',
                'start_time' => $this->nextMonday() . ' 09:00:00',
                'end_time' => $this->nextMonday() . ' 09:30:00',
            ]);
        }, 'active workspace member');
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('calendar-hardening-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug)",
            [$id, sprintf('10000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function addMembership(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE membership_status = 'active', role_slug = 'owner', is_owner = 1",
            [$workspaceId, $userId, $userId]
        );
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createProfile(int $workspaceId, ?int $ownerUserId, array $overrides = []): int
    {
        $slug = (string) ($overrides['slug'] ?? ('profile-' . bin2hex(random_bytes(3))));
        Database::execute(
            "INSERT INTO meeting_booking_profiles (
                workspace_id, owner_user_id, booking_mode, availability_source_mode,
                availability_integration_ids_json, slug, title, description, public_enabled,
                timezone, default_duration_minutes, allowed_durations_json, buffer_before_minutes,
                buffer_after_minutes, min_notice_hours, max_advance_days,
                allowed_meeting_formats_json, approval_mode, status, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 30, ?, 0, 0, ?, 365, ?, 'manual', 'active', ?)",
            [
                $workspaceId,
                $ownerUserId,
                (string) ($overrides['booking_mode'] ?? 'single_host'),
                (string) ($overrides['availability_source_mode'] ?? 'owner_all'),
                $overrides['availability_integration_ids_json'] ?? json_encode([]),
                $slug,
                (string) ($overrides['title'] ?? 'Book a meeting'),
                (string) ($overrides['description'] ?? 'Testing booking profile'),
                (int) ($overrides['public_enabled'] ?? 1),
                (string) ($overrides['timezone'] ?? 'UTC'),
                json_encode([30]),
                (int) ($overrides['min_notice_hours'] ?? 0),
                json_encode(['google_meet']),
                $ownerUserId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function addAvailabilityWindow(int $profileId): void
    {
        Database::execute(
            "INSERT INTO meeting_availability_windows (profile_id, day_of_week, start_time, end_time, is_enabled)
             VALUES (?, 1, '09:00:00', '10:00:00', 1)",
            [$profileId]
        );
    }

    private function addRoundRobinHost(int $profileId, int $userId, int $sortOrder = 1): void
    {
        Database::execute(
            "INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE is_enabled = 1, sort_order = VALUES(sort_order)",
            [$profileId, $userId, $sortOrder]
        );
    }

    private function createBooking(int $workspaceId, int $profileId, int $hostUserId): int
    {
        Database::execute(
            "INSERT INTO meeting_booking_requests (
                workspace_id, profile_id, assigned_host_user_id, assignment_strategy, assigned_at,
                requester_name, requester_email, meeting_format, inquiry_type, timezone,
                scheduled_start, scheduled_end, duration_minutes, status, public_token, metadata_json
             ) VALUES (?, ?, ?, 'test', NOW(), 'Test Client', ?, 'google_meet', 'Intro', 'UTC', ?, ?, 30, 'pending', ?, ?)",
            [
                $workspaceId,
                $profileId,
                $hostUserId > 0 ? $hostUserId : null,
                'client-' . bin2hex(random_bytes(4)) . '@example.test',
                $this->nextMonday() . ' 09:00:00',
                $this->nextMonday() . ' 09:30:00',
                bin2hex(random_bytes(32)),
                json_encode(['source' => 'test']),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function createCalendarIntegration(int $workspaceId, int $userId, string $email): int
    {
        Database::execute(
            "INSERT INTO calendar_integrations (
                workspace_id, user_id, provider, calendar_id, calendar_name, provider_account_email,
                sync_enabled, availability_enabled, sync_direction, availability_last_checked_at
             ) VALUES (?, ?, 'google', ?, 'Primary calendar', ?, 0, 1, 'to_crm', NOW())",
            [$workspaceId, $userId, 'primary-' . $userId, $email]
        );

        return (int) Database::lastInsertId();
    }

    private function addBusyCache(int $workspaceId, int $integrationId, string $start, string $end): void
    {
        Database::execute(
            "INSERT INTO meeting_calendar_busy_cache (
                workspace_id, integration_id, source_label, busy_start, busy_end,
                fetched_at, expires_at, status
             ) VALUES (?, ?, 'Test calendar', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 'ok')",
            [$workspaceId, $integrationId, $start, $end]
        );
    }

    private function addBlockedTime(int $workspaceId, int $profileId, int $userId, string $start, string $end): void
    {
        Database::execute(
            "INSERT INTO meeting_blocked_times (workspace_id, profile_id, user_id, start_time, end_time, is_all_day, reason)
             VALUES (?, ?, ?, ?, ?, 0, 'Blocked in test')",
            [$workspaceId, $profileId, $userId, $start, $end]
        );
    }

    private function nextMonday(): string
    {
        return (new \DateTimeImmutable('next monday', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    /**
     * @param list<array<string,mixed>> $slots
     * @return array<string,mixed>|null
     */
    private function slotStartingAt(array $slots, string $startsAt): ?array
    {
        foreach ($slots as $slot) {
            if ((string) ($slot['starts_at'] ?? '') === $startsAt) {
                return $slot;
            }
        }

        return null;
    }

    private function assertRuntimeFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected the operation to fail.');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
