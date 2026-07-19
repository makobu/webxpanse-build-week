<?php
/**
 * Email Flow Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\EmailService;
use CRM\Modules\EmailTracking;
use CRM\Modules\Contacts;
use CRM\Database;

class EmailFlowTest extends DatabaseTestCase
{
    private EmailService $emailService;
    private EmailTracking $tracking;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->emailService = new EmailService();
        $this->tracking = new EmailTracking();
        $this->contacts = new Contacts();
        
        $contact = $this->contacts->create([
            'first_name' => 'Email',
            'email' => 'emailflow@example.com'
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
    
    public function testCompleteEmailFlow(): void
    {
        // 1. Send email
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test Email',
            'Test body',
            ['body_html' => '<html><body><a href="https://example.com">Click</a></body></html>']
        );
        
        $this->assertIsString($uuid);
        
        // 2. Verify email created
        $email = Database::queryOne(
            "SELECT * FROM emails WHERE uuid = ?",
            [$uuid]
        );
        $this->assertNotNull($email);
        $this->assertEquals('pending', $email['status']);
        
        // 3. Verify in queue
        $queueItem = Database::queryOne(
            "SELECT * FROM email_queue WHERE email_id = ?",
            [$email['id']]
        );
        $this->assertNotNull($queueItem);
        
        // 4. Track open
        $this->tracking->trackOpen($uuid);
        
        $email = Database::queryOne(
            "SELECT id, status FROM emails WHERE uuid = ?",
            [$uuid]
        );
        $this->assertEquals('opened', $email['status']);
        
        // 5. Track click
        $this->tracking->trackClick($uuid, 'https://example.com');
        
        $email = Database::queryOne(
            "SELECT id, status FROM emails WHERE uuid = ?",
            [$uuid]
        );
        $this->assertEquals('clicked', $email['status']);
        
        // 6. Verify tracking stats
        $stats = $this->tracking->getStats($email['id']);
        $this->assertEquals(1, $stats['opens']);
        $this->assertEquals(1, $stats['clicks']);
    }
}
