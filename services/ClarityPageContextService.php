<?php

namespace CRM\Services;

class ClarityPageContextService
{
    /**
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId, ?string $currentPage, array $operatingContext = []): array
    {
        $page = $this->normalizePage((string) ($currentPage ?? ''));
        $profile = $this->pageProfile($page);
        $marketingContext = MarketingPageContextService::contextForPage($page);
        if ($marketingContext !== null) {
            $profile = $this->mergeMarketingProfile($profile, $marketingContext);
        }

        $openingInsight = [];
        if ($workspaceId > 0 && $userId > 0) {
            try {
                $openingInsight = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, $page, false);
            } catch (\Throwable $e) {
                $openingInsight = [];
            }
        }
        if ($openingInsight === []) {
            $openingInsight = $this->fallbackOpeningInsight($page, $profile, $marketingContext);
        }

        return [
            'source' => 'clarity_page_context',
            'page' => [
                'filename' => $page,
                'family' => (string) ($profile['family'] ?? 'workspace'),
                'purpose' => (string) ($profile['purpose'] ?? 'workspace context'),
                'entity_focus' => array_values((array) ($profile['entity_focus'] ?? [])),
                'empty_behavior' => (string) ($profile['empty_behavior'] ?? 'make the current page more useful'),
            ],
            'opening_insight' => $this->safeOpeningInsight($openingInsight),
            'available_actions' => $this->availableActions($page, $openingInsight, $marketingContext),
            'page_data_signals' => $this->pageDataSignals($page, $operatingContext),
            'marketing_page_context' => $marketingContext ?? [],
        ];
    }

    private function normalizePage(string $page): string
    {
        $page = strtolower(trim($page));
        $page = basename(parse_url($page, PHP_URL_PATH) ?: $page);

        return $page !== '' ? $page : 'dashboard.php';
    }

    /**
     * @return array<string,mixed>
     */
    private function pageProfile(string $page): array
    {
        $profiles = [
            'dashboard.php' => [
                'family' => 'workspace_overview',
                'purpose' => 'system health, setup confidence, and today focus',
                'entity_focus' => ['setup', 'skills', 'tasks', 'deals', 'leads'],
                'empty_behavior' => 'choose one setup, lead, or task signal that improves confidence',
            ],
            'contacts.php' => [
                'family' => 'crm_records',
                'purpose' => 'contact usefulness and lead quality',
                'entity_focus' => ['contacts', 'leads', 'ownership', 'relationship_activity'],
                'empty_behavior' => 'add enough real contact details for useful guidance',
            ],
            'leads.php' => [
                'family' => 'crm_records',
                'purpose' => 'lead usefulness and follow-up quality',
                'entity_focus' => ['leads', 'ownership', 'timing', 'relationship_activity'],
                'empty_behavior' => 'capture real lead data before scoring or follow-up advice',
            ],
            'deals.php' => [
                'family' => 'pipeline',
                'purpose' => 'pipeline usefulness and close confidence',
                'entity_focus' => ['deals', 'close_dates', 'owners', 'next_steps'],
                'empty_behavior' => 'create real opportunities with value, stage, owner, and close date',
            ],
            'tasks.php' => [
                'family' => 'execution',
                'purpose' => 'task hygiene and completion clarity',
                'entity_focus' => ['tasks', 'due_dates', 'owners', 'outcomes'],
                'empty_behavior' => 'create tasks from real setup, follow-up, or customer commitments',
            ],
            'workspace_skills.php' => [
                'family' => 'marketplace',
                'purpose' => 'Marketplace fit and installed skill context',
                'entity_focus' => ['plugins', 'installed_skills', 'setup_readiness'],
                'empty_behavior' => 'show one best-fit Marketplace action when available',
            ],
            'settings.php' => [
                'family' => 'configuration',
                'purpose' => 'setup confidence and system readiness',
                'entity_focus' => ['workspace_setup', 'skill_setup', 'automation_readiness'],
                'empty_behavior' => 'explain the strongest setup confidence blocker',
            ],
            'inbox.php' => [
                'family' => 'communications',
                'purpose' => 'message triage and customer follow-through',
                'entity_focus' => ['threads', 'unread_items', 'response_due_dates', 'owners'],
                'empty_behavior' => 'connect an inbox source or capture a real customer thread',
            ],
            'hr_analytics.php' => [
                'family' => 'organization_intelligence',
                'purpose' => 'executive organization intelligence and support signals',
                'entity_focus' => ['organization_health', 'workload_signals', 'priorities', 'confidence'],
                'empty_behavior' => 'validate visible signals before taking an executive action',
            ],
        ];

        return $profiles[$page] ?? [
            'family' => str_starts_with($page, 'marketing_') || $page === 'marketing.php' ? 'marketing' : 'workspace',
            'purpose' => 'workspace context',
            'entity_focus' => ['current_page', 'missing_data', 'next_action'],
            'empty_behavior' => 'make the current page more useful',
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $marketingContext
     * @return array<string,mixed>
     */
    private function mergeMarketingProfile(array $profile, array $marketingContext): array
    {
        return array_merge($profile, [
            'family' => 'marketing',
            'purpose' => (string) ($marketingContext['plain_english_purpose'] ?? $profile['purpose'] ?? 'marketing context'),
            'entity_focus' => array_values(array_filter([
                'marketing_stage',
                (string) ($marketingContext['stage_key'] ?? ''),
                (string) ($marketingContext['expert_object_name'] ?? ''),
            ])),
            'empty_behavior' => (string) ($marketingContext['current_likely_blocker'] ?? $profile['empty_behavior'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed>|null $marketingContext
     * @return array<string,mixed>
     */
    private function fallbackOpeningInsight(string $page, array $profile, ?array $marketingContext): array
    {
        if ($marketingContext !== null) {
            return [
                'kind' => 'marketing_page_context',
                'title' => (string) ($marketingContext['founder_job'] ?? 'Move Marketing Forward'),
                'body' => (string) ($marketingContext['plain_english_purpose'] ?? 'This Marketing page supports the guided founder path.'),
                'bullets' => array_values(array_filter([
                    'Likely blocker: ' . (string) ($marketingContext['current_likely_blocker'] ?? ''),
                    'Next move: ' . (string) ($marketingContext['one_next_step'] ?? ''),
                ])),
                'source' => 'clarity_page_context',
            ];
        }

        return [
            'kind' => 'general',
            'title' => 'Make This Page More Useful',
            'body' => 'Clarity is reading this page as ' . (string) ($profile['purpose'] ?? 'workspace context') . '.',
            'bullets' => [
                'Ask what information on this page is missing, stale, or hard to trust.',
                'Use Clarity to decide what would make this page more useful before jumping elsewhere.',
            ],
            'source' => 'clarity_page_context',
            'page' => $page,
        ];
    }

    /**
     * @param array<string,mixed> $insight
     * @return array<string,mixed>
     */
    private function safeOpeningInsight(array $insight): array
    {
        $safe = [
            'kind' => $this->safeScalar($insight['kind'] ?? 'general', 80),
            'title' => $this->safeScalar($insight['title'] ?? '', 120),
            'body' => $this->safeScalar($insight['body'] ?? '', 280),
            'bullets' => $this->safeList($insight['bullets'] ?? [], 4, 220),
            'source' => $this->safeScalar($insight['source'] ?? 'clarity_page_insight', 80),
        ];

        $ctaLabel = $this->safeScalar($insight['cta_label'] ?? '', 80);
        $ctaUrl = $this->safeUrl((string) ($insight['cta_url'] ?? ''));
        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $safe['cta'] = [
                'label' => $ctaLabel,
                'url' => $ctaUrl,
                'reason' => $this->safeScalar($insight['cta_reason'] ?? '', 80),
            ];
        }

        foreach (['skill_key', 'task_id', 'setup_key'] as $key) {
            if (array_key_exists($key, $insight) && is_scalar($insight[$key])) {
                $safe[$key] = $this->safeScalar($insight[$key], 80);
            }
        }

        return $safe;
    }

    /**
     * @param array<string,mixed>|null $marketingContext
     * @return array<int,array<string,string>>
     */
    private function availableActions(string $page, array $openingInsight, ?array $marketingContext): array
    {
        $actions = [
            [
                'type' => 'answer_question',
                'label' => 'Answer a question about this page',
                'safety' => 'help_only',
            ],
        ];

        $ctaLabel = $this->safeScalar($openingInsight['cta_label'] ?? '', 80);
        $ctaUrl = $this->safeUrl((string) ($openingInsight['cta_url'] ?? ''));
        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $actions[] = [
                'type' => 'navigate',
                'label' => $ctaLabel,
                'url' => $ctaUrl,
                'safety' => 'navigation_only',
            ];
        }

        if ($marketingContext !== null) {
            $nextStep = $this->safeScalar($marketingContext['one_next_step'] ?? '', 120);
            if ($nextStep !== '') {
                $actions[] = [
                    'type' => 'explain_next_step',
                    'label' => $nextStep,
                    'safety' => 'help_only',
                ];
            }
        }

        if ($page === 'workspace_skills.php') {
            $actions[] = [
                'type' => 'explain_skill_readiness',
                'label' => 'Explain which skill setup matters next',
                'safety' => 'help_only',
            ];
        }

        return array_slice($actions, 0, 4);
    }

    /**
     * @return array<string,mixed>
     */
    private function pageDataSignals(string $page, array $operatingContext): array
    {
        $signals = [
            'readiness_gaps' => array_slice(array_values((array) ($operatingContext['feature_state']['readiness_gaps'] ?? [])), 0, 4),
            'missing_context_flags' => array_slice(array_values((array) ($operatingContext['missing_context_flags'] ?? [])), 0, 6),
        ];

        if (in_array($page, ['dashboard.php', 'deals.php'], true)) {
            $signals['pipeline_state'] = (array) ($operatingContext['pipeline_state'] ?? []);
        }
        if (in_array($page, ['dashboard.php', 'tasks.php'], true)) {
            $signals['task_state'] = (array) ($operatingContext['task_state'] ?? []);
        }
        if (in_array($page, ['dashboard.php', 'targets.php'], true)) {
            $signals['target_state'] = (array) ($operatingContext['target_state'] ?? []);
        }
        if (in_array($page, ['dashboard.php', 'inbox.php'], true)) {
            $signals['inbox_state'] = (array) ($operatingContext['inbox_state'] ?? []);
        }
        if ($page === 'workspace_skills.php') {
            $skills = (array) ($operatingContext['workspace_skills'] ?? []);
            $signals['workspace_skills'] = [
                'installed_keys' => array_values((array) ($skills['installed_keys'] ?? [])),
                'ready_advice_skills' => array_values((array) ($skills['ready_advice_skills'] ?? [])),
            ];
            $signals['workspace_marketplace'] = [
                'top_recommendation_keys' => array_values((array) ($operatingContext['workspace_marketplace']['top_recommendation_keys'] ?? [])),
                'visibility' => (string) ($operatingContext['workspace_marketplace']['visibility'] ?? ''),
            ];
        }

        return array_filter($signals, static fn($value): bool => $value !== [] && $value !== '');
    }

    private function safeScalar($value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '');
        if ($text === '' || strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(substr($text, 0, max(1, $maxLength - 3))) . '...';
    }

    /**
     * @return array<int,string>
     */
    private function safeList($value, int $limit, int $maxLength): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = $this->safeScalar($item, $maxLength);
            if ($text === '' || in_array($text, $items, true)) {
                continue;
            }
            $items[] = $text;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(?:javascript|data):#i', $url) === 1) {
            return '';
        }

        return $this->safeScalar($url, 240);
    }
}
