<?php
/**
 * Notes Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Notes;
use CRM\Database;

class NotesTest extends DatabaseTestCase
{
    private Notes $notes;
    private int $testUserId;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = new Notes();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (first_name, last_name, email, created_at) 
             VALUES (?, ?, ?, NOW())",
            ['John', 'Doe', 'john@example.com']
        );
        $this->testContactId = (int) Database::lastInsertId();
    }
    
    public function testCreateNote()
    {
        $id = $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $this->testContactId,
            'content' => 'Test note content',
            'title' => 'Test Note',
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $note = Database::queryOne("SELECT * FROM notes WHERE id = ?", [$id]);
        $this->assertEquals('contact', $note['entity_type']);
        $this->assertEquals($this->testContactId, $note['entity_id']);
    }
    
    public function testCreateNoteRequiresFields()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Note content, entity type, and entity ID are required");
        
        $this->notes->create([]);
    }
    
    public function testGetNoteById()
    {
        $id = $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $this->testContactId,
            'content' => 'Test content',
            'created_by' => $this->testUserId
        ]);
        
        $note = $this->notes->getById($id);
        
        $this->assertIsArray($note);
        $this->assertEquals('contact', $note['entity_type']);
    }
    
    public function testGetRecent()
    {
        $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $this->testContactId,
            'content' => 'Recent note',
            'created_by' => $this->testUserId
        ]);
        
        $notes = $this->notes->getRecent(5, $this->testUserId);
        
        $this->assertIsArray($notes);
        $this->assertGreaterThanOrEqual(1, count($notes));
    }
}
