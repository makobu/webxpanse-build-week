<?php
/**
 * Cost Optimizer
 * Tracks and optimizes API costs
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class CostOptimizer
{
    private float $monthlyBudget = 100.0;
    
    /**
     * Track AI cost
     */
    public function trackAICost(string $provider, int $tokens, float $costPer1k): void
    {
        if (!$this->shouldMirrorLegacyUsage()) {
            return;
        }

        Database::execute(
            "INSERT INTO ai_usage (provider, token_count, cost, date) 
             VALUES (?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE token_count = token_count + ?, cost = cost + ?",
            [$provider, $tokens, ($tokens / 1000) * $costPer1k, $tokens, ($tokens / 1000) * $costPer1k]
        );
    }
    
    /**
     * Get monthly costs
     */
    public function getMonthlyCosts(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $costs = null;

        if ($this->tableExists('workspace_ai_usage')) {
            if ($workspaceId > 0) {
                $costs = Database::queryOne(
                    "SELECT COALESCE(SUM(provider_cost), 0) AS total
                     FROM workspace_ai_usage
                     WHERE workspace_id = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    [$workspaceId]
                );
            } else {
                $costs = Database::queryOne(
                    "SELECT COALESCE(SUM(provider_cost), 0) AS total
                     FROM workspace_ai_usage
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
            }
        } elseif ($this->tableExists('ai_usage')) {
            $costs = Database::queryOne(
                "SELECT SUM(cost) as total FROM ai_usage 
                 WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
        }
        
        $total = (float) ($costs['total'] ?? 0);
        
        return [
            'total' => $total,
            'budget' => $this->monthlyBudget,
            'remaining' => $this->monthlyBudget - $total,
            'percentage' => ($total / $this->monthlyBudget) * 100
        ];
    }

    private function shouldMirrorLegacyUsage(): bool
    {
        if (!$this->tableExists('ai_usage')) {
            return false;
        }

        return strtolower(trim((string) ($_ENV['AI_USAGE_COMPATIBILITY_MIRROR'] ?? 'false'))) === 'true';
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
