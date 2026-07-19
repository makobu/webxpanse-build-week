<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class WebhookTestEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testTestEndpointRequiresAuthPermissionAndCsrf(): void
    {
        $guest = $this->runEndpointScript('api/webhook_test.php', [
            'method' => 'POST',
            'post' => ['webhook_id' => 1, 'csrf_token' => 'csrf-webhook-test'],
        ]);

        $limitedUserId = $this->createUser('webhook-test-limited@example.test', 'viewer');
        $limited = $this->runWebEndpoint('api/webhook_test.php', $this->webSession($limitedUserId, 'csrf-webhook-test'), [
            'method' => 'POST',
            'post' => ['webhook_id' => 1, 'csrf_token' => 'csrf-webhook-test'],
        ]);

        $superAdminId = $this->createSuperAdminUser('webhook-test-csrf@example.test');
        $badCsrf = $this->runWebEndpoint('api/webhook_test.php', $this->webSession($superAdminId, 'csrf-webhook-test'), [
            'method' => 'POST',
            'post' => ['webhook_id' => 1, 'csrf_token' => 'wrong-token'],
        ]);

        $this->assertSame(401, (int) ($guest['status'] ?? 0));
        $this->assertSame(403, (int) ($limited['status'] ?? 0), (string) ($limited['body'] ?? ''));
        $this->assertSame(419, (int) ($badCsrf['status'] ?? 0), (string) ($badCsrf['body'] ?? ''));
    }

    public function testTestSendCreatesWebhookTestLog(): void
    {
        $userId = $this->createSuperAdminUser('webhook-test-send@example.test');
        $webhookId = $this->createWebhook($userId);

        $response = $this->runWebEndpoint('api/webhook_test.php', $this->webSession($userId, 'csrf-webhook-test-send'), [
            'method' => 'POST',
            'headers' => ['X-CSRF-Token' => 'csrf-webhook-test-send'],
            'post' => ['webhook_id' => $webhookId],
        ]);
        $payload = $this->decodeJson($response);
        $log = Database::queryOne("SELECT * FROM webhook_logs WHERE webhook_id = ? AND event_type = 'webhook.test' ORDER BY id DESC LIMIT 1", [$webhookId]);
        $loggedPayload = json_decode((string) ($log['payload'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . ' ' . (string) ($response['stderr'] ?? ''));
        $this->assertSame('webhook.test', (string) ($payload['event_type'] ?? ''));
        $this->assertGreaterThan(0, (int) ($payload['log_id'] ?? 0));
        $this->assertSame((int) ($log['id'] ?? 0), (int) ($payload['log_id'] ?? 0));
        $this->assertSame('webhook.test', (string) ($log['event_type'] ?? ''));
        $this->assertTrue((bool) ($loggedPayload['data']['test'] ?? false));
    }

    private function createUser(string $email, string $role): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), ?, ?, ?, NOW())",
            [$email, password_hash('password', PASSWORD_DEFAULT), $role]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, ?, 'active', 0, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active'",
            [$userId, $role]
        );

        return $userId;
    }

    private function createSuperAdminUser(string $email): int
    {
        $userId = $this->createUser($email, 'admin');
        $superAdminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($superAdminRole['id'] ?? 0), $userId);
        Database::execute(
            "UPDATE workspace_memberships
             SET role_slug = 'owner', is_owner = 1
             WHERE workspace_id = 1 AND user_id = ?",
            [$userId]
        );

        return $userId;
    }

    private function createWebhook(int $userId): int
    {
        Database::execute(
            "INSERT INTO webhooks (workspace_id, user_id, name, url, method, secret, events, headers, is_active)
             VALUES (1, ?, 'Endpoint Test Webhook', 'http://127.0.0.1:9/webhook', 'POST', NULL, ?, NULL, 1)",
            [$userId, json_encode(['contact.created'])]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(int $userId, string $csrfToken): array
    {
        return [
            'user_id' => $userId,
            'user_role' => 'admin',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            'csrf_token' => $csrfToken,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJson(array $response): array
    {
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($payload, (string) ($response['body'] ?? '') . ' ' . (string) ($response['stderr'] ?? ''));
        return $payload;
    }
}
