<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceSkillInstallEmailReadinessTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::execute("DELETE FROM email_integrations WHERE workspace_id = 1 AND scope IN ('main_email', 'outreach_email', 'nurture_email', 'assistant_email')");
        Database::execute("DELETE FROM platform_email_defaults WHERE scope IN ('main_email', 'assistant_email')");
        Database::execute("DELETE FROM workspace_assistant_configs WHERE workspace_id = 1 AND assistant_type = 'email'");
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins(1, 1);
    }

    public function testEmailPluginIsNotReadyWhenOnlySystemMailExists(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'system@example.test',
            'smtp_host' => 'smtp.system.test',
            'smtp_username' => 'system@example.test',
            'smtp_password' => 'system-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.system.test',
            'imap_username' => 'system@example.test',
            'imap_password' => 'imap-secret',
        ], 1);

        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, 1, WorkspaceSkillCatalogService::PLUGIN_EMAIL);

        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['outbound_ready'] ?? true));
        $this->assertSame('needs_setup', (string) ($readiness['status'] ?? ''));
        $this->assertContains('At least one email identity ready', (array) ($readiness['blockers'] ?? []));
    }

    public function testEmailPluginReportsSendingReadyWhenOutreachHasOnlyOutboundSmtp(): void
    {
        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.outreach.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'outreach-secret',
            'imap_enabled' => false,
        ], 1);

        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, 1, WorkspaceSkillCatalogService::PLUGIN_EMAIL);

        $this->assertTrue((bool) ($readiness['ready'] ?? false));
        $this->assertTrue((bool) ($readiness['outbound_ready'] ?? false));
        $this->assertFalse((bool) ($readiness['inbound_ready'] ?? true));
        $this->assertSame('sending_ready', (string) ($readiness['status'] ?? ''));
        $this->assertStringContainsString('Email sending is ready', (string) ($readiness['message'] ?? ''));
    }

    public function testEmailAssistantMailboxDoesNotMakeEmailPluginReady(): void
    {
        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'from_email' => 'assistant@example.test',
            'smtp_host' => 'smtp.assistant.test',
            'smtp_username' => 'assistant@example.test',
            'smtp_password' => 'assistant-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.assistant.test',
            'imap_username' => 'assistant@example.test',
            'imap_password' => 'assistant-imap-secret',
        ], true, 1);

        $readiness = (new WorkspaceSkillInstallService())->buildReadinessForModule(1, 1, WorkspaceSkillCatalogService::PLUGIN_EMAIL);

        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['outbound_ready'] ?? true));
        $this->assertSame('needs_setup', (string) ($readiness['status'] ?? ''));
    }
}
