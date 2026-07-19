<?php
/**
 * AI Contact Summarizer
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Database;

class AIContactSummarizer
{
    private AIService $aiService;
    
    public function __construct()
    {
        $this->aiService = new AIService();
    }
    
    /**
     * Generate contact summary
     */
    public function generateSummary(int $contactId): array
    {
        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
        $activities = Database::query(
            "SELECT * FROM activities WHERE contact_id = ? ORDER BY created_at DESC LIMIT 10",
            [$contactId]
        );
        
        $context = [
            'contact' => $contact,
            'activities' => $activities
        ];
        
        $summary = $this->aiService->process('summarization', [
            'text' => json_encode($context)
        ]);
        
        return [
            'summary' => $summary,
            'last_updated' => time()
        ];
    }
}
