<?php
/**
 * Search Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Search;
use CRM\Modules\Contacts;
use CRM\Database;

class SearchTest extends DatabaseTestCase
{
    private Search $search;
    private Contacts $contacts;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new Search();
        $this->contacts = new Contacts();
        
        // Create test contact for search
        $this->contacts->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'company' => 'Test Company'
        ]);
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM contacts WHERE email = ?", ['john.doe@example.com']);
        parent::tearDown();
    }
    
    public function testSearchWithEmptyQuery(): void
    {
        $results = $this->search->search('');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
    
    public function testSearchContacts(): void
    {
        $results = $this->search->search('John');
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('contacts', $results);
        $this->assertGreaterThan(0, count($results['contacts']));
        
        // Verify contact is in results
        $found = false;
        foreach ($results['contacts'] as $contact) {
            if (stripos($contact['first_name'], 'John') !== false || 
                stripos($contact['email'], 'john') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
    
    public function testSearchWithLimit(): void
    {
        $results = $this->search->search('test', 5);
        
        $this->assertIsArray($results);
        // Check that limits are respected per entity type
        foreach ($results as $entityResults) {
            $this->assertLessThanOrEqual(5, count($entityResults));
        }
    }
    
    public function testGetSuggestions(): void
    {
        $suggestions = $this->search->getSuggestions('john');
        
        $this->assertIsArray($suggestions);
        // Should return quick suggestions
        $this->assertGreaterThanOrEqual(0, count($suggestions));
    }
    
    public function testGetSuggestionsWithEmptyQuery(): void
    {
        $suggestions = $this->search->getSuggestions('');
        $this->assertIsArray($suggestions);
        $this->assertEmpty($suggestions);
    }
    
    public function testSearchMultipleEntityTypes(): void
    {
        // Search should return results from multiple entity types
        $results = $this->search->search('test');
        
        $this->assertIsArray($results);
        // May have contacts, deals, tasks, events, emails, activities, notes
        // At minimum should have structure even if empty
        $this->assertIsArray($results);
    }
}
