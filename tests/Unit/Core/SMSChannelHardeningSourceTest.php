<?php

namespace CRM\Tests\Unit\Core;

use CRM\Tests\TestCase;

final class SMSChannelHardeningSourceTest extends TestCase
{
    public function testWebhookEndpointRequiresTwilioSignatureAndReturnsForbiddenOnRejection(): void
    {
        $source = $this->source('api/webhooks/sms.php');

        $this->assertStringContainsString("HTTP_X_TWILIO_SIGNATURE", $source);
        $this->assertStringContainsString("->handle(\$_POST, \$requestUrl, \$signature)", $source);
        $this->assertStringContainsString('catch (SMSWebhookAuthenticationException $e)', $source);
        $this->assertStringContainsString('http_response_code(403)', $source);
    }

    public function testBulkSmsEndpointRequiresPermissionConsentAndIdempotency(): void
    {
        $source = $this->source('api/bulk_messaging.php');

        $this->assertStringContainsString("Authorization::can('sms.bulk_send'", $source);
        $this->assertStringContainsString('sms_consent_confirmed', $source);
        $this->assertStringContainsString('idempotency_key', $source);
    }

    public function testSmsWorkerAcknowledgesWithClaimToken(): void
    {
        $source = $this->source('cli/sms_worker.php');

        $this->assertStringContainsString("\$message['claim_token']", $source);
        $this->assertStringContainsString('QueueWorkerHeartbeatService', $source);
        $this->assertStringContainsString("'rate_reserved' => true", $source);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        return (string) $source;
    }
}
