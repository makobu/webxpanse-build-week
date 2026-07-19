<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSampleWorkflowService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceSampleWorkflowServiceTest extends DatabaseTestCase
{
    public function testSampleWorkflowCreatesLinkedRecordsAndStatusLinks(): void
    {
        $provisioned = $this->provisionWorkspace('sample-workflow-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceSampleWorkflowService();
        $result = $service->create($workspaceId, $userId);

        $this->assertTrue((bool) ($result['created'] ?? false));
        $this->assertTrue((bool) ($result['exists'] ?? false));
        $this->assertArrayHasKey('sample_contact', $result['records']);
        $this->assertArrayHasKey('sample_deal', $result['records']);
        $this->assertArrayHasKey('sample_invoice', $result['records']);
        $this->assertArrayHasKey('sample_task', $result['records']);
        $this->assertArrayHasKey('sample_activity', $result['records']);
        $this->assertStringContainsString('contact_view.php?id=', (string) ($result['links']['sample_contact'] ?? ''));

        $contactId = (int) ($result['records']['sample_contact']['record_id'] ?? 0);
        $dealId = (int) ($result['records']['sample_deal']['record_id'] ?? 0);
        $invoiceId = (int) ($result['records']['sample_invoice']['record_id'] ?? 0);
        $taskId = (int) ($result['records']['sample_task']['record_id'] ?? 0);

        $this->assertNotEmpty(Database::queryOne("SELECT id FROM contacts WHERE workspace_id = ? AND id = ?", [$workspaceId, $contactId]));
        $this->assertNotEmpty(Database::queryOne("SELECT id FROM deals WHERE workspace_id = ? AND id = ? AND contact_id = ?", [$workspaceId, $dealId, $contactId]));
        $this->assertNotEmpty(Database::queryOne("SELECT id FROM invoices WHERE workspace_id = ? AND id = ? AND deal_id = ? AND contact_id = ?", [$workspaceId, $invoiceId, $dealId, $contactId]));
        $this->assertNotEmpty(Database::queryOne("SELECT id FROM tasks WHERE workspace_id = ? AND id = ? AND contact_id = ?", [$workspaceId, $taskId, $contactId]));

        $again = $service->create($workspaceId, $userId);
        $this->assertFalse((bool) ($again['created'] ?? true));
        $this->assertCount(5, Database::query("SELECT id FROM workspace_sample_workflow_registry WHERE workspace_id = ?", [$workspaceId]));
    }

    public function testSampleWorkflowRemovalDeletesOnlyRegisteredRecords(): void
    {
        $provisioned = $this->provisionWorkspace('sample-cleanup-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceSampleWorkflowService();
        $created = $service->create($workspaceId, $userId);
        $sampleContactId = (int) ($created['records']['sample_contact']['record_id'] ?? 0);

        $unrelated = (new Contacts())->create([
            'first_name' => 'Real',
            'last_name' => 'Customer',
            'email' => 'real.customer@example.test',
            'lead_source' => 'other',
            'assigned_to' => $userId,
            'created_by' => $userId,
        ]);
        $unrelatedContactId = (int) ($unrelated['id'] ?? 0);

        $removed = $service->remove($workspaceId, $userId);

        $this->assertGreaterThanOrEqual(5, (int) ($removed['deleted'] ?? 0));
        $this->assertEmpty(Database::query("SELECT id FROM workspace_sample_workflow_registry WHERE workspace_id = ?", [$workspaceId]));
        $this->assertEmpty(Database::queryOne("SELECT id FROM contacts WHERE workspace_id = ? AND id = ?", [$workspaceId, $sampleContactId]));
        $this->assertNotEmpty(Database::queryOne("SELECT id FROM contacts WHERE workspace_id = ? AND id = ?", [$workspaceId, $unrelatedContactId]));
        $this->assertFalse((bool) ($service->status($workspaceId)['exists'] ?? true));
    }

    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Sample Workflow ' . uniqid('', true),
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }
}
