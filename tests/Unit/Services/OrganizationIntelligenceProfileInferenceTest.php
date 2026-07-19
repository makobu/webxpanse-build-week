<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\OrganizationIntelligenceMutationContextService;
use CRM\Services\OrganizationIntelligenceProfileService;
use CRM\Tests\DatabaseTestCase;

class OrganizationIntelligenceProfileInferenceTest extends DatabaseTestCase
{
    public function testAllSixOperatingModelsUseExplicitStructure(): void
    {
        $service = new OrganizationIntelligenceProfileService();

        $solo = $this->workspace('solo');
        $this->member($solo, true);
        $this->assertSame('solo_founder', $service->infer($solo)['model']);

        $multi = $this->workspace('multi');
        $this->member($multi, true);
        $this->member($multi, true);
        $this->assertSame('multi_founder', $service->infer($multi)['model']);

        $founderLed = $this->workspace('founder-led');
        $founder = $this->member($founderLed, true);
        $this->member($founderLed, false);
        foreach (['leadership', 'sales', 'operations'] as $slug) {
            $this->assignFunction($founderLed, $founder, $slug);
        }
        $this->assertSame('founder_led_team', $service->infer($founderLed)['model']);

        $functional = $this->workspace('functional');
        $firstFunctionalOwner = $this->member($functional, false);
        $secondFunctionalOwner = $this->member($functional, false);
        $this->assignFunction($functional, $firstFunctionalOwner, 'sales');
        $this->assignFunction($functional, $secondFunctionalOwner, 'operations');
        $this->assertSame('functional_team', $service->infer($functional)['model']);

        $departmental = $this->workspace('departmental');
        $departmentA = $this->department($departmental, 'alpha');
        $departmentB = $this->department($departmental, 'beta');
        $departmentOwnerA = $this->member($departmental, false, $departmentA);
        $this->member($departmental, false, $departmentA);
        $departmentOwnerB = $this->member($departmental, false, $departmentB);
        $this->member($departmental, false, $departmentB);
        $this->assignFunction($departmental, $departmentOwnerA, 'sales');
        $this->assignFunction($departmental, $departmentOwnerB, 'operations');
        $this->assertSame('departmental_organization', $service->infer($departmental)['model']);

        $scaling = $this->workspace('scaling');
        $scalingOwners = [];
        foreach (['one', 'two', 'three'] as $departmentSlug) {
            $departmentId = $this->department($scaling, $departmentSlug);
            $scalingOwners[] = $this->member($scaling, false, $departmentId);
            $this->member($scaling, false, $departmentId);
            $this->member($scaling, false, $departmentId);
        }
        $this->member($scaling, false);
        foreach (['sales', 'operations', 'marketing'] as $index => $slug) {
            $this->assignFunction($scaling, $scalingOwners[$index], $slug);
        }
        $this->assertSame('scaling_multi_team', $service->infer($scaling)['model']);
    }

    public function testDepartmentalInferenceRequiresOwnershipAcrossDistinctDepartments(): void
    {
        $workspaceId = $this->workspace('not-fake-departmental');
        $departmentA = $this->department($workspaceId, 'a');
        $departmentB = $this->department($workspaceId, 'b');
        $ownerA = $this->member($workspaceId, false, $departmentA);
        $this->member($workspaceId, false, $departmentA);
        $this->member($workspaceId, false, $departmentB);
        $this->member($workspaceId, false, $departmentB);
        $this->assignFunction($workspaceId, $ownerA, 'sales');
        $this->assignFunction($workspaceId, $ownerA, 'operations');

        $inference = (new OrganizationIntelligenceProfileService())->infer($workspaceId);
        $this->assertNotSame('departmental_organization', $inference['model']);
        $this->assertSame(1, $inference['evidence']['owner_department_count']);
    }

    public function testMutationContextIsSignedScopedAndExpires(): void
    {
        $service = new OrganizationIntelligenceMutationContextService();
        $issued = $service->issue(12, 34, 56);
        $payload = $service->verify($issued['token'], 12, 34);
        $this->assertSame(56, $payload['snapshot_id']);
        $this->expectException(\RuntimeException::class);
        $service->verify($issued['token'], 99, 34);
    }

    private function workspace(string $slug): int
    {
        $slug .= '-' . substr(sha1(uniqid('', true)), 0, 8);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, 'active', 'active', NOW(), NOW())",
            [uniqid('oi-workspace-', true), ucwords(str_replace('-', ' ', $slug)), $slug]
        );
        return (int) Database::lastInsertId();
    }

    private function department(int $workspaceId, string $slug): int
    {
        $slug = 'oi-' . $workspaceId . '-' . $slug;
        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, is_active, is_system) VALUES (?, ?, ?, 1, 0)",
            [$workspaceId, strtoupper($slug), $slug]
        );
        return (int) Database::lastInsertId();
    }

    private function member(int $workspaceId, bool $owner, ?int $departmentId = null): int
    {
        $suffix = uniqid((string) $workspaceId . '-', true);
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, department_id, first_name, last_name, created_at)
             VALUES (?, ?, ?, ?, ?, 'OI', 'Fixture', NOW())",
            [uniqid('oi-user-', true), $suffix . '@example.test', password_hash('secret', PASSWORD_DEFAULT), $owner ? 'owner' : 'viewer', $departmentId]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, ?, 'active', ?, NOW())",
            [$workspaceId, $userId, $owner ? 'owner' : 'viewer', $departmentId, $owner ? 1 : 0]
        );
        return $userId;
    }

    private function assignFunction(int $workspaceId, int $userId, string $slug): void
    {
        $function = Database::queryOne('SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = ? LIMIT 1', [$workspaceId, $slug]);
        if (!$function) {
            Database::execute(
                "INSERT INTO organization_functions (workspace_id, name, slug, category, measurement_strength, is_core, is_active)
                 VALUES (?, ?, ?, 'core', 'partial', 1, 1)",
                [$workspaceId, ucfirst($slug), $slug]
            );
            $functionId = (int) Database::lastInsertId();
        } else {
            $functionId = (int) $function['id'];
        }
        Database::execute(
            "INSERT INTO user_function_assignments (workspace_id, user_id, function_id, assignment_type, importance, is_primary)
             VALUES (?, ?, ?, 'owner', 'primary', 1)",
            [$workspaceId, $userId, $functionId]
        );
    }
}
