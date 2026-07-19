<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\DemoWorkspaceService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceCommunicationGateServiceTest extends DatabaseTestCase
{
    public function testRuntimeIsLockedWhenEmailAndWhatsappAreNotReady(): void
    {
        $this->withEnv([
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ], function (): void {
            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertFalse((bool) ($status['ready'] ?? true));
            $this->assertTrue((bool) ($status['locked'] ?? false));
            $this->assertFalse((bool) ($status['email_ready'] ?? true));
            $this->assertFalse((bool) ($status['whatsapp_ready'] ?? true));
            $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL, (string) ($status['setup_skill_key'] ?? ''));
        });
    }

    public function testRuntimeStaysLockedWhenOnlySmtpIsReady(): void
    {
        $this->withEnv([
            'SMTP_HOST' => 'smtp.example.test',
            'SMTP_USER' => 'sender@example.test',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ], function (): void {
            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertFalse((bool) ($status['ready'] ?? true));
            $this->assertTrue((bool) ($status['locked'] ?? false));
            $this->assertFalse((bool) ($status['email_ready'] ?? true));
        });
    }

    public function testRuntimeStaysLockedWhenOnlyWhatsAppWebhookIsReady(): void
    {
        $this->withEnv([
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'SMTP_PASS' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
            'WHATSAPP_VERIFY_TOKEN' => 'verify-token',
        ], function (): void {
            Database::execute(
                "INSERT INTO workspace_whatsapp_integrations
                    (workspace_id, connected_by_user_id, connection_status, webhook_token, webhook_verify_token, connected_at)
                 VALUES (1, NULL, 'needs_attention', 'workspace-webhook-token', 'workspace-verify-token', NOW())
                 ON DUPLICATE KEY UPDATE connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
            );

            $status = (new WorkspaceCommunicationGateService())->status(1, []);
            $whatsapp = (array) ($status['channels']['whatsapp'] ?? []);

            $this->assertFalse((bool) ($status['ready'] ?? true));
            $this->assertTrue((bool) ($status['locked'] ?? false));
            $this->assertFalse((bool) ($status['whatsapp_ready'] ?? true));
            $this->assertFalse((bool) ($whatsapp['outbound_ready'] ?? true));
            $this->assertTrue((bool) ($whatsapp['inbound_ready'] ?? false));
        });
    }

    public function testRuntimeStaysLockedWhenOnlySystemMailIsReady(): void
    {
        $this->withEnv([
            'SMTP_HOST' => 'smtp.env.test',
            'SMTP_USER' => 'env@example.test',
            'SMTP_PASS' => 'env-secret',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ], function (): void {
            (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
                'from_email' => 'sender@example.test',
                'smtp_host' => 'smtp.example.test',
                'smtp_username' => 'sender@example.test',
                'smtp_password' => 'smtp-secret',
                'imap_enabled' => true,
                'imap_host' => 'imap.example.test',
                'imap_username' => 'sender@example.test',
                'imap_password' => 'imap-secret',
            ], 1);

            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertFalse((bool) ($status['ready'] ?? true));
            $this->assertTrue((bool) ($status['locked'] ?? false));
            $this->assertFalse((bool) ($status['email_ready'] ?? true));
            $this->assertTrue((bool) ($status['main_email_ready'] ?? false));
        });
    }

    public function testRuntimeUnlocksFromWorkspacePluginEmailConfigWithoutEnv(): void
    {
        $this->withEnv([
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'SMTP_PASS' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ], function (): void {
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

            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertTrue((bool) ($status['ready'] ?? false));
            $this->assertFalse((bool) ($status['locked'] ?? true));
            $this->assertTrue((bool) ($status['email_ready'] ?? false));
            $this->assertTrue((bool) ($status['outreach_email_ready'] ?? false));
        });
    }

    public function testAssistantEmailReadyDoesNotUnlockCustomerCommunicationRuntime(): void
    {
        $this->withEnv([
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'SMTP_PASS' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
        ], function (): void {
            (new WorkspaceAssistantConfigService())->save(1, 'email', [
                'from_email' => 'assistant@example.test',
                'from_name' => 'Assistant',
                'smtp_host' => 'smtp.assistant.test',
                'smtp_username' => 'assistant@example.test',
                'smtp_password' => 'smtp-secret',
                'imap_enabled' => true,
                'imap_host' => 'imap.assistant.test',
                'imap_username' => 'assistant@example.test',
                'imap_password' => 'imap-secret',
            ], true, 1);

            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertTrue((bool) ($status['assistant_email_ready'] ?? false));
            $this->assertFalse((bool) ($status['ready'] ?? true));
            $this->assertTrue((bool) ($status['locked'] ?? false));
            $this->assertFalse((bool) ($status['email_ready'] ?? true));
        });
    }

    public function testRuntimeUnlocksFromWorkspaceManualWhatsAppConfigWithoutEmbeddedSignup(): void
    {
        $this->withEnv([
            'SMTP_HOST' => '',
            'SMTP_USER' => '',
            'SMTP_PASS' => '',
            'GMAIL_MAIL_CLIENT_ID' => '',
            'GMAIL_MAIL_CLIENT_SECRET' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_ID' => '',
            'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET' => '',
            'META_APP_ID' => '',
            'META_APP_SECRET' => '',
            'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => '',
            'WHATSAPP_VERIFY_TOKEN' => 'verify-token',
        ], function (): void {
            Database::execute(
                "INSERT INTO workspace_whatsapp_integrations
                    (workspace_id, connected_by_user_id, phone_number_id, display_phone_number, access_token, connection_status, webhook_token, webhook_verify_token, connected_at)
                 VALUES (1, NULL, '555555555555555', '+254 700 555 555', 'manual-token', 'connected', 'workspace-webhook-token', 'workspace-verify-token', NOW())
                 ON DUPLICATE KEY UPDATE phone_number_id = VALUES(phone_number_id), access_token = VALUES(access_token), connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
            );

            $status = (new WorkspaceCommunicationGateService())->status(1, []);

            $this->assertTrue((bool) ($status['ready'] ?? false));
            $this->assertFalse((bool) ($status['locked'] ?? true));
            $this->assertTrue((bool) ($status['whatsapp_ready'] ?? false));
            $this->assertFalse((bool) ($status['email_ready'] ?? true));
        });
    }

    public function testEmailReadinessDoesNotUnlockWhatsAppCustomerMessaging(): void
    {
        $this->ensureWhatsAppPluginInstalled();
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

        $gate = new WorkspaceCommunicationGateService();
        $overall = $gate->status(1, ['id' => 1, 'role' => 'owner']);
        $whatsApp = $gate->channelStatus(1, 'whatsapp', ['id' => 1, 'role' => 'owner']);
        $payload = $gate->jsonChannelBlockPayload(1, 'whatsapp', ['id' => 1, 'role' => 'owner']);

        $this->assertTrue((bool) ($overall['ready'] ?? false));
        $this->assertTrue((bool) ($overall['email_ready'] ?? false));
        $this->assertFalse((bool) ($whatsApp['ready'] ?? true));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, (string) ($whatsApp['skill_key'] ?? ''));
        $this->assertSame('whatsapp_setup_required', (string) ($payload['error_code'] ?? ''));
        $this->assertStringContainsString('module=whatsapp', (string) ($payload['setup_url'] ?? ''));
        $this->assertStringNotContainsString('assistant', strtolower((string) ($payload['error'] ?? '')));
    }

    public function testWhatsAppCustomerMessagingIsReadyWithoutAssistantPlugin(): void
    {
        $this->ensureWhatsAppPluginInstalled();
        Database::execute(
            "DELETE FROM workspace_skill_installs WHERE workspace_id = 1 AND skill_key = ?",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        );
        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, phone_number_id, display_phone_number, access_token, connection_status, webhook_token, webhook_verify_token, connected_at)
             VALUES (1, NULL, '555555555555556', '+254 700 555 556', 'manual-token', 'connected', 'workspace-webhook-token', 'workspace-verify-token', NOW())
             ON DUPLICATE KEY UPDATE phone_number_id = VALUES(phone_number_id), display_phone_number = VALUES(display_phone_number), access_token = VALUES(access_token), connection_status = VALUES(connection_status), webhook_token = VALUES(webhook_token), webhook_verify_token = VALUES(webhook_verify_token)"
        );

        $status = (new WorkspaceCommunicationGateService())->channelStatus(
            1,
            'whatsapp',
            ['id' => 1, 'role' => 'owner']
        );

        $this->assertTrue((bool) ($status['installed'] ?? false));
        $this->assertTrue((bool) ($status['ready'] ?? false));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP, (string) ($status['skill_key'] ?? ''));
        $this->assertFalse((bool) Database::queryOne(
            "SELECT 1 FROM workspace_skill_installs WHERE workspace_id = 1 AND skill_key = ? LIMIT 1",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
        ));
    }

    public function testProtectedDemoWorkspaceRuntimeUnlocksForActiveDemoSession(): void
    {
        $demoWorkspaceId = (new DemoWorkspaceService())->id();
        $userId = $this->ensureDemoTestUser();
        $sessionUuid = '11111111-1111-4111-8111-111111111111';

        Database::execute(
            "INSERT INTO demo_visitor_sessions
                (session_uuid, workspace_id, user_id, consent_privacy, access_source, status, expires_at, purge_after, last_seen_at)
             VALUES (?, ?, ?, 1, 'logged_in', 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
            [$sessionUuid, $demoWorkspaceId, $userId]
        );

        Session::set('__remember_restore_attempted', true);
        Session::set('user_id', $userId);
        Session::set('demo_visitor_session_id', (int) Database::lastInsertId());
        Session::set('demo_visitor_session_uuid', $sessionUuid);
        Session::set('demo_workspace_id', $demoWorkspaceId);
        WorkspaceContext::activateRuntimeWorkspace($demoWorkspaceId, $userId, 'demo_viewer');

        $status = (new WorkspaceCommunicationGateService())->status($demoWorkspaceId, ['id' => $userId]);

        $this->assertTrue((bool) ($status['ready'] ?? false));
        $this->assertFalse((bool) ($status['locked'] ?? true));
        $this->assertTrue((bool) ($status['email_ready'] ?? false));
        $this->assertTrue((bool) ($status['whatsapp_ready'] ?? false));
        $this->assertSame('simulated', (string) ($status['channels']['main_email']['runtime'] ?? ''));
    }

    /**
     * @param array<string,string> $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $original = [];
        foreach ($values as $key => $value) {
            $original[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        try {
            $callback();
        } finally {
            foreach ($original as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                    putenv($key);
                } else {
                    $_ENV[$key] = $value;
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    private function ensureDemoTestUser(): int
    {
        $row = Database::queryOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $userId = (int) ($row['id'] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, email_verified_at, created_at)
             VALUES (UUID(), 'demo.gate.test@example.test', ?, 'viewer', NOW(), NOW())",
            [password_hash('P@ssword123!', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWhatsAppPluginInstalled(): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs
                (workspace_id, skill_key, status, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at)
             VALUES (1, ?, 'installed', 1, 1, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', disabled_at = NULL, uninstalled_at = NULL",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP]
        );
    }
}
