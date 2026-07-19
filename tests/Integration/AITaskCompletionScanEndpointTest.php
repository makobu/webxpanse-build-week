<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class AITaskCompletionScanEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testGetRequestIsRejectedForCompletionScanEndpoint(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('scan-endpoint-', true), 'scan-endpoint@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );

        $response = $this->runWebEndpoint('api/ai/task-completion-scan.php', [
            'user_id' => $userId,
            'user_email' => 'scan-endpoint@example.com',
            'user_role' => 'admin',
            'csrf_token' => 'scan-endpoint-csrf',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
        ], [
            'method' => 'GET',
            'query' => ['task_id' => 123],
        ]);

        $this->assertSame(405, (int) ($response['status'] ?? 0));
        $body = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertFalse((bool) ($body['success'] ?? true));
        $this->assertSame('Method not allowed', (string) ($body['error'] ?? ''));
    }
}
