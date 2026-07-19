<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Services\WorkspaceAIAutoResponderQuietHoursService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class OwnerSettingsAccessTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testOwnerSettingsDefaultShowsOnlyOwnerAllowlistTabs(): void
    {
        $seed = $this->provisionOwner('owner-settings-default');

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Team', $body);
        $this->assertStringContainsString('id="workspace-team"', $body);
        $this->assertStringContainsString('Invite Member', $body);
        $this->assertStringContainsString('Company Profile', $body);
        $this->assertStringContainsString('Products &amp; Services', $body);
        $this->assertStringContainsString('Voice', $body);
        $this->assertStringContainsString('Invoicing', $body);
        $this->assertStringContainsString('Billing', $body);
        $this->assertStringContainsString('AI Auto-Responder', $body);
        $this->assertStringContainsString('Two-Factor Authentication', $body);

        foreach ([
            '<span>General</span>',
            '<span>Page Videos</span>',
            '<span>Email Assistant</span>',
            '<span>AI Services</span>',
            '<span>Platform Admin</span>',
            '<span>Mobile Push</span>',
            '<span>Readiness Capture</span>',
        ] as $hiddenLabel) {
            $this->assertStringNotContainsString($hiddenLabel, $body);
        }
    }

    public function testDefaultWorkspaceSuperAdminCanRenderWorkspaceTeamAndWorkspace2FA(): void
    {
        $userId = (int) Auth::createUser(
            'default-workspace-team-superadmin@example.test',
            'P@ssword123!',
            'admin',
            'Default',
            'Superadmin'
        );
        $this->assertGreaterThan(0, $userId);

        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, 'superadmin', true, $userId);

        $response = $this->runWebEndpoint('public/settings.php', $this->defaultWorkspaceSuperAdminSession($userId, $membershipId), [
            'method' => 'GET',
            'query' => ['tab' => 'workspace_governance'],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Team', $body);
        $this->assertStringContainsString('workspace-governance-overview', $body);
        $this->assertStringContainsString('Invite Member', $body);
        $this->assertStringContainsString('Workspace 2FA', $body);
        $this->assertStringContainsString('Save Workspace 2FA', $body);
        $this->assertStringContainsString('Automation readiness refresh', $body);
        $this->assertStringContainsString('Save Refresh Cadence', $body);
        $this->assertStringContainsString('Every 6 hours', $body);
        $this->assertStringNotContainsString('You do not have permission for this settings section.', $body);

        $products = $this->runWebEndpoint('public/settings.php', $this->defaultWorkspaceSuperAdminSession($userId, $membershipId), [
            'method' => 'GET',
            'query' => ['tab' => 'products'],
        ]);
        $productsBody = (string) ($products['body'] ?? '');

        $this->assertSame(200, (int) ($products['status'] ?? 0), (string) ($products['stderr'] ?? ''));
        $this->assertStringContainsString('Products &amp; Services', $productsBody);
        $this->assertStringContainsString('Manage the products and services your business sells.', $productsBody);
        $this->assertStringNotContainsString('Clarity packages your workspace can use', $productsBody);
        $this->assertStringNotContainsString('data-package-code=', $productsBody);
    }

    public function testOwnerCanSaveAutomationReadinessRefreshCadence(): void
    {
        $seed = $this->provisionOwner('owner-settings-readiness-refresh');
        $workspaceId = (int) $seed['workspace_id'];

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'POST',
            'query' => ['tab' => 'workspace_governance'],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'workspace_governance',
                'workspace_governance_action' => 'update_automation_readiness_refresh',
                'automation_readiness_refresh_cadence' => 'daily',
            ],
        ]);
        $body = (string) ($response['body'] ?? '');
        $workspace = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [];
        $settings = json_decode((string) ($workspace['settings_json'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Automation readiness refresh cadence updated.', $body);
        $this->assertStringContainsString('value="daily" selected', $body);
        $this->assertSame('daily', (string) ($settings['automation_readiness']['refresh_cadence'] ?? ''));
    }

    public function testNonOwnerCannotSaveAutomationReadinessRefreshCadence(): void
    {
        $seed = $this->provisionOwner('owner-settings-readiness-refresh-blocked');
        $workspaceId = (int) $seed['workspace_id'];
        $viewerId = (int) Auth::createUser(
            'owner-settings-readiness-refresh-viewer@example.test',
            'P@ssword123!',
            'viewer',
            'Blocked',
            'Viewer'
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $viewerId]
        );

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSessionForUser($viewerId, $workspaceId, 'viewer'), [
            'method' => 'POST',
            'query' => ['tab' => 'workspace_governance'],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'workspace_governance',
                'workspace_governance_action' => 'update_automation_readiness_refresh',
                'automation_readiness_refresh_cadence' => 'daily',
            ],
        ]);
        $workspace = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [];
        $settings = json_decode((string) ($workspace['settings_json'] ?? '{}'), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('You do not have permission for this settings section.', (string) ($response['body'] ?? ''));
        $this->assertArrayNotHasKey('automation_readiness', $settings);
    }

    public function testOwnerAccessProfileRendersAsSystemProfile(): void
    {
        $userId = (int) Auth::createUser(
            'owner-profile-system-superadmin@example.test',
            'P@ssword123!',
            'admin',
            'Profile',
            'Reviewer'
        );
        $this->assertGreaterThan(0, $userId);

        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        $membershipId = (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, 'superadmin', true, $userId);

        $response = $this->runWebEndpoint('public/roles.php', $this->defaultWorkspaceSuperAdminSession($userId, $membershipId), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertMatchesRegularExpression(
            '/<div class="admin-meta-value">Owner<\/div>[\s\S]*?<span class="admin-status-badge admin-status-badge--info">\s*System\s*<\/span>/',
            $body
        );
    }

    public function testOwnerCanRenderOwnerFacingSettingsTabs(): void
    {
        $seed = $this->provisionOwner('owner-settings-allowed-tabs');

        $billing = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'billing'],
        ]);
        $billingBody = (string) ($billing['body'] ?? '');

        $this->assertSame(200, (int) ($billing['status'] ?? 0), (string) ($billing['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Billing', $billingBody);

        $invoicing = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'invoicing'],
        ]);
        $invoicingBody = (string) ($invoicing['body'] ?? '');

        $this->assertSame(200, (int) ($invoicing['status'] ?? 0), (string) ($invoicing['stderr'] ?? ''));
        $this->assertStringContainsString('Invoice prefix', $invoicingBody);
        $this->assertStringContainsString('Document identity', $invoicingBody);

        $company = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'company'],
        ]);
        $companyBody = (string) ($company['body'] ?? '');

        $this->assertSame(200, (int) ($company['status'] ?? 0), (string) ($company['stderr'] ?? ''));
        $this->assertStringContainsString('Company Name', $companyBody);
        $this->assertStringNotContainsString('Add Product', $companyBody);

        $products = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'products'],
        ]);
        $productsBody = (string) ($products['body'] ?? '');

        $this->assertSame(200, (int) ($products['status'] ?? 0), (string) ($products['stderr'] ?? ''));
        $this->assertStringContainsString('Products &amp; Services', $productsBody);
        $this->assertStringContainsString('Add product or service', $productsBody);
        $this->assertStringContainsString('data-settings-products-ui', $productsBody);
        $this->assertStringContainsString('data-product-image-upload-endpoint', $productsBody);
        $this->assertStringContainsString('data-product-image-uploader', $productsBody);
        $this->assertStringContainsString('data-product-image-url-field', $productsBody);
        $this->assertStringContainsString('Manage the products and services your business sells.', $productsBody);
        $this->assertStringContainsString('placeholder="Product or service"', $productsBody);
        $this->assertStringNotContainsString('Clarity packages your workspace can use', $productsBody);
        $this->assertStringNotContainsString('System-managed package', $productsBody);
        $this->assertStringNotContainsString('data-package-code=', $productsBody);
        $this->assertStringNotContainsString('Choose or compare package', $productsBody);

        $voice = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'voice'],
        ]);
        $voiceBody = (string) ($voice['body'] ?? '');

        $this->assertSame(200, (int) ($voice['status'] ?? 0), (string) ($voice['stderr'] ?? ''));
        $this->assertStringContainsString('Tone preset', $voiceBody);
        $this->assertStringContainsString('Voice notes', $voiceBody);

        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_location, company_timezone, is_active)
             VALUES (?, 'Owner Quiet Hours Co', 'Nairobi, Kenya', NULL, TRUE)",
            [(int) $seed['workspace_id']]
        );

        $aiAutoResponder = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'GET',
            'query' => ['tab' => 'ai_autoresponder'],
        ]);
        $aiAutoResponderBody = (string) ($aiAutoResponder['body'] ?? '');

        $this->assertSame(200, (int) ($aiAutoResponder['status'] ?? 0), (string) ($aiAutoResponder['stderr'] ?? ''));
        $this->assertStringContainsString('AI Auto-Responder Quiet Hours', $aiAutoResponderBody);
        $this->assertStringContainsString('Enable quiet hours', $aiAutoResponderBody);
        $this->assertStringContainsString('Save Quiet Hours', $aiAutoResponderBody);
        $this->assertStringContainsString('<select name="ai_auto_quiet_timezone"', $aiAutoResponderBody);
        $this->assertStringContainsString('value="Africa/Nairobi" selected', $aiAutoResponderBody);
        $this->assertStringNotContainsString('type="text" name="ai_auto_quiet_timezone"', $aiAutoResponderBody);
        $this->assertStringNotContainsString('name="ai_auto_mode"', $aiAutoResponderBody);
        $this->assertStringNotContainsString('name="ai_auto_email_enabled"', $aiAutoResponderBody);
        $this->assertStringNotContainsString('ai_auto_escalation_keywords', $aiAutoResponderBody);
    }

    public function testOwnerSettingsDuplicatedAndAdminTabsRedirectAway(): void
    {
        $seed = $this->provisionOwner('owner-settings-redirects');
        $settingsSource = (string) file_get_contents(__DIR__ . '/../../public/settings.php');

        foreach ([
            'email' => ['workspace_skills.php?module=email'],
            'general' => [
                "\$ownerSettingsDefaultTab = 'workspace_governance'",
                "settings.php?tab=' . \$ownerSettingsDefaultTab",
            ],
        ] as $tab => $sourceChecks) {
            $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
                'method' => 'GET',
                'query' => ['tab' => $tab],
            ]);

            $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            foreach ($sourceChecks as $sourceCheck) {
                $this->assertStringContainsString($sourceCheck, $settingsSource);
            }
        }
    }

    public function testOwnerForgedGeneralSettingsPostDoesNotResetWorkspaceContext(): void
    {
        $seed = $this->provisionOwner('owner-settings-forged-post');
        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, is_active)
             VALUES (?, 'Owner Settings Guard', TRUE)",
            [(int) $seed['workspace_id']]
        );

        $before = $this->workspaceCompanyProfileCount((int) $seed['workspace_id']);
        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'general',
                'danger_action' => 'reset_company_context',
                'danger_confirm_text' => 'RESET CONTEXT',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('You do not have permission for this settings section.', (string) ($response['body'] ?? ''));
        $this->assertSame($before, $this->workspaceCompanyProfileCount((int) $seed['workspace_id']));
    }

    public function testOwnerForgedAiAutoresponderPostOnlyUpdatesWorkspaceQuietHours(): void
    {
        $seed = $this->provisionOwner('owner-settings-ai-quiet-forged-post');
        $workspaceId = (int) $seed['workspace_id'];

        $config = new AIAutoResponderConfig();
        $before = $config->get();

        $response = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'ai_autoresponder',
                'ai_auto_enabled' => '1',
                'ai_auto_mode' => 'full_auto',
                'ai_auto_default_confidence' => '0.10',
                'ai_auto_email_enabled' => '1',
                'ai_auto_email_confidence' => '0.10',
                'ai_auto_email_max_chars' => '9999',
                'ai_auto_escalation_keywords' => 'changed-global-keyword',
                'ai_auto_quiet_hours_enabled' => '1',
                'ai_auto_quiet_start' => '21:00',
                'ai_auto_quiet_end' => '06:00',
                'ai_auto_quiet_timezone' => 'Africa/Nairobi',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));

        $after = $config->get();
        $this->assertSame($before['mode'] ?? null, $after['mode'] ?? null);
        $this->assertSame((bool) ($before['enabled'] ?? false), (bool) ($after['enabled'] ?? false));
        $this->assertSame(
            $before['safety']['escalation_keywords'] ?? [],
            $after['safety']['escalation_keywords'] ?? []
        );
        $this->assertSame(
            (int) ($before['channels']['email']['max_chars'] ?? 0),
            (int) ($after['channels']['email']['max_chars'] ?? 0)
        );

        $quietHours = (new WorkspaceAIAutoResponderQuietHoursService())->get($workspaceId);
        $this->assertTrue((bool) ($quietHours['enabled'] ?? false));
        $this->assertSame('21:00', $quietHours['start'] ?? null);
        $this->assertSame('06:00', $quietHours['end'] ?? null);
        $this->assertSame('Africa/Nairobi', $quietHours['timezone'] ?? null);
    }

    public function testOwnerRoleKeepsWorkspaceAdminProfileWithoutPlatformSettings(): void
    {
        $permissions = Database::query(
            "SELECT p.permission_key
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'owner'
               AND rp.can_access = 1
             ORDER BY p.permission_key"
        );
        $keys = array_map(static fn(array $row): string => (string) $row['permission_key'], $permissions);
        $settingsKeys = array_values(array_filter(
            $keys,
            static fn(string $key): bool => strpos($key, 'settings.') === 0
        ));

        $this->assertGreaterThan(12, count($keys));
        $this->assertContains('admin.users.access', $keys);
        $this->assertContains('admin.users.manage', $keys);
        $this->assertContains('billing.manage', $keys);
        $this->assertContains('finance.manage', $keys);
        $this->assertContains('workspace.skills.manage', $keys);
        $this->assertSame(['settings.invoicing'], $settingsKeys);
        $this->assertNotContains('settings.ai_autoresponder', $keys);
        $this->assertNotContains('platform.settings.manage', $keys);
        $this->assertNotContains('billing.trials.manage', $keys);
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionOwner(string $suffix): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Owner Settings ' . $suffix,
            'workspace_slug' => $suffix,
            'first_name' => 'Owner',
            'last_name' => 'Settings',
            'email' => $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $seed
     * @return array<string,mixed>
     */
    private function workspaceSession(array $seed): array
    {
        $userId = (int) ($seed['user_id'] ?? 0);
        $workspaceId = (int) ($seed['workspace_id'] ?? 0);
        return $this->workspaceSessionForUser($userId, $workspaceId, 'owner');
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSessionForUser(int $userId, int $workspaceId, string $workspaceRole): array
    {
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];
        $workspace = Database::queryOne("SELECT uuid, name, slug FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'owner'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => $workspaceRole,
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultWorkspaceSuperAdminSession(int $userId, int $membershipId): array
    {
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];
        $workspace = Database::queryOne("SELECT uuid, name, slug FROM workspaces WHERE id = 1 LIMIT 1") ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'active_workspace_id' => 1,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? 'default'),
            'active_workspace_name' => (string) ($workspace['name'] ?? 'Default Workspace'),
            'active_workspace_role' => 'superadmin',
            'active_workspace_membership_id' => $membershipId,
            'csrf_token' => 'test-csrf-token',
        ];
    }

    private function workspaceCompanyProfileCount(int $workspaceId): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM company_profile WHERE workspace_id = ?",
            [$workspaceId]
        )['c'] ?? 0);
    }
}
