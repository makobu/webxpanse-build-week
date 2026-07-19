<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIProviderProbeService;
use PHPUnit\Framework\TestCase;

class AIProviderProbeServiceTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    public function testProbeWithoutLiveCallReturnsConfigurationReadiness(): void
    {
        $_ENV['AI_API_KEY'] = 'sk-test';
        $_ENV['AI_SERVICE_URL'] = 'https://api.openai.com/v1';
        $_ENV['AI_MODEL'] = 'gpt-4o-mini';
        $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] = 'smtp.example.com';
        $_ENV['EMAIL_ASSISTANT_SMTP_USER'] = 'assistant@example.com';
        $_ENV['EMAIL_ASSISTANT_SMTP_PASS'] = 'secret';
        $_ENV['EMAIL_ASSISTANT_FROM_EMAIL'] = 'assistant@example.com';
        $_ENV['EMAIL_ASSISTANT_IMAP_ENABLED'] = 'false';

        $probe = (new AIProviderProbeService())->probe(false);

        $this->assertTrue($probe['success']);
        $this->assertFalse($probe['live_probe_attempted']);
        $this->assertTrue($probe['core_ai_provider_ready']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $probe['provider']['normalized_api_url']);
    }

    public function testProbeWithMissingConfigReturnsActionableMessage(): void
    {
        unset($_ENV['AI_API_KEY'], $_ENV['AI_SERVICE_URL']);

        $probe = (new AIProviderProbeService())->probe(true);

        $this->assertFalse($probe['success']);
        $this->assertTrue($probe['live_probe_attempted']);
        $this->assertStringContainsString('AI_API_KEY', $probe['message']);
    }
}
