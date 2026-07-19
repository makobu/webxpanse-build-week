<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\AITokenRateLimiterService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceWalletService;
use CRM\Tests\DatabaseTestCase;

class AITokenRateLimiterServiceTest extends DatabaseTestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    public function testUsageSummaryComputesRemainingBudget(): void
    {
        $_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] = 'true';
        $_ENV['AI_DAILY_TOKEN_LIMIT'] = '1000';
        WorkspaceContext::clear();

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (1, 'openai', 'gpt-test', 'summary', ?, 100, 150, 250, 0.05, NOW())",
            ['usage-summary-' . bin2hex(random_bytes(4))]
        );
        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (1, 'ollama', 'llama-test', 'summary', ?, 40, 60, 100, 0.0, NOW())",
            ['usage-summary-' . bin2hex(random_bytes(4))]
        );

        $service = new AITokenRateLimiterService();
        $summary = $service->getUsageSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertSame(1000, (int) $summary['daily_limit']);
        $this->assertSame(350, (int) $summary['used_today']);
        $this->assertSame(650, (int) $summary['remaining_today']);
        $this->assertFalse($summary['is_limited']);
    }

    public function testEnforceThrowsWhenLimitReached(): void
    {
        $_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] = 'true';
        $_ENV['AI_DAILY_TOKEN_LIMIT'] = '300';
        WorkspaceContext::clear();

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (1, 'openai', 'gpt-test', 'limit-check', ?, 120, 180, 300, 0.06, NOW())",
            ['usage-limit-' . bin2hex(random_bytes(4))]
        );

        $service = new AITokenRateLimiterService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily AI token limit reached');
        $service->enforce();
    }

    public function testWorkspaceMeteringReservesAndSettlesAgainstWallet(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'AI Workspace',
            'workspace_slug' => 'ai-workspace',
            'first_name' => 'Linus',
            'last_name' => 'Torvalds',
            'email' => 'linus@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        (new WorkspaceWalletService())->creditTokens($workspaceId, 2000, 'manual_adjustment', 'wallet-seed', $userId);

        Session::start();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        $service = new AITokenRateLimiterService();
        $reservation = $service->beginRequest('openai', 'gpt-test', 'email_draft', 'Please draft a helpful email for this customer.', $userId);
        $settlement = $service->completeRequest($reservation, 'Here is a concise helpful draft.', 0.012, 120);
        $usage = \CRM\Database::queryOne(
            "SELECT * FROM workspace_ai_usage WHERE workspace_id = ? AND request_id = ?",
            [$workspaceId, (string) ($reservation['request_id'] ?? '')]
        );
        $summary = $service->getUsageSummary();

        $this->assertSame(120, (int) ($settlement['billable_tokens'] ?? 0));
        $this->assertNotNull($usage);
        $this->assertSame($workspaceId, (int) ($usage['workspace_id'] ?? 0));
        $this->assertSame(120, (int) ($usage['billable_tokens'] ?? 0));
        $this->assertSame($workspaceId, (int) ($summary['workspace_id'] ?? 0));
        $this->assertSame(51880, (int) ($summary['credit_balance'] ?? $summary['token_balance'] ?? 0));
    }

    public function testSuperAdminCanUseAiWithEmptyWalletWithoutDebit(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Super Admin AI Workspace',
            'workspace_slug' => 'super-admin-ai-workspace',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.superadmin@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        $this->assignGlobalRole($userId, 'superadmin');

        Session::start();
        Session::set('user_id', $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        $wallets = new WorkspaceWalletService();
        $beforeWallet = $wallets->getSummary($workspaceId);
        $service = new AITokenRateLimiterService();

        $service->enforce();
        $reservation = $service->beginRequest('openai', 'gpt-test', 'super_admin_assist', 'Draft the admin launch note.', $userId);
        $settlement = $service->completeRequest($reservation, 'Admin launch note response.', 0.02, 140);
        $afterWallet = $wallets->getSummary($workspaceId);
        $usage = Database::queryOne(
            "SELECT billable_tokens, ledger_entry_id
             FROM workspace_ai_usage
             WHERE workspace_id = ? AND request_id = ?
             LIMIT 1",
            [$workspaceId, (string) ($reservation['request_id'] ?? '')]
        );

        $this->assertTrue((bool) ($reservation['billing_exempt'] ?? false));
        $this->assertNull($reservation['ledger_entry_id'] ?? null);
        $this->assertSame(140, (int) ($settlement['billable_tokens'] ?? 0));
        $this->assertSame((int) ($beforeWallet['token_balance'] ?? 0), (int) ($afterWallet['token_balance'] ?? -1));
        $this->assertSame(0, (int) ($afterWallet['reserved_tokens'] ?? -1));
        $this->assertNotNull($usage);
        $this->assertSame(140, (int) ($usage['billable_tokens'] ?? 0));
        $this->assertNull($usage['ledger_entry_id'] ?? null);
    }

    public function testDefaultWorkspaceRegularUserCanUseAiWithEmptyWalletWithoutDebit(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, 'user', 'Default', 'Member', NOW())",
            ['default.workspace.ai@example.com', password_hash('P@ssword123!', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status)
             VALUES (1, ?, 'member', 'active')",
            [$userId]
        );

        Session::start();
        Session::set('user_id', $userId);
        WorkspaceContext::activateRuntimeWorkspace(1);

        $wallets = new WorkspaceWalletService();
        $beforeWallet = $wallets->getSummary(1);
        $service = new AITokenRateLimiterService();

        $service->enforce();
        $reservation = $service->beginRequest('openai', 'gpt-test', 'default_workspace_assist', 'Draft the platform operations note.', $userId);
        $settlement = $service->completeRequest($reservation, 'Platform operations response.', 0.02, 160);
        $afterWallet = $wallets->getSummary(1);
        $usage = Database::queryOne(
            "SELECT input_tokens, output_tokens, billable_tokens, provider_cost, ledger_entry_id
             FROM workspace_ai_usage
             WHERE workspace_id = 1 AND request_id = ?
             LIMIT 1",
            [(string) ($reservation['request_id'] ?? '')]
        );

        $this->assertTrue((bool) ($reservation['billing_exempt'] ?? false));
        $this->assertSame(0, (int) ($reservation['reserved_tokens'] ?? -1));
        $this->assertNull($reservation['ledger_entry_id'] ?? null);
        $this->assertSame(160, (int) ($settlement['billable_tokens'] ?? 0));
        $this->assertSame((int) ($beforeWallet['token_balance'] ?? 0), (int) ($afterWallet['token_balance'] ?? -1));
        $this->assertSame((int) ($beforeWallet['reserved_tokens'] ?? 0), (int) ($afterWallet['reserved_tokens'] ?? -1));
        $this->assertNotNull($usage);
        $this->assertGreaterThan(0, (int) ($usage['input_tokens'] ?? 0));
        $this->assertGreaterThan(0, (int) ($usage['output_tokens'] ?? 0));
        $this->assertSame(160, (int) ($usage['billable_tokens'] ?? 0));
        $this->assertSame(0.02, (float) ($usage['provider_cost'] ?? 0));
        $this->assertNull($usage['ledger_entry_id'] ?? null);
    }

    public function testRegularUserWithEmptyWalletStillCannotBeginAiRequest(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Regular Empty Wallet Workspace',
            'workspace_slug' => 'regular-empty-wallet-workspace',
            'first_name' => 'Regular',
            'last_name' => 'Owner',
            'email' => 'regular.empty.wallet@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);

        Session::start();
        Session::set('user_id', $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
        (new WorkspaceWalletService())->debitTokens($workspaceId, 50000, 'test_wallet_empty', 'regular-user-empty', $userId, [], 'Empty Compass Free credits for rate limit test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI Credit balance exhausted');

        (new AITokenRateLimiterService())->beginRequest('openai', 'gpt-test', 'regular_user_assist', 'Draft this note.', $userId);
    }

    public function testUsageSummaryPrefersWorkspaceUsageOverLegacyAggregate(): void
    {
        $_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] = 'true';
        $_ENV['AI_DAILY_TOKEN_LIMIT'] = '1000';

        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Scoped Usage Workspace',
            'workspace_slug' => 'scoped-usage-workspace',
            'first_name' => 'Mary',
            'last_name' => 'Jackson',
            'email' => 'mary.jackson@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);

        Session::start();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        $service = new AITokenRateLimiterService();
        $service->recordUsage('openai', 900, 0.09);

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, ?, 'openai', 'gpt-test', 'assistant_chat', ?, 30, 50, 80, 0.016, NOW())",
            [$workspaceId, $userId, 'scoped-usage-' . bin2hex(random_bytes(4))]
        );

        $summary = $service->getUsageSummary();

        $this->assertSame(80, (int) ($summary['used_today'] ?? 0));
        $this->assertSame(920, (int) ($summary['remaining_today'] ?? 0));
        $this->assertFalse((bool) ($summary['is_limited'] ?? true));
    }

    public function testFailRequestReleasesReservationWithoutSettledUsageRow(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Fail Request Workspace',
            'workspace_slug' => 'fail-request-workspace',
            'first_name' => 'Annie',
            'last_name' => 'Easley',
            'email' => 'annie.easley@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        $wallets = new WorkspaceWalletService();
        $wallets->creditTokens($workspaceId, 500, 'manual_adjustment', 'seed-balance', $userId);
        $walletBefore = $wallets->getSummary($workspaceId);
        $availableBefore = (int) ($walletBefore['available_tokens'] ?? 0);

        Session::start();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        $service = new AITokenRateLimiterService();
        $reservation = $service->beginRequest('openai', 'gpt-test', 'reply_suggestion', 'Help me reply to this message.', $userId);
        $service->failRequest($reservation);

        $usage = Database::queryOne(
            "SELECT id
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND request_id = ?
             LIMIT 1",
            [$workspaceId, (string) ($reservation['request_id'] ?? '')]
        );
        $wallet = $wallets->getSummary($workspaceId);

        $this->assertNull($usage);
        $this->assertSame(0, (int) ($wallet['reserved_tokens'] ?? 0));
        $this->assertSame($availableBefore, (int) ($wallet['available_tokens'] ?? 0));
    }

    public function testCompleteRequestDoesNotMirrorLegacyAiUsageByDefault(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'No Legacy Mirror Workspace',
            'workspace_slug' => 'no-legacy-mirror-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace.hopper@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        (new WorkspaceWalletService())->creditTokens($workspaceId, 1000, 'manual_adjustment', 'seed-balance', $userId);

        Session::start();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        unset($_ENV['AI_USAGE_COMPATIBILITY_MIRROR']);

        $legacyUsageCountBefore = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM ai_usage"
        )['c'] ?? 0));

        $service = new AITokenRateLimiterService();
        $reservation = $service->beginRequest('openai', 'gpt-test', 'timeline_summary', 'Summarize this account timeline.', $userId);
        $service->completeRequest($reservation, 'Summary response', 0.01, 90);

        $legacyUsageCountAfter = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM ai_usage"
        )['c'] ?? 0));
        $workspaceUsage = Database::queryOne(
            "SELECT billable_tokens
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND request_id = ?
             LIMIT 1",
            [$workspaceId, (string) ($reservation['request_id'] ?? '')]
        );

        $this->assertSame($legacyUsageCountBefore, $legacyUsageCountAfter);
        $this->assertSame(90, (int) ($workspaceUsage['billable_tokens'] ?? 0));
    }

    public function testCompleteRequestPersistsAiProviderSourceMetadata(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'AI Source Workspace',
            'workspace_slug' => 'ai-source-workspace',
            'first_name' => 'Source',
            'last_name' => 'Owner',
            'email' => 'ai.source@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        (new WorkspaceWalletService())->creditTokens($workspaceId, 1000, 'manual_adjustment', 'source-seed', $userId);

        Session::start();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);

        $service = new AITokenRateLimiterService();
        $reservation = $service->beginRequest(
            'openai',
            'gpt-test',
            'source_metadata',
            'Draft a source-aware note.',
            $userId,
            [
                'provider_source' => 'default_workspace',
                'provider_config_workspace_id' => 1,
                'credential_scope' => 'content_generation',
            ]
        );
        $service->completeRequest($reservation, 'Source-aware response.', 0.01, 100);

        $usage = Database::queryOne(
            "SELECT provider_source, provider_config_workspace_id, credential_scope
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND request_id = ?
             LIMIT 1",
            [$workspaceId, (string) ($reservation['request_id'] ?? '')]
        );

        $this->assertSame('default_workspace', (string) ($usage['provider_source'] ?? ''));
        $this->assertSame(1, (int) ($usage['provider_config_workspace_id'] ?? 0));
        $this->assertSame('content_generation', (string) ($usage['credential_scope'] ?? ''));
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null, 'Expected role ' . $roleSlug . ' to exist.');

        Database::execute("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)",
            [$userId, (int) $role['id'], $userId]
        );
    }
}
