<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\SMTPClient;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class SMTPClientProviderSelectionTest extends DatabaseTestCase
{
    public function testDefaultProviderIgnoresLegacyMainGmailIntegration(): void
    {
        $_ENV['GMAIL_MAIL_CLIENT_ID'] = 'gmail-client-id';
        $_ENV['GMAIL_MAIL_CLIENT_SECRET'] = 'gmail-client-secret';

        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, access_token, refresh_token, token_expires_at, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'gmail_oauth', 'main_email', 'gmail-token', 'gmail-refresh', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'owner@gmail.com', 1, NULL, '{}', NOW(), NOW())"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $client = new SMTPClient();
        $providerKey = $client->getActiveProviderKey();
        $providerLabel = $client->getActiveProviderLabel();
        WorkspaceContext::clear();

        $this->assertSame('manual_smtp', $providerKey);
        $this->assertSame('Manual SMTP / IMAP', $providerLabel);
    }

    public function testActiveProviderFallsBackToManualSmtpWhenNoOauthIntegrationExists(): void
    {
        $_ENV['SMTP_HOST'] = 'smtp.gmail.com';
        $_ENV['SMTP_USER'] = 'fallback@example.com';

        WorkspaceContext::activateRuntimeWorkspace(1);
        $client = new SMTPClient();
        $providerKey = $client->getActiveProviderKey();
        $providerLabel = $client->getActiveProviderLabel();
        WorkspaceContext::clear();

        $this->assertSame('manual_smtp', $providerKey);
        $this->assertSame('Manual SMTP / IMAP', $providerLabel);
    }

    public function testOutreachProfileDoesNotFallBackToMainIntegration(): void
    {
        $_ENV['GMAIL_MAIL_CLIENT_ID'] = 'gmail-client-id';
        $_ENV['GMAIL_MAIL_CLIENT_SECRET'] = 'gmail-client-secret';

        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, access_token, refresh_token, token_expires_at, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'gmail_oauth', 'main_email', 'gmail-token', 'gmail-refresh', DATE_ADD(NOW(), INTERVAL 1 HOUR), 'owner@gmail.com', 1, NULL, '{}', NOW(), NOW())"
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $client = new SMTPClient('outreach');
        $providerKey = $client->getActiveProviderKey();
        $providerLabel = $client->getActiveProviderLabel();
        WorkspaceContext::clear();

        $this->assertSame('manual_smtp', $providerKey);
        $this->assertSame('Manual SMTP / IMAP', $providerLabel);
    }

    public function testNurtureProfileCanUseRoleSpecificManualProvider(): void
    {
        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'manual_smtp', 'nurture_email', 'nurture@example.com', 1, NULL, ?, NOW(), NOW())",
            [json_encode([
                'smtp_host' => 'smtp.example.com',
                'smtp_username' => 'nurture@example.com',
                'smtp_password' => 'secret',
                'from_email' => 'nurture@example.com',
            ], JSON_UNESCAPED_SLASHES)]
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $client = new SMTPClient('nurture');
        $providerKey = $client->getActiveProviderKey();
        $fromEmail = $client->getPreferredFromEmail();
        WorkspaceContext::clear();

        $this->assertSame('manual_smtp', $providerKey);
        $this->assertSame('nurture@example.com', $fromEmail);
    }

    public function testNurtureProfileDoesNotFallBackToMainIntegration(): void
    {
        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'manual_smtp', 'main_email', 'owner@example.com', 1, NULL, ?, NOW(), NOW())",
            [json_encode([
                'smtp_host' => 'smtp.example.com',
                'smtp_username' => 'owner@example.com',
                'smtp_password' => 'secret',
                'from_email' => 'owner@example.com',
            ], JSON_UNESCAPED_SLASHES)]
        );

        WorkspaceContext::activateRuntimeWorkspace(1);
        $client = new SMTPClient('nurture');
        $providerKey = $client->getActiveProviderKey();
        $fromEmail = $client->getPreferredFromEmail();
        WorkspaceContext::clear();

        $this->assertSame('manual_smtp', $providerKey);
        $this->assertSame('', $fromEmail);
    }
}
