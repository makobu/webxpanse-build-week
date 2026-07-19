<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIService;
use PHPUnit\Framework\TestCase;

class AIServiceResponsesTest extends TestCase
{
    public function testResponsesUrlReplacesChatCompletionsEndpoint(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'responsesApiUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://api.openai.com/v1/responses',
            $method->invoke(new AIService(), 'https://api.openai.com/v1/chat/completions')
        );
    }

    public function testReasoningSupportTargetsReasoningModelsOnly(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'supportsReasoningEffort');
        $method->setAccessible(true);
        $service = new AIService();

        $this->assertTrue($method->invoke($service, 'gpt-5.4-mini'));
        $this->assertTrue($method->invoke($service, 'o3-mini'));
        $this->assertFalse($method->invoke($service, 'gpt-4o-mini'));
    }

    public function testResponsesOutputExtractsNestedOutputText(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'responsesOutputText');
        $method->setAccessible(true);

        $output = $method->invoke(new AIService(), [
            'output' => [[
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Your score is high because task completion is low.'],
                ],
            ]],
        ]);

        $this->assertSame('Your score is high because task completion is low.', $output);
    }

    public function testMediumReasoningReservesVisibleAnswerBudget(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'responsesMaxOutputTokens');
        $method->setAccessible(true);

        $this->assertSame(600, $method->invoke(new AIService(), 'medium', 100));
        $this->assertSame(1800, $method->invoke(new AIService(), 'medium', 1800));
    }

    public function testChatFallbackUsesCompletionTokenFieldForReasoningModel(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'chatCompletionsPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(new AIService(), 'gpt-5.4-mini', 'system', 'question', ['max_tokens' => 900]);

        $this->assertSame(900, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testStructuredOutputSchemaIsAppliedToChatFallback(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'chatCompletionsPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(new AIService(), 'gpt-4o-mini', 'system', 'question', [
            'task' => 'social_post_variants',
            'output_contract' => [
                'type' => 'json_schema',
                'name' => 'social_post_variants',
                'schema' => [
                    'type' => 'object',
                    'properties' => ['linkedin' => ['type' => 'string']],
                    'required' => ['linkedin'],
                    'additionalProperties' => false,
                ],
            ],
        ]);

        $this->assertSame('json_schema', (string) ($payload['response_format']['type'] ?? ''));
        $this->assertSame('social_post_variants', (string) ($payload['response_format']['json_schema']['name'] ?? ''));
        $this->assertTrue((bool) ($payload['response_format']['json_schema']['strict'] ?? false));
        $this->assertSame(['linkedin'], (array) ($payload['response_format']['json_schema']['schema']['required'] ?? []));
    }

    public function testJsonSchemaContractRejectsMissingRequiredChannel(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'validateOutputContract');
        $method->setAccessible(true);
        $result = $method->invoke(new AIService(), '{"facebook":"Hello"}', [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'facebook' => ['type' => 'string'],
                    'linkedin' => ['type' => 'string'],
                ],
                'required' => ['facebook', 'linkedin'],
                'additionalProperties' => false,
            ],
        ]);

        $this->assertFalse((bool) ($result['valid'] ?? true));
        $this->assertStringContainsString('linkedin', (string) ($result['message'] ?? ''));
    }
}
