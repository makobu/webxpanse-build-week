<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class AICoachDiagnosticsControlsPageTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testDiagnosticsPageRedirectsUnauthenticatedUsersToLogin(): void
    {
        $response = $this->runEndpointScript('public/ai_automation_diagnostics.php', [
            'method' => 'GET',
            'query' => ['source' => 'coach'],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testDiagnosticsPageRedirectsUsersWithoutAiOperationsPermission(): void
    {
        $userId = $this->createUser('coach-regular@example.com', 'user');

        $response = $this->runWebEndpoint('public/ai_automation_diagnostics.php', $this->webSession($userId, 'csrf-regular'), [
            'method' => 'GET',
            'query' => ['source' => 'coach'],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testAutomationAdminHistoryPagesAreSuperadminOnly(): void
    {
        $regularUserId = $this->createUser('automation-admin-regular@example.com', 'admin');
        $superAdminId = $this->createUser('automation-admin-super@example.com', 'admin');
        Authorization::assignUserRoleBySlug($superAdminId, 'superadmin', $superAdminId);

        foreach ([
            'public/ai_automation_diagnostics.php',
            'public/ai_learning_review.php',
            'public/commercial_approvals.php',
            'public/workflow_approvals.php',
        ] as $path) {
            $regular = $this->runWebEndpoint($path, $this->webSession($regularUserId, 'csrf-regular'), [
                'method' => 'GET',
            ]);
            $this->assertSame(302, (int) ($regular['status'] ?? 0), $path . ' should redirect regular users. ' . (string) ($regular['stderr'] ?? ''));

            $super = $this->runWebEndpoint($path, $this->webSession($superAdminId, 'csrf-super'), [
                'method' => 'GET',
            ]);
            $this->assertSame(200, (int) ($super['status'] ?? 0), $path . ' should load for superadmins. ' . (string) ($super['stderr'] ?? ''));
        }
    }

    public function testInvalidCsrfDoesNotCreateCoachControl(): void
    {
        $userId = $this->createUser('coach-admin-bad-csrf@example.com', 'admin');
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

        $response = $this->runWebEndpoint('public/ai_automation_diagnostics.php', $this->webSession($userId, 'csrf-good'), [
            'method' => 'POST',
            'query' => ['source' => 'coach'],
            'post' => [
                'csrf_token' => 'csrf-bad',
                'action' => 'set_ai_coach_control',
                'control_scope' => 'feedback_signature',
                'control_value' => 'coach_sig_page',
                'control_type' => 'boosted',
            ],
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid security token.', (string) ($response['body'] ?? ''));
        $this->assertNull(Database::queryOne(
            "SELECT id FROM ai_coach_recommendation_controls WHERE control_value = 'coach_sig_page' LIMIT 1"
        ));
    }

    public function testAdminsCanCreateAndDisableCoachControlsWithValidCsrf(): void
    {
        $userId = $this->createUser('coach-admin-controls@example.com', 'admin');
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        $session = $this->webSession($userId, 'csrf-admin');

        $create = $this->runWebEndpoint('public/ai_automation_diagnostics.php', $session, [
            'method' => 'POST',
            'query' => ['source' => 'coach'],
            'post' => [
                'csrf_token' => 'csrf-admin',
                'action' => 'set_ai_coach_control',
                'control_scope' => 'feedback_signature',
                'control_value' => 'coach_sig_page',
                'control_type' => 'boosted',
                'reason' => 'page harness',
            ],
        ]);

        $this->assertSame(200, (int) ($create['status'] ?? 0), (string) ($create['stderr'] ?? ''));
        $control = Database::queryOne(
            "SELECT * FROM ai_coach_recommendation_controls WHERE control_value = 'coach_sig_page' AND enabled = 1 LIMIT 1"
        );
        $this->assertNotNull($control);

        $disable = $this->runWebEndpoint('public/ai_automation_diagnostics.php', $session, [
            'method' => 'POST',
            'query' => ['source' => 'coach'],
            'post' => [
                'csrf_token' => 'csrf-admin',
                'action' => 'disable_ai_coach_control',
                'control_id' => (int) $control['id'],
                'reason' => 'page harness disable',
            ],
        ]);

        $this->assertSame(200, (int) ($disable['status'] ?? 0), (string) ($disable['stderr'] ?? ''));
        $disabled = Database::queryOne('SELECT enabled FROM ai_coach_recommendation_controls WHERE id = ?', [(int) $control['id']]);
        $this->assertSame(0, (int) ($disabled['enabled'] ?? 1));
        $this->assertNotNull(Database::queryOne(
            "SELECT id FROM audit_log WHERE action = 'ai_coach_control_created' AND entity_id = ? LIMIT 1",
            [(int) $control['id']]
        ));
        $this->assertNotNull(Database::queryOne(
            "SELECT id FROM audit_log WHERE action = 'ai_coach_control_disabled' AND entity_id = ? LIMIT 1",
            [(int) $control['id']]
        ));
    }

    private function createUser(string $email, string $role): int
    {
        Database::execute(
            'INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())',
            [uniqid('diag-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $role]
        );

        return (int) Database::lastInsertId();
    }

    private function webSession(int $userId, string $csrfToken): array
    {
        return [
            'user_id' => $userId,
            'user_uuid' => '00000000-0000-4000-8000-' . str_pad((string) $userId, 12, '0', STR_PAD_LEFT),
            'user_email' => 'user' . $userId . '@example.com',
            'user_role' => 'admin',
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            'csrf_token' => $csrfToken,
            '__remember_restore_attempted' => true,
        ];
    }
}
