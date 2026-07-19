<?php
/**
 * AI Guidance Evaluator
 *
 * Evaluates effective AI guidance mode when user has selected 'auto'.
 * Foundation (1): New users or incomplete business profile.
 * Operations (2): Fundamentals stable - company profile, products, pipeline, activity.
 */

namespace CRM\Modules;

use CRM\Database;

class AIGuidanceEvaluator
{
    private const NEW_USER_DAYS = 30;
    private const MIN_ACTIVITY_DAYS = 14;

    /**
     * Evaluate effective mode for auto: returns '1' (Foundation) or '2' (Operations).
     * Guardian (3) is never auto-selected; it runs in background for everyone.
     */
    public function evaluateMode(int $userId): string
    {
        return $this->evaluateDetailedMode($userId)['mode'];
    }

    public function evaluateDetailedMode(int $userId): array
    {
        $lock = (new UserPreferences())->getAIModeLock($userId);
        if ($lock && $lock !== 'auto') {
            return [
                'mode' => $this->mapModeLock($lock),
                'reason_codes' => ['mode_lock'],
                'scores' => [
                    'foundation_need' => 0.0,
                    'operations_readiness' => 1.0,
                    'context_quality' => 1.0,
                ],
            ];
        }

        $foundationNeed = 0.0;
        $operationsReadiness = 1.0;
        $reasons = [];

        $user = Database::queryOne("SELECT created_at FROM users WHERE id = ?", [$userId]);
        if ($user && !empty($user['created_at'])) {
            $created = strtotime($user['created_at']);
            if ($created >= strtotime('-' . self::NEW_USER_DAYS . ' days')) {
                $foundationNeed += 0.25;
                $operationsReadiness -= 0.25;
                $reasons[] = 'new_account';
            }
        }

        $profile = (new CompanyProfile())->get();
        $hasDescription = $profile && trim($profile['company_description'] ?? '') !== '';
        $hasCompanyName = $profile && trim($profile['company_name'] ?? '') !== '';
        if (!$hasDescription || !$hasCompanyName) {
            $foundationNeed += 0.2;
            $operationsReadiness -= 0.2;
            $reasons[] = 'profile_incomplete';
        }

        $products = (new Products())->list();
        $pricedCount = 0;
        foreach ($products as $p) {
            if ((float) ($p['unit_price'] ?? 0) > 0 || trim((string) ($p['pricing_info'] ?? '')) !== '') {
                $pricedCount++;
            }
        }
        if ($pricedCount === 0) {
            $foundationNeed += 0.15;
            $operationsReadiness -= 0.15;
            $reasons[] = 'pricing_incomplete';
        }

        $activeTargets = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM targets WHERE user_id = ? AND status = 'active'", [$userId])['c'] ?? 0);
        if ($activeTargets === 0) {
            $foundationNeed += 0.15;
            $operationsReadiness -= 0.10;
            $reasons[] = 'missing_goals';
        }

        $stagesWithDeals = Database::query("SELECT stage FROM deals WHERE stage NOT IN ('closed_won', 'closed_lost') GROUP BY stage");
        if (count($stagesWithDeals) < 2) {
            $foundationNeed += 0.10;
            $operationsReadiness -= 0.10;
            $reasons[] = 'pipeline_shallow';
        }

        $hasWorkflowGraph = (bool) Database::queryOne(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflows' AND COLUMN_NAME = 'graph_json'"
        );
        if (!$hasWorkflowGraph) {
            $foundationNeed += 0.05;
            $operationsReadiness -= 0.05;
            $reasons[] = 'workflow_graph_unavailable';
        }

        if (!$this->hasRecentActivity($userId)) {
            $foundationNeed += 0.10;
            $operationsReadiness -= 0.15;
            $reasons[] = 'low_recent_activity';
        }

        $contextQuality = max(0.0, min(1.0, 1.0 - $foundationNeed));
        $mode = $foundationNeed >= 0.35 ? '1' : '2';
        if ($this->shouldUseFoundation($userId)) {
            $mode = '1';
        }
        if ($mode === '2') {
            $reasons[] = 'ops_ready';
        }

        return [
            'mode' => $mode,
            'reason_codes' => array_values(array_unique($reasons)),
            'scores' => [
                'foundation_need' => max(0.0, min(1.0, $foundationNeed)),
                'operations_readiness' => max(0.0, min(1.0, $operationsReadiness)),
                'context_quality' => $contextQuality,
            ],
        ];
    }

    private function shouldUseFoundation(int $userId): bool
    {
        // New user: created in last N days
        $user = Database::queryOne("SELECT created_at FROM users WHERE id = ?", [$userId]);
        if ($user && !empty($user['created_at'])) {
            $created = strtotime($user['created_at']);
            if ($created >= strtotime('-' . self::NEW_USER_DAYS . ' days')) {
                return true;
            }
        }

        // Incomplete business profile
        $profile = (new CompanyProfile())->get();
        $hasDescription = $profile && trim($profile['company_description'] ?? '') !== '';
        $products = (new Products())->list();
        $hasProductWithPricing = false;
        foreach ($products as $p) {
            if (trim($p['pricing_info'] ?? '') !== '') {
                $hasProductWithPricing = true;
                break;
            }
        }
        if (!$hasDescription || !$hasProductWithPricing) {
            return true;
        }

        // Pipeline: need 2+ stages with deals
        $stagesWithDeals = Database::query(
            "SELECT stage FROM deals WHERE stage NOT IN ('closed_won', 'closed_lost') GROUP BY stage"
        );
        if (count($stagesWithDeals) < 2) {
            return true;
        }

        // Activity: 14+ days of tasks, activities, or communications
        $cutoff = date('Y-m-d', strtotime('-' . self::MIN_ACTIVITY_DAYS . ' days'));
        $hasRecentActivity = false;
        $taskActivity = Database::queryOne(
            "SELECT 1 FROM tasks WHERE (created_by = ? OR assigned_to = ?) AND created_at >= ? LIMIT 1",
            [$userId, $userId, $cutoff]
        );
        if ($taskActivity) {
            $hasRecentActivity = true;
        }
        if (!$hasRecentActivity) {
            $activityCount = Database::queryOne(
                "SELECT 1 FROM activities WHERE user_id = ? AND created_at >= ? LIMIT 1",
                [$userId, $cutoff]
            );
            $hasRecentActivity = (bool) $activityCount;
        }
        if (!$hasRecentActivity) {
            $commCount = Database::queryOne(
                "SELECT 1 FROM communications WHERE created_at >= ? LIMIT 1",
                [$cutoff]
            );
            $hasRecentActivity = (bool) $commCount;
        }
        if (!$hasRecentActivity) {
            return true;
        }

        return false; // All criteria met -> Operations mode
    }

    private function hasRecentActivity(int $userId): bool
    {
        $cutoff = date('Y-m-d', strtotime('-' . self::MIN_ACTIVITY_DAYS . ' days'));
        $taskActivity = Database::queryOne(
            "SELECT 1 FROM tasks WHERE (created_by = ? OR assigned_to = ?) AND created_at >= ? LIMIT 1",
            [$userId, $userId, $cutoff]
        );
        if ($taskActivity) {
            return true;
        }
        $activityCount = Database::queryOne(
            "SELECT 1 FROM activities WHERE user_id = ? AND created_at >= ? LIMIT 1",
            [$userId, $cutoff]
        );
        if ($activityCount) {
            return true;
        }
        $commCount = Database::queryOne(
            "SELECT 1 FROM communications WHERE created_at >= ? LIMIT 1",
            [$cutoff]
        );
        return (bool) $commCount;
    }

    private function mapModeLock(string $mode): string
    {
        return match ($mode) {
            'foundation' => '1',
            'operations' => '2',
            'guardian' => '3',
            default => '1',
        };
    }
}
