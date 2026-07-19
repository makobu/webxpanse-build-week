<?php

namespace CRM\Services;

class WorkspaceMarketplaceCatalogCopyService
{
    private AIService $aiService;

    /**
     * @var array<string,int>
     */
    private array $limits = [
        'label' => 80,
        'summary' => 280,
        'pitch' => 650,
        'thumbnail_alt' => 160,
        'banner_alt' => 160,
    ];

    public function __construct(?AIService $aiService = null)
    {
        $this->aiService = $aiService ?: new AIService();
    }

    public function draft(int $workspaceId, int $userId, array $module, array $currentFields = []): array
    {
        $base = $this->normalizeFields($module, $currentFields);
        $prompt = $this->buildPrompt($workspaceId, $userId, $module, $base);
        $draft = [];

        try {
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('marketplace_catalog', 'catalog_copy_optimise', [
                'surface' => 'marketplace_catalog',
                'prompt_key' => 'catalog_copy_optimise',
                'blocks' => [
                    ['key' => 'module', 'content' => $this->moduleContext($module)],
                    ['key' => 'current_fields', 'content' => $base],
                ],
            ], [
                'legacy_prompt' => $prompt,
                'module_key' => (string) ($module['key'] ?? ''),
            ]);
            $draft = $this->decodeDraft($this->aiService->processWithPrompt('marketplace_catalog_copy', $resolvedPrompt, ['fast_fallback' => true]));
        } catch (\Throwable $e) {
            $draft = [];
        }

        return $this->normalizeDraft(array_replace($this->fallbackDraft($module, $base), $draft), $base);
    }

    private function buildPrompt(int $workspaceId, int $userId, array $module, array $fields): string
    {
        return "You are Clarity, improving a CRM marketplace module page for an owner-facing marketplace.\n"
            . "Return ONLY valid compact JSON. No markdown, comments, or explanation.\n"
            . "Write practical, specific, conversion-oriented copy in heading case where headings are expected.\n"
            . "Keep claims conservative and grounded in the module context. Do not invent integrations, metrics, guarantees, pricing, or compliance claims.\n"
            . "Use short bullets, one idea per bullet, without bullet symbols.\n\n"
            . "OUTPUT JSON SHAPE:\n"
            . json_encode($this->emptyOutputShape(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "CONTEXT:\n" . json_encode([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'module' => $this->moduleContext($module),
                'current_fields' => $fields,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function moduleContext(array $module): array
    {
        $profile = (array) ($module['plugin_metadata']['marketplace_profile'] ?? []);

        return [
            'key' => (string) ($module['key'] ?? ''),
            'label' => (string) ($module['label'] ?? ''),
            'type' => (string) ($module['module_type'] ?? 'skill'),
            'category' => (string) ($module['category'] ?? ''),
            'summary' => (string) ($module['summary'] ?? ''),
            'capabilities' => array_keys(array_filter((array) ($module['capabilities'] ?? []))),
            'setup_steps' => array_values(array_filter(array_map('strval', (array) ($module['setup_steps'] ?? [])))),
            'profile' => [
                'pitch' => (string) ($profile['pitch'] ?? ''),
                'recommendations' => (array) ($profile['recommendations'] ?? []),
                'prerequisites' => (array) ($profile['prerequisites'] ?? []),
                'setup_guide' => (array) ($profile['setup_guide'] ?? []),
                'benefit_bullets' => (array) ($profile['benefit_bullets'] ?? []),
                'outcome_bullets' => (array) ($profile['outcome_bullets'] ?? []),
                'how_it_works_bullets' => (array) ($profile['how_it_works_bullets'] ?? []),
            ],
        ];
    }

    private function normalizeFields(array $module, array $currentFields): array
    {
        $profile = (array) ($module['plugin_metadata']['marketplace_profile'] ?? []);
        $fields = [
            'label' => (string) ($currentFields['label'] ?? $module['label'] ?? ''),
            'summary' => (string) ($currentFields['summary'] ?? $module['summary'] ?? ''),
            'pitch' => (string) ($currentFields['pitch'] ?? $profile['pitch'] ?? ''),
            'thumbnail_alt' => (string) ($currentFields['thumbnail_alt'] ?? $profile['thumbnail_alt'] ?? ''),
            'banner_alt' => (string) ($currentFields['banner_alt'] ?? $profile['banner_alt'] ?? ''),
            'overview_brief_format' => (string) ($currentFields['overview_brief_format'] ?? $profile['overview_brief_format'] ?? 'text'),
            'overview_brief_content' => (string) ($currentFields['overview_brief_content'] ?? $profile['overview_brief_content'] ?? ''),
            'overview_deep_dive_format' => (string) ($currentFields['overview_deep_dive_format'] ?? $profile['overview_deep_dive_format'] ?? 'text'),
            'overview_deep_dive_content' => (string) ($currentFields['overview_deep_dive_content'] ?? $profile['overview_deep_dive_content'] ?? ''),
            'benefit_bullets' => $this->cleanList((array) ($currentFields['benefit_bullets'] ?? $profile['benefit_bullets'] ?? [])),
            'outcome_bullets' => $this->cleanList((array) ($currentFields['outcome_bullets'] ?? $profile['outcome_bullets'] ?? [])),
            'how_it_works_bullets' => $this->cleanList((array) ($currentFields['how_it_works_bullets'] ?? $profile['how_it_works_bullets'] ?? [])),
            'recommendations' => $this->cleanList((array) ($currentFields['recommendations'] ?? $profile['recommendations'] ?? [])),
            'prerequisites' => $this->cleanList((array) ($currentFields['prerequisites'] ?? $profile['prerequisites'] ?? [])),
            'setup_guide' => $this->cleanList((array) ($currentFields['setup_guide'] ?? $profile['setup_guide'] ?? [])),
        ];

        $normalized = $this->normalizeDraft($fields, []);
        foreach (['benefit_bullets', 'outcome_bullets', 'how_it_works_bullets', 'recommendations', 'prerequisites', 'setup_guide'] as $field) {
            $normalized[$field] = $fields[$field] ?? [];
        }

        return $normalized;
    }

    private function fallbackDraft(array $module, array $fields): array
    {
        $label = (string) ($fields['label'] ?: $module['label'] ?? 'Marketplace Module');
        $category = (string) ($module['category'] ?? 'workspace');
        $capabilities = array_map(
            static fn(string $value): string => ucwords(str_replace('_', ' ', $value)),
            array_keys(array_filter((array) ($module['capabilities'] ?? [])))
        );
        $coreCapability = (string) ($capabilities[0] ?? 'workspace context');
        $briefContent = trim((string) ($fields['overview_brief_content'] ?? ''));
        $deepDiveContent = trim((string) ($fields['overview_deep_dive_content'] ?? ''));
        $bullets = static function (array $items): string {
            $clean = array_values(array_filter(array_map('strval', $items), static fn(string $item): bool => trim($item) !== ''));
            if ($clean === []) {
                return '';
            }

            return implode("\n", array_map(static fn(string $item): string => '- ' . trim($item), $clean));
        };
        $section = static function (string $title, array $items) use ($bullets): string {
            $body = $bullets($items);
            return $body !== '' ? $title . "\n" . $body : '';
        };

        return [
            'label' => trim($label),
            'summary' => (string) ($fields['summary'] ?: "{$label} helps teams add {$coreCapability} to the workspace without leaving the marketplace."),
            'pitch' => (string) ($fields['pitch'] ?: "{$label} gives owners a focused way to turn {$category} work into something the workspace can install, configure, and use from one place."),
            'thumbnail_alt' => (string) ($fields['thumbnail_alt'] ?: "{$label} marketplace thumbnail"),
            'banner_alt' => (string) ($fields['banner_alt'] ?: "{$label} marketplace banner"),
            'overview_brief_format' => (string) (($fields['overview_brief_format'] ?? 'text') === 'html' ? 'html' : 'text'),
            'overview_brief_content' => $briefContent !== ''
                ? $briefContent
                : trim(implode("\n\n", array_filter([
                    (string) ($fields['summary'] ?: "{$label} helps teams add {$coreCapability} to the workspace without leaving the marketplace."),
                    (string) ($fields['pitch'] ?: "{$label} gives owners a focused way to turn {$category} work into something the workspace can install, configure, and use from one place."),
                    $bullets((array) ($fields['benefit_bullets'] ?? [])),
                ], static fn(string $text): bool => trim($text) !== ''))),
            'overview_deep_dive_format' => (string) (($fields['overview_deep_dive_format'] ?? 'text') === 'html' ? 'html' : 'text'),
            'overview_deep_dive_content' => $deepDiveContent !== ''
                ? $deepDiveContent
                : trim(implode("\n\n", array_filter([
                    $section('What you need', (array) ($fields['prerequisites'] ?? [])),
                    $section('Setup steps', (array) ($fields['setup_guide'] ?? [])),
                    $section('Expected outcomes', (array) ($fields['outcome_bullets'] ?? [])),
                    $section('How it works', (array) ($fields['how_it_works_bullets'] ?? [])),
                    $section('Recommendations', (array) ($fields['recommendations'] ?? [])),
                ], static fn(string $text): bool => trim($text) !== ''))),
        ];
    }

    private function decodeDraft(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (preg_match('/\{.*\}/s', $raw, $matches) === 1) {
            $raw = $matches[0];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDraft(array $draft, array $fallback): array
    {
        $normalized = [];
        foreach ($this->limits as $field => $limit) {
            $value = trim(strip_tags((string) ($draft[$field] ?? $fallback[$field] ?? '')));
            $normalized[$field] = $this->limit($value, $limit);
        }
        foreach (['overview_brief', 'overview_deep_dive'] as $prefix) {
            $formatField = $prefix . '_format';
            $contentField = $prefix . '_content';
            $format = (string) ($draft[$formatField] ?? $fallback[$formatField] ?? 'text');
            $content = trim(str_replace("\0", '', (string) ($draft[$contentField] ?? $fallback[$contentField] ?? '')));
            $normalized[$formatField] = $format === 'html' ? 'html' : 'text';
            $normalized[$contentField] = $this->limit($content, 20000);
        }

        return $normalized;
    }

    private function cleanList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $clean = $this->limit(trim(strip_tags((string) $item)), 180);
            $clean = preg_replace('/^\s*[-*]\s*/', '', $clean) ?? $clean;
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    private function emptyOutputShape(): array
    {
        return [
            'label' => '',
            'summary' => '',
            'pitch' => '',
            'thumbnail_alt' => '',
            'banner_alt' => '',
            'overview_brief_format' => 'text',
            'overview_brief_content' => '',
            'overview_deep_dive_format' => 'text',
            'overview_deep_dive_content' => '',
        ];
    }

    private function limit(string $value, int $limit): string
    {
        if ($limit > 0 && mb_strlen($value) > $limit) {
            return rtrim(mb_substr($value, 0, $limit - 1)) . '...';
        }

        return $value;
    }
}
