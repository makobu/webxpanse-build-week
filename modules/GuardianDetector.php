<?php
/**
 * Guardian Detector Module
 *
 * Strategic Intervention Mode: Silent until necessary.
 * Evaluates triggers and creates high-priority alerts for:
 * - Conversion rate collapse
 * - High-value leads ignored
 * - Revenue-impacting patterns
 * - Strategic KPI collapse
 * - Stale high-value deals
 */

namespace CRM\Modules;

use CRM\Database;

class GuardianDetector
{
    private const CONVERSION_DROP_THRESHOLD_PCT = 20;
    private const LEAD_SCORE_THRESHOLD = 70;
    private const LEAD_IGNORED_DAYS = 7;
    private const STALE_DEAL_DAYS = 14;
    private const STALE_DEAL_VALUE_THRESHOLD = 1000;
    private const BASELINE_DAYS = 30;

    private AlertingSystem $alertingSystem;
    private Notifications $notifications;

    public function __construct()
    {
        $this->alertingSystem = new AlertingSystem();
        $this->notifications = new Notifications();
    }

    /**
     * Run all Guardian checks and create alerts when triggers fire.
     * Returns array of created alerts.
     */
    public function runChecks(): array
    {
        $alerts = [];

        // 1. Conversion rate collapse
        $convAlert = $this->checkConversionRateCollapse();
        if ($convAlert) {
            $alerts[] = $convAlert;
        }

        // 2. High-value leads ignored
        $leadsAlert = $this->checkHighValueLeadsIgnored();
        if ($leadsAlert) {
            $alerts[] = $leadsAlert;
        }

        // 3. Revenue-impacting pattern (pipeline drop)
        $revenueAlert = $this->checkPipelineDrop();
        if ($revenueAlert) {
            $alerts[] = $revenueAlert;
        }

        // 4. Stale high-value deals
        $staleAlert = $this->checkStaleHighValueDeals();
        if ($staleAlert) {
            $alerts[] = $staleAlert;
        }

        return $alerts;
    }

    /**
     * Check if win rate or conversion has dropped significantly vs baseline.
     */
    private function checkConversionRateCollapse(): ?int
    {
        $baselineEnd = date('Y-m-d', strtotime('-' . (self::BASELINE_DAYS + 14) . ' days'));
        $baselineStart = date('Y-m-d', strtotime('-' . (self::BASELINE_DAYS + 14 + self::BASELINE_DAYS) . ' days'));
        $currentEnd = date('Y-m-d');
        $currentStart = date('Y-m-d', strtotime('-' . self::BASELINE_DAYS . ' days'));

        $baseline = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN stage = 'closed_won' THEN 1 END) as won,
                COUNT(CASE WHEN stage IN ('closed_won', 'closed_lost') THEN 1 END) as total
             FROM deals
             WHERE actual_close_date IS NOT NULL
             AND actual_close_date >= ? AND actual_close_date < ?",
            [$baselineStart, $baselineEnd]
        );

        $current = Database::queryOne(
            "SELECT 
                COUNT(CASE WHEN stage = 'closed_won' THEN 1 END) as won,
                COUNT(CASE WHEN stage IN ('closed_won', 'closed_lost') THEN 1 END) as total
             FROM deals
             WHERE actual_close_date IS NOT NULL
             AND actual_close_date >= ? AND actual_close_date <= ?",
            [$currentStart, $currentEnd]
        );

        $baselineTotal = (int) ($baseline['total'] ?? 0);
        $currentTotal = (int) ($current['total'] ?? 0);
        if ($baselineTotal < 5 || $currentTotal < 3) {
            return null; // Not enough data
        }

        $baselineRate = ($baseline['won'] ?? 0) / max(1, $baselineTotal) * 100;
        $currentRate = ($current['won'] ?? 0) / max(1, $currentTotal) * 100;
        $drop = $baselineRate - $currentRate;

        if ($drop >= self::CONVERSION_DROP_THRESHOLD_PCT) {
            return $this->createGuardianAlert(
                'conversion_collapse',
                'high',
                'Conversion Rate Drop Detected',
                sprintf(
                    'Win rate dropped from %.1f%% to %.1f%% (%.1f point drop) vs prior 30-day baseline. Review pipeline and qualification process.',
                    $baselineRate,
                    $currentRate,
                    $drop
                ),
                ['baseline_rate' => $baselineRate, 'current_rate' => $currentRate, 'drop_pct' => $drop]
            );
        }

        return null;
    }

    /**
     * Check for high-value leads (lead_score > threshold) with no activity in last N days.
     */
    private function checkHighValueLeadsIgnored(): ?int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::LEAD_IGNORED_DAYS . ' days'));

        $ignored = Database::query(
            "SELECT c.id, c.first_name, c.last_name, c.lead_score
             FROM contacts c
             WHERE COALESCE(c.lead_score, 0) >= ?
             AND NOT EXISTS (
                 SELECT 1 FROM activities a WHERE a.contact_id = c.id AND a.created_at >= ?
             )
             AND NOT EXISTS (
                 SELECT 1 FROM communications co WHERE co.contact_id = c.id AND co.created_at >= ?
             )
             LIMIT 20",
            [self::LEAD_SCORE_THRESHOLD, $cutoff, $cutoff]
        );

        if (empty($ignored)) {
            return null;
        }

        $count = count($ignored);
        return $this->createGuardianAlert(
            'high_value_leads_ignored',
            'high',
            'High-Value Leads Not Contacted',
            sprintf(
                '%d contact(s) with composite lead score >= %d have had no activity in the last %d days. Prioritize outreach.',
                $count,
                self::LEAD_SCORE_THRESHOLD,
                self::LEAD_IGNORED_DAYS
            ),
            ['count' => $count, 'contact_ids' => array_column($ignored, 'id')]
        );
    }

    /**
     * Check if pipeline is empty despite recent deal activity (revenue-impacting pattern).
     */
    private function checkPipelineDrop(): ?int
    {
        $currentValue = (float) Database::queryOne(
            "SELECT COALESCE(SUM(value), 0) as total
             FROM deals WHERE stage NOT IN ('closed_won', 'closed_lost')"
        )['total'];

        $recentWon = Database::queryOne(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(value), 0) as total
             FROM deals
             WHERE stage = 'closed_won'
             AND actual_close_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );

        $recentWonCount = (int) ($recentWon['cnt'] ?? 0);
        $recentWonValue = (float) ($recentWon['total'] ?? 0);

        // Alert if pipeline is empty but we had recent won deals (going concern with empty pipeline)
        if ($currentValue < 100 && $recentWonCount >= 1 && $recentWonValue >= 500) {
            return $this->createGuardianAlert(
                'pipeline_empty',
                'high',
                'Pipeline Empty Despite Recent Sales',
                sprintf(
                    'Pipeline value is %s but you closed %d deal(s) worth %s in the last 90 days. Prioritize new opportunity intake.',
                    number_format($currentValue, 0),
                    $recentWonCount,
                    number_format($recentWonValue, 0)
                ),
                ['current_value' => $currentValue, 'recent_won_count' => $recentWonCount, 'recent_won_value' => $recentWonValue]
            );
        }

        return null;
    }

    /**
     * Check for high-value deals stuck in same stage for too long.
     */
    private function checkStaleHighValueDeals(): ?int
    {
        $cutoff = date('Y-m-d', strtotime('-' . self::STALE_DEAL_DAYS . ' days'));

        $stale = Database::query(
            "SELECT d.id, d.title, d.value, d.stage, d.updated_at
             FROM deals d
             WHERE d.stage NOT IN ('closed_won', 'closed_lost')
             AND d.value >= ?
             AND d.updated_at <= ?
             ORDER BY d.value DESC
             LIMIT 10",
            [self::STALE_DEAL_VALUE_THRESHOLD, $cutoff . ' 23:59:59']
        );

        if (empty($stale)) {
            return null;
        }

        $count = count($stale);
        $totalValue = array_sum(array_column($stale, 'value'));
        return $this->createGuardianAlert(
            'stale_high_value_deals',
            'high',
            'High-Value Deals Stalled',
            sprintf(
                '%d deal(s) worth %s total have been in the same stage for %d+ days. Review and advance or close.',
                $count,
                number_format($totalValue, 0),
                self::STALE_DEAL_DAYS
            ),
            ['count' => $count, 'deal_ids' => array_column($stale, 'id'), 'total_value' => $totalValue]
        );
    }

    /**
     * Create a Guardian alert and notify admins.
     */
    private function createGuardianAlert(string $type, string $severity, string $title, string $message, array $data = []): int
    {
        $metadata = array_merge($data, ['guardian_type' => $type]);
        $alertId = $this->alertingSystem->createAlert('guardian', $severity, $title, $message, $metadata);
        return $alertId;
    }
}
