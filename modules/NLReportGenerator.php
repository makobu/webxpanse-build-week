<?php
/**
 * Natural Language Report Generator
 *
 * Answers questions like "Top 10 leads this month" or "Deals stuck in negotiation."
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Authorization;
use CRM\Services\AIService;
use CRM\Services\AnalyticsWorkspaceService;

class NLReportGenerator
{
    private AIService $aiService;
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    /**
     * Generate answer to a natural-language question.
     *
     * @return array { answer: string, report_type?: string, data?: array }
     */
    public function generate(string $question, array $context = []): array
    {
        $question = trim($question);
        if (empty($question)) {
            return ['answer' => 'Please ask a question.'];
        }

        $viewer = $context['user'] ?? null;
        unset($context['user']);
        $context = array_merge($this->buildContext(is_array($viewer) ? $viewer : null), $context);
        $raw = $this->aiService->process('nl_report', [
            'question' => $question,
            'context' => $context,
            'text' => $question,
        ]);

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'answer' => $decoded['answer'] ?? trim($raw),
                'report_type' => $decoded['report_type'] ?? null,
                'suggested_query' => $decoded['suggested_query'] ?? null,
            ];
        }

        return ['answer' => trim($raw) ?: 'Unable to generate report.'];
    }

    private function buildContext(?array $user = null): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        [$contactOwnerSql, $contactOwnerParams] = $this->contactOwnerScope('', $user);
        [$dealOwnerSql, $dealOwnerParams] = $this->dealOwnerScope('c', $user);

        $contactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) as c FROM contacts WHERE workspace_id = ?" . $contactOwnerSql,
            array_merge([$workspaceId], $contactOwnerParams)
        )['c'] ?? 0);
        $dealCount = (int) (Database::queryOne(
            "SELECT COUNT(*) as c
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND d.stage NOT IN ('closed_won','closed_lost')" . $dealOwnerSql,
            array_merge([$workspaceId], $dealOwnerParams)
        )['c'] ?? 0);
        $wonThisMonth = Database::queryOne(
            "SELECT COUNT(*) as c, COALESCE(SUM(d.value),0) as total
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND d.stage = 'closed_won'
               AND MONTH(d.actual_close_date) = MONTH(CURRENT_DATE())
               AND YEAR(d.actual_close_date) = YEAR(CURRENT_DATE())" . $dealOwnerSql,
            array_merge([$workspaceId], $dealOwnerParams)
        );
        $leadsThisMonth = (int) (Database::queryOne(
            "SELECT COUNT(*) as c
             FROM contacts
             WHERE workspace_id = ?
               AND MONTH(created_at) = MONTH(CURRENT_DATE())
               AND YEAR(created_at) = YEAR(CURRENT_DATE())" . $contactOwnerSql,
            array_merge([$workspaceId], $contactOwnerParams)
        )['c'] ?? 0);
        $topDeals = Database::query(
            "SELECT d.title, d.value, d.stage, c.first_name, c.last_name
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND d.stage NOT IN ('closed_won','closed_lost')" . $dealOwnerSql . "
             ORDER BY d.value DESC
             LIMIT 10",
            array_merge([$workspaceId], $dealOwnerParams)
        );
        $stageCounts = Database::query(
            "SELECT d.stage, COUNT(*) as c
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND d.stage NOT IN ('closed_won','closed_lost')" . $dealOwnerSql . "
             GROUP BY d.stage",
            array_merge([$workspaceId], $dealOwnerParams)
        );

        return [
            'contact_count' => $contactCount,
            'active_deal_count' => $dealCount,
            'won_this_month_count' => (int) ($wonThisMonth['c'] ?? 0),
            'won_this_month_value' => (float) ($wonThisMonth['total'] ?? 0),
            'leads_this_month' => $leadsThisMonth,
            'top_deals' => $topDeals,
            'deals_by_stage' => $stageCounts,
        ];
    }

    private function contactOwnerScope(string $alias, ?array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || Authorization::can('contacts.view_all', $user)) {
            return ['', []];
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        return [" AND ({$prefix}assigned_to = ? OR {$prefix}assigned_to IS NULL OR {$prefix}assigned_to = 0)", [$userId]];
    }

    private function dealOwnerScope(string $contactAlias, ?array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || Authorization::can('contacts.view_all', $user)) {
            return ['', []];
        }

        return [" AND (d.contact_id IS NULL OR {$contactAlias}.assigned_to = ? OR {$contactAlias}.assigned_to IS NULL OR {$contactAlias}.assigned_to = 0)", [$userId]];
    }
}
