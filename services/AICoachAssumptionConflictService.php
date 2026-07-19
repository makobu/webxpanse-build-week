<?php

namespace CRM\Services;

use CRM\Database;

class AICoachAssumptionConflictService
{
    /**
     * @param array<string,mixed> $operatingContext
     * @return list<array<string,mixed>>
     */
    public function detect(int $workspaceId, int $userId, array $operatingContext = []): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        if ($workspaceId <= 0 || $userId <= 0) {
            return [];
        }

        $journey = $this->journeyContext($workspaceId, $userId, $operatingContext);
        $responses = $this->responsesByStage($journey);
        if ($responses === []) {
            return [];
        }

        $conflicts = [];
        $finance = $this->financeContext($workspaceId, $userId, $operatingContext);
        $this->appendChannelConflict($conflicts, $workspaceId, $responses);
        $this->appendSegmentConflict($conflicts, $workspaceId, $responses);
        $this->appendPricingConflict($conflicts, $workspaceId, $finance);
        $this->appendRevenueModelConflict($conflicts, $responses, $finance);
        $this->appendCostStructureConflict($conflicts, $responses, $finance);
        $this->appendExperimentBudgetConflict($conflicts, $responses, $finance);
        $this->appendRunwayConflict($conflicts, $responses, $finance);
        $this->appendCacConflict($conflicts, $responses, $finance);
        $this->appendFounderLoopOutcomeConflict($conflicts, $responses, (array) ($operatingContext['founder_operating_loop_context'] ?? []));

        return array_slice($conflicts, 0, 8);
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @return array<string,mixed>
     */
    private function journeyContext(int $workspaceId, int $userId, array $operatingContext): array
    {
        $journey = (array) ($operatingContext['startup_journey_context'] ?? []);
        if ($journey !== []) {
            return $journey;
        }
        if (!Database::tableExists('startup_journeys')) {
            return [];
        }
        try {
            return (new StartupJourneyService())->getContextForAI($workspaceId, $userId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @return array<string,mixed>
     */
    private function financeContext(int $workspaceId, int $userId, array $operatingContext): array
    {
        $finance = (array) ($operatingContext['finance_context'] ?? []);
        if ($finance !== []) {
            return $finance;
        }
        try {
            $context = (new FounderFinanceService())->aiContext($workspaceId, $userId);
            return (array) ($context['finance_context'] ?? $context);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $journey
     * @return array<string,array<string,string>>
     */
    private function responsesByStage(array $journey): array
    {
        $responses = [];
        foreach ((array) ($journey['stages'] ?? []) as $stage) {
            $key = (string) ($stage['stage_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $responses[$key] = [];
            foreach ((array) ($stage['responses'] ?? []) as $field => $value) {
                $clean = $this->clean($value);
                if ($clean !== '') {
                    $responses[$key][(string) $field] = $clean;
                }
            }
        }
        return $responses;
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     */
    private function appendChannelConflict(array &$conflicts, int $workspaceId, array $responses): void
    {
        $channels = $this->clean(($responses['go_to_market']['channels'] ?? '') . ' ' . ($responses['lean_canvas']['channels'] ?? ''));
        if ($channels === '' || !Database::tableExists('contacts') || !Database::columnExists('contacts', 'lead_source')) {
            return;
        }

        $topSource = Database::queryOne(
            "SELECT lead_source, COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND lead_source IS NOT NULL
             GROUP BY lead_source
             ORDER BY c DESC
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
        $source = strtolower(trim((string) ($topSource['lead_source'] ?? '')));
        $count = (int) ($topSource['c'] ?? 0);
        if ($source === '' || $count < 2 || $this->textMentionsChannel($channels, $source)) {
            return;
        }

        $conflicts[] = $this->conflict(
            'gtm_channel',
            'medium',
            'Journey GTM channels: ' . $channels,
            'CRM lead source evidence: ' . $count . ' contacts are coming from ' . $source . '.',
            'Decide whether ' . $source . ' is an accidental source or update the Journey GTM channel assumption.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     */
    private function appendSegmentConflict(array &$conflicts, int $workspaceId, array $responses): void
    {
        $segment = $this->clean(($responses['customer_discovery']['target_customer'] ?? '') . ' ' . ($responses['lean_canvas']['customer_segments'] ?? '') . ' ' . ($responses['go_to_market']['beachhead_segment'] ?? ''));
        $token = $this->segmentToken($segment);
        if ($token === '' || !Database::tableExists('contacts')) {
            return;
        }

        $total = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ?", [$workspaceId])['c'] ?? 0);
        if ($total < 3) {
            return;
        }

        $like = '%' . $token . '%';
        $matches = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND (
                    LOWER(COALESCE(company, '')) LIKE ?
                    OR LOWER(COALESCE(first_name, '')) LIKE ?
                    OR LOWER(COALESCE(last_name, '')) LIKE ?
               )",
            [$workspaceId, $like, $like, $like]
        )['c'] ?? 0);
        if ($matches > 0) {
            return;
        }

        $conflicts[] = $this->conflict(
            'target_customer',
            'low',
            'Journey target customer: ' . $segment,
            'CRM evidence: ' . $total . ' contacts exist, but none visibly match the "' . $token . '" segment token.',
            'Tag or enrich contacts by segment, then confirm whether the Journey target customer still fits the pipeline.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,mixed> $finance
     */
    private function appendPricingConflict(array &$conflicts, int $workspaceId, array $finance): void
    {
        $targetDealValue = (float) ($finance['target_deal_value'] ?? 0);
        if ($targetDealValue <= 0) {
            return;
        }

        $count = (int) ($finance['paid_invoice_count'] ?? 0);
        $avg = (float) ($finance['avg_paid_invoice'] ?? 0);
        if (($count <= 0 || $avg <= 0) && Database::tableExists('invoices')) {
            $row = Database::queryOne(
                "SELECT AVG(CASE WHEN amount_paid > 0 THEN amount_paid ELSE grand_total END) AS avg_paid_invoice, COUNT(*) AS c
                 FROM invoices
                 WHERE workspace_id = ?
                   AND status IN ('paid','partially_paid')
                   AND document_type = 'invoice'",
                [$workspaceId]
            ) ?: [];
            $count = (int) ($row['c'] ?? 0);
            $avg = (float) ($row['avg_paid_invoice'] ?? 0);
        }
        if ($count <= 0 || $avg <= 0) {
            return;
        }

        $delta = abs($avg - $targetDealValue) / max($targetDealValue, 1);
        if ($delta < 0.35) {
            return;
        }

        $conflicts[] = $this->conflict(
            'pricing',
            $delta >= 0.6 ? 'high' : 'medium',
            'Finance/Journey target deal value: ' . number_format($targetDealValue, 2),
            'Paid invoice evidence: average paid invoice is ' . number_format($avg, 2) . ' across ' . $count . ' paid invoice' . ($count === 1 ? '' : 's') . '.',
            'Review pricing evidence in Founder Loop and update the offer or target deal value assumption.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $finance
     */
    private function appendRevenueModelConflict(array &$conflicts, array $responses, array $finance): void
    {
        $revenueAssumption = $this->clean(
            ($responses['lean_canvas']['revenue_streams'] ?? '') . ' ' .
            ($responses['aarrr']['revenue'] ?? '')
        );
        if ($revenueAssumption === '') {
            return;
        }

        $invoiceIncome = (float) ($finance['invoice_sales_income'] ?? 0);
        $manualIncome = (float) ($finance['manual_sales_income'] ?? 0) + (float) ($finance['manual_other_income'] ?? 0);
        $pipeline = (float) ($finance['pipeline_revenue'] ?? 0);
        $paidInvoices = (int) ($finance['paid_invoice_count'] ?? 0);
        if ($manualIncome > 0 && $invoiceIncome <= 0 && $this->containsAny($revenueAssumption, ['subscription', 'monthly', 'retainer', 'pilot', 'plan', 'invoice'])) {
            $conflicts[] = $this->conflict(
                'revenue_model',
                'medium',
                'Journey revenue assumption: ' . $revenueAssumption,
                'Finance evidence: revenue is recorded manually (' . $this->formatMoney($manualIncome) . ') with no paid invoice-backed revenue this period.',
                'Decide whether manual revenue is an intentional early signal or whether invoices/products need to match the Journey revenue model.'
            );
            return;
        }

        if ($pipeline > 0 && $paidInvoices <= 0 && $this->containsAny($revenueAssumption, ['paid', 'revenue', 'pilot', 'subscription', 'monthly'])) {
            $conflicts[] = $this->conflict(
                'revenue_model',
                'low',
                'Journey revenue assumption: ' . $revenueAssumption,
                'Finance evidence: pipeline value is ' . $this->formatMoney($pipeline) . ', but no paid invoices are recorded yet.',
                'Use Founder Loop to convert one pipeline opportunity into a paid invoice or update the revenue timing assumption.'
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $finance
     */
    private function appendCostStructureConflict(array &$conflicts, array $responses, array $finance): void
    {
        $costAssumption = $this->clean($responses['lean_canvas']['cost_structure'] ?? '');
        $topExpense = $this->topExpenseCategory($finance);
        if ($costAssumption === '' || $topExpense === []) {
            return;
        }

        $amount = (float) ($topExpense['total_amount'] ?? 0);
        $moneyOut = (float) ($finance['money_out'] ?? 0);
        $share = $moneyOut > 0 ? $amount / $moneyOut : 0;
        $label = (string) ($topExpense['category_name'] ?? 'expense');
        $type = (string) ($topExpense['category_type'] ?? '');
        if ($amount <= 0 || $share < 0.4 || $this->textMentionsCostCategory($costAssumption, $label, $type)) {
            return;
        }

        $conflicts[] = $this->conflict(
            'cost_structure',
            $share >= 0.65 ? 'medium' : 'low',
            'Journey cost structure: ' . $costAssumption,
            'Finance evidence: the largest expense category is ' . $label . ' at ' . $this->formatMoney($amount) . ', about ' . (int) round($share * 100) . '% of this period spend.',
            'Check whether this spend is a real cost driver and update the Journey cost structure or reduce the weekly budget.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $finance
     */
    private function appendExperimentBudgetConflict(array &$conflicts, array $responses, array $finance): void
    {
        $budgetText = $this->clean($responses['mvp']['experiment_budget'] ?? '');
        $budget = $this->moneyAmountFromText($budgetText);
        $actualSpend = (float) ($finance['money_out'] ?? 0);
        if ($budgetText === '' || $budget <= 0 || $actualSpend <= ($budget * 1.25)) {
            return;
        }

        $conflicts[] = $this->conflict(
            'experiment_budget',
            $actualSpend >= ($budget * 2) ? 'high' : 'medium',
            'Journey MVP experiment budget: ' . $budgetText,
            'Finance evidence: this period spend is ' . $this->formatMoney($actualSpend) . ', above the implied experiment budget of ' . $this->formatMoney($budget) . '.',
            'Review whether the MVP test is still constrained enough, then set this week\'s Founder Loop budget guardrail.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $finance
     */
    private function appendRunwayConflict(array &$conflicts, array $responses, array $finance): void
    {
        if (!array_key_exists('runway_months', $finance) || $finance['runway_months'] === null) {
            return;
        }
        $runway = (float) $finance['runway_months'];
        if ($runway >= 3) {
            return;
        }

        $launchAssumption = $this->clean(
            ($responses['go_to_market']['launch_plan'] ?? '') . ' ' .
            ($responses['go_to_market']['conversion_goal'] ?? '') . ' ' .
            ($responses['okrs']['objective'] ?? '') . ' ' .
            ($responses['okrs']['key_result_1'] ?? '') . ' ' .
            ($responses['okrs']['key_result_2'] ?? '') . ' ' .
            ($responses['okrs']['key_result_3'] ?? '')
        );
        if ($launchAssumption === '' || !$this->containsAny($launchAssumption, ['launch', 'pilot', 'deal', 'customer', 'revenue', 'paid', 'close', 'convert'])) {
            return;
        }

        $conflicts[] = $this->conflict(
            'runway',
            $runway < 1.5 ? 'high' : 'medium',
            'Journey launch/OKR assumption: ' . $launchAssumption,
            'Finance evidence: runway is about ' . number_format($runway, 1) . ' month' . ($runway === 1.0 ? '' : 's') . ' based on current reserve and burn.',
            'Shorten the next Founder Loop commitment to revenue collection, expense reduction, or a narrower paid pilot before extending the launch plan.'
        );
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $finance
     */
    private function appendCacConflict(array &$conflicts, array $responses, array $finance): void
    {
        $channelAssumption = $this->clean(
            ($responses['go_to_market']['channels'] ?? '') . ' ' .
            ($responses['lean_canvas']['channels'] ?? '') . ' ' .
            ($responses['go_to_market']['sales_motion'] ?? '')
        );
        $cac = array_key_exists('cac', $finance) && $finance['cac'] !== null ? (float) $finance['cac'] : null;
        $targetCac = array_key_exists('target_cac', $finance) && $finance['target_cac'] !== null ? (float) $finance['target_cac'] : null;
        if ($cac !== null && $targetCac !== null && $targetCac > 0 && $cac > $targetCac) {
            $conflicts[] = $this->conflict(
                'cac',
                $cac >= ($targetCac * 1.75) ? 'high' : 'medium',
                'Journey GTM channel assumption: ' . ($channelAssumption !== '' ? $channelAssumption : 'customer acquisition should be efficient enough for the target CAC'),
                'Finance evidence: CAC is ' . $this->formatMoney($cac) . ' against a target CAC of ' . $this->formatMoney($targetCac) . '.',
                'Review the channel mix and move this week\'s commitment toward lower-cost conversations or higher-value deal follow-up.'
            );
            return;
        }

        $marketingSpend = $this->marketingSpendFromFinance($finance);
        $paidCustomers = (int) ($finance['paid_customer_count'] ?? 0);
        if ($marketingSpend > 0 && $paidCustomers <= 0 && $this->containsAny($channelAssumption, ['ad', 'ads', 'paid', 'campaign', 'outbound', 'linkedin'])) {
            $conflicts[] = $this->conflict(
                'cac',
                'medium',
                'Journey GTM channel assumption: ' . $channelAssumption,
                'Finance evidence: marketing/channel spend is ' . $this->formatMoney($marketingSpend) . ', but no paid customers are recorded this period.',
                'Pause broad spend until Founder Loop names the next paid-customer follow-up or interview that can prove willingness to pay.'
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $founderLoop
     */
    private function appendFounderLoopOutcomeConflict(array &$conflicts, array $responses, array $founderLoop): void
    {
        if ($founderLoop === []) {
            return;
        }

        $objectiveText = strtolower($this->clean(
            ($responses['okrs']['objective'] ?? '') . ' ' .
            ($responses['okrs']['key_result_1'] ?? '') . ' ' .
            ($responses['okrs']['key_result_2'] ?? '') . ' ' .
            ($responses['okrs']['key_result_3'] ?? '') . ' ' .
            ($responses['go_to_market']['conversion_goal'] ?? '')
        ));
        if ($objectiveText === '' || !preg_match('/deal|customer|pilot|revenue|paid|close/', $objectiveText)) {
            return;
        }

        $signal = (array) ($founderLoop['first_customer_signal'] ?? []);
        $movement = (int) ($signal['leads_created'] ?? 0)
            + (int) ($signal['open_deals'] ?? 0)
            + (int) ($signal['deals_opened'] ?? 0)
            + (int) ($signal['deals_won'] ?? 0)
            + (int) ($signal['paid_customer_count'] ?? 0);
        $activeTasks = (int) ($signal['open_founder_tasks'] ?? 0) + (int) ($signal['completed_founder_tasks'] ?? 0);
        if ($activeTasks <= 0 || $movement > 0) {
            return;
        }

        $conflicts[] = $this->conflict(
            'okr_execution',
            'medium',
            'Journey OKR/conversion assumption: ' . $objectiveText,
            'Founder Loop evidence: commitments exist, but there is no lead, deal, or paid-customer movement this week.',
            'Narrow the weekly commitment to one named customer action and record the CRM outcome before the next review.'
        );
    }

    private function textMentionsChannel(string $text, string $source): bool
    {
        $text = strtolower($text);
        $synonyms = [
            'form' => ['form', 'website', 'site', 'landing page'],
            'whatsapp' => ['whatsapp', 'message', 'chat'],
            'ad' => ['ad', 'ads', 'paid', 'campaign'],
            'referral' => ['referral', 'partner', 'word of mouth', 'community'],
            'social' => ['social', 'linkedin', 'facebook', 'instagram', 'x', 'twitter'],
            'import' => ['import', 'list', 'csv'],
            'other' => ['other'],
        ];
        foreach ($synonyms[$source] ?? [$source] as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function containsAny(string $text, array $needles): bool
    {
        $text = strtolower($text);
        foreach ($needles as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $finance
     * @return array<string,mixed>
     */
    private function topExpenseCategory(array $finance): array
    {
        $expenses = array_values((array) ($finance['expense_by_category'] ?? []));
        usort($expenses, static function (array $a, array $b): int {
            return ((float) ($b['total_amount'] ?? 0)) <=> ((float) ($a['total_amount'] ?? 0));
        });
        return (array) ($expenses[0] ?? []);
    }

    private function textMentionsCostCategory(string $text, string $categoryName, string $categoryType): bool
    {
        $needles = [$categoryName, $categoryType];
        $synonyms = [
            'marketing' => ['marketing', 'ads', 'ad', 'campaign', 'paid acquisition', 'lead generation'],
            'operations' => ['operations', 'ops', 'software', 'tools', 'admin'],
            'inventory' => ['inventory', 'stock', 'fulfillment', 'delivery', 'cost of sales', 'cogs'],
            'payroll' => ['payroll', 'salary', 'wages', 'contractor', 'team'],
            'hosting' => ['hosting', 'cloud', 'server', 'ai usage', 'infrastructure'],
            'support' => ['support', 'service', 'customer success', 'onboarding'],
        ];
        $normalized = strtolower($categoryName . ' ' . $categoryType);
        foreach ($synonyms as $token => $words) {
            if (str_contains($normalized, $token)) {
                $needles = array_merge($needles, $words);
            }
        }
        return $this->containsAny($text, $needles);
    }

    private function moneyAmountFromText(string $text): float
    {
        if (!preg_match('/(?:\$|usd|kes|eur|gbp)?\s*([0-9][0-9,]*(?:\.[0-9]+)?)/i', $text, $match)) {
            return 0.0;
        }
        return (float) str_replace(',', '', (string) ($match[1] ?? '0'));
    }

    /**
     * @param array<string,mixed> $finance
     */
    private function marketingSpendFromFinance(array $finance): float
    {
        $marketingSpend = 0.0;
        foreach ((array) ($finance['expense_by_category'] ?? []) as $row) {
            $categoryType = strtolower((string) ($row['category_type'] ?? ''));
            $categoryName = strtolower((string) ($row['category_name'] ?? ''));
            if (str_contains($categoryType, 'marketing') || str_contains($categoryName, 'marketing') || str_contains($categoryName, 'ads')) {
                $marketingSpend += (float) ($row['total_amount'] ?? 0);
            }
        }
        return round($marketingSpend, 2);
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function segmentToken(string $segment): string
    {
        $segment = strtolower($segment);
        $tokens = [
            'agency' => ['agency', 'agencies'],
            'founder' => ['founder', 'founders'],
            'service' => ['service', 'services'],
            'consultant' => ['consultant', 'consultants', 'consulting'],
            'restaurant' => ['restaurant', 'restaurants'],
            'real estate' => ['real estate', 'realtor', 'property'],
            'ecommerce' => ['ecommerce', 'e-commerce', 'shopify'],
            'saas' => ['saas', 'software'],
        ];
        foreach ($tokens as $canonical => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($segment, $needle)) {
                    return $canonical;
                }
            }
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function conflict(string $type, string $severity, string $assumption, string $evidence, string $action): array
    {
        return [
            'type' => $type,
            'severity' => in_array($severity, ['low', 'medium', 'high'], true) ? $severity : 'medium',
            'journey_assumption' => $this->clean($assumption),
            'operating_evidence' => $this->clean($evidence),
            'suggested_next_action' => $this->clean($action),
        ];
    }

    private function clean(mixed $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
        return mb_substr($value, 0, 1200);
    }
}
