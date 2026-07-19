<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceVoiceConfigService;
use PHPUnit\Framework\TestCase;

class WorkspaceVoiceConfigServiceTest extends TestCase
{
    public function testKenyanNumbersAreNormalizedDeterministically(): void
    {
        $service = new WorkspaceVoiceConfigService();
        $this->assertSame('+254712345678', $service->normalizePhone('0712 345 678'));
        $this->assertSame('+254712345678', $service->normalizePhone('00254 712 345 678'));
        $this->assertSame('', $service->normalizePhone('not-a-number'));
        $this->assertSame($service->numberHash('0712345678'), $service->numberHash('+254712345678'));
        $this->assertStringStartsWith('+254', $service->maskPhone('+254712345678'));
        $this->assertStringContainsString('*', $service->maskPhone('+254712345678'));
    }
}
