<?php
/**
 * AI Score Calculator Module
 * 
 * Calculates AI insight-based score (0-100) based on AI context analysis
 * Assesses contact potential based on role level, industry, data quality, and insights
 */

namespace CRM\Modules;

use CRM\Database;

class AIScoreCalculator
{
    /**
     * Calculate AI score based on AI context insights
     * 
     * Scoring breakdown:
     * - Role level: 0-30 points (senior/executive roles score higher)
     * - Industry alignment: 0-20 points (if relevant to business)
     * - Data quality: 0-25 points (verified data scores higher)
     * - Insights quality: 0-25 points (number and confidence of insights)
     * 
     * @param int $contactId Contact ID
     * @param int|null $workspaceId Optional workspace scope
     * @param bool $persist Whether to persist ai_score directly
     * @return array Score with breakdown and confidence
     */
    public function calculateAIScore(int $contactId, ?int $workspaceId = null, bool $persist = false): array
    {
        if ($workspaceId !== null) {
            $contact = Database::queryOne(
                "SELECT ai_context, company_industry FROM contacts WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $contactId]
            );
        } else {
            $contact = Database::queryOne("SELECT ai_context, company_industry FROM contacts WHERE id = ?", [$contactId]);
        }

        if (!$contact) {
            throw new \RuntimeException('Contact not found for AI score calculation.');
        }
        
        if (empty($contact['ai_context'])) {
            return [
                'score' => 0,
                'available' => false,
                'breakdown' => [
                    'role_level' => 0,
                    'industry_alignment' => 0,
                    'data_quality' => 0,
                    'insights_quality' => 0
                ],
                'confidence' => 0.0,
                'reasoning' => 'No AI context available'
            ];
        }
        
        $aiContext = json_decode($contact['ai_context'], true);
        if (!$aiContext) {
            return [
                'score' => 0,
                'available' => false,
                'breakdown' => [
                    'role_level' => 0,
                    'industry_alignment' => 0,
                    'data_quality' => 0,
                    'insights_quality' => 0
                ],
                'confidence' => 0.0,
                'reasoning' => 'Invalid AI context data'
            ];
        }
        
        $breakdown = [
            'role_level' => 0,
            'industry_alignment' => 0,
            'data_quality' => 0,
            'insights_quality' => 0
        ];
        
        $totalConfidence = 0.0;
        $insightCount = 0;
        
        // 1. Role Level Score (0-30 points)
        $roleScore = $this->calculateRoleLevelScore($aiContext);
        $breakdown['role_level'] = $roleScore;
        
        // 2. Industry Alignment Score (0-20 points)
        $industryScore = $this->calculateIndustryAlignmentScore($aiContext, $contact['company_industry'] ?? null);
        $breakdown['industry_alignment'] = $industryScore;
        
        // 3. Data Quality Score (0-25 points)
        $dataQualityScore = $this->calculateDataQualityScore($aiContext);
        $breakdown['data_quality'] = $dataQualityScore;
        
        // 4. Insights Quality Score (0-25 points)
        $insightsScore = $this->calculateInsightsQualityScore($aiContext);
        $breakdown['insights_quality'] = $insightsScore;
        
        // Calculate total score
        $totalScore = $roleScore + $industryScore + $dataQualityScore + $insightsScore;
        $totalScore = max(0, min(100, (int) round($totalScore)));
        
        // Calculate overall confidence
        $confidence = $this->calculateOverallConfidence($aiContext);
        
        if ($persist) {
            if ($workspaceId !== null) {
                Database::execute(
                    "UPDATE contacts SET ai_score = ? WHERE workspace_id = ? AND id = ?",
                    [$totalScore, $workspaceId, $contactId]
                );
            } else {
                Database::execute(
                    "UPDATE contacts SET ai_score = ? WHERE id = ?",
                    [$totalScore, $contactId]
                );
            }
        }
        
        return [
            'score' => $totalScore,
            'available' => true,
            'breakdown' => $breakdown,
            'confidence' => $confidence,
            'reasoning' => $this->generateReasoning($breakdown, $aiContext)
        ];
    }
    
    /**
     * Calculate role level score (0-30 points)
     */
    private function calculateRoleLevelScore(array $aiContext): float
    {
        $score = 0;
        
        // Check insights for role level
        foreach ($aiContext['insights'] ?? [] as $insight) {
            if (($insight['type'] ?? '') === 'professional_role') {
                $conclusion = strtolower($insight['conclusion'] ?? '');
                $confidence = $insight['confidence'] ?? 0;
                
                // Senior/executive roles score higher
                if (strpos($conclusion, 'senior') !== false || 
                    strpos($conclusion, 'executive') !== false ||
                    strpos($conclusion, 'director') !== false ||
                    strpos($conclusion, 'vp') !== false ||
                    strpos($conclusion, 'vice president') !== false ||
                    strpos($conclusion, 'chief') !== false ||
                    strpos($conclusion, 'ceo') !== false ||
                    strpos($conclusion, 'cto') !== false ||
                    strpos($conclusion, 'cfo') !== false) {
                    $score = 30 * $confidence; // Full points weighted by confidence
                } elseif (strpos($conclusion, 'manager') !== false || 
                         strpos($conclusion, 'lead') !== false) {
                    $score = 20 * $confidence; // Mid-level
                } else {
                    $score = 10 * $confidence; // Individual contributor
                }
                break; // Use first role insight
            }
        }
        
        // Check analysis for role level
        if ($score === 0 && !empty($aiContext['analysis']['professional_profile']['role_level'])) {
            $roleLevel = strtolower($aiContext['analysis']['professional_profile']['role_level']);
            if (strpos($roleLevel, 'senior') !== false || strpos($roleLevel, 'executive') !== false) {
                $score = 25; // High score for senior roles
            } elseif (strpos($roleLevel, 'management') !== false) {
                $score = 15; // Medium score for management
            } else {
                $score = 8; // Lower score for individual contributors
            }
        }
        
        return min(30, $score);
    }
    
    /**
     * Calculate industry alignment score (0-20 points)
     */
    private function calculateIndustryAlignmentScore(array $aiContext, ?string $contactIndustry): float
    {
        $score = 0;
        
        // If contact has industry information, that's valuable
        if (!empty($contactIndustry)) {
            $score += 10; // Base score for having industry data
        }
        
        // Check insights for industry focus
        foreach ($aiContext['insights'] ?? [] as $insight) {
            if (($insight['type'] ?? '') === 'industry_focus') {
                $confidence = $insight['confidence'] ?? 0;
                $score += 10 * $confidence; // Additional points for industry insights
                break;
            }
        }
        
        // Check analysis for industry
        if (!empty($aiContext['analysis']['company_insights']['industry'])) {
            $score += 5; // Additional points for company industry analysis
        }
        
        return min(20, $score);
    }
    
    /**
     * Calculate data quality score (0-25 points)
     */
    private function calculateDataQualityScore(array $aiContext): float
    {
        $score = 0;
        
        // Check data quality assessment
        $dataQuality = $aiContext['analysis']['data_quality'] ?? null;
        if ($dataQuality === 'high') {
            $score += 20;
        } elseif ($dataQuality === 'medium') {
            $score += 12;
        } elseif ($dataQuality === 'low') {
            $score += 5;
        }
        
        // Check for verified data insights
        foreach ($aiContext['insights'] ?? [] as $insight) {
            if (($insight['type'] ?? '') === 'contact_quality') {
                $conclusion = strtolower($insight['conclusion'] ?? '');
                $confidence = $insight['confidence'] ?? 0;
                
                if (strpos($conclusion, 'verified') !== false) {
                    $score += 5 * $confidence; // Bonus for verified data
                }
                break;
            }
        }
        
        // Check data completeness
        $completeness = $aiContext['analysis']['data_completeness'] ?? 0;
        if ($completeness > 0) {
            $score += ($completeness / 100) * 5; // Up to 5 points for completeness
        }
        
        return min(25, $score);
    }
    
    /**
     * Calculate insights quality score (0-25 points)
     */
    private function calculateInsightsQualityScore(array $aiContext): float
    {
        $score = 0;
        $insights = $aiContext['insights'] ?? [];
        $insightCount = count($insights);
        
        // Base score for having insights (up to 10 points)
        if ($insightCount > 0) {
            $score += min(10, $insightCount * 2); // 2 points per insight, max 10
        }
        
        // Average confidence score (up to 15 points)
        $totalConfidence = 0;
        $confidentInsights = 0;
        foreach ($insights as $insight) {
            $confidence = $insight['confidence'] ?? 0;
            if ($confidence >= 0.7) {
                $totalConfidence += $confidence;
                $confidentInsights++;
            }
        }
        
        if ($confidentInsights > 0) {
            $avgConfidence = $totalConfidence / $confidentInsights;
            $score += $avgConfidence * 15; // Up to 15 points for high confidence
        }
        
        return min(25, $score);
    }
    
    /**
     * Calculate overall confidence in AI score
     */
    private function calculateOverallConfidence(array $aiContext): float
    {
        $insights = $aiContext['insights'] ?? [];
        if (empty($insights)) {
            return 0.0;
        }
        
        $totalConfidence = 0;
        $count = 0;
        foreach ($insights as $insight) {
            if (isset($insight['confidence'])) {
                $totalConfidence += $insight['confidence'];
                $count++;
            }
        }
        
        if ($count === 0) {
            return 0.0;
        }
        
        return min(1.0, $totalConfidence / $count);
    }
    
    /**
     * Generate reasoning for the score
     */
    private function generateReasoning(array $breakdown, array $aiContext): string
    {
        $reasons = [];
        
        if ($breakdown['role_level'] > 20) {
            $reasons[] = 'High role level score';
        }
        if ($breakdown['industry_alignment'] > 10) {
            $reasons[] = 'Strong industry alignment';
        }
        if ($breakdown['data_quality'] > 15) {
            $reasons[] = 'High data quality';
        }
        if ($breakdown['insights_quality'] > 15) {
            $reasons[] = 'Quality AI insights';
        }
        
        if (empty($reasons)) {
            return 'Limited AI context available';
        }
        
        return implode(', ', $reasons);
    }
}
