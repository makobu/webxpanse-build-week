<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\OnboardingClarityDraftService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class OnboardingClarityDraftServiceTest extends DatabaseTestCase
{
    public function testDraftUsesSavedAndUnsavedContext(): void
    {
        $ai = new class extends AIService {
            public array $prompt = [];

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                $this->prompt = $inputs;
                return ['rendered_prompt' => (string) ($inputs['legacy_prompt'] ?? '')];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return 'Drafted field text from Clarity.';
            }
        };

        WorkspaceContext::activateRuntimeWorkspace(1, 1, 'owner');
        Database::execute(
            "UPDATE company_profile
             SET company_name = 'Saved Co',
                 company_industry = 'Consulting',
                 company_website = 'https://saved.example',
                 company_description = 'Saved description.',
                 updated_at = NOW()
             WHERE workspace_id = ? AND is_active = TRUE",
            [1]
        );

        $service = new OnboardingClarityDraftService($ai);
        $result = $service->draft(1, 1, 4, 'product_description', '', [
            'company_name' => 'Unsaved Co',
            'product_name' => 'Pipeline Sprint',
        ]);
        WorkspaceContext::clear();

        $this->assertSame('Drafted field text from Clarity.', $result['draft']);
        $this->assertSame('product_description', $result['field']);
        $this->assertStringContainsString('Unsaved Co', $ai->prompt['legacy_prompt']);
        $this->assertStringContainsString('Saved Co', $ai->prompt['legacy_prompt']);
        $this->assertStringContainsString('Pipeline Sprint', $ai->prompt['legacy_prompt']);
    }

    public function testUnsupportedFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new OnboardingClarityDraftService(new class extends AIService {}))->draft(1, 1, 1, 'not_a_field', '', []);
    }

    public function testEmptyContextReturnsLowConfidenceWithoutCallingAi(): void
    {
        $ai = new class extends AIService {
            public bool $called = false;

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                $this->called = true;
                return 'Should not be used';
            }
        };

        $result = (new OnboardingClarityDraftService($ai))->draft(1, 1, 1, 'company_description', '', []);

        $this->assertSame('low', $result['confidence']);
        $this->assertSame('', $result['draft']);
        $this->assertFalse($ai->called);
        $this->assertStringContainsString('Add a website', $result['source_notes']);
    }
}
