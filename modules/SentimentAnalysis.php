<?php
/**
 * Sentiment Analysis Module
 * 
 * Analyzes sentiment in communications (emails, messages, notes)
 */

namespace CRM\Modules;

use CRM\Services\AIService;
use CRM\Database;

class SentimentAnalysis
{
    private AIService $aiService;
    
    public function __construct()
    {
        $this->aiService = new AIService();
    }
    
    /**
     * Analyze sentiment of text
     */
    public function analyze(string $text, array $options = []): array
    {
        if (empty($text)) {
            return [
                'sentiment' => 'neutral',
                'score' => 0,
                'confidence' => 0
            ];
        }
        
        // Use AI service for sentiment analysis
        $result = $this->aiService->process('sentiment', [
            'text' => $text
        ], $options);
        
        // Parse AI response
        $analysis = json_decode($result, true);
        if (!$analysis) {
            // Fallback to simple keyword-based analysis
            $analysis = $this->simpleSentimentAnalysis($text);
        }
        
        return [
            'sentiment' => $analysis['sentiment'] ?? 'neutral',
            'score' => (float) ($analysis['score'] ?? 0),
            'confidence' => (float) ($analysis['confidence'] ?? 0.5),
            'emotions' => $analysis['emotions'] ?? []
        ];
    }
    
    /**
     * Simple keyword-based sentiment analysis (fallback)
     */
    private function simpleSentimentAnalysis(string $text): array
    {
        $positiveWords = ['good', 'great', 'excellent', 'wonderful', 'amazing', 'happy', 'pleased', 'satisfied', 'love', 'thanks', 'thank you'];
        $negativeWords = ['bad', 'terrible', 'awful', 'disappointed', 'angry', 'frustrated', 'hate', 'problem', 'issue', 'complaint'];
        
        $textLower = strtolower($text);
        $positiveCount = 0;
        $negativeCount = 0;
        
        foreach ($positiveWords as $word) {
            $positiveCount += substr_count($textLower, $word);
        }
        
        foreach ($negativeWords as $word) {
            $negativeCount += substr_count($textLower, $word);
        }
        
        $total = $positiveCount + $negativeCount;
        if ($total === 0) {
            return ['sentiment' => 'neutral', 'score' => 0];
        }
        
        $score = ($positiveCount - $negativeCount) / $total;
        
        if ($score > 0.2) {
            $sentiment = 'positive';
        } elseif ($score < -0.2) {
            $sentiment = 'negative';
        } else {
            $sentiment = 'neutral';
        }
        
        return [
            'sentiment' => $sentiment,
            'score' => $score
        ];
    }
    
    /**
     * Analyze sentiment of email
     */
    public function analyzeEmail(int $emailId): array
    {
        $email = Database::queryOne(
            "SELECT subject, body_html, body_text FROM emails WHERE id = ?",
            [$emailId]
        );
        
        if (!$email) {
            throw new \Exception("Email not found");
        }
        
        $text = $email['body_text'] ?? strip_tags($email['body_html'] ?? '');
        $text = $email['subject'] . ' ' . $text;
        
        return $this->analyze($text);
    }
    
    /**
     * Analyze sentiment of communication thread
     */
    public function analyzeThread(int $contactId, string $channel = 'email'): array
    {
        $communications = Database::query(
            "SELECT body, created_at 
             FROM communications 
             WHERE contact_id = ? AND channel = ? 
             ORDER BY created_at ASC",
            [$contactId, $channel]
        );
        
        $sentiments = [];
        foreach ($communications as $comm) {
            $sentiments[] = $this->analyze($comm['body']);
        }
        
        // Calculate average sentiment
        $totalScore = 0;
        $count = count($sentiments);
        
        foreach ($sentiments as $sentiment) {
            $totalScore += $sentiment['score'];
        }
        
        $avgScore = $count > 0 ? $totalScore / $count : 0;
        
        return [
            'average_sentiment' => $avgScore > 0.2 ? 'positive' : ($avgScore < -0.2 ? 'negative' : 'neutral'),
            'average_score' => $avgScore,
            'message_count' => $count,
            'sentiments' => $sentiments
        ];
    }
}
