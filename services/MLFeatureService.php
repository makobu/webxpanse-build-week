<?php
/**
 * ML Feature Service
 * Enhanced feature extraction for machine learning models
 * Extracts 50+ features from contact data for ML training and prediction
 */

namespace CRM\Services;

use CRM\Database;
use CRM\CacheManager;
use CRM\Modules\PredictiveAnalytics;

class MLFeatureService
{
    private CacheManager $cache;
    private PredictiveAnalytics $predictiveAnalytics;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private array $featureCache = [];
    
    public function __construct(?AnalyticsWorkspaceService $analyticsWorkspace = null)
    {
        $this->cache = new CacheManager();
        $this->predictiveAnalytics = new PredictiveAnalytics();
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }
    
    /**
     * Extract all features for a contact (50+ features)
     */
    public function extractAllFeatures(int $contactId, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        
        // Get base contact data
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [$resolvedWorkspaceId, $contactId]
        );
        
        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        // Check cache after proving the contact belongs to the requested workspace.
        $cacheKey = $this->cacheKey($contactId, $resolvedWorkspaceId);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $features = [];
        
        // 1. Behavioral Features (20 features)
        $features = array_merge($features, $this->extractBehavioralFeatures($contactId, $contact, $resolvedWorkspaceId));
        
        // 2. Engagement Features (10 features)
        $features = array_merge($features, $this->extractEngagementFeatures($contactId, $contact, $resolvedWorkspaceId));
        
        // 3. Deal Features (10 features)
        $features = array_merge($features, $this->extractDealFeatures($contactId, $contact, $resolvedWorkspaceId));
        
        // 4. Temporal Features (8 features)
        $features = array_merge($features, $this->extractTemporalFeatures($contactId, $contact, $resolvedWorkspaceId));
        
        // 5. Demographic Features (12 features)
        $features = array_merge($features, $this->extractDemographicFeatures($contactId, $contact));
        
        // 6. Enrichment Features (5 features)
        $features = array_merge($features, $this->extractEnrichmentFeatures($contactId, $contact));
        
        // Cache for 1 hour
        $this->cache->set($cacheKey, $features, 3600);
        
        return $features;
    }
    
    /**
     * Extract behavioral features
     */
    private function extractBehavioralFeatures(int $contactId, array $contact, int $workspaceId): array
    {
        // Email engagement
        $emailStats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_emails_sent,
                COUNT(CASE WHEN status = 'opened' THEN 1 END) as emails_opened,
                COUNT(CASE WHEN status = 'clicked' THEN 1 END) as emails_clicked,
                COUNT(CASE WHEN status = 'replied' THEN 1 END) as emails_replied,
                COUNT(CASE WHEN status = 'bounced' THEN 1 END) as emails_bounced
             FROM emails 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $totalSent = (int) ($emailStats['total_emails_sent'] ?? 0);
        $totalOpened = (int) ($emailStats['emails_opened'] ?? 0);
        $totalClicked = (int) ($emailStats['emails_clicked'] ?? 0);
        $totalReplied = (int) ($emailStats['emails_replied'] ?? 0);
        
        // Email tracking stats
        $trackingStats = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN tracking_type = 'open' THEN 1 END) as total_opens,
                COUNT(CASE WHEN tracking_type = 'click' THEN 1 END) as total_clicks,
                COUNT(DISTINCT DATE(tracked_at)) as unique_days_engaged
             FROM email_tracking et
             JOIN emails e ON et.email_id = e.id
             WHERE e.workspace_id = ? AND e.contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        // Activity stats
        $activityStats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_activities,
                COUNT(CASE WHEN activity_type = 'email' THEN 1 END) as email_activities,
                COUNT(CASE WHEN activity_type = 'call' THEN 1 END) as call_activities,
                COUNT(CASE WHEN activity_type = 'meeting' THEN 1 END) as meeting_activities,
                COUNT(CASE WHEN activity_type = 'note' THEN 1 END) as note_activities,
                COUNT(CASE WHEN activity_type = 'form_submit' THEN 1 END) as form_submissions,
                COUNT(CASE WHEN activity_type = 'page_visited' THEN 1 END) as page_visits,
                COUNT(CASE WHEN activity_type = 'link_clicked' THEN 1 END) as link_clicks,
                COUNT(DISTINCT DATE(created_at)) as active_days,
                COUNT(DISTINCT activity_type) as activity_types_count
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $totalActivities = (int) ($activityStats['total_activities'] ?? 0);
        
        return [
            // Email engagement rates
            'email_open_rate' => $totalSent > 0 ? ($totalOpened / $totalSent) : 0,
            'email_click_rate' => $totalSent > 0 ? ($totalClicked / $totalSent) : 0,
            'email_reply_rate' => $totalSent > 0 ? ($totalReplied / $totalSent) : 0,
            'email_bounce_rate' => $totalSent > 0 ? (($emailStats['emails_bounced'] ?? 0) / $totalSent) : 0,
            'total_emails_sent' => $totalSent,
            'total_emails_opened' => $totalOpened,
            'total_emails_clicked' => $totalClicked,
            'total_email_opens' => (int) ($trackingStats['total_opens'] ?? 0),
            'total_email_clicks' => (int) ($trackingStats['total_clicks'] ?? 0),
            'unique_email_engagement_days' => (int) ($trackingStats['unique_days_engaged'] ?? 0),
            
            // Activity counts
            'total_activities' => $totalActivities,
            'email_activities' => (int) ($activityStats['email_activities'] ?? 0),
            'call_activities' => (int) ($activityStats['call_activities'] ?? 0),
            'meeting_activities' => (int) ($activityStats['meeting_activities'] ?? 0),
            'form_submissions' => (int) ($activityStats['form_submissions'] ?? 0),
            'page_visits' => (int) ($activityStats['page_visits'] ?? 0),
            'link_clicks' => (int) ($activityStats['link_clicks'] ?? 0),
            'active_days' => (int) ($activityStats['active_days'] ?? 0),
            'activity_types_diversity' => (int) ($activityStats['activity_types_count'] ?? 0),
            'activity_frequency' => $totalActivities > 0 ? ($totalActivities / max(1, (int) ($activityStats['active_days'] ?? 1))) : 0
        ];
    }
    
    /**
     * Extract engagement features
     */
    private function extractEngagementFeatures(int $contactId, array $contact, int $workspaceId): array
    {
        // Response rate (replies received / emails sent to contact)
        $responseStats = Database::queryOne(
            "SELECT 
                (SELECT COUNT(*)
                 FROM emails
                 WHERE workspace_id = ? AND contact_id = ? AND status = 'sent') as emails_sent_to_contact,
                (SELECT COUNT(*)
                 FROM communications
                 WHERE workspace_id = ? AND contact_id = ? AND direction = 'inbound' AND channel = 'email') as emails_received_from_contact",
            [$workspaceId, $contactId, $workspaceId, $contactId]
        );
        
        $emailsSentToContact = (int) ($responseStats['emails_sent_to_contact'] ?? 0);
        $emailsReceivedFromContact = (int) ($responseStats['emails_received_from_contact'] ?? 0);
        
        // Activity velocity (activities in different time windows)
        $velocityStats = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as activities_7d,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as activities_30d,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 END) as activities_90d
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        // Time to first response
        $firstResponse = Database::queryOne(
            "SELECT 
                MIN(c.created_at) as first_response_at,
                MIN(e.created_at) as first_email_sent_at
             FROM communications c
             LEFT JOIN emails e ON e.workspace_id = c.workspace_id AND e.contact_id = c.contact_id
             WHERE c.workspace_id = ? AND c.contact_id = ? AND c.direction = 'inbound' AND c.channel = 'email'
             GROUP BY c.contact_id",
            [$workspaceId, $contactId]
        );
        
        $timeToFirstResponse = 0;
        if (!empty($firstResponse['first_email_sent_at']) && !empty($firstResponse['first_response_at'])) {
            $timeToFirstResponse = (strtotime($firstResponse['first_response_at']) - strtotime($firstResponse['first_email_sent_at'])) / 3600; // hours
        }
        
        // Engagement trend (increasing/decreasing)
        $recentActivities = (int) ($velocityStats['activities_7d'] ?? 0);
        $olderActivities = (int) ($velocityStats['activities_30d'] ?? 0) - $recentActivities;
        $engagementTrend = $olderActivities > 0 ? ($recentActivities / $olderActivities) : ($recentActivities > 0 ? 1 : 0);
        
        return [
            'response_rate' => $emailsSentToContact > 0 ? ($emailsReceivedFromContact / $emailsSentToContact) : 0,
            'emails_sent_to_contact' => $emailsSentToContact,
            'emails_received_from_contact' => $emailsReceivedFromContact,
            'activities_7d' => (int) ($velocityStats['activities_7d'] ?? 0),
            'activities_30d' => (int) ($velocityStats['activities_30d'] ?? 0),
            'activities_90d' => (int) ($velocityStats['activities_90d'] ?? 0),
            'engagement_velocity' => (int) ($velocityStats['activities_7d'] ?? 0) / 7.0, // per day
            'time_to_first_response_hours' => max(0, $timeToFirstResponse),
            'engagement_trend' => $engagementTrend,
            'engagement_acceleration' => $recentActivities > 0 && $olderActivities > 0 ? ($recentActivities - $olderActivities) / $olderActivities : 0
        ];
    }
    
    /**
     * Extract deal features
     */
    private function extractDealFeatures(int $contactId, array $contact, int $workspaceId): array
    {
        $dealStats = Database::queryOne(
            "SELECT 
                COUNT(*) as deal_count,
                SUM(value) as total_deal_value,
                AVG(value) as avg_deal_value,
                MAX(value) as max_deal_value,
                MIN(value) as min_deal_value,
                COUNT(CASE WHEN stage = 'closed_won' THEN 1 END) as won_deals,
                COUNT(CASE WHEN stage = 'closed_lost' THEN 1 END) as lost_deals,
                AVG(probability) as avg_probability,
                MIN(created_at) as first_deal_date,
                MAX(updated_at) as last_deal_update
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $dealCount = (int) ($dealStats['deal_count'] ?? 0);
        $wonDeals = (int) ($dealStats['won_deals'] ?? 0);
        $lostDeals = (int) ($dealStats['lost_deals'] ?? 0);
        
        // Deal stage progression
        $stageProgression = Database::queryOne(
            "SELECT 
                COUNT(DISTINCT stage) as stages_visited,
                AVG(DATEDIFF(updated_at, created_at)) as avg_days_per_stage
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ? AND stage NOT IN ('closed_won', 'closed_lost')",
            [$workspaceId, $contactId]
        );
        
        // Days in pipeline
        $daysInPipeline = 0;
        if (!empty($dealStats['first_deal_date'])) {
            $daysInPipeline = (time() - strtotime($dealStats['first_deal_date'])) / 86400;
        }
        
        // Deal value trend
        $recentDeals = Database::queryOne(
            "SELECT AVG(value) as avg_value
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$workspaceId, $contactId]
        );
        
        $olderDeals = Database::queryOne(
            "SELECT AVG(value) as avg_value
             FROM deals 
             WHERE workspace_id = ? AND contact_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$workspaceId, $contactId]
        );
        
        $recentAvg = (float) ($recentDeals['avg_value'] ?? 0);
        $olderAvg = (float) ($olderDeals['avg_value'] ?? 0);
        $dealValueTrend = $olderAvg > 0 ? (($recentAvg - $olderAvg) / $olderAvg) : ($recentAvg > 0 ? 1 : 0);
        
        return [
            'has_active_deals' => $dealCount > 0 ? 1 : 0,
            'deal_count' => $dealCount,
            'total_deal_value' => (float) ($dealStats['total_deal_value'] ?? 0),
            'avg_deal_value' => (float) ($dealStats['avg_deal_value'] ?? 0),
            'max_deal_value' => (float) ($dealStats['max_deal_value'] ?? 0),
            'win_rate' => ($wonDeals + $lostDeals) > 0 ? ($wonDeals / ($wonDeals + $lostDeals)) : 0,
            'won_deals' => $wonDeals,
            'lost_deals' => $lostDeals,
            'avg_deal_probability' => (float) ($dealStats['avg_probability'] ?? 0),
            'stages_visited' => (int) ($stageProgression['stages_visited'] ?? 0),
            'days_in_pipeline' => (int) $daysInPipeline,
            'deal_value_trend' => $dealValueTrend
        ];
    }
    
    /**
     * Extract temporal features
     */
    private function extractTemporalFeatures(int $contactId, array $contact, int $workspaceId): array
    {
        // Days since various events
        $firstActivity = Database::queryOne(
            "SELECT MIN(created_at) as first_activity FROM activities WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $lastActivity = Database::queryOne(
            "SELECT MAX(created_at) as last_activity FROM activities WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $lastEmail = Database::queryOne(
            "SELECT MAX(created_at) as last_email FROM emails WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        $lastMeeting = Database::queryOne(
            "SELECT MAX(created_at) as last_meeting 
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ? AND activity_type = 'meeting'",
            [$workspaceId, $contactId]
        );
        
        $contactCreated = strtotime($contact['created_at']);
        $firstActivityTime = !empty($firstActivity['first_activity']) ? strtotime($firstActivity['first_activity']) : $contactCreated;
        $lastActivityTime = !empty($lastActivity['last_activity']) ? strtotime($lastActivity['last_activity']) : $contactCreated;
        $lastEmailTime = !empty($lastEmail['last_email']) ? strtotime($lastEmail['last_email']) : 0;
        $lastMeetingTime = !empty($lastMeeting['last_meeting']) ? strtotime($lastMeeting['last_meeting']) : 0;
        
        $now = time();
        
        // Activity frequency trend
        $recentActivityCount = Database::queryOne(
            "SELECT COUNT(*) as count 
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)",
            [$workspaceId, $contactId]
        );
        
        $olderActivityCount = Database::queryOne(
            "SELECT COUNT(*) as count 
             FROM activities 
             WHERE workspace_id = ? AND contact_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
             AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)",
            [$workspaceId, $contactId]
        );
        
        $recentCount = (int) ($recentActivityCount['count'] ?? 0);
        $olderCount = (int) ($olderActivityCount['count'] ?? 0);
        $activityFrequencyTrend = $olderCount > 0 ? ($recentCount / $olderCount) : ($recentCount > 0 ? 1 : 0);
        
        return [
            'days_since_first_contact' => (int) (($now - $firstActivityTime) / 86400),
            'days_since_last_activity' => (int) (($now - $lastActivityTime) / 86400),
            'days_since_last_email' => $lastEmailTime > 0 ? (int) (($now - $lastEmailTime) / 86400) : 999,
            'days_since_last_meeting' => $lastMeetingTime > 0 ? (int) (($now - $lastMeetingTime) / 86400) : 999,
            'contact_age_days' => (int) (($now - $contactCreated) / 86400),
            'activity_frequency_trend' => $activityFrequencyTrend,
            'engagement_trend_direction' => $recentCount > $olderCount ? 1 : ($recentCount < $olderCount ? -1 : 0),
            'time_to_first_activity_days' => (int) (($firstActivityTime - $contactCreated) / 86400)
        ];
    }
    
    /**
     * Extract demographic features
     */
    private function extractDemographicFeatures(int $contactId, array $contact): array
    {
        // Company size encoding (small: 1-50, medium: 51-500, large: 501+)
        $companySize = $contact['company_size'] ?? '';
        $companySizeEncoded = 0;
        if (stripos($companySize, 'small') !== false || preg_match('/\b([1-9]|[1-4][0-9])\b/', $companySize)) {
            $companySizeEncoded = 1; // Small
        } elseif (stripos($companySize, 'medium') !== false || preg_match('/\b(5[0-9]|[1-4][0-9]{2}|500)\b/', $companySize)) {
            $companySizeEncoded = 2; // Medium
        } elseif (stripos($companySize, 'large') !== false || preg_match('/\b(50[1-9]|[5-9][0-9]{2}|[1-9][0-9]{3,})\b/', $companySize)) {
            $companySizeEncoded = 3; // Large
        }
        
        // Job title level (executive: 3, manager: 2, individual: 1, unknown: 0)
        $jobTitle = strtolower($contact['job_title'] ?? '');
        $jobTitleLevel = 0;
        $executiveKeywords = ['ceo', 'cto', 'cfo', 'president', 'founder', 'owner', 'director', 'vp', 'vice president'];
        $managerKeywords = ['manager', 'head', 'lead', 'supervisor', 'coordinator'];
        
        foreach ($executiveKeywords as $keyword) {
            if (stripos($jobTitle, $keyword) !== false) {
                $jobTitleLevel = 3;
                break;
            }
        }
        
        if ($jobTitleLevel === 0) {
            foreach ($managerKeywords as $keyword) {
                if (stripos($jobTitle, $keyword) !== false) {
                    $jobTitleLevel = 2;
                    break;
                }
            }
        }
        
        if ($jobTitleLevel === 0 && !empty($jobTitle)) {
            $jobTitleLevel = 1; // Individual contributor
        }
        
        // Industry encoding (one-hot would be too many, use top industries)
        $industry = strtolower($contact['company_industry'] ?? '');
        $industryEncoded = [
            'technology' => stripos($industry, 'tech') !== false || stripos($industry, 'software') !== false || stripos($industry, 'it') !== false,
            'finance' => stripos($industry, 'finance') !== false || stripos($industry, 'banking') !== false || stripos($industry, 'financial') !== false,
            'healthcare' => stripos($industry, 'health') !== false || stripos($industry, 'medical') !== false,
            'retail' => stripos($industry, 'retail') !== false || stripos($industry, 'ecommerce') !== false,
            'manufacturing' => stripos($industry, 'manufactur') !== false || stripos($industry, 'production') !== false,
            'education' => stripos($industry, 'education') !== false || stripos($industry, 'school') !== false,
            'consulting' => stripos($industry, 'consult') !== false,
            'real_estate' => stripos($industry, 'real estate') !== false || stripos($industry, 'property') !== false
        ];
        
        // Location encoding
        $location = strtolower($contact['location'] ?? '');
        $isUS = stripos($location, 'united states') !== false || stripos($location, 'usa') !== false || stripos($location, 'us,') !== false;
        $isEU = stripos($location, 'europe') !== false || stripos($location, 'uk') !== false || stripos($location, 'germany') !== false || stripos($location, 'france') !== false;
        
        // Lead source encoding
        $leadSource = $contact['lead_source'] ?? 'other';
        $leadSourceEncoded = [
            'form' => $leadSource === 'form' ? 1 : 0,
            'whatsapp' => $leadSource === 'whatsapp' ? 1 : 0,
            'ad' => $leadSource === 'ad' ? 1 : 0,
            'referral' => $leadSource === 'referral' ? 1 : 0,
            'social' => $leadSource === 'social' ? 1 : 0
        ];
        
        return [
            'has_company' => !empty($contact['company']) ? 1 : 0,
            'company_size_encoded' => $companySizeEncoded,
            'job_title_level' => $jobTitleLevel,
            'has_company_website' => !empty($contact['company_website']) ? 1 : 0,
            'industry_technology' => $industryEncoded['technology'] ? 1 : 0,
            'industry_finance' => $industryEncoded['finance'] ? 1 : 0,
            'industry_healthcare' => $industryEncoded['healthcare'] ? 1 : 0,
            'industry_retail' => $industryEncoded['retail'] ? 1 : 0,
            'location_us' => $isUS ? 1 : 0,
            'location_eu' => $isEU ? 1 : 0,
            'lead_source_form' => $leadSourceEncoded['form'],
            'lead_source_referral' => $leadSourceEncoded['referral']
        ];
    }
    
    /**
     * Extract enrichment features
     */
    private function extractEnrichmentFeatures(int $contactId, array $contact): array
    {
        // Data completeness score
        $fields = ['first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 
                   'company_website', 'company_size', 'company_industry', 'location', 
                   'linkedin_url', 'twitter_url'];
        $filledFields = 0;
        foreach ($fields as $field) {
            if (!empty($contact[$field])) {
                $filledFields++;
            }
        }
        $dataCompleteness = count($fields) > 0 ? ($filledFields / count($fields)) : 0;
        
        return [
            'enrichment_score' => (int) ($contact['enrichment_score'] ?? 0),
            'data_completeness' => $dataCompleteness,
            'has_linkedin' => !empty($contact['linkedin_url']) ? 1 : 0,
            'has_twitter' => !empty($contact['twitter_url']) ? 1 : 0,
            'email_verified' => !empty($contact['email_verified']) ? 1 : 0
        ];
    }
    
    /**
     * Normalize features for ML (standardize numerical features)
     */
    public function normalizeFeatures(array $features): array
    {
        // Get feature statistics for normalization (would ideally be pre-computed)
        $normalized = [];
        
        foreach ($features as $name => $value) {
            // Skip boolean and encoded features
            if (is_bool($value) || (is_numeric($value) && ($value === 0 || $value === 1))) {
                $normalized[$name] = $value;
                continue;
            }
            
            // Normalize numerical features (min-max normalization to 0-1)
            // For production, use pre-computed min/max from training data
            if (is_numeric($value)) {
                // Simple normalization - in production, use actual min/max from training set
                $normalized[$name] = max(0, min(1, $value / 100.0)); // Rough normalization
            } else {
                $normalized[$name] = $value;
            }
        }
        
        return $normalized;
    }
    
    /**
     * Get list of all available feature names
     */
    public function getFeatureNames(): array
    {
        return [
            // Behavioral
            'email_open_rate', 'email_click_rate', 'email_reply_rate', 'email_bounce_rate',
            'total_emails_sent', 'total_emails_opened', 'total_emails_clicked', 'total_email_opens',
            'total_email_clicks', 'unique_email_engagement_days', 'total_activities', 'email_activities',
            'call_activities', 'meeting_activities', 'form_submissions', 'page_visits', 'link_clicks',
            'active_days', 'activity_types_diversity', 'activity_frequency',
            
            // Engagement
            'response_rate', 'emails_sent_to_contact', 'emails_received_from_contact',
            'activities_7d', 'activities_30d', 'activities_90d', 'engagement_velocity',
            'time_to_first_response_hours', 'engagement_trend', 'engagement_acceleration',
            
            // Deal
            'has_active_deals', 'deal_count', 'total_deal_value', 'avg_deal_value', 'max_deal_value',
            'win_rate', 'won_deals', 'lost_deals', 'avg_deal_probability', 'stages_visited',
            'days_in_pipeline', 'deal_value_trend',
            
            // Temporal
            'days_since_first_contact', 'days_since_last_activity', 'days_since_last_email',
            'days_since_last_meeting', 'contact_age_days', 'activity_frequency_trend',
            'engagement_trend_direction', 'time_to_first_activity_days',
            
            // Demographic
            'has_company', 'company_size_encoded', 'job_title_level', 'has_company_website',
            'industry_technology', 'industry_finance', 'industry_healthcare', 'industry_retail',
            'location_us', 'location_eu', 'lead_source_form', 'lead_source_referral',
            
            // Enrichment
            'enrichment_score', 'data_completeness', 'has_linkedin', 'has_twitter', 'email_verified'
        ];
    }
    
    /**
     * Cache features for a contact
     */
    public function cacheFeatures(int $contactId, array $features, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $cacheKey = $this->cacheKey($contactId, $resolvedWorkspaceId);
        $this->cache->set($cacheKey, $features, 3600); // Cache for 1 hour
        $this->featureCache[$cacheKey] = $features;
    }
    
    /**
     * Clear cached features for a contact
     */
    public function clearCache(int $contactId, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $cacheKey = $this->cacheKey($contactId, $resolvedWorkspaceId);
        $this->cache->delete($cacheKey);
        unset($this->featureCache[$cacheKey]);
    }
    
    /**
     * Get feature statistics for normalization
     */
    public function getFeatureStatistics(): array
    {
        // This would ideally query historical data to compute min/max/mean/std
        // For now, return empty array - will be computed during training
        return [];
    }

    private function cacheKey(int $contactId, int $workspaceId): string
    {
        return "ml_features_{$workspaceId}_{$contactId}";
    }
}
