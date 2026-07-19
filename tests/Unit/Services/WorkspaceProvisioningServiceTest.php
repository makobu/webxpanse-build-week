<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Departments;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceProvisioningServiceTest extends DatabaseTestCase
{
    public function testSignupWorkspaceOwnerCreatesWorkspaceMembershipWalletAndCompassFreeSubscription(): void
    {
        $service = new WorkspaceProvisioningService();
        $result = $service->signupWorkspaceOwner([
            'workspace_name' => 'Acme Workspace',
            'workspace_slug' => 'manual-slug-ignored',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);

        $workspace = Database::queryOne("SELECT * FROM workspaces WHERE id = ?", [$workspaceId]);
        $membership = Database::queryOne(
            "SELECT * FROM workspace_memberships WHERE workspace_id = ? AND user_id = ?",
            [$workspaceId, $userId]
        );
        $wallet = Database::queryOne("SELECT * FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $subscription = Database::queryOne(
            "SELECT ws.*, bp.code AS plan_code
             FROM workspace_subscriptions ws
             JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE ws.workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertNotNull($workspace);
        $this->assertSame('acme-workspace', $workspace['slug']);
        $this->assertSame('active', (string) ($workspace['plan_status'] ?? ''));
        $this->assertNull($workspace['trial_starts_at'] ?? null);
        $this->assertNull($workspace['trial_ends_at'] ?? null);
        $this->assertNotNull($membership);
        $this->assertSame('owner', $membership['role_slug']);
        $this->assertSame(1, (int) $membership['is_owner']);
        $this->assertNull($membership['department_id'] ?? null);
        $this->assertNotNull($wallet);
        $this->assertSame(50000, (int) $wallet['token_balance']);
        $this->assertNotNull($subscription);
        $this->assertSame('active', $subscription['subscription_status']);
        $this->assertSame('compass-free', $subscription['plan_code']);
        $this->assertNull($result['checkout']);

        $starterTemplates = Database::query(
            "SELECT name, slug, category, is_active, is_ai_generated, created_by
             FROM email_templates
             WHERE workspace_id = ? AND category = 'workspace_starter'
             ORDER BY name ASC",
            [$workspaceId]
        );
        $this->assertCount(5, $starterTemplates);
        $this->assertContains('First Helpful Note', array_column($starterTemplates, 'name'));
        $this->assertContains('Meeting Recap', array_column($starterTemplates, 'name'));
        foreach ($starterTemplates as $starterTemplate) {
            $this->assertSame(1, (int) ($starterTemplate['is_active'] ?? 0));
            $this->assertSame(1, (int) ($starterTemplate['is_ai_generated'] ?? 0));
            $this->assertSame($userId, (int) ($starterTemplate['created_by'] ?? 0));
            $this->assertStringEndsWith('-' . $workspaceId, (string) ($starterTemplate['slug'] ?? ''));
        }

        $activeFunctionCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM organization_functions
             WHERE workspace_id = ?
               AND is_active = 1
               AND COALESCE(NULLIF(relevance_status, ''), 'active') = 'active'",
            [$workspaceId]
        )['c'] ?? 0);
        $ownerFunctionAssignments = Database::query(
            "SELECT f.slug, ufa.assignment_type, ufa.is_primary
             FROM user_function_assignments ufa
             JOIN organization_functions f
               ON f.id = ufa.function_id
              AND f.workspace_id = ufa.workspace_id
             WHERE ufa.workspace_id = ?
               AND ufa.user_id = ?
             ORDER BY ufa.is_primary DESC, f.slug ASC",
            [$workspaceId, $userId]
        );
        $primarySlugs = array_values(array_map(
            static fn(array $assignment): string => (string) ($assignment['slug'] ?? ''),
            array_filter($ownerFunctionAssignments, static fn(array $assignment): bool => (int) ($assignment['is_primary'] ?? 0) === 1)
        ));

        $this->assertSame($activeFunctionCount, count($ownerFunctionAssignments));
        $this->assertNotEmpty($ownerFunctionAssignments);
        $this->assertSame(['leadership'], $primarySlugs);
        foreach ($ownerFunctionAssignments as $assignment) {
            $this->assertSame('owner', (string) ($assignment['assignment_type'] ?? ''));
        }

        $starterSlugs = array_map(static fn(array $starter): string => (string) $starter['slug'], Departments::STARTER_DEPARTMENTS);
        $starterDepartments = Database::query(
            "SELECT slug, name, is_system
             FROM departments
             WHERE workspace_id = ?
               AND slug IN (" . implode(',', array_fill(0, count($starterSlugs), '?')) . ")
             ORDER BY slug ASC",
            array_merge([$workspaceId], $starterSlugs)
        );
        $starterBySlug = [];
        foreach ($starterDepartments as $department) {
            $starterBySlug[(string) $department['slug']] = $department;
        }

        $this->assertCount(count($starterSlugs), $starterBySlug);
        $this->assertSame('Leadership / Admin', (string) ($starterBySlug['admin']['name'] ?? ''));
        foreach ($starterSlugs as $slug) {
            $this->assertSame(0, (int) ($starterBySlug[$slug]['is_system'] ?? 1));
        }
    }

    public function testSignupIgnoresStarterTokenPackCheckoutRequest(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ignored Starter Checkout Workspace',
            'first_name' => 'No',
            'last_name' => 'Checkout',
            'email' => 'ignored-starter-checkout@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => true,
        ]);
        $workspaceId = (int) ($result['workspace_id'] ?? 0);

        $checkoutCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM billing_checkout_sessions WHERE workspace_id = ?",
            [$workspaceId]
        )['count'] ?? 0);

        $this->assertNull($result['checkout']);
        $this->assertSame(0, $checkoutCount);
    }

    public function testWorkspaceSlugDerivesFromNameAndCollisionsGetSuffix(): void
    {
        $service = new WorkspaceProvisioningService();
        $first = $service->signupWorkspaceOwner([
            'workspace_name' => 'Collision Workspace',
            'workspace_slug' => 'ignored-one',
            'first_name' => 'First',
            'last_name' => 'Owner',
            'email' => 'collision-one@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $second = $service->signupWorkspaceOwner([
            'workspace_name' => 'Collision Workspace',
            'workspace_slug' => 'ignored-two',
            'first_name' => 'Second',
            'last_name' => 'Owner',
            'email' => 'collision-two@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $firstWorkspace = Database::queryOne("SELECT slug FROM workspaces WHERE id = ?", [(int) $first['workspace_id']]);
        $secondWorkspace = Database::queryOne("SELECT slug FROM workspaces WHERE id = ?", [(int) $second['workspace_id']]);

        $this->assertSame('collision-workspace', (string) ($firstWorkspace['slug'] ?? ''));
        $this->assertSame('collision-workspace-2', (string) ($secondWorkspace['slug'] ?? ''));
    }

    public function testDuplicateOwnerEmailShowsActionableError(): void
    {
        $service = new WorkspaceProvisioningService();
        $service->signupWorkspaceOwner([
            'workspace_name' => 'Existing Owner Workspace',
            'first_name' => 'Existing',
            'last_name' => 'Owner',
            'email' => 'existing-owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('An account already exists for this email. Sign in to manage workspaces or use a different owner email.');

        $service->signupWorkspaceOwner([
            'workspace_name' => 'Second Existing Owner Workspace',
            'first_name' => 'Existing',
            'last_name' => 'Owner',
            'email' => 'Existing-Owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    public function testSignupRecoversExistingUserWithoutWorkspaceMembership(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role)
             VALUES (?, 'Old', 'Orphan', 'orphan-owner@example.com', ?, 'viewer')",
            [uniqid('orphan-', true), password_hash('old-password', PASSWORD_DEFAULT)]
        );
        $orphanUserId = (int) Database::lastInsertId();

        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Recovered Orphan Workspace',
            'first_name' => 'Recovered',
            'last_name' => 'Owner',
            'email' => 'Orphan-Owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->assertSame($orphanUserId, (int) ($result['user_id'] ?? 0));

        $user = Database::queryOne("SELECT first_name, last_name, email, role, password_hash FROM users WHERE id = ?", [$orphanUserId]);
        $this->assertSame('Recovered', (string) ($user['first_name'] ?? ''));
        $this->assertSame('Owner', (string) ($user['last_name'] ?? ''));
        $this->assertSame('orphan-owner@example.com', (string) ($user['email'] ?? ''));
        $this->assertSame('owner', (string) ($user['role'] ?? ''));
        $this->assertTrue(password_verify('P@ssword123!', (string) ($user['password_hash'] ?? '')));

        $membership = Database::queryOne(
            "SELECT * FROM workspace_memberships WHERE workspace_id = ? AND user_id = ?",
            [(int) ($result['workspace_id'] ?? 0), $orphanUserId]
        );
        $this->assertNotNull($membership);
        $this->assertSame('owner', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame(1, (int) ($membership['is_owner'] ?? 0));
    }
}
