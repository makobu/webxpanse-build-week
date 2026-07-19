<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class StartupJourneyReportEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testReportEndpointRejectsUnauthenticatedRequests(): void
    {
        $response = $this->runEndpointScript('api/startup_journey/report.php', [
            'method' => 'GET',
        ]);

        $body = $this->decodeJsonResponse($response);
        $this->assertSame(401, (int) ($response['status'] ?? 0));
        $this->assertFalse((bool) ($body['success'] ?? true));
    }

    public function testReportEndpointRejectsInvalidCsrfOnPost(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();

        $response = $this->postReport($this->webSession($seed, 'owner'), [
            'csrf_token' => 'bad-token',
        ]);
        $body = $this->decodeJsonResponse($response);

        $this->assertSame(419, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertFalse((bool) ($body['success'] ?? true));
        $this->assertSame(0, $this->journeyReportArtifactCount((int) $seed['workspace_id']));
    }

    public function testReportEndpointRejectsGenerationWithoutManagePermission(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();
        $viewer = $this->addWorkspaceViewer($seed, 'journey-report-viewer-' . strtolower(bin2hex(random_bytes(3))) . '@example.test');

        $response = $this->postReport($this->webSession($viewer, 'viewer'), [
            'csrf_token' => 'csrf-startup-journey-report',
        ]);
        $body = $this->decodeJsonResponse($response);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertFalse((bool) ($body['success'] ?? true));
    }

    public function testReportEndpointReturnsLatestReportForViewUsers(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();
        $viewer = $this->addWorkspaceViewer($seed, 'journey-report-readonly-' . strtolower(bin2hex(random_bytes(3))) . '@example.test');
        $this->activateSession($viewer, 'viewer');
        $artifactId = $this->insertReportArtifact($viewer);

        $response = $this->runWebEndpoint('api/startup_journey/report.php', $this->webSession($viewer, 'viewer'), [
            'method' => 'GET',
        ]);
        $body = $this->decodeJsonResponse($response);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($body['success'] ?? false));
        $this->assertSame($artifactId, (int) ($body['latest_report']['report_meta']['artifact_id'] ?? 0));
        $this->assertFalse((bool) ($body['availability']['can_generate'] ?? true));
    }

    public function testReportEndpointGeneratesAndPersistsFreshReportForManageUsers(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();

        $response = $this->postReport($this->webSession($seed, 'owner'), [
            'csrf_token' => 'csrf-startup-journey-report',
        ]);
        $body = $this->decodeJsonResponse($response);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($body['success'] ?? false));
        $this->assertGreaterThan(0, (int) ($body['artifact_id'] ?? 0));
        $this->assertSame('journey_report', (string) ($body['report']['report_meta']['artifact_type'] ?? ''));
        $this->assertCount(8, (array) ($body['report']['stage_health'] ?? []));
        $this->assertSame(1, $this->journeyReportArtifactCount((int) $seed['workspace_id']));
    }

    public function testJourneyPageRendersReportDrawerControls(): void
    {
        $seed = $this->seedReadyJourneyWorkspace();

        $response = $this->runWebEndpoint('public/startup_journey.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('data-journey-report-open', $body);
        $this->assertStringContainsString('data-journey-report-drawer', $body);
        $this->assertStringContainsString('Journey Report', $body);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postReport(array $session, array $payload): array
    {
        return $this->runWebEndpoint('api/startup_journey/report.php', $session, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'env' => [
                'AI_API_KEY' => '',
                'AI_SERVICE_URL' => '',
                'AI_LOCAL_ENABLED' => 'false',
                'OLLAMA_ENABLED' => 'false',
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));

        return $decoded;
    }

    private function journeyReportArtifactCount(int $workspaceId): int
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM startup_journey_artifacts
             WHERE workspace_id = ?
               AND artifact_type = 'journey_report'",
            [$workspaceId]
        ) ?: [];

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function insertReportArtifact(array $seed): int
    {
        $journey = (new StartupJourneyService())->getJourney((int) $seed['workspace_id'], (int) $seed['user_id']);
        $payload = [
            'executive_summary' => 'Saved report for a view-only Journey user.',
            'journey_health' => [
                'status' => 'needs_setup',
                'message' => 'Clarity Journey needs more context.',
                'labels' => ['thin'],
            ],
            'stage_health' => [],
            'swot' => [
                'status' => 'unavailable',
                'reason' => 'Seeded endpoint test report.',
                'strengths' => [],
                'weaknesses' => [],
                'opportunities' => [],
                'threats' => [],
            ],
            'assumption_conflicts' => [],
            'founder_loop_bridge' => [],
            'next_actions' => [],
            'caveats' => [],
            'report_meta' => [
                'artifact_type' => 'journey_report',
                'version' => 'test',
                'generated_at' => date('Y-m-d H:i:s'),
            ],
        ];

        Database::execute(
            "INSERT INTO startup_journey_artifacts (
                journey_id, workspace_id, user_id, artifact_type, title, content_json, created_by
             ) VALUES (?, ?, ?, 'journey_report', 'Clarity Journey Report', ?, ?)",
            [
                (int) ($journey['journey_id'] ?? 0),
                (int) $seed['workspace_id'],
                (int) $seed['user_id'],
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                (int) $seed['user_id'],
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedReadyJourneyWorkspace(): array
    {
        $seed = $this->seedWorkspace('startup-journey-report');
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
            'source' => 'startup_journey_report_endpoint_test',
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
            'workspace_name' => 'Startup Journey Report ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Journey',
            'last_name' => 'Reporter',
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
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function addWorkspaceViewer(array $seed, string $email): array
    {
        $viewerId = (int) Auth::createUser($email, 'P@ssword123!', 'viewer', 'Journey', 'Viewer');
        Authorization::assignUserRoleBySlug($viewerId, 'viewer', (int) $seed['user_id']);
        $this->grantRolePermissions('viewer', ['workspace.skills.view']);
        $this->revokeRolePermissions('viewer', ['workspace.skills.manage']);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'viewer', 'active', 0, NOW(), ?)
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status)",
            [(int) $seed['workspace_id'], $viewerId, (int) $seed['user_id']]
        );
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [(int) $seed['workspace_id'], $viewerId]
        ) ?? [];

        return array_merge($seed, [
            'user_id' => $viewerId,
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $email,
        ]);
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

    /**
     * @param list<string> $permissionKeys
     */
    private function revokeRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 0
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = 0",
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
            'user_uuid' => 'startup-journey-report-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-startup-journey-report',
            '__remember_restore_attempted' => true,
        ];
    }
}
