<?php

namespace CRM\Services;

use CRM\CacheManager;

class GuidedSetupDestinationService
{
    public const CACHE_VERSION = 'v2';
    public const CACHE_TTL_SECONDS = 300;

    private CacheManager $cache;
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceMarketplaceRecommendationService $recommendations;
    private WorkspaceMarketplaceSetupJourneyService $journeys;

    public function __construct(
        ?CacheManager $cache = null,
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceMarketplaceRecommendationService $recommendations = null,
        ?WorkspaceMarketplaceSetupJourneyService $journeys = null
    ) {
        $this->cache = $cache ?: new CacheManager();
        $this->catalog = $catalog ?: new WorkspaceSkillCatalogService();
        $this->recommendations = $recommendations ?: new WorkspaceMarketplaceRecommendationService($this->catalog);
        $this->journeys = $journeys ?: new WorkspaceMarketplaceSetupJourneyService($this->catalog);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function destinationFor(int $workspaceId, int $userId, array $context = []): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $mode = $this->normalizeMode((string) ($context['mode'] ?? UIExperienceService::MODE_BEGINNER));
        $source = $this->normalizeSource((string) ($context['source'] ?? 'direct'));
        $moduleKey = $this->normalizeKey((string) ($context['module'] ?? ''));
        $gapKey = $this->normalizeKey((string) ($context['gap'] ?? ''));
        $actionKey = $this->normalizeKey((string) ($context['action'] ?? ''));
        $nextAction = $this->nextActionContext((array) ($context['next_action'] ?? []));
        $context['next_action'] = $nextAction;
        $skipCache = !empty($context['skip_cache']);
        $cacheKey = implode(':', [
            'guided_setup_destination',
            self::CACHE_VERSION,
            $workspaceId,
            $userId,
            $mode,
            $source,
            $moduleKey !== '' ? $moduleKey : '-',
            $gapKey !== '' ? $gapKey : '-',
            $actionKey !== '' ? $actionKey : '-',
            $this->nextActionCacheIdentity($nextAction),
        ]);

        if (!$skipCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $mode === UIExperienceService::MODE_BEGINNER
            ? $this->buildBeginnerPayload($workspaceId, $userId, $source, $moduleKey, $gapKey, $actionKey, $context)
            : $this->advancedPayload($source, $moduleKey);

        if (!$skipCache) {
            $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildBeginnerPayload(
        int $workspaceId,
        int $userId,
        string $source,
        string $moduleKey,
        string $gapKey,
        string $actionKey,
        array $context
    ): array {
        $route = $this->routeForContext($moduleKey, $gapKey, $actionKey);
        $nextAction = (array) ($context['next_action'] ?? []);
        if ($this->shouldUseNextAction($route, $moduleKey, $gapKey, $nextAction)) {
            $route = $this->routeForNextAction($nextAction);
        }
        $targetModuleKey = (string) ($route['module_key'] ?? $moduleKey);
        $targetModule = $targetModuleKey !== '' ? $this->catalog->find($targetModuleKey) : null;

        $recommendations = $this->recommendationQueue($workspaceId, $userId, $context);
        $recommendedModules = $this->recommendedModules($recommendations, $context, 3, $targetModuleKey);

        if ($targetModuleKey === '' && $recommendedModules !== []) {
            $targetModuleKey = (string) ($recommendedModules[0]['module_key'] ?? '');
            $targetModule = $targetModuleKey !== '' ? $this->catalog->find($targetModuleKey) : null;
        }

        $journey = $targetModuleKey !== ''
            ? $this->journeyForModule($workspaceId, $userId, $targetModuleKey, $recommendations, $context)
            : [];
        $steps = $this->stepsForDestination($targetModuleKey, $targetModule ?: [], $journey, $route);
        $primaryStep = $this->primaryStep($targetModuleKey, $route, $steps, $targetModule ?: [], $journey);
        $progress = (array) ($journey['progress'] ?? []);
        $total = (int) ($progress['total'] ?? count($steps));
        $completed = (int) ($progress['completed'] ?? count(array_filter(
            $steps,
            static fn(array $step): bool => !empty($step['complete'])
        )));

        $payload = [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'show_guidance' => true,
            'surface' => 'workspace_skills',
            'source' => $source,
            'module_key' => $targetModuleKey,
            'headline' => $this->headline($route, $targetModule ?: [], $targetModuleKey === ''),
            'reason' => $this->reason($route, $targetModule ?: [], $targetModuleKey === ''),
            'status_label' => $this->statusLabel($route, $journey),
            'progress_label' => $total > 0 ? ($completed . ' of ' . $total . ' setup steps done') : '',
            'primary_step' => $primaryStep,
            'steps' => array_slice($steps, 0, 5),
            'recommended_modules' => $recommendedModules,
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
            'tracking_metadata' => [
                'source' => $source,
                'gap' => $gapKey,
                'action' => $actionKey,
                'module_key' => $targetModuleKey,
                'recommended_count' => count($recommendedModules),
            ],
        ];

        return $this->stripEmptyAction($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function advancedPayload(string $source, string $moduleKey): array
    {
        return [
            'mode' => UIExperienceService::MODE_ADVANCED,
            'show_guidance' => false,
            'surface' => 'workspace_skills',
            'source' => $source,
            'module_key' => $moduleKey,
            'primary_step' => [],
            'steps' => [],
            'recommended_modules' => [],
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function routeForContext(string $moduleKey, string $gapKey, string $actionKey): array
    {
        $key = $gapKey !== '' ? $gapKey : $actionKey;
        $routes = [
            'channel' => [
                'module_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'headline' => 'Connect email or WhatsApp',
                'reason' => 'Customer replies can be drafted after a channel is connected.',
                'cta_label' => 'Connect channel',
                'status_label' => 'Blocked until a channel is connected',
                'href' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
            ],
            'connect_channel' => [
                'module_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                'headline' => 'Connect email or WhatsApp',
                'reason' => 'Customer replies can be drafted after a channel is connected.',
                'cta_label' => 'Connect channel',
                'status_label' => 'Blocked until a channel is connected',
                'href' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
            ],
            'money' => [
                'module_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'headline' => 'Set up invoices',
                'reason' => 'Payment details are needed before quotes and invoices are ready for daily work.',
                'cta_label' => 'Set up invoices',
                'status_label' => 'Needs invoice settings first',
                'href' => 'workspace_skills.php?module=finance#setup',
            ],
            'finish_invoice_settings' => [
                'module_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'headline' => 'Set up invoices',
                'reason' => 'Payment details are needed before quotes and invoices are ready for daily work.',
                'cta_label' => 'Set up invoices',
                'status_label' => 'Needs invoice settings first',
                'href' => 'workspace_skills.php?module=finance#setup',
            ],
            'prepare_invoice' => [
                'module_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'headline' => 'Set up invoices',
                'reason' => 'Payment details are needed before quotes and invoices are ready for daily work.',
                'cta_label' => 'Set up invoices',
                'status_label' => 'Needs invoice settings first',
                'href' => 'workspace_skills.php?module=finance#setup',
            ],
            'safe_automation' => [
                'module_key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                'headline' => 'Choose how much approval AI needs',
                'reason' => 'Approval rules tell Clarity when to draft, suggest, or wait for you.',
                'cta_label' => 'Choose approval level',
                'status_label' => 'Needs your approval rules first',
                'href' => 'onboarding.php?step=4',
            ],
        ];

        if (isset($routes[$key])) {
            return $routes[$key];
        }

        if ($moduleKey !== '') {
            return [
                'module_key' => $moduleKey,
                'href' => 'workspace_skills.php?module=' . rawurlencode($moduleKey) . '#setup',
                'cta_label' => 'Open setup',
            ];
        }

        return [
            'module_key' => '',
            'headline' => 'Add only the capability that unblocks work',
            'reason' => 'Start with the smallest useful capability instead of browsing everything at once.',
            'cta_label' => 'Open best match',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function recommendationQueue(int $workspaceId, int $userId, array $context): array
    {
        $provided = (array) ($context['recommendations'] ?? []);
        if ($provided !== []) {
            return array_values(array_filter($provided, 'is_array'));
        }

        try {
            return $this->recommendations->recommendationsForWorkspace($workspaceId, $userId, 3, 'marketplace');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $recommendations
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function recommendedModules(array $recommendations, array $context, int $limit, string $excludeModuleKey = ''): array
    {
        $modulesByKey = (array) ($context['marketplace_modules_by_key'] ?? []);
        $excludeModuleKey = $this->normalizeKey($excludeModuleKey);
        $out = [];

        foreach ($recommendations as $recommendation) {
            $key = $this->normalizeKey((string) ($recommendation['skill_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if ($excludeModuleKey !== '' && $key === $excludeModuleKey) {
                continue;
            }

            $module = (array) (($modulesByKey[$key]['module'] ?? null) ?: ($this->catalog->find($key) ?? []));
            if ($module === []) {
                continue;
            }

            $label = (string) ($module['label'] ?? $recommendation['label'] ?? ucwords(str_replace('_', ' ', $key)));
            $out[] = [
                'module_key' => $key,
                'label' => $this->plainModuleLabel($key, $label),
                'reason' => $this->plainRecommendationReason((string) ($recommendation['why_now'] ?? ''), $key),
                'href' => 'workspace_skills.php?module=' . rawurlencode($key),
                'cta_label' => 'Open setup',
                'priority' => (string) ($recommendation['priority'] ?? 'medium'),
            ];

            if (count($out) >= max(1, $limit)) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $recommendations
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function journeyForModule(
        int $workspaceId,
        int $userId,
        string $moduleKey,
        array $recommendations,
        array $context
    ): array {
        $provided = (array) ($context['journeys_by_skill'] ?? []);
        if (isset($provided[$moduleKey]) && is_array($provided[$moduleKey])) {
            return (array) $provided[$moduleKey];
        }

        $installedByKey = (array) ($context['installed_by_key'] ?? []);
        $readinessByKey = (array) ($context['readiness_by_key'] ?? []);
        $moduleRecommendation = null;
        foreach ($recommendations as $recommendation) {
            if ($this->normalizeKey((string) ($recommendation['skill_key'] ?? '')) === $moduleKey) {
                $moduleRecommendation = $recommendation;
                break;
            }
        }
        if ($moduleRecommendation === null) {
            $module = $this->catalog->find($moduleKey) ?? [];
            $moduleRecommendation = [
                'skill_key' => $moduleKey,
                'label' => (string) ($module['label'] ?? ucwords(str_replace('_', ' ', $moduleKey))),
                'setup_url' => (string) ($module['settings_schema']['settings_url'] ?? $module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php?module=' . rawurlencode($moduleKey)),
                'is_installed' => isset($installedByKey[$moduleKey]),
                'readiness' => (array) ($readinessByKey[$moduleKey] ?? []),
            ];
        }

        try {
            $journeys = $this->journeys->journeysBySkill($workspaceId, $userId, [$moduleRecommendation], $installedByKey, $readinessByKey);
            return (array) ($journeys[$moduleKey] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $module
     * @param array<string,mixed> $journey
     * @param array<string,mixed> $route
     * @return array<int,array<string,mixed>>
     */
    private function stepsForDestination(string $moduleKey, array $module, array $journey, array $route): array
    {
        $steps = [];
        foreach ((array) ($journey['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }

            $label = $this->plainStepLabel($moduleKey, (string) ($step['label'] ?? 'Setup step'));
            if ($label === '') {
                continue;
            }
            $steps[] = [
                'key' => (string) ($step['step_key'] ?? ''),
                'label' => $label,
                'complete' => (string) ($step['status'] ?? 'pending') === 'completed',
                'status' => (string) ($step['status'] ?? 'pending'),
            ];
        }

        if ($steps !== []) {
            return $steps;
        }

        foreach ((array) ($module['setup_steps'] ?? []) as $index => $label) {
            $label = $this->plainStepLabel($moduleKey, (string) $label);
            if ($label === '') {
                continue;
            }
            $steps[] = [
                'key' => 'setup_' . (int) $index,
                'label' => $label,
                'complete' => false,
                'status' => 'pending',
            ];
        }

        if ($steps !== []) {
            return $steps;
        }

        $routeLabel = trim((string) ($route['headline'] ?? 'Open setup'));
        return $routeLabel !== ''
            ? [['key' => 'open_setup', 'label' => $routeLabel, 'complete' => false, 'status' => 'pending']]
            : [];
    }

    /**
     * @param array<string,mixed> $route
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,mixed> $module
     * @param array<string,mixed> $journey
     * @return array<string,mixed>
     */
    private function primaryStep(string $moduleKey, array $route, array $steps, array $module, array $journey): array
    {
        $next = [];
        foreach ($steps as $step) {
            if (empty($step['complete'])) {
                $next = $step;
                break;
            }
        }
        if ($next === [] && $steps !== []) {
            $next = $steps[0];
        }

        $href = trim((string) ($route['href'] ?? $journey['setup_url'] ?? ''));
        if ($href === '' && $moduleKey !== '') {
            $href = (string) ($module['settings_schema']['settings_url'] ?? $module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php?module=' . rawurlencode($moduleKey));
        }

        $label = trim((string) ($next['label'] ?? $route['headline'] ?? 'Open setup'));

        return $this->stripEmptyAction([
            'key' => (string) ($next['key'] ?? 'open_setup'),
            'label' => $label !== '' ? $label : 'Open setup',
            'href' => $href,
            'cta_label' => (string) ($route['cta_label'] ?? 'Open setup'),
            'status_label' => (string) ($route['status_label'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $module
     */
    private function headline(array $route, array $module, bool $isCatalogFallback): string
    {
        if (!empty($route['headline'])) {
            return (string) $route['headline'];
        }
        if ($isCatalogFallback) {
            return 'Add only the capability that unblocks work';
        }

        $label = (string) ($module['label'] ?? 'Setup');
        return 'Set up ' . $this->plainModuleLabel((string) ($module['key'] ?? ''), $label);
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $module
     */
    private function reason(array $route, array $module, bool $isCatalogFallback): string
    {
        if (!empty($route['reason'])) {
            return (string) $route['reason'];
        }
        if ($isCatalogFallback) {
            return 'Start with the smallest useful capability instead of browsing everything at once.';
        }

        $summary = trim((string) ($module['summary'] ?? ''));
        return $summary !== '' ? $summary : 'Finish the setup path so this capability is ready for daily work.';
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $journey
     */
    private function statusLabel(array $route, array $journey): string
    {
        if (!empty($route['status_label'])) {
            return (string) $route['status_label'];
        }
        $progress = (array) ($journey['progress'] ?? []);
        $pending = (int) ($progress['pending'] ?? 0);
        return $pending > 0 ? 'Setup step needs attention' : 'Ready for daily work';
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $nextAction
     */
    private function shouldUseNextAction(array $route, string $moduleKey, string $gapKey, array $nextAction): bool
    {
        if ($moduleKey !== '' || $gapKey !== '' || empty($nextAction['is_actionable'])) {
            return false;
        }

        return trim((string) ($route['module_key'] ?? '')) === '';
    }

    /**
     * @param array<string,mixed> $nextAction
     * @return array<string,mixed>
     */
    private function routeForNextAction(array $nextAction): array
    {
        $skillKey = $this->normalizeKey((string) ($nextAction['skill_key'] ?? ''));
        if ($skillKey === '') {
            return [];
        }

        $label = trim((string) ($nextAction['label'] ?? ''));
        $kind = (string) ($nextAction['kind'] ?? '');
        $href = trim((string) ($nextAction['url'] ?? ''));
        $message = trim((string) ($nextAction['message'] ?? ''));
        if ($href === '') {
            $href = 'workspace_skills.php?module=' . rawurlencode($skillKey);
        }
        if ($label === '') {
            $moduleLabel = trim((string) ($nextAction['module_label'] ?? ''));
            $label = $kind === 'install'
                ? 'Install ' . ($moduleLabel !== '' ? $moduleLabel : ucwords(str_replace('_', ' ', $skillKey)))
                : 'Open setup';
        }

        return [
            'module_key' => $skillKey,
            'headline' => $label,
            'reason' => $message !== '' ? $message : 'Finish the setup path so this capability is ready for daily work.',
            'cta_label' => $label,
            'status_label' => $kind === 'finish_setup' ? 'Setup step needs attention' : 'Recommended capability',
            'href' => $href,
            'from_next_action' => true,
        ];
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function nextActionContext(array $action): array
    {
        $skillKey = $this->normalizeKey((string) ($action['skill_key'] ?? ''));
        if ($skillKey === '' || empty($action['is_actionable'])) {
            return [];
        }

        return [
            'skill_key' => $skillKey,
            'label' => trim((string) ($action['label'] ?? '')),
            'url' => trim((string) ($action['url'] ?? '')),
            'kind' => trim((string) ($action['kind'] ?? '')),
            'message' => trim((string) ($action['message'] ?? '')),
            'module_label' => trim((string) ($action['module_label'] ?? '')),
            'is_actionable' => true,
        ];
    }

    /**
     * @param array<string,mixed> $nextAction
     */
    private function nextActionCacheIdentity(array $nextAction): string
    {
        if (empty($nextAction['is_actionable'])) {
            return '-';
        }

        return substr(hash('sha1', implode('|', [
            (string) ($nextAction['skill_key'] ?? ''),
            (string) ($nextAction['kind'] ?? ''),
            (string) ($nextAction['label'] ?? ''),
            (string) ($nextAction['url'] ?? ''),
        ])), 0, 16);
    }

    private function plainModuleLabel(string $moduleKey, string $label): string
    {
        return match ($moduleKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => 'Connect email or WhatsApp',
            WorkspaceSkillCatalogService::PLUGIN_FINANCE => 'Set up invoices',
            WorkspaceSkillCatalogService::SKILL_AI_COACH => 'AI guidance',
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER => 'Marketing help',
            default => $label !== '' ? $label : ucwords(str_replace('_', ' ', $moduleKey)),
        };
    }

    private function plainStepLabel(string $moduleKey, string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        if (in_array($moduleKey, [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
        ], true)) {
            $lower = strtolower($label);
            if (str_contains($lower, 'email') || str_contains($lower, 'whatsapp') || str_contains($lower, 'channel')) {
                return 'Connect email or WhatsApp';
            }
        }

        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE) {
            $lower = strtolower($label);
            if (str_contains($lower, 'invoice') || str_contains($lower, 'payment') || str_contains($lower, 'bank')) {
                return 'Set up invoices and payment details';
            }
        }

        return str_replace(
            ['Marketplace', 'marketplace', 'automation battery', 'Automation battery', 'diagnostics', 'Diagnostics'],
            ['Add capabilities', 'Add capabilities', 'readiness', 'Readiness', 'setup checks', 'Setup checks'],
            $label
        );
    }

    private function plainRecommendationReason(string $reason, string $moduleKey): string
    {
        $reason = trim($reason);
        if (in_array($moduleKey, [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
        ], true)) {
            return 'Connect a customer channel before reply drafts can help.';
        }
        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE) {
            return 'Invoice setup unlocks quotes and payment follow-through.';
        }
        if ($reason === '') {
            return 'This is the most relevant capability for the current setup gap.';
        }

        return str_replace(
            ['Marketplace', 'marketplace', 'campaign', 'Campaign'],
            ['Add capabilities', 'Add capabilities', 'customer action', 'Customer action'],
            $reason
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stripEmptyAction(array $payload): array
    {
        foreach (['href', 'cta_label', 'status_label'] as $key) {
            if (array_key_exists($key, $payload) && trim((string) $payload[$key]) === '') {
                unset($payload[$key]);
            }
        }
        if (isset($payload['primary_step']) && is_array($payload['primary_step'])) {
            $payload['primary_step'] = $this->stripEmptyAction($payload['primary_step']);
        }

        return $payload;
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === UIExperienceService::MODE_ADVANCED
            ? UIExperienceService::MODE_ADVANCED
            : UIExperienceService::MODE_BEGINNER;
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $source) ?? ''));
        return in_array($source, ['dashboard_guidance', 'dashboard_readiness', 'marketplace', 'coach', 'direct', 'activation_bundle'], true)
            ? $source
            : 'direct';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }
}
