<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\CalendarService;
use CRM\Services\GoogleCalendarService;
use CRM\Services\OutlookCalendarService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;
use ReflectionMethod;

class CalendarServiceTest extends DatabaseTestCase
{
    private CalendarService $service;
    private int $userId;
    private int $otherUserId;
    private int $ownerUserId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('brandProductName')) {
            require_once __DIR__ . '/../../../config/constants.php';
        }

        $this->service = new CalendarService();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('calendar-user-', true), 'calendar-user@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('calendar-other-', true), 'calendar-other@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('calendar-owner-', true), 'calendar-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->ownerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                slug = VALUES(slug),
                status = VALUES(status),
                plan_status = VALUES(plan_status)",
            ['00000000-0000-4000-8000-000000000002']
        );

        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        Authorization::assignUserRole($this->ownerUserId, (int) ($ownerRole['id'] ?? 0), $this->ownerUserId);
        WorkspaceContext::activateRuntimeWorkspace(1);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function testImportAssignsEventsToImportingUser(): void
    {
        $ical = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:test-import-1\r\n"
            . "SUMMARY:Imported Event\r\n"
            . "DTSTART:20260328T100000Z\r\n"
            . "DTEND:20260328T110000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $result = $this->service->importFromICal($ical, $this->userId, ['skip_duplicates' => true]);

        $this->assertSame(1, $result['count']);
        $eventId = (int) ($result['imported'][0] ?? 0);
        $event = Database::queryOne("SELECT assigned_to, created_by, workspace_id FROM events WHERE id = ?", [$eventId]);

        $this->assertSame($this->userId, (int) ($event['assigned_to'] ?? 0));
        $this->assertSame($this->userId, (int) ($event['created_by'] ?? 0));
        $this->assertSame(1, (int) ($event['workspace_id'] ?? 0));
    }

    public function testExportToICalRespectsScopedVisibility(): void
    {
        Database::execute(
            "INSERT INTO events (workspace_id, title, event_type, assigned_to, created_by, start_time, status, created_at) VALUES (1, ?, 'meeting', ?, ?, ?, 'scheduled', NOW())",
            ['My Event', $this->userId, $this->userId, '2026-03-28 10:00:00']
        );
        Database::execute(
            "INSERT INTO events (workspace_id, title, event_type, assigned_to, created_by, start_time, status, created_at) VALUES (2, ?, 'meeting', ?, ?, ?, 'scheduled', NOW())",
            ['Other Event', $this->otherUserId, $this->otherUserId, '2026-03-28 11:00:00']
        );

        $userIcal = $this->service->exportToICal(null, ['id' => $this->userId, 'role' => 'user'], [
            'assigned_to' => $this->userId,
        ]);
        $ownerIcal = $this->service->exportToICal(null, ['id' => $this->ownerUserId, 'role' => 'viewer'], [
            'assigned_to' => '',
        ]);

        $this->assertStringContainsString('SUMMARY:My Event', $userIcal);
        $this->assertStringNotContainsString('SUMMARY:Other Event', $userIcal);
        $this->assertStringContainsString('SUMMARY:My Event', $ownerIcal);
        $this->assertStringNotContainsString('SUMMARY:Other Event', $ownerIcal);
    }

    public function testGoogleSyncAssignsImportedEventAndPreservesManualReassignment(): void
    {
        $method = new ReflectionMethod(GoogleCalendarService::class, 'createOrUpdateEventFromGoogle');
        $method->setAccessible(true);
        $service = new GoogleCalendarService();

        $eventId = (int) $method->invoke($service, [
            'id' => 'google-visible-1',
            'summary' => 'Google Synced Event',
            'description' => 'Synced from Google',
            'start' => ['dateTime' => '2026-03-28T10:00:00+03:00'],
            'end' => ['dateTime' => '2026-03-28T11:00:00+03:00'],
        ], $this->userId, 101, 1);

        $event = Database::queryOne("SELECT assigned_to FROM events WHERE id = ?", [$eventId]);
        $this->assertSame($this->userId, (int) ($event['assigned_to'] ?? 0));

        $visible = (new \CRM\Modules\Events())->getByDateRangeForUser(
            '2026-03-28',
            '2026-03-28',
            [],
            ['id' => $this->userId, 'role' => 'user']
        );
        $this->assertContains('Google Synced Event', array_column($visible, 'title'));

        Database::execute("UPDATE events SET assigned_to = ? WHERE id = ?", [$this->otherUserId, $eventId]);
        $method->invoke($service, [
            'id' => 'google-visible-1',
            'summary' => 'Google Synced Event Updated',
            'start' => ['dateTime' => '2026-03-28T12:00:00+03:00'],
            'end' => ['dateTime' => '2026-03-28T13:00:00+03:00'],
        ], $this->userId, 101, 1);

        $updated = Database::queryOne("SELECT assigned_to FROM events WHERE id = ?", [$eventId]);
        $this->assertSame($this->otherUserId, (int) ($updated['assigned_to'] ?? 0));
    }

    public function testGoogleBookingPayloadPreservesProfileTimezoneAndRequestsMeetConference(): void
    {
        $method = new ReflectionMethod(GoogleCalendarService::class, 'buildGoogleEventPayload');
        $method->setAccessible(true);

        $payload = $method->invoke(new GoogleCalendarService(), [
            'id' => 321,
            'workspace_id' => 1,
            'title' => 'Client meeting',
            'description' => 'Booking request',
            'location' => 'Google Meet',
            'start_time' => '2026-08-10 09:00:00',
            'end_time' => '2026-08-10 09:30:00',
        ], [
            'timezone' => 'Africa/Nairobi',
            'meeting_format' => 'google_meet',
        ]);

        $this->assertSame('Africa/Nairobi', (string) ($payload['start']['timeZone'] ?? ''));
        $this->assertSame('2026-08-10T09:00:00+03:00', (string) ($payload['start']['dateTime'] ?? ''));
        $this->assertSame('hangoutsMeet', (string) ($payload['conferenceData']['createRequest']['conferenceSolutionKey']['type'] ?? ''));
        $this->assertSame('crm-booking-1-321', (string) ($payload['conferenceData']['createRequest']['requestId'] ?? ''));
    }

    public function testOutlookSyncAssignsImportedEventAndFillsNullAssignment(): void
    {
        $method = new ReflectionMethod(OutlookCalendarService::class, 'createOrUpdateEventFromOutlook');
        $method->setAccessible(true);
        $service = new OutlookCalendarService();

        $eventId = (int) $method->invoke($service, [
            'id' => 'outlook-visible-1',
            'subject' => 'Outlook Synced Event',
            'body' => ['content' => 'Synced from Outlook'],
            'start' => ['dateTime' => '2026-03-29T09:00:00', 'timeZone' => 'Africa/Nairobi'],
            'end' => ['dateTime' => '2026-03-29T10:00:00', 'timeZone' => 'Africa/Nairobi'],
        ], $this->userId, 202, 1);

        $event = Database::queryOne("SELECT assigned_to FROM events WHERE id = ?", [$eventId]);
        $this->assertSame($this->userId, (int) ($event['assigned_to'] ?? 0));

        Database::execute("UPDATE events SET assigned_to = NULL WHERE id = ?", [$eventId]);
        $method->invoke($service, [
            'id' => 'outlook-visible-1',
            'subject' => 'Outlook Synced Event Updated',
            'start' => ['dateTime' => '2026-03-29T11:00:00', 'timeZone' => 'Africa/Nairobi'],
            'end' => ['dateTime' => '2026-03-29T12:00:00', 'timeZone' => 'Africa/Nairobi'],
        ], $this->userId, 202, 1);

        $updated = Database::queryOne("SELECT assigned_to FROM events WHERE id = ?", [$eventId]);
        $this->assertSame($this->userId, (int) ($updated['assigned_to'] ?? 0));
    }

    public function testImportHandlesTimezoneAllDayAndFoldedDescriptions(): void
    {
        $ical = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:tz-import-1\r\n"
            . "SUMMARY:TZID Event\r\n"
            . "DTSTART;TZID=Africa/Nairobi:20260328T100000\r\n"
            . "DTEND;TZID=Africa/Nairobi:20260328T110000\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:all-day-import-1\r\n"
            . "SUMMARY:All Day Imported\r\n"
            . "DESCRIPTION:First line\r\n"
            . " folded description\r\n"
            . "DTSTART;VALUE=DATE:20260330\r\n"
            . "DTEND;VALUE=DATE:20260331\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $result = $this->service->importFromICal($ical, $this->userId);

        $this->assertSame(2, $result['count']);
        $timed = Database::queryOne("SELECT start_time FROM events WHERE title = 'TZID Event' LIMIT 1");
        $allDay = Database::queryOne("SELECT description, start_time, end_time, is_all_day FROM events WHERE title = 'All Day Imported' LIMIT 1");

        $this->assertSame('2026-03-28 10:00:00', (string) ($timed['start_time'] ?? ''));
        $this->assertStringContainsString('folded description', (string) ($allDay['description'] ?? ''));
        $this->assertSame('2026-03-30 00:00:00', (string) ($allDay['start_time'] ?? ''));
        $this->assertSame('2026-03-30 23:59:59', (string) ($allDay['end_time'] ?? ''));
        $this->assertSame(1, (int) ($allDay['is_all_day'] ?? 0));
    }

    public function testExportEmitsAllDayTimedAndRecurringICal(): void
    {
        Database::execute(
            "INSERT INTO events (
                workspace_id, title, event_type, assigned_to, created_by, start_time, end_time, is_all_day,
                recurrence_pattern, recurrence_count, status, created_at
            ) VALUES (1, ?, 'meeting', ?, ?, ?, ?, 1, 'weekly', 3, 'scheduled', NOW())",
            ['Recurring All Day', $this->userId, $this->userId, '2026-04-01 00:00:00', '2026-04-02 23:59:59']
        );
        $allDayId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO events (workspace_id, title, event_type, assigned_to, created_by, start_time, end_time, status, created_at)
             VALUES (1, ?, 'meeting', ?, ?, ?, ?, 'scheduled', NOW())",
            ['Timed Export', $this->userId, $this->userId, '2026-04-03 10:00:00', '2026-04-03 11:00:00']
        );
        $timedId = (int) Database::lastInsertId();

        $ical = $this->service->exportToICal([$allDayId, $timedId], ['id' => $this->userId, 'role' => 'user']);

        $this->assertStringContainsString("SUMMARY:Recurring All Day", $ical);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20260401", $ical);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20260403", $ical);
        $this->assertStringContainsString("RRULE:FREQ=WEEKLY;COUNT=3", $ical);
        $this->assertStringContainsString("SUMMARY:Timed Export", $ical);
        $this->assertMatchesRegularExpression('/DTSTART:\d{8}T\d{6}Z/', $ical);
    }
}
