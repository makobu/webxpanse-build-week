<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\TwilioWebhookSignatureValidator;
use CRM\Tests\TestCase;

final class TwilioWebhookSignatureValidatorTest extends TestCase
{
    public function testValidSignatureIsAcceptedAndTamperingIsRejected(): void
    {
        $validator = new TwilioWebhookSignatureValidator();
        $url = 'https://crm.example.test/api/webhooks/sms.php';
        $payload = ['MessageSid' => 'SM123', 'From' => '+254700000001', 'Body' => 'Hello'];
        $signature = $validator->sign($url, $payload, 'twilio-secret');

        $this->assertTrue($validator->isValid($url, $payload, $signature, 'twilio-secret'));
        $this->assertFalse($validator->isValid($url, array_merge($payload, ['Body' => 'Forged']), $signature, 'twilio-secret'));
        $this->assertFalse($validator->isValid($url, $payload, '', 'twilio-secret'));
    }
}
