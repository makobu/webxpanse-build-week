<?php

namespace CRM\Services;

class AIAdviceDomainRouterService
{
    private const PRODUCT_NAVIGATION_PATTERNS = [
        'where is', 'where can i', 'where do i', 'show me where', 'open ', 'navigate', 'configure',
        'dashboard', 'settings', 'marketplace', 'workspace skills', 'login',
        'password', 'page', 'button', 'menu', 'tab', 'feature', 'crm help',
        'use this app', 'find ',
    ];

    private const PRODUCT_HELP_PATTERNS = [
        'how do i use', 'how does this app', 'what is this page', 'what does this button',
        'clarity help', 'crm help',
    ];

    private const CRM_OPERATION_PATTERNS = [
        'create task', 'create a task', 'add task', 'add a task', 'update task', 'mark task', 'complete task',
        'create contact', 'add contact', 'update contact', 'create deal',
        'update deal', 'move deal', 'create invoice', 'send invoice',
        'schedule', 'remind me', 'follow up with', 'log call', 'record note', 'call ',
    ];

    private const DOMAIN_TAXONOMY = [
        'startup' => ['startup', 'founder', 'startup idea', 'mvp', 'early customer', 'launch idea'],
        'validation' => ['validate', 'validation', 'assumption', 'experiment', 'customer interview', 'test my idea'],
        'business_model' => ['business model', 'lean canvas', 'customer segment', 'value proposition', 'revenue stream', 'cost structure', 'unfair advantage'],
        'pricing' => ['price', 'pricing', 'charge', 'subscription', 'revenue model', 'margin', 'package'],
        'metrics' => ['metric', 'kpi', 'measure', 'north star', 'conversion', 'retention', 'churn'],
        'marketing' => ['marketing', 'channel strategy', 'brand', 'demand generation'],
        'campaigns' => ['campaign', 'launch email', 'sequence', 'nurture', 'social post', 'ad campaign'],
        'positioning' => ['positioning', 'differentiation', 'competitor', 'category', 'claim', 'offer angle'],
        'messaging' => ['message', 'messaging', 'copy', 'headline', 'landing page', 'email copy', 'whatsapp message'],
        'content' => ['content', 'post idea', 'newsletter', 'blog', 'linkedin post'],
        'outreach' => ['outreach', 'cold email', 'prospect', 'lead magnet', 'follow-up sequence'],
        'growth' => ['grow', 'growth', 'scale', 'strategy', 'what should i do', 'next move', 'more customers'],
    ];

    private const DOMAIN_ALIASES = [
        'validation' => ['startup', 'business_model'],
        'retention' => ['metrics', 'growth'],
        'content' => ['marketing', 'campaigns'],
        'messaging' => ['marketing', 'positioning'],
        'outreach' => ['marketing', 'campaigns'],
    ];

    public function evaluate(string $message, array $operatingContext, array $questionIntent = []): array
    {
        $message = trim($message);
        $lower = strtolower($message);
        $contracts = (array) ($operatingContext['installed_skill_contracts']
            ?? $operatingContext['workspace_skills']['installed_skill_contracts']
            ?? []);

        if ($message === '') {
            return $this->decision('product_help', [], [], '', '', 1.0);
        }

        // Explaining server-owned evidence is product interpretation, not new
        // strategic advice. Keep it available even when words such as churn,
        // pricing, or risk would otherwise route into a gated advice domain.
        if (($questionIntent['intent'] ?? '') === 'explanation') {
            return $this->decision('allowed', [], [], '', '', 0.98) + [
                'explanation_request' => true,
            ];
        }

        $domains = $this->detectDomains($lower, $contracts);
        $productNavigation = $this->containsAny($lower, self::PRODUCT_NAVIGATION_PATTERNS);
        $productHelp = $this->containsAny($lower, self::PRODUCT_HELP_PATTERNS);
        $crmOperation = $this->containsAny($lower, self::CRM_OPERATION_PATTERNS);

        if ($domains === [] && ($productHelp || $productNavigation)) {
            return $this->decision('product_help', [], [], '', '', 0.95);
        }

        if ($crmOperation && !$this->isStrategicAdviceRequest($lower)) {
            return $this->decision('allowed', [], [], '', '', 0.9);
        }

        if ($domains !== [] && $productNavigation && $this->looksLikeWhereToConfigure($lower)) {
            return $this->decision('product_help', [], [], '', '', 0.82);
        }

        if ($domains === []) {
            return $this->decision('product_help', [], [], '', '', 0.55);
        }

        $matched = [];
        $installedNotReady = [];
        $bestConfidence = 0.72;
        foreach ($contracts as $contract) {
            $policy = (string) ($contract['boundary_policy'] ?? 'strict');
            $skillDomains = $this->normalizeDomains((array) ($contract['advice_domains'] ?? []));
            if ($skillDomains === [] || array_intersect($domains, $skillDomains) === []) {
                continue;
            }
            if ($policy === 'orchestrator_only') {
                continue;
            }
            if (!empty($contract['readiness']['ready'])) {
                $matched[] = (string) ($contract['key'] ?? '');
                $bestConfidence = max($bestConfidence, $this->contractMatchConfidence($domains, $contract));
            } else {
                $installedNotReady[] = (string) ($contract['key'] ?? '');
            }
        }
        $matched = array_values(array_filter(array_unique($matched)));
        if ($matched !== []) {
            return $this->decision('allowed', $matched, [], '', '', $bestConfidence);
        }

        $recommended = $this->recommendedSkillKey($domains, $contracts, $installedNotReady);

        return $this->decision(
            'blocked_skill_required',
            [],
            $domains,
            $recommended,
            'Business advice is available only when a ready installed skill covers the request domain.',
            0.86
        );
    }

    private function detectDomains(string $message, array $contracts): array
    {
        $domains = [];
        foreach (self::DOMAIN_TAXONOMY as $domain => $keywords) {
            if ($this->containsAny($message, $keywords)) {
                $domains[] = $domain;
            }
        }

        foreach ($contracts as $contract) {
            foreach ($this->normalizeDomains((array) ($contract['advice_domains'] ?? [])) as $domain) {
                if ($this->containsAny($message, [$domain, str_replace('_', ' ', $domain)])) {
                    $domains[] = $domain;
                }
            }
            foreach ((array) ($contract['routing_examples'] ?? []) as $example) {
                $example = strtolower((string) $example);
                if ($example !== '' && $this->roughlyMatches($message, $example)) {
                    foreach ($this->normalizeDomains((array) ($contract['advice_domains'] ?? [])) as $domain) {
                        $domains[] = $domain;
                    }
                }
            }
        }

        $expanded = [];
        foreach (array_values(array_unique(array_filter($domains))) as $domain) {
            $expanded[] = $domain;
            foreach (self::DOMAIN_ALIASES[$domain] ?? [] as $alias) {
                $expanded[] = $alias;
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    private function recommendedSkillKey(array $domains, array $contracts, array $installedNotReady): string
    {
        if ($installedNotReady !== []) {
            return (string) $installedNotReady[0];
        }

        if (array_intersect($domains, ['marketing', 'campaigns', 'positioning', 'messaging', 'outreach']) !== []) {
            return WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO;
        }

        if (array_intersect($domains, ['startup', 'business_model', 'pricing', 'metrics', 'growth']) !== []) {
            return WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS;
        }

        foreach ($contracts as $contract) {
            if (array_intersect($domains, $this->normalizeDomains((array) ($contract['advice_domains'] ?? []))) !== []) {
                return (string) ($contract['key'] ?? '');
            }
        }

        return '';
    }

    private function decision(
        string $decision,
        array $matchedSkillKeys = [],
        array $missingDomains = [],
        string $recommendedSkillKey = '',
        string $blockedReason = '',
        float $confidence = 0.75
    ): array {
        return [
            'skill_route_decision' => $decision,
            'matched_skill_keys' => array_values(array_filter($matchedSkillKeys)),
            'missing_skill_domains' => array_values(array_filter($missingDomains)),
            'recommended_skill_key' => $recommendedSkillKey,
            'blocked_reason' => $blockedReason,
            'confidence' => round(max(0.0, min(1.0, $confidence)), 4),
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = strtolower((string) $needle);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function roughlyMatches(string $message, string $example): bool
    {
        $hits = 0;
        foreach (preg_split('/\s+/', $example) ?: [] as $term) {
            $term = trim($term, " \t\n\r\0\x0B?.!,");
            if (strlen($term) >= 5 && str_contains($message, $term)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function normalizeDomains(array $domains): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            $domain = trim(preg_replace('/[^a-z0-9_]+/', '_', $domain) ?? '', '_');
            if ($domain !== '') {
                $out[] = $domain;
            }
        }

        return array_values(array_unique($out));
    }

    private function looksLikeWhereToConfigure(string $message): bool
    {
        return preg_match('/\b(where|open|show|find|navigate)\b.*\b(set|setup|configure|settings|page|screen|field|button|menu)\b/', $message) === 1
            || preg_match('/\b(set|setup|configure|settings)\b.*\b(where|page|screen|field|button|menu)\b/', $message) === 1;
    }

    private function isStrategicAdviceRequest(string $message): bool
    {
        return preg_match('/\b(strategy|advise|advice|recommend|should|grow|scale|position|price|campaign|validate|business model)\b/', $message) === 1;
    }

    private function contractMatchConfidence(array $domains, array $contract): float
    {
        $skillDomains = $this->normalizeDomains((array) ($contract['advice_domains'] ?? []));
        $overlap = count(array_intersect($domains, $skillDomains));
        $base = $overlap > 1 ? 0.9 : 0.82;
        if (!empty($contract['routing_examples'])) {
            $base += 0.04;
        }
        if ((string) ($contract['definition_source'] ?? 'platform') !== 'platform') {
            $base += 0.02;
        }

        return min(0.98, $base);
    }
}
