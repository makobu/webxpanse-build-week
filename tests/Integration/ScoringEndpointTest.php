<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Database;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class ScoringEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int) Auth::createUser(
            'scoring-endpoint@example.test',
            'P@ssword123!',
            'owner',
            'Scoring',
            'Owner'
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status), is_owner = VALUES(is_owner)",
            [$this->userId]
        );
        $this->ensureWorkspace(2, 'scoring-endpoint-two', 'Scoring Endpoint Two');
    }

    public function testCalculateEndpointRecalculatesOnceAndReturnsPersistedBreakdown(): void
    {
        $contactId = $this->createContact(1, 'calculate-endpoint@example.test');
        $this->logActivity(1, $contactId, 'form_submit');

        $response = $this->postJson('api/scoring/calculate.php', [
            'contact_id' => $contactId,
        ]);

        $this->assertSame(200, (int) $response['status'], $response['body']);
        $body = json_decode((string) $response['body'], true);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertSame(20, (int) ($body['consolidated_score'] ?? 0));
        $this->assertSame(20, (int) ($body['breakdown']['consolidated_score'] ?? 0));
        $this->assertFalse((bool) ($body['breakdown']['ml_metadata']['available'] ?? true));

        $stored = Database::queryOne(
            "SELECT lead_score, ml_score FROM contacts WHERE workspace_id = 1 AND id = ?",
            [$contactId]
        );
        $this->assertSame(20, (int) $stored['lead_score']);
        $this->assertNull($stored['ml_score']);
    }

    public function testCalculateEndpointRejectsInvalidWeights(): void
    {
        $contactId = $this->createContact(1, 'invalid-calculate-endpoint@example.test');

        $response = $this->postJson('api/scoring/calculate.php', [
            'contact_id' => $contactId,
            'weights' => ['engagement' => 0.5, 'ml' => 0.5],
        ]);

        $this->assertSame(400, (int) $response['status'], $response['body']);
        $body = json_decode((string) $response['body'], true);
        $this->assertSame('error', $body['status'] ?? null);
    }

    public function testApplyWeightsEndpointRejectsInvalidWeights(): void
    {
        $contactId = $this->createContact(1, 'invalid-apply-endpoint@example.test');

        $response = $this->postJson('api/scoring/apply-weights.php', [
            'contact_id' => $contactId,
            'weights' => ['engagement' => 0.9, 'ml' => 0.9, 'ai' => -0.8],
        ]);

        $this->assertSame(400, (int) $response['status'], $response['body']);
        $body = json_decode((string) $response['body'], true);
        $this->assertSame('error', $body['status'] ?? null);
    }

    public function testScoringEndpointsRejectForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-endpoint@example.test');

        $response = $this->postJson('api/scoring/calculate.php', [
            'contact_id' => $foreignContactId,
        ]);

        $this->assertSame(404, (int) $response['status'], $response['body']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(string $endpoint, array $payload): array
    {
        return $this->runWebEndpoint($endpoint, $this->webSession(), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode($payload),
        ]);
    }

    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            '__remember_restore_attempted' => true,
        ];
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at, updated_at)
             VALUES (?, ?, 'Endpoint', 'Contact', ?, NOW(), NOW())",
            [$workspaceId, uniqid('endpoint-score-contact-', true), $email]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function logActivity(int $workspaceId, int $contactId, string $type): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (?, ?, ?, NOW())",
            [$workspaceId, $contactId, $type]
        );
    }
}
