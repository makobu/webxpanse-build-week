<?php
/**
 * Activity Timeline Module
 * 
 * Renders activity timeline for contacts
 */

namespace CRM\Modules;

use CRM\Modules\Activities;

class ActivityTimeline
{
    private Activities $activities;
    
    public function __construct()
    {
        $this->activities = new Activities();
    }
    
    /**
     * Get timeline data for a contact
     */
    public function getTimeline(int $contactId, int $limit = 50): array
    {
        $activities = $this->activities->getByContact($contactId, $limit);
        
        return array_map(function($activity) {
            return $this->formatActivity($activity);
        }, $activities);
    }
    
    /**
     * Format activity for display
     */
    private function formatActivity(array $activity): array
    {
        $metadata = !empty($activity['metadata']) ? json_decode($activity['metadata'], true) : [];
        
        return [
            'id' => $activity['id'],
            'type' => $activity['activity_type'],
            'description' => $activity['description'] ?? $this->getDefaultDescription($activity['activity_type']),
            'user' => $activity['user_email'] ?? 'System',
            'contact' => [
                'name' => trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')),
                'email' => $activity['contact_email'] ?? ''
            ],
            'metadata' => $metadata,
            'created_at' => $activity['created_at'],
            'formatted_date' => $this->formatDate($activity['created_at'])
        ];
    }
    
    /**
     * Get default description for activity type
     */
    private function getDefaultDescription(string $activityType): string
    {
        $descriptions = [
            'email' => 'Email sent',
            'call' => 'Phone call made',
            'note' => 'Note added',
            'meeting' => 'Meeting scheduled',
            'status_change' => 'Status changed',
            'form_submit' => 'Form submitted',
            'email_opened' => 'Email opened',
            'link_clicked' => 'Link clicked',
            'page_visited' => 'Page visited'
        ];
        
        return $descriptions[$activityType] ?? Activities::formatTypeLabel($activityType) . ' logged';
    }
    
    /**
     * Format date for display
     */
    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        $now = time();
        $diff = $now - $timestamp;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return "$minutes minute" . ($minutes > 1 ? 's' : '') . " ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "$hours hour" . ($hours > 1 ? 's' : '') . " ago";
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return "$days day" . ($days > 1 ? 's' : '') . " ago";
        } else {
            return date('M j, Y', $timestamp);
        }
    }
}
