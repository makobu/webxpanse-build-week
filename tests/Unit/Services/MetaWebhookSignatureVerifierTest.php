<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\MetaWebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

class MetaWebhookSignatureVerifierTest extends TestCase
{
    public function testAcceptsValidMetaSignature(): void
    {
        $payload = '{"object":"whatsapp_business_account"}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'app-secret');

        $this->assertTrue((new MetaWebhookSignatureVerifier())->verify($payload, $signature, 'app-secret'));
    }

    public function testRejectsMissingMalformedAndWrongSignatures(): void
    {
        $verifier = new MetaWebhookSignatureVerifier();
        $payload = '{"object":"whatsapp_business_account"}';

        $this->assertFalse($verifier->verify($payload, '', 'app-secret'));
        $this->assertFalse($verifier->verify($payload, 'sha256=not-a-digest', 'app-secret'));
        $this->assertFalse($verifier->verify($payload, 'sha256=' . str_repeat('a', 64), 'app-secret'));
        $this->assertFalse($verifier->verify($payload, 'sha256=' . hash_hmac('sha256', $payload, 'app-secret'), ''));
    }
}
