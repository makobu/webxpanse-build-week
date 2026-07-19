<?php
/**
 * ML Explainability Service
 * Explains why a contact received a specific ML score
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\MLPredictionService;
use CRM\Services\MLFeatureService;
use CRM\Services\AIService;

class MLExplainabilityService
{
    private MLPredictionService $predictionService;
    private MLFeatureService $featureService;
    private AIService $aiService;
    
    public function __construct()
    {
        $this->predictionService = new MLPredictionService();
        $this->featureService = new MLFeatureService();
        $this->aiService = new AIService();
    }
    
    /**
     * Explain score for a contact
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type
     * @return array Full explanation with factors, comparison, and natural language
     */
    public function explainScore(int $contactId, string $modelType = 'conversion'): array
    {
        // Get prediction
        $prediction = $this->predictionService->predictConversion($contactId, $modelType);
        
        // Get top factors
        $topFactors = $this->getTopFactors($contactId, $modelType, 10);
        
        // Compare to average
        $comparison = $this->compareToAverage($contactId, $modelType);
        
        // Generate natural language explanation
        $explanation = $this->generateExplanation($contactId, $prediction, $topFactors, $comparison, $modelType);
        
        return [
            'contact_id' => $contactId,
            'score' => $prediction['prediction_score'],
            'probability' => $prediction['probability'],
            'confidence' => $prediction['confidence'],
            'top_factors' => $topFactors,
            'comparison' => $comparison,
            'explanation' => $explanation,
            'recommendations' => $this->getRecommendations($prediction, $topFactors, $modelType)
        ];
    }
    
    /**
     * Get top factors affecting score
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type
     * @param int $limit Number of factors to return
     * @return array Top factors
     */
    public function getTopFactors(int $contactId, string $modelType = 'conversion', int $limit = 5): array
    {
        $prediction = $this->predictionService->predictConversion($contactId, $modelType);
        $factors = $prediction['top_factors'] ?? [];
        
        // Sort by absolute contribution
        usort($factors, fn($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));
        
        // Format factors with human-readable names
        $formattedFactors = [];
        foreach (array_slice($factors, 0, $limit) as $factor) {
            $formattedFactors[] = [
                'feature' => $this->getFeatureDisplayName($factor['feature']),
                'value' => $this->formatFeatureValue($factor['feature'], $factor['value']),
                'contribution' => $factor['contribution'],
                'impact' => $factor['impact'],
                'importance' => $factor['importance'],
                'description' => $this->getFactorDescription($factor, $modelType)
            ];
        }
        
        return $formattedFactors;
    }
    
    /**
     * Compare contact to average
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type
     * @return array Comparison analysis
     */
    public function compareToAverage(int $contactId, string $modelType = 'conversion'): array
    {
        // Get contact features
        $contactFeatures = $this->featureService->extractAllFeatures($contactId);
        
        // Get average features from database (would ideally be pre-computed)
        $avgFeatures = $this->getAverageFeatures($modelType);
        
        // Compare key features
        $comparisons = [];
        $keyFeatures = [
            'email_open_rate', 'email_click_rate', 'total_activities', 
            'activities_30d', 'deal_count', 'win_rate', 'enrichment_score'
        ];
        
        foreach ($keyFeatures as $feature) {
            $contactValue = (float) ($contactFeatures[$feature] ?? 0);
            $avgValue = (float) ($avgFeatures[$feature] ?? 0);
            
            if ($avgValue > 0) {
                $difference = (($contactValue - $avgValue) / $avgValue) * 100;
                $comparisons[$feature] = [
                    'contact_value' => $contactValue,
                    'average_value' => $avgValue,
                    'difference_percent' => $difference,
                    'above_average' => $contactValue > $avgValue
                ];
            }
        }
        
        // Calculate overall comparison score
        $aboveAverageCount = count(array_filter($comparisons, fn($c) => $c['above_average']));
        $totalCount = count($comparisons);
        $comparisonScore = $totalCount > 0 ? ($aboveAverageCount / $totalCount) : 0.5;
        
        return [
            'comparisons' => $comparisons,
            'above_average_count' => $aboveAverageCount,
            'total_features_compared' => $totalCount,
            'comparison_score' => $comparisonScore,
            'overall_assessment' => $comparisonScore >= 0.6 ? 'above_average' : 
                                  ($comparisonScore >= 0.4 ? 'average' : 'below_average')
        ];
    }
    
    /**
     * Generate natural language explanation
     */
    public function generateExplanation(
        int $contactId, 
        array $prediction, 
        array $topFactors, 
        array $comparison,
        string $modelType = 'conversion'
    ): string {
        $score = $prediction['prediction_score'];
        $probability = $prediction['probability'];
        
        // Build explanation from factors
        $explanationParts = [];
        
        // Score summary
        if ($modelType === 'conversion') {
            $explanationParts[] = sprintf(
                "This contact has a %.0f%% conversion probability (score: %.0f/100).",
                $probability * 100,
                $score
            );
        } else {
            $explanationParts[] = sprintf(
                "This contact has a %.0f%% %s probability (score: %.0f/100).",
                $probability * 100,
                $modelType,
                $score
            );
        }
        
        // Top positive factors
        $positiveFactors = array_filter($topFactors, fn($f) => $f['impact'] === 'positive');
        if (!empty($positiveFactors)) {
            $explanationParts[] = "Key strengths:";
            foreach (array_slice($positiveFactors, 0, 3) as $factor) {
                $explanationParts[] = sprintf(
                    "• %s (%s)",
                    $factor['description'],
                    $factor['value']
                );
            }
        }
        
        // Top negative factors
        $negativeFactors = array_filter($topFactors, fn($f) => $f['impact'] === 'negative');
        if (!empty($negativeFactors)) {
            $explanationParts[] = "Areas for improvement:";
            foreach (array_slice($negativeFactors, 0, 3) as $factor) {
                $explanationParts[] = sprintf(
                    "• %s (%s)",
                    $factor['description'],
                    $factor['value']
                );
            }
        }
        
        // Comparison summary
        if ($comparison['overall_assessment'] === 'above_average') {
            $explanationParts[] = "This contact performs above average compared to similar contacts.";
        } elseif ($comparison['overall_assessment'] === 'below_average') {
            $explanationParts[] = "This contact performs below average compared to similar contacts.";
        }
        
        return implode("\n", $explanationParts);
    }
    
    /**
     * Get recommendations based on prediction
     */
    private function getRecommendations(array $prediction, array $topFactors, string $modelType): array
    {
        $recommendations = [];
        $score = $prediction['prediction_score'];
        
        if ($modelType === 'conversion') {
            if ($score >= 70) {
                $recommendations[] = [
                    'priority' => 'high',
                    'action' => 'Prioritize this contact for immediate follow-up',
                    'reason' => 'High conversion probability'
                ];
            } elseif ($score >= 50) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'action' => 'Continue nurturing with regular engagement',
                    'reason' => 'Moderate conversion probability'
                ];
            } else {
                $recommendations[] = [
                    'priority' => 'low',
                    'action' => 'Focus on improving engagement metrics',
                    'reason' => 'Low conversion probability'
                ];
            }
            
            // Factor-based recommendations
            foreach ($topFactors as $factor) {
                if ($factor['impact'] === 'negative' && abs($factor['contribution']) > 0.1) {
                    $recommendations[] = [
                        'priority' => 'medium',
                        'action' => $this->getActionForFactor($factor['feature']),
                        'reason' => "Low {$this->getFeatureDisplayName($factor['feature'])}"
                    ];
                }
            }
        } elseif ($modelType === 'churn') {
            if ($score >= 70) {
                $recommendations[] = [
                    'priority' => 'high',
                    'action' => 'Immediate intervention required to prevent churn',
                    'reason' => 'High churn risk'
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Get average features for comparison
     */
    private function getAverageFeatures(string $modelType): array
    {
        // This would ideally query pre-computed averages
        // For now, return default values
        return [
            'email_open_rate' => 0.25,
            'email_click_rate' => 0.05,
            'total_activities' => 5,
            'activities_30d' => 2,
            'deal_count' => 1,
            'win_rate' => 0.3,
            'enrichment_score' => 60
        ];
    }
    
    /**
     * Get feature display name
     */
    private function getFeatureDisplayName(string $featureName): string
    {
        $displayNames = [
            'email_open_rate' => 'Email Open Rate',
            'email_click_rate' => 'Email Click Rate',
            'total_activities' => 'Total Activities',
            'activities_30d' => 'Activities (30 days)',
            'deal_count' => 'Deal Count',
            'win_rate' => 'Win Rate',
            'enrichment_score' => 'Data Enrichment Score',
            'response_rate' => 'Response Rate',
            'engagement_velocity' => 'Engagement Velocity',
            'days_since_last_activity' => 'Days Since Last Activity'
        ];
        
        return $displayNames[$featureName] ?? ucwords(str_replace('_', ' ', $featureName));
    }
    
    /**
     * Format feature value for display
     */
    private function formatFeatureValue(string $featureName, $value): string
    {
        if (strpos($featureName, 'rate') !== false || strpos($featureName, 'probability') !== false) {
            return number_format($value * 100, 1) . '%';
        } elseif (strpos($featureName, 'days') !== false || strpos($featureName, 'count') !== false) {
            return number_format($value, 0);
        } elseif (strpos($featureName, 'value') !== false || strpos($featureName, 'revenue') !== false) {
            return '$' . number_format($value, 2);
        } else {
            return number_format($value, 2);
        }
    }
    
    /**
     * Get factor description
     */
    private function getFactorDescription(array $factor, string $modelType): string
    {
        $feature = $factor['feature'];
        $impact = $factor['impact'];
        $value = $factor['value'];
        
        $descriptions = [
            'email_open_rate' => [
                'positive' => 'High email engagement',
                'negative' => 'Low email engagement'
            ],
            'activities_30d' => [
                'positive' => 'Active recent engagement',
                'negative' => 'Low recent activity'
            ],
            'deal_count' => [
                'positive' => 'Multiple deals in pipeline',
                'negative' => 'No active deals'
            ],
            'win_rate' => [
                'positive' => 'Strong conversion history',
                'negative' => 'Low conversion rate'
            ]
        ];
        
        if (isset($descriptions[$feature][$impact])) {
            return $descriptions[$feature][$impact];
        }
        
        return ucwords(str_replace('_', ' ', $feature));
    }
    
    /**
     * Get action recommendation for a factor
     */
    private function getActionForFactor(string $featureName): string
    {
        $actions = [
            'email_open_rate' => 'Improve email subject lines and send times',
            'email_click_rate' => 'Create more compelling email content and CTAs',
            'activities_30d' => 'Increase touchpoints and engagement frequency',
            'deal_count' => 'Focus on creating new opportunities',
            'win_rate' => 'Improve deal qualification and follow-up',
            'response_rate' => 'Personalize outreach and improve messaging',
            'days_since_last_activity' => 'Re-engage with timely follow-up'
        ];
        
        return $actions[$featureName] ?? 'Focus on improving this metric';
    }
}
