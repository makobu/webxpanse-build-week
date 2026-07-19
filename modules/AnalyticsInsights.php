<?php
/**
 * Analytics Insights Module
 * 
 * Generates intelligent insights and recommendations from analytics data
 */

namespace CRM\Modules;

use CRM\Database;

class AnalyticsInsights
{
    /**
     * Generate insights from metrics and trends
     */
    public function generateInsights(array $metrics, array $trends): array
    {
        $insights = [];
        
        // Analyze email performance
        if (!empty($metrics['email_open_rate'])) {
            $openRate = $metrics['email_open_rate'];
            if ($openRate < 20) {
                $insights[] = [
                    'type' => 'warning',
                    'category' => 'email',
                    'title' => 'Low Email Open Rate',
                    'message' => "Your email open rate is {$openRate}%, which is below industry average (20-25%).",
                    'recommendation' => 'Consider improving subject lines, sending at optimal times, and segmenting your audience.',
                    'impact' => 'high',
                    'metric' => 'email_open_rate',
                    'value' => $openRate
                ];
            } elseif ($openRate > 30) {
                $insights[] = [
                    'type' => 'success',
                    'category' => 'email',
                    'title' => 'Excellent Email Engagement',
                    'message' => "Your email open rate of {$openRate}% is above industry average!",
                    'recommendation' => 'Maintain this performance and consider scaling successful campaigns.',
                    'impact' => 'medium',
                    'metric' => 'email_open_rate',
                    'value' => $openRate
                ];
            }
        }
        
        // Analyze conversion rates
        if (!empty($metrics['conversion_rate'])) {
            $convRate = $metrics['conversion_rate'];
            if ($convRate < 5) {
                $insights[] = [
                    'type' => 'warning',
                    'category' => 'conversion',
                    'title' => 'Low Conversion Rate',
                    'message' => "Your conversion rate is {$convRate}%, indicating potential issues in your sales process.",
                    'recommendation' => 'Review your qualification process, improve lead quality, and enhance follow-up strategies.',
                    'impact' => 'high',
                    'metric' => 'conversion_rate',
                    'value' => $convRate
                ];
            }
        }
        
        // Analyze funnel drop-offs
        if (!empty($trends['funnel_drop_offs'])) {
            foreach ($trends['funnel_drop_offs'] as $dropOff) {
                if ($dropOff['drop_off_rate'] > 50) {
                    $insights[] = [
                        'type' => 'warning',
                        'category' => 'funnel',
                        'title' => "High Drop-off at {$dropOff['from_stage']} Stage",
                        'message' => "{$dropOff['drop_off_rate']}% of contacts are dropping off between {$dropOff['from_stage']} and {$dropOff['to_stage']}.",
                        'recommendation' => "Focus on improving engagement and qualification at the {$dropOff['from_stage']} stage.",
                        'impact' => 'high',
                        'metric' => 'drop_off_rate',
                        'value' => $dropOff['drop_off_rate']
                    ];
                }
            }
        }
        
        // Analyze growth trends
        if (!empty($trends['growth'])) {
            $growth = $trends['growth'];
            if ($growth > 20) {
                $insights[] = [
                    'type' => 'success',
                    'category' => 'growth',
                    'title' => 'Strong Growth Momentum',
                    'message' => "Your contacts are growing at {$growth}% rate, indicating healthy business expansion.",
                    'recommendation' => 'Maintain this growth trajectory and ensure your team can handle the increased volume.',
                    'impact' => 'medium',
                    'metric' => 'growth_rate',
                    'value' => $growth
                ];
            } elseif ($growth < -10) {
                $insights[] = [
                    'type' => 'danger',
                    'category' => 'growth',
                    'title' => 'Declining Growth',
                    'message' => "Your contact growth has declined by {$growth}%, which requires immediate attention.",
                    'recommendation' => 'Review your lead generation strategies and marketing campaigns.',
                    'impact' => 'high',
                    'metric' => 'growth_rate',
                    'value' => $growth
                ];
            }
        }
        
        // Analyze response times
        if (!empty($metrics['avg_response_time'])) {
            $responseTime = $metrics['avg_response_time'];
            if ($responseTime > 24) {
                $insights[] = [
                    'type' => 'warning',
                    'category' => 'engagement',
                    'title' => 'Slow Response Times',
                    'message' => "Average response time is {$responseTime} hours, which may impact customer satisfaction.",
                    'recommendation' => 'Implement faster response protocols and consider automation for common inquiries.',
                    'impact' => 'medium',
                    'metric' => 'response_time',
                    'value' => $responseTime
                ];
            } elseif ($responseTime < 2) {
                $insights[] = [
                    'type' => 'success',
                    'category' => 'engagement',
                    'title' => 'Excellent Response Times',
                    'message' => "Average response time of {$responseTime} hours demonstrates strong customer service.",
                    'recommendation' => 'Maintain this level of responsiveness to keep customer satisfaction high.',
                    'impact' => 'low',
                    'metric' => 'response_time',
                    'value' => $responseTime
                ];
            }
        }
        
        return $insights;
    }
    
    /**
     * Identify opportunities
     */
    public function identifyOpportunities(array $data): array
    {
        $opportunities = [];
        
        // High-value deals in pipeline
        if (!empty($data['high_value_deals'])) {
            $opportunities[] = [
                'type' => 'revenue',
                'title' => 'High-Value Pipeline Opportunities',
                'description' => count($data['high_value_deals']) . ' high-value deals in pipeline',
                'potential_value' => array_sum(array_column($data['high_value_deals'], 'value')),
                'action' => 'Prioritize follow-up on these deals',
                'priority' => 'high'
            ];
        }
        
        // Top-performing lead sources
        if (!empty($data['top_sources'])) {
            $topSource = $data['top_sources'][0] ?? null;
            if ($topSource && $topSource['conversion_rate'] > 30) {
                $opportunities[] = [
                    'type' => 'acquisition',
                    'title' => 'High-Converting Lead Source',
                    'description' => "{$topSource['lead_source']} has {$topSource['conversion_rate']}% conversion rate",
                    'potential_value' => 'Scale this channel for more leads',
                    'action' => "Increase investment in {$topSource['lead_source']} channel",
                    'priority' => 'high'
                ];
            }
        }
        
        // Contacts ready for next stage
        if (!empty($data['ready_to_advance'])) {
            $opportunities[] = [
                'type' => 'conversion',
                'title' => 'Contacts Ready to Advance',
                'description' => count($data['ready_to_advance']) . ' contacts ready for next stage',
                'potential_value' => 'Accelerate conversion',
                'action' => 'Review and advance these contacts',
                'priority' => 'medium'
            ];
        }
        
        return $opportunities;
    }
    
    /**
     * Detect anomalies
     */
    public function detectAnomalies(array $data): array
    {
        $anomalies = [];
        
        // Sudden drop in activity
        if (!empty($data['activity_trends'])) {
            $recent = array_slice($data['activity_trends'], -7);
            $previous = array_slice($data['activity_trends'], -14, 7);
            
            if (!empty($recent) && !empty($previous)) {
                $recentAvg = array_sum($recent) / count($recent);
                $previousAvg = array_sum($previous) / count($previous);
                
                if ($previousAvg > 0 && ($recentAvg / $previousAvg) < 0.5) {
                    $anomalies[] = [
                        'type' => 'activity_drop',
                        'severity' => 'high',
                        'title' => 'Significant Activity Drop Detected',
                        'message' => 'Activity has dropped by more than 50% compared to previous period.',
                        'recommendation' => 'Investigate the cause and take corrective action.'
                    ];
                }
            }
        }
        
        // Unusual email bounce rate
        if (!empty($data['email_bounce_rate']) && $data['email_bounce_rate'] > 5) {
            $anomalies[] = [
                'type' => 'email_quality',
                'severity' => 'medium',
                'title' => 'High Email Bounce Rate',
                'message' => "Email bounce rate of {$data['email_bounce_rate']}% is above normal threshold.",
                'recommendation' => 'Review email list quality and validation processes.'
            ];
        }
        
        return $anomalies;
    }
    
    /**
     * Generate recommendations
     */
    public function generateRecommendations(array $insights): array
    {
        $recommendations = [];
        
        foreach ($insights as $insight) {
            if (!empty($insight['recommendation'])) {
                $recommendations[] = [
                    'category' => $insight['category'] ?? 'general',
                    'priority' => $insight['impact'] ?? 'medium',
                    'recommendation' => $insight['recommendation'],
                    'related_metric' => $insight['metric'] ?? null,
                    'expected_impact' => $insight['impact'] ?? 'medium'
                ];
            }
        }
        
        // Add general recommendations based on common patterns
        if (empty($recommendations)) {
            $recommendations[] = [
                'category' => 'general',
                'priority' => 'low',
                'recommendation' => 'Continue monitoring key metrics and maintain current performance levels.',
                'related_metric' => null,
                'expected_impact' => 'low'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Calculate benchmarks
     */
    public function calculateBenchmarks(array $metrics): array
    {
        // Industry benchmarks (simplified - in production, these would be more sophisticated)
        $benchmarks = [
            'email_open_rate' => [
                'industry_avg' => 20.0,
                'industry_good' => 25.0,
                'industry_excellent' => 30.0
            ],
            'email_click_rate' => [
                'industry_avg' => 2.5,
                'industry_good' => 4.0,
                'industry_excellent' => 6.0
            ],
            'conversion_rate' => [
                'industry_avg' => 5.0,
                'industry_good' => 10.0,
                'industry_excellent' => 15.0
            ],
            'response_time_hours' => [
                'industry_avg' => 4.0,
                'industry_good' => 2.0,
                'industry_excellent' => 1.0
            ]
        ];
        
        $comparisons = [];
        foreach ($metrics as $key => $value) {
            if (isset($benchmarks[$key])) {
                $benchmark = $benchmarks[$key];
                $comparisons[$key] = [
                    'value' => $value,
                    'benchmark' => $benchmark,
                    'status' => $value >= $benchmark['industry_excellent'] ? 'excellent' : 
                               ($value >= $benchmark['industry_good'] ? 'good' : 
                               ($value >= $benchmark['industry_avg'] ? 'average' : 'below_average'))
                ];
            }
        }
        
        return $comparisons;
    }
}
