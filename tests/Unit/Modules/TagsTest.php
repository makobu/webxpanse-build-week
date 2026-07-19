<?php
/**
 * Tags Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Tags;
use CRM\Database;

class TagsTest extends DatabaseTestCase
{
    private Tags $tags;
    private int $testUserId;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tags = new Tags();
        
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
    
    public function testCreateTag()
    {
        $id = $this->tags->create([
            'name' => 'Test Tag',
            'color' => '#3c3',
            'description' => 'Test description',
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $tag = Database::queryOne("SELECT * FROM tags WHERE id = ?", [$id]);
        $this->assertEquals('Test Tag', $tag['name']);
        $this->assertEquals('#33cc33', $tag['color']);
    }

    public function testCreateRejectsNameLongerThanDatabaseColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag name must be 100 characters or fewer');

        $this->tags->create([
            'name' => str_repeat('x', 101),
            'created_by' => $this->testUserId,
        ]);
    }
    
    public function testCreateTagRequiresName()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Tag name is required");
        
        $this->tags->create([]);
    }
    
    public function testGetTagById()
    {
        $id = $this->tags->create([
            'name' => 'Test Tag',
            'created_by' => $this->testUserId
        ]);
        
        $tag = $this->tags->getById($id);
        
        $this->assertIsArray($tag);
        $this->assertEquals('Test Tag', $tag['name']);
    }
    
    public function testAssignTagToEntity()
    {
        $tagId = $this->tags->create([
            'name' => 'VIP',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->tags->assign($tagId, 'contact', $this->testContactId);
        
        $this->assertTrue($result);
        
        $assignment = Database::queryOne(
            "SELECT * FROM tag_assignments WHERE tag_id = ? AND entity_type = ? AND entity_id = ?",
            [$tagId, 'contact', $this->testContactId]
        );
        $this->assertNotNull($assignment);
    }

    public function testUpdateTagChangesStoredValues(): void
    {
        $tagId = $this->tags->create([
            'name' => 'Original Tag',
            'color' => '#3366cc',
            'created_by' => $this->testUserId
        ]);

        $updated = $this->tags->update($tagId, [
            'name' => 'Updated Tag',
            'description' => 'Updated description',
            'color' => '#cc3333',
        ]);

        $this->assertTrue($updated);

        $tag = $this->tags->getById($tagId);
        $this->assertSame('Updated Tag', $tag['name']);
        $this->assertSame('Updated description', $tag['description']);
        $this->assertSame('#cc3333', $tag['color']);
    }

    public function testDeleteTagRemovesAssignments(): void
    {
        $tagId = $this->tags->create([
            'name' => 'Delete Me',
            'created_by' => $this->testUserId
        ]);
        $this->tags->assign($tagId, 'contact', $this->testContactId);

        $deleted = $this->tags->delete($tagId);

        $this->assertTrue($deleted);
        $this->assertNull(Database::queryOne("SELECT id FROM tags WHERE id = ?", [$tagId]));
        $this->assertNull(Database::queryOne("SELECT id FROM tag_assignments WHERE tag_id = ?", [$tagId]));
    }

    public function testGetByIdNormalizesLegacyShortHexColor(): void
    {
        Database::execute(
            "INSERT INTO tags (name, color, description, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
            ['Legacy Tag', '#3c3', '', $this->testUserId]
        );

        $tagId = (int) Database::lastInsertId();
        $tag = $this->tags->getById($tagId);

        $this->assertSame('#33cc33', $tag['color']);
    }

    public function testUpdateTagRequiresNonEmptyName(): void
    {
        $tagId = $this->tags->create([
            'name' => 'Needs Name',
            'created_by' => $this->testUserId
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tag name is required');

        $this->tags->update($tagId, [
            'name' => '   ',
        ]);
    }
}
