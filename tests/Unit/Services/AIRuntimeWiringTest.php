<?php

namespace CRM\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class AIRuntimeWiringTest extends TestCase
{
    public function testApiEndpointsUseSharedRuntimeServices(): void
    {
        $health = file_get_contents(__DIR__ . '/../../../api/email_assistant_health.php');
        $probe = file_get_contents(__DIR__ . '/../../../api/test_ai_connection.php');

        $this->assertStringContainsString('AIRuntimeConfig', (string) $health);
        $this->assertStringContainsString('AIProviderProbeService', (string) $probe);
    }
}
