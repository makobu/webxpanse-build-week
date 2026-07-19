<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIService;
use CRM\Services\WorkspaceMarketplaceCatalogCopyService;
use PHPUnit\Framework\TestCase;

class WorkspaceMarketplaceCatalogCopyServiceTest extends TestCase
{
    public function testDraftOutputOmitsLegacyMarketplaceOverviewFields(): void
    {
        $ai = new class extends AIService {
            public function __construct()
            {
            }

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'rendered_prompt' => 'Prompt',
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'label' => 'Sharper Email Assistant',
                    'summary' => 'A concise summary for the catalog.',
                    'pitch' => 'A practical owner-facing pitch.',
                    'thumbnail_alt' => 'Email assistant thumbnail',
                    'banner_alt' => 'Email assistant banner',
                    'overview_headline' => 'Legacy headline should be dropped',
                    'overview_intro' => 'Legacy intro should be dropped',
                    'overview_brief_image_url' => 'https://example.test/legacy.webp',
                    'overview_brief_image_alt' => 'Legacy brief image',
                    'overview_deep_dive_image_url' => 'https://example.test/legacy-deep.webp',
                    'overview_deep_dive_image_alt' => 'Legacy deep image',
                    'overview_brief_format' => 'html',
                    'overview_brief_content' => '<p>Brief two-section content.</p>',
                    'overview_deep_dive_format' => 'html',
                    'overview_deep_dive_content' => '<p>Deep dive two-section content.</p>',
                    'benefit_bullets' => ['Should not be emitted'],
                    'recommendations' => ['Should not be emitted'],
                    'setup_guide' => ['Should not be emitted'],
                ], JSON_UNESCAPED_SLASHES) ?: '{}';
            }
        };

        $service = new WorkspaceMarketplaceCatalogCopyService($ai);
        $draft = $service->draft(12, 34, [
            'key' => 'email_assistant',
            'label' => 'Email Assistant',
            'module_type' => 'plugin',
            'category' => 'communication',
            'summary' => 'Current summary.',
            'capabilities' => ['email_assistant' => true],
            'setup_steps' => ['Connect inbox'],
            'plugin_metadata' => [
                'marketplace_profile' => [
                    'pitch' => 'Current pitch.',
                    'overview_headline' => 'Old default headline',
                    'overview_intro' => 'Old default intro',
                    'benefit_bullets' => ['Digest repetitive messages'],
                    'setup_guide' => ['Connect inbox'],
                ],
            ],
        ]);

        $this->assertSame([
            'label',
            'summary',
            'pitch',
            'thumbnail_alt',
            'banner_alt',
            'overview_brief_format',
            'overview_brief_content',
            'overview_deep_dive_format',
            'overview_deep_dive_content',
        ], array_keys($draft));
        $this->assertSame('Sharper Email Assistant', $draft['label']);
        $this->assertSame('html', $draft['overview_brief_format']);
        $this->assertSame('<p>Brief two-section content.</p>', $draft['overview_brief_content']);
        $this->assertSame('html', $draft['overview_deep_dive_format']);
        $this->assertSame('<p>Deep dive two-section content.</p>', $draft['overview_deep_dive_content']);

        foreach ([
            'overview_headline',
            'overview_intro',
            'overview_brief_image_url',
            'overview_brief_image_alt',
            'overview_deep_dive_image_url',
            'overview_deep_dive_image_alt',
            'benefit_bullets',
            'recommendations',
            'setup_guide',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $draft);
        }
    }
}
