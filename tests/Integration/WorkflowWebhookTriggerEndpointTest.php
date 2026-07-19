<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Authorization;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class WorkflowWebhookTriggerEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testMissingAndInvalidApiKeyReturnUnauthorized(): void
    {
        $userId = $this->createUser('workflow-trigger-query-key@example.test');
        $validApiKey = $this->createApiKey($userId, 1, ['workflows.trigger']);

        $missing = $this->postTrigger([], [
            'workflow_id' => 1,
            'contact_id' => 1,
        ]);
        $invalid = $this->postTrigger(['Authorization' => 'Bearer crm_invalid'], [
            'workflow_id' => 1,
            'contact_id' => 1,
        ]);
        $queryKey = $this->runEndpointScript('api/webhooks/workflow_trigger.php', [
            'method' => 'POST',
            'query' => ['api_key' => $validApiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'workflow_id' => 1,
                'contact_id' => 1,
            ]) ?: '{}',
        ]);

        $this->assertSame(401, (int) ($missing['status'] ?? 0));
        $this->assertSame(401, (int) ($invalid['status'] ?? 0));
        $this->assertSame(401, (int) ($queryKey['status'] ?? 0));
    }

    public function testApiKeyWithoutWorkflowTriggerPermissionReturnsForbidden(): void
    {
        $userId = $this->createUser('workflow-trigger-forbidden@example.test');
        $apiKey = $this->createApiKey($userId, 1, ['contacts.read']);
        $contactId = $this->createContact(1, 'forbidden-lead@example.test');
        $workflowId = $this->createWorkflow(1, ['type' => 'webhook_received']);

        $response = $this->postTrigger(['Authorization' => 'Bearer ' . $apiKey], [
            'workflow_id' => $workflowId,
            'contact_id' => $contactId,
        ]);
        $payload = $this->decodeJson($response);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertFalse((bool) ($payload['success'] ?? true));
        $this->assertSame('forbidden', (string) ($payload['code'] ?? ''));
    }

    public function testValidWorkflowIdQueuesSameWorkspaceContact(): void
    {
        $userId = $this->createUser('workflow-trigger-id@example.test');
        $apiKey = $this->createApiKey($userId, 1, ['workflows.trigger']);
        $contactId = $this->createContact(1, 'workflow-id-lead@example.test');
        $workflowId = $this->createWorkflow(1, ['type' => 'contact_updated']);

        $response = $this->postTrigger(['Authorization' => 'Bearer ' . $apiKey], [
            'workflow_id' => $workflowId,
            'contact_id' => $contactId,
            'source' => 'landing_page',
            'event' => 'demo_requested',
            'data' => ['budget' => '50000'],
        ]);
        $payload = $this->decodeJson($response);
        $queue = Database::queryOne("SELECT * FROM workflow_queue WHERE id = ?", [(int) ($payload['queue_id'] ?? 0)]);
        $apiLog = Database::queryOne("SELECT response_status FROM api_key_logs WHERE endpoint = ? ORDER BY id DESC LIMIT 1", ['/api/webhooks/workflow_trigger.php']);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . ' ' . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame($workflowId, (int) ($payload['workflow_id'] ?? 0));
        $this->assertSame($contactId, (int) ($payload['contact_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($payload['queue_id'] ?? 0));
        $this->assertSame($workflowId, (int) ($queue['workflow_id'] ?? 0));
        $this->assertSame($contactId, (int) ($queue['contact_id'] ?? 0));
        $this->assertSame(1, (int) ($queue['workspace_id'] ?? 0));
        $this->assertSame(200, (int) ($apiLog['response_status'] ?? 0));
    }

    public function testTriggerKeyResolvesOnlyInsideApiKeyWorkspace(): void
    {
        $otherWorkspaceId = $this->createWorkspace('workflow-trigger-other');
        $userId = $this->createUser('workflow-trigger-key@example.test');
        $apiKey = $this->createApiKey($userId, 1, ['workflows.trigger']);
        $contactId = $this->createContact(1, 'trigger-key-lead@example.test');
        $otherWorkflowId = $this->createWorkflow($otherWorkspaceId, [
            'type' => 'webhook_received',
            'trigger_key' => 'pricing-demo-request',
        ]);

        $notFound = $this->postTrigger(['X-API-Key' => $apiKey], [
            'trigger_key' => 'pricing-demo-request',
            'email' => 'trigger-key-lead@example.test',
        ]);

        $this->assertSame(404, (int) ($notFound['status'] ?? 0), (string) ($notFound['body'] ?? ''));

        $workflowId = $this->createWorkflow(1, [
            'type' => 'api_call',
            'trigger_key' => 'pricing-demo-request',
        ]);
        $ok = $this->postTrigger(['X-API-Key' => $apiKey], [
            'trigger_key' => 'pricing-demo-request',
            'email' => 'trigger-key-lead@example.test',
            'source' => 'typeform',
            'event' => 'demo_requested',
        ]);
        $payload = $this->decodeJson($ok);

        $this->assertSame(200, (int) ($ok['status'] ?? 0), (string) ($ok['body'] ?? ''));
        $this->assertSame($workflowId, (int) ($payload['workflow_id'] ?? 0));
        $this->assertSame($contactId, (int) ($payload['contact_id'] ?? 0));
        $this->assertNotSame($otherWorkflowId, (int) ($payload['workflow_id'] ?? 0));
    }

    public function testDuplicateTriggerKeysReturnConflict(): void
    {
        $userId = $this->createUser('workflow-trigger-duplicate@example.test');
        $apiKey = $this->createApiKey($userId, 1, ['workflows.trigger']);
        $this->createContact(1, 'duplicate-trigger-lead@example.test');
        $this->createWorkflow(1, ['type' => 'webhook_received', 'trigger_key' => 'duplicate-key']);
        $this->createWorkflow(1, ['type' => 'api_call', 'trigger_key' => 'duplicate-key']);

        $response = $this->postTrigger(['Authorization' => 'Bearer ' . $apiKey], [
            'trigger_key' => 'duplicate-key',
            'email' => 'duplicate-trigger-lead@example.test',
        ]);
        $payload = $this->decodeJson($response);

        $this->assertSame(409, (int) ($response['status'] ?? 0));
        $this->assertSame('duplicate_trigger_key', (string) ($payload['code'] ?? ''));
    }

    public function testEmailContactResolutionIsWorkspaceScoped(): void
    {
        $otherWorkspaceId = $this->createWorkspace('workflow-trigger-email-other');
        $userId = $this->createUser('workflow-trigger-email@example.test');
        $apiKey = $this->createApiKey($userId, 1, null);
        $workspaceContactId = $this->createContact(1, 'shared-lead@example.test');
        $otherContactId = $this->createContact($otherWorkspaceId, 'shared-lead@example.test');
        $workflowId = $this->createWorkflow(1, [
            'type' => 'webhook_received',
            'trigger_key' => 'shared-email-key',
        ]);

        $response = $this->postTrigger(['Authorization' => 'Bearer ' . $apiKey], [
            'trigger_key' => 'shared-email-key',
            'email' => 'shared-lead@example.test',
        ]);
        $payload = $this->decodeJson($response);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertSame($workflowId, (int) ($payload['workflow_id'] ?? 0));
        $this->assertSame($workspaceContactId, (int) ($payload['contact_id'] ?? 0));
        $this->assertNotSame($otherContactId, (int) ($payload['contact_id'] ?? 0));
    }

    public function testWorkflowSaveRejectsDuplicateTriggerKeyInWorkspace(): void
    {
        $userId = $this->createSuperAdminUser('workflow-save-trigger-key@example.test');
        $this->createWorkflow(1, [
            'type' => 'webhook_received',
            'trigger_key' => 'existing-save-key',
        ]);

        $response = $this->runWebEndpoint('api/workflows/save.php', $this->webSession($userId, 'csrf-save-trigger-key'), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'name' => 'Duplicate Trigger Key Save',
                'trigger' => [
                    'type' => 'api_call',
                    'trigger_key' => 'existing-save-key',
                ],
                'conditions' => [],
                'actions' => [
                    ['type' => 'add_note', 'note' => 'Duplicate key test'],
                ],
                'is_active' => true,
            ]) ?: '{}',
        ]);
        $payload = $this->decodeJson($response);

        $this->assertSame(409, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertSame('trigger_key_conflict', (string) ($payload['error_code'] ?? ''));
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postTrigger(array $headers, array $payload): array
    {
        return $this->runEndpointScript('api/webhooks/workflow_trigger.php', [
            'method' => 'POST',
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'raw_body' => json_encode($payload) ?: '{}',
        ]);
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), ?, ?, 'admin', NOW())",
            [$email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createSuperAdminUser(string $email): int
    {
        $userId = $this->createUser($email);
        $superAdminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($superAdminRole['id'] ?? 0), $userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active'",
            [$userId]
        );

        return $userId;
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
     * @param array<int,string>|null $permissions
     */
    private function createApiKey(int $userId, int $workspaceId, ?array $permissions): string
    {
        $apiKey = 'crm_' . bin2hex(random_bytes(16));
        Database::execute(
            "INSERT INTO api_keys (workspace_id, user_id, name, key_hash, key_prefix, permissions, rate_limit_per_minute, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 60, 1)",
            [
                $workspaceId,
                $userId,
                'Workflow Trigger Test Key',
                password_hash($apiKey, PASSWORD_DEFAULT),
                substr($apiKey, 0, 10),
                $permissions === null ? null : json_encode($permissions),
            ]
        );

        return $apiKey;
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, workspace_id, first_name, email, created_at)
             VALUES (UUID(), ?, 'Lead', ?, NOW())",
            [$workspaceId, $email]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $trigger
     */
    private function createWorkflow(int $workspaceId, array $trigger): int
    {
        Database::execute(
            "INSERT INTO workflows (workspace_id, name, trigger_config, conditions, actions, is_active)
             VALUES (?, ?, ?, '[]', ?, 1)",
            [
                $workspaceId,
                'Workflow Trigger Test',
                json_encode($trigger),
                json_encode([['type' => 'add_note', 'note' => 'Webhook trigger test']]),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (UUID(), ?, ?, 'active', 'trial', NOW(), NOW())",
            ['Workflow Trigger ' . $slug, $slug . '-' . bin2hex(random_bytes(3))]
        );

        return (int) Database::lastInsertId();
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
