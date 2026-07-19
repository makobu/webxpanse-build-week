<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailIntegrationServiceTest extends DatabaseTestCase
{
    private EmailIntegrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailIntegrationService();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                uuid = VALUES(uuid),
                name = VALUES(name),
                slug = VALUES(slug),
                status = VALUES(status),
                plan_status = VALUES(plan_status),
                updated_at = NOW()",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "DELETE FROM email_integrations
             WHERE provider = 'google_workspace'
               AND scope = 'main_email'
               AND workspace_id IN (1, 2)"
        );

        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, access_token, refresh_token, token_expires_at, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'google_workspace', 'main_email', 'token-ws1', 'refresh-ws1', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'ws1@example.com', 1, NULL, '{}', NOW(), NOW()),
             (2, 'google_workspace', 'main_email', 'token-ws2', 'refresh-ws2', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'ws2@example.com', 1, NULL, '{}', NOW(), NOW())"
        );
    }

    public function testGetActiveMainIntegrationUsesActiveWorkspace(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        $workspaceOne = $this->service->getActiveMainIntegration();
        WorkspaceContext::clear();

        WorkspaceContext::activateRuntimeWorkspace(2);
        $workspaceTwo = $this->service->getActiveMainIntegration();
        WorkspaceContext::clear();

        $this->assertSame(1, (int) ($workspaceOne['workspace_id'] ?? 0));
        $this->assertSame('ws1@example.com', (string) ($workspaceOne['email_address'] ?? ''));
        $this->assertSame(2, (int) ($workspaceTwo['workspace_id'] ?? 0));
        $this->assertSame('ws2@example.com', (string) ($workspaceTwo['email_address'] ?? ''));
    }

    public function testListActiveMainIntegrationsReturnsWorkspaceScopedRows(): void
    {
        $rows = $this->service->listActiveMainIntegrations();

        $workspaceIds = array_map(
            static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
            $rows
        );

        sort($workspaceIds);

        $this->assertSame([1, 2], $workspaceIds);
    }

    public function testMainProviderSummaryUsesPlatformSystemMailAndIgnoresLegacyMainRows(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'smtp-user@example.com',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_username' => 'smtp-user@example.com',
            'smtp_password' => 'smtp-password',
            'imap_enabled' => true,
            'imap_host' => 'imap.gmail.com',
            'imap_username' => 'imap-user@example.com',
            'imap_password' => 'imap-password',
        ], 1);
        $_ENV['GMAIL_MAIL_CLIENT_ID'] = 'gmail-client-id';
        $_ENV['GMAIL_MAIL_CLIENT_SECRET'] = 'gmail-client-secret';

        Database::execute(
            "UPDATE email_integrations
             SET is_active = CASE WHEN workspace_id = 1 AND provider = 'google_workspace' THEN 0 ELSE is_active END"
        );
        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, access_token, refresh_token, token_expires_at, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'gmail_oauth', 'main_email', 'gmail-token', 'gmail-refresh', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'owner@gmail.com', 1, NULL, '{\"last_successful_provider\":\"gmail_oauth\"}', NOW(), NOW())"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $summary = $this->service->getMainProviderSummary();
        WorkspaceContext::clear();

        $this->assertSame(EmailIntegrationService::PROVIDER_MANUAL_SMTP, (string) ($summary['provider_key'] ?? ''));
        $this->assertSame('Manual SMTP / IMAP', (string) ($summary['provider_label'] ?? ''));
        $this->assertFalse((bool) ($summary['is_active'] ?? true));
        $this->assertSame('', (string) ($summary['connected_email'] ?? ''));
        $this->assertTrue((bool) ($summary['smtp_fallback_configured'] ?? false));
        $this->assertTrue((bool) ($summary['incoming_fallback_configured'] ?? false));
        $this->assertSame('ready', (string) ($summary['readiness'] ?? ''));
        $this->assertSame('manual_smtp', (string) ($summary['last_successful_provider'] ?? ''));
    }

    public function testMainProviderSummaryIsBlockedWhenOnlyLegacyMainRowsExist(): void
    {
        Database::execute('DELETE FROM platform_email_defaults WHERE scope = ?', [EmailIntegrationService::SCOPE_MAIN_EMAIL]);
        $envBackup = [
            'SMTP_HOST' => $_ENV['SMTP_HOST'] ?? null,
            'SMTP_USER' => $_ENV['SMTP_USER'] ?? null,
            'SMTP_PASS' => $_ENV['SMTP_PASS'] ?? null,
        ];
        $_ENV['SMTP_HOST'] = 'smtp.legacy-env.test';
        $_ENV['SMTP_USER'] = 'legacy@example.test';
        $_ENV['SMTP_PASS'] = 'legacy-secret';
        try {
            Database::execute(
                "INSERT INTO email_integrations
                 (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
                 VALUES
                 (1, 'manual_smtp', 'main_email', 'legacy-main@example.test', 1, NULL, ?, NOW(), NOW())",
                [json_encode([
                    'smtp_host' => 'smtp.legacy-row.test',
                    'smtp_username' => 'legacy-main@example.test',
                    'smtp_password' => 'legacy-row-secret',
                    'from_email' => 'legacy-main@example.test',
                ], JSON_UNESCAPED_SLASHES)]
            );

            WorkspaceContext::activateRuntimeWorkspace(1);
            $summary = $this->service->getMainProviderSummary();
            WorkspaceContext::clear();

            $this->assertSame('blocked', (string) ($summary['readiness'] ?? ''));
            $this->assertFalse((bool) ($summary['smtp_fallback_configured'] ?? true));
            $this->assertSame('', (string) ($summary['connected_email'] ?? ''));
            $this->assertStringContainsString('System Mail SMTP', (string) (($summary['issues'][0]['message'] ?? '')));
        } finally {
            WorkspaceContext::clear();
            foreach ($envBackup as $key => $value) {
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

    public function testRecordProviderFailureAndSuccessUpdateSettingsMetadata(): void
    {
        $integration = Database::queryOne(
            "SELECT id, workspace_id
             FROM email_integrations
             WHERE workspace_id = 1 AND provider = 'google_workspace'
             LIMIT 1"
        );

        $this->assertNotNull($integration);

        $this->service->recordProviderFailure((int) $integration['id'], 'Token refresh failed because refresh token is revoked.', 1);
        $failed = Database::queryOne("SELECT settings_json FROM email_integrations WHERE id = ?", [(int) $integration['id']]);
        $failedSettings = json_decode((string) ($failed['settings_json'] ?? '{}'), true);

        $this->assertSame('Token refresh failed because refresh token is revoked.', (string) ($failedSettings['last_failure'] ?? ''));
        $this->assertNotEmpty($failedSettings['last_failure_at'] ?? null);

        $this->service->recordProviderSuccess((int) $integration['id'], EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE, 'gmail_api', 1);
        $successful = Database::queryOne("SELECT settings_json FROM email_integrations WHERE id = ?", [(int) $integration['id']]);
        $successSettings = json_decode((string) ($successful['settings_json'] ?? '{}'), true);

        $this->assertArrayNotHasKey('last_failure', $successSettings);
        $this->assertSame('google_workspace', (string) ($successSettings['last_successful_provider'] ?? ''));
        $this->assertSame('gmail_api', (string) ($successSettings['last_delivery_method'] ?? ''));
        $this->assertNotEmpty($successSettings['last_success_at'] ?? null);
    }

    public function testAssistantGmailScopeDoesNotOverwriteMainMailbox(): void
    {
        $_ENV['GMAIL_MAIL_CLIENT_ID'] = 'gmail-client-id';
        $_ENV['GMAIL_MAIL_CLIENT_SECRET'] = 'gmail-client-secret';

        WorkspaceContext::activateRuntimeWorkspace(1);
        $assistantId = $this->service->storeAssistantGmailIntegration(0, [
            'access_token' => 'assistant-token',
            'refresh_token' => 'assistant-refresh',
            'expires_in' => 3600,
        ], [
            'emailAddress' => 'assistant@gmail.com',
        ]);
        WorkspaceContext::clear();

        $main = $this->service->getActiveMainIntegration(1);
        $assistant = $this->service->getActiveAssistantIntegration(1);

        $this->assertSame('ws1@example.com', (string) ($main['email_address'] ?? ''));
        $this->assertSame($assistantId, (int) ($assistant['id'] ?? 0));
        $this->assertSame('assistant_email', (string) ($assistant['scope'] ?? ''));
        $this->assertSame('assistant@gmail.com', (string) ($assistant['email_address'] ?? ''));
    }

    public function testStrictOutreachSmtpCanBeCopiedIntoPlatformSystemMailDefaults(): void
    {
        $this->service->storeManualMailIntegrationForRole('outreach', 1, [
            'smtp_host' => 'mail.outreach.example.test',
            'smtp_port' => '465',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'outreach-secret',
            'smtp_encryption' => 'ssl',
            'from_email' => 'outreach@example.test',
            'from_name' => 'Outreach Team',
        ], 1);

        $this->assertTrue($this->service->isStrictRoleOutboundReady('outreach', 1));

        $outreachSmtp = $this->service->getStrictManualSmtpConfigForRole('outreach', 1);
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => (string) ($outreachSmtp['from_email'] ?? ''),
            'from_name' => (string) ($outreachSmtp['from_name'] ?? ''),
            'smtp_host' => (string) ($outreachSmtp['host'] ?? ''),
            'smtp_port' => (string) ($outreachSmtp['port'] ?? ''),
            'smtp_username' => (string) ($outreachSmtp['username'] ?? ''),
            'smtp_password' => (string) ($outreachSmtp['password'] ?? ''),
            'smtp_encryption' => (string) ($outreachSmtp['encryption'] ?? ''),
            'imap_enabled' => false,
        ], 1);

        $systemMail = (new PlatformEmailDefaultService())->smtpConfig(EmailIntegrationService::SCOPE_MAIN_EMAIL);

        $this->assertSame('mail.outreach.example.test', (string) ($systemMail['host'] ?? ''));
        $this->assertSame(465, (int) ($systemMail['port'] ?? 0));
        $this->assertSame('outreach@example.test', (string) ($systemMail['username'] ?? ''));
        $this->assertSame('outreach-secret', (string) ($systemMail['password'] ?? ''));
        $this->assertSame('ssl', (string) ($systemMail['encryption'] ?? ''));
        $this->assertSame('outreach@example.test', (string) ($systemMail['from_email'] ?? ''));
        $this->assertSame('Outreach Team', (string) ($systemMail['from_name'] ?? ''));
    }

    public function testStrictOutreachSmtpIsMissingUntilRoleMailboxIsConfigured(): void
    {
        $this->assertFalse($this->service->isStrictRoleOutboundReady('outreach', 1));
        $this->assertSame([], $this->service->getStrictManualSmtpConfigForRole('outreach', 1));
    }

    public function testRoleMailboxEditPreservesSavedPasswordWhenPasswordFieldIsBlank(): void
    {
        $this->service->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.old.example.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'saved-secret',
            'imap_enabled' => false,
        ], 1);

        $this->service->storeManualMailIntegrationForRole('outreach', 1, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.new.example.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => '',
            'imap_enabled' => false,
        ], 1);

        $smtp = $this->service->getStrictManualSmtpConfigForRole('outreach', 1);

        $this->assertSame('smtp.new.example.test', (string) ($smtp['host'] ?? ''));
        $this->assertSame('saved-secret', (string) ($smtp['password'] ?? ''));
        $this->assertTrue($this->service->isStrictRoleOutboundReady('outreach', 1));
    }

    public function testRoleProviderSummaryDoesNotFallbackToSystemMail(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'system@example.test',
            'smtp_host' => 'smtp.system.example.test',
            'smtp_username' => 'system@example.test',
            'smtp_password' => 'system-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.system.example.test',
            'imap_username' => 'system@example.test',
            'imap_password' => 'system-secret',
        ], 1);

        $summary = $this->service->getOutreachProviderSummary(1);

        $this->assertFalse((bool) ($summary['is_active'] ?? true));
        $this->assertFalse((bool) ($summary['smtp_fallback_configured'] ?? true));
        $this->assertFalse((bool) ($summary['incoming_fallback_configured'] ?? true));
        $this->assertSame('blocked', (string) ($summary['readiness'] ?? ''));
        $this->assertSame([], $this->service->getManualSmtpConfigForRole('outreach', 1));
        $this->assertSame('', $this->service->getPreferredFromEmailForRole('outreach', '', 1));
    }
}
