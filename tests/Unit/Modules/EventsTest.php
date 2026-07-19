<?php
/**
 * Events Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Events;
use CRM\Database;

class EventsTest extends DatabaseTestCase
{
    private Events $events;
    private int $testUserId;
    private int $otherUserId;
    private int $ownerUserId;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new Events();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('events-user-', true), 'test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('events-other-', true), 'other@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('events-owner-', true), 'owner@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->ownerUserId = (int) Database::lastInsertId();

        foreach ([$this->testUserId, $this->otherUserId, $this->ownerUserId] as $workspaceUserId) {
            Database::execute(
                "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
                 VALUES (1, ?, 'member', 'active', NOW())",
                [$workspaceUserId]
            );
        }

        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        Authorization::assignUserRole($this->ownerUserId, (int) ($ownerRole['id'] ?? 0), $this->ownerUserId);
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at)
             VALUES (1, ?, ?, ?, NOW())",
            ['John', 'Doe', 'john@example.com']
        );
        $this->testContactId = (int) Database::lastInsertId();
    }
    
    public function testCreateEvent()
    {
        $id = $this->events->create([
            'title' => 'Test Event',
            'start_time' => '2026-01-25 10:00:00',
            'end_time' => '2026-01-25 11:00:00',
            'event_type' => 'meeting',
            'contact_id' => $this->testContactId,
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $event = Database::queryOne("SELECT * FROM events WHERE id = ?", [$id]);
        $this->assertEquals('Test Event', $event['title']);
        $this->assertEquals('meeting', $event['event_type']);
    }
    
    public function testCreateEventRequiresTitleAndStartTime()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Event title and start time are required");
        
        $this->events->create(['title' => 'Test']);
    }

    public function testCreateRejectsContactAssignedToAnotherUser(): void
    {
        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$this->otherUserId, $this->testContactId]
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected contact is not accessible.');
        $this->events->create([
            'title' => 'Unauthorized Contact Event',
            'start_time' => '2026-01-25 10:00:00',
            'contact_id' => $this->testContactId,
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
        ]);
    }

    public function testUpdateRejectsRetargetingEventToAnotherUsersContact(): void
    {
        $eventId = $this->events->create([
            'title' => 'Owned Event',
            'start_time' => '2026-01-25 10:00:00',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
        ]);
        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$this->otherUserId, $this->testContactId]
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Selected contact is not accessible.');
        $this->events->update($eventId, [
            'contact_id' => $this->testContactId,
            'actor_user_id' => $this->testUserId,
        ]);
    }
    
    public function testGetEventById()
    {
        $id = $this->events->create([
            'title' => 'Test Event',
            'start_time' => '2026-01-25 10:00:00',
            'created_by' => $this->testUserId
        ]);
        
        $event = $this->events->getById($id);
        
        $this->assertIsArray($event);
        $this->assertEquals('Test Event', $event['title']);
    }
    
    public function testGetUpcoming()
    {
        // Create future event
        $this->events->create([
            'title' => 'Future Event',
            'start_time' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $events = $this->events->getUpcoming(5, $this->testUserId);
        
        $this->assertIsArray($events);
        $this->assertGreaterThanOrEqual(1, count($events));
    }

    public function testApplyVisibilityScopeDefaultsToCurrentUserForRegularUser(): void
    {
        $user = ['id' => $this->testUserId, 'role' => 'user'];

        $scoped = $this->events->applyVisibilityScope([], $user);

        $this->assertSame($this->testUserId, $scoped['assigned_to']);
    }

    public function testResolveAssignedToForRegularUserForcesCurrentUser(): void
    {
        $user = ['id' => $this->testUserId, 'role' => 'user'];

        $resolved = $this->events->resolveAssignedToForUser($this->otherUserId, $user);

        $this->assertSame($this->testUserId, $resolved);
    }

    public function testGetByIdForUserRejectsEventAssignedToAnotherUser(): void
    {
        $eventId = $this->events->create([
            'title' => 'Other User Event',
            'start_time' => '2026-01-26 10:00:00',
            'assigned_to' => $this->otherUserId,
            'created_by' => $this->otherUserId,
        ]);

        $event = $this->events->getByIdForUser($eventId, ['id' => $this->testUserId, 'role' => 'user']);

        $this->assertNull($event);
    }

    public function testOwnerCanResolveAllCalendarScopeAndOtherAssignments(): void
    {
        $owner = ['id' => $this->ownerUserId, 'role' => 'viewer'];

        $allScope = $this->events->applyVisibilityScope(['assigned_to' => ''], $owner);
        $otherAssignee = $this->events->resolveAssignedToForUser($this->otherUserId, $owner);

        $this->assertArrayNotHasKey('assigned_to', $allScope);
        $this->assertSame($this->otherUserId, $otherAssignee);
    }

    public function testDateRangeIncludesEventThatStartedBeforeRange(): void
    {
        $this->events->create([
            'title' => 'Multi-day Strategy Session',
            'start_time' => '2026-04-01 22:00:00',
            'end_time' => '2026-04-03 10:00:00',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
        ]);

        $events = $this->events->getByDateRangeForUser(
            '2026-04-02',
            '2026-04-02',
            [],
            ['id' => $this->testUserId, 'role' => 'user']
        );

        $this->assertContains('Multi-day Strategy Session', array_column($events, 'title'));
    }

    public function testCoveredDatesRepeatSpanningEventsAcrossVisibleDays(): void
    {
        $coveredDates = $this->events->getDatesCoveredByEvent([
            'start_time' => '2026-04-01 22:00:00',
            'end_time' => '2026-04-03 10:00:00',
            'is_all_day' => 1,
        ], '2026-04-02', '2026-04-04');

        $this->assertSame(['2026-04-02', '2026-04-03'], $coveredDates);
    }

    public function testRecurringEventsExpandInsideRequestedRangeAndRespectCount(): void
    {
        $cases = [
            ['daily', '2026-01-01 09:00:00', '2026-01-02', '2026-01-04', ['2026-01-02 09:00:00', '2026-01-03 09:00:00']],
            ['weekly', '2026-01-01 09:00:00', '2026-01-08', '2026-01-15', ['2026-01-08 09:00:00', '2026-01-15 09:00:00']],
            ['monthly', '2026-01-15 09:00:00', '2026-02-01', '2026-03-31', ['2026-02-15 09:00:00', '2026-03-15 09:00:00']],
            ['yearly', '2026-06-01 09:00:00', '2027-01-01', '2027-12-31', ['2027-06-01 09:00:00']],
        ];

        foreach ($cases as [$pattern, $startTime, $rangeStart, $rangeEnd, $expectedStarts]) {
            $expanded = $this->events->expandEventsForDateRange([[
                'id' => random_int(1000, 9999),
                'workspace_id' => 1,
                'title' => ucfirst($pattern) . ' recurrence',
                'start_time' => $startTime,
                'end_time' => date('Y-m-d H:i:s', (int) strtotime($startTime) + 3600),
                'recurrence_pattern' => $pattern,
                'recurrence_count' => 3,
                'recurrence_end_date' => null,
            ]], $rangeStart, $rangeEnd);

            $this->assertSame($expectedStarts, array_column($expanded, 'start_time'), $pattern . ' recurrence mismatch');
            $this->assertNotEmpty(array_filter(array_column($expanded, 'occurrence_id')));
        }
    }
}
