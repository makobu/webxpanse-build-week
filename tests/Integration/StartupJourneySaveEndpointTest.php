<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class StartupJourneySaveEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testSaveStageEndpointHandlesDraftCompleteBlockCompleteAndReopen(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();
        $session = $this->webSession($seed, 'owner');

        $draft = $this->postStage($session, [
            'stage_key' => 'customer_discovery',
            'responses' => [
                'target_customer' => 'Founder-led service teams',
            ],
            'notes' => 'Early draft.',
            'completion_intent' => 'draft',
            'save_source' => 'manual',
        ]);

        $this->assertTrue((bool) ($draft['success'] ?? false));
        $this->assertSame('draft', (string) ($draft['stage_status'] ?? ''));
        $this->assertSame('Draft saved.', (string) ($draft['message'] ?? ''));

        $blocked = $this->postStage($session, [
            'stage_key' => 'customer_discovery',
            'responses' => [
                'target_customer' => 'Founders',
                'interview_count' => '',
            ],
            'notes' => 'Thin completion attempt.',
            'completion_intent' => 'complete',
            'save_source' => 'manual',
        ]);

        $this->assertTrue((bool) ($blocked['success'] ?? false));
        $this->assertSame('draft', (string) ($blocked['stage_status'] ?? ''));
        $this->assertFalse((bool) ($blocked['completion_allowed'] ?? true));
        $this->assertStringContainsString('Complete is blocked', (string) ($blocked['completion_message'] ?? ''));
        $this->assertStringContainsString('Next:', (string) ($blocked['completion_message'] ?? ''));

        $readyResponses = $this->readyCustomerDiscoveryResponses();
        $completed = $this->postStage($session, [
            'stage_key' => 'customer_discovery',
            'responses' => $readyResponses,
            'notes' => 'Ready to complete.',
            'completion_intent' => 'complete',
            'save_source' => 'manual',
        ]);

        $this->assertTrue((bool) ($completed['success'] ?? false));
        $this->assertSame('completed', (string) ($completed['stage_status'] ?? ''));
        $this->assertTrue((bool) ($completed['completion_allowed'] ?? false));
        $this->assertSame('Stage completed.', (string) ($completed['completion_message'] ?? ''));
        $this->assertNotSame('', (string) ($completed['completed_at'] ?? ''));
        $this->assertSame(1, (int) (($completed['progress']['completed'] ?? 0)));

        $reopened = $this->postStage($session, [
            'stage_key' => 'customer_discovery',
            'responses' => $readyResponses,
            'notes' => 'Reopened for edits.',
            'completion_intent' => 'draft',
            'save_source' => 'manual',
        ]);

        $this->assertTrue((bool) ($reopened['success'] ?? false));
        $this->assertSame('draft', (string) ($reopened['stage_status'] ?? ''));
        $this->assertSame('', (string) ($reopened['completed_at'] ?? ''));
        $this->assertTrue((bool) ($reopened['manual_completion_required'] ?? false));

        $row = Database::queryOne(
            "SELECT status, completed_at, responses_json, notes
             FROM startup_journey_stage_responses
             WHERE workspace_id = ? AND user_id = ? AND stage_key = 'customer_discovery'
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        ) ?: [];
        $this->assertSame('draft', (string) ($row['status'] ?? ''));
        $this->assertNull($row['completed_at'] ?? null);
        $this->assertSame('Reopened for edits.', (string) ($row['notes'] ?? ''));

        $event = Database::queryOne(
            "SELECT event_type
             FROM startup_journey_stage_events
             WHERE workspace_id = ? AND user_id = ? AND stage_key = 'customer_discovery'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        ) ?: [];
        $this->assertSame('stage_reopened', (string) ($event['event_type'] ?? ''));
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postStage(array $session, array $payload): array
    {
        $payload['csrf_token'] = (string) ($session['csrf_token'] ?? '');
        $response = $this->runWebEndpoint('api/startup_journey/save_stage.php', $session, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);

        $body = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertIsArray($body, (string) ($response['body'] ?? ''));

        return $body;
    }

    /**
     * @return array<string,string>
     */
    private function readyCustomerDiscoveryResponses(): array
    {
        return [
            'target_customer' => 'Founder-led service firms with 2 to 10 people who lose warm leads after demos.',
            'interview_count' => '12 customer interviews completed with founders from referrals and LinkedIn outreach.',
            'observed_problem' => 'Customers said follow-up falls apart when no one owns the next deal step after a promising call.',
            'evidence' => 'Interview notes showed 8 of 12 founders had paid leads go stale after quotes or demos.',
            'riskiest_assumption' => 'The biggest assumption is that founders will pay for guided execution before full automation.',
        ];
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedReadyJourneyWorkspace(): array
    {
        $seed = $this->seedWorkspace('startup-journey-save');
        $this->grantRolePermissions('owner', [
            'workspace.skills.view',
            'workspace.skills.manage',
            'finance.view',
            'finance.manage',
        ]);
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'owner', (int) $seed['user_id']);
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        $catalog = new WorkspaceSkillCatalogService();
        $catalog->syncDefinitions();
        $installer = new WorkspaceSkillInstallService($catalog);
        (new WorkspaceFinanceGateService($installer))->saveInitialSetup((int) $seed['workspace_id'], [
            'currency' => 'USD',
            'opening_date' => date('Y-m-d'),
            'opening_cash' => '1000.00',
            'opening_receivables' => '250.00',
            'opening_payables' => '125.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '500.00',
            'opening_equity' => '1625.00',
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                    'opening_owner_draws' => '0.00',
                ],
            ],
        ], (int) $seed['user_id']);
        $installer->install((int) $seed['workspace_id'], WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (int) $seed['user_id'], [
            'source' => 'startup_journey_save_endpoint_test',
        ]);

        return $seed;
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Startup Journey Save ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Journey',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
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
     * @param list<string> $permissionKeys
     */
    private function grantRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 1
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$permissionKey, $roleSlug]
            );
        }
    }

    private function completeOnboarding(int $workspaceId): void
    {
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 completed_at = COALESCE(completed_at, NOW())
             WHERE workspace_id = ?",
            [$workspaceId]
        );
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed, string $role): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('__remember_restore_attempted', true);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', $role);
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], $role);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed, string $role): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'startup-journey-save-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-startup-journey-save',
            '__remember_restore_attempted' => true,
        ];
    }
}
