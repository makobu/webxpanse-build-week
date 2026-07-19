<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIExecutionStatusService;
use PHPUnit\Framework\TestCase;

class AIExecutionStatusServiceTest extends TestCase
{
    public function testPresentsCachedFallbackAndBlockedStatesConsistently(): void
    {
        $service = new AIExecutionStatusService();

        $cached = $service->present(['success' => true, 'cache_hit' => true, 'provider' => 'cache']);
        $fallback = $service->present(['success' => false, 'fallback_used' => true], ['source' => 'deterministic_fallback']);
        $blocked = $service->present(['success' => false, 'blocked_reason' => 'workspace_ai_key_required']);

        $this->assertSame('cached', $cached['state']);
        $this->assertTrue($cached['cache_hit']);
        $this->assertSame('fallback', $fallback['state']);
        $this->assertTrue($fallback['retryable']);
        $this->assertSame('blocked', $blocked['state']);
        $this->assertFalse($blocked['retryable']);
    }

    public function testPreservesWorkspaceSurfaceAndContractMetadata(): void
    {
        $status = (new AIExecutionStatusService())->present([
            'success' => true,
            'workspace_id' => 22,
            'surface' => 'assistant',
            'contract_valid' => true,
            'prompt_truncated' => true,
            'model' => 'gpt-test',
        ]);

        $this->assertSame(22, $status['workspace_id']);
        $this->assertSame('assistant', $status['surface']);
        $this->assertTrue($status['contract_valid']);
        $this->assertTrue($status['prompt_truncated']);
        $this->assertSame('gpt-test', $status['model']);
    }
}
