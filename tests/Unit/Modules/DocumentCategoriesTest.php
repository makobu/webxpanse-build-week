<?php
/**
 * Document Categories Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\DocumentCategories;
use CRM\Database;

class DocumentCategoriesTest extends DatabaseTestCase
{
    private DocumentCategories $categories;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->categories = new DocumentCategories();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM document_categories WHERE created_by = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        parent::tearDown();
    }
    
    public function testCreateCategory(): void
    {
        $data = [
            'name' => 'Contracts',
            'description' => 'Legal contracts',
            'color' => '#FF5733',
            'created_by' => $this->testUserId
        ];
        
        $id = $this->categories->create($data);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        // Verify it was created
        $category = $this->categories->getById($id);
        $this->assertEquals('Contracts', $category['name']);
        $this->assertEquals('#FF5733', $category['color']);
    }
    
    public function testCreateCategoryMissingName(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Category name is required');
        
        $this->categories->create(['description' => 'Test']);
    }
    
    public function testCreateCategoryWithDefaultColor(): void
    {
        $id = $this->categories->create([
            'name' => 'Test Category',
            'created_by' => $this->testUserId
        ]);
        
        $category = $this->categories->getById($id);
        $this->assertEquals('#3B82F6', $category['color']); // Default color
    }
    
    public function testCreateCategoryWithInvalidColor(): void
    {
        $id = $this->categories->create([
            'name' => 'Test',
            'color' => 'invalid-color',
            'created_by' => $this->testUserId
        ]);
        
        // Should default to valid color
        $category = $this->categories->getById($id);
        $this->assertEquals('#3B82F6', $category['color']);
    }
    
    public function testGetById(): void
    {
        $id = $this->categories->create([
            'name' => 'Test Category',
            'created_by' => $this->testUserId
        ]);
        
        $category = $this->categories->getById($id);
        
        $this->assertIsArray($category);
        $this->assertEquals($id, $category['id']);
        $this->assertEquals('Test Category', $category['name']);
        $this->assertArrayHasKey('document_count', $category);
    }
    
    public function testGetByIdNotFound(): void
    {
        $category = $this->categories->getById(99999);
        $this->assertNull($category);
    }
    
    public function testGetAll(): void
    {
        // Create multiple categories
        $this->categories->create(['name' => 'Category 1', 'created_by' => $this->testUserId]);
        $this->categories->create(['name' => 'Category 2', 'created_by' => $this->testUserId]);
        $this->categories->create(['name' => 'Category 3', 'created_by' => $this->testUserId]);
        
        $all = $this->categories->getAll();
        
        $this->assertIsArray($all);
        $this->assertGreaterThanOrEqual(3, count($all));
    }
    
    public function testUpdateCategory(): void
    {
        $id = $this->categories->create([
            'name' => 'Original Name',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->categories->update($id, [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'color' => '#00FF00'
        ]);
        
        $this->assertTrue($result);
        
        // Verify update
        $category = $this->categories->getById($id);
        $this->assertEquals('Updated Name', $category['name']);
        $this->assertEquals('Updated description', $category['description']);
        $this->assertEquals('#00FF00', $category['color']);
    }
    
    public function testDeleteCategory(): void
    {
        $id = $this->categories->create([
            'name' => 'To Delete',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->categories->delete($id);
        $this->assertTrue($result);
        
        // Verify deletion
        $category = $this->categories->getById($id);
        $this->assertNull($category);
    }
    
    public function testDeleteCategoryNotFound(): void
    {
        $result = $this->categories->delete(99999);
        $this->assertFalse($result);
    }
}
