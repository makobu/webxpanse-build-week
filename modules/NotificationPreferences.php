<?php
/**
 * Notification Preferences Module
 * 
 * Handles user notification preferences
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Auth;

class NotificationPreferences
{
    /**
     * Get user preferences
     */
    public function getUserPreferences(int $userId): array
    {
        $preferences = Database::query(
            "SELECT * FROM notification_preferences WHERE user_id = ?",
            [$userId]
        );
        
        $result = [];
        foreach ($preferences as $pref) {
            $result[$pref['notification_type']] = [
                'email_enabled' => (bool) $pref['email_enabled'],
                'in_app_enabled' => (bool) $pref['in_app_enabled']
            ];
        }
        
        return $result;
    }
    
    /**
     * Get preference for specific notification type
     */
    public function getPreference(int $userId, string $notificationType): array
    {
        $pref = Database::queryOne(
            "SELECT * FROM notification_preferences WHERE user_id = ? AND notification_type = ?",
            [$userId, $notificationType]
        );
        
        if ($pref) {
            return [
                'email_enabled' => (bool) $pref['email_enabled'],
                'in_app_enabled' => (bool) $pref['in_app_enabled']
            ];
        }
        
        // Default: both enabled
        return [
            'email_enabled' => true,
            'in_app_enabled' => true
        ];
    }
    
    /**
     * Update preferences
     */
    public function updatePreferences(int $userId, array $preferences): bool
    {
        foreach ($preferences as $type => $settings) {
            $emailEnabled = isset($settings['email_enabled']) ? (int) $settings['email_enabled'] : 1;
            $inAppEnabled = isset($settings['in_app_enabled']) ? (int) $settings['in_app_enabled'] : 1;
            
            Database::execute(
                "INSERT INTO notification_preferences (user_id, notification_type, email_enabled, in_app_enabled)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    email_enabled = VALUES(email_enabled),
                    in_app_enabled = VALUES(in_app_enabled),
                    updated_at = NOW()",
                [$userId, $type, $emailEnabled, $inAppEnabled]
            );
        }
        
        return true;
    }
    
    /**
     * Check if email notification is enabled for user and type
     */
    public function isEmailEnabled(int $userId, string $notificationType): bool
    {
        $pref = $this->getPreference($userId, $notificationType);
        return $pref['email_enabled'];
    }
    
    /**
     * Check if in-app notification is enabled for user and type
     */
    public function isInAppEnabled(int $userId, string $notificationType): bool
    {
        $pref = $this->getPreference($userId, $notificationType);
        return $pref['in_app_enabled'];
    }
}
