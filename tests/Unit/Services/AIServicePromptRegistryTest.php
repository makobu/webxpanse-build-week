<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIService;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Tests\DatabaseTestCase;

class AIServicePromptRegistryTest extends DatabaseTestCase
{
    public function testLegacyPromptReceivesResponseStyleInstructionFromBundle(): void
    {
        $bundle = [
            'surface' => 'unit_surface',
            'prompt_key' => 'unit_prompt',
            'blocks' => [[
                'type' => 'response_style_contract',
                'content' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_1'),
            ]],
        ];

        $resolved = (new AIService())->buildPromptFromRegistry('unit_surface', 'unit_prompt', $bundle, [
            'legacy_prompt' => 'Answer using the existing legacy prompt.',
        ]);

        $this->assertStringContainsString('Answer using the existing legacy prompt.', $resolved['rendered_prompt']);
        $this->assertStringContainsString('RESPONSE STYLE:', $resolved['rendered_prompt']);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 1', $resolved['rendered_prompt']);
        $this->assertStringContainsString('do not reduce nuance', $resolved['rendered_prompt']);
    }

    public function testRegistryPromptReceivesResponseStyleSectionFromBundle(): void
    {
        $bundle = [
            'surface' => 'unit_surface',
            'prompt_key' => 'unit_prompt',
            'blocks' => [[
                'type' => 'response_style_contract',
                'content' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_3'),
            ]],
        ];

        $resolved = (new AIService())->buildPromptFromRegistry('unit_surface', 'unit_prompt', $bundle);

        $this->assertStringContainsString('RESPONSE STYLE:', $resolved['rendered_prompt']);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 3', $resolved['rendered_prompt']);
        $this->assertStringContainsString('executive operating language', $resolved['rendered_prompt']);
    }

    public function testRegistryPromptInfersResponseStyleFromInputsWhenBundleHasNoBlock(): void
    {
        $bundle = [
            'surface' => 'unit_surface',
            'prompt_key' => 'unit_prompt',
            'blocks' => [[
                'type' => 'inputs',
                'content' => ['message' => 'Draft useful copy.'],
            ]],
        ];

        $resolved = (new AIService())->buildPromptFromRegistry('unit_surface', 'unit_prompt', $bundle, [
            'response_style_contract' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_1'),
        ]);

        $this->assertStringContainsString('RESPONSE STYLE:', $resolved['rendered_prompt']);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 1', $resolved['rendered_prompt']);
        $this->assertStringContainsString('plain terms', $resolved['rendered_prompt']);
    }

    public function testWebsiteAssistantDirectPromptUsesOperatingContextResponseStyle(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'buildWebsiteAssistantPrompt');
        $method->setAccessible(true);

        $prompt = (string) $method->invoke(new AIService(), [
            'question' => 'What should I do next?',
            'context' => ['summary' => ['open_deals' => 2]],
            'operating_context' => [
                'ai_settings' => [
                    'response_style_contract' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_3'),
                ],
            ],
        ]);

        $this->assertStringContainsString('LANGUAGE LEVEL: Level 3', $prompt);
        $this->assertStringContainsString('executive operating language', $prompt);
    }

    public function testTextPromptRequestsProseWithoutEchoingJsonContract(): void
    {
        $resolved = (new AIService())->buildPromptFromRegistry('clarity_chat', 'clarity_question_answer', [
            'surface' => 'clarity_chat',
            'prompt_key' => 'clarity_question_answer',
            'blocks' => [],
        ], ['question' => 'Why is my score high?']);

        $this->assertStringContainsString('PLAIN TEXT RESPONSE:', $resolved['rendered_prompt']);
        $this->assertStringNotContainsString('OUTPUT CONTRACT:', $resolved['rendered_prompt']);
        $this->assertStringNotContainsString('"type": "text"', $resolved['rendered_prompt']);
    }

    public function testClarityFinalAnswerContractComesLastAndSurvivesPromptTruncation(): void
    {
        $bundle = [
            'surface' => 'clarity_chat',
            'prompt_key' => 'clarity_question_answer',
            'response_style_contract' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_1'),
            'blocks' => [[
                'type' => 'workspace_context',
                'content' => ['payload' => str_repeat('large evidence ', 500)],
            ]],
        ];

        $resolved = (new AIService())->buildPromptFromRegistry('clarity_chat', 'clarity_question_answer', $bundle, [
            'question' => 'Why is my risk score high?',
            'max_prompt_chars' => 1200,
        ]);

        $prompt = (string) $resolved['rendered_prompt'];
        $this->assertLessThanOrEqual(1200, strlen($prompt));
        $this->assertStringContainsString('[Prompt content truncated]', $prompt);
        $this->assertStringContainsString('FINAL ANSWER CONTRACT:', $prompt);
        $this->assertStringContainsString('Start with the direct answer.', $prompt);
        $this->assertStringContainsString('one or two short sentences', $prompt);
        $this->assertGreaterThan(strpos($prompt, '[Prompt content truncated]'), strpos($prompt, 'FINAL ANSWER CONTRACT:'));
    }

    public function testPromptInputSanitizationRemovesDuplicatedContextPayloads(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'sanitizePromptInputs');
        $method->setAccessible(true);

        $sanitized = $method->invoke(new AIService(), [
            'question' => 'What should I do next?',
            'legacy_prompt' => 'duplicate legacy prompt',
            'operating_context' => ['private' => 'duplicate context'],
            'website_context' => ['private' => 'duplicate website context'],
            'response_style_contract' => ['level' => 'level_2'],
        ]);

        $this->assertSame(['question' => 'What should I do next?'], $sanitized);
    }

    public function testRenderedPromptHardLimitAddsTruncationMarker(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'limitPromptChars');
        $method->setAccessible(true);

        $limited = (string) $method->invoke(new AIService(), str_repeat('context ', 5000), 1200);

        $this->assertLessThanOrEqual(1200, strlen($limited));
        $this->assertStringEndsWith('[Prompt content truncated]', $limited);
    }
}
