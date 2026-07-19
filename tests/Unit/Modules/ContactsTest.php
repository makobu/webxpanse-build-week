<?php
/**
 * Contacts Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Contacts;
use CRM\Modules\Companies;
use CRM\Database;

class ContactsTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private Companies $companies;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
        $this->companies = new Companies();
    }
    
    public function testCreateContact(): void
    {
        $data = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+1234567890',
            'company' => 'Test Company',
            'lead_source' => 'form'
        ];
        
        $result = $this->contacts->create($data);
        
        $this->assertEquals('success', $result['status']);
        $this->assertIsInt($result['id']);
        $this->assertIsString($result['uuid']);
        
        // Clean up
        $this->contacts->delete($result['id']);
    }

    public function testCreateContactCreatesAndLinksCompany(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Link',
            'email' => 'link@example.com',
            'company' => 'Linked Company',
        ]);

        $contact = $this->contacts->getById($result['id']);
        $this->assertNotNull($contact);
        $this->assertNotEmpty($contact['company_id']);

        $company = $this->companies->getById((int) $contact['company_id']);
        $this->assertNotNull($company);
        $this->assertSame('Linked Company', $company['name']);

        $this->contacts->delete($result['id']);
    }

    public function testCreateContactWithPublicEmailStillLinksManualCompany(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Personal',
            'email' => 'personal.contact@gmail.com',
            'company' => 'Studio Infinity',
        ]);

        $contact = $this->contacts->getById($result['id']);
        $this->assertNotNull($contact);
        $this->assertSame('Studio Infinity', (string) ($contact['company'] ?? ''));
        $this->assertNotEmpty($contact['company_id']);

        $company = $this->companies->getById((int) $contact['company_id']);
        $this->assertNotNull($company);
        $this->assertSame('Studio Infinity', $company['name']);

        $this->contacts->delete($result['id']);
    }

    public function testCreateContactDoesNotCreateContactNotesByDefault(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Plain',
            'email' => 'plain-contact@example.com',
        ]);

        $noteCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM notes WHERE entity_type = 'contact' AND entity_id = ?",
            [$result['id']]
        )['c'] ?? 0);

        $this->assertSame(0, $noteCount);

        $this->contacts->delete($result['id']);
    }
    
    public function testCreateContactMissingRequiredField(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required field');
        
        $data = [
            'last_name' => 'Doe',
            'email' => 'test@example.com'
        ];
        
        $this->contacts->create($data);
    }

    public function testCreateContactRejectsBlankFirstNameAfterSanitizing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('First name is required');

        $this->contacts->create([
            'first_name' => '   ',
            'email' => 'blank-first-name@example.com',
        ]);
    }
    
    public function testCreateContactInvalidEmail(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid email address');
        
        $data = [
            'first_name' => 'John',
            'email' => 'invalid-email'
        ];
        
        $this->contacts->create($data);
    }
    
    public function testCreateContactDuplicateEmail(): void
    {
        $data = [
            'first_name' => 'John',
            'email' => 'duplicate@example.com',
            'phone' => '+1234567890'
        ];
        
        // Create first contact
        $result1 = $this->contacts->create($data);
        $this->assertEquals('success', $result1['status']);
        
        // Try to create duplicate
        $result2 = $this->contacts->create($data);
        $this->assertEquals('duplicate', $result2['status']);
        $this->assertArrayHasKey('matches', $result2);
        
        // Clean up
        $this->contacts->delete($result1['id']);
    }
    
    public function testGetContactById(): void
    {
        $data = [
            'first_name' => 'Jane',
            'email' => 'jane@example.com'
        ];
        
        $result = $this->contacts->create($data);
        $contact = $this->contacts->getById($result['id']);
        
        $this->assertNotNull($contact);
        $this->assertEquals('Jane', $contact['first_name']);
        $this->assertEquals('jane@example.com', $contact['email']);
        
        // Clean up
        $this->contacts->delete($result['id']);
    }
    
    public function testUpdateContact(): void
    {
        $data = [
            'first_name' => 'Original',
            'email' => 'original@example.com'
        ];
        
        $result = $this->contacts->create($data);
        $contactId = $result['id'];
        
        $updateData = [
            'first_name' => 'Updated',
            'last_name' => 'Name'
        ];
        
        $updated = $this->contacts->update($contactId, $updateData);
        $this->assertTrue($updated);
        
        $contact = $this->contacts->getById($contactId);
        $this->assertEquals('Updated', $contact['first_name']);
        $this->assertEquals('Name', $contact['last_name']);
        
        // Clean up
        $this->contacts->delete($contactId);
    }

    public function testUpdateContactRejectsBlankFirstNameAfterSanitizing(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Original',
            'email' => 'blank-update@example.com',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('First name is required');

        try {
            $this->contacts->update((int) $result['id'], ['first_name' => '   ']);
        } finally {
            $this->contacts->delete((int) $result['id']);
        }
    }

    public function testUpdateContactRelinksCompany(): void
    {
        $firstCompanyId = $this->companies->create(['name' => 'Alpha Co']);
        $secondCompanyId = $this->companies->create(['name' => 'Beta Co']);

        $result = $this->contacts->create([
            'first_name' => 'Relink',
            'email' => 'relink@example.com',
            'company' => 'Alpha Co',
            'company_id' => $firstCompanyId,
        ]);

        $this->contacts->update($result['id'], [
            'company' => 'Beta Co',
            'company_id' => $secondCompanyId,
        ]);

        $contact = $this->contacts->getById($result['id']);
        $this->assertSame('Beta Co', $contact['company']);
        $this->assertSame($secondCompanyId, (int) $contact['company_id']);

        $this->contacts->delete($result['id']);
    }
    
    public function testDeleteContact(): void
    {
        $data = [
            'first_name' => 'Delete',
            'email' => 'delete@example.com'
        ];
        
        $result = $this->contacts->create($data);
        $contactId = $result['id'];
        
        $deleted = $this->contacts->delete($contactId);
        $this->assertTrue($deleted);
        
        $contact = $this->contacts->getById($contactId);
        $this->assertNull($contact);
    }

    public function testBulkUpdateRejectsInvalidStage(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Bulk',
            'email' => 'bulk-invalid-stage@example.com',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contact stage');

        try {
            $this->contacts->bulkUpdate([(int) $result['id']], ['stage' => 'not_a_stage']);
        } finally {
            $this->contacts->delete((int) $result['id']);
        }
    }

    public function testBulkUpdateRejectsInvalidLeadSource(): void
    {
        $result = $this->contacts->create([
            'first_name' => 'Bulk',
            'email' => 'bulk-invalid-source@example.com',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lead source');

        try {
            $this->contacts->bulkUpdate([(int) $result['id']], ['lead_source' => 'not_a_source']);
        } finally {
            $this->contacts->delete((int) $result['id']);
        }
    }
    
    public function testSearchContacts(): void
    {
        // Create test contacts
        $contact1 = $this->contacts->create([
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'company' => 'Company A'
        ]);
        
        $contact2 = $this->contacts->create([
            'first_name' => 'Bob',
            'email' => 'bob@example.com',
            'company' => 'Company B'
        ]);
        
        // Search by name
        $results = $this->contacts->search('Alice');
        $this->assertGreaterThan(0, count($results));
        
        // Search by company
        $results = $this->contacts->search('Company');
        $this->assertGreaterThanOrEqual(2, count($results));
        
        // Clean up
        $this->contacts->delete($contact1['id']);
        $this->contacts->delete($contact2['id']);
    }

    public function testSelectableContactsFilterByChannelAndExcludeDeletedContacts(): void
    {
        $emailOnly = $this->contacts->create([
            'first_name' => 'Email',
            'email' => 'channel-email@example.com',
        ]);
        $phoneOnly = $this->contacts->create([
            'first_name' => 'Phone',
            'email' => 'channel-phone@example.com',
            'phone' => '+15550000001',
        ]);
        $both = $this->contacts->create([
            'first_name' => 'Both',
            'email' => 'channel-both@example.com',
            'phone' => '+15550000002',
        ]);

        $emailContacts = $this->contacts->getSelectableContactsForChannel('email');
        $whatsAppContacts = $this->contacts->getSelectableContactsForChannel('whatsapp');

        $emailIds = array_map(static fn (array $contact): int => (int) $contact['id'], $emailContacts);
        $whatsAppIds = array_map(static fn (array $contact): int => (int) $contact['id'], $whatsAppContacts);

        $this->assertContains((int) $emailOnly['id'], $emailIds);
        $this->assertContains((int) $phoneOnly['id'], $emailIds);
        $this->assertContains((int) $both['id'], $emailIds);

        $this->assertContains((int) $phoneOnly['id'], $whatsAppIds);
        $this->assertContains((int) $both['id'], $whatsAppIds);
        $this->assertNotContains((int) $emailOnly['id'], $whatsAppIds);

        $this->contacts->delete($both['id']);

        $emailContactsAfterDelete = $this->contacts->getSelectableContactsForChannel('email');
        $whatsAppContactsAfterDelete = $this->contacts->getSelectableContactsForChannel('whatsapp');

        $this->assertNotContains((int) $both['id'], array_map(static fn (array $contact): int => (int) $contact['id'], $emailContactsAfterDelete));
        $this->assertNotContains((int) $both['id'], array_map(static fn (array $contact): int => (int) $contact['id'], $whatsAppContactsAfterDelete));

        $this->contacts->delete($emailOnly['id']);
        $this->contacts->delete($phoneOnly['id']);
    }

    public function testCountCreatedSinceHonorsWorkspaceAndOwnerScope(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('count-since-user-', true), 'count-since-user@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('count-since-other-', true), 'count-since-other@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $otherUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        $rows = [
            [1, 'Visible Assigned', 'visible-assigned@example.com', $userId, '2026-05-19 10:00:00'],
            [1, 'Visible Unassigned', 'visible-unassigned@example.com', null, '2026-05-19 10:05:00'],
            [1, 'Hidden Assigned', 'hidden-assigned@example.com', $otherUserId, '2026-05-19 10:10:00'],
            [1, 'Old Visible', 'old-visible@example.com', $userId, '2026-05-19 08:00:00'],
            [2, 'Foreign Workspace', 'foreign-workspace@example.com', null, '2026-05-19 10:15:00'],
        ];

        foreach ($rows as [$workspaceId, $firstName, $email, $assignedTo, $createdAt]) {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, email, assigned_to, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [(int) $workspaceId, uniqid('count-since-contact-', true), $firstName, $email, $assignedTo, $createdAt]
            );
        }

        $this->assertSame(
            2,
            $this->contacts->countCreatedSince('2026-05-19 09:00:00', 'mine_unassigned', $userId)
        );
        $this->assertSame(
            3,
            $this->contacts->countCreatedSince('2026-05-19 09:00:00', 'all', $userId)
        );
        $this->assertSame(0, $this->contacts->countCreatedSince(null, 'mine_unassigned', $userId));
        $this->assertSame(0, $this->contacts->countCreatedSince('not a date', 'mine_unassigned', $userId));
    }

    public function testGetByIdReturnsNullForAnotherWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, created_at)
             VALUES (2, ?, 'Outside', 'outside@example.com', NOW())",
            [uniqid('contact_', true)]
        );
        $foreignContactId = (int) Database::lastInsertId();

        $this->assertNull($this->contacts->getById($foreignContactId));
    }

    public function testDuplicateDetectionIsWorkspaceLocal(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, created_at)
             VALUES (2, ?, 'Outside', 'shared@example.com', NOW())",
            [uniqid('contact_', true)]
        );

        $result = $this->contacts->create([
            'first_name' => 'Inside',
            'email' => 'shared@example.com',
        ]);

        $this->assertSame('success', $result['status']);
        $this->contacts->delete($result['id']);
    }
}
