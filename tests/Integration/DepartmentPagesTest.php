<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class DepartmentPagesTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testCreateDepartmentRedirectsToDepartmentList(): void
    {
        $userId = $this->createAdminUser();
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

        $response = $this->runWebEndpoint('public/department_create.php', $this->webSession($userId), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-department',
                'name' => 'Client Experience',
                'slug' => 'client_experience',
                'description' => 'Post-sale customer team',
                'is_active' => '1',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNotNull(Database::queryOne(
            "SELECT id FROM departments WHERE slug = 'client_experience' AND name = 'Client Experience' LIMIT 1"
        ));

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/department_create.php');
        $this->assertStringContainsString("header('Location: departments.php?success=' . urlencode('Department created.'))", $source);
        $this->assertStringNotContainsString("header('Location: department_edit.php", $source);
    }

    private function createAdminUser(): int
    {
        Database::execute(
            'INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())',
            [
                '00000000-0000-4000-8000-000000000321',
                'department-admin@example.test',
                password_hash('secret', PASSWORD_DEFAULT),
                'admin',
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(int $userId): array
    {
        return [
            'user_id' => $userId,
            'user_uuid' => '00000000-0000-4000-8000-000000000321',
            'user_email' => 'department-admin@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            'csrf_token' => 'csrf-department',
            '__remember_restore_attempted' => true,
        ];
    }
}
