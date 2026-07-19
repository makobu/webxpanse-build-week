<?php
/**
 * Smart Tagging Module
 * 
 * AI-powered tag suggestions for contacts and other entities
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Modules\Contacts;
use CRM\Modules\Tags;
use CRM\Database;

class SmartTagging
{
    private AIService $aiService;
    private Contacts $contacts;
    private Tags $tags;
    
    public function __construct()
    {
        $this->aiService = new AIService();
        $this->contacts = new Contacts();
        $this->tags = new Tags();
    }
    
    /**
     * Suggest tags for contact
     */
    public function suggestTagsForContact(int $contactId): array
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return [];
        }
        
        // Get contact context
        $activities = Database::query(
            "SELECT activity_type, description FROM activities WHERE contact_id = ? ORDER BY created_at DESC LIMIT 5",
            [$contactId]
        );
        
        $context = [
            'contact' => [
                'name' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
                'company' => $contact['company'] ?? '',
                'stage' => $contact['stage'] ?? 'new',
                'lead_source' => $contact['lead_source'] ?? ''
            ],
            'activities' => array_map(function($activity) {
                return $activity['activity_type'];
            }, $activities)
        ];
        
        // Use AI to suggest tags
        $result = $this->aiService->process('smart_tagging', [
            'context' => $context,
            'entity_type' => 'contact'
        ]);
        
        // Parse AI response
        $suggestions = json_decode($result, true);
        if (!$suggestions || !is_array($suggestions)) {
            // Fallback to rule-based suggestions
            $suggestions = $this->ruleBasedTagSuggestions($contact, $activities);
        }
        
        // Ensure suggestions are valid tag names
        $validTags = [];
        foreach ($suggestions as $tag) {
            if (is_string($tag) && !empty(trim($tag))) {
                $validTags[] = trim($tag);
            } elseif (is_array($tag) && isset($tag['name'])) {
                $validTags[] = trim($tag['name']);
            }
        }
        
        return array_unique($validTags);
    }
    
    /**
     * Rule-based tag suggestions (fallback)
     */
    private function ruleBasedTagSuggestions(array $contact, array $activities): array
    {
        $tags = [];
        
        // Stage-based tags
        if (!empty($contact['stage'])) {
            $tags[] = $contact['stage'];
        }
        
        // Lead source tags
        if (!empty($contact['lead_source'])) {
            $tags[] = $contact['lead_source'];
        }
        
        // Activity-based tags
        $activityTypes = array_unique(array_column($activities, 'activity_type'));
        foreach ($activityTypes as $type) {
            if (in_array($type, ['email_sent', 'email_received', 'call', 'meeting', 'note'])) {
                $tags[] = $type;
            }
        }
        
        // Company-based tags
        if (!empty($contact['company'])) {
            $tags[] = 'has_company';
        }
        
        return $tags;
    }
    
    /**
     * Auto-apply suggested tags
     */
    public function autoApplyTags(int $contactId, array $tagNames): int
    {
        $applied = 0;
        
        foreach ($tagNames as $tagName) {
            // Get or create tag
            $tag = $this->tags->getByName($tagName);
            if (!$tag) {
                $tagId = $this->tags->create([
                    'name' => $tagName,
                    'color' => $this->generateTagColor($tagName)
                ]);
            } else {
                $tagId = $tag['id'];
            }
            
            // Apply tag to contact
            if ($this->tags->assign($tagId, 'contact', $contactId)) {
                $applied++;
            }
        }
        
        return $applied;
    }
    
    /**
     * Generate color for tag based on name
     */
    private function generateTagColor(string $tagName): string
    {
        $colors = [
            '#3B82F6', // blue
            '#10B981', // green
            '#F59E0B', // amber
            '#EF4444', // red
            '#8B5CF6', // purple
            '#EC4899', // pink
        ];
        
        $hash = crc32($tagName);
        return $colors[abs($hash) % count($colors)];
    }
}
