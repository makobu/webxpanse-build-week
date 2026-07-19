<?php
/**
 * Email Tracking Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\EmailTracking;
use CRM\Services\EmailService;
use CRM\Modules\Contacts;
use CRM\Database;

class EmailTrackingTest extends DatabaseTestCase
{
    private EmailTracking $tracking;
    private EmailService $emailService;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tracking = new EmailTracking();
        $this->emailService = new EmailService();
        $this->contacts = new Contacts();
        
        $contact = $this->contacts->create([
            'first_name' => 'Track',
            'email' => 'track@example.com'
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
    
    public function testTrackEmailOpen(): void
    {
        // Create email
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test',
            'Body'
        );
        
        $email = Database::queryOne(
            "SELECT id FROM emails WHERE uuid = ?",
            [$uuid]
        );
        
        // Track open
        $this->tracking->trackOpen($uuid);
        
        // Verify tracking record
        $track = Database::queryOne(
            "SELECT * FROM email_tracking WHERE email_id = ? AND tracking_type = 'open'",
            [$email['id']]
        );
        $this->assertNotNull($track);
        
        // Verify email status updated
        $email = Database::queryOne(
            "SELECT status, opened_at FROM emails WHERE id = ?",
            [$email['id']]
        );
        $this->assertEquals('opened', $email['status']);
        $this->assertNotNull($email['opened_at']);
    }
    
    public function testTrackEmailClick(): void
    {
        // Create email
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test',
            'Body'
        );
        
        $email = Database::queryOne(
            "SELECT id FROM emails WHERE uuid = ?",
            [$uuid]
        );
        
        // Track click
        $this->tracking->trackClick($uuid, 'https://example.com');
        
        // Verify tracking record
        $track = Database::queryOne(
            "SELECT * FROM email_tracking WHERE email_id = ? AND tracking_type = 'click'",
            [$email['id']]
        );
        $this->assertNotNull($track);
        $this->assertEquals('https://example.com', $track['clicked_url']);
        
        // Verify email status updated
        $email = Database::queryOne(
            "SELECT status, clicked_at FROM emails WHERE id = ?",
            [$email['id']]
        );
        $this->assertEquals('clicked', $email['status']);
        $this->assertNotNull($email['clicked_at']);
    }
    
    public function testGetTrackingStats(): void
    {
        // Create email
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test',
            'Body'
        );
        
        $email = Database::queryOne(
            "SELECT id FROM emails WHERE uuid = ?",
            [$uuid]
        );
        
        // Track opens and clicks
        $this->tracking->trackOpen($uuid);
        $this->tracking->trackClick($uuid, 'https://example.com');
        $this->tracking->trackClick($uuid, 'https://example.com/page2');
        
        $stats = $this->tracking->getStats($email['id']);
        
        $this->assertEquals(1, $stats['opens']);
        $this->assertEquals(2, $stats['clicks']);
    }
}
