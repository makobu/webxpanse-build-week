<?php
/**
 * Notification Preferences Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\NotificationPreferences;
use CRM\Database;
use CRM\Auth;

class NotificationPreferencesTest extends DatabaseTestCase
{
    private NotificationPreferences $preferences;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->preferences = new NotificationPreferences();
        
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
        Database::execute("DELETE FROM notification_preferences WHERE user_id = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        unset($_SESSION['user_id']);
        parent::tearDown();
    }
    
    public function testUpdatePreferences(): void
    {
        $prefs = [
            'contact_created' => ['email_enabled' => true, 'in_app_enabled' => true],
            'task_assigned' => ['email_enabled' => false, 'in_app_enabled' => true]
        ];
        
        $result = $this->preferences->updatePreferences($this->testUserId, $prefs);
        $this->assertTrue($result);
        
        // Verify it was saved
        $pref = $this->preferences->getPreference($this->testUserId, 'contact_created');
        $this->assertTrue($pref['email_enabled']);
        $this->assertTrue($pref['in_app_enabled']);
    }
    
    public function testGetPreference(): void
    {
        // Set a preference first
        $this->preferences->updatePreferences($this->testUserId, [
            'task_assigned' => ['email_enabled' => true, 'in_app_enabled' => false]
        ]);
        
        $pref = $this->preferences->getPreference($this->testUserId, 'task_assigned');
        
        $this->assertIsArray($pref);
        $this->assertTrue($pref['email_enabled']);
        $this->assertFalse($pref['in_app_enabled']);
    }
    
    public function testGetPreferenceNotFound(): void
    {
        $pref = $this->preferences->getPreference($this->testUserId, 'non_existent');
        
        $this->assertIsArray($pref);
        $this->assertTrue($pref['email_enabled']); // Defaults
        $this->assertTrue($pref['in_app_enabled']); // Defaults
    }
    
    public function testGetUserPreferences(): void
    {
        // Set multiple preferences
        $this->preferences->updatePreferences($this->testUserId, [
            'contact_created' => ['email_enabled' => true, 'in_app_enabled' => true],
            'task_assigned' => ['email_enabled' => true, 'in_app_enabled' => false],
            'email_received' => ['email_enabled' => false, 'in_app_enabled' => true]
        ]);
        
        $allPrefs = $this->preferences->getUserPreferences($this->testUserId);
        
        $this->assertIsArray($allPrefs);
        $this->assertArrayHasKey('contact_created', $allPrefs);
        $this->assertArrayHasKey('task_assigned', $allPrefs);
        $this->assertArrayHasKey('email_received', $allPrefs);
        $this->assertTrue($allPrefs['contact_created']['email_enabled']);
        $this->assertFalse($allPrefs['email_received']['email_enabled']);
    }
    
    public function testIsEmailEnabled(): void
    {
        $this->preferences->updatePreferences($this->testUserId, [
            'contact_created' => ['email_enabled' => true, 'in_app_enabled' => true]
        ]);
        
        $this->assertTrue($this->preferences->isEmailEnabled($this->testUserId, 'contact_created'));
        
        $this->preferences->updatePreferences($this->testUserId, [
            'task_assigned' => ['email_enabled' => false, 'in_app_enabled' => true]
        ]);
        
        $this->assertFalse($this->preferences->isEmailEnabled($this->testUserId, 'task_assigned'));
    }
    
    public function testIsInAppEnabled(): void
    {
        $this->preferences->updatePreferences($this->testUserId, [
            'contact_created' => ['email_enabled' => true, 'in_app_enabled' => true]
        ]);
        
        $this->assertTrue($this->preferences->isInAppEnabled($this->testUserId, 'contact_created'));
        
        $this->preferences->updatePreferences($this->testUserId, [
            'task_assigned' => ['email_enabled' => true, 'in_app_enabled' => false]
        ]);
        
        $this->assertFalse($this->preferences->isInAppEnabled($this->testUserId, 'task_assigned'));
    }
}
