<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AfricaTalkingVoiceProvider;
use PHPUnit\Framework\TestCase;

class AfricaTalkingVoiceProviderTest extends TestCase
{
    public function testExplicitConsentStopsBeforeDialling(): void
    {
        $xml = (new AfricaTalkingVoiceProvider())->renderInboundInstructions([
            'consent_mode' => 'explicit_keypress',
            'consent_notice' => 'This call may be recorded.',
            'consent_callback_url' => 'https://crm.example/api/consent?token=abc&call=1',
            'destination' => '+254700000001',
            'record' => true,
        ]);

        $this->assertStringContainsString('<GetDigits', $xml);
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    public function testConsentGrantedDialEscapesDestinationAndEnablesRecording(): void
    {
        $xml = (new AfricaTalkingVoiceProvider())->renderInboundInstructions([
            'consent_mode' => 'notice_only',
            'destination' => '+254700000001',
            'record' => true,
            'max_duration' => 3600,
        ]);

        $this->assertStringContainsString('phoneNumbers="+254700000001"', $xml);
        $this->assertStringContainsString('record="true"', $xml);
        $this->assertStringContainsString('maxDuration="3600"', $xml);
    }

    public function testProviderEventsAndEstimatesAreNormalized(): void
    {
        $provider = new AfricaTalkingVoiceProvider();
        $event = $provider->normalizeEvent([
            'sessionId' => 'session-1', 'status' => 'No Answer',
            'callerNumber' => '+254700000001', 'destinationNumber' => '+254711111111',
            'durationInSeconds' => 61, 'clientRequestId' => '00000000-0000-4000-8000-000000000001',
        ]);
        $this->assertSame('session-1', $event['session_id']);
        $this->assertSame('no_answer', $event['state']);
        $this->assertSame(61, $event['duration_seconds']);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $event['client_request_id']);
        $this->assertSame(['currency' => 'KES', 'amount' => 5.0, 'billable_minutes' => 2], $provider->estimateUsage('outbound', 61));
        $this->assertSame(['currency' => 'KES', 'amount' => 5.5, 'billable_minutes' => 2], $provider->estimateUsage('outbound', 61, true));
    }

    public function testCallbackAuthenticityRequiresConfiguredVirtualNumber(): void
    {
        $provider = new AfricaTalkingVoiceProvider();
        $accepted = $provider->scoreCallbackAuthenticity(
            ['virtual_number' => '+254700000001', 'settings' => []],
            ['sessionId' => 'session-1', 'destinationNumber' => '+254700000001'],
            '192.0.2.10'
        );
        $rejected = $provider->scoreCallbackAuthenticity(
            ['virtual_number' => '+254700000001', 'settings' => []],
            ['sessionId' => 'session-2', 'destinationNumber' => '+254711111111'],
            '192.0.2.10'
        );
        $this->assertTrue($accepted['accepted']);
        $normalized = $provider->scoreCallbackAuthenticity(
            ['virtual_number' => '+254700000001', 'settings' => []],
            ['sessionId' => 'session-3', 'destinationNumber' => '254700000001'],
            '192.0.2.10'
        );
        $this->assertTrue($normalized['accepted']);
        $this->assertFalse($rejected['accepted']);
        $this->assertContains('virtual_number_mismatch', $rejected['reasons']);
    }

    public function testConfigurationVerificationAuthenticatesAgainstApplicationData(): void
    {
        $request = [];
        $provider = new AfricaTalkingVoiceProvider(function (string $method, string $url, array $fields, array $headers) use (&$request): array {
            $request = compact('method', 'url', 'fields', 'headers');
            return ['balance' => 'KES 125.50'];
        });
        $result = $provider->verifyConfiguration([
            'account_username' => 'sandbox', 'api_key' => 'secret-value', 'virtual_number' => '+254700000001',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('sandbox', $result['metadata']['environment']);
        $this->assertSame('KES 125.50', $result['metadata']['balance']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://api.sandbox.africastalking.com/version1/user', $request['url']);
        $this->assertSame(['username' => 'sandbox'], $request['fields']);
        $this->assertContains('apiKey: secret-value', $request['headers']);
    }

    public function testTransferAndQueueStatusUseDocumentedProviderContracts(): void
    {
        $requests = [];
        $provider = new AfricaTalkingVoiceProvider(function (string $method, string $url, array $fields, array $headers) use (&$requests): array {
            $requests[] = compact('method', 'url', 'fields', 'headers');
            return ['status' => 'Success'];
        });
        $config = ['account_username' => 'live-app', 'api_key' => 'secret-value', 'virtual_number' => '+254700000001'];

        $provider->transfer($config, 'AT-session-1', '+254700000002');
        $provider->queueStatus($config, ['+254700000001', '+254700000001']);

        $this->assertSame('https://voice.africastalking.com/callTransfer', $requests[0]['url']);
        $this->assertSame('callee', $requests[0]['fields']['callLeg']);
        $this->assertArrayNotHasKey('callBackUrl', $requests[0]['fields']);
        $this->assertSame('https://voice.africastalking.com/queueStatus', $requests[1]['url']);
        $this->assertSame('+254700000001', $requests[1]['fields']['phoneNumbers']);
    }

    public function testUnsupportedRemoteHangupIsExplicit(): void
    {
        $provider = new AfricaTalkingVoiceProvider();
        $this->assertFalse($provider->capabilities()['remote_end']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not expose a documented remote hangup operation');
        $provider->endCall(['account_username' => 'live-app'], 'AT-session-1');
    }

    public function testDocumentedQueueActionsAreRenderedSafely(): void
    {
        $provider = new AfricaTalkingVoiceProvider();
        $this->assertStringContainsString('<Enqueue name="support" holdMusic="https://example.com/hold.mp3" />', $provider->renderEnqueueInstructions('support', 'https://example.com/hold.mp3'));
        $this->assertStringContainsString('<Dequeue phoneNumber="+254700000001" name="support" />', $provider->renderDequeueInstructions('+254700000001', 'support'));
    }

    public function testAgentFirstOutboundBridgeNeverClaimsUnprovenCustomerConsent(): void
    {
        $provider = new AfricaTalkingVoiceProvider();
        $this->assertFalse($provider->capabilities()['outbound_prebridge_consent']);
        $xml = $provider->renderOutboundBridgeInstructions([
            'destination' => '+254700000001',
            'record' => true,
            'consent_granted' => true,
            'max_duration' => 3600,
        ]);
        $this->assertStringContainsString('record="false"', $xml);
        $this->assertStringNotContainsString('record="true"', $xml);
    }
}
