<?php
/**
 * Activity Timeline Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Activities;
use CRM\Modules\ActivityTimeline;
use CRM\Modules\Contacts;

class ActivityTimelineTest extends DatabaseTestCase
{
    private Activities $activities;
    private ActivityTimeline $timeline;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->activities = new Activities();
        $this->timeline = new ActivityTimeline();
        $this->contacts = new Contacts();
        
        // Create test contact
        $contact = $this->contacts->create([
            'first_name' => 'Timeline',
            'email' => 'timeline@example.com'
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
    
    public function testGetTimeline(): void
    {
        // Create multiple activities
        $this->activities->log($this->testContactId, 'email', 'Email sent');
        $this->activities->log($this->testContactId, 'call', 'Phone call');
        $this->activities->log($this->testContactId, 'note', 'Note added');
        
        $timeline = $this->timeline->getTimeline($this->testContactId);
        
        $this->assertGreaterThanOrEqual(3, count($timeline));
        $this->assertArrayHasKey('type', $timeline[0]);
        $this->assertArrayHasKey('description', $timeline[0]);
        $this->assertArrayHasKey('formatted_date', $timeline[0]);
    }
    
    public function testTimelineFormatting(): void
    {
        $this->activities->log($this->testContactId, 'email', 'Test email');
        
        $timeline = $this->timeline->getTimeline($this->testContactId);
        
        $this->assertNotEmpty($timeline);
        $activity = $timeline[0];
        $this->assertIsString($activity['formatted_date']);
        $this->assertIsString($activity['description']);
    }
}
