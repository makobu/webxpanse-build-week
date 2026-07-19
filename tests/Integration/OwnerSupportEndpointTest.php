<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\DefaultWorkspaceOwnerSupportService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class OwnerSupportEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $superAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminId = $this->createSuperAdmin('endpoint-owner-support-admin@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->superAdminId, 'superadmin', true, $this->superAdminId);
    }

    public function testOwnerSupportPageLoadsForWorkspaceOwner(): void
    {
        $seed = $this->provisionOwner('endpoint-support-owner@example.test', 'Endpoint Support Owner');
        (new DefaultWorkspaceOwnerSupportService())->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'setup', 'Endpoint setup help', 'Please review setup.');

        $response = $this->runWebEndpoint('public/owner_support.php', $this->ownerSession($seed), [
            'method' => 'GET',
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertStringContainsString('Help Center', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Report a system issue', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Setup help', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Hire an expert', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Endpoint setup help', (string) ($response['body'] ?? ''));
    }

    public function testOwnerCanCreatePaidSetupRequestFromEndpoint(): void
    {
        $seed = $this->provisionOwner('endpoint-support-setup-request@example.test', 'Endpoint Setup Request');
        $offering = Database::queryOne("SELECT id FROM owner_help_offerings WHERE offering_key = 'workspace_setup_audit' LIMIT 1");

        $response = $this->runWebEndpoint('public/owner_support.php', $this->ownerSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'endpoint-owner-support-csrf',
                'action' => 'create_help_request',
                'lane' => 'setup_help',
                'offering_id' => (int) ($offering['id'] ?? 0),
                'subject' => 'Endpoint setup quote',
                'message' => 'Please quote workspace setup.',
                'preferred_time' => 'This week',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $created = Database::queryOne(
            "SELECT r.lane, r.commercial_type, r.pricing_state
             FROM owner_help_service_requests r
             JOIN default_workspace_ops_events e ON e.id = r.ops_event_id
             WHERE e.owner_workspace_id = ? AND e.owner_subject = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], 'Endpoint setup quote']
        );
        $this->assertSame('setup_help', (string) ($created['lane'] ?? ''));
        $this->assertSame('paid_setup', (string) ($created['commercial_type'] ?? ''));
        $this->assertSame('quote_required', (string) ($created['pricing_state'] ?? ''));
    }

    public function testOwnerSupportPrefillsPackageSalesRequest(): void
    {
        $seed = $this->provisionOwner('endpoint-support-sales-prefill@example.test', 'Endpoint Sales Prefill');

        $response = $this->runWebEndpoint('public/owner_support.php', $this->ownerSession($seed), [
            'method' => 'GET',
            'query' => [
                'tab' => 'setup',
                'prefill' => 'package_sales',
                'package_code' => 'scale-custom',
            ],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Contact sales', $body);
        $this->assertStringContainsString('Sales handoff', $body);
        $this->assertStringContainsString('Scale Custom package request', $body);
        $this->assertStringContainsString('Package code: scale-custom', $body);
        $this->assertStringContainsString('name="lane" value="account_manager"', $body);
        $this->assertStringContainsString('Request sales follow-up', $body);
    }

    public function testOwnerCanCreatePackageSalesRequestFromPrefilledForm(): void
    {
        $seed = $this->provisionOwner('endpoint-support-sales-request@example.test', 'Endpoint Sales Request');

        $response = $this->runWebEndpoint('public/owner_support.php', $this->ownerSession($seed), [
            'method' => 'POST',
            'query' => [
                'tab' => 'setup',
                'prefill' => 'package_sales',
                'package_code' => 'scale-custom',
            ],
            'post' => [
                'csrf_token' => 'endpoint-owner-support-csrf',
                'action' => 'create_help_request',
                'lane' => 'account_manager',
                'subject' => 'Scale Custom package request',
                'message' => 'Please contact me about Scale Custom pricing and rollout.',
                'preferred_contact_method' => 'Email',
                'preferred_time' => 'This week',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $created = Database::queryOne(
            "SELECT r.lane, r.commercial_type, r.pricing_state, r.preferred_contact_method, e.owner_subject
             FROM owner_help_service_requests r
             JOIN default_workspace_ops_events e ON e.id = r.ops_event_id
             WHERE e.owner_workspace_id = ? AND e.owner_subject = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], 'Scale Custom package request']
        );

        $this->assertSame('account_manager', (string) ($created['lane'] ?? ''));
        $this->assertSame('paid_expert', (string) ($created['commercial_type'] ?? ''));
        $this->assertSame('quote_required', (string) ($created['pricing_state'] ?? ''));
        $this->assertSame('Email', (string) ($created['preferred_contact_method'] ?? ''));
    }

    public function testOwnerSupportPageBlocksNonOwnerMember(): void
    {
        $seed = $this->provisionOwner('endpoint-support-primary@example.test', 'Endpoint Support Primary');
        $memberId = $this->createUser('endpoint-support-viewer@example.test', 'viewer');
        $this->addWorkspaceMembershipDirect((int) $seed['workspace_id'], $memberId, 'viewer', false);

        $response = $this->runWebEndpoint('public/owner_support.php', $this->memberSession((int) $seed['workspace_id'], $memberId, 'viewer'), [
            'method' => 'GET',
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertStringContainsString('Only active workspace owners', (string) ($response['body'] ?? ''));
    }

    public function testOwnerSupportAdminPageLoadsForDefaultWorkspaceSuperAdmin(): void
    {
        $seed = $this->provisionOwner('endpoint-support-admin-case@example.test', 'Endpoint Support Admin Case');
        $case = (new DefaultWorkspaceOwnerSupportService())->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'billing', 'Endpoint billing help', 'Please review billing.');

        $response = $this->runWebEndpoint('public/owner_support_admin.php', $this->superAdminSession(), [
            'method' => 'GET',
            'query' => ['event_id' => (int) ($case['id'] ?? 0)],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertStringContainsString('Owner Support Admin', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Structured quote', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Triage', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Endpoint billing help', (string) ($response['body'] ?? ''));
    }

    public function testOwnerCanOpenApprovedExpertProfileButNotPendingProfile(): void
    {
        $seed = $this->provisionOwner('endpoint-expert-profile-owner@example.test', 'Endpoint Expert Profile Owner');
        $approvedExpertId = $this->createExpertProfile($this->superAdminId, 'approved');
        $pendingUserId = $this->createUser('endpoint-pending-expert@example.test', 'admin');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $pendingUserId, 'admin', false, $this->superAdminId);
        $pendingExpertId = $this->createExpertProfile($pendingUserId, 'pending');

        $approved = $this->runWebEndpoint('public/owner_help_expert.php', $this->ownerSession($seed), [
            'method' => 'GET',
            'query' => ['id' => $approvedExpertId],
        ]);
        $this->assertSame(200, (int) ($approved['status'] ?? 0), json_encode([
            'headers' => $approved['headers'] ?? [],
            'body' => substr((string) ($approved['body'] ?? ''), 0, 500),
            'stderr' => $approved['stderr'] ?? '',
        ]));
        $this->assertStringContainsString('Request this expert', (string) ($approved['body'] ?? ''));
        $this->assertStringContainsString('Request expert quote', (string) ($approved['body'] ?? ''));

        $pending = $this->runWebEndpoint('public/owner_help_expert.php', $this->ownerSession($seed), [
            'method' => 'GET',
            'query' => ['id' => $pendingExpertId],
        ]);
        $this->assertSame(404, (int) ($pending['status'] ?? 0), (string) ($pending['stderr'] ?? ''));
        $this->assertStringContainsString('This expert profile is not available', (string) ($pending['body'] ?? ''));
    }

    public function testOwnerCanRequestSpecificExpertFromProfileEndpoint(): void
    {
        $seed = $this->provisionOwner('endpoint-expert-request-owner@example.test', 'Endpoint Expert Request Owner');
        $expertId = $this->createExpertProfile($this->superAdminId, 'approved');

        $response = $this->runWebEndpoint('public/owner_help_expert.php', $this->ownerSession($seed), [
            'method' => 'POST',
            'query' => ['id' => $expertId],
            'post' => [
                'csrf_token' => 'endpoint-owner-support-csrf',
                'expert_profile_id' => $expertId,
                'preferred_contact_method' => 'Email',
                'preferred_time' => 'Tomorrow',
                'message' => 'Please help with launch strategy.',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $created = Database::queryOne(
            "SELECT r.lane, r.commercial_type, r.expert_profile_id
             FROM owner_help_service_requests r
             JOIN default_workspace_ops_events e ON e.id = r.ops_event_id
             WHERE e.owner_workspace_id = ? AND r.expert_profile_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], $expertId]
        );
        $this->assertSame('account_manager', (string) ($created['lane'] ?? ''));
        $this->assertSame('paid_expert', (string) ($created['commercial_type'] ?? ''));
        $this->assertSame($expertId, (int) ($created['expert_profile_id'] ?? 0));
    }

    public function testExpertAdminPageLoadsForSuperAdminAndBlocksOwner(): void
    {
        $seed = $this->provisionOwner('endpoint-expert-admin-owner@example.test', 'Endpoint Expert Admin Owner');

        $allowed = $this->runWebEndpoint('public/owner_help_experts_admin.php', $this->superAdminSession(), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($allowed['status'] ?? 0), (string) ($allowed['stderr'] ?? ''));
        $this->assertStringContainsString('Expert Profiles', (string) ($allowed['body'] ?? ''));
        $this->assertStringContainsString('Save profile', (string) ($allowed['body'] ?? ''));

        $blocked = $this->runWebEndpoint('public/owner_help_experts_admin.php', $this->ownerSession($seed), [
            'method' => 'GET',
        ]);
        $this->assertSame(302, (int) ($blocked['status'] ?? 0), (string) ($blocked['stderr'] ?? ''));
    }

    public function testAdminCanCreateStructuredQuoteFromEndpoint(): void
    {
        $seed = $this->provisionOwner('endpoint-quote-owner@example.test', 'Endpoint Quote Owner');
        $case = (new DefaultWorkspaceOwnerSupportService())->createHelpRequest(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'setup_help',
            'Endpoint quote needed',
            'Please quote setup.',
            []
        );

        $response = $this->runWebEndpoint('public/owner_support_admin.php', $this->superAdminSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'endpoint-owner-support-csrf',
                'action' => 'save_quote',
                'event_id' => (int) ($case['id'] ?? 0),
                'quote_submit' => 'send',
                'title' => 'Endpoint setup quote',
                'currency' => 'KES',
                'scope_summary' => 'Endpoint quote scope.',
                'owner_visible_notes' => 'Owner can accept this quote.',
                'terms' => 'Manual payment after acceptance.',
                'quote_item_label' => ['Setup review'],
                'quote_item_description' => ['Review workspace launch readiness.'],
                'quote_item_quantity' => [1],
                'quote_item_unit_price' => [15000],
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $quote = Database::queryOne(
            "SELECT q.status, q.total_amount, r.pricing_state, r.lifecycle_status
             FROM owner_help_quotes q
             JOIN owner_help_service_requests r ON r.id = q.service_request_id
             WHERE q.ops_event_id = ?
             LIMIT 1",
            [(int) ($case['id'] ?? 0)]
        );
        $this->assertSame('sent', (string) ($quote['status'] ?? ''));
        $this->assertSame(15000.0, (float) ($quote['total_amount'] ?? 0));
        $this->assertSame('quoted', (string) ($quote['pricing_state'] ?? ''));
        $this->assertSame('quoted', (string) ($quote['lifecycle_status'] ?? ''));
    }

    public function testOwnerSupportAdminRedirectsNonSuperAdmin(): void
    {
        $seed = $this->provisionOwner('endpoint-support-nonadmin@example.test', 'Endpoint Support Nonadmin');

        $response = $this->runWebEndpoint('public/owner_support_admin.php', $this->ownerSession($seed), [
            'method' => 'GET',
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionOwner(string $email, string $workspaceName): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => $workspaceName,
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function createSuperAdmin(string $email): int
    {
        $userId = $this->createUser($email, 'admin');
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
        return $userId;
    }

    private function createUser(string $email, string $role): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (UUID(), ?, ?, ?, 'Endpoint', 'User', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT), $role]
        );
        return (int) Database::lastInsertId();
    }

    private function addWorkspaceMembershipDirect(int $workspaceId, int $userId, string $roleSlug, bool $isOwner): void
    {
        $existing = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        );

        if ($existing) {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = ?, membership_status = 'active', is_owner = ?, joined_at = COALESCE(joined_at, NOW())
                 WHERE id = ?",
                [$roleSlug, $isOwner ? 1 : 0, (int) ($existing['id'] ?? 0)]
            );
            return;
        }

        Database::execute(
            "INSERT INTO workspace_memberships
                (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, 'active', ?, NOW())",
            [$workspaceId, $userId, $roleSlug, $isOwner ? 1 : 0]
        );
    }

    private function createExpertProfile(int $userId, string $approvalStatus = 'approved'): int
    {
        Database::execute(
            "INSERT INTO owner_help_expert_profiles
                (user_id, role_label, headline, bio, cv_summary, setup_areas_json, industries_json, languages_json, timezone, availability_summary, profile_status, is_internal, approval_status, approved_by, approved_at)
             VALUES (?, 'Account Manager', 'Verified endpoint launch mentor', 'Helps owners with setup and strategy.', 'Internal CRM mentor profile.', JSON_ARRAY('Strategy', 'Setup'), JSON_ARRAY('Startups'), JSON_ARRAY('English'), 'Africa/Nairobi', 'Available by request.', 'active', 1, ?, ?, CASE WHEN ? = 'approved' THEN NOW() ELSE NULL END)
             ON DUPLICATE KEY UPDATE headline = VALUES(headline), profile_status = 'active', approval_status = VALUES(approval_status), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)",
            [$userId, $approvalStatus, $approvalStatus === 'approved' ? $this->superAdminId : null, $approvalStatus]
        );
        $profile = Database::queryOne("SELECT id FROM owner_help_expert_profiles WHERE user_id = ? LIMIT 1", [$userId]);
        $profileId = (int) ($profile['id'] ?? 0);
        Database::execute(
            "INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
             VALUES (?, 'strategy', 'Strategy mentoring', 'system_verified', 'Verified in endpoint test', 10)
             ON DUPLICATE KEY UPDATE verification_level = VALUES(verification_level)",
            [$profileId]
        );
        return $profileId;
    }

    /**
     * @param array<string,mixed> $seed
     * @return array<string,mixed>
     */
    private function ownerSession(array $seed): array
    {
        return $this->workspaceSession((int) $seed['workspace_id'], (int) $seed['user_id'], 'owner');
    }

    /**
     * @return array<string,mixed>
     */
    private function memberSession(int $workspaceId, int $userId, string $role): array
    {
        return $this->workspaceSession($workspaceId, $userId, $role);
    }

    /**
     * @return array<string,mixed>
     */
    private function superAdminSession(): array
    {
        return $this->workspaceSession(1, $this->superAdminId, 'superadmin');
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $workspaceId, int $userId, string $role): array
    {
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?: [];
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
            [$workspaceId, $userId]
        ) ?: [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ('endpoint-user-' . $userId)),
            'user_email' => (string) ($user['email'] ?? 'endpoint@example.test'),
            'user_role' => (string) ($user['role'] ?? 'viewer'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'endpoint-owner-support-csrf',
        ];
    }
}
