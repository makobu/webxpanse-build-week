<?php
/**
 * SMTP Client Tests
 * 
 * Note: These tests may require a test SMTP server
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\TestCase;
use CRM\Services\SMTPClient;

class SMTPClientTest extends TestCase
{
    public function testSMTPClientInstantiation(): void
    {
        $client = new SMTPClient();
        $this->assertInstanceOf(SMTPClient::class, $client);
    }

    public function testRejectsSubjectHeaderInjectionBeforeTransport(): void
    {
        $client = new SMTPClient();

        $this->expectException(\InvalidArgumentException::class);
        $client->send(
            'recipient@example.test',
            'sender@example.test',
            'Sender',
            "Hello\r\nBcc: attacker@example.test",
            'Body'
        );
    }

    public function testRejectsCustomHeaderInjectionBeforeTransport(): void
    {
        $client = new SMTPClient();

        $this->expectException(\InvalidArgumentException::class);
        $client->sendWithHeaders(
            'recipient@example.test',
            'sender@example.test',
            'Sender',
            'Hello',
            'Body',
            [],
            null,
            ['References' => "<safe@example.test>\nBcc: attacker@example.test"]
        );
    }
    
    // Note: Actual SMTP sending tests would require a test server
    // These are integration tests that should be run separately
}
