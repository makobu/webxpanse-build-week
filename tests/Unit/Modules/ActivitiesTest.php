<?php
/**
 * Activities Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Activities;
use CRM\Modules\Contacts;
use CRM\Database;
use InvalidArgumentException;

class ActivitiesTest extends DatabaseTestCase
{
    private Activities $activities;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->activities = new Activities();
        $this->contacts = new Contacts();
        
        // Create test contact
        $contact = $this->contacts->create([
            'first_name' => 'Activity',
            'email' => 'activity@example.com'
        ]);
        $this->testContactId = $contact['id'];
    }
    
    protected function tearDown(): void
    {
        if ($this->testContactId) {
            $this->contacts->delete($this->testContactId);
        }
        parent::tearDown();
    }
    
    public function testLogActivity(): void
    {
        $activityId = $this->activities->log(
            $this->testContactId,
            'note',
            'Test note',
            ['key' => 'value']
        );
        
        $this->assertIsInt($activityId);
        $this->assertGreaterThan(0, $activityId);
    }

    public function testLogSystemActivityTypes(): void
    {
        foreach (['deal_created', 'deal_stage_changed', 'workflow_activity'] as $type) {
            $activityId = $this->activities->log(
                $this->testContactId,
                $type,
                "System event: {$type}"
            );

            $activity = $this->activities->getById($activityId);
            $this->assertSame($type, $activity['activity_type']);
            $this->assertSame(Activities::formatTypeLabel($type), ucwords(str_replace('_', ' ', $type)));
        }
    }

    public function testInvalidActivityTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->activities->log($this->testContactId, 'deal-created', 'Invalid type');
    }
    
    public function testGetActivitiesByContact(): void
    {
        // Create multiple activities
        $this->activities->log($this->testContactId, 'email', 'Email sent');
        $this->activities->log($this->testContactId, 'call', 'Phone call made');
        $this->activities->log($this->testContactId, 'note', 'Note added');
        
        $activities = $this->activities->getByContact($this->testContactId);
        
        $this->assertGreaterThanOrEqual(3, count($activities));
        $this->assertEquals('note', $activities[0]['activity_type']); // Most recent first
    }
    
    public function testGetActivitiesByType(): void
    {
        $this->activities->log($this->testContactId, 'email', 'Email 1');
        $this->activities->log($this->testContactId, 'email', 'Email 2');
        
        $activities = $this->activities->getByType('email');
        
        $this->assertGreaterThanOrEqual(2, count($activities));
        foreach ($activities as $activity) {
            $this->assertEquals('email', $activity['activity_type']);
        }
    }
    
    public function testGetRecentActivities(): void
    {
        $this->activities->log($this->testContactId, 'note', 'Recent note');
        
        $activities = $this->activities->getRecent(10);
        
        $this->assertGreaterThan(0, count($activities));
    }
    
    public function testCountActivitiesByContact(): void
    {
        $this->activities->log($this->testContactId, 'email', 'Email 1');
        $this->activities->log($this->testContactId, 'email', 'Email 2');
        
        $count = $this->activities->countByContact($this->testContactId);
        
        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function testTypeCountsIncludeManualAndSystemTypes(): void
    {
        $this->activities->log($this->testContactId, 'note', 'Manual note');
        $this->activities->log($this->testContactId, 'deal_created', 'System deal');

        $counts = $this->activities->getTypeCounts();
        $byType = [];
        foreach ($counts as $row) {
            $byType[$row['type']] = $row;
        }

        $this->assertArrayHasKey('note', $byType);
        $this->assertArrayHasKey('deal_created', $byType);
        $this->assertTrue($byType['note']['is_manual']);
        $this->assertFalse($byType['deal_created']['is_manual']);
        $this->assertSame('Deal Created', $byType['deal_created']['label']);
    }
}
