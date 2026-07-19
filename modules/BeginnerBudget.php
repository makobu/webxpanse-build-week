<?php
/**
 * Beginner Budget Module
 *
 * Manages minimal budgeting for Foundation Mode (monthly marketing budget,
 * fixed costs, target deal value, target CAC).
 */

namespace CRM\Modules;

use CRM\Database;

class BeginnerBudget
{
    private const MAX_AMOUNT = 999999.99;

    /**
     * Get budget for a user.
     */
    public function get(int $userId): ?array
    {
        $row = Database::queryOne(
            "SELECT id, user_id, monthly_marketing_budget, monthly_fixed_costs,
                    target_deal_value, target_cac, currency_code, created_at, updated_at
             FROM beginner_budget WHERE user_id = ?",
            [$userId]
        );
        return $row ?: null;
    }

    /**
     * Save or update budget.
     */
    public function save(int $userId, array $data): bool
    {
        $monthlyMarketingBudget = $this->sanitizeAmount($data['monthly_marketing_budget'] ?? 0);
        $monthlyFixedCosts = $this->sanitizeAmount($data['monthly_fixed_costs'] ?? 0);
        $targetDealValue = $this->sanitizeAmount($data['target_deal_value'] ?? 0);
        $targetCac = isset($data['target_cac']) && $data['target_cac'] !== '' && $data['target_cac'] !== null
            ? $this->sanitizeAmount($data['target_cac'])
            : null;
        $currencyCode = trim($data['currency_code'] ?? 'USD');
        if (strlen($currencyCode) > 3) {
            $currencyCode = 'USD';
        }

        $existing = $this->get($userId);
        if ($existing) {
            Database::execute(
                "UPDATE beginner_budget SET
                    monthly_marketing_budget = ?, monthly_fixed_costs = ?, target_deal_value = ?,
                    target_cac = ?, currency_code = ?
                 WHERE user_id = ?",
                [$monthlyMarketingBudget, $monthlyFixedCosts, $targetDealValue, $targetCac, $currencyCode, $userId]
            );
        } else {
            Database::execute(
                "INSERT INTO beginner_budget (user_id, monthly_marketing_budget, monthly_fixed_costs, target_deal_value, target_cac, currency_code)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $monthlyMarketingBudget, $monthlyFixedCosts, $targetDealValue, $targetCac, $currencyCode]
            );
        }

        return true;
    }

    /**
     * Get break-even number of deals (simplified: fixed_costs / target_deal_value).
     */
    public function getBreakEvenDeals(int $userId): ?int
    {
        $budget = $this->get($userId);
        if (!$budget) {
            return null;
        }
        $fixedCosts = (float) ($budget['monthly_fixed_costs'] ?? 0);
        $dealValue = (float) ($budget['target_deal_value'] ?? 0);
        if ($dealValue <= 0) {
            return null;
        }
        return (int) ceil($fixedCosts / $dealValue);
    }

    /**
     * Get max leads at target CAC (marketing_budget / target_cac).
     */
    public function getMaxLeadsAtCac(int $userId): ?int
    {
        $budget = $this->get($userId);
        if (!$budget) {
            return null;
        }
        $targetCac = $budget['target_cac'] ?? null;
        if ($targetCac === null || (float) $targetCac <= 0) {
            return null;
        }
        $marketingBudget = (float) ($budget['monthly_marketing_budget'] ?? 0);
        return (int) floor($marketingBudget / (float) $targetCac);
    }

    /**
     * Get budget context string for AI prompt.
     */
    public function getContextForPrompt(int $userId): string
    {
        $budget = $this->get($userId);
        if (!$budget) {
            return '';
        }

        $lines = [];
        $marketing = (float) ($budget['monthly_marketing_budget'] ?? 0);
        $fixed = (float) ($budget['monthly_fixed_costs'] ?? 0);
        $dealValue = (float) ($budget['target_deal_value'] ?? 0);
        $cac = $budget['target_cac'] !== null ? (float) $budget['target_cac'] : null;

        if ($marketing > 0 || $fixed > 0 || $dealValue > 0) {
            $lines[] = 'BUDGET: monthly_marketing_budget ' . $marketing . ', monthly_fixed_costs ' . $fixed . ', target_deal_value ' . $dealValue;
            if ($dealValue > 0 && $fixed > 0) {
                $be = $this->getBreakEvenDeals($userId);
                if ($be !== null) {
                    $lines[] = 'Break-even: ' . $be . ' deals per month';
                }
            }
            if ($cac !== null && $cac > 0 && $marketing > 0) {
                $maxLeads = $this->getMaxLeadsAtCac($userId);
                if ($maxLeads !== null) {
                    $lines[] = 'Max leads at target CAC: ' . $maxLeads;
                }
            }
        }

        return empty($lines) ? '' : implode('. ', $lines);
    }

    private function sanitizeAmount($value): float
    {
        $f = (float) $value;
        if ($f < 0) {
            $f = 0;
        }
        if ($f > self::MAX_AMOUNT) {
            $f = self::MAX_AMOUNT;
        }
        return round($f, 2);
    }
}
