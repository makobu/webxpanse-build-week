<?php

namespace CRM\Tests\Unit\Services;

use CRM\CacheManager;
use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AIServiceRuntimeStatusTest extends DatabaseTestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
        $this->resetLocalProviderCooldown();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        $this->resetLocalProviderCooldown();
        parent::tearDown();
    }

    public function testProcessCapturesFallbackStatusWhenRemoteProviderIsUnavailable(): void
    {
        unset($_ENV['AI_API_KEY'], $_ENV['AI_SERVICE_URL']);
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';

        $service = new AIService();
        $service->process('thread_summary', ['text' => 'Summarize this thread.']);
        $status = $service->getLastProviderStatus();

        $this->assertSame('ollama', $status['provider']);
        $this->assertTrue($status['fallback_used']);
        $this->assertSame('local_fallback', $status['mode']);
        $this->assertFalse($status['success']);
    }

    public function testFastFallbackSkipsLocalProbeWhenNotExplicitlyEnabled(): void
    {
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';
        $_ENV['AI_LOCAL_ENABLED'] = 'false';

        $service = new AIService();
        $startedAt = microtime(true);
        $result = $service->process('sentiment', ['text' => 'Happy customer'], ['fast_fallback' => true]);
        $elapsedSeconds = microtime(true) - $startedAt;
        $status = $service->getLastProviderStatus();

        $this->assertSame('', $result);
        $this->assertLessThan(1.0, $elapsedSeconds);
        $this->assertSame('ollama', $status['provider']);
        $this->assertSame('fast_fallback', $status['mode']);
        $this->assertTrue($status['fallback_used']);
        $this->assertFalse($status['success']);
    }

    public function testFastFallbackUsesCooldownAfterLocalFailure(): void
    {
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';
        $_ENV['AI_LOCAL_ENABLED'] = 'true';

        $service = new AIService();
        $service->process('sentiment', ['text' => 'Happy customer'], ['fast_fallback' => true]);
        $firstStatus = $service->getLastProviderStatus();

        $service->process('intent_detection', ['text' => 'Can I get a quote?'], ['fast_fallback' => true]);
        $secondStatus = $service->getLastProviderStatus();

        $this->assertSame('local_fallback', $firstStatus['mode']);
        $this->assertSame('fast_fallback_cooldown', $secondStatus['mode']);
        $this->assertTrue($secondStatus['fallback_used']);
        $this->assertFalse($secondStatus['success']);
    }

    public function testCacheOnlyFallbackSkipsProvidersOnCacheMiss(): void
    {
        $_ENV['AI_API_KEY'] = 'test-key';
        $_ENV['AI_SERVICE_URL'] = 'https://api.openai.com/v1/chat/completions';
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';
        $_ENV['AI_LOCAL_ENABLED'] = 'true';

        $service = new AIService();
        $startedAt = microtime(true);
        $result = $service->process('ai_coach_recommendations', ['text' => 'Build recommendations.'], [
            'fast_fallback' => true,
            'cache_only' => true,
        ]);
        $elapsedSeconds = microtime(true) - $startedAt;
        $status = $service->getLastProviderStatus();

        $this->assertSame('', $result);
        $this->assertLessThan(1.0, $elapsedSeconds);
        $this->assertSame('none', $status['provider']);
        $this->assertSame('cache_only_miss', $status['mode']);
        $this->assertTrue($status['fallback_used']);
        $this->assertFalse($status['success']);
    }

    public function testCachedCoachRecommendationReportsCacheHit(): void
    {
        $data = ['text' => 'Build cached recommendations.'];
        $context = ['fast_fallback' => true, 'workspace_id' => 1, 'user_id' => 1];
        $service = new AIService();
        $cacheKey = $this->cacheKey($service, 'ai_coach_recommendations', $data, $context);
        $this->assertNotNull($cacheKey);
        $cache = new CacheManager();
        $cache->set($cacheKey, '{"why_this_matters":"cached"}', 60);

        try {
            $result = $service->process('ai_coach_recommendations', $data, $context);
            $status = $service->getLastProviderStatus();

            $this->assertSame('{"why_this_matters":"cached"}', $result);
            $this->assertSame('cache', $status['provider']);
            $this->assertSame('cached', $status['mode']);
            $this->assertTrue($status['cache_hit']);
            $this->assertFalse($status['fallback_used']);
        } finally {
            $cache->delete($cacheKey);
        }
    }

    public function testForceRefreshBypassesCachedCoachRecommendation(): void
    {
        unset($_ENV['AI_API_KEY'], $_ENV['AI_SERVICE_URL']);
        $_ENV['AI_LOCAL_ENABLED'] = 'false';
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';

        $data = ['text' => 'Build refresh recommendations.'];
        $context = ['fast_fallback' => true, 'workspace_id' => 1, 'user_id' => 1];
        $service = new AIService();
        $cacheKey = $this->cacheKey($service, 'ai_coach_recommendations', $data, $context);
        $this->assertNotNull($cacheKey);
        $cache = new CacheManager();
        $cache->set($cacheKey, '{"why_this_matters":"cached"}', 60);

        try {
            $result = $service->process('ai_coach_recommendations', $data, $context + [
                'force_refresh' => true,
            ]);
            $status = $service->getLastProviderStatus();

            $this->assertSame('', $result);
            $this->assertFalse($status['cache_hit']);
            $this->assertNotSame('cache', $status['provider']);
        } finally {
            $cache->delete($cacheKey);
        }
    }

    public function testProcessThrowsWhenDailyTokenLimitReached(): void
    {
        $_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] = 'true';
        $_ENV['AI_DAILY_TOKEN_LIMIT'] = '1';
        WorkspaceContext::clear();

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (1, 'openai', 'gpt-test', 'thread_summary', ?, 1, 0, 1, 0, NOW())",
            ['ai-service-limit-' . bin2hex(random_bytes(4))]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily AI token limit reached');
        $service = new AIService();
        $service->process('thread_summary', ['text' => 'Summarize this thread.']);
    }

    public function testMetadataOnlyCachedTextResponseIsEvicted(): void
    {
        unset($_ENV['AI_API_KEY'], $_ENV['AI_SERVICE_URL']);
        $_ENV['AI_LOCAL_ENABLED'] = 'false';
        $_ENV['OLLAMA_URL'] = 'http://127.0.0.1:9';

        $data = ['text' => 'Explain the current risk.'];
        $context = ['workspace_id' => 1, 'user_id' => 1];
        $service = new AIService();
        $cacheKey = $this->cacheKey($service, 'thread_summary', $data, $context);
        $this->assertNotNull($cacheKey);
        $cache = new CacheManager();
        $cache->set($cacheKey, '{"type":"text"}', 60);

        try {
            $result = $service->process('thread_summary', $data, $context);

            $this->assertSame('', $result);
            $this->assertNull($cache->get($cacheKey));
            $this->assertNotSame('cache', $service->getLastProviderStatus()['provider'] ?? '');
        } finally {
            $cache->delete($cacheKey);
        }
    }

    public function testCacheKeysAreIsolatedByWorkspaceAndUser(): void
    {
        $service = new AIService();
        $method = new \ReflectionMethod(AIService::class, 'buildCacheKey');
        $method->setAccessible(true);
        $data = ['text' => 'Private workspace context'];

        $workspaceOne = $method->invoke($service, 'thread_summary', $data, [], 1, 10);
        $workspaceTwo = $method->invoke($service, 'thread_summary', $data, [], 2, 10);
        $otherUser = $method->invoke($service, 'thread_summary', $data, [], 1, 11);

        $this->assertNotSame($workspaceOne, $workspaceTwo);
        $this->assertNotSame($workspaceOne, $otherUser);
        $this->assertNull($method->invoke($service, 'thread_summary', $data, [], 0, 10));
        $this->assertNull($method->invoke($service, 'thread_summary', $data, [], 1, 0));
    }

    public function testInvalidStructuredOutputIsRejected(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'validateOutputContract');
        $method->setAccessible(true);

        $invalid = $method->invoke(new AIService(), '{"summary":"ok"}', [
            'type' => 'json',
            'required' => ['summary', 'risks'],
        ]);
        $valid = $method->invoke(new AIService(), "```json\n{\"summary\":\"ok\",\"risks\":[]}\n```", [
            'type' => 'json',
            'required' => ['summary', 'risks'],
        ]);

        $this->assertFalse($invalid['valid']);
        $this->assertTrue($valid['valid']);
        $this->assertSame(['summary' => 'ok', 'risks' => []], json_decode($valid['output'], true));
    }

    public function testTextContractNormalizesWrappersAndRejectsMetadataOnlyOutput(): void
    {
        $method = new \ReflectionMethod(AIService::class, 'validateOutputContract');
        $method->setAccessible(true);
        $service = new AIService();

        $wrapped = $method->invoke($service, '{"response":"Usable answer"}', ['type' => 'text']);
        $placeholder = $method->invoke($service, '{"type":"text"}', ['type' => 'text']);
        $arbitraryJson = $method->invoke($service, '{"metric":3}', ['type' => 'text']);

        $this->assertTrue($wrapped['valid']);
        $this->assertSame('Usable answer', $wrapped['output']);
        $this->assertFalse($placeholder['valid']);
        $this->assertSame('', $placeholder['output']);
        $this->assertTrue($arbitraryJson['valid']);
        $this->assertSame('{"metric":3}', $arbitraryJson['output']);
    }

    public function testPausedRuntimeStopsExecutionBeforeProviderLookup(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1, 1, 'owner');
        (new AIRuntimeControlService())->setControl('global', 'paused', 1, 'Unit test pause', null, [], 1);

        $service = new AIService();
        $result = $service->process('thread_summary', ['text' => 'Do not send this to a provider.']);
        $status = $service->getLastProviderStatus();

        $this->assertSame('', $result);
        $this->assertSame('paused', $status['mode']);
        $this->assertSame('runtime_paused', $status['blocked_reason']);
        $this->assertSame([], $status['attempted_providers']);
    }

    private function cacheKey(AIService $service, string $task, array $data, array $context): ?string
    {
        $workspaceMethod = new \ReflectionMethod(AIService::class, 'resolveWorkspaceId');
        $workspaceMethod->setAccessible(true);
        $userMethod = new \ReflectionMethod(AIService::class, 'resolveUserId');
        $userMethod->setAccessible(true);
        $cacheMethod = new \ReflectionMethod(AIService::class, 'buildCacheKey');
        $cacheMethod->setAccessible(true);

        $workspaceId = (int) $workspaceMethod->invoke($service, $context);
        if ($workspaceId <= 0) {
            WorkspaceContext::activateRuntimeWorkspace(1, 1, 'owner');
            $workspaceId = 1;
        }
        $userId = (int) $userMethod->invoke($service, $context);

        return $cacheMethod->invoke($service, $task, $data, $context, $workspaceId, $userId);
    }

    private function resetLocalProviderCooldown(): void
    {
        $reflection = new \ReflectionClass(AIService::class);

        $cooldownUntil = $reflection->getProperty('localProviderCooldownUntil');
        $cooldownUntil->setAccessible(true);
        $cooldownUntil->setValue(null, 0);

        $cooldownMessage = $reflection->getProperty('localProviderCooldownMessage');
        $cooldownMessage->setAccessible(true);
        $cooldownMessage->setValue(null, '');
    }
}
