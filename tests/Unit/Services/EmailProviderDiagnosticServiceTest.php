<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailProviderDiagnosticService;
use PHPUnit\Framework\TestCase;

class EmailProviderDiagnosticServiceTest extends TestCase
{
    private EmailProviderDiagnosticService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailProviderDiagnosticService();
    }

    public function testDiagnosesMissingSystemMailProvider(): void
    {
        $diagnostic = $this->service->diagnose('No configured mail provider is available.', [
            'provider_label' => 'Manual SMTP / IMAP',
            'readiness' => 'blocked',
        ]);

        $this->assertSame('system_mail_missing', $diagnostic['diagnostic_code']);
        $this->assertSame('System Mail is not configured', $diagnostic['diagnostic_title']);
        $this->assertStringContainsString('System Mail SMTP/IMAP', $diagnostic['recommended_action']);
        $this->assertStringContainsString('copy the active Outreach Email SMTP settings', $diagnostic['recommended_action']);
        $this->assertStringNotContainsString('SMTP/OAuth', $diagnostic['recommended_action']);
        $this->assertSame('No configured mail provider is available.', $diagnostic['technical_error']);
    }

    public function testDiagnosesAuthenticationFailure(): void
    {
        $diagnostic = $this->service->diagnose('SMTP Error: Could not authenticate. 535 invalid credentials', [
            'provider_label' => 'System Mail SMTP',
        ]);

        $this->assertSame('smtp_auth_failed', $diagnostic['diagnostic_code']);
        $this->assertSame('System Mail SMTP authentication failed', $diagnostic['diagnostic_title']);
        $this->assertStringContainsString('SMTP username and password', $diagnostic['recommended_action']);
    }

    public function testDiagnosesMailFromMismatch(): void
    {
        $diagnostic = $this->service->diagnose('550-The MAIL FROM address crm@example.test is not allowed to use this server.');

        $this->assertSame('sender_mismatch', $diagnostic['diagnostic_code']);
        $this->assertSame('From email is not allowed by the mail server', $diagnostic['diagnostic_title']);
        $this->assertStringContainsString('From Email', $diagnostic['recommended_action']);
    }

    public function testDiagnosesConnectionAndTlsFailure(): void
    {
        $diagnostic = $this->service->diagnose('stream_socket_enable_crypto(): SSL operation failed with code 1');

        $this->assertSame('smtp_connection_failed', $diagnostic['diagnostic_code']);
        $this->assertSame('Could not connect to the mail server', $diagnostic['diagnostic_title']);
        $this->assertStringContainsString('host, port, and encryption', $diagnostic['recommended_action']);
    }
}
