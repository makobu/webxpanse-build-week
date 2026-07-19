<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\WorkspaceAutomationReadinessSettingsService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class DashboardAutomationBatteryEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testIdleRequestReturnsSkippedMetadataWhenSnapshotIsNotDue(): void
    {
        $userId = $this->createSuperAdmin();
        $this->insertBatterySnapshot($userId, 64, 'manual', 3600);

        $response = $this->runWebEndpoint('api/dashboard/automation_battery.php', $this->workspaceSession($userId), [
            'method' => 'GET',
            'query' => [
                'source' => 'idle',
                'subject_user_id' => $userId,
            ],
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($payload['refresh_skipped'] ?? false));
        $this->assertSame('not_due', (string) ($payload['status']['refresh_skip_reason'] ?? ''));
        $this->assertSame('six_hours', (string) ($payload['refresh_policy']['cadence'] ?? ''));
        $this->assertNotEmpty($payload['refresh_due_at'] ?? null);
        $this->assertSame(64, (int) ($payload['status']['score'] ?? 0));
        $this->assertIsArray($payload['status']['top_actions'] ?? null);
        $this->assertIsArray($payload['status']['top_progress_signals'] ?? null);
        $this->assertArrayHasKey('is_visible', (array) ($payload['status'] ?? []));
        $this->assertIsArray($payload['status']['context_enrichments'] ?? null);
        $this->assertIsArray($payload['status']['health_summary'] ?? null);
        $this->assertIsArray($payload['status']['attention_counts'] ?? null);
        $this->assertSame('Already fresh. The next automatic check is scheduled.', (string) ($payload['status']['refresh_state_label'] ?? ''));
    }

    public function testManualRequestReturnsStatusAndRefreshPolicyMetadata(): void
    {
        $userId = $this->createSuperAdmin();
        $this->insertBatterySnapshot($userId, 72, 'manual', 30);

        $response = $this->runWebEndpoint('api/dashboard/automation_battery.php', $this->workspaceSession($userId), [
            'method' => 'GET',
            'query' => [
                'source' => 'manual',
                'subject_user_id' => $userId,
            ],
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertIsArray($payload['status'] ?? null);
        $this->assertSame('six_hours', (string) ($payload['refresh_policy']['cadence'] ?? ''));
        $this->assertSame(21600, (int) ($payload['refresh_policy']['seconds'] ?? 0));
        $this->assertTrue((bool) ($payload['refresh_skipped'] ?? false));
        $this->assertSame('manual_throttle', (string) ($payload['status']['refresh_skip_reason'] ?? ''));
        $this->assertIsArray($payload['status']['top_actions'] ?? null);
        $this->assertIsArray($payload['status']['top_progress_signals'] ?? null);
        $this->assertArrayHasKey('is_visible', (array) ($payload['status'] ?? []));
        $this->assertIsArray($payload['status']['context_enrichments'] ?? null);
        $this->assertIsArray($payload['status']['health_summary'] ?? null);
        $this->assertIsArray($payload['status']['attention_counts'] ?? null);
        $this->assertSame('Already fresh. Manual refresh will be available again shortly.', (string) ($payload['status']['refresh_state_label'] ?? ''));
    }

    public function testIdleRequestReportsManualOnlyPolicy(): void
    {
        $userId = $this->createSuperAdmin();
        (new WorkspaceAutomationReadinessSettingsService())->savePolicy(1, 'manual_only', $userId);

        $response = $this->runWebEndpoint('api/dashboard/automation_battery.php', $this->workspaceSession($userId), [
            'method' => 'GET',
            'query' => [
                'source' => 'idle',
                'subject_user_id' => $userId,
            ],
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($payload['refresh_skipped'] ?? false));
        $this->assertSame('manual_only', (string) ($payload['status']['refresh_skip_reason'] ?? ''));
        $this->assertSame('manual_only', (string) ($payload['refresh_policy']['cadence'] ?? ''));
        $this->assertNull($payload['refresh_due_at'] ?? null);
        $this->assertSame('Automatic checks are off. Manual refresh is available from the dashboard.', (string) ($payload['status']['refresh_state_label'] ?? ''));
    }

    public function testIdleRequestPreservesExpiredSnapshotWhenManualOnly(): void
    {
        $userId = $this->createSuperAdmin();
        $this->insertBatterySnapshot($userId, 58, 'cron', 1800);
        (new WorkspaceAutomationReadinessSettingsService())->savePolicy(1, 'manual_only', $userId);

        $response = $this->runWebEndpoint('api/dashboard/automation_battery.php', $this->workspaceSession($userId), [
            'method' => 'GET',
            'query' => [
                'source' => 'idle',
                'subject_user_id' => $userId,
            ],
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($payload['is_stale'] ?? false));
        $this->assertTrue((bool) ($payload['status']['is_stale'] ?? false));
        $this->assertSame(58, (int) ($payload['status']['score'] ?? 0));
        $this->assertSame('manual_only', (string) ($payload['status']['refresh_skip_reason'] ?? ''));
    }

    private function createSuperAdmin(): int
    {
        $userId = (int) Auth::createUser(
            'dashboard-battery-api-' . uniqid('', true) . '@example.test',
            'P@ssword123!',
            'admin',
            'Battery',
            'Admin'
        );
        $this->assertGreaterThan(0, $userId);
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, 'superadmin', true, $userId);

        return $userId;
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $userId): array
    {
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];
        $workspace = Database::queryOne("SELECT uuid, name, slug FROM workspaces WHERE id = 1 LIMIT 1") ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$userId]
        ) ?? [];

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
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
        ];
    }

    private function insertBatterySnapshot(int $subjectUserId, int $score, string $source, int $ageSeconds): void
    {
        $calculatedAt = date('Y-m-d H:i:s', time() - max(0, $ageSeconds));
        $expiresAt = date('Y-m-d H:i:s', strtotime($calculatedAt) + 600);
        $status = [
            'score' => $score,
            'bucket' => 'medium',
            'status_label' => 'Building automation',
            'headline_label' => 'Cached snapshot',
            'summary' => 'Stored dashboard snapshot.',
            'top_blockers' => [],
            'top_boosters' => ['Snapshot ready'],
            'top_actions' => [],
            'top_progress_signals' => [],
            'context_enrichments' => [],
            'mode_label' => 'Cached snapshot',
            'subject_user_id' => $subjectUserId,
            'setup_progress_label' => '3 of 6 foundations ready',
            'layers' => [],
            'job_health' => [],
        ];

        Database::execute(
            "INSERT INTO automation_battery_snapshots
                (workspace_id, subject_user_id, score, bucket, status_label, headline_label, summary,
                 payload_json, fingerprint, calculation_source, calculated_at, expires_at, created_at, updated_at)
             VALUES (1, ?, ?, 'medium', 'Building automation', 'Cached snapshot', 'Stored dashboard snapshot.',
                     ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $subjectUserId,
                $score,
                json_encode($status),
                str_repeat('d', 64),
                $source,
                $calculatedAt,
                $expiresAt,
            ]
        );
    }
}
