<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchChecklistService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceLaunchChecklistServiceTest extends DatabaseTestCase
{
    public function testOfferPricingChecklistOpensProductsSettings(): void
    {
        $definitions = array_column((new WorkspaceLaunchChecklistService())->definitions(), null, 'key');

        $this->assertSame('startup_journey.php', (string) ($definitions['complete_clarity_journey']['url'] ?? ''));
        $this->assertSame('settings.php?tab=products', (string) ($definitions['define_offer_pricing']['url'] ?? ''));
    }

    public function testChecklistCreatesExpectedTasksAndDeduplicatesByMetadataKey(): void
    {
        $provisioned = $this->provisionWorkspace('launch-checklist-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceLaunchChecklistService();
        $first = $service->ensureForWorkspace($workspaceId, $userId);
        $second = $service->ensureForWorkspace($workspaceId, $userId);

        $this->assertSame(6, (int) $first['created']);
        $this->assertSame(0, (int) $second['created']);
        $this->assertSame(6, (int) $second['existing']);

        $rows = Database::query(
            "SELECT metadata_json
             FROM tasks
             WHERE workspace_id = ?
               AND metadata_json LIKE ?",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%']
        );
        $this->assertCount(6, $rows);
        $keys = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $keys[] = (string) ($metadata['checklist_key'] ?? '');
        }
        sort($keys);
        $this->assertSame([
            'add_first_customer_list',
            'complete_clarity_journey',
            'connect_inbox',
            'define_offer_pricing',
            'send_first_outreach',
            'start_weekly_review',
        ], $keys);
    }

    public function testCustomerListIsVirtuallyCompletedWhenWorkspaceHasContacts(): void
    {
        $provisioned = $this->provisionWorkspace('launch-checklist-contacts@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceLaunchChecklistService();
        $service->ensureForWorkspace($workspaceId, $userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, stage, assigned_to, created_by, created_at, updated_at)
             VALUES (?, UUID(), 'Ready', 'Lead', 'ready.customer@example.test', 'Ready Co', 'qualified', ?, ?, NOW(), NOW())",
            [$workspaceId, $userId, $userId]
        );

        $summary = $service->summary($workspaceId);
        $customerList = $this->summaryItem($summary, 'add_first_customer_list');
        $this->assertTrue((bool) ($customerList['complete'] ?? false));
        $this->assertTrue((bool) ($customerList['virtual_complete'] ?? false));

        $task = Database::queryOne(
            "SELECT status
             FROM tasks
             WHERE workspace_id = ?
               AND metadata_json LIKE ?
               AND metadata_json LIKE ?
             LIMIT 1",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%', '%add_first_customer_list%']
        );
        $this->assertSame('pending', (string) ($task['status'] ?? ''));
        WorkspaceContext::clear();
    }

    public function testConnectInboxIsVirtuallyCompletedOnlyWhenInboundIsReady(): void
    {
        $provisioned = $this->provisionWorkspace('launch-checklist-email@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceLaunchChecklistService();
        $service->ensureForWorkspace($workspaceId, $userId);

        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', $userId, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => false,
        ], $workspaceId);

        $smtpOnly = $service->summary($workspaceId);
        $smtpOnlyInbox = $this->summaryItem($smtpOnly, 'connect_inbox');
        $this->assertFalse((bool) ($smtpOnlyInbox['complete'] ?? true));

        (new EmailIntegrationService())->storeManualMailIntegrationForRole('outreach', $userId, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.workspace.test',
            'imap_username' => 'outreach@example.test',
            'imap_password' => 'imap-secret',
        ], $workspaceId);

        $ready = $service->summary($workspaceId);
        $readyInbox = $this->summaryItem($ready, 'connect_inbox');
        $this->assertTrue((bool) ($readyInbox['complete'] ?? false));
        $this->assertTrue((bool) ($readyInbox['virtual_complete'] ?? false));

        $task = Database::queryOne(
            "SELECT status
             FROM tasks
             WHERE workspace_id = ?
               AND metadata_json LIKE ?
               AND metadata_json LIKE ?
             LIMIT 1",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%', '%connect_inbox%']
        );
        $this->assertSame('pending', (string) ($task['status'] ?? ''));
        WorkspaceContext::clear();
    }

    private function summaryItem(array $summary, string $key): array
    {
        foreach ((array) ($summary['items'] ?? []) as $item) {
            if ((string) ($item['key'] ?? '') === $key) {
                return $item;
            }
        }

        return [];
    }

    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Launch Checklist ' . uniqid('', true),
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }
}
