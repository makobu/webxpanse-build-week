<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\WorkspaceChannelHealthService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceChannelHealthServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearChannelEnv();
    }

    protected function tearDown(): void
    {
        $this->clearChannelEnv();
        WorkspaceContext::clear();
        parent::tearDown();
    }

    private function clearChannelEnv(): void
    {
        unset(
            $_ENV['SMTP_HOST'],
            $_ENV['SMTP_USER'],
            $_ENV['SMTP_PASS'],
            $_ENV['IMAP_ENABLED'],
            $_ENV['IMAP_HOST'],
            $_ENV['IMAP_USER'],
            $_ENV['IMAP_PASS'],
            $_ENV['GMAIL_MAIL_CLIENT_ID'],
            $_ENV['GMAIL_MAIL_CLIENT_SECRET'],
            $_ENV['META_APP_ID'],
            $_ENV['META_APP_SECRET'],
            $_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'],
            $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'],
            $_ENV['WHATSAPP_VERIFY_TOKEN'],
            $_ENV['TWILIO_ACCOUNT_SID'],
            $_ENV['TWILIO_AUTH_TOKEN'],
            $_ENV['TWILIO_FROM_NUMBER']
        );
    }

    public function testMainEmailReportsSmtpConnectedImapMissing(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'sales@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'sales@example.test',
            'smtp_password' => 'secret',
            'imap_enabled' => false,
        ], 1);

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'admin']);
        WorkspaceContext::clear();

        $this->assertSame('Sending ready, inbox not connected', $channels['main_email']['label']);
        $this->assertContains('SMTP ready', $channels['main_email']['badges']);
        $this->assertContains('IMAP missing', $channels['main_email']['badges']);
        $this->assertTrue((bool) ($channels['main_email']['outbound_ready'] ?? false));
        $this->assertFalse((bool) ($channels['main_email']['inbound_ready'] ?? true));
    }

    public function testWorkspaceManualEmailIsReadyWithoutOauthPlatformSetup(): void
    {
        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.workspace.test',
            'imap_username' => 'outreach@example.test',
            'imap_password' => 'imap-secret',
        ], 1);

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('ready', $channels['outreach_email']['status']);
        $this->assertSame('SMTP and IMAP connected', $channels['outreach_email']['label']);
        $this->assertTrue((bool) ($channels['outreach_email']['outbound_ready'] ?? false));
        $this->assertTrue((bool) ($channels['outreach_email']['inbound_ready'] ?? false));
        $this->assertNotContains('Ask Super Admin to configure Google OAuth credentials.', $channels['outreach_email']['actions']);
        $this->assertNotContains('OAuth app missing', $channels['outreach_email']['badges']);
    }

    public function testWorkspaceManualEmailWithOnlySmtpReportsSendingReadyWarning(): void
    {
        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => false,
        ], 1);

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('warning', $channels['outreach_email']['status']);
        $this->assertSame('Sending ready, inbox not connected', $channels['outreach_email']['label']);
        $this->assertTrue((bool) ($channels['outreach_email']['outbound_ready'] ?? false));
        $this->assertFalse((bool) ($channels['outreach_email']['inbound_ready'] ?? true));
    }

    public function testWorkspaceManualEmailDoesNotFallBackToEnvWhenPluginConfigExists(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'platform@example.test',
            'smtp_host' => 'smtp.platform.test',
            'smtp_username' => 'platform@example.test',
            'smtp_password' => 'platform-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.platform.test',
            'imap_username' => 'platform@example.test',
            'imap_password' => 'platform-imap-secret',
        ], 1);

        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => '',
            'smtp_password' => '',
            'imap_enabled' => false,
        ], 1);

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('not_connected', $channels['outreach_email']['status']);
        $this->assertSame('Email not connected', $channels['outreach_email']['label']);
        $this->assertContains('Finish SMTP setup.', $channels['outreach_email']['actions']);
    }

    public function testAssistantGmailAndWhatsappHealthBadges(): void
    {
        $_ENV['GMAIL_MAIL_CLIENT_ID'] = 'gmail-client-id';
        $_ENV['GMAIL_MAIL_CLIENT_SECRET'] = 'gmail-client-secret';
        $_ENV['META_APP_ID'] = 'meta-app';
        $_ENV['META_APP_SECRET'] = 'meta-secret';
        $_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] = 'meta-config';
        $_ENV['WHATSAPP_VERIFY_TOKEN'] = 'verify-token';

        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, access_token, refresh_token, token_expires_at, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'gmail_oauth', 'assistant_email', 'assistant-token', 'assistant-refresh', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'assistant@gmail.com', 1, NULL, '{}', NOW(), NOW())"
        );
        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, phone_number_id, display_phone_number, access_token, connection_status, webhook_token, webhook_verify_token, connected_at)
             VALUES (1, NULL, '123456789', '+254 700 000 000', 'token', 'connected', 'workspace-webhook-token', 'workspace-verify-token', NOW())
             ON DUPLICATE KEY UPDATE display_phone_number = VALUES(display_phone_number), connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'admin']);
        WorkspaceContext::clear();

        $this->assertSame('Assistant Gmail connected', $channels['assistant_email']['label']);
        $this->assertContains('Assistant Gmail connected', $channels['assistant_email']['badges']);
        $this->assertSame('WhatsApp number connected', $channels['whatsapp']['label']);
        $this->assertContains('Number connected', $channels['whatsapp']['badges']);
    }

    public function testManualWhatsAppCredentialsAreReadyWithoutEmbeddedSignupEnv(): void
    {
        $_ENV['WHATSAPP_VERIFY_TOKEN'] = 'verify-token';

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, phone_number_id, display_phone_number, access_token, connection_status, webhook_token, webhook_verify_token, connected_at)
             VALUES (1, NULL, '987654321', '+254 700 987 654', 'manual-token', 'connected', 'workspace-webhook-token', 'workspace-verify-token', NOW())
             ON DUPLICATE KEY UPDATE phone_number_id = VALUES(phone_number_id), access_token = VALUES(access_token), connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('ready', $channels['whatsapp']['status']);
        $this->assertSame('WhatsApp number connected', $channels['whatsapp']['label']);
        $this->assertTrue((bool) ($channels['whatsapp']['outbound_ready'] ?? false));
        $this->assertTrue((bool) ($channels['whatsapp']['inbound_ready'] ?? false));
        $this->assertFalse((bool) ($channels['whatsapp']['embedded_signup_configured'] ?? true));
    }

    public function testSmsHealthUsesWorkspaceConfig(): void
    {
        (new WorkspaceSmsChannelConfigService())->save(1, [
            'enabled' => true,
            'account_sid' => 'ACsmshealth',
            'auth_token' => 'sms-health-token',
            'from_number' => '+15550000001',
        ]);

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('ready', $channels['sms']['status']);
        $this->assertSame('SMS sender ready', $channels['sms']['label']);
        $this->assertContains('Twilio account SID saved', $channels['sms']['badges']);
        $this->assertTrue((bool) ($channels['sms']['outbound_ready'] ?? false));
    }

    public function testWhatsappVerifyTokenMarksInboundReadyWithoutOutboundCredentials(): void
    {
        $_ENV['WHATSAPP_VERIFY_TOKEN'] = 'verify-token';

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, connection_status, webhook_token, webhook_verify_token, connected_at)
             VALUES (1, NULL, 'needs_attention', 'workspace-webhook-token', 'workspace-verify-token', NOW())
             ON DUPLICATE KEY UPDATE connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('warning', $channels['whatsapp']['status']);
        $this->assertFalse((bool) ($channels['whatsapp']['outbound_ready'] ?? true));
        $this->assertTrue((bool) ($channels['whatsapp']['inbound_ready'] ?? false));
        $this->assertNotSame('ready', $channels['whatsapp']['status']);
    }

    public function testMissingEmbeddedSignupEnvDoesNotDisableSavedManualWhatsApp(): void
    {
        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, phone_number_id, display_phone_number, access_token, connection_status, connected_at)
             VALUES (1, NULL, '123123123', '+254 700 123 123', 'manual-token', 'connected', NOW())
             ON DUPLICATE KEY UPDATE phone_number_id = VALUES(phone_number_id), access_token = VALUES(access_token), connection_status = VALUES(connection_status)"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $channels = (new WorkspaceChannelHealthService())->summarize(1, ['id' => 1, 'role' => 'owner']);
        WorkspaceContext::clear();

        $this->assertSame('warning', $channels['whatsapp']['status']);
        $this->assertSame('Sending ready, webhook needs setup', $channels['whatsapp']['label']);
        $this->assertTrue((bool) ($channels['whatsapp']['outbound_ready'] ?? false));
        $this->assertFalse((bool) ($channels['whatsapp']['inbound_ready'] ?? true));
        $this->assertNotSame('disabled', $channels['whatsapp']['status']);
    }
}
