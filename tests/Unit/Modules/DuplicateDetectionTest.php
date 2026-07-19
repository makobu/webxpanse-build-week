<?php
/**
 * Duplicate Detection Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Contacts;

class DuplicateDetectionTest extends DatabaseTestCase
{
    private Contacts $contacts;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
    }
    
    public function testDuplicateEmailDetection(): void
    {
        $email = 'duplicate@example.com';
        
        // Create first contact
        $contact1 = $this->contacts->create([
            'first_name' => 'First',
            'email' => $email
        ]);
        
        // Try to create duplicate
        $contact2 = $this->contacts->create([
            'first_name' => 'Second',
            'email' => $email
        ]);
        
        $this->assertEquals('duplicate', $contact2['status']);
        $this->assertArrayHasKey('matches', $contact2);
        $this->assertArrayHasKey('email', $contact2['matches']);
        
        // Clean up
        $this->contacts->delete($contact1['id']);
    }
    
    public function testDuplicatePhoneDetection(): void
    {
        $phone = '+1234567890';
        
        // Create first contact
        $contact1 = $this->contacts->create([
            'first_name' => 'First',
            'email' => 'first@example.com',
            'phone' => $phone
        ]);
        
        // Try to create duplicate
        $contact2 = $this->contacts->create([
            'first_name' => 'Second',
            'email' => 'second@example.com',
            'phone' => $phone
        ]);
        
        $this->assertEquals('duplicate', $contact2['status']);
        $this->assertArrayHasKey('matches', $contact2);
        $this->assertArrayHasKey('phone', $contact2['matches']);
        
        // Clean up
        $this->contacts->delete($contact1['id']);
    }
    
    public function testNoDuplicateWhenDifferent(): void
    {
        $contact1 = $this->contacts->create([
            'first_name' => 'First',
            'email' => 'first@example.com',
            'phone' => '+1111111111'
        ]);
        
        $contact2 = $this->contacts->create([
            'first_name' => 'Second',
            'email' => 'second@example.com',
            'phone' => '+2222222222'
        ]);
        
        $this->assertEquals('success', $contact2['status']);
        
        // Clean up
        $this->contacts->delete($contact1['id']);
        $this->contacts->delete($contact2['id']);
    }
}
