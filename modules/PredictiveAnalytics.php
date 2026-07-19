<?php
/**
 * Predictive Analytics Module
 * 
 * Provides predictive analytics including:
 * - Lead conversion prediction
 * - Churn prediction
 * - Revenue forecasting
 * - Customer lifetime value prediction
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Activities;
use CRM\Modules\LeadScoring;
use CRM\Services\AnalyticsWorkspaceService;

class PredictiveAnalytics
{
    private Contacts $contacts;
    private Deals $deals;
    private Activities $activities;
    private LeadScoring $leadScoring;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    
    public function __construct()
    {
        $this->contacts = new Contacts();
        $this->deals = new Deals();
        $this->activities = new Activities();
        $this->leadScoring = new LeadScoring();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }
    
    /**
     * Predict lead conversion probability
     */
    public function predictLeadConversion(int $contactId): array
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Get contact features for prediction
        $features = $this->extractContactFeatures($contactId);
        
        // Calculate base probability from lead score
        $leadScore = $contact['lead_score'] ?? 0;
        $baseProbability = min(95, max(5, $leadScore)); // Convert score (0-100) to probability (5-95%)
        
        // Adjust based on features
        $adjustments = $this->calculateConversionAdjustments($features);
        $probability = min(95, max(5, $baseProbability + $adjustments['adjustment']));
        
        // Get historical conversion rate for similar contacts
        $historicalRate = $this->getHistoricalConversionRate($features);
        
        // Weighted prediction
        $finalProbability = ($probability * 0.6) + ($historicalRate * 0.4);
        
        return [
            'contact_id' => $contactId,
            'conversion_probability' => round($finalProbability, 2),
            'confidence' => $this->calculateConfidence($features),
            'factors' => $adjustments['factors'],
            'recommended_actions' => $this->getConversionRecommendations($finalProbability, $features),
            'time_to_conversion' => $this->predictTimeToConversion($features)
        ];
    }
    
    /**
     * Extract features for prediction
     */
    private function extractContactFeatures(int $contactId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contact = $this->contacts->getById($contactId);
        
        // Get activity statistics
        $activityStats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_activities,
                COUNT(CASE WHEN activity_type = 'email_sent' THEN 1 END) as emails_sent,
                COUNT(CASE WHEN activity_type = 'email_received' THEN 1 END) as emails_received,
                COUNT(CASE WHEN activity_type = 'call' THEN 1 END) as calls,
                COUNT(CASE WHEN activity_type = 'meeting' THEN 1 END) as meetings,
                MAX(created_at) as last_activity_date,
                MIN(created_at) as first_activity_date
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        // Get deal information
        $dealInfo = Database::queryOne(
            "SELECT 
                COUNT(*) as deal_count,
                SUM(value) as total_deal_value,
                AVG(value) as avg_deal_value
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        // Calculate days since first contact
        $daysSinceFirstContact = 0;
        if (!empty($activityStats['first_activity_date'])) {
            $daysSinceFirstContact = (time() - strtotime($activityStats['first_activity_date'])) / 86400;
        }
        
        // Calculate days since last activity
        $daysSinceLastActivity = 0;
        if (!empty($activityStats['last_activity_date'])) {
            $daysSinceLastActivity = (time() - strtotime($activityStats['last_activity_date'])) / 86400;
        }
        
        return [
            'lead_score' => $contact['lead_score'] ?? 0,
            'stage' => $contact['stage'] ?? 'new',
            'lead_source' => $contact['lead_source'] ?? '',
            'has_company' => !empty($contact['company']),
            'total_activities' => (int) ($activityStats['total_activities'] ?? 0),
            'emails_sent' => (int) ($activityStats['emails_sent'] ?? 0),
            'emails_received' => (int) ($activityStats['emails_received'] ?? 0),
            'calls' => (int) ($activityStats['calls'] ?? 0),
            'meetings' => (int) ($activityStats['meetings'] ?? 0),
            'days_since_first_contact' => (int) $daysSinceFirstContact,
            'days_since_last_activity' => (int) $daysSinceLastActivity,
            'deal_count' => (int) ($dealInfo['deal_count'] ?? 0),
            'total_deal_value' => (float) ($dealInfo['total_deal_value'] ?? 0),
            'avg_deal_value' => (float) ($dealInfo['avg_deal_value'] ?? 0)
        ];
    }
    
    /**
     * Calculate conversion probability adjustments
     */
    private function calculateConversionAdjustments(array $features): array
    {
        $adjustment = 0;
        $factors = [];
        
        // Stage-based adjustments
        $stageAdjustments = [
            'new' => -20,
            'contacted' => -10,
            'qualified' => 10,
            'proposal' => 20,
            'negotiation' => 30,
            'won' => 0, // Already converted
            'lost' => -50
        ];
        if (isset($stageAdjustments[$features['stage']])) {
            $adjustment += $stageAdjustments[$features['stage']];
            $factors[] = "Stage: {$features['stage']}";
        }
        
        // Activity-based adjustments
        if ($features['meetings'] > 0) {
            $adjustment += 15;
            $factors[] = "Has meetings scheduled";
        }
        
        if ($features['calls'] > 2) {
            $adjustment += 10;
            $factors[] = "Multiple calls made";
        }
        
        if ($features['emails_received'] > 0) {
            $adjustment += 5;
            $factors[] = "Contact is responding";
        }
        
        // Time-based adjustments
        if ($features['days_since_last_activity'] > 30) {
            $adjustment -= 15;
            $factors[] = "No recent activity";
        }
        
        if ($features['days_since_first_contact'] > 90 && $features['stage'] === 'new') {
            $adjustment -= 20;
            $factors[] = "Long time without progress";
        }
        
        // Deal value adjustments
        if ($features['total_deal_value'] > 0) {
            $adjustment += 25;
            $factors[] = "Has active deals";
        }
        
        return [
            'adjustment' => $adjustment,
            'factors' => $factors
        ];
    }
    
    /**
     * Get historical conversion rate for similar contacts
     */
    private function getHistoricalConversionRate(array $features): float
    {
        // Get conversion rate for contacts with similar characteristics
        $stage = $features['stage'];
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        
        $conversionRate = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN stage = 'won' THEN 1 END) * 100.0 / COUNT(*) as conversion_rate
             FROM contacts 
             WHERE workspace_id = ?
             AND (stage = ? OR stage = 'won' OR stage = 'lost')
             GROUP BY stage = 'won'",
            [$workspaceId, $stage]
        );
        
        return (float) ($conversionRate['conversion_rate'] ?? 30.0); // Default 30%
    }
    
    /**
     * Calculate prediction confidence
     */
    private function calculateConfidence(array $features): float
    {
        $confidence = 0.5; // Base confidence
        
        // More data = higher confidence
        if ($features['total_activities'] > 5) {
            $confidence += 0.2;
        }
        
        if ($features['total_activities'] > 10) {
            $confidence += 0.1;
        }
        
        // Recent activity = higher confidence
        if ($features['days_since_last_activity'] < 7) {
            $confidence += 0.1;
        }
        
        // Has deals = higher confidence
        if ($features['deal_count'] > 0) {
            $confidence += 0.1;
        }
        
        return min(0.95, max(0.3, $confidence));
    }
    
    /**
     * Get conversion recommendations
     */
    private function getConversionRecommendations(float $probability, array $features): array
    {
        $recommendations = [];
        
        if ($probability < 30) {
            $recommendations[] = "Schedule a discovery call to understand needs";
            $recommendations[] = "Send personalized content based on lead source";
        } elseif ($probability < 50) {
            $recommendations[] = "Follow up with proposal or demo";
            $recommendations[] = "Engage decision makers";
        } elseif ($probability < 70) {
            $recommendations[] = "Address any objections";
            $recommendations[] = "Provide case studies or testimonials";
        } else {
            $recommendations[] = "Prepare contract and closing materials";
            $recommendations[] = "Schedule final meeting";
        }
        
        if ($features['days_since_last_activity'] > 14) {
            $recommendations[] = "Re-engage with recent activity";
        }
        
        if ($features['meetings'] === 0 && $probability > 40) {
            $recommendations[] = "Schedule a meeting to move forward";
        }
        
        return $recommendations;
    }
    
    /**
     * Predict time to conversion (in days)
     */
    private function predictTimeToConversion(array $features): int
    {
        $baseDays = 60; // Base prediction
        
        // Adjust based on stage
        $stageDays = [
            'new' => 90,
            'contacted' => 60,
            'qualified' => 30,
            'proposal' => 15,
            'negotiation' => 7
        ];
        
        if (isset($stageDays[$features['stage']])) {
            $baseDays = $stageDays[$features['stage']];
        }
        
        // Adjust based on activity level
        if ($features['total_activities'] > 10) {
            $baseDays -= 15;
        }
        
        if ($features['meetings'] > 0) {
            $baseDays -= 10;
        }
        
        // Adjust based on lead score
        if ($features['lead_score'] > 70) {
            $baseDays -= 20;
        }
        
        return max(7, min(180, (int) $baseDays));
    }
    
    /**
     * Predict churn probability
     */
    public function predictChurn(int $contactId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        $features = $this->extractContactFeatures($contactId);
        
        // Calculate churn risk factors
        $riskScore = 0;
        $riskFactors = [];
        
        // No recent activity
        if ($features['days_since_last_activity'] > 60) {
            $riskScore += 30;
            $riskFactors[] = "No activity in {$features['days_since_last_activity']} days";
        }
        
        // Declining engagement
        $recentActivities = Database::query(
            "SELECT COUNT(*) as count 
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$workspaceId, $contactId]
        );
        $recentCount = (int) ($recentActivities[0]['count'] ?? 0);
        if ($recentCount === 0 && $features['total_activities'] > 0) {
            $riskScore += 25;
            $riskFactors[] = "Engagement has stopped";
        }
        
        // No response to emails
        if ($features['emails_sent'] > 3 && $features['emails_received'] === 0) {
            $riskScore += 20;
            $riskFactors[] = "Not responding to emails";
        }
        
        // Low lead score
        if ($features['lead_score'] < 30) {
            $riskScore += 15;
            $riskFactors[] = "Low engagement score";
        }
        
        // Lost deals
        $lostDeals = Database::queryOne(
            "SELECT COUNT(*) as count FROM deals WHERE workspace_id = ? AND contact_id = ? AND stage = 'lost'",
            [$workspaceId, $contactId]
        );
        if (($lostDeals['count'] ?? 0) > 0) {
            $riskScore += 20;
            $riskFactors[] = "Has lost deals";
        }
        
        $churnProbability = min(95, max(5, $riskScore));
        
        return [
            'contact_id' => $contactId,
            'churn_probability' => round($churnProbability, 2),
            'risk_factors' => $riskFactors,
            'recommended_actions' => $this->getChurnPreventionActions($churnProbability, $riskFactors)
        ];
    }
    
    /**
     * Get churn prevention actions
     */
    private function getChurnPreventionActions(float $probability, array $riskFactors): array
    {
        $actions = [];
        
        if ($probability > 50) {
            $actions[] = "Immediate re-engagement campaign";
            $actions[] = "Personal outreach from account manager";
            $actions[] = "Offer special incentives or discounts";
        } elseif ($probability > 30) {
            $actions[] = "Schedule check-in call";
            $actions[] = "Send valuable content or resources";
            $actions[] = "Request feedback on experience";
        } else {
            $actions[] = "Regular check-ins";
            $actions[] = "Continue providing value";
        }
        
        return $actions;
    }
    
    /**
     * Forecast revenue for a period
     */
    public function forecastRevenue(string $period = 'month', int $months = 3): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        // Get historical revenue data
        $historical = Database::query(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(value) as revenue,
                COUNT(*) as deal_count
             FROM deals 
             WHERE workspace_id = ?
             AND stage = 'won' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month ASC",
            [$workspaceId, $months * 2]
        );
        
        // Get pipeline value
        $pipeline = Database::queryOne(
            "SELECT 
                SUM(value * (probability / 100.0)) as weighted_value,
                SUM(value) as total_value,
                COUNT(*) as deal_count
             FROM deals 
             WHERE workspace_id = ?
             AND stage NOT IN ('won', 'lost')",
            [$workspaceId]
        );
        
        // Calculate average monthly revenue
        $avgMonthlyRevenue = 0;
        if (!empty($historical)) {
            $totalRevenue = array_sum(array_column($historical, 'revenue'));
            $avgMonthlyRevenue = $totalRevenue / count($historical);
        }
        
        // Forecast future months
        $forecast = [];
        $currentMonth = date('Y-m');
        
        for ($i = 1; $i <= $months; $i++) {
            $forecastMonth = date('Y-m', strtotime("+{$i} months"));
            
            // Base forecast on historical average + pipeline conversion
            $pipelineContribution = ($pipeline['weighted_value'] ?? 0) / $months;
            $forecastedRevenue = $avgMonthlyRevenue + ($pipelineContribution * 0.3); // 30% pipeline conversion
            
            $forecast[] = [
                'month' => $forecastMonth,
                'forecasted_revenue' => round($forecastedRevenue, 2),
                'confidence' => 0.7 - ($i * 0.1) // Decreasing confidence for further months
            ];
        }
        
        return [
            'period' => $period,
            'months' => $months,
            'historical_avg_monthly' => round($avgMonthlyRevenue, 2),
            'current_pipeline_value' => round($pipeline['total_value'] ?? 0, 2),
            'weighted_pipeline_value' => round($pipeline['weighted_value'] ?? 0, 2),
            'forecast' => $forecast,
            'total_forecasted' => round(array_sum(array_column($forecast, 'forecasted_revenue')), 2)
        ];
    }
    
    /**
     * Calculate Customer Lifetime Value (CLV)
     */
    public function calculateCLV(int $contactId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Get historical revenue
        $historicalRevenue = Database::queryOne(
            "SELECT 
                SUM(value) as total_revenue,
                COUNT(*) as deal_count,
                AVG(value) as avg_deal_value,
                MIN(created_at) as first_deal_date,
                MAX(created_at) as last_deal_date
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ? AND stage = 'won'",
            [$workspaceId, $contactId]
        );
        
        $totalRevenue = (float) ($historicalRevenue['total_revenue'] ?? 0);
        $dealCount = (int) ($historicalRevenue['deal_count'] ?? 0);
        $avgDealValue = (float) ($historicalRevenue['avg_deal_value'] ?? 0);
        
        // Calculate customer age
        $customerAge = 0;
        if (!empty($historicalRevenue['first_deal_date'])) {
            $customerAge = (time() - strtotime($historicalRevenue['first_deal_date'])) / (365.25 * 86400);
        }
        
        // Calculate purchase frequency
        $purchaseFrequency = $customerAge > 0 ? $dealCount / $customerAge : 0;
        
        // Predict future value
        $avgCustomerLifespan = 3; // years (industry average, can be adjusted)
        $predictedFutureDeals = $purchaseFrequency * ($avgCustomerLifespan - $customerAge);
        $predictedFutureValue = $predictedFutureDeals * $avgDealValue;
        
        // Total CLV
        $clv = $totalRevenue + $predictedFutureValue;
        
        return [
            'contact_id' => $contactId,
            'historical_revenue' => round($totalRevenue, 2),
            'deal_count' => $dealCount,
            'avg_deal_value' => round($avgDealValue, 2),
            'customer_age_years' => round($customerAge, 2),
            'purchase_frequency' => round($purchaseFrequency, 2),
            'predicted_future_value' => round($predictedFutureValue, 2),
            'customer_lifetime_value' => round($clv, 2),
            'confidence' => $this->calculateCLVConfidence($dealCount, $customerAge)
        ];
    }
    
    /**
     * Calculate CLV confidence
     */
    private function calculateCLVConfidence(int $dealCount, float $customerAge): float
    {
        $confidence = 0.5;
        
        if ($dealCount > 3) {
            $confidence += 0.2;
        }
        
        if ($customerAge > 1) {
            $confidence += 0.2;
        }
        
        if ($dealCount > 5 && $customerAge > 2) {
            $confidence += 0.1;
        }
        
        return min(0.95, $confidence);
    }
    
    /**
     * Get predictive dashboard data
     */
    public function getDashboardData(): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        // Lead conversion predictions
        $highValueLeads = Database::query(
            "SELECT id, first_name, last_name, email, lead_score, stage 
             FROM contacts 
             WHERE workspace_id = ?
             AND stage NOT IN ('won', 'lost') 
             AND lead_score > 50 
             ORDER BY lead_score DESC 
             LIMIT 10",
            [$workspaceId]
        );
        
        $conversionPredictions = [];
        foreach ($highValueLeads as $lead) {
            $prediction = $this->predictLeadConversion($lead['id']);
            $conversionPredictions[] = [
                'contact' => $lead,
                'conversion_probability' => $prediction['conversion_probability'],
                'time_to_conversion' => $prediction['time_to_conversion']
            ];
        }
        
        // Churn predictions
        $atRiskContacts = Database::query(
            "SELECT id, first_name, last_name, email, lead_score, stage 
             FROM contacts 
             WHERE workspace_id = ?
             AND stage = 'won' 
             ORDER BY id DESC 
             LIMIT 10",
            [$workspaceId]
        );
        
        $churnPredictions = [];
        foreach ($atRiskContacts as $contact) {
            $prediction = $this->predictChurn($contact['id']);
            if ($prediction['churn_probability'] > 30) {
                $churnPredictions[] = [
                    'contact' => $contact,
                    'churn_probability' => $prediction['churn_probability'],
                    'risk_factors' => $prediction['risk_factors']
                ];
            }
        }
        
        // Revenue forecast
        $revenueForecast = $this->forecastRevenue('month', 6);
        
        return [
            'conversion_predictions' => $conversionPredictions,
            'churn_predictions' => $churnPredictions,
            'revenue_forecast' => $revenueForecast,
            'summary' => [
                'high_probability_leads' => count(array_filter($conversionPredictions, fn($p) => $p['conversion_probability'] > 70)),
                'at_risk_customers' => count($churnPredictions),
                'forecasted_revenue_6m' => $revenueForecast['total_forecasted']
            ]
        ];
    }
}
