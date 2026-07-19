<?php
/**
 * Intent Detection Module
 * 
 * Detects intent in messages and communications
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Database;

class IntentDetection
{
    private AIService $aiService;
    
    public function __construct()
    {
        $this->aiService = new AIService();
    }
    
    /**
     * Detect intent in text
     */
    public function detect(string $text, array $options = []): array
    {
        if (empty($text)) {
            return [
                'intent' => 'unknown',
                'confidence' => 0
            ];
        }
        
        // Use AI service for intent detection
        $result = $this->aiService->process('intent_detection', [
            'text' => $text
        ], $options);
        
        // Parse AI response
        $intent = json_decode($result, true);
        if (!$intent) {
            // Fallback to keyword-based detection
            $intent = $this->simpleIntentDetection($text);
        }
        
        return [
            'intent' => $intent['intent'] ?? 'unknown',
            'confidence' => (float) ($intent['confidence'] ?? 0.5),
            'category' => $intent['category'] ?? 'general',
            'entities' => $intent['entities'] ?? []
        ];
    }
    
    /**
     * Simple keyword-based intent detection (fallback)
     */
    private function simpleIntentDetection(string $text): array
    {
        $textLower = strtolower($text);
        
        $intents = [
            'purchase' => ['buy', 'purchase', 'order', 'price', 'cost', 'quote'],
            'support' => ['help', 'support', 'issue', 'problem', 'error', 'bug'],
            'information' => ['info', 'information', 'details', 'tell me', 'what is'],
            'complaint' => ['complaint', 'dissatisfied', 'unhappy', 'disappointed', 'bad service'],
            'inquiry' => ['question', 'ask', 'wonder', 'curious', 'inquiry'],
            'booking' => ['book', 'schedule', 'appointment', 'meeting', 'reserve'],
            'cancellation' => ['cancel', 'refund', 'return', 'stop']
        ];
        
        $scores = [];
        foreach ($intents as $intent => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($textLower, $keyword);
            }
            if ($score > 0) {
                $scores[$intent] = $score;
            }
        }
        
        if (empty($scores)) {
            return ['intent' => 'general', 'confidence' => 0.3];
        }
        
        $maxIntent = array_search(max($scores), $scores);
        $maxScore = max($scores);
        $totalScore = array_sum($scores);
        
        return [
            'intent' => $maxIntent,
            'confidence' => min(0.9, $maxScore / max($totalScore, 1))
        ];
    }
    
    /**
     * Detect intent in message
     */
    public function detectInMessage(int $messageId, string $channel = 'email'): array
    {
        $message = Database::queryOne(
            "SELECT body, subject FROM communications WHERE id = ? AND channel = ?",
            [$messageId, $channel]
        );
        
        if (!$message) {
            throw new \Exception("Message not found");
        }
        
        $text = ($message['subject'] ?? '') . ' ' . ($message['body'] ?? '');
        
        return $this->detect($text);
    }
}
