<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class CalendarConnectionConsolidationTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testLegacyCalendarIntegrationsPageRedirectsToCalendarMeetingsCalendarTab(): void
    {
        $seed = $this->seedWorkspace('calendar-legacy-redirect');

        $response = $this->runWebEndpoint('public/calendar_integrations.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'success' => 'updated',
                'error' => 'provider_warning',
            ],
        ]);
        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $source = (string) file_get_contents(__DIR__ . '/../../public/calendar_integrations.php');
        $this->assertStringContainsString('workspace_skills.php?', $source);
        $this->assertStringContainsString('WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS', $source);
        $this->assertStringContainsString("'setup_tab' => 'calendar'", $source);
        $this->assertStringContainsString("'success', 'error', 'calendar_connected', 'calendar_updated'", $source);
        $this->assertStringContainsString("'#setup'", $source);
    }

    public function testCalendarOauthFallbacksTargetCalendarMeetingsCalendarTab(): void
    {
        $seed = $this->seedWorkspace('calendar-oauth-fallbacks', false);

        $invalidProvider = $this->runWebEndpoint('api/calendar/oauth/initiate.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['provider' => 'ical'],
        ]);
        $accessError = $this->runWebEndpoint('api/calendar/oauth/initiate.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => ['provider' => 'google'],
        ]);

        $this->assertSame(302, (int) ($invalidProvider['status'] ?? 0), (string) ($invalidProvider['stderr'] ?? ''));
        $this->assertSame(302, (int) ($accessError['status'] ?? 0), (string) ($accessError['stderr'] ?? ''));
        $source = (string) file_get_contents(__DIR__ . '/../../api/calendar/oauth/initiate.php');
        $this->assertStringContainsString("'module' => 'calendar_meetings'", $source);
        $this->assertStringContainsString("'setup_tab' => 'calendar'", $source);
        $this->assertStringContainsString("'#setup'", $source);
        $this->assertStringNotContainsString("calendar_integrations.php?error", $source);
    }

    public function testCalendarOauthCallbacksTargetCalendarMeetingsCalendarTabWhenMissingCode(): void
    {
        $seed = $this->seedWorkspace('calendar-callback-fallbacks');

        foreach (['api/calendar/google/callback.php', 'api/calendar/outlook/callback.php'] as $endpoint) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession($seed, 'owner'), ['method' => 'GET']);
            $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        }
        foreach (['api/calendar/google/callback.php', 'api/calendar/outlook/callback.php'] as $sourcePath) {
            $source = (string) file_get_contents(__DIR__ . '/../../' . $sourcePath);
            $this->assertStringContainsString("'module' => 'calendar_meetings'", $source);
            $this->assertStringContainsString("'setup_tab' => 'calendar'", $source);
            $this->assertStringContainsString("'#setup'", $source);
            $this->assertStringContainsString("'error' => 'no_code'", $source);
            $this->assertStringNotContainsString("calendar_integrations.php", $source);
        }
    }

    public function testRegularCalendarMeetingsUserCanManageOnlyOwnCalendarConnection(): void
    {
        $seed = $this->seedWorkspace('calendar-regular-user');
        $member = $this->addWorkspaceMember($seed, 'calendar.member@example.test');
        $ownIntegrationId = $this->insertCalendarIntegration($seed, (int) $member['user_id'], 'Member Calendar');
        $otherIntegrationId = $this->insertCalendarIntegration($seed, (int) $seed['user_id'], 'Owner Calendar');

        $get = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($member, 'viewer'), [
            'method' => 'GET',
            'query' => [
                'module' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'setup_tab' => 'calendar',
            ],
        ]);
        $this->assertSame(200, (int) ($get['status'] ?? 0), (string) ($get['stderr'] ?? ''));
        $this->assertStringContainsString('Connected Calendars', (string) ($get['body'] ?? ''));
        $this->assertStringNotContainsString('Access denied', (string) ($get['body'] ?? ''));

        $updateOwn = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($member, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-calendar-connections',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'skill_action' => 'manage_calendar_connection',
                'calendar_action' => 'update_connection',
                'integration_id' => (string) $ownIntegrationId,
                'availability_enabled' => '1',
                'sync_direction' => 'to_crm',
            ],
        ]);
        $own = Database::queryOne("SELECT availability_enabled, sync_enabled, sync_direction FROM calendar_integrations WHERE id = ?", [$ownIntegrationId]);
        $this->assertSame(200, (int) ($updateOwn['status'] ?? 0), (string) ($updateOwn['stderr'] ?? ''));
        $this->assertSame(1, (int) ($own['availability_enabled'] ?? 0));
        $this->assertSame(0, (int) ($own['sync_enabled'] ?? 1));
        $this->assertSame('to_crm', (string) ($own['sync_direction'] ?? ''));

        $updateOther = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($member, 'viewer'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-calendar-connections',
                'skill_key' => WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                'skill_action' => 'manage_calendar_connection',
                'calendar_action' => 'update_connection',
                'integration_id' => (string) $otherIntegrationId,
                'availability_enabled' => '1',
                'sync_enabled' => '1',
                'sync_direction' => 'both',
            ],
        ]);
        $other = Database::queryOne("SELECT availability_enabled, sync_enabled, sync_direction FROM calendar_integrations WHERE id = ?", [$otherIntegrationId]);
        $this->assertSame(200, (int) ($updateOther['status'] ?? 0), (string) ($updateOther['stderr'] ?? ''));
        $this->assertStringContainsString('Calendar connection not found.', (string) ($updateOther['body'] ?? ''));
        $this->assertSame(0, (int) ($other['availability_enabled'] ?? 1));
        $this->assertSame(0, (int) ($other['sync_enabled'] ?? 1));
        $this->assertSame('to_crm', (string) ($other['sync_direction'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix, bool $installCalendarMeetings = true): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Calendar Consolidation ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Calendar',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        (new WorkspaceSkillCatalogService())->syncDefinitions();
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        Authorization::assignUserRoleBySlug($userId, 'owner', $userId);
        if ($installCalendarMeetings) {
            $this->installCalendarMeetings($workspaceId, $userId);
        }

        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
        ];
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function addWorkspaceMember(array $seed, string $email): array
    {
        $userId = (int) Auth::createUser($email, 'P@ssword123!', 'viewer', 'Calendar', 'Member');
        Authorization::assignUserRoleBySlug($userId, 'viewer', (int) $seed['user_id']);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'viewer', 'active', 0, NOW(), ?)
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status)",
            [(int) $seed['workspace_id'], $userId, (int) $seed['user_id']]
        );
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [(int) $seed['workspace_id'], $userId]
        ) ?? [];

        return array_merge($seed, [
            'user_id' => $userId,
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $email,
        ]);
    }

    private function installCalendarMeetings(int $workspaceId, int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at, disabled_at, uninstalled_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [
                $workspaceId,
                WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                $userId,
                $userId,
            ]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function insertCalendarIntegration(array $seed, int $userId, string $name): int
    {
        Database::execute(
            "INSERT INTO calendar_integrations (
                workspace_id, user_id, provider, calendar_id, calendar_name, provider_account_email,
                availability_enabled, sync_enabled, sync_direction, updated_at
             ) VALUES (?, ?, 'google', 'primary', ?, ?, 0, 0, 'to_crm', NOW())",
            [
                (int) $seed['workspace_id'],
                $userId,
                $name,
                'calendar-' . $userId . '@example.test',
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed, string $role): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'calendar-consolidation-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'user',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-calendar-connections',
            '__remember_restore_attempted' => true,
        ];
    }

}
