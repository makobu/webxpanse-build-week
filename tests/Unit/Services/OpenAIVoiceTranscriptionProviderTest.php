<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\OpenAIVoiceTranscriptionProvider;
use CRM\Services\WorkspaceAIProviderConfigService;
use PHPUnit\Framework\TestCase;

class OpenAIVoiceTranscriptionProviderTest extends TestCase
{
    private string $audioPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vcc_audio_test_');
        if ($path === false) {
            $this->fail('Could not create a temporary audio fixture.');
        }
        $this->audioPath = $path;
        file_put_contents($this->audioPath, 'test-audio-bytes');
    }

    protected function tearDown(): void
    {
        if (isset($this->audioPath) && is_file($this->audioPath)) {
            @unlink($this->audioPath);
        }
    }

    public function testDefaultGpt4oTranscriptionUsesSupportedJsonResponse(): void
    {
        $fields = [];
        $provider = new OpenAIVoiceTranscriptionProvider(
            $this->settingsConfig(),
            static function (string $endpoint, array $requestFields, string $apiKey) use (&$fields): array {
                $fields = $requestFields;
                return ['text' => 'Customer requested a follow-up.', 'language' => 'en'];
            }
        );

        $result = $provider->transcribe(1, $this->audioPath, 'gpt-4o-mini-transcribe');
        $this->assertSame('Customer requested a follow-up.', $result['text']);
        $this->assertSame('json', $fields['response_format']);
        $this->assertArrayNotHasKey('chunking_strategy', $fields);
    }

    public function testDiarizedTranscriptionUsesSpeakerFormatAndAutomaticChunking(): void
    {
        $fields = [];
        $provider = new OpenAIVoiceTranscriptionProvider(
            $this->settingsConfig(),
            static function (string $endpoint, array $requestFields, string $apiKey) use (&$fields): array {
                $fields = $requestFields;
                return ['text' => 'Agent: Hello. Customer: Hi.', 'segments' => [['speaker' => 'agent', 'text' => 'Hello.']]];
            }
        );

        $result = $provider->transcribe(1, $this->audioPath, 'gpt-4o-transcribe-diarize');
        $this->assertSame('diarized_json', $fields['response_format']);
        $this->assertSame('auto', $fields['chunking_strategy']);
        $this->assertSame('agent', $result['segments'][0]['speaker']);
    }

    public function testSupportedModelValidationFailsClosed(): void
    {
        $provider = new OpenAIVoiceTranscriptionProvider($this->settingsConfig(), static fn(): array => []);
        $this->assertTrue($provider->supportsModel('gpt-4o-mini-transcribe'));
        $this->assertTrue($provider->supportsModel('gpt-4o-transcribe-diarize'));
        $this->assertFalse($provider->supportsModel('gpt-4o-mini'));
    }

    private function settingsConfig(): WorkspaceAIProviderConfigService
    {
        $config = $this->createMock(WorkspaceAIProviderConfigService::class);
        $config->method('get')->willReturn([
            'enabled' => true,
            'api_key' => 'settings-owned-key',
            'provider_key' => 'openai',
        ]);
        return $config;
    }
}
