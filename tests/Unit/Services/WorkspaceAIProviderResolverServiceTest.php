<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceAIProviderConfigService;
use CRM\Services\WorkspaceAIProviderResolverService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceAIProviderResolverServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_KEY'] = 'resolver-test-key';
        $this->ensureWorkspace(20002, 'resolver-two', 'Resolver Two');
        $this->ensureWorkspace(20003, 'resolver-three', 'Resolver Three');
        $this->setWorkspacePlan(20002, 'growth-studio-monthly');
        $this->setWorkspacePlan(20003, 'growth-studio-monthly');
        Database::execute("DELETE FROM workspace_ai_usage WHERE workspace_id IN (20002, 20003)");
        Database::execute("DELETE FROM workspace_ai_provider_configs WHERE workspace_id IN (1, 20002, 20003)");
    }

    public function testUsesDefaultWorkspaceCommonConfigBeforeCap(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(1, [
            'api_key' => 'common-key',
            'api_url' => '',
            'model' => 'gpt-4o-mini',
            'shared_daily_token_cap' => 100,
        ]);

        $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(20002, 'email_draft');

        $this->assertTrue((bool) ($resolved['available'] ?? false));
        $this->assertSame('default_workspace', (string) ($resolved['source'] ?? ''));
        $this->assertSame(1, (int) ($resolved['config_workspace_id'] ?? 0));
        $this->assertSame('common-key', (string) ($resolved['api_key'] ?? ''));
    }

    public function testUsesWorkspaceConfigAfterCommonCapExceeded(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(1, [
            'api_key' => 'common-key',
            'model' => 'gpt-4o-mini',
            'shared_daily_token_cap' => 100,
        ]);
        $configs->save(20002, [
            'api_key' => 'workspace-key',
            'model' => 'gpt-4o-mini',
        ]);

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, provider_source, provider_config_workspace_id, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (20002, 'openai', 'default_workspace', 1, 'gpt-test', 'cap', ?, 50, 50, 100, 0, NOW())",
            ['resolver-cap-' . bin2hex(random_bytes(4))]
        );

        $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(20002, 'email_draft');

        $this->assertTrue((bool) ($resolved['available'] ?? false));
        $this->assertSame('workspace_api', (string) ($resolved['source'] ?? ''));
        $this->assertSame(20002, (int) ($resolved['config_workspace_id'] ?? 0));
        $this->assertSame('workspace-key', (string) ($resolved['api_key'] ?? ''));
    }

    public function testBlocksClearlyAfterCommonCapWhenWorkspaceKeyMissing(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(1, [
            'api_key' => 'common-key',
            'model' => 'gpt-4o-mini',
            'shared_daily_token_cap' => 100,
        ]);

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, provider_source, provider_config_workspace_id, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (20003, 'openai', 'default_workspace', 1, 'gpt-test', 'cap', ?, 60, 60, 120, 0, NOW())",
            ['resolver-block-' . bin2hex(random_bytes(4))]
        );

        $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(20003, 'email_draft');

        $this->assertFalse((bool) ($resolved['available'] ?? true));
        $this->assertSame('workspace_ai_key_required', (string) ($resolved['blocked_reason'] ?? ''));
        $this->assertStringContainsString('common AI API daily cap', (string) ($resolved['message'] ?? ''));
    }

    public function testUsesWorkspaceConfigWhenCommonProviderIsUnavailable(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(20002, [
            'api_key' => 'workspace-only-key',
            'model' => 'gpt-4o-mini',
        ]);

        $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(20002, 'email_draft');

        $this->assertTrue((bool) ($resolved['available'] ?? false));
        $this->assertSame('workspace_api', (string) ($resolved['source'] ?? ''));
        $this->assertSame('workspace-only-key', (string) ($resolved['api_key'] ?? ''));
        $this->assertStringContainsString('common provider is unavailable', (string) ($resolved['message'] ?? ''));
    }

    public function testGeneralAndContentGenerationCredentialsCoexistWithoutOverwriting(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $general = $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_GENERAL,
            'api_key' => 'general-workspace-key',
            'model' => 'gpt-4o-mini',
        ]);
        $content = $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
            'api_key' => 'content-workspace-key',
            'model' => 'gpt-5-mini',
        ]);

        $rows = Database::query(
            "SELECT credential_scope, encrypted_api_key, api_key_fingerprint
             FROM workspace_ai_provider_configs
             WHERE workspace_id = ?
             ORDER BY credential_scope ASC",
            [20002]
        );

        $this->assertCount(2, $rows);
        $this->assertTrue((bool) ($general['api_key_present'] ?? false));
        $this->assertTrue((bool) ($content['api_key_present'] ?? false));
        $this->assertNotSame($general['api_key_fingerprint'] ?? '', $content['api_key_fingerprint'] ?? '');
        $this->assertSame('general-workspace-key', (string) ($configs->get(20002, true, WorkspaceAIProviderConfigService::SCOPE_GENERAL)['api_key'] ?? ''));
        $this->assertSame('content-workspace-key', (string) ($configs->get(20002, true, WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION)['api_key'] ?? ''));
        foreach ($rows as $row) {
            $encrypted = (string) ($row['encrypted_api_key'] ?? '');
            $this->assertNotSame('general-workspace-key', $encrypted);
            $this->assertNotSame('content-workspace-key', $encrypted);
        }
    }

    public function testContentGenerationDoesNotFallThroughToGeneralOrEnvironmentKey(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_GENERAL,
            'api_key' => 'general-only-key',
            'model' => 'gpt-4o-mini',
        ]);
        $previousEnvKey = $_ENV['AI_API_KEY'] ?? null;
        $_ENV['AI_API_KEY'] = 'environment-general-key';

        try {
            $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(
                20002,
                'social_post_variants',
                WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
            );
        } finally {
            if ($previousEnvKey === null) {
                unset($_ENV['AI_API_KEY']);
            } else {
                $_ENV['AI_API_KEY'] = $previousEnvKey;
            }
        }

        $this->assertFalse((bool) ($resolved['available'] ?? true));
        $this->assertSame('content_generation_key_required', (string) ($resolved['blocked_reason'] ?? ''));
        $this->assertSame(WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION, (string) ($resolved['credential_scope'] ?? ''));
    }

    public function testContentGenerationResolvesOnlyTheMatchingCredential(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_GENERAL,
            'api_key' => 'general-key',
        ]);
        $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
            'api_key' => 'content-key',
        ]);

        $resolved = (new WorkspaceAIProviderResolverService($configs))->resolveForWorkspace(
            20002,
            'social_post_variants',
            WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
        );

        $this->assertTrue((bool) ($resolved['available'] ?? false));
        $this->assertSame('content-key', (string) ($resolved['api_key'] ?? ''));
        $this->assertSame(WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION, (string) ($resolved['credential_scope'] ?? ''));
    }

    public function testCommonCapUsageIsAccountedPerCredentialScope(): void
    {
        $configs = new WorkspaceAIProviderConfigService();
        $configs->save(1, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
            'api_key' => 'common-content-key',
            'shared_daily_token_cap' => 100,
        ]);
        $configs->save(20002, [
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
            'api_key' => 'workspace-content-key',
        ]);
        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, provider_source, provider_config_workspace_id, credential_scope, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (20002, 'openai', 'default_workspace', 1, 'general', 'gpt-test', 'general-cap', ?, 60, 60, 120, 0, NOW())",
            ['resolver-general-scope-' . bin2hex(random_bytes(4))]
        );

        $resolver = new WorkspaceAIProviderResolverService($configs);
        $beforeContentUsage = $resolver->resolveForWorkspace(
            20002,
            'social_post_variants',
            WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
        );
        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, provider_source, provider_config_workspace_id, credential_scope, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (20002, 'openai', 'default_workspace', 1, 'content_generation', 'gpt-test', 'content-cap', ?, 50, 50, 100, 0, NOW())",
            ['resolver-content-scope-' . bin2hex(random_bytes(4))]
        );
        $afterContentUsage = $resolver->resolveForWorkspace(
            20002,
            'social_post_variants',
            WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
        );

        $this->assertSame('default_workspace', (string) ($beforeContentUsage['source'] ?? ''));
        $this->assertSame(0, (int) ($beforeContentUsage['common_used_today'] ?? -1));
        $this->assertSame('workspace_api', (string) ($afterContentUsage['source'] ?? ''));
        $this->assertSame(100, (int) ($afterContentUsage['common_used_today'] ?? 0));
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), updated_at = NOW()",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function setWorkspacePlan(int $workspaceId, string $priceCode): void
    {
        $price = (new WorkspacePlanEntitlementService())->priceByCode($priceCode) ?? [];
        $this->assertGreaterThan(0, (int) ($price['id'] ?? 0));
        Database::execute("DELETE FROM workspace_subscriptions WHERE workspace_id = ?", [$workspaceId]);
        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
              renewal_status, subscription_status, current_period_start, current_period_end, created_at, updated_at)
             VALUES (?, ?, 'internal', ?, 'active', 'manual', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())",
            [$workspaceId, (int) $price['id'], 'resolver-plan-' . $workspaceId]
        );
    }
}
