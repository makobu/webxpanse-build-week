<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class WorkspaceContextTest extends DatabaseTestCase
{
    public function testRecoversWhenSessionWorkspaceWasDeleted(): void
    {
        $userId = (int) Auth::createUser('workspace-context-recover@example.com', 'P@ssword123!', 'owner', 'Session', 'Owner');
        $staleWorkspaceId = $this->createWorkspace('Deleted Workspace', 'deleted-workspace', $userId);
        $fallbackWorkspaceId = $this->createWorkspace('Fallback Workspace', 'fallback-workspace', $userId);
        $this->addMembership($staleWorkspaceId, $userId);
        $fallbackMembershipId = $this->addMembership($fallbackWorkspaceId, $userId);

        WorkspaceContext::clearRuntimeWorkspace();
        Session::set('user_id', $userId);
        Session::set('active_workspace_id', $staleWorkspaceId);
        Session::set('active_workspace_membership_id', 999999);

        Database::execute('DELETE FROM workspaces WHERE id = ?', [$staleWorkspaceId]);

        $this->assertSame($fallbackWorkspaceId, WorkspaceContext::currentWorkspaceId());
        $this->assertSame($fallbackWorkspaceId, (int) Session::get('active_workspace_id'));
        $this->assertSame($fallbackMembershipId, (int) Session::get('active_workspace_membership_id'));
        $this->assertSame('fallback-workspace', (string) Session::get('active_workspace_slug'));
    }

    public function testRecoversWhenLoggedInSessionHasNoActiveWorkspace(): void
    {
        $userId = (int) Auth::createUser('workspace-context-empty@example.com', 'P@ssword123!', 'owner', 'Empty', 'Owner');
        $workspaceId = $this->createWorkspace('Only Workspace', 'only-workspace', $userId);
        $this->addMembership($workspaceId, $userId);

        WorkspaceContext::clear();
        Session::set('user_id', $userId);

        $this->assertSame($workspaceId, WorkspaceContext::currentWorkspaceId());
        $this->assertSame($workspaceId, (int) Session::get('active_workspace_id'));
    }

    public function testClearsSessionWorkspaceWhenValidationCannotRun(): void
    {
        $userId = (int) Auth::createUser('workspace-context-fail-closed@example.com', 'P@ssword123!', 'owner', 'Closed', 'Owner');
        $workspaceId = $this->createWorkspace('Fail Closed Workspace', 'fail-closed-workspace', $userId);
        $this->addMembership($workspaceId, $userId);

        WorkspaceContext::clear();
        Session::set('user_id', $userId);
        Session::set('active_workspace_id', $workspaceId);
        Session::set('active_workspace_membership_id', 999999);

        Database::execute('RENAME TABLE workspace_memberships TO workspace_memberships_broken_for_test');
        try {
            $this->assertNull(WorkspaceContext::currentWorkspaceId());
            $this->assertNull(Session::get('active_workspace_id'));
        } finally {
            Database::execute('RENAME TABLE workspace_memberships_broken_for_test TO workspace_memberships');
        }
    }

    private function createWorkspace(string $name, string $slug, int $createdBy): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, ?, ?, 'active', 'active', ?)",
            [$this->uuidFor($slug), $name, $slug, $createdBy]
        );

        return (int) Database::lastInsertId();
    }

    private function addMembership(int $workspaceId, int $userId): int
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceId, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function uuidFor(string $value): string
    {
        $hash = md5($value);
        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 16, 3),
            substr($hash, 19, 12)
        );
    }
}
