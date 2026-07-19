<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIRuntimeConfig;
use CRM\Services\EmailAssistantConfig;
use PHPUnit\Framework\TestCase;

class AIRuntimeConfigTest extends TestCase
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

    public function testValidateReportsFallbackOnlyWhenCoreProviderMissing(): void
    {
        unset($_ENV['AI_API_KEY'], $_ENV['AI_SERVICE_URL'], $_ENV['AI_MODEL']);
        $_ENV['EMAIL_ASSISTANT_IMAP_ENABLED'] = 'false';
        unset($_ENV['EMAIL_ASSISTANT_SMTP_HOST'], $_ENV['EMAIL_ASSISTANT_SMTP_USER'], $_ENV['EMAIL_ASSISTANT_SMTP_PASS'], $_ENV['EMAIL_ASSISTANT_FROM_EMAIL']);

        $result = AIRuntimeConfig::validate();

        $this->assertFalse($result['core_ai_provider_ready']);
        $this->assertTrue($result['fallback_only_mode']);
        $this->assertSame(['AI_API_KEY', 'AI_SERVICE_URL'], $result['provider']['missing']);
    }

    public function testValidateNormalizesOpenAiUrlAndDelegatesEmailAssistantConfig(): void
    {
        $_ENV['AI_API_KEY'] = 'sk-test';
        $_ENV['AI_SERVICE_URL'] = 'https://api.openai.com/v1';
        $_ENV['AI_MODEL'] = 'gpt-4o-mini';
        $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] = 'smtp.example.com';
        $_ENV['EMAIL_ASSISTANT_SMTP_USER'] = 'assistant@example.com';
        $_ENV['EMAIL_ASSISTANT_SMTP_PASS'] = 'secret';
        $_ENV['EMAIL_ASSISTANT_FROM_EMAIL'] = 'assistant@example.com';
        $_ENV['EMAIL_ASSISTANT_IMAP_ENABLED'] = 'false';

        $result = AIRuntimeConfig::validate();

        $this->assertTrue($result['core_ai_provider_ready']);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $result['provider']['normalized_api_url']);
        $this->assertSame('https://api.openai.com/v1/models', $result['provider']['normalized_probe_url']);
        $this->assertSame('gpt-4o-mini', $result['provider']['model']);
        $this->assertSame($result['email_assistant'], EmailAssistantConfig::validate());
    }
}
