<?php
/**
 * Tracking Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Tracking;
use CRM\Modules\Contacts;
use CRM\Database;

class TrackingTest extends DatabaseTestCase
{
    private Tracking $tracking;
    private Contacts $contacts;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tracking = new Tracking();
        $this->contacts = new Contacts();
    }
    
    public function testTrackPageView(): void
    {
        $data = [
            'visitor_id' => 'test_visitor_' . uniqid(),
            'page' => '/test-page',
            'referrer' => 'https://example.com',
            'utm' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc'
            ]
        ];
        
        $pageViewId = $this->tracking->trackPageView($data);
        
        $this->assertIsInt($pageViewId);
        $this->assertGreaterThan(0, $pageViewId);
        
        // Verify visitor was created
        $visitor = Database::queryOne(
            "SELECT * FROM visitors WHERE visitor_id = ?",
            [$data['visitor_id']]
        );
        $this->assertNotNull($visitor);
    }
    
    public function testTrackFormSubmission(): void
    {
        $visitorId = 'test_visitor_' . uniqid();
        $formData = [
            'email' => 'test_' . uniqid() . '@example.com',
            'first_name' => 'Test',
            'last_name' => 'User'
        ];
        
        $data = [
            'workspace_id' => 1,
            'visitor_id' => $visitorId,
            'form_id' => 'contact_form',
            'form_data' => $formData,
            'page' => '/contact'
        ];
        
        $submissionId = $this->tracking->trackFormSubmission($data);
        
        $this->assertIsInt($submissionId);
        $this->assertGreaterThan(0, $submissionId);

        $submission = Database::queryOne(
            "SELECT workspace_id FROM form_submissions WHERE id = ?",
            [$submissionId]
        );
        $this->assertSame(1, (int) ($submission['workspace_id'] ?? 0));
        
        // Verify contact was created
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE email = ?",
            [$formData['email']]
        );
        $this->assertNotNull($contact);
        
        // Clean up
        if ($contact) {
            $this->contacts->delete($contact['id']);
        }
    }
    
    public function testGetVisitorPageViews(): void
    {
        $visitorId = 'test_visitor_' . uniqid();
        
        // Track multiple page views
        $this->tracking->trackPageView(['visitor_id' => $visitorId, 'page' => '/page1']);
        $this->tracking->trackPageView(['visitor_id' => $visitorId, 'page' => '/page2']);
        
        $views = $this->tracking->getVisitorPageViews($visitorId);
        
        $this->assertGreaterThanOrEqual(2, count($views));
    }
}
