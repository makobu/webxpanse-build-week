<?php
/**
 * Analytics Dashboard Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\WorkspaceContext;

class AnalyticsDashboard
{
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    /**
     * Get real-time metrics
     */
    public function getRealTimeMetrics(string $timeframe = 'today', ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $dateFilter = $this->dateRangeCondition('created_at', $timeframe);
        
        // Adjust date filter for different tables
        $emailDateFilter = $this->dateRangeCondition('created_at', $timeframe);
        
        $formDateFilter = $this->dateRangeCondition('submitted_at', $timeframe);

        $contactParams = [];
        $contactWhere = $this->appendWorkspaceCondition($dateFilter, $contactParams, 'workspace_id', $workspaceId);
        $contactWhere = $this->appendUserCondition($contactWhere, $contactParams, 'assigned_to', $userId);

        $emailParams = [];
        $sentEmailStatuses = "status IN ('sent', 'delivered', 'opened', 'clicked')";
        $emailWhere = $this->appendWorkspaceCondition("$emailDateFilter AND $sentEmailStatuses", $emailParams, 'workspace_id', $workspaceId);
        $emailWhere = $this->appendUserCondition($emailWhere, $emailParams, 'user_id', $userId);

        $emailOpenParams = [$workspaceId];
        $emailOpenDateFilter = $this->dateRangeCondition('et.tracked_at', $timeframe);
        $emailOpenWhere = "e.workspace_id = ? AND et.tracking_type = 'open' AND $emailOpenDateFilter";
        if ($userId !== null) {
            $emailOpenWhere .= " AND e.user_id = ?";
            $emailOpenParams[] = $userId;
        }

        $formParams = [$workspaceId];
        $formWhere = "workspace_id = ? AND {$formDateFilter}";
        if ($userId !== null) {
            $formWhere .= " AND EXISTS (
                SELECT 1
                FROM contacts c
                WHERE c.id = form_submissions.contact_id
                  AND c.workspace_id = form_submissions.workspace_id
                  AND c.assigned_to = ?
            )";
            $formParams[] = $userId;
        }

        $metrics = [
            'leads' => (int) (Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE $contactWhere", $contactParams)['count'] ?? 0),
            'emails_sent' => (int) (Database::queryOne("SELECT COUNT(*) as count FROM emails WHERE $emailWhere", $emailParams)['count'] ?? 0),
            'emails_opened' => (int) (Database::queryOne(
                "SELECT COUNT(DISTINCT e.id) as count
                 FROM email_tracking et
                 INNER JOIN emails e ON e.id = et.email_id
                 WHERE $emailOpenWhere",
                $emailOpenParams
            )['count'] ?? 0),
            'form_submissions' => (int) (Database::queryOne("SELECT COUNT(*) as count FROM form_submissions WHERE $formWhere", $formParams)['count'] ?? 0),
        ];
        
        return $metrics;
    }

    private function dateRangeCondition(string $column, string $timeframe): string
    {
        return match($timeframe) {
            'today' => "{$column} >= CURDATE() AND {$column} < DATE_ADD(CURDATE(), INTERVAL 1 DAY)",
            'week' => "{$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "{$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "{$column} >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
            'all' => "1=1",
            default => "{$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };
    }
    
    /**
     * Get funnel analysis
     */
    public function getFunnelAnalysis(string $startDate, string $endDate, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
        $funnel = [];
        
        foreach ($stages as $index => $stage) {
            $params = [$workspaceId, $stage, $startDate, $endDate];
            $userFilter = '';
            if ($userId !== null) {
                $userFilter = " AND assigned_to = ?";
                $params[] = $userId;
            }
            $count = (int) Database::queryOne(
                "SELECT COUNT(*) as count FROM contacts 
                 WHERE workspace_id = ?
                 AND stage = ? 
                 AND created_at BETWEEN ? AND ?{$userFilter}",
                $params
            )['count'] ?? 0;
            
            $funnel[$stage] = [
                'count' => $count,
                'conversion_rate' => 0
            ];
            
            if ($index > 0) {
                $previousStage = $stages[$index - 1];
                $previousCount = $funnel[$previousStage]['count'] ?? 0;
                
                if ($previousCount > 0) {
                    $funnel[$stage]['conversion_rate'] = ($count / $previousCount) * 100;
                }
            }
        }
        
        return $funnel;
    }
    
    /**
     * Get period comparison (current vs previous period)
     */
    public function getPeriodComparison(string $currentPeriod, string $previousPeriod, ?int $userId = null): array
    {
        $currentMetrics = $this->getRealTimeMetrics($currentPeriod, $userId);
        $previousMetrics = $this->getRealTimeMetrics($previousPeriod, $userId);
        
        $comparison = [];
        foreach ($currentMetrics as $key => $currentValue) {
            $previousValue = $previousMetrics[$key] ?? 0;
            $change = $previousValue > 0 ? (($currentValue - $previousValue) / $previousValue) * 100 : ($currentValue > 0 ? 100 : 0);
            
            $comparison[$key] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'change' => round($change, 1),
                'change_absolute' => $currentValue - $previousValue,
                'trend' => $change > 5 ? 'up' : ($change < -5 ? 'down' : 'neutral')
            ];
        }
        
        return $comparison;
    }
    
    /**
     * Get growth metrics
     */
    public function getGrowthMetrics(string $timeframe, ?int $userId = null): array
    {
        $currentPeriod = $timeframe;
        $previousPeriod = match($timeframe) {
            'today' => 'week',
            'week' => 'month',
            'month' => 'year',
            default => 'month'
        };
        
        $comparison = $this->getPeriodComparison($currentPeriod, $previousPeriod, $userId);
        
        // Calculate growth rates
        $growthRates = [];
        foreach ($comparison as $key => $data) {
            $growthRates[$key] = [
                'growth_rate' => $data['change'],
                'momentum' => abs($data['change']) > 10 ? ($data['change'] > 0 ? 'accelerating' : 'decelerating') : 'stable'
            ];
        }
        
        return [
            'comparison' => $comparison,
            'growth_rates' => $growthRates,
            'overall_growth' => array_sum(array_column($comparison, 'change')) / count($comparison)
        ];
    }
    
    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(string $startDate, string $endDate, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        if (WorkspaceContext::isDefaultWorkspace($workspaceId)) {
            return $this->getDefaultWorkspaceSubscriptionRevenueAnalytics($startDate, $endDate);
        }

        $wonParams = [$workspaceId, $startDate, $endDate];
        $wonOwnerFilter = $userId !== null ? " AND assigned_to = ?" : '';
        if ($userId !== null) {
            $wonParams[] = $userId;
        }

        // Total revenue (won deals)
        $wonRevenue = Database::queryOne(
            "SELECT 
                COUNT(*) as deal_count,
                SUM(value) as total_revenue,
                AVG(value) as avg_deal_size,
                MIN(value) as min_deal_size,
                MAX(value) as max_deal_size
             FROM deals
             WHERE workspace_id = ?
             AND stage = 'closed_won'
             AND actual_close_date BETWEEN ? AND ?{$wonOwnerFilter}",
            $wonParams
        );
        
        // Revenue by stage (weighted by probability)
        $stageParams = [$workspaceId];
        $stageOwnerFilter = '';
        if ($userId !== null) {
            $stageOwnerFilter = " AND assigned_to = ?";
            $stageParams[] = $userId;
        }
        $revenueByStage = Database::query(
            "SELECT 
                stage,
                COUNT(*) as deal_count,
                SUM(value) as total_value,
                SUM(value * (probability / 100.0)) as weighted_value,
                AVG(value) as avg_value,
                AVG(probability) as avg_probability
             FROM deals
             WHERE workspace_id = ?
             AND stage NOT IN ('closed_won', 'closed_lost')
             {$stageOwnerFilter}
             GROUP BY stage",
            $stageParams
        );
        
        // Revenue trend (monthly)
        $trendParams = [$workspaceId, $endDate];
        $trendOwnerFilter = '';
        if ($userId !== null) {
            $trendOwnerFilter = " AND assigned_to = ?";
            $trendParams[] = $userId;
        }
        $revenueTrend = Database::query(
            "SELECT 
                DATE_FORMAT(actual_close_date, '%Y-%m') as month,
                COUNT(*) as deal_count,
                SUM(value) as revenue
             FROM deals
             WHERE workspace_id = ?
             AND stage = 'closed_won'
             AND actual_close_date >= DATE_SUB(?, INTERVAL 12 MONTH)
             {$trendOwnerFilter}
             GROUP BY DATE_FORMAT(actual_close_date, '%Y-%m')
             ORDER BY month ASC",
            $trendParams
        );
        
        // Win rate
        $winLossParams = [$workspaceId, $startDate, $endDate];
        $winLossOwnerFilter = '';
        if ($userId !== null) {
            $winLossOwnerFilter = " AND assigned_to = ?";
            $winLossParams[] = $userId;
        }
        $winLossStats = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN stage = 'closed_won' THEN 1 END) as won_count,
                COUNT(CASE WHEN stage = 'closed_lost' THEN 1 END) as lost_count,
                COUNT(CASE WHEN stage IN ('closed_won', 'closed_lost') THEN 1 END) as total_closed
             FROM deals
             WHERE workspace_id = ?
             AND actual_close_date BETWEEN ? AND ?{$winLossOwnerFilter}",
            $winLossParams
        );
        
        $winRate = ($winLossStats['total_closed'] ?? 0) > 0 
            ? (($winLossStats['won_count'] ?? 0) / ($winLossStats['total_closed'] ?? 1)) * 100 
            : 0;
        
        // Average sales cycle length
        $salesCycleParams = [$workspaceId, $startDate, $endDate];
        $salesCycleOwnerFilter = '';
        if ($userId !== null) {
            $salesCycleOwnerFilter = " AND assigned_to = ?";
            $salesCycleParams[] = $userId;
        }
        $salesCycle = Database::queryOne(
            "SELECT 
                AVG(DATEDIFF(actual_close_date, created_at)) as avg_cycle_days
             FROM deals
             WHERE workspace_id = ?
             AND stage = 'closed_won'
             AND actual_close_date BETWEEN ? AND ?
             AND actual_close_date IS NOT NULL
             AND created_at IS NOT NULL{$salesCycleOwnerFilter}",
            $salesCycleParams
        );
        
        // Revenue by lead source
        $sourceParams = [$workspaceId, $startDate, $endDate];
        $sourceOwnerFilter = '';
        if ($userId !== null) {
            $sourceOwnerFilter = " AND d.assigned_to = ?";
            $sourceParams[] = $userId;
        }
        $revenueBySource = Database::query(
            "SELECT 
                c.lead_source,
                COUNT(DISTINCT d.id) as deal_count,
                SUM(d.value) as total_revenue
             FROM deals d
             INNER JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.stage = 'closed_won'
             AND d.workspace_id = ?
             AND d.actual_close_date BETWEEN ? AND ?
             {$sourceOwnerFilter}
             AND c.lead_source IS NOT NULL
             GROUP BY c.lead_source
             ORDER BY total_revenue DESC",
            $sourceParams
        );
        
        return [
            'total_revenue' => (float) ($wonRevenue['total_revenue'] ?? 0),
            'deal_count' => (int) ($wonRevenue['deal_count'] ?? 0),
            'avg_deal_size' => (float) ($wonRevenue['avg_deal_size'] ?? 0),
            'min_deal_size' => (float) ($wonRevenue['min_deal_size'] ?? 0),
            'max_deal_size' => (float) ($wonRevenue['max_deal_size'] ?? 0),
            'revenue_by_stage' => $revenueByStage,
            'revenue_trend' => $revenueTrend,
            'win_rate' => round($winRate, 1),
            'loss_rate' => round(100 - $winRate, 1),
            'avg_sales_cycle_days' => round((float) ($salesCycle['avg_cycle_days'] ?? 0), 1),
            'revenue_by_source' => $revenueBySource
        ];
    }

    private function getDefaultWorkspaceSubscriptionRevenueAnalytics(string $startDate, string $endDate): array
    {
        $defaultWorkspaceId = (new DefaultWorkspaceService())->id();
        $summary = Database::queryOne(
            "SELECT
                COUNT(DISTINCT bt.workspace_id) AS paid_workspace_count,
                COUNT(*) AS subscription_charge_count,
                COALESCE(SUM(bt.amount), 0) AS total_revenue,
                COALESCE(AVG(bt.amount), 0) AS avg_subscription_charge,
                COALESCE(MIN(bt.amount), 0) AS min_subscription_charge,
                COALESCE(MAX(bt.amount), 0) AS max_subscription_charge
             FROM billing_transactions bt
             WHERE bt.transaction_type = 'subscription_charge'
               AND bt.transaction_status = 'succeeded'
               AND bt.workspace_id <> ?
               AND DATE(bt.created_at) BETWEEN ? AND ?",
            [$defaultWorkspaceId, $startDate, $endDate]
        ) ?? [];

        $revenueTrend = Database::query(
            "SELECT
                DATE_FORMAT(bt.created_at, '%Y-%m') AS month,
                COUNT(DISTINCT bt.workspace_id) AS deal_count,
                COALESCE(SUM(bt.amount), 0) AS revenue
             FROM billing_transactions bt
             WHERE bt.transaction_type = 'subscription_charge'
               AND bt.transaction_status = 'succeeded'
               AND bt.workspace_id <> ?
               AND bt.created_at >= DATE_SUB(?, INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(bt.created_at, '%Y-%m')
             ORDER BY month ASC",
            [$defaultWorkspaceId, $endDate]
        );

        $revenueBySource = Database::query(
            "SELECT
                COALESCE(c.lead_source, 'other') AS lead_source,
                COUNT(DISTINCT bt.workspace_id) AS deal_count,
                COALESCE(SUM(bt.amount), 0) AS total_revenue
             FROM billing_transactions bt
             LEFT JOIN contacts c
               ON c.workspace_id = ?
              AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.source')) = 'default_workspace_owner_contact'
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_id')) AS UNSIGNED) = bt.workspace_id
             WHERE bt.transaction_type = 'subscription_charge'
               AND bt.transaction_status = 'succeeded'
               AND bt.workspace_id <> ?
               AND DATE(bt.created_at) BETWEEN ? AND ?
             GROUP BY COALESCE(c.lead_source, 'other')
             ORDER BY total_revenue DESC",
            [$defaultWorkspaceId, $defaultWorkspaceId, $startDate, $endDate]
        );

        $paidWorkspaceCount = (int) ($summary['paid_workspace_count'] ?? 0);

        return [
            'total_revenue' => (float) ($summary['total_revenue'] ?? 0),
            'deal_count' => $paidWorkspaceCount,
            'avg_deal_size' => (float) ($summary['avg_subscription_charge'] ?? 0),
            'min_deal_size' => (float) ($summary['min_subscription_charge'] ?? 0),
            'max_deal_size' => (float) ($summary['max_subscription_charge'] ?? 0),
            'revenue_by_stage' => [],
            'revenue_trend' => $revenueTrend,
            'win_rate' => $paidWorkspaceCount > 0 ? 100.0 : 0.0,
            'loss_rate' => $paidWorkspaceCount > 0 ? 0.0 : 100.0,
            'avg_sales_cycle_days' => 0.0,
            'revenue_by_source' => $revenueBySource,
        ];
    }
    
    /**
     * Get activity patterns
     */
    public function getActivityPatterns(string $timeframe, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $dateFilter = match($timeframe) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
            'all' => "1=1",
            default => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };

        $activityParams = [];
        $activityWhere = $this->appendWorkspaceCondition($dateFilter, $activityParams, 'workspace_id', $workspaceId);
        if ($userId !== null) {
            $activityWhere .= " AND user_id = ?";
            $activityParams[] = $userId;
        }

        $byDayOfWeek = Database::query(
            "SELECT 
                DAYNAME(created_at) as day_name,
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as activity_count
             FROM activities
             WHERE $activityWhere
             GROUP BY DAYNAME(created_at), DAYOFWEEK(created_at)
             ORDER BY day_num",
            $activityParams
        );

        $byHour = Database::query(
            "SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as activity_count
             FROM activities
             WHERE $activityWhere
             GROUP BY HOUR(created_at)
             ORDER BY hour",
            $activityParams
        );

        $byType = Database::query(
            "SELECT 
                activity_type,
                COUNT(*) as count
             FROM activities
             WHERE $activityWhere
             GROUP BY activity_type
             ORDER BY count DESC",
            $activityParams
        );

        $responseParams = [$workspaceId, $workspaceId];
        $responseUserFilter = '';
        if ($userId !== null) {
            $responseUserFilter = " AND e1.user_id = ?";
            $responseParams[] = $userId;
        }
        $responseTimes = Database::queryOne(
            "SELECT 
                AVG(hours_diff) as avg_response_hours,
                MIN(hours_diff) as min_response_hours,
                MAX(hours_diff) as max_response_hours
             FROM (
                 SELECT 
                     TIMESTAMPDIFF(HOUR, 
                         (SELECT e2.created_at 
                          FROM emails e2 
                          WHERE e2.contact_id = e1.contact_id 
                          AND e2.created_at < e1.created_at 
                          ORDER BY e2.created_at DESC 
                          LIMIT 1),
                         e1.created_at
                     ) as hours_diff
                 FROM emails e1
                 WHERE e1.workspace_id = ?
                 AND e1.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 {$responseUserFilter}
                 AND EXISTS (
                     SELECT 1 FROM emails e2 
                     WHERE e2.contact_id = e1.contact_id 
                     AND e2.workspace_id = ?
                     AND e2.created_at < e1.created_at
                 )
             ) as response_times
             WHERE hours_diff IS NOT NULL AND hours_diff > 0",
            $responseParams
        );

        $channelParams = [$workspaceId, $workspaceId];
        $emailChannelFilter = " AND e.workspace_id = ?";
        $whatsAppChannelFilter = " AND workspace_id = ?";
        if ($userId !== null) {
            $emailChannelFilter = " AND e.workspace_id = ? AND e.user_id = ?";
            $whatsAppChannelFilter = " AND workspace_id = ? AND user_id = ?";
            $channelParams = [$workspaceId, $userId, $workspaceId, $userId];
        }
        $channelPerformance = Database::query(
            "SELECT 
                'email' as channel,
                COUNT(DISTINCT e.id) as sent,
                COUNT(DISTINCT CASE WHEN et.tracking_type = 'open' THEN et.email_id END) as opened,
                COUNT(DISTINCT CASE WHEN et.tracking_type = 'click' THEN et.email_id END) as clicked
             FROM emails e
             LEFT JOIN email_tracking et ON e.id = et.email_id
             WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND e.status IN ('sent', 'delivered', 'opened', 'clicked')
             {$emailChannelFilter}
             UNION ALL
             SELECT 
                'whatsapp' as channel,
                COUNT(*) as sent,
                0 as opened,
                0 as clicked
             FROM whatsapp_messages
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             {$whatsAppChannelFilter}",
            $channelParams
        );

        return [
            'by_day_of_week' => $byDayOfWeek,
            'by_hour' => $byHour,
            'by_type' => $byType,
            'response_times' => $responseTimes ?: null,
            'channel_performance' => $channelPerformance
        ];
    }
    
    /**
     * Get detailed conversion funnel with drop-off analysis
     */
    public function getConversionFunnel(string $startDate, string $endDate, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
        $funnel = [];
        
        foreach ($stages as $index => $stage) {
            // Get count and average dwell time
            $params = [$workspaceId, "%{$stage}%", $stage, $startDate, $endDate];
            $userFilter = '';
            if ($userId !== null) {
                $userFilter = " AND c.assigned_to = ?";
                $params[] = $userId;
            }
            $stageData = Database::queryOne(
                "SELECT 
                    COUNT(*) as count,
                    AVG(DATEDIFF(
                        COALESCE(
                            (SELECT MIN(created_at) FROM activities 
                             WHERE contact_id = c.id 
                             AND workspace_id = c.workspace_id
                             AND activity_type LIKE '%stage%' 
                             AND description LIKE ?),
                            c.updated_at
                        ),
                        c.created_at
                    )) as avg_dwell_days
                 FROM contacts c
                 WHERE c.workspace_id = ?
                 AND c.stage = ?
                 AND c.created_at BETWEEN ? AND ?{$userFilter}",
                $params
            );
            
            $count = (int) ($stageData['count'] ?? 0);
            $avgDwellDays = round((float) ($stageData['avg_dwell_days'] ?? 0), 1);
            
            // Calculate drop-off from previous stage
            $dropOffRate = 0;
            $conversionRate = 0;
            if ($index > 0) {
                $previousStage = $stages[$index - 1];
                $previousCount = $funnel[$previousStage]['count'] ?? 0;
                
                if ($previousCount > 0) {
                    $dropOffRate = (($previousCount - $count) / $previousCount) * 100;
                    $conversionRate = ($count / $previousCount) * 100;
                }
            }
            
            $funnel[$stage] = [
                'count' => $count,
                'avg_dwell_days' => $avgDwellDays,
                'drop_off_rate' => round($dropOffRate, 1),
                'conversion_rate' => round($conversionRate, 1)
            ];
        }
        
        return $funnel;
    }
    
    /**
     * Get top performers
     */
    public function getTopPerformers(string $type, string $timeframe, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $dateFilter = match($timeframe) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
            'all' => "1=1",
            default => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };
        
        switch ($type) {
            case 'deals':
                $dealParams = [$workspaceId];
                $dealUserFilter = '';
                if ($userId !== null) {
                    $dealUserFilter = " AND d.assigned_to = ?";
                    $dealParams[] = $userId;
                }
                return Database::query(
                    "SELECT 
                        d.id,
                        d.title,
                        d.value,
                        d.stage,
                        c.first_name,
                        c.last_name,
                        c.company
                     FROM deals d
                     LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
                     WHERE d.workspace_id = ?
                     AND d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     {$dealUserFilter}
                     ORDER BY d.value DESC
                     LIMIT 10",
                    $dealParams
                );
                
            case 'contacts':
                $contactParams = [$workspaceId];
                $contactUserFilter = '';
                if ($userId !== null) {
                    $contactUserFilter = " AND c.assigned_to = ?";
                    $contactParams[] = $userId;
                }
                return Database::query(
                    "SELECT 
                        c.id,
                        c.first_name,
                        c.last_name,
                        c.email,
                        c.company,
                        c.lead_score,
                        COUNT(DISTINCT a.id) as activity_count
                     FROM contacts c
                     LEFT JOIN activities a ON a.contact_id = c.id AND a.workspace_id = c.workspace_id
                     WHERE c.workspace_id = ?
                     AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     {$contactUserFilter}
                     GROUP BY c.id
                     ORDER BY activity_count DESC, c.lead_score DESC
                     LIMIT 10",
                    $contactParams
                );
                
            case 'users':
                $membershipClause = $this->analyticsWorkspace->workspaceMembershipClause('u.id', $workspaceId);
                $userParams = [$workspaceId, $workspaceId];
                $usersWhere = 'WHERE ' . $membershipClause['sql'];
                if ($userId !== null) {
                    $usersWhere .= " AND u.id = ?";
                    $userParams[] = $userId;
                }
                return Database::query(
                    "SELECT 
                        u.id,
                        u.email,
                        COUNT(DISTINCT d.id) as deals_count,
                        SUM(d.value) as deals_value,
                        COUNT(DISTINCT c.id) as contacts_count
                     FROM users u
                     LEFT JOIN deals d ON d.assigned_to = u.id AND d.workspace_id = ? AND d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     LEFT JOIN contacts c ON c.assigned_to = u.id AND c.workspace_id = ? AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     {$usersWhere}
                     GROUP BY u.id
                     ORDER BY deals_value DESC, deals_count DESC
                     LIMIT 10",
                    array_merge($userParams, $membershipClause['params'])
                );
                
            case 'sources':
                $sourceParams = [$workspaceId];
                $sourceUserFilter = '';
                if ($userId !== null) {
                    $sourceUserFilter = " AND assigned_to = ?";
                    $sourceParams[] = $userId;
                }
                return Database::query(
                    "SELECT 
                        lead_source,
                        COUNT(*) as contact_count,
                        COUNT(CASE WHEN stage = 'won' THEN 1 END) as won_count,
                        (COUNT(CASE WHEN stage = 'won' THEN 1 END) / COUNT(*)) * 100 as conversion_rate
                     FROM contacts
                     WHERE workspace_id = ?
                     AND lead_source IS NOT NULL
                     AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     {$sourceUserFilter}
                     GROUP BY lead_source
                     ORDER BY conversion_rate DESC, contact_count DESC
                     LIMIT 10",
                    $sourceParams
                );
                
            default:
                return [];
        }
    }
    
    /**
     * Get bottlenecks
     */
    public function getBottlenecks(string $timeframe, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $dateFilter = match($timeframe) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
            'all' => "1=1",
            default => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };
        $contactScopeFilter = " AND workspace_id = ?";
        $contactScopeParams = [$workspaceId];
        if ($userId !== null) {
            $contactScopeFilter .= " AND assigned_to = ?";
            $contactScopeParams[] = $userId;
        }
        
        // Stages with longest dwell time
        $dwellTimes = Database::query(
            "SELECT 
                stage,
                COUNT(*) as contact_count,
                AVG(DATEDIFF(COALESCE(updated_at, NOW()), created_at)) as avg_dwell_days
             FROM contacts
             WHERE stage NOT IN ('won', 'lost')
             AND $dateFilter
             {$contactScopeFilter}
             GROUP BY stage
             ORDER BY avg_dwell_days DESC",
            $contactScopeParams
        );
        
        // Stages with highest drop-off
        $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
        $dropOffs = [];
        foreach ($stages as $index => $stage) {
            if ($index === 0) continue;
            
            $currentParams = [$stage, ...$contactScopeParams];
            $currentCount = (int) Database::queryOne(
                "SELECT COUNT(*) as count FROM contacts WHERE stage = ? AND $dateFilter{$contactScopeFilter}",
                $currentParams
            )['count'] ?? 0;
            
            $previousStage = $stages[$index - 1];
            $previousParams = [$previousStage, ...$contactScopeParams];
            $previousCount = (int) Database::queryOne(
                "SELECT COUNT(*) as count FROM contacts WHERE stage = ? AND $dateFilter{$contactScopeFilter}",
                $previousParams
            )['count'] ?? 0;
            
            $dropOffRate = $previousCount > 0 ? (($previousCount - $currentCount) / $previousCount) * 100 : 0;
            
            $dropOffs[] = [
                'from_stage' => $previousStage,
                'to_stage' => $stage,
                'drop_off_rate' => round($dropOffRate, 1),
                'contacts_lost' => $previousCount - $currentCount
            ];
        }
        
        // Stale deals (no activity for 30+ days)
        $staleParams = [$workspaceId];
        $staleUserFilter = " AND d.workspace_id = ?";
        if ($userId !== null) {
            $staleUserFilter .= " AND d.assigned_to = ?";
            $staleParams[] = $userId;
        }
        $staleDeals = Database::query(
            "SELECT 
                COUNT(*) as stale_count,
                SUM(value) as stale_value
             FROM deals d
             WHERE d.stage NOT IN ('closed_won', 'closed_lost')
             AND d.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
             {$staleUserFilter}",
            $staleParams
        );
        
        // Contacts stuck in stages
        $stuckContacts = Database::query(
            "SELECT 
                stage,
                COUNT(*) as stuck_count
             FROM contacts
             WHERE stage NOT IN ('won', 'lost')
             AND updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
             {$contactScopeFilter}
             GROUP BY stage",
            $contactScopeParams
        );
        
        // Sort drop-offs by rate
        usort($dropOffs, function($a, $b) {
            return $b['drop_off_rate'] <=> $a['drop_off_rate'];
        });
        
        return [
            'longest_dwell_times' => $dwellTimes,
            'highest_drop_offs' => array_slice($dropOffs, 0, 3),
            'stale_deals' => $staleDeals[0] ?? ($staleDeals ?: null),
            'stuck_contacts' => $stuckContacts
        ];
    }
    
    /**
     * Get KPIs
     */
    public function getKPIs(string $timeframe, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $dateFilter = match($timeframe) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
            'all' => "1=1",
            default => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        };
        $contactDateFilter = $this->qualifyDateFilter($dateFilter, 'created_at', 'c.created_at');
        $dealDateFilter = $this->qualifyDateFilter($dateFilter, 'created_at', 'd.created_at');
        
        // Customer Acquisition Cost (CAC) - simplified: marketing spend / new customers
        // Assuming we track marketing costs elsewhere, for now use a placeholder
        $newCustomersParams = [$workspaceId];
        $newCustomersFilter = '';
        if ($userId !== null) {
            $newCustomersFilter = " AND assigned_to = ?";
            $newCustomersParams[] = $userId;
        }
        $newCustomers = (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND stage = 'won' AND $dateFilter{$newCustomersFilter}",
            $newCustomersParams
        )['count'] ?? 0;
        
        // Lifetime Value (LTV) - average deal value * average customer deals
        $avgDealValueParams = [$workspaceId];
        $avgDealValueFilter = '';
        if ($userId !== null) {
            $avgDealValueFilter = " AND assigned_to = ?";
            $avgDealValueParams[] = $userId;
        }
        $avgDealValue = Database::queryOne(
            "SELECT AVG(value) as avg_value FROM deals WHERE workspace_id = ? AND stage = 'closed_won' AND actual_close_date >= DATE_SUB(NOW(), INTERVAL 90 DAY){$avgDealValueFilter}",
            $avgDealValueParams
        );
        $avgDealsPerCustomerParams = [$workspaceId];
        $avgDealsPerCustomerFilter = '';
        if ($userId !== null) {
            $avgDealsPerCustomerFilter = " AND assigned_to = ?";
            $avgDealsPerCustomerParams[] = $userId;
        }
        $avgDealsPerCustomer = Database::queryOne(
            "SELECT AVG(deal_count) as avg_deals FROM (
                SELECT contact_id, COUNT(*) as deal_count 
                FROM deals 
                WHERE workspace_id = ? AND stage = 'closed_won'{$avgDealsPerCustomerFilter}
                GROUP BY contact_id
            ) as customer_deals",
            $avgDealsPerCustomerParams
        );
        
        $ltv = ($avgDealValue['avg_value'] ?? 0) * ($avgDealsPerCustomer['avg_deals'] ?? 1);
        
        // Sales Velocity = (Deals × Average Deal Size × Win Rate) / Sales Cycle Length
        $salesVelocityParams = [$workspaceId];
        $salesVelocityFilter = '';
        if ($userId !== null) {
            $salesVelocityFilter = " AND assigned_to = ?";
            $salesVelocityParams[] = $userId;
        }
        $salesVelocityData = Database::queryOne(
            "SELECT 
                COUNT(*) as total_deals,
                AVG(value) as avg_deal_size,
                AVG(DATEDIFF(actual_close_date, created_at)) as avg_cycle_days
             FROM deals
             WHERE workspace_id = ?
             AND stage = 'closed_won'
             AND actual_close_date >= DATE_SUB(NOW(), INTERVAL 90 DAY){$salesVelocityFilter}",
            $salesVelocityParams
        );
        
        $wonDeals = (int) ($salesVelocityData['total_deals'] ?? 0);
        $avgDealSize = (float) ($salesVelocityData['avg_deal_size'] ?? 0);
        $avgCycleDays = (float) ($salesVelocityData['avg_cycle_days'] ?? 0);
        
        $winRateParams = [$workspaceId];
        $winRateFilter = '';
        if ($userId !== null) {
            $winRateFilter = " AND assigned_to = ?";
            $winRateParams[] = $userId;
        }
        $winRate = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN stage = 'closed_won' THEN 1 END) as won,
                COUNT(CASE WHEN stage IN ('closed_won', 'closed_lost') THEN 1 END) as total
             FROM deals
             WHERE workspace_id = ?
             AND actual_close_date >= DATE_SUB(NOW(), INTERVAL 90 DAY){$winRateFilter}",
            $winRateParams
        );
        $winRatePercent = ($winRate['total'] ?? 0) > 0 ? (($winRate['won'] ?? 0) / ($winRate['total'] ?? 1)) * 100 : 0;
        
        $salesVelocity = $avgCycleDays > 0 
            ? ($wonDeals * $avgDealSize * ($winRatePercent / 100)) / $avgCycleDays 
            : 0;
        
        // Conversion rates by stage
        $conversionRates = [];
        $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won'];
        foreach ($stages as $index => $stage) {
            if ($index === 0) continue;
            $previousStage = $stages[$index - 1];
            $currentParams = [$workspaceId, $stage];
            $previousParams = [$workspaceId, $previousStage];
            $currentFilter = '';
            $previousFilter = '';
            if ($userId !== null) {
                $currentFilter = " AND assigned_to = ?";
                $previousFilter = " AND assigned_to = ?";
                $currentParams[] = $userId;
                $previousParams[] = $userId;
            }
            $current = (int) Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND stage = ?{$currentFilter}", $currentParams)['count'] ?? 0;
            $previous = (int) Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND stage = ?{$previousFilter}", $previousParams)['count'] ?? 0;
            $conversionRates[$stage] = $previous > 0 ? ($current / $previous) * 100 : 0;
        }
        
        // Email engagement rates
        $emailEngagementParams = [$workspaceId];
        $emailEngagementFilter = '';
        if ($userId !== null) {
            $emailEngagementFilter = " AND e.user_id = ?";
            $emailEngagementParams[] = $userId;
        }
        $emailEngagement = Database::queryOne(
            "SELECT 
                COUNT(DISTINCT e.id) as sent,
                COUNT(DISTINCT et_open.email_id) as opened,
                COUNT(DISTINCT et_click.email_id) as clicked
             FROM emails e
             LEFT JOIN email_tracking et_open ON e.id = et_open.email_id AND et_open.tracking_type = 'open'
             LEFT JOIN email_tracking et_click ON e.id = et_click.email_id AND et_click.tracking_type = 'click'
             WHERE e.workspace_id = ?
             AND e.status IN ('sent', 'delivered', 'opened', 'clicked')
             AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY){$emailEngagementFilter}",
            $emailEngagementParams
        );
        
        $openRate = ($emailEngagement['sent'] ?? 0) > 0 
            ? (($emailEngagement['opened'] ?? 0) / ($emailEngagement['sent'] ?? 0)) * 100 
            : 0;
        $clickRate = ($emailEngagement['sent'] ?? 0) > 0 
            ? (($emailEngagement['clicked'] ?? 0) / ($emailEngagement['sent'] ?? 0)) * 100 
            : 0;
        
        return [
            'cac' => 0, // Placeholder - requires marketing spend tracking
            'ltv' => round($ltv, 2),
            'ltv_cac_ratio' => 0, // Placeholder
            'sales_velocity' => round($salesVelocity, 2),
            'avg_sales_cycle_days' => round($avgCycleDays, 1),
            'conversion_rates' => $conversionRates,
            'email_open_rate' => round($openRate, 1),
            'email_click_rate' => round($clickRate, 1),
            'win_rate' => round($winRatePercent, 1)
        ];
    }

    /**
     * Cohort retention matrix by contact acquisition month.
     *
     * Retained = contact has at least one engagement event in period month:
     * communications OR activities OR deals updates.
     *
     * @return array<string,mixed>
     */
    public function getCohortRetentionMetrics(int $cohortMonths = 6, int $periodMonths = 6, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $cohortMonths = max(1, min(24, $cohortMonths));
        $periodMonths = max(1, min(12, $periodMonths));
        $cohortParams = [$workspaceId, $cohortMonths - 1];
        $cohortUserFilter = '';
        if ($userId !== null) {
            $cohortUserFilter = " AND assigned_to = ?";
            $cohortParams[] = $userId;
        }

        $cohorts = Database::query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS cohort_month,
                    COUNT(*) AS cohort_size
             FROM contacts
             WHERE workspace_id = ?
             AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL ? MONTH)
             {$cohortUserFilter}
             GROUP BY DATE_FORMAT(created_at, '%Y-%m-01')
             ORDER BY cohort_month ASC",
            $cohortParams
        );

        $matrix = [];
        $summary = [
            'avg_m1_retention' => 0.0,
            'avg_m3_retention' => 0.0,
            'avg_m6_retention' => 0.0,
            'cohort_count' => count($cohorts),
        ];
        $m1Rates = [];
        $m3Rates = [];
        $m6Rates = [];

        foreach ($cohorts as $cohort) {
            $cohortMonth = (string) ($cohort['cohort_month'] ?? '');
            $cohortSize = (int) ($cohort['cohort_size'] ?? 0);
            if ($cohortMonth === '' || $cohortSize <= 0) {
                continue;
            }

            $periods = [];
            for ($period = 0; $period < $periodMonths; $period++) {
                if ($period === 0) {
                    $retainedCount = $cohortSize;
                } else {
                    $retainedParams = [
                        $workspaceId,
                        $cohortMonth,
                        $workspaceId,
                        $cohortMonth, $period,
                        $cohortMonth, $period + 1,
                        $cohortMonth, $period,
                        $cohortMonth, $period + 1,
                        $cohortMonth, $period,
                        $cohortMonth, $period + 1,
                    ];
                    $retainedUserFilter = '';
                    if ($userId !== null) {
                        $retainedUserFilter = " AND c.assigned_to = ?";
                        array_splice($retainedParams, 2, 0, [$userId]);
                    }
                    $retained = Database::queryOne(
                        "SELECT COUNT(DISTINCT c.id) AS retained_count
                         FROM contacts c
                         WHERE c.workspace_id = ?
                           AND DATE_FORMAT(c.created_at, '%Y-%m-01') = ?
                           {$retainedUserFilter}
                           AND (
                               EXISTS (
                                   SELECT 1 FROM communications comm
                                   WHERE comm.contact_id = c.id
                                     AND comm.workspace_id = ?
                                     AND comm.created_at >= DATE_ADD(?, INTERVAL ? MONTH)
                                     AND comm.created_at < DATE_ADD(?, INTERVAL ? MONTH)
                               )
                               OR EXISTS (
                                   SELECT 1 FROM activities a
                                   WHERE a.contact_id = c.id
                                     AND a.workspace_id = c.workspace_id
                                     AND a.created_at >= DATE_ADD(?, INTERVAL ? MONTH)
                                     AND a.created_at < DATE_ADD(?, INTERVAL ? MONTH)
                               )
                               OR EXISTS (
                                   SELECT 1 FROM deals d
                                   WHERE d.contact_id = c.id
                                     AND d.workspace_id = c.workspace_id
                                     AND d.updated_at >= DATE_ADD(?, INTERVAL ? MONTH)
                                     AND d.updated_at < DATE_ADD(?, INTERVAL ? MONTH)
                               )
                           )",
                        $retainedParams
                    );
                    $retainedCount = (int) ($retained['retained_count'] ?? 0);
                }

                $rate = $cohortSize > 0 ? round(($retainedCount / $cohortSize) * 100, 1) : 0.0;
                $periods[] = [
                    'period' => $period,
                    'retained_count' => $retainedCount,
                    'retention_rate' => $rate,
                ];

                if ($period === 1) {
                    $m1Rates[] = $rate;
                } elseif ($period === 3) {
                    $m3Rates[] = $rate;
                } elseif ($period === 5) {
                    $m6Rates[] = $rate;
                }
            }

            $matrix[] = [
                'cohort_month' => $cohortMonth,
                'cohort_label' => date('M Y', strtotime($cohortMonth)),
                'cohort_size' => $cohortSize,
                'periods' => $periods,
            ];
        }

        if (!empty($m1Rates)) {
            $summary['avg_m1_retention'] = round(array_sum($m1Rates) / count($m1Rates), 1);
        }
        if (!empty($m3Rates)) {
            $summary['avg_m3_retention'] = round(array_sum($m3Rates) / count($m3Rates), 1);
        }
        if (!empty($m6Rates)) {
            $summary['avg_m6_retention'] = round(array_sum($m6Rates) / count($m6Rates), 1);
        }

        return [
            'summary' => $summary,
            'period_months' => $periodMonths,
            'matrix' => $matrix,
        ];
    }

    /**
     * Attribution revenue by campaign for dashboard widgets
     */
    public function getAttributionRevenueByCampaign(string $modelSlug = 'last_touch', ?string $startDate = null, ?string $endDate = null, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $where = ["am.slug = ?", "ar.workspace_id = ?"];
        $params = [$modelSlug, $workspaceId];

        if ($startDate) {
            $where[] = "ar.conversion_at >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $where[] = "ar.conversion_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        if ($userId !== null) {
            $where[] = "d.assigned_to = ?";
            $params[] = $userId;
        }

        return Database::query(
            "SELECT ar.campaign_id,
                    COALESCE(c.name, 'Unassigned') AS campaign_name,
                    SUM(ar.credited_value) AS credited_revenue,
                    COUNT(DISTINCT ar.deal_id) AS won_deals
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
              LEFT JOIN contacts contact ON contact.id = ar.contact_id AND contact.workspace_id = ar.workspace_id
              LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
              LEFT JOIN campaigns c ON c.id = ar.campaign_id AND c.workspace_id = ar.workspace_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY ar.campaign_id, c.name
             ORDER BY credited_revenue DESC",
            $params
        );
    }

    /**
     * Attribution channel split for dashboard widgets
     */
    public function getAttributionChannelSplit(string $modelSlug = 'last_touch', ?string $startDate = null, ?string $endDate = null, ?int $userId = null): array
    {
        $userId = $this->normalizeUserId($userId);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $where = ["am.slug = ?", "ar.workspace_id = ?"];
        $params = [$modelSlug, $workspaceId];

        if ($startDate) {
            $where[] = "ar.conversion_at >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $where[] = "ar.conversion_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        if ($userId !== null) {
            $where[] = "d.assigned_to = ?";
            $params[] = $userId;
        }

        return Database::query(
            "SELECT ar.channel,
                    SUM(ar.credited_value) AS credited_revenue,
                    SUM(ar.attribution_weight) AS total_weight
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
              LEFT JOIN contacts contact ON contact.id = ar.contact_id AND contact.workspace_id = ar.workspace_id
              LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY ar.channel
             ORDER BY credited_revenue DESC",
            $params
        );
    }

    private function normalizeUserId(?int $userId): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        return $this->analyticsWorkspace->ensureScopedUserId($userId);
    }

    private function appendUserCondition(string $where, array &$params, string $column, ?int $userId): string
    {
        if ($userId === null) {
            return $where;
        }

        $params[] = $userId;
        return $where . " AND {$column} = ?";
    }

    private function qualifyDateFilter(string $filter, string $column, string $qualifiedColumn): string
    {
        return str_replace($column, $qualifiedColumn, $filter);
    }

    private function appendWorkspaceCondition(string $where, array &$params, string $column, int $workspaceId): string
    {
        $params[] = $workspaceId;
        return $where . " AND {$column} = ?";
    }
}
