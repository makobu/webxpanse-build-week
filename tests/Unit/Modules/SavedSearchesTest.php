<?php
/**
 * Saved Searches Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\SavedSearches;
use CRM\Database;
use CRM\Auth;

class SavedSearchesTest extends DatabaseTestCase
{
    private SavedSearches $savedSearches;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = new SavedSearches();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Set session for Auth
        $_SESSION['user_id'] = $this->testUserId;
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM saved_searches WHERE user_id = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        unset($_SESSION['user_id']);
        parent::tearDown();
    }
    
    public function testCreateSavedSearch(): void
    {
        $data = [
            'name' => 'My Contacts',
            'entity_type' => 'contacts',
            'search_query' => 'john',
            'filters' => ['stage' => 'new']
        ];
        
        $id = $this->savedSearches->create($data);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        // Verify it was created
        $search = $this->savedSearches->getById($id);
        $this->assertEquals('My Contacts', $search['name']);
        $this->assertEquals('contacts', $search['entity_type']);
    }
    
    public function testCreateSavedSearchMissingRequiredFields(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Name and entity type are required');
        
        $this->savedSearches->create(['name' => 'Test']);
    }

    public function testCreateRejectsInvalidEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid saved search entity type');

        $this->savedSearches->create([
            'name' => 'Bad Search',
            'entity_type' => 'javascript:alert(1)',
        ]);
    }

    public function testCreateRejectsInvalidFilterJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Saved search filters must be valid JSON');

        $this->savedSearches->create([
            'name' => 'Bad Filter Search',
            'entity_type' => 'contacts',
            'filters' => '{not-json',
        ]);
    }
    
    public function testGetById(): void
    {
        $data = [
            'name' => 'Test Search',
            'entity_type' => 'contacts',
            'search_query' => 'test'
        ];
        
        $id = $this->savedSearches->create($data);
        $search = $this->savedSearches->getById($id);
        
        $this->assertIsArray($search);
        $this->assertEquals($id, $search['id']);
        $this->assertEquals('Test Search', $search['name']);
        $this->assertEquals('contacts', $search['entity_type']);
    }
    
    public function testGetByIdNotFound(): void
    {
        $search = $this->savedSearches->getById(99999);
        $this->assertNull($search);
    }
    
    public function testGetUserSearches(): void
    {
        // Create multiple searches
        $this->savedSearches->create([
            'name' => 'Contacts Search',
            'entity_type' => 'contacts'
        ]);
        $this->savedSearches->create([
            'name' => 'Tasks Search',
            'entity_type' => 'tasks'
        ]);
        $this->savedSearches->create([
            'name' => 'Another Contacts Search',
            'entity_type' => 'contacts'
        ]);
        
        // Get all searches
        $allSearches = $this->savedSearches->getUserSearches();
        $this->assertCount(3, $allSearches);
        
        // Get contacts searches only
        $contactsSearches = $this->savedSearches->getUserSearches('contacts');
        $this->assertCount(2, $contactsSearches);
    }
    
    public function testGetUserSearchesWithFilters(): void
    {
        // Create searches with filters
        $id1 = $this->savedSearches->create([
            'name' => 'Contacts Search',
            'entity_type' => 'contacts',
            'filters' => ['stage' => 'new']
        ]);
        
        $id2 = $this->savedSearches->create([
            'name' => 'Tasks Search',
            'entity_type' => 'tasks',
            'filters' => ['status' => 'pending']
        ]);
        
        // Get all
        $all = $this->savedSearches->getUserSearches();
        $this->assertCount(2, $all);
        
        // Get contacts only
        $contacts = $this->savedSearches->getUserSearches('contacts');
        $this->assertCount(1, $contacts);
        $this->assertEquals('Contacts Search', $contacts[0]['name']);
    }
    
    public function testDeleteSavedSearch(): void
    {
        $id = $this->savedSearches->create([
            'name' => 'To Delete',
            'entity_type' => 'contacts'
        ]);
        
        $result = $this->savedSearches->delete($id);
        $this->assertTrue($result);
        
        // Verify deletion
        $search = $this->savedSearches->getById($id);
        $this->assertNull($search);
    }
    
    public function testDeleteSavedSearchNotFound(): void
    {
        $result = $this->savedSearches->delete(99999);
        $this->assertFalse($result);
    }
}
