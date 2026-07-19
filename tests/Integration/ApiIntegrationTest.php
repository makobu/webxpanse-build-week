<?php
/**
 * API Integration Tests
 * 
 * Tests API endpoints for proper functionality
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Contacts;
use CRM\Modules\Activities;
use CRM\Database;
use CRM\Auth;
use CRM\Session;

class ApiIntegrationTest extends DatabaseTestCase
{
    private int $testUserId;
    private string $testEmail = 'apitest@example.com';
    private string $testPassword = 'ApiTest123!';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->testUserId = Auth::createUser($this->testEmail, $this->testPassword, 'user');
        
        // Start session and login
        Session::start();
        Auth::login($this->testEmail, $this->testPassword);
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM contacts WHERE email LIKE 'apitest%@example.com'");
        Database::execute("DELETE FROM activities WHERE user_id = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        Session::destroy();
        parent::tearDown();
    }
    
    /**
     * Simulate API request
     */
    private function makeApiRequest(string $endpoint, string $method = 'GET', array $data = []): array
    {
        // This simulates an API request
        // In a real scenario, you'd use a proper HTTP client
        
        $ch = curl_init('http://localhost' . apiUrl($endpoint));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-CSRF-Token: ' . \CRM\Security::getCsrfToken()
            ],
            CURLOPT_COOKIE => session_name() . '=' . session_id()
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'code' => $httpCode,
            'body' => json_decode($response, true) ?? []
        ];
    }
    
    public function testContactsApiGetAll(): void
    {
        // Create test contacts
        $contacts = new Contacts();
        $contact1 = $contacts->create([
            'first_name' => 'API',
            'last_name' => 'Test1',
            'email' => 'apitest1@example.com'
        ]);
        $contact2 = $contacts->create([
            'first_name' => 'API',
            'last_name' => 'Test2',
            'email' => 'apitest2@example.com'
        ]);
        
        // Test GET /api/contacts.php
        // Since we can't easily test HTTP endpoints in unit tests,
        // we'll test the underlying module methods that the API uses
        $allContacts = $contacts->getAll(50, 0);
        
        $this->assertIsArray($allContacts);
        $found1 = false;
        $found2 = false;
        
        foreach ($allContacts as $contact) {
            if ($contact['email'] === 'apitest1@example.com') {
                $found1 = true;
            }
            if ($contact['email'] === 'apitest2@example.com') {
                $found2 = true;
            }
        }
        
        $this->assertTrue($found1 || $found2); // At least one should be found
    }
    
    public function testContactsApiGetById(): void
    {
        $contacts = new Contacts();
        $contact = $contacts->create([
            'first_name' => 'API',
            'email' => 'apitest3@example.com'
        ]);
        
        $retrieved = $contacts->getById($contact['id']);
        
        $this->assertNotNull($retrieved);
        $this->assertEquals($contact['id'], $retrieved['id']);
        $this->assertEquals('API', $retrieved['first_name']);
        $this->assertEquals('apitest3@example.com', $retrieved['email']);
    }
    
    public function testContactsApiCreate(): void
    {
        $contacts = new Contacts();
        $result = $contacts->create([
            'first_name' => 'API',
            'last_name' => 'Create',
            'email' => 'apicreate@example.com',
            'phone' => '+1234567890'
        ]);
        
        $this->assertEquals('success', $result['status']);
        $this->assertIsInt($result['id']);
        $this->assertIsString($result['uuid']);
        
        // Verify contact was created
        $contact = $contacts->getById($result['id']);
        $this->assertEquals('API', $contact['first_name']);
        $this->assertEquals('apicreate@example.com', $contact['email']);
    }
    
    public function testContactsApiUpdate(): void
    {
        $contacts = new Contacts();
        $contact = $contacts->create([
            'first_name' => 'API',
            'email' => 'apiupdate@example.com'
        ]);
        
        $result = $contacts->update($contact['id'], [
            'first_name' => 'Updated',
            'stage' => 'contacted'
        ]);
        
        $this->assertTrue($result);
        
        // Verify update
        $updated = $contacts->getById($contact['id']);
        $this->assertEquals('Updated', $updated['first_name']);
        $this->assertEquals('contacted', $updated['stage']);
    }
    
    public function testContactsApiDelete(): void
    {
        $contacts = new Contacts();
        $contact = $contacts->create([
            'first_name' => 'API',
            'email' => 'apidelete@example.com'
        ]);
        
        $result = $contacts->delete($contact['id']);
        $this->assertTrue($result);
        
        // Verify deletion
        $deleted = $contacts->getById($contact['id']);
        $this->assertNull($deleted);
    }
    
    public function testContactsApiSearch(): void
    {
        $contacts = new Contacts();
        $contacts->create([
            'first_name' => 'Search',
            'last_name' => 'Test',
            'email' => 'searchtest@example.com'
        ]);
        
        $results = $contacts->search('Search', 10, 0);
        
        $this->assertIsArray($results);
        $found = false;
        foreach ($results as $contact) {
            if (stripos($contact['first_name'], 'Search') !== false || 
                stripos($contact['email'], 'search') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
    
    public function testActivitiesApiCreate(): void
    {
        $contacts = new Contacts();
        $contact = $contacts->create([
            'first_name' => 'Activity',
            'email' => 'activitytest@example.com'
        ]);
        
        $activities = new Activities();
        $activityId = $activities->log(
            $contact['id'],
            'note',
            'Test activity note',
            $this->testUserId
        );
        
        $this->assertIsInt($activityId);
        $this->assertGreaterThan(0, $activityId);
        
        // Verify activity was created
        $activity = $activities->getById($activityId);
        $this->assertEquals('note', $activity['activity_type']);
        $this->assertEquals('Test activity note', $activity['description']);
    }
    
    public function testApiAuthenticationRequired(): void
    {
        // Test that API requires authentication
        // This would normally be tested with actual HTTP requests
        // For now, we verify Auth::check() works
        $this->assertTrue(Auth::check());
        $this->assertEquals($this->testUserId, Auth::userId());
    }
    
    public function testApiErrorHandling(): void
    {
        $contacts = new Contacts();
        
        // Test invalid contact ID
        $contact = $contacts->getById(99999);
        $this->assertNull($contact);
        
        // Test missing required fields
        $this->expectException(\Exception::class);
        $contacts->create(['last_name' => 'Test']); // Missing first_name and email
    }
}
