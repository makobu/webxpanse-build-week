<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailAssistantRuntimeConfig;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantRuntimeConfigTest extends DatabaseTestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
        WorkspaceContext::activateRuntimeWorkspace(1);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function testWorkspaceConfigOverridesLegacyEnvFlags(): void
    {
        $_ENV['EMAIL_ASSISTANT_Q&A_ENABLED'] = 'true';
        $_ENV['EMAIL_ASSISTANT_INSTRUCTIONS_ENABLED'] = 'true';
        $_ENV['EMAIL_ASSISTANT_CUSTOMER_SEND_ENABLED'] = 'true';
        $_ENV['EMAIL_ASSISTANT_ALLOWED_SENDERS'] = 'legacy@example.test';

        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'qa_enabled' => false,
            'instructions_enabled' => false,
            'customer_thread_enabled' => true,
            'customer_send_enabled' => false,
            'allowed_senders' => 'workspace@example.test',
            'min_confidence' => '0.77',
            'min_send_confidence' => '0.88',
        ], true, 0);

        $config = (new EmailAssistantRuntimeConfig())->forWorkspace(1);

        $this->assertTrue($config['has_workspace_config']);
        $this->assertFalse($config['qa_enabled']);
        $this->assertFalse($config['instructions_enabled']);
        $this->assertTrue($config['customer_thread_enabled']);
        $this->assertFalse($config['customer_send_enabled']);
        $this->assertSame(['workspace@example.test'], $config['allowed_senders']);
        $this->assertSame(0.77, $config['min_confidence']);
        $this->assertSame(0.88, $config['min_send_confidence']);
    }

    public function testAllowedSenderAcceptsExactEmailsAndDomains(): void
    {
        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'allowed_senders' => "owner@example.test\ntrusted.test",
        ], true, 0);

        $config = new EmailAssistantRuntimeConfig();

        $this->assertTrue($config->isSenderAllowed('owner@example.test', 1));
        $this->assertTrue($config->isSenderAllowed('person@trusted.test', 1));
        $this->assertFalse($config->isSenderAllowed('person@other.test', 1));
    }

    public function testWhatsappActionSettingsAreIndependentFromEmailSettings(): void
    {
        $configs = new WorkspaceAssistantConfigService();
        $configs->save(1, 'email', [
            'instructions_enabled' => false,
            'skill_create_contact' => false,
        ], true, 0);
        $configs->save(1, 'whatsapp', [
            'instructions_enabled' => true,
            'skill_create_contact' => true,
        ], true, 0);

        $email = new EmailAssistantRuntimeConfig('email');
        $whatsapp = new EmailAssistantRuntimeConfig('whatsapp');
        $emailRuntime = $email->forWorkspace(1);
        $whatsappRuntime = $whatsapp->forWorkspace(1);

        $this->assertSame('email', $emailRuntime['assistant_type']);
        $this->assertSame('whatsapp', $whatsappRuntime['assistant_type']);
        $this->assertFalse($email->actionEnabled($emailRuntime, 'skill_create_contact', 'EMAIL_ASSISTANT_SKILL_CREATE_CONTACT', false));
        $this->assertTrue($whatsapp->actionEnabled($whatsappRuntime, 'skill_create_contact', 'EMAIL_ASSISTANT_SKILL_CREATE_CONTACT', false));
        $this->assertFalse($emailRuntime['instructions_enabled']);
        $this->assertTrue($whatsappRuntime['instructions_enabled']);
    }

    public function testEmailActionSettingsAreIndependentFromWhatsappSettings(): void
    {
        $configs = new WorkspaceAssistantConfigService();
        $configs->save(1, 'email', [
            'instructions_enabled' => true,
            'skill_run_report' => true,
        ], true, 0);
        $configs->save(1, 'whatsapp', [
            'instructions_enabled' => false,
            'skill_run_report' => false,
        ], true, 0);

        $email = new EmailAssistantRuntimeConfig('email');
        $whatsapp = new EmailAssistantRuntimeConfig('whatsapp');
        $emailRuntime = $email->forWorkspace(1);
        $whatsappRuntime = $whatsapp->forWorkspace(1);

        $this->assertTrue($email->actionEnabled($emailRuntime, 'skill_run_report', 'EMAIL_ASSISTANT_SKILL_RUN_REPORT', false));
        $this->assertFalse($whatsapp->actionEnabled($whatsappRuntime, 'skill_run_report', 'EMAIL_ASSISTANT_SKILL_RUN_REPORT', false));
        $this->assertTrue($emailRuntime['instructions_enabled']);
        $this->assertFalse($whatsappRuntime['instructions_enabled']);
    }
}
