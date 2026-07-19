<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceOwnerSupportService;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\OwnerHelpQuoteService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceOwnerSupportServiceTest extends DatabaseTestCase
{
    private int $superAdminId;
    private DefaultWorkspaceOwnerSupportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminId = $this->createSuperAdmin('owner-support-admin@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->superAdminId, 'superadmin', true, $this->superAdminId);
        $this->service = new DefaultWorkspaceOwnerSupportService();
    }

    public function testOwnerCanCreateCaseWithInitialThreadMessage(): void
    {
        $seed = $this->provisionOwner('support.owner@example.test', 'Support Owner Workspace');
        $viewerId = $this->createUser('owner-support-default-viewer@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $viewerId, 'viewer', false, $this->superAdminId);

        $case = $this->service->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'technical', 'Inbox is stuck', 'Email sync has not moved today.');

        $this->assertSame('owner_support_request', (string) ($case['signal_type'] ?? ''));
        $this->assertTrue((bool) ($case['owner_visible'] ?? false));
        $this->assertSame('Inbox is stuck', (string) ($case['owner_subject'] ?? ''));
        $this->assertSame('technical', (string) ($case['owner_category'] ?? ''));
        $this->assertSame('open', (string) ($case['status'] ?? ''));
        $this->assertSame('system_error', (string) ($case['help_request']['lane'] ?? ''));
        $this->assertSame('free', (string) ($case['help_request']['commercial_type'] ?? ''));

        $threadId = (int) ($case['conversation_thread_id'] ?? 0);
        $this->assertGreaterThan(0, $threadId);

        $messages = $this->service->messagesForOwner((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id']);
        $this->assertCount(1, $messages);
        $this->assertSame('inbound', (string) ($messages[0]['direction'] ?? ''));
        $this->assertSame('Email sync has not moved today.', (string) ($messages[0]['body'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications WHERE workspace_id = 1 AND user_id = ? AND type = 'owner_support'",
            [$this->superAdminId]
        )['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications WHERE workspace_id = 1 AND user_id = ? AND type = 'owner_support'",
            [$viewerId]
        )['c'] ?? 0));
    }

    public function testNonOwnerMemberCannotCreateOrViewCases(): void
    {
        $seed = $this->provisionOwner('support.primary.owner@example.test', 'Support Primary Workspace');
        $memberId = $this->createUser('support.member@example.test');
        $this->addWorkspaceMembershipDirect((int) $seed['workspace_id'], $memberId, 'viewer', false);

        $case = $this->service->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'setup', 'Need setup help', 'Please help finish setup.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only active workspace owners');
        $this->service->ownerCase((int) $seed['workspace_id'], $memberId, (int) $case['id']);
    }

    public function testOwnerFromAnotherWorkspaceCannotViewOrReply(): void
    {
        $seed = $this->provisionOwner('support.workspace.one@example.test', 'Support Workspace One');
        $other = $this->provisionOwner('support.workspace.two@example.test', 'Support Workspace Two');
        $case = $this->service->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'billing', 'Billing question', 'Can you check the invoice?');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Support case was not found');
        $this->service->addOwnerReply((int) $other['workspace_id'], (int) $other['user_id'], (int) $case['id'], 'I should not see this.');
    }

    public function testSuperAdminCanReplyAssignAndTransitionCase(): void
    {
        $seed = $this->provisionOwner('support.admin.workflow@example.test', 'Support Admin Workflow');
        $case = $this->service->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'account', 'Change account owner', 'We need help with owner access.');
        $actor = $this->superAdmin();

        $this->service->assignCase($actor, (int) $case['id'], $this->superAdminId);
        $this->service->updatePriority($actor, (int) $case['id'], 'urgent');
        $updated = $this->service->addOperatorReply($actor, (int) $case['id'], 'We are checking this now.');

        $this->assertSame('waiting_on_owner', (string) ($updated['status'] ?? ''));
        $this->assertSame('urgent', (string) ($updated['priority'] ?? ''));
        $this->assertSame($this->superAdminId, (int) ($updated['assigned_user_id'] ?? 0));

        $resolved = $this->service->transitionCase($actor, (int) $case['id'], 'resolved', 'Resolved in test.', 'Owner access updated.');
        $this->assertSame('resolved', (string) ($resolved['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications WHERE workspace_id = ? AND user_id = ? AND type = 'owner_support'",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        )['c'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM operator_audit_log WHERE action_type LIKE 'owner_support_%'"
        )['c'] ?? 0));
    }

    public function testOwnerReplyToResolvedCaseReopensIt(): void
    {
        $seed = $this->provisionOwner('support.reopen@example.test', 'Support Reopen Workspace');
        $case = $this->service->createCase((int) $seed['workspace_id'], (int) $seed['user_id'], 'other', 'Still unclear', 'First message.');
        $this->service->transitionCase($this->superAdmin(), (int) $case['id'], 'resolved', 'Resolved in test.', 'Done.');

        $reopened = $this->service->addOwnerReply((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id'], 'This is still happening.');

        $this->assertSame('open', (string) ($reopened['status'] ?? ''));
        $this->assertSame(2, count($this->service->messagesForOwner((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id'])));
    }

    public function testOwnerCanCreatePaidSetupHelpRequestWithOffering(): void
    {
        $seed = $this->provisionOwner('support.setup.request@example.test', 'Support Setup Help Workspace');
        $offering = Database::queryOne("SELECT id, label FROM owner_help_offerings WHERE offering_key = 'workspace_setup_audit' LIMIT 1");
        $this->assertIsArray($offering);

        $case = $this->service->createHelpRequest(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'setup_help',
            'Please configure the workspace',
            'We need the whole onboarding setup reviewed.',
            ['offering_id' => (int) ($offering['id'] ?? 0), 'preferred_time' => 'This week']
        );

        $this->assertSame('setup_help', (string) ($case['help_request']['lane'] ?? ''));
        $this->assertSame('paid_setup', (string) ($case['help_request']['commercial_type'] ?? ''));
        $this->assertSame('quote_required', (string) ($case['help_request']['pricing_state'] ?? ''));
        $this->assertSame((int) ($offering['id'] ?? 0), (int) ($case['help_request']['offering_id'] ?? 0));
        $this->assertSame('Workspace Setup Audit', (string) ($case['help_request']['offering_label'] ?? ''));
    }

    public function testOwnerCanRequestSpecificInternalExpertAndAdminCanQuote(): void
    {
        $seed = $this->provisionOwner('support.expert.request@example.test', 'Support Expert Help Workspace');
        $expertId = $this->createExpertProfile($this->superAdminId);

        $case = $this->service->createHelpRequest(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'account_manager',
            'Expert help request',
            'We need a human mentor for launch strategy.',
            ['expert_profile_id' => $expertId]
        );

        $this->assertSame('account_manager', (string) ($case['help_request']['lane'] ?? ''));
        $this->assertSame('paid_expert', (string) ($case['help_request']['commercial_type'] ?? ''));
        $this->assertSame($expertId, (int) ($case['help_request']['expert_profile_id'] ?? 0));

        $quoted = $this->service->updateHelpRequest($this->superAdmin(), (int) $case['id'], [
            'lane' => 'account_manager',
            'commercial_type' => 'paid_expert',
            'pricing_state' => 'quoted',
            'lifecycle_status' => 'quoted',
            'expert_profile_id' => $expertId,
            'quote_notes' => 'Two launch strategy sessions quoted.',
        ]);

        $this->assertSame('quoted', (string) ($quoted['help_request']['pricing_state'] ?? ''));
        $this->assertSame('quoted', (string) ($quoted['help_request']['lifecycle_status'] ?? ''));
        $this->assertSame('in_progress', (string) ($quoted['status'] ?? ''));
        $this->assertStringContainsString('Two launch strategy sessions', (string) ($quoted['help_request']['quote_notes'] ?? ''));
    }

    public function testOnlyApprovedExpertsAreVisibleToOwners(): void
    {
        $approvedId = $this->createExpertProfile($this->superAdminId, 'approved');
        $pendingUserId = $this->createUser('owner-support-pending-expert@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $pendingUserId, 'admin', false, $this->superAdminId);
        $pendingId = $this->createExpertProfile($pendingUserId, 'pending');

        $experts = (new OwnerHelpExpertService())->activeExperts();
        $ids = array_map(static fn(array $expert): int => (int) ($expert['id'] ?? 0), $experts);

        $this->assertContains($approvedId, $ids);
        $this->assertNotContains($pendingId, $ids);
        $this->assertNotNull((new OwnerHelpExpertService())->find($approvedId));
        $this->assertNull((new OwnerHelpExpertService())->find($pendingId));
    }

    public function testExpertsWithoutUserAccountsAreNotVisibleToOwners(): void
    {
        Database::execute(
            "INSERT INTO owner_help_expert_profiles
                (user_id, role_label, headline, bio, cv_summary, setup_areas_json, industries_json, languages_json, timezone, availability_summary, profile_status, is_internal, approval_status, approved_by, approved_at)
             VALUES (NULL, 'Account Manager', 'Orphan expert', 'Should not be public.', 'Missing user account.', JSON_ARRAY('Setup'), JSON_ARRAY('Startups'), JSON_ARRAY('English'), 'Africa/Nairobi', 'Unavailable.', 'active', 1, 'approved', ?, NOW())",
            [$this->superAdminId]
        );
        $profileId = (int) Database::lastInsertId();

        $experts = (new OwnerHelpExpertService())->activeExperts();
        $ids = array_map(static fn(array $expert): int => (int) ($expert['id'] ?? 0), $experts);

        $this->assertNotContains($profileId, $ids);
        $this->assertNull((new OwnerHelpExpertService())->find($profileId));
    }

    public function testAdminCanHideAndDeleteUnusedExpertProfile(): void
    {
        $expertId = $this->createExpertProfile($this->superAdminId);
        $expertService = new OwnerHelpExpertService();

        $expertService->updateProfileStatus($expertId, 'hidden');
        $this->assertNull($expertService->find($expertId));

        $expertService->updateProfileStatus($expertId, 'active');
        $this->assertNotNull($expertService->find($expertId));

        $expertService->deleteProfile($expertId);

        $this->assertNull($expertService->adminProfile($expertId));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM owner_help_expert_skills WHERE expert_profile_id = ?",
            [$expertId]
        )['c'] ?? 0));
    }

    public function testUserAccountDeletionCleanupDeletesExpertProfile(): void
    {
        $expertUserId = $this->createUser('owner-support-deleted-expert@example.test');
        $expertId = $this->createExpertProfile($expertUserId);

        (new OwnerHelpExpertService())->deleteProfilesForUsers([$expertUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$expertUserId]);

        $this->assertNull((new OwnerHelpExpertService())->adminProfile($expertId));
    }

    public function testSuperAdminCanCreateSendAndOwnerAcceptStructuredQuote(): void
    {
        $seed = $this->provisionOwner('support.quote.accept@example.test', 'Support Quote Accept Workspace');
        $case = $this->service->createHelpRequest(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'setup_help',
            'Quote me setup',
            'Please quote launch setup.',
            []
        );

        $quoteService = new OwnerHelpQuoteService($this->service);
        $quote = $quoteService->saveDraft($this->superAdmin(), (int) $case['id'], [
            'title' => 'Launch setup quote',
            'scope_summary' => 'Configure core launch setup.',
            'currency' => 'KES',
            'valid_until' => date('Y-m-d', strtotime('+7 days')),
        ], [[
            'item_label' => 'Setup audit',
            'item_description' => 'Review and configure launch blockers.',
            'quantity' => 1,
            'unit_price' => 12000,
        ]]);

        $this->assertSame('draft', (string) ($quote['status'] ?? ''));
        $this->assertSame(12000.0, (float) ($quote['total_amount'] ?? 0));

        $sent = $quoteService->sendQuote($this->superAdmin(), (int) $case['id'], (int) ($quote['id'] ?? 0));
        $this->assertSame('sent', (string) ($sent['status'] ?? ''));

        $ownerQuote = $quoteService->activeQuoteForOwnerCase((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id']);
        $this->assertSame((int) ($quote['id'] ?? 0), (int) ($ownerQuote['id'] ?? 0));

        $accepted = $quoteService->acceptQuote((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id'], (int) ($quote['id'] ?? 0));
        $this->assertSame('accepted', (string) ($accepted['status'] ?? ''));

        $updated = $this->service->ownerCase((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $case['id']);
        $this->assertSame('accepted', (string) ($updated['help_request']['pricing_state'] ?? ''));
        $this->assertSame('assigned', (string) ($updated['help_request']['lifecycle_status'] ?? ''));
        $this->assertSame('in_progress', (string) ($updated['status'] ?? ''));
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

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (UUID(), ?, ?, 'viewer', 'Test', 'User', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        return (int) Database::lastInsertId();
    }

    private function createSuperAdmin(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (UUID(), ?, ?, 'admin', 'Super', 'Admin', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
        return $userId;
    }

    private function createExpertProfile(int $userId, string $approvalStatus = 'approved'): int
    {
        Database::execute(
            "INSERT INTO owner_help_expert_profiles
                (user_id, role_label, headline, bio, cv_summary, setup_areas_json, industries_json, languages_json, timezone, availability_summary, profile_status, is_internal, approval_status, approved_by, approved_at)
             VALUES (?, 'Account Manager', 'Verified internal launch mentor', 'Helps owners with setup and strategy.', 'Internal CRM mentor profile.', JSON_ARRAY('Strategy', 'Setup'), JSON_ARRAY('Startups'), JSON_ARRAY('English'), 'Africa/Nairobi', 'Available by request.', 'active', 1, ?, ?, CASE WHEN ? = 'approved' THEN NOW() ELSE NULL END)
             ON DUPLICATE KEY UPDATE headline = VALUES(headline), profile_status = 'active', approval_status = VALUES(approval_status), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)",
            [$userId, $approvalStatus, $approvalStatus === 'approved' ? $this->superAdminId : null, $approvalStatus]
        );
        $profile = Database::queryOne("SELECT id FROM owner_help_expert_profiles WHERE user_id = ? LIMIT 1", [$userId]);
        $profileId = (int) ($profile['id'] ?? 0);
        Database::execute(
            "INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
             VALUES (?, 'strategy', 'Strategy mentoring', 'system_verified', 'Verified in test', 10)
             ON DUPLICATE KEY UPDATE verification_level = VALUES(verification_level)",
            [$profileId]
        );
        return $profileId;
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

    /**
     * @return array<string,mixed>
     */
    private function superAdmin(): array
    {
        return Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$this->superAdminId]) ?: [];
    }
}
