<?php
/**
 * Tracking Flow Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Tracking;
use CRM\Modules\Contacts;
use CRM\Database;

class TrackingFlowTest extends DatabaseTestCase
{
    private Tracking $tracking;
    private Contacts $contacts;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tracking = new Tracking();
        $this->contacts = new Contacts();
    }
    
    public function testCompleteTrackingFlow(): void
    {
        $visitorId = 'integration_visitor_' . uniqid();
        
        // 1. Track initial page view
        $pageView1 = $this->tracking->trackPageView([
            'visitor_id' => $visitorId,
            'page' => '/home',
            'referrer' => 'https://google.com',
            'utm' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'test'
            ]
        ]);
        
        $this->assertGreaterThan(0, $pageView1);
        
        // 2. Track another page view
        $pageView2 = $this->tracking->trackPageView([
            'visitor_id' => $visitorId,
            'page' => '/products'
        ]);
        
        $this->assertGreaterThan(0, $pageView2);
        
        // 3. Track form submission (creates contact)
        $formData = [
            'email' => 'integration@example.com',
            'first_name' => 'Integration',
            'last_name' => 'Test',
            'phone' => '+1234567890'
        ];
        
        $submissionId = $this->tracking->trackFormSubmission([
            'visitor_id' => $visitorId,
            'form_id' => 'contact_form',
            'form_data' => $formData,
            'page' => '/contact'
        ]);
        
        $this->assertGreaterThan(0, $submissionId);
        
        // 4. Verify contact was created
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE email = ?",
            [$formData['email']]
        );
        $this->assertNotNull($contact);
        $this->assertEquals('Integration', $contact['first_name']);
        
        // 5. Verify form submission is linked to contact
        $submission = Database::queryOne(
            "SELECT * FROM form_submissions WHERE id = ?",
            [$submissionId]
        );
        $this->assertEquals($contact['id'], $submission['contact_id']);
        
        // 6. Verify activity was logged
        $activities = Database::query(
            "SELECT * FROM activities WHERE contact_id = ? AND activity_type = 'form_submit'",
            [$contact['id']]
        );
        $this->assertGreaterThan(0, count($activities));
        
        // Clean up
        $this->contacts->delete($contact['id']);
    }
}
