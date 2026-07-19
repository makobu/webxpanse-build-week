<?php
/**
 * Email Service Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\EmailService;
use CRM\Services\EmailTemplates;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Modules\Contacts;
use CRM\Database;

class EmailServiceTest extends DatabaseTestCase
{
    private EmailService $emailService;
    private Contacts $contacts;
    private int $testContactId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->emailService = new EmailService();
        $this->contacts = new Contacts();
        
        $contact = $this->contacts->create([
            'first_name' => 'Email',
            'email' => 'email@example.com'
        ]);
        $this->testContactId = $contact['id'];
    }
    
    protected function tearDown(): void
    {
        if ($this->testContactId) {
            $this->contacts->delete($this->testContactId);
        }
        parent::tearDown();
    }
    
    public function testSendEmailAddsToQueue(): void
    {
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test Subject',
            'Test body'
        );
        
        $this->assertIsString($uuid);
        
        // Verify email was created
        $email = Database::queryOne(
            "SELECT * FROM emails WHERE uuid = ?",
            [$uuid]
        );
        $this->assertNotNull($email);
        $this->assertEquals('pending', $email['status']);
        $this->assertSame(1, (int) ($email['workspace_id'] ?? 0));
        
        // Verify added to queue
        $queueItem = Database::queryOne(
            "SELECT * FROM email_queue WHERE email_id = ?",
            [$email['id']]
        );
        $this->assertNotNull($queueItem);
        $this->assertSame(1, (int) ($queueItem['workspace_id'] ?? 0));
    }
    
    public function testEmailTrackingInjection(): void
    {
        $htmlBody = '<html><body><a href="https://example.com">Link</a></body></html>';
        
        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Test',
            'Plain text',
            ['body_html' => $htmlBody]
        );
        
        $email = Database::queryOne(
            "SELECT body_html FROM emails WHERE uuid = ?",
            [$uuid]
        );
        
        $this->assertNotNull($email);
        $this->assertStringContainsString('api/track/email', $email['body_html']);
        $this->assertStringContainsString('/open/', $email['body_html']);
        $this->assertStringContainsString('/click/', $email['body_html']);
    }

    public function testNurtureSenderProfileStoresNurtureIdentityAndProfile(): void
    {
        $this->storeManualIntegration('nurture_email', 'nurture@example.com', 'Nurture Team');

        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Nurture subject',
            'Nurture body',
            [
                'sender_profile' => 'nurture',
                'from_email' => 'wrong@example.com',
                'from_name' => 'Wrong Sender',
            ]
        );

        $email = Database::queryOne("SELECT sender_profile, from_email, from_name FROM emails WHERE uuid = ?", [$uuid]);

        $this->assertSame('nurture', (string) ($email['sender_profile'] ?? ''));
        $this->assertSame('nurture@example.com', (string) ($email['from_email'] ?? ''));
        $this->assertSame('Nurture Team', (string) ($email['from_name'] ?? ''));
    }

    public function testNurtureSenderProfileDoesNotFallbackToMainEmail(): void
    {
        $this->storeManualIntegration('main_email', 'main@example.com', 'Main Team');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configure Nurture Email before sending nurture messages.');

        $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Nurture subject',
            'Nurture body',
            ['sender_profile' => 'nurture']
        );
    }

    public function testNurtureSenderProfileDoesNotFallbackToPlatformDefaultEmail(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'platform@example.com',
            'from_name' => 'Platform Team',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_username' => 'platform@example.com',
            'smtp_password' => 'secret',
            'smtp_encryption' => 'tls',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configure Nurture Email before sending nurture messages.');

        $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Nurture subject',
            'Nurture body',
            ['sender_profile' => 'nurture']
        );
    }

    public function testQueuedNurtureEmailReloadsSenderProfile(): void
    {
        $this->storeManualIntegration('nurture_email', 'nurture@example.com', 'Nurture Team');
        Database::execute("UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1");

        $uuid = $this->emailService->send(
            $this->testContactId,
            'recipient@example.com',
            'Queued nurture subject',
            'Queued nurture body',
            ['sender_profile' => 'nurture']
        );
        $email = Database::queryOne("SELECT id, sender_profile FROM emails WHERE uuid = ?", [$uuid]);

        $lastError = null;
        $result = $this->emailService->processEmailDetailed((int) $email['id'], $lastError, [], 1);

        $this->assertTrue((bool) ($result['success'] ?? false), (string) ($result['error'] ?? ''));
        $this->assertSame('nurture', (string) ($email['sender_profile'] ?? ''));
        $this->assertSame('nurture', (string) ($result['smtp_profile'] ?? ''));
        $this->assertSame('nurture@example.com', (string) ($result['from_email'] ?? ''));
    }

    public function testSendWithTemplateIdUsesGeneratedTemplatePresentationShell(): void
    {
        $templates = new EmailTemplates();
        $setId = $this->createActiveSmartTemplateSet();
        $templateId = $templates->create([
            'name' => 'Generated Send Template',
            'slug' => 'generated-send-template',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name},</p><p>Open <a href="https://example.com">this plan</a>.</p>',
            'body_text' => 'Hi {first_name}, Open this plan.',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => 1,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $setId,
        ]);

        $uuid = $this->emailService->sendWithTemplate($this->testContactId, '', [], [
            'template_id' => $templateId,
            'queue' => false,
        ]);

        $email = Database::queryOne("SELECT subject, body, body_html FROM emails WHERE uuid = ?", [$uuid]);

        $this->assertNotNull($email);
        $this->assertSame('Hello Email', (string) ($email['subject'] ?? ''));
        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', (string) ($email['body_html'] ?? ''));
        $this->assertStringContainsString('#145c7d', (string) ($email['body_html'] ?? ''));
        $this->assertStringContainsString('api/track/email/click', (string) ($email['body_html'] ?? ''));
        $this->assertSame('Hi Email, Open this plan.', (string) ($email['body'] ?? ''));
    }

    public function testSendWithTemplateIdUsesPlatformOpsPresentationShell(): void
    {
        $templates = new EmailTemplates();
        $templateId = $templates->create([
            'name' => 'Platform Ops Send Template',
            'slug' => 'platform-ops-send-template',
            'subject' => 'Finish setup for {workspace_name}',
            'body_html' => '<p>Hi {owner_name},</p><p><a href="{setup_url}">Continue setup</a></p>',
            'body_text' => 'Hi {owner_name}, Continue setup: {setup_url}',
            'category' => 'platform_ops',
            'variables' => ['owner_name', 'workspace_name', 'setup_url'],
            'is_active' => 1,
            'created_by' => 1,
            'is_library' => 0,
            'is_ai_generated' => 0,
            'tags' => ['platform_ops_owner_helpline', 'workspace_owner'],
        ]);

        $uuid = $this->emailService->sendWithTemplate($this->testContactId, '', [
            'owner_name' => 'Email',
            'workspace_name' => 'Default Workspace',
            'setup_url' => 'https://example.test/setup',
        ], [
            'template_id' => $templateId,
            'queue' => false,
        ]);

        $email = Database::queryOne("SELECT subject, body, body_html FROM emails WHERE uuid = ?", [$uuid]);

        $this->assertNotNull($email);
        $this->assertSame('Finish setup for Default Workspace', (string) ($email['subject'] ?? ''));
        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', (string) ($email['body_html'] ?? ''));
        $this->assertStringContainsString('#145c7d', (string) ($email['body_html'] ?? ''));
        $this->assertStringContainsString('api/track/email/click', (string) ($email['body_html'] ?? ''));
        $this->assertStringNotContainsString('Email Assistant', (string) ($email['body_html'] ?? ''));
        $this->assertSame('Hi Email, Continue setup: https://webxpanse.com/setup', (string) ($email['body'] ?? ''));
    }

    public function testOutgoingHtmlNormalizesProductionLinksBeforeTracking(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousAppUrl = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['APP_URL']);

        try {
            $uuid = $this->emailService->send(
                $this->testContactId,
                'recipient@example.com',
                'Safe links',
                'Continue: http://localhost/crm/public/onboarding.php',
                [
                    'body_html' => '<p><a href="/crm/public/onboarding.php">Continue</a> <a href="">Missing</a> <a href="mailto:help@webxpanse.com">Email us</a></p>',
                ]
            );

            $email = Database::queryOne('SELECT body, body_html FROM emails WHERE uuid = ?', [$uuid]);
            $html = (string) ($email['body_html'] ?? '');

            $this->assertSame('Continue: https://webxpanse.com/onboarding.php', (string) ($email['body'] ?? ''));
            $this->assertStringContainsString('https://webxpanse.com/api/track/email/open/', $html);
            $this->assertStringContainsString('url=https%3A%2F%2Fwebxpanse.com%2Fonboarding.php', $html);
            $this->assertStringContainsString('href="mailto:help@webxpanse.com"', $html);
            $this->assertStringNotContainsString('href=""', $html);
            $this->assertStringNotContainsString('localhost', $html);
        } finally {
            if ($previousAppEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousAppEnv;
            }
            if ($previousAppUrl === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previousAppUrl;
            }
        }
    }

    private function storeManualIntegration(string $scope, string $fromEmail, string $fromName): void
    {
        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'manual_smtp', ?, ?, 1, NULL, ?, NOW(), NOW())",
            [
                $scope,
                $fromEmail,
                json_encode([
                    'smtp_host' => 'smtp.example.com',
                    'smtp_username' => $fromEmail,
                    'smtp_password' => 'secret',
                    'smtp_encryption' => 'tls',
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function createActiveSmartTemplateSet(): int
    {
        Database::execute(
            "INSERT INTO smart_template_sets (workspace_id, user_id, status, context_hash, context_snapshot_json, created_at, updated_at)
             VALUES (1, 1, 'active', ?, '{}', NOW(), NOW())",
            [uniqid('email-service-smart-set-', true)]
        );

        return (int) Database::lastInsertId();
    }
}
