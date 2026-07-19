<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\VoiceCallPolicyService;
use PHPUnit\Framework\TestCase;

class VoiceCallPolicyServiceTest extends TestCase
{
    public function testKenyaIsAllowedByDefaultAndDomesticNumbersAreNormalized(): void
    {
        $result = (new VoiceCallPolicyService())->assertDestinationAllowed(1, '0700 000 001', [
            'allowed_country_codes' => ['+254'], 'blocked_prefixes' => [],
        ]);
        $this->assertSame('+254700000001', $result);
    }

    public function testPremiumAndInternationalDestinationsFailClosed(): void
    {
        $service = new VoiceCallPolicyService();
        try {
            $service->assertDestinationAllowed(1, '+254900123456', [
                'allowed_country_codes' => ['+254'], 'blocked_prefixes' => [],
            ]);
            $this->fail('Premium number should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Premium', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('country is not enabled');
        $service->assertDestinationAllowed(1, '+12025550123', [
            'allowed_country_codes' => ['+254'], 'blocked_prefixes' => [],
        ]);
    }
}
