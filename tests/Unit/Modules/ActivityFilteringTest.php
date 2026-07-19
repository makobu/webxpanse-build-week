<?php
/**
 * Activity Filtering Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Activities;
use CRM\Modules\Contacts;

class ActivityFilteringTest extends DatabaseTestCase
{
    private Activities $activities;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->activities = new Activities();
        $this->contacts = new Contacts();
        
        $contact = $this->contacts->create([
            'first_name' => 'Filter',
            'email' => 'filter@example.com'
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
    
    public function testFilterByType(): void
    {
        $this->activities->log($this->testContactId, 'email', 'Email 1');
        $this->activities->log($this->testContactId, 'call', 'Call 1');
        $this->activities->log($this->testContactId, 'email', 'Email 2');
        
        $emailActivities = $this->activities->getByType('email');
        $callActivities = $this->activities->getByType('call');
        
        $this->assertGreaterThanOrEqual(2, count($emailActivities));
        $this->assertGreaterThanOrEqual(1, count($callActivities));
        
        foreach ($emailActivities as $activity) {
            $this->assertEquals('email', $activity['activity_type']);
        }
    }
    
    public function testPagination(): void
    {
        // Create more activities than limit
        for ($i = 0; $i < 15; $i++) {
            $this->activities->log($this->testContactId, 'note', "Note $i");
        }
        
        $firstPage = $this->activities->getByContact($this->testContactId, 10, 0);
        $secondPage = $this->activities->getByContact($this->testContactId, 10, 10);
        
        $this->assertEquals(10, count($firstPage));
        $this->assertGreaterThan(0, count($secondPage));
    }
}
