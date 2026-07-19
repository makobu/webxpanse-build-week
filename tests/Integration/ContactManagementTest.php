<?php
/**
 * Contact Management Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Contacts;
use CRM\Modules\CustomFields;
use CRM\Database;

class ContactManagementTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private CustomFields $customFields;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
        $this->customFields = new CustomFields();
    }
    
    public function testCompleteContactLifecycle(): void
    {
        // 1. Create contact
        $contactData = [
            'first_name' => 'Integration',
            'last_name' => 'Test',
            'email' => 'integration@example.com',
            'phone' => '+1234567890',
            'company' => 'Test Company',
            'lead_source' => 'form',
            'stage' => 'new'
        ];
        
        $result = $this->contacts->create($contactData);
        $this->assertEquals('success', $result['status']);
        $contactId = $result['id'];
        
        // 2. Verify contact exists
        $contact = $this->contacts->getById($contactId);
        $this->assertNotNull($contact);
        $this->assertEquals('Integration', $contact['first_name']);
        $this->assertEquals('new', $contact['stage']);
        
        // 3. Create custom field
        $fieldId = $this->customFields->create([
            'field_name' => 'Custom Field',
            'field_type' => 'text',
            'module' => 'contacts'
        ]);
        
        // 4. Set custom field value
        $this->customFields->setContactValue($contactId, $fieldId, 'Custom Value');
        
        // 5. Get custom field values
        $values = $this->customFields->getContactValues($contactId);
        $this->assertGreaterThan(0, count($values));
        
        // 6. Update contact stage
        $this->contacts->update($contactId, ['stage' => 'contacted']);
        $contact = $this->contacts->getById($contactId);
        $this->assertEquals('contacted', $contact['stage']);
        
        // 7. Verify activity was logged
        $activities = Database::query(
            "SELECT * FROM activities WHERE contact_id = ? ORDER BY created_at DESC",
            [$contactId]
        );
        $this->assertGreaterThan(0, count($activities));
        
        // 8. Search for contact
        $searchResults = $this->contacts->search('Integration');
        $this->assertGreaterThan(0, count($searchResults));
        
        // 9. Delete contact (cascades to custom data and activities)
        $deleted = $this->contacts->delete($contactId);
        $this->assertTrue($deleted);
        
        // 10. Verify contact is deleted
        $contact = $this->contacts->getById($contactId);
        $this->assertNull($contact);
        
        // Clean up custom field
        $this->customFields->delete($fieldId);
    }
    
    public function testBulkContactOperations(): void
    {
        $contactIds = [];
        
        // Create multiple contacts
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->contacts->create([
                'first_name' => "Bulk{$i}",
                'email' => "bulk{$i}@example.com"
            ]);
            $contactIds[] = $result['id'];
        }
        
        // Get all contacts
        $allContacts = $this->contacts->getAll(10, 0);
        $this->assertGreaterThanOrEqual(5, count($allContacts));
        
        // Filter by stage
        $newContacts = $this->contacts->getAll(10, 0, 'new');
        $this->assertGreaterThanOrEqual(5, count($newContacts));
        
        // Clean up
        foreach ($contactIds as $id) {
            $this->contacts->delete($id);
        }
    }
}
