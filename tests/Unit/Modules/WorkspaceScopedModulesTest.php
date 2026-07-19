<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Forms;
use CRM\Modules\Products;
use CRM\Modules\Tags;
use CRM\Modules\Targets;
use CRM\Services\CampaignOrchestrator;
use CRM\Services\InvoiceProductRecommendationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceScopedModulesTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'module-scope-two', 'Module Scope Two');

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, 'module-scope@example.test', ?, 'admin', NOW())",
            [uniqid('module-scope-user-', true), password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);
        $memberships->addOrUpdateMembership(2, $this->userId, 'owner', true, $this->userId);
    }

    public function testCompanyProfileIsScopedToActiveWorkspace(): void
    {
        $this->activateWorkspace(1);
        (new CompanyProfile())->update(['company_name' => 'Workspace One Ltd']);

        $this->activateWorkspace(2);
        (new CompanyProfile())->update(['company_name' => 'Workspace Two Ltd']);

        $this->activateWorkspace(1);
        $this->assertSame('Workspace One Ltd', (string) ((new CompanyProfile())->get()['company_name'] ?? ''));

        $this->activateWorkspace(2);
        $this->assertSame('Workspace Two Ltd', (string) ((new CompanyProfile())->get()['company_name'] ?? ''));
    }

    public function testProductsAreListedAndFetchedOnlyWithinActiveWorkspace(): void
    {
        $this->activateWorkspace(1);
        $workspaceOneProductId = (new Products())->create(['name' => 'Workspace One Product', 'category' => 'Scope']);

        $this->activateWorkspace(2);
        $workspaceTwoProductId = (new Products())->create(['name' => 'Workspace Two Product', 'category' => 'Scope']);

        $products = new Products();
        $this->assertNull($products->getById($workspaceOneProductId));
        $this->assertSame('Workspace Two Product', (string) ($products->getById($workspaceTwoProductId)['name'] ?? ''));
        $this->assertSame(['Workspace Two Product'], array_column($products->list(), 'name'));

        $this->activateWorkspace(1);
        $products = new Products();
        $this->assertSame('Workspace One Product', (string) ($products->getById($workspaceOneProductId)['name'] ?? ''));
        $this->assertNull($products->getById($workspaceTwoProductId));
        $this->assertSame(['Workspace One Product'], array_column($products->list(), 'name'));
    }

    public function testFormsAreListedFetchedMutatedAndSubmittedOnlyWithinActiveWorkspace(): void
    {
        $this->activateWorkspace(1);
        $workspaceOneFormId = (new Forms())->create(['name' => 'Workspace One Form', 'fields' => []]);
        $workspaceOneForm = (new Forms())->getById($workspaceOneFormId);
        $this->insertFormSubmission(1, (string) $workspaceOneForm['uuid'], $workspaceOneFormId, null);

        $this->activateWorkspace(2);
        $workspaceTwoFormId = (new Forms())->create(['name' => 'Workspace Two Form', 'fields' => []]);
        $workspaceTwoForm = (new Forms())->getById($workspaceTwoFormId);
        $this->insertFormSubmission(2, (string) $workspaceTwoForm['uuid'], $workspaceTwoFormId, null);

        $this->activateWorkspace(1);
        $forms = new Forms();
        $this->assertSame(['Workspace One Form'], array_column($forms->list(), 'name'));
        $this->assertNull($forms->getById($workspaceTwoFormId));
        $this->assertSame([], $forms->getSubmissions($workspaceTwoFormId));
        $this->assertFalse($forms->delete($workspaceTwoFormId));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Form not found');
        $forms->update($workspaceTwoFormId, ['name' => 'Should Not Update']);
    }

    public function testTagsAndAssignmentsAreScopedToActiveWorkspace(): void
    {
        $workspaceOneContactId = $this->insertContact(1, 'Tag One', 'tag-one@example.test');
        $workspaceTwoContactId = $this->insertContact(2, 'Tag Two', 'tag-two@example.test');

        $this->activateWorkspace(1);
        $workspaceOneTagId = (new Tags())->create(['name' => 'Priority', 'created_by' => $this->userId]);
        $this->assertTrue((new Tags())->assign($workspaceOneTagId, 'contact', $workspaceOneContactId));

        $this->activateWorkspace(2);
        $workspaceTwoTagId = (new Tags())->create(['name' => 'Priority', 'created_by' => $this->userId]);
        $this->assertTrue((new Tags())->assign($workspaceTwoTagId, 'contact', $workspaceTwoContactId));

        $this->activateWorkspace(1);
        $tags = new Tags();
        $this->assertSame([$workspaceOneTagId], array_map('intval', array_column($tags->getAll(), 'id')));
        $this->assertNull($tags->getById($workspaceTwoTagId));
        $this->assertSame([$workspaceOneTagId], array_map('intval', array_column($tags->getEntityTags('contact', $workspaceOneContactId), 'id')));
        $this->assertSame([], $tags->getEntityTags('contact', $workspaceTwoContactId));
        $this->assertFalse($tags->delete($workspaceTwoTagId));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tag not found');
        $tags->assign($workspaceTwoTagId, 'contact', $workspaceOneContactId);
    }

    public function testCampaignLifecycleIsScopedToActiveWorkspace(): void
    {
        $workspaceOneContactId = $this->insertContact(1, 'Campaign One', 'campaign-one@example.test');
        $workspaceTwoContactId = $this->insertContact(2, 'Campaign Two', 'campaign-two@example.test');

        $this->activateWorkspace(1);
        $workspaceOneCampaignId = (new CampaignOrchestrator())->createCampaign([
            'name' => 'Workspace One Campaign',
            'steps' => [['step_name' => 'Send', 'action_type' => 'send_email', 'channel' => 'email']],
        ]);

        $this->activateWorkspace(2);
        $workspaceTwoCampaignId = (new CampaignOrchestrator())->createCampaign([
            'name' => 'Workspace Two Campaign',
            'steps' => [['step_name' => 'Send', 'action_type' => 'send_email', 'channel' => 'email']],
        ]);

        $this->activateWorkspace(1);
        $campaigns = new CampaignOrchestrator();
        $this->assertSame(['Workspace One Campaign'], array_column($campaigns->listCampaigns(), 'name'));
        $this->assertNull($campaigns->getCampaignById($workspaceTwoCampaignId));

        $launch = $campaigns->launchCampaign($workspaceOneCampaignId, ['contact_ids' => [$workspaceTwoContactId]]);
        $this->assertSame(0, (int) $launch['audience_count']);
        $this->assertSame(0, (int) $launch['enrollment']['created']);

        $launch = $campaigns->launchCampaign($workspaceOneCampaignId, ['contact_ids' => [$workspaceOneContactId]]);
        $this->assertSame(1, (int) $launch['audience_count']);
        $this->assertSame(1, (int) $launch['enrollment']['created']);

        $this->assertCampaignNotFound(fn() => $campaigns->updateCampaign($workspaceTwoCampaignId, ['name' => 'Nope']));
        $this->assertCampaignNotFound(fn() => $campaigns->pauseCampaign($workspaceTwoCampaignId));
        $this->assertCampaignNotFound(fn() => $campaigns->resumeCampaign($workspaceTwoCampaignId));
        $this->assertCampaignNotFound(fn() => $campaigns->deleteCampaign($workspaceTwoCampaignId));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');
        $campaigns->launchCampaign($workspaceTwoCampaignId);
    }

    public function testProductRecommendationsOnlyUseActiveWorkspaceCatalogAndContext(): void
    {
        $this->activateWorkspace(1);
        $workspaceOneProductId = (new Products())->create([
            'name' => 'Workspace One Advisory',
            'category' => 'Advisory',
            'unit_price' => 100,
        ]);
        $workspaceOneContactId = $this->insertContact(1, 'Advisory Buyer', 'advisory-one@example.test', 'Advisory Co');

        $this->activateWorkspace(2);
        $workspaceTwoProductId = (new Products())->create([
            'name' => 'Workspace Two Advisory',
            'category' => 'Advisory',
            'unit_price' => 200,
        ]);
        $workspaceTwoContactId = $this->insertContact(2, 'Advisory Buyer', 'advisory-two@example.test', 'Advisory Co');

        $this->activateWorkspace(1);
        $recommendations = (new InvoiceProductRecommendationService())->recommend([
            'contact_id' => $workspaceTwoContactId,
        ]);
        $productIds = array_map('intval', array_column($recommendations, 'product_id'));

        $this->assertContains($workspaceOneProductId, $productIds);
        $this->assertNotContains($workspaceTwoProductId, $productIds);
        $this->assertNotContains($workspaceTwoContactId, array_column($recommendations, 'contact_id'));
    }

    public function testTargetsAreListedAndFetchedOnlyWithinActiveWorkspace(): void
    {
        $targetDate = date('Y-m-d', strtotime('+30 days'));

        $this->activateWorkspace(1);
        $workspaceOneTargetId = (new Targets())->create([
            'user_id' => $this->userId,
            'title' => 'Workspace One Target',
            'target_value' => 100,
            'target_date' => $targetDate,
        ]);

        $this->activateWorkspace(2);
        $workspaceTwoTargetId = (new Targets())->create([
            'user_id' => $this->userId,
            'title' => 'Workspace Two Target',
            'target_value' => 200,
            'target_date' => $targetDate,
        ]);

        $targets = new Targets();
        $this->assertNull($targets->getById($workspaceOneTargetId));
        $this->assertSame('Workspace Two Target', (string) ($targets->getById($workspaceTwoTargetId)['title'] ?? ''));
        $this->assertSame(['Workspace Two Target'], array_column($targets->getAll(), 'title'));

        $this->activateWorkspace(1);
        $targets = new Targets();
        $this->assertSame('Workspace One Target', (string) ($targets->getById($workspaceOneTargetId)['title'] ?? ''));
        $this->assertNull($targets->getById($workspaceTwoTargetId));
        $this->assertSame(['Workspace One Target'], array_column($targets->getAll(), 'title'));
    }

    private function activateWorkspace(int $workspaceId): void
    {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $this->userId, 'owner');
    }

    private function insertContact(int $workspaceId, string $name, string $email, string $company = ''): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, created_by, created_at)
             VALUES (?, ?, ?, '', ?, ?, ?, NOW())",
            [$workspaceId, 'module-contact-' . bin2hex(random_bytes(8)), $name, $email, $company, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function insertFormSubmission(int $workspaceId, string $formUuid, int $formId, ?int $contactId): void
    {
        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, form_id, form_definition_id, contact_id, form_data, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$workspaceId, 'visitor-' . bin2hex(random_bytes(4)), $formUuid, $formId, $contactId, json_encode(['email' => 'submit@example.test'])]
        );
    }

    private function assertCampaignNotFound(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected campaign operation to reject foreign workspace campaign.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Campaign not found', $e->getMessage());
        }
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
}
