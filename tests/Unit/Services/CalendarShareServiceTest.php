<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\CalendarShareService;
use CRM\Services\MeetingBookingService;
use CRM\Tests\DatabaseTestCase;
use Throwable;

class CalendarShareServiceTest extends DatabaseTestCase
{
    public function testCreatesShareWithClientSpecificLinkAndSuggestedSlots(): void
    {
        $fixture = $this->seedFixture();
        $service = new CalendarShareService();
        [$from, $to] = $this->shareWindow();

        $share = $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'deal_id' => $fixture['deal_id'],
            'meeting_purpose' => 'demo',
            'duration_minutes' => 30,
            'suggested_slot_count' => 3,
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $this->assertSame((int) $fixture['contact_id'], (int) $share['contact_id']);
        $this->assertSame((int) $fixture['deal_id'], (int) $share['deal_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $share['token']);
        $this->assertStringContainsString('meeting_schedule.php?share=' . $share['token'], (string) $share['booking_url']);
        $this->assertNotEmpty($share['suggested_slots']);
        $this->assertLessThanOrEqual(3, count((array) $share['suggested_slots']));
    }

    public function testRejectsCrossWorkspaceProfileContactAndDeal(): void
    {
        $fixture = $this->seedFixture();
        $other = $this->seedFixture($this->createWorkspace((int) $fixture['user_id']));
        $service = new CalendarShareService();
        [$from, $to] = $this->shareWindow();

        $profileFailure = $this->captureThrowable(function () use ($service, $fixture, $other, $from, $to): void {
            $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
                'profile_id' => $other['profile_id'],
                'contact_id' => $fixture['contact_id'],
                'date_from' => $from,
                'date_to' => $to,
            ]);
        });
        $this->assertInstanceOf(Throwable::class, $profileFailure);
        $this->assertStringContainsString('Booking profile not found', (string) $profileFailure->getMessage());

        $contactFailure = $this->captureThrowable(function () use ($service, $fixture, $other, $from, $to): void {
            $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
                'profile_id' => $fixture['profile_id'],
                'contact_id' => $other['contact_id'],
                'date_from' => $from,
                'date_to' => $to,
            ]);
        });
        $this->assertInstanceOf(Throwable::class, $contactFailure);
        $this->assertStringContainsString('Choose a contact', (string) $contactFailure->getMessage());

        $dealFailure = $this->captureThrowable(function () use ($service, $fixture, $other, $from, $to): void {
            $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
                'profile_id' => $fixture['profile_id'],
                'contact_id' => $fixture['contact_id'],
                'deal_id' => $other['deal_id'],
                'date_from' => $from,
                'date_to' => $to,
            ]);
        });
        $this->assertInstanceOf(Throwable::class, $dealFailure);
        $this->assertStringContainsString('Deal not found', (string) $dealFailure->getMessage());
    }

    public function testFallbackDraftPreservesLinkSlotsAndPrivacySafeCopy(): void
    {
        $fixture = $this->seedFixture();
        $service = new CalendarShareService();
        [$from, $to] = $this->shareWindow();
        $share = $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'meeting_purpose' => 'follow_up',
            'duration_minutes' => 30,
            'suggested_slot_count' => 3,
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $result = $service->draftForShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], (int) $share['id'], false);
        $draft = (array) ($result['draft'] ?? []);
        $body = strtolower((string) ($draft['body_text'] ?? ''));

        $this->assertSame('calendar_share_fallback', (string) ($draft['source'] ?? ''));
        $this->assertStringContainsString((string) $share['booking_url'], (string) $draft['body_text']);
        $this->assertStringContainsString('minutes', $body);
        $this->assertStringContainsString('available options', $body);
        $this->assertStringNotContainsString('crm_event', $body);
        $this->assertStringNotContainsString('external_busy', $body);
        $this->assertStringNotContainsString('internal note', $body);
        $this->assertStringNotContainsString('other client', $body);
    }

    public function testPublicTokenResolutionAndExpiryRules(): void
    {
        $fixture = $this->seedFixture();
        $service = new CalendarShareService();
        [$from, $to] = $this->shareWindow();
        $share = $service->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $public = $service->getPublicShareByToken((string) $share['token'], true);
        $this->assertIsArray($public);
        $this->assertSame('calendar-share.test', (string) ($public['workspace_slug'] ?? ''));
        $this->assertSame('client@example.test', (string) ($public['contact_email'] ?? ''));

        $viewed = Database::queryOne("SELECT status, viewed_at FROM meeting_calendar_shares WHERE id = ?", [(int) $share['id']]);
        $this->assertSame('viewed', (string) ($viewed['status'] ?? ''));
        $this->assertNotEmpty($viewed['viewed_at'] ?? null);

        Database::execute("UPDATE meeting_calendar_shares SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?", [(int) $share['id']]);
        $this->assertNull($service->getPublicShareByToken((string) $share['token'], false));
    }

    public function testBookingThroughShareLinksRequestContactDealAndMarksShareBooked(): void
    {
        $fixture = $this->seedFixture();
        $shareService = new CalendarShareService();
        [$from, $to] = $this->shareWindow();
        $share = $shareService->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'deal_id' => $fixture['deal_id'],
            'date_from' => $from,
            'date_to' => $to,
        ]);
        $slot = (array) ($share['suggested_slots'][0] ?? []);
        $this->assertNotEmpty($slot['starts_at'] ?? null);

        $result = (new MeetingBookingService())->requestBooking([
            'calendar_share_token' => $share['token'],
            'starts_at' => $slot['starts_at'],
            'timezone' => $slot['timezone'] ?? 'UTC',
            'duration_minutes' => 30,
            'meeting_format' => 'google_meet',
            'inquiry_type' => 'Follow-up',
            'inquiry_description' => 'Client selected a shared calendar slot.',
            'requester_name' => 'Client Example',
            'requester_email' => 'client+' . bin2hex(random_bytes(2)) . '@example.test',
            'requester_phone' => '+15555550100',
            'requester_organization' => 'Example Co',
            'requester_role' => 'Buyer',
            'booking_website' => '',
            'form_started_at' => time() - 10,
        ]);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $booking = Database::queryOne("SELECT * FROM meeting_booking_requests WHERE id = ?", [(int) ($result['booking_id'] ?? 0)]);
        $this->assertSame((int) $share['id'], (int) ($booking['calendar_share_id'] ?? 0));
        $this->assertSame((int) $fixture['contact_id'], (int) ($booking['contact_id'] ?? 0));
        $this->assertSame((int) $fixture['deal_id'], (int) ($booking['deal_id'] ?? 0));
        $this->assertStringContainsString('"source":"calendar_share"', (string) ($booking['metadata_json'] ?? ''));

        $bookedShare = Database::queryOne("SELECT status, booked_at, metadata_json FROM meeting_calendar_shares WHERE id = ?", [(int) $share['id']]);
        $this->assertSame('booked', (string) ($bookedShare['status'] ?? ''));
        $this->assertNotEmpty($bookedShare['booked_at'] ?? null);
        $this->assertStringContainsString('"booking_id":' . (int) ($result['booking_id'] ?? 0), (string) ($bookedShare['metadata_json'] ?? ''));
    }

    public function testShareDurationAndDateWindowAreAuthoritativeForBooking(): void
    {
        $fixture = $this->seedFixture();
        Database::execute(
            "UPDATE meeting_booking_profiles
             SET allowed_durations_json = ?, default_duration_minutes = 30
             WHERE id = ?",
            [json_encode([30, 60]), (int) $fixture['profile_id']]
        );
        [$from] = $this->shareWindow();
        $shareService = new CalendarShareService();
        $share = $shareService->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'duration_minutes' => 60,
            'date_from' => $from,
            'date_to' => $from,
        ]);
        $slot = (array) ($share['suggested_slots'][0] ?? []);
        $this->assertNotEmpty($slot['starts_at'] ?? null);

        $result = (new MeetingBookingService())->requestBooking([
            'calendar_share_token' => $share['token'],
            'starts_at' => $slot['starts_at'],
            'timezone' => $slot['timezone'] ?? 'UTC',
            'duration_minutes' => 30,
            'meeting_format' => 'google_meet',
            'requester_name' => 'Duration Client',
            'requester_email' => 'duration-client@example.test',
            'form_started_at' => time() - 10,
        ]);
        $booking = Database::queryOne(
            "SELECT duration_minutes FROM meeting_booking_requests WHERE id = ?",
            [(int) ($result['booking_id'] ?? 0)]
        );
        $this->assertSame(60, (int) ($booking['duration_minutes'] ?? 0));

        $secondShare = $shareService->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'duration_minutes' => 30,
            'date_from' => $from,
            'date_to' => $from,
        ]);
        $outside = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->modify('+1 day');
        while ((int) $outside->format('N') > 5) {
            $outside = $outside->modify('+1 day');
        }
        $outsideSlots = (new \CRM\Services\MeetingAvailabilityService())->getSlots(
            (int) $fixture['profile_id'],
            $outside->format('Y-m-d'),
            $outside->format('Y-m-d'),
            'UTC',
            30
        );
        $outsideSlot = current(array_filter($outsideSlots, static fn(array $candidate): bool => !empty($candidate['available']))) ?: [];
        $this->assertNotEmpty($outsideSlot['starts_at'] ?? null);

        $failure = $this->captureThrowable(function () use ($secondShare, $outsideSlot): void {
            (new MeetingBookingService())->requestBooking([
                'calendar_share_token' => $secondShare['token'],
                'starts_at' => $outsideSlot['starts_at'],
                'timezone' => $outsideSlot['timezone'] ?? 'UTC',
                'duration_minutes' => 30,
                'meeting_format' => 'google_meet',
                'requester_name' => 'Outside Client',
                'requester_email' => 'outside-client@example.test',
                'form_started_at' => time() - 10,
            ]);
        });
        $this->assertInstanceOf(Throwable::class, $failure);
        $this->assertStringContainsString('date window', (string) $failure?->getMessage());
    }

    /**
     * @return array{workspace_id:int,user_id:int,profile_id:int,contact_id:int,deal_id:int}
     */
    private function seedFixture(?int $workspaceId = null): array
    {
        $userId = $this->createUser();
        $workspaceId = $workspaceId ?? 1;
        $this->ensureWorkspace($workspaceId, $userId);
        $this->ensureMembership($workspaceId, $userId);
        $contactId = $this->createContact($workspaceId, $userId);
        $dealId = $this->createDeal($workspaceId, $userId, $contactId);
        $profileId = $this->createProfile($workspaceId, $userId);

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'profile_id' => $profileId,
            'contact_id' => $contactId,
            'deal_id' => $dealId,
        ];
    }

    private function createUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (UUID(), ?, ?, 'admin', NOW())",
            ['calendar-share-' . bin2hex(random_bytes(4)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createWorkspace(int $userId): int
    {
        $slug = 'calendar-share-' . bin2hex(random_bytes(4));
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, created_by) VALUES (UUID(), ?, ?, 'active', ?)",
            ['Calendar Share ' . $slug, $slug, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $workspaceId, int $userId): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]);
        if ($existing) {
            if ((int) $workspaceId === 1) {
                Database::execute("UPDATE workspaces SET slug = 'calendar-share.test', name = 'Calendar Share Test' WHERE id = 1");
            }
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, created_by) VALUES (?, UUID(), 'Calendar Share Test', 'calendar-share.test', 'active', ?)",
            [$workspaceId, $userId]
        );
    }

    private function ensureMembership(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active'",
            [$workspaceId, $userId]
        );
    }

    private function createContact(int $workspaceId, int $userId): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, workspace_id, first_name, last_name, email, phone, company, assigned_to, created_by)
             VALUES (UUID(), ?, 'Client', 'Example', 'client@example.test', '+15555550100', 'Example Co', ?, ?)",
            [$workspaceId, $userId, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createDeal(int $workspaceId, int $userId, int $contactId): int
    {
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, probability)
             VALUES (?, 'Calendar Share Deal', ?, ?, ?, 'qualification', 1200, 50)",
            [$workspaceId, $contactId, $userId, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createProfile(int $workspaceId, int $userId): int
    {
        $slug = 'share-' . bin2hex(random_bytes(4));
        Database::execute(
            "INSERT INTO meeting_booking_profiles (
                workspace_id, owner_user_id, slug, title, description, public_enabled, timezone,
                default_duration_minutes, allowed_durations_json, buffer_before_minutes, buffer_after_minutes,
                min_notice_hours, max_advance_days, allowed_meeting_formats_json, approval_mode, status, created_by
             ) VALUES (?, ?, ?, 'Client booking', 'Choose a time with our team.', 1, 'UTC', 30, ?, 0, 0, 0, 45, ?, 'manual', 'active', ?)",
            [$workspaceId, $userId, $slug, json_encode([30]), json_encode(['google_meet', 'phone_call']), $userId]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function shareWindow(): array
    {
        $from = new \DateTimeImmutable('tomorrow');
        while ((int) $from->format('N') > 5) {
            $from = $from->modify('+1 day');
        }

        return [$from->format('Y-m-d'), $from->modify('+4 weekdays')->format('Y-m-d')];
    }

    private function captureThrowable(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }
}
