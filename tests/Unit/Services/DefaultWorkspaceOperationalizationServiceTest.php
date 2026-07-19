<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceOperationalizationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceOperationalizationServiceTest extends DatabaseTestCase
{
    public function testOperationalizesDefaultWorkspaceToOneHundredPercent(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-admin@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $actorUserId, 'superadmin');

        $result = (new DefaultWorkspaceOperationalizationService())->operationalize($actorUserId);

        $this->assertSame(100, (int) ($result['operational_score'] ?? 0));
        $this->assertSame(0, (int) ($result['products_upserted'] ?? -1));
        $this->assertSame(8, (int) ($result['capabilities_registered'] ?? 0));
        $this->assertSame(11, (int) ($result['email_templates_upserted'] ?? 0));
        $this->assertSame(8, (int) ($result['tasks_upserted'] ?? 0));
        $this->assertSame(7, (int) ($result['ai_prompts_upserted'] ?? 0));

        $workspace = Database::queryOne("SELECT name, settings_json FROM workspaces WHERE id = 1");
        $settings = json_decode((string) ($workspace['settings_json'] ?? '{}'), true);
        $this->assertSame('Clarity Platform Operations HQ', (string) ($workspace['name'] ?? ''));
        $this->assertSame('platform_ops', (string) ($settings['workspace_purpose'] ?? ''));
        $this->assertTrue((bool) ($settings['internal_channel_ready'] ?? false));
        $this->assertTrue((bool) ($settings['owner_helpline_enabled'] ?? false));

        $profile = Database::queryOne("SELECT company_name, company_industry FROM company_profile WHERE workspace_id = 1 AND is_active = 1 LIMIT 1");
        $this->assertSame('Clarity Platform Operations HQ', (string) ($profile['company_name'] ?? ''));
        $this->assertSame('CRM SaaS platform operations', (string) ($profile['company_industry'] ?? ''));

        $state = Database::queryOne("SELECT status, readiness_score, launch_summary_json FROM workspace_onboarding_state WHERE workspace_id = 1");
        $this->assertSame('completed', (string) ($state['status'] ?? ''));
        $this->assertSame(100, (int) ($state['readiness_score'] ?? 0));
        $this->assertStringContainsString('operating_brief', (string) ($state['launch_summary_json'] ?? ''));
        $this->assertStringContainsString('cold outreach', (string) ($state['launch_summary_json'] ?? ''));
        $this->assertStringContainsString('existing customers and trial users only', (string) ($state['launch_summary_json'] ?? ''));
        $this->assertStringContainsString('automatically qualify for nurturing', (string) ($state['launch_summary_json'] ?? ''));
        $this->assertStringContainsString('not for marketing to convert new customers', (string) ($state['launch_summary_json'] ?? ''));
        $this->assertSame(
            8,
            (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND metadata_json LIKE '%platform_ops_area%'")['c'] ?? 0)
        );
        $this->assertSame(
            7,
            (int) (Database::queryOne("SELECT COUNT(*) AS c FROM ai_prompt_registry WHERE workspace_id = 1 AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.seed_source')) = 'default_workspace_platform_ops_ai'")['c'] ?? 0)
        );

        $welcomeTemplate = Database::queryOne(
            "SELECT subject, body_html, body_text, variables, match_metadata_json
             FROM email_templates
             WHERE slug = 'platform-ops-owner_welcome_setup'
             LIMIT 1"
        );
        $welcomeVariables = json_decode((string) ($welcomeTemplate['variables'] ?? '[]'), true);
        $welcomeMatch = json_decode((string) ($welcomeTemplate['match_metadata_json'] ?? '{}'), true);
        $this->assertSame('Welcome to Clarity, {owner_name}', (string) ($welcomeTemplate['subject'] ?? ''));
        $this->assertStringContainsString('Start setup', (string) ($welcomeTemplate['body_html'] ?? ''));
        $this->assertStringContainsString('If anything gets blocked', (string) ($welcomeTemplate['body_text'] ?? ''));
        $this->assertStringNotContainsString('<html', strtolower((string) ($welcomeTemplate['body_html'] ?? '')));
        $this->assertContains('support_url', is_array($welcomeVariables) ? $welcomeVariables : []);
        $this->assertContains('support_url', is_array($welcomeMatch['required_variables'] ?? null) ? $welcomeMatch['required_variables'] : []);
    }

    public function testDiagnoseReportsMissingBeforeAndHealthyAfterSetup(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-diagnose@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $before = $service->diagnose($actorUserId);
        $this->assertLessThan((int) ($before['total_count'] ?? 0), (int) ($before['healthy_count'] ?? 0));
        $this->assertNotEmpty($before['missing']);

        $service->operationalize($actorUserId);
        $after = $service->diagnose($actorUserId);

        $this->assertSame((int) ($after['total_count'] ?? 0), (int) ($after['healthy_count'] ?? -1));
        $this->assertSame([], $after['missing']);
    }

    public function testOperationalizationIsIdempotent(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-idempotent@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        $service->operationalize($actorUserId);

        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM products WHERE workspace_id = 1 AND is_active = 1 AND pricing_info = 'Internal service'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE slug = 'platform-ops-onboarding_recovery'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE slug = 'platform-ops-owner_welcome_setup' AND tags LIKE '%workspace_owner%'")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE slug = 'platform-ops-channel_setup_reminder' AND workspace_id = 1")['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND metadata_json LIKE '%review_stuck_onboarding%'")['c'] ?? 0));
    }

    public function testRepairRecreatesDeletedSeededArtifactOnly(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-repair@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        Database::execute("DELETE FROM email_templates WHERE slug = 'platform-ops-low_token_warning'");

        $result = $service->operationalize($actorUserId);

        $this->assertSame(1, (int) ($result['artifact_counts']['email_templates']['created'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE slug = 'platform-ops-low_token_warning'")['c'] ?? 0));
    }

    public function testRepairRecreatesDeletedSeededAiPromptOnly(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-ai-repair@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        Database::execute(
            "DELETE FROM ai_prompt_registry
             WHERE workspace_id = 1
               AND surface = 'assistant'
               AND prompt_key = 'assistant_question'"
        );

        $result = $service->operationalize($actorUserId);

        $this->assertSame(1, (int) ($result['artifact_counts']['ai_prompts']['created'] ?? 0));
        $prompt = Database::queryOne(
            "SELECT system_prompt_text, metadata_json
             FROM ai_prompt_registry
             WHERE workspace_id = 1
               AND surface = 'assistant'
               AND prompt_key = 'assistant_question'
               AND status = 'active'
             LIMIT 1"
        );
        $this->assertStringContainsString('Platform Ops HQ', (string) ($prompt['system_prompt_text'] ?? ''));
        $this->assertSame(
            'default_workspace_platform_ops_ai',
            (string) (json_decode((string) ($prompt['metadata_json'] ?? '{}'), true)['seed_source'] ?? '')
        );
    }

    public function testOperationalizationPreservesCustomizedDefaultWorkspaceAiPrompt(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-ai-custom@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        $customPrompt = 'Custom Platform Ops assistant prompt';
        Database::execute(
            "UPDATE ai_prompt_registry
             SET system_prompt_text = ?,
                 metadata_json = ?
             WHERE workspace_id = 1
               AND surface = 'assistant'
               AND prompt_key = 'assistant_question'
               AND status = 'active'",
            [
                $customPrompt,
                json_encode([
                    'seed_source' => 'default_workspace_platform_ops_ai',
                    'seed_key' => 'assistant_question',
                    'seed_version' => '1.0.0',
                    'customized_at' => gmdate('c'),
                ], JSON_UNESCAPED_SLASHES),
            ]
        );

        $result = $service->operationalize($actorUserId);
        $prompt = Database::queryOne(
            "SELECT system_prompt_text
             FROM ai_prompt_registry
             WHERE workspace_id = 1
               AND surface = 'assistant'
               AND prompt_key = 'assistant_question'
               AND status = 'active'
             LIMIT 1"
        );

        $this->assertSame($customPrompt, (string) ($prompt['system_prompt_text'] ?? ''));
        $this->assertSame(1, (int) ($result['artifact_counts']['ai_prompts']['skipped_customized'] ?? 0));
    }

    public function testOperationalizationPreservesCustomizedSeededTemplate(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-custom-template@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        $customBody = 'Custom operator-edited body';
        Database::execute(
            "UPDATE email_templates
             SET body_text = ?,
                 seed_metadata_json = ?
             WHERE slug = 'platform-ops-low_token_warning'",
            [
                $customBody,
                json_encode([
                    'seed_source' => 'default_workspace_platform_ops',
                    'seed_key' => 'low_token_warning',
                    'seed_version' => '1.0.0',
                    'customized_at' => gmdate('c'),
                ], JSON_UNESCAPED_SLASHES),
            ]
        );

        $result = $service->operationalize($actorUserId);
        $template = Database::queryOne("SELECT body_text FROM email_templates WHERE slug = 'platform-ops-low_token_warning'");

        $this->assertSame($customBody, (string) ($template['body_text'] ?? ''));
        $this->assertSame(1, (int) ($result['artifact_counts']['email_templates']['skipped_customized'] ?? 0));
    }

    public function testNonSuperAdminCannotOperationalizeOrDiagnose(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, 'admin', 'Plain', 'Admin', NOW())",
            ['default-ops-plain-admin@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $service = new DefaultWorkspaceOperationalizationService();

        try {
            $service->diagnose($userId);
            $this->fail('Expected diagnose to reject non-Super Admin users.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Super Admin', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Super Admin');
        $service->operationalize($userId);
    }

    public function testOperationalizationWritesSuccessAndFailureAuditRows(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-audit@example.test');
        $service = new DefaultWorkspaceOperationalizationService();

        $service->operationalize($actorUserId);
        $successCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM operator_audit_log WHERE actor_user_id = ? AND action_type IN ('default_workspace_operationalization_started', 'default_workspace_operationalization_completed')",
            [$actorUserId]
        )['c'] ?? 0);
        $this->assertSame(2, $successCount);

        $other = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Audit Failure Workspace',
            'first_name' => 'Audit',
            'last_name' => 'Owner',
            'email' => 'audit-failure-owner@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        try {
            $service->operationalize($actorUserId, (int) $other['workspace_id']);
            $this->fail('Expected non-default workspace to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('protected default workspace', $e->getMessage());
        }

        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM operator_audit_log WHERE actor_user_id = ? AND action_type = 'default_workspace_operationalization_failed'",
            [$actorUserId]
        )['c'] ?? 0));
    }

    public function testRefusesNonDefaultWorkspace(): void
    {
        $actorUserId = $this->createSuperAdmin('default-ops-refuse@example.test');
        $other = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Other Workspace',
            'first_name' => 'Other',
            'last_name' => 'Owner',
            'email' => 'other-workspace-owner@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected default workspace');
        (new DefaultWorkspaceOperationalizationService())->operationalize($actorUserId, (int) $other['workspace_id']);
    }

    public function testNormalWorkspaceDoesNotReceiveDefaultReadinessException(): void
    {
        $seed = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Normal Readiness Workspace',
            'first_name' => 'Normal',
            'last_name' => 'Owner',
            'email' => 'normal-readiness-owner@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        Database::execute(
            "UPDATE workspaces
             SET settings_json = ?
             WHERE id = ?",
            [json_encode(['workspace_purpose' => 'platform_ops', 'internal_channel_ready' => true]), $workspaceId]
        );

        $impact = (new WorkspaceOnboardingService())->getDashboardSetupImpact($workspaceId, $userId);
        $this->assertLessThan(100, (int) ($impact['score'] ?? 0));
        $this->assertNotEmpty($impact['actions']);

        $diagnostics = (new DefaultWorkspaceOperationalizationService())->diagnose($this->createSuperAdmin('default-ops-warning@example.test'));
        $this->assertNotEmpty($diagnostics['warnings']);
    }

    private function createSuperAdmin(string $email): int
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, 'admin', 'Super', 'Admin', NOW())",
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
