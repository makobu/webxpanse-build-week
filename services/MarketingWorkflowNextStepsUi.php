<?php

namespace CRM\Services;

class MarketingWorkflowNextStepsUi
{
    public static function render(array $center): string
    {
        if ($center === []) {
            return '';
        }

        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
        $status = preg_replace('/[^a-z0-9_-]/i', '', (string) ($center['status'] ?? 'attention')) ?: 'attention';
        $actions = array_slice((array) ($center['actions'] ?? []), 0, 6);
        $advancedActions = array_slice((array) ($center['advanced_actions'] ?? []), 0, 8);
        $priorityCounts = (array) ($center['priority_counts'] ?? []);

        $cards = '';
        foreach ($actions as $action) {
            $priority = preg_replace('/[^a-z0-9_-]/i', '', (string) ($action['priority'] ?? 'normal')) ?: 'normal';
            $cards .= '<a class="marketing-nextstep-card ' . $escape($priority) . '" href="' . $escape($action['href'] ?? 'marketing.php') . '">'
                . '<strong>' . $escape($action['label'] ?? 'Review Marketing Action') . '</strong>'
                . '<span>' . $escape($action['reason'] ?? 'Review this marketing workflow step.') . '</span>'
                . '<em>' . $escape($action['source'] ?? 'Workflow Clarity') . '</em>'
                . '</a>';
        }

        if ($cards === '') {
            $cards = '<div class="empty-state"><p>No workflow actions are open for this view. Continue monitoring the command center or update setup when strategy changes.</p></div>';
        }

        $advanced = '';
        if ($advancedActions !== []) {
            $advancedCards = '';
            foreach ($advancedActions as $action) {
                $advancedCards .= '<a href="' . $escape($action['href'] ?? 'marketing_system_map.php') . '">'
                    . '<strong>' . $escape($action['label'] ?? 'Open advanced tool') . '</strong>'
                    . '<span>' . $escape($action['reason'] ?? 'Expert route retained for operators and admins.') . '</span>'
                    . '</a>';
            }
            $advanced = '<details class="marketing-nextstep-advanced marketing-advanced-tools">'
                . '<summary>Advanced Marketing Tools</summary>'
                . '<div class="marketing-advanced-tools-grid">' . $advancedCards . '</div>'
                . '</details>';
        }

        return '<div class="content-card marketing-nextstep-panel">'
            . '<div class="marketing-nextstep-score">'
            . '<strong>' . (int) ($center['score'] ?? 0) . '%</strong>'
            . '<span>' . $escape($labelize($status)) . '</span>'
            . '<span>' . (int) ($priorityCounts['high'] ?? 0) . ' high-priority action(s)</span>'
            . '<span>Manual-first</span>'
            . '</div>'
            . '<div>'
            . '<div class="premium-section-header">'
            . '<div><h2>' . $escape($center['title'] ?? 'Workflow Next Steps') . '</h2>'
            . '<p>' . $escape($center['subtitle'] ?? 'Recommended next actions for this Marketing workflow.') . '</p></div>'
            . '<span class="marketing-nextstep-status ' . $escape($status) . '">' . $escape($labelize($status)) . '</span>'
            . '</div>'
            . '<div class="marketing-nextstep-grid">' . $cards . '</div>'
            . $advanced
            . '<div class="marketing-nextstep-footnote">'
            . '<span>' . $escape($center['recommended_product_decision'] ?? 'Use recommended next steps to route operators to the exact Marketing surface that resolves the current gap.') . '</span>'
            . '<span>No external send, publish, or channel API call is triggered from this panel.</span>'
            . '</div>'
            . '</div>'
            . '</div>';
    }
}
