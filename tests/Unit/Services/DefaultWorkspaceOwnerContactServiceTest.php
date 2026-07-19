<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceOwnerContactService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceOwnerContactServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $superAdminId = $this->createSuperAdmin('default.helpline.recipient@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
    }

    public function testProvisioningCreatesOwnerContactInDefaultWorkspaceOnly(): void
    {
        $seed = $this->provisionOwner('helpline.future.owner@example.test', 'Helpline Future');

        $contact = $this->ownerContact((int) $seed['user_id']);
        $this->assertNotEmpty($contact);
        $this->assertSame('helpline.future.owner@example.test', (string) ($contact['email'] ?? ''));
        $this->assertSame('Helpline Future', (string) ($contact['company'] ?? ''));
        $this->assertSame('qualified', (string) ($contact['stage'] ?? ''));
        $this->assertSame(75, (int) ($contact['lead_score'] ?? 0));

        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true);
        $this->assertSame('default_workspace_owner_contact', (string) ($metadata['source'] ?? ''));
        $this->assertSame((int) $seed['workspace_id'], (int) ($metadata['owner_workspace_id'] ?? 0));
        $this->assertSame('workspace_owner', (string) ($metadata['relationship'] ?? ''));
        $this->assertFalse((bool) ($metadata['default_workspace_nurture_qualified'] ?? true));
        $this->assertSame('qualified_workspace_lead', (string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $this->assertSame('lead_to_customer_conversion', (string) ($metadata['default_workspace_use'] ?? ''));
        $this->assertFalse((bool) ($metadata['current_paying_customer'] ?? true));
        $this->assertTrue((bool) ($metadata['marketing_conversion_allowed'] ?? false));

        $mapping = Database::queryOne(
            "SELECT relationship_status, customer_state, contact_id
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ? AND owner_user_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $this->assertNotEmpty($mapping);
        $this->assertSame('active', (string) ($mapping['relationship_status'] ?? ''));
        $this->assertSame('qualified_workspace_lead', (string) ($mapping['customer_state'] ?? ''));
        $this->assertSame((int) ($contact['id'] ?? 0), (int) ($mapping['contact_id'] ?? 0));

        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $this->assertNotEmpty($deal);
        $this->assertSame('Helpline Future conversion', (string) ($deal['title'] ?? ''));
        $this->assertSame('qualification', (string) ($deal['stage'] ?? ''));
        $this->assertSame(40, (int) ($deal['probability'] ?? 0));
        $this->assertSame((int) ($contact['id'] ?? 0), (int) ($deal['contact_id'] ?? 0));
        $customFields = json_decode((string) ($deal['custom_fields'] ?? '{}'), true);
        $this->assertTrue((bool) ($customFields['default_workspace_pipeline'] ?? false));
        $this->assertSame((int) $seed['workspace_id'], (int) ($customFields['owner_workspace_id'] ?? 0));
        $this->assertSame('package_active', (string) ($customFields['conversion_signal'] ?? ''));

        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [(int) $seed['user_id']]
        );
        $this->assertEmpty($membership, 'Mirrored owner contacts must not become default workspace members.');

        $notification = Database::queryOne(
            "SELECT type, title, message, entity_type, entity_id, link, is_read
             FROM notifications
             WHERE workspace_id = 1 AND type = 'workspace_created' AND entity_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        $this->assertNotEmpty($notification);
        $this->assertSame('workspace', (string) ($notification['entity_type'] ?? ''));
        $this->assertSame('workspace_admin.php?id=' . (int) $seed['workspace_id'], (string) ($notification['link'] ?? ''));
        $this->assertStringContainsString('New workspace created', (string) ($notification['title'] ?? ''));
        $this->assertStringContainsString('Platform Ops', (string) ($notification['message'] ?? ''));
        $this->assertSame(0, (int) ($notification['is_read'] ?? 1));
    }

    public function testProvisioningReusesUnqualifiedDemoProspectContact(): void
    {
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, phone, lead_source, stage, metadata_json, created_at, updated_at)
             VALUES
                (1, UUID(), 'Demo', 'Prospect', 'demo.prospect.owner@example.test', '+254700000129', 'other', 'new', ?, NOW(), NOW())",
            [json_encode([
                'source' => 'default_workspace_demo_visitor',
                'relationship' => 'demo_visitor',
                'default_workspace_use' => 'demo_email_follow_up',
                'default_workspace_contact_scope' => 'unqualified_demo_prospect',
                'default_workspace_nurture_qualified' => false,
                'prospect_state' => 'unqualified',
                'email_follow_up_allowed' => true,
                'demo_session_uuid' => '88888888-8888-4888-8888-888888888888',
                'captured_at' => '2026-06-23T00:00:00+00:00',
            ], JSON_UNESCAPED_SLASHES)]
        );
        $prospectContactId = (int) Database::lastInsertId();

        $seed = $this->provisionOwner('demo.prospect.owner@example.test', 'Demo Prospect Workspace');
        $contact = $this->ownerContact((int) $seed['user_id']);

        $this->assertSame($prospectContactId, (int) ($contact['id'] ?? 0));
        $this->assertSame('qualified', (string) ($contact['stage'] ?? ''));
        $this->assertSame('Demo Prospect Workspace', (string) ($contact['company'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = 1
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))",
            ['demo.prospect.owner@example.test']
        )['c'] ?? 0));

        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true);
        $this->assertSame('default_workspace_owner_contact', (string) ($metadata['source'] ?? ''));
        $this->assertSame('qualified_workspace_lead', (string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $this->assertTrue((bool) ($metadata['converted_from_demo_prospect'] ?? false));
        $this->assertNotEmpty($metadata['demo_prospect_qualified_at'] ?? null);
        $this->assertSame('88888888-8888-4888-8888-888888888888', (string) ($metadata['demo_prospect_session_uuid'] ?? ''));
        $this->assertTrue((bool) ($metadata['email_follow_up_allowed'] ?? false));

        $mapping = Database::queryOne(
            "SELECT contact_id, customer_state, conversion_deal_id
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ?
               AND owner_user_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        ) ?: [];
        $this->assertSame($prospectContactId, (int) ($mapping['contact_id'] ?? 0));
        $this->assertSame('qualified_workspace_lead', (string) ($mapping['customer_state'] ?? ''));
        $this->assertGreaterThan(0, (int) ($mapping['conversion_deal_id'] ?? 0));
    }

    public function testSyncIsIdempotentAndUpdatesWorkspaceMetadata(): void
    {
        $seed = $this->provisionOwner('helpline.idempotent.owner@example.test', 'Helpline Idempotent');
        $service = new DefaultWorkspaceOwnerContactService();

        $first = $service->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        Database::execute("UPDATE workspaces SET status = 'active', plan_status = 'active' WHERE id = ?", [(int) $seed['workspace_id']]);
        $second = $service->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $this->assertSame('updated', (string) ($first['status'] ?? ''));
        $this->assertSame('updated', (string) ($second['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = 1 AND email = ?",
            ['helpline.idempotent.owner@example.test']
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM notifications WHERE workspace_id = 1 AND type = 'workspace_created' AND entity_type = 'workspace' AND entity_id = ?",
            [(int) $seed['workspace_id']]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM default_workspace_owner_contacts WHERE owner_workspace_id = ? AND owner_user_id = ?",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM deals
             WHERE workspace_id = 1
               AND custom_fields LIKE '%default_workspace_pipeline%'
               AND custom_fields LIKE ?",
            ['%"owner_workspace_id":' . (int) $seed['workspace_id'] . '%']
        )['c'] ?? 0));

        $metadata = json_decode((string) ($this->ownerContact((int) $seed['user_id'])['metadata_json'] ?? '{}'), true);
        $this->assertSame('active', (string) ($metadata['owner_workspace_status'] ?? ''));
        $this->assertSame('active', (string) ($metadata['owner_workspace_plan_status'] ?? ''));
    }

    public function testPaidTenantOwnerQualifiesForDefaultWorkspaceNurture(): void
    {
        $seed = $this->provisionOwner('helpline.active.owner@example.test', 'Helpline Active');
        Database::execute("UPDATE workspaces SET status = 'active', plan_status = 'active' WHERE id = ?", [(int) $seed['workspace_id']]);
        $this->markWorkspacePaid((int) $seed['workspace_id']);

        $result = (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);
        $contact = $this->ownerContact((int) $seed['user_id']);
        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true);

        $this->assertSame('updated', (string) ($result['status'] ?? ''));
        $this->assertSame('won', (string) ($contact['stage'] ?? ''));
        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $this->assertSame('closed_won', (string) ($deal['stage'] ?? ''));
        $this->assertSame(100, (int) ($deal['probability'] ?? 0));
        $this->assertSame('active', (string) ($metadata['owner_workspace_plan_status'] ?? ''));
        $this->assertSame('current_paying_customer', (string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $this->assertTrue((bool) ($metadata['current_paying_customer'] ?? false));
        $this->assertTrue((bool) ($metadata['default_workspace_nurture_qualified'] ?? false));

        $mapping = Database::queryOne(
            "SELECT relationship_status, customer_state
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ? AND owner_user_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $this->assertSame('active', (string) ($mapping['relationship_status'] ?? ''));
        $this->assertSame('current_paying_customer', (string) ($mapping['customer_state'] ?? ''));
    }

    public function testLegacyTrialingWorkspaceStaysPackageActiveInConversionDeal(): void
    {
        $seed = $this->provisionOwner('helpline.proposal.owner@example.test', 'Helpline Proposal');
        $trialEndsAt = date('Y-m-d H:i:s', strtotime('+3 days'));
        Database::execute(
            "UPDATE workspaces SET plan_status = 'trialing', trial_ends_at = ? WHERE id = ?",
            [$trialEndsAt, (int) $seed['workspace_id']]
        );
        Database::execute(
            "UPDATE workspace_subscriptions
             SET subscription_status = 'trialing', trial_ends_at = ?, current_period_end = ?
             WHERE workspace_id = ?",
            [$trialEndsAt, $trialEndsAt, (int) $seed['workspace_id']]
        );

        (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $customFields = json_decode((string) ($deal['custom_fields'] ?? '{}'), true);
        $this->assertSame('qualification', (string) ($deal['stage'] ?? ''));
        $this->assertSame(40, (int) ($deal['probability'] ?? 0));
        $this->assertSame('package_active', (string) ($customFields['conversion_signal'] ?? ''));
    }

    public function testPendingCheckoutMovesConversionDealToNegotiation(): void
    {
        $seed = $this->provisionOwner('helpline.checkout.owner@example.test', 'Helpline Checkout');
        $this->insertSubscriptionCheckout((int) $seed['workspace_id'], (int) $seed['user_id'], 'pending');

        (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $customFields = json_decode((string) ($deal['custom_fields'] ?? '{}'), true);
        $this->assertSame('negotiation', (string) ($deal['stage'] ?? ''));
        $this->assertSame(75, (int) ($deal['probability'] ?? 0));
        $this->assertSame('payment_started', (string) ($customFields['conversion_signal'] ?? ''));
    }

    public function testFailedSubscriptionChargeMovesConversionDealToNegotiation(): void
    {
        $seed = $this->provisionOwner('helpline.failed.owner@example.test', 'Helpline Failed');
        $subscription = Database::queryOne(
            "SELECT id FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        Database::execute("UPDATE workspaces SET plan_status = 'past_due' WHERE id = ?", [(int) $seed['workspace_id']]);
        Database::execute("UPDATE workspace_subscriptions SET subscription_status = 'past_due' WHERE id = ?", [(int) ($subscription['id'] ?? 0)]);
        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, subscription_id, provider, provider_reference, transaction_type, transaction_status, amount, currency)
             VALUES (?, ?, 'test', ?, 'subscription_charge', 'failed', 1000, 'KES')",
            [(int) $seed['workspace_id'], (int) ($subscription['id'] ?? 0), 'failed-test-' . (int) $seed['workspace_id']]
        );

        (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $customFields = json_decode((string) ($deal['custom_fields'] ?? '{}'), true);
        $this->assertSame('negotiation', (string) ($deal['stage'] ?? ''));
        $this->assertSame('payment_failed', (string) ($customFields['conversion_signal'] ?? ''));
    }

    public function testExpiredSubscriptionMovesConversionDealToLost(): void
    {
        $seed = $this->provisionOwner('helpline.expired.owner@example.test', 'Helpline Expired');
        $endedAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        Database::execute(
            "UPDATE workspaces SET plan_status = 'inactive', trial_ends_at = ? WHERE id = ?",
            [$endedAt, (int) $seed['workspace_id']]
        );
        Database::execute(
            "UPDATE workspace_subscriptions
             SET subscription_status = 'expired', trial_ends_at = ?, current_period_end = ?
             WHERE workspace_id = ?",
            [$endedAt, $endedAt, (int) $seed['workspace_id']]
        );

        (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $contact = $this->ownerContact((int) $seed['user_id']);
        $deal = $this->conversionDealForOwner((int) $seed['user_id']);
        $customFields = json_decode((string) ($deal['custom_fields'] ?? '{}'), true);
        $this->assertSame('lost', (string) ($contact['stage'] ?? ''));
        $this->assertSame('closed_lost', (string) ($deal['stage'] ?? ''));
        $this->assertSame(0, (int) ($deal['probability'] ?? -1));
        $this->assertSame('subscription_inactive', (string) ($customFields['conversion_signal'] ?? ''));
    }

    public function testSuspendedTenantOwnerDoesNotSyncIntoDefaultWorkspaceNurture(): void
    {
        $seed = $this->provisionOwner('helpline.suspended.owner@example.test', 'Helpline Suspended');
        Database::execute("DELETE FROM contacts WHERE workspace_id = 1 AND email = ?", ['helpline.suspended.owner@example.test']);
        Database::execute("UPDATE workspaces SET status = 'suspended', plan_status = 'past_due' WHERE id = ?", [(int) $seed['workspace_id']]);

        $result = (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $this->assertSame('skipped', (string) ($result['status'] ?? ''));
        $this->assertSame('workspace_not_active_package', (string) ($result['reason'] ?? ''));
        $this->assertEmpty($this->ownerContact((int) $seed['user_id']));
    }

    public function testSuspendedTenantOwnerMarksExistingMappingInactive(): void
    {
        $seed = $this->provisionOwner('helpline.inactive.owner@example.test', 'Helpline Inactive');
        Database::execute("UPDATE workspaces SET status = 'suspended', plan_status = 'past_due' WHERE id = ?", [(int) $seed['workspace_id']]);

        $result = (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace((int) $seed['workspace_id'], (int) $seed['user_id']);

        $this->assertSame('skipped', (string) ($result['status'] ?? ''));
        $mapping = Database::queryOne(
            "SELECT relationship_status, customer_state
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ? AND owner_user_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );
        $this->assertSame('inactive', (string) ($mapping['relationship_status'] ?? ''));
        $this->assertSame('ineligible', (string) ($mapping['customer_state'] ?? ''));
    }

    public function testSyncSkipsDefaultWorkspaceOwner(): void
    {
        $userId = $this->createUser('default.owner.skip@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, 'owner', true, $userId);

        $result = (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace(1, $userId);

        $this->assertSame('skipped', (string) ($result['status'] ?? ''));
        $this->assertEmpty($this->ownerContact($userId));
    }

    public function testSuperAdminActivationPrefersDefaultWorkspaceButTenantOwnerDoesNot(): void
    {
        $superAdminId = $this->createSuperAdmin('default.activation.superadmin@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        WorkspaceContext::clearRuntimeWorkspace();
        WorkspaceContext::activateForUser($superAdminId);
        $this->assertSame(1, (int) (WorkspaceContext::currentWorkspaceId() ?? 0));

        $seed = $this->provisionOwner('default.activation.owner@example.test', 'Activation Tenant');
        WorkspaceContext::clearRuntimeWorkspace();
        WorkspaceContext::activateForUser((int) $seed['user_id']);
        $this->assertSame((int) $seed['workspace_id'], (int) (WorkspaceContext::currentWorkspaceId() ?? 0));
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

    /**
     * @return array<string,mixed>
     */
    private function ownerContact(int $ownerUserId): array
    {
        return (array) ((new DefaultWorkspaceOwnerContactService())->statusForOwner($ownerUserId) ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    private function conversionDealForOwner(int $ownerUserId): array
    {
        return (array) (Database::queryOne(
            "SELECT d.*
             FROM default_workspace_owner_contacts map
             JOIN deals d ON d.id = map.conversion_deal_id AND d.workspace_id = map.default_workspace_id
             WHERE map.owner_user_id = ?
             LIMIT 1",
            [$ownerUserId]
        ) ?? []);
    }

    private function markWorkspacePaid(int $workspaceId): void
    {
        $subscription = Database::queryOne(
            "SELECT id FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId]
        );
        $subscriptionId = (int) ($subscription['id'] ?? 0);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET subscription_status = 'active',
                 current_period_start = NOW(),
                 current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 next_billing_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
             WHERE id = ?",
            [$subscriptionId]
        );
        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, subscription_id, provider, provider_reference, transaction_type, transaction_status, amount, currency)
             VALUES (?, ?, 'test', ?, 'subscription_charge', 'succeeded', 1000, 'KES')",
            [$workspaceId, $subscriptionId, 'paid-test-' . $workspaceId]
        );
    }

    private function insertSubscriptionCheckout(int $workspaceId, int $userId, string $status): void
    {
        $price = Database::queryOne(
            "SELECT id, amount, currency
             FROM billing_plan_prices
             ORDER BY is_default DESC, id ASC
             LIMIT 1"
        );
        Database::execute(
            "INSERT INTO billing_checkout_sessions
                (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount, billing_plan_price_id, payment_mode, flow_type)
             VALUES
                (?, ?, 'test', 'subscription', ?, ?, ?, ?, ?, 'card', 'redirect')",
            [
                $workspaceId,
                $userId,
                'checkout-test-' . $workspaceId . '-' . $status,
                $status,
                (string) ($price['currency'] ?? 'KES'),
                (float) ($price['amount'] ?? 1000),
                (int) ($price['id'] ?? 1),
            ]
        );
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (UUID(), ?, ?, 'owner', 'Default', 'Owner', NOW())",
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
}
