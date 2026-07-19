<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceServiceTest extends DatabaseTestCase
{
    public function testResolvesIdOneDefaultWorkspace(): void
    {
        $service = new DefaultWorkspaceService();
        $workspace = $service->resolve();

        $this->assertSame(1, (int) ($workspace['id'] ?? 0));
        $this->assertSame('default', (string) ($workspace['slug'] ?? ''));
        $this->assertSame(1, $service->id());
        $this->assertTrue($service->isDefaultWorkspace(1));
    }

    public function testRejectsIdOneWhenSlugDoesNotMatchDefault(): void
    {
        Database::execute("UPDATE workspaces SET slug = 'not-default' WHERE id = 1");
        $service = new DefaultWorkspaceService();

        $this->assertFalse($service->isDefaultWorkspace(1));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected default workspace');
        $service->resolve();
    }

    public function testRejectsDefaultSlugOnNonCanonicalWorkspace(): void
    {
        Database::execute("UPDATE workspaces SET slug = 'legacy-default' WHERE id = 1");
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), 'Wrong Default Workspace', 'default', 'active', 'active', NOW())"
        );
        $workspace = Database::queryOne("SELECT * FROM workspaces WHERE slug = 'default' LIMIT 1") ?: [];

        $this->assertFalse((new DefaultWorkspaceService())->isDefaultWorkspace(null, $workspace));
    }

    public function testHealthReportsRequiredDefaultWorkspaceInvariants(): void
    {
        $health = (new DefaultWorkspaceService())->health();

        $this->assertTrue((bool) ($health['checks']['workspace_exists'] ?? false));
        $this->assertTrue((bool) ($health['checks']['id_one_is_default'] ?? false));
        $this->assertTrue((bool) ($health['checks']['default_slug_unique'] ?? false));
        $this->assertTrue((bool) ($health['checks']['wallet_exists'] ?? false));
    }

    public function testAssertDefaultWorkspaceRejectsNormalWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), 'Other Workspace', 'other-workspace', 'active', 'trialing', NOW())"
        );
        $workspaceId = (int) Database::lastInsertId();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected default workspace');
        (new DefaultWorkspaceService())->assertDefaultWorkspace($workspaceId);
    }
}
