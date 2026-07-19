<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Events;
use CRM\Services\AIWorkspaceScopeService;
use CRM\Services\WorkflowQueueService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;
use InvalidArgumentException;
use RuntimeException;

class WorkspaceSeparationRegressionTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId;
    private int $workspaceOneContactId;
    private int $workspaceTwoContactId;
    private int $workspaceOneWorkflowId;
    private int $workspaceTwoWorkflowId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'separation-two', 'Separation Two');
        $this->userId = $this->createUser('workspace-separation@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->userId, 'admin', true, $this->userId);

        $this->workspaceOneContactId = $this->insertContact(1, 'Current', 'Contact', 'current-separation@example.test');
        $this->workspaceTwoContactId = $this->insertContact(2, 'Foreign', 'Contact', 'foreign-separation@example.test');
        $this->workspaceOneWorkflowId = $this->insertWorkflow(1, 'Current Workspace Workflow');
        $this->workspaceTwoWorkflowId = $this->insertWorkflow(2, 'Foreign Workspace Workflow');

        $this->activateWorkspaceSession(1);
    }

    public function testWorkflowLoadReturnsNotFoundForForeignWorkflow(): void
    {
        $response = $this->runWebEndpoint('api/workflows/load.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['id' => $this->workspaceTwoWorkflowId],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('Workflow not found', (string) ($payload['error'] ?? ''));
    }

    public function testWorkflowQueueRejectsMismatchedWorkflowAndContactWorkspaces(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow and contact belong to different workspaces.');

        (new WorkflowQueueService())->addToQueue(
            $this->workspaceOneWorkflowId,
            $this->workspaceTwoContactId,
            ['contact_id' => $this->workspaceTwoContactId],
            1
        );
    }

    public function testEventCreateRejectsForeignContactAndForeignAssignee(): void
    {
        $events = new Events();

        try {
            $events->create([
                'title' => 'Foreign contact event',
                'start_time' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                'contact_id' => $this->workspaceTwoContactId,
                'assigned_to' => $this->userId,
            ]);
            $this->fail('Expected foreign contact to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('The requested record does not belong to the active workspace.', $e->getMessage());
        }

        $foreignUserId = $this->createUser('foreign-assignee@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(2, $foreignUserId, 'admin', false, $foreignUserId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The selected user is not a member of the active workspace.');

        $events->create([
            'title' => 'Foreign assignee event',
            'start_time' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'contact_id' => $this->workspaceOneContactId,
            'assigned_to' => $foreignUserId,
        ]);
    }

    public function testTaskCreateScopesAssigneeOptionsAndRejectsForeignAssignee(): void
    {
        $currentAssigneeId = $this->createUser('task-current-assignee@example.test');
        $foreignAssigneeId = $this->createUser('task-foreign-assignee@example.test');
        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $currentAssigneeId, 'sales', false, $this->userId);
        $memberships->addOrUpdateMembership(2, $foreignAssigneeId, 'sales', false, $this->userId);

        $page = $this->runWebEndpoint('public/task_create.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($page['body'] ?? '');

        $this->assertSame(200, (int) ($page['status'] ?? 0), (string) ($page['stderr'] ?? ''));
        $this->assertStringContainsString('task-current-assignee@example.test', $body);
        $this->assertStringNotContainsString('task-foreign-assignee@example.test', $body);

        $response = $this->runWebEndpoint('public/task_create.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'title' => 'Foreign assignee should fail',
                'description' => 'Workspace scoped assignee regression',
                'assigned_to' => (string) $foreignAssigneeId,
                'status' => 'pending',
                'priority' => 'medium',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Selected assignee is not valid for task assignment.', (string) ($response['body'] ?? ''));

        $created = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM tasks
             WHERE workspace_id = 1
               AND title = ?
               AND assigned_to = ?",
            ['Foreign assignee should fail', $foreignAssigneeId]
        );
        $this->assertSame(0, (int) ($created['count'] ?? 0));
    }

    public function testContactAssignmentScopesAssigneeOptionsAndRejectsForeignAssignee(): void
    {
        $currentAssigneeId = $this->createUser('contact-current-assignee@example.test');
        $foreignAssigneeId = $this->createUser('contact-foreign-assignee@example.test');
        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $currentAssigneeId, 'sales', false, $this->userId);
        $memberships->addOrUpdateMembership(2, $foreignAssigneeId, 'sales', false, $this->userId);

        $page = $this->runWebEndpoint('public/contacts.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($page['body'] ?? '');

        $this->assertSame(200, (int) ($page['status'] ?? 0), (string) ($page['stderr'] ?? ''));
        $this->assertStringContainsString('contact-current-assignee@example.test', $body);
        $this->assertStringNotContainsString('contact-foreign-assignee@example.test', $body);

        $response = $this->runWebEndpoint('api/contacts_bulk.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'action' => 'bulk_update',
                'contact_ids' => json_encode([$this->workspaceOneContactId]),
                'assigned_to' => (string) $foreignAssigneeId,
            ],
        ]);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(400, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('The selected user is not a member of the active workspace.', (string) ($payload['error'] ?? ''));

        $contactAfterBulk = Database::queryOne(
            'SELECT assigned_to FROM contacts WHERE workspace_id = 1 AND id = ? LIMIT 1',
            [$this->workspaceOneContactId]
        );
        $this->assertNotSame($foreignAssigneeId, (int) ($contactAfterBulk['assigned_to'] ?? 0));

        try {
            (new Contacts())->update($this->workspaceOneContactId, ['assigned_to' => $foreignAssigneeId]);
            $this->fail('Expected foreign contact assignee to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('The selected user is not a member of the active workspace.', $e->getMessage());
        }

        $contactAfterDirectUpdate = Database::queryOne(
            'SELECT assigned_to FROM contacts WHERE workspace_id = 1 AND id = ? LIMIT 1',
            [$this->workspaceOneContactId]
        );
        $this->assertNotSame($foreignAssigneeId, (int) ($contactAfterDirectUpdate['assigned_to'] ?? 0));
    }

    public function testAiWorkspaceScopeRejectsForeignTenantKeys(): void
    {
        $scope = new AIWorkspaceScopeService();

        $this->assertSame(1, $scope->requireWorkspaceId('contact:' . $this->workspaceOneContactId));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The requested workspace is not available to the active user.');
        $scope->requireWorkspaceId('contact:' . $this->workspaceTwoContactId);
    }

    public function testActivityPageDoesNotRenderForeignWorkspaceActivity(): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'note', ?, NOW())",
            [1, $this->workspaceOneContactId, $this->userId, 'Visible current workspace activity']
        );
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'call', ?, NOW())",
            [2, $this->workspaceTwoContactId, $this->userId, 'Hidden foreign workspace activity']
        );
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'note', ?, NOW())",
            [1, $this->workspaceTwoContactId, $this->userId, 'Hidden mismatched contact activity']
        );

        $response = $this->runWebEndpoint('public/activities.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Visible current workspace activity', $body);
        $this->assertStringNotContainsString('Hidden foreign workspace activity', $body);
        $this->assertStringNotContainsString('Hidden mismatched contact activity', $body);
        $this->assertStringContainsString('Showing 1 of 1 activities.', $body);
    }

    public function testActivityPageRendersSystemTypeFilterAndLabel(): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'deal_created', ?, NOW())",
            [1, $this->workspaceOneContactId, $this->userId, 'Deal created from automation']
        );

        $response = $this->runWebEndpoint('public/activities.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['type' => 'deal_created'],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Deal Created', $body);
        $this->assertStringContainsString('Deal created from automation', $body);
        $this->assertStringContainsString('activities_export.php?type=deal_created', $body);
    }

    public function testActivitiesExportDoesNotIncludeForeignWorkspaceActivity(): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'note', ?, NOW())",
            [1, $this->workspaceOneContactId, $this->userId, 'Export visible activity']
        );
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'note', ?, NOW())",
            [2, $this->workspaceTwoContactId, $this->userId, 'Export hidden foreign activity']
        );
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, 'note', ?, NOW())",
            [1, $this->workspaceTwoContactId, $this->userId, 'Export hidden mismatched activity']
        );

        $response = $this->runWebEndpoint('public/activities_export.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['type' => 'note'],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Export visible activity', $body);
        $this->assertStringNotContainsString('Export hidden foreign activity', $body);
        $this->assertStringNotContainsString('Export hidden mismatched activity', $body);
    }

    public function testForeignContactIsRejectedByEnrichmentStatusEndpoint(): void
    {
        $response = $this->runWebEndpoint('api/enrichment/status.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['contact_id' => $this->workspaceTwoContactId],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('Contact not found', (string) ($payload['error'] ?? ''));
    }

    public function testForeignContactIsRejectedByEmailTemplatePreviewEndpoint(): void
    {
        $response = $this->runWebEndpoint('api/email_template_preview.php', $this->webSession(), [
            'method' => 'GET',
            'query' => [
                'slug' => 'welcome',
                'contact_id' => $this->workspaceTwoContactId,
            ],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('Contact not found', (string) ($payload['error'] ?? ''));
    }

    public function testForeignCommunicationIsRejectedByWhatsAppMediaEndpoint(): void
    {
        $foreignCommunicationId = $this->insertCommunication(2, $this->workspaceTwoContactId, 'whatsapp', [
            'media_id' => 'foreign-media-id',
        ]);

        $response = $this->runWebEndpoint('api/whatsapp/media.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['communication_id' => $foreignCommunicationId],
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(404, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('Communication not found or not WhatsApp', (string) ($payload['error'] ?? ''));
    }

    public function testActivityCreateAcceptsManualTypesOnly(): void
    {
        $valid = $this->runWebEndpoint('public/activity_create.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'contact_id' => $this->workspaceOneContactId,
                'activity_type' => 'call',
                'description' => 'Manual call logged from test',
            ],
        ]);

        $this->assertContains((int) ($valid['status'] ?? 0), [200, 302], (string) ($valid['stderr'] ?? ''));
        $created = Database::queryOne(
            "SELECT COUNT(*) AS count FROM activities WHERE workspace_id = ? AND contact_id = ? AND activity_type = 'call' AND description = ?",
            [1, $this->workspaceOneContactId, 'Manual call logged from test']
        );
        $this->assertSame(1, (int) ($created['count'] ?? 0));

        $invalid = $this->runWebEndpoint('public/activity_create.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'contact_id' => $this->workspaceOneContactId,
                'activity_type' => 'deal_created',
                'description' => 'System type should not be manual',
            ],
        ]);

        $this->assertSame(200, (int) ($invalid['status'] ?? 0), (string) ($invalid['stderr'] ?? ''));
        $this->assertStringContainsString('Please select a valid manual activity type.', (string) ($invalid['body'] ?? ''));
        $rejected = Database::queryOne(
            "SELECT COUNT(*) AS count FROM activities WHERE workspace_id = ? AND contact_id = ? AND activity_type = 'deal_created' AND description = ?",
            [1, $this->workspaceOneContactId, 'System type should not be manual']
        );
        $this->assertSame(0, (int) ($rejected['count'] ?? 0));
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

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, first_name, email, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, 'admin', NOW())",
            ['wsu-' . bin2hex(random_bytes(8)), strtok($email, '@'), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function insertContact(int $workspaceId, string $firstName, string $lastName, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$workspaceId, 'wsc-' . bin2hex(random_bytes(8)), $firstName, $lastName, $email]
        );

        return (int) Database::lastInsertId();
    }

    private function insertWorkflow(int $workspaceId, string $name): int
    {
        Database::execute(
            "INSERT INTO workflows (workspace_id, name, trigger_config, conditions, actions, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())",
            [$workspaceId, $name, json_encode(['type' => 'contact_created']), json_encode([]), json_encode([])]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function insertCommunication(int $workspaceId, int $contactId, string $channel, array $metadata = []): int
    {
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, body, status, metadata, created_at)
             VALUES (?, ?, ?, ?, 'inbound', 'Media message', 'sent', ?, NOW())",
            [$workspaceId, sprintf('00000000-0000-4000-8000-%012d', random_int(1, 999999999999)), $contactId, $channel, json_encode($metadata)]
        );

        return (int) Database::lastInsertId();
    }

    private function activateWorkspaceSession(int $workspaceId): void
    {
        Session::set('user_id', $this->userId);
        Session::set('active_workspace_id', $workspaceId);
        Session::set('active_workspace_uuid', sprintf('00000000-0000-4000-8000-%012d', $workspaceId));
        Session::set('active_workspace_slug', $workspaceId === 1 ? 'default' : 'separation-two');
        Session::set('active_workspace_name', $workspaceId === 1 ? 'Default Workspace' : 'Separation Two');
        Session::set('active_workspace_role', 'admin');
        Session::set('active_workspace_membership_id', 1);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'workspace-separation-user',
            'user_email' => 'workspace-separation@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'admin',
            'active_workspace_membership_id' => 1,
            'csrf_token' => 'test-csrf-token',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));
        return $decoded;
    }
}
