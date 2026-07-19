<?php
/**
 * Lead Scoring Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AnalyticsWorkspaceService;

class LeadScoring
{
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private array $scoringRules = [
        'email' => ['weight' => 8, 'decay' => 0.9],
        'call' => ['weight' => 12, 'decay' => 0.9],
        'meeting' => ['weight' => 18, 'decay' => 0.85],
        'note' => ['weight' => 3, 'decay' => 0.95],
        'form_submit' => ['weight' => 20, 'decay' => 0.8],
        'email_opened' => ['weight' => 5, 'decay' => 0.9],
        'link_clicked' => ['weight' => 10, 'decay' => 0.85],
        'form_submitted' => ['weight' => 20, 'decay' => 0.8],
        'page_visited' => ['weight' => 15],
    ];

    public function __construct(?AnalyticsWorkspaceService $analyticsWorkspace = null)
    {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }
    
    /**
     * Calculate engagement-based score (data-driven)
     * Measures actual engagement and interaction with the contact
     * 
     * @param int $contactId Contact ID
     * @return array Score with metadata. Persists engagement_score only.
     */
    public function calculateEngagementScore(int $contactId): array
    {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);

        $activities = Database::query(
            "SELECT * FROM activities 
             WHERE workspace_id = ?
             AND contact_id = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY created_at DESC",
            [$workspaceId, $contactId]
        );
        
        $totalScore = 0;
        $activityCount = count($activities);
        $activityBreakdown = [];
        $recentActivity = null;
        
        foreach ($activities as $activity) {
            $rule = $this->scoringRules[$activity['activity_type']] ?? null;
            if (!$rule) continue;
            
            $daysAgo = (time() - strtotime($activity['created_at'])) / 86400;
            $decayFactor = isset($rule['decay']) ? pow($rule['decay'], $daysAgo) : 1.0;
            $score = $rule['weight'] * $decayFactor;
            $totalScore += $score;
            
            // Track breakdown
            $type = $activity['activity_type'];
            if (!isset($activityBreakdown[$type])) {
                $activityBreakdown[$type] = ['count' => 0, 'points' => 0];
            }
            $activityBreakdown[$type]['count']++;
            $activityBreakdown[$type]['points'] += $score;
            
            // Track most recent activity
            if (!$recentActivity || strtotime($activity['created_at']) > strtotime($recentActivity['created_at'])) {
                $recentActivity = $activity;
            }
        }
        
        // Cap between 0-100
        $totalScore = max(0, min(100, (int) round($totalScore)));
        
        // Update contact engagement_score field
        Database::execute(
            "UPDATE contacts SET engagement_score = ? WHERE workspace_id = ? AND id = ?",
            [$totalScore, $workspaceId, $contactId]
        );
        
        return [
            'score' => $totalScore,
            'activity_count' => $activityCount,
            'activity_breakdown' => $activityBreakdown,
            'recent_activity' => $recentActivity ? [
                'type' => $recentActivity['activity_type'],
                'date' => $recentActivity['created_at']
            ] : null
        ];
    }
    
    /**
     * Calculate lead score (backward compatibility)
     * Returns the engagement-only score and persists engagement_score only.
     * The canonical composite lead_score is owned by AILeadScoring::recalculateScore().
     *
     * @deprecated Use calculateEngagementScore() for engagement-only persistence,
     *             or AILeadScoring for consolidated scoring.
     */
    public function calculateScore(int $contactId): int
    {
        $result = $this->calculateEngagementScore($contactId);

        return (int) ($result['score'] ?? 0);
    }

    private function assertActiveWorkspaceContact(int $contactId): int
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        );

        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        return $workspaceId;
    }
}
