<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Session;
use CRM\Services\HRAnalyticsAiService;
use CRM\Services\HRAnalyticsService;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceHRAnalyticsSetupService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

require_once __DIR__ . '/../../../api/mobile/_serializers.php';

class HRAnalyticsServiceTest extends DatabaseTestCase
{
    public function testBuildDashboardProducesRoleAwareScoresAndStrategies(): void
    {
        [$marketingId, $salesId, $opsId] = $this->seedUsers();
        $this->seedStrategySnapshots($marketingId, $salesId);
        $this->seedTaskPerformance($marketingId, $salesId, $opsId);
        $this->seedActivities($marketingId, $salesId);
        $this->seedDeals($salesId);
        $this->seedCampaigns($marketingId);
        $this->seedOutcomeEvents($marketingId, $salesId);

        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => false]);

        $service = new HRAnalyticsService($settings, new HRAnalyticsAiService());
        $dashboard = $service->buildDashboard(['timeframe' => 'month']);

        $mobilePayload = \mobileOrganizationIntelligencePayload($dashboard);
        $this->assertSame(2, $mobilePayload['schema_version']);
        $this->assertSame(
            (string) ($dashboard['founder_brief']['overall_readout'] ?? ''),
            (string) ($mobilePayload['executive_brief']['overall_readout'] ?? '')
        );
        $this->assertArrayHasKey('overdue_open_tasks', $mobilePayload['workload_distribution'][0]);
        $this->assertArrayHasKey('organization_profile', $mobilePayload);

        $this->assertNotEmpty($dashboard['employees']);
        $this->assertContains($dashboard['employees'][0]['role'], ['marketing', 'sales']);
        $this->assertSame('general', $dashboard['employees'][2]['role']);
        $this->assertGreaterThan($dashboard['employees'][2]['score'], $dashboard['employees'][0]['score']);
        $this->assertNotEmpty($dashboard['best_strategies']);
        $this->assertNotEmpty($dashboard['strategy_leaderboard']);
        $this->assertArrayHasKey('competitors', $dashboard['strategy_leaderboard'][0]);
        $this->assertGreaterThanOrEqual(
            (float) ($dashboard['strategy_leaderboard'][array_key_last($dashboard['strategy_leaderboard'])]['score'] ?? 0),
            (float) ($dashboard['strategy_leaderboard'][0]['score'] ?? 0)
        );
        $this->assertNotEmpty($dashboard['gaps']);
        $this->assertArrayHasKey('org', $dashboard['swot']);
        $this->assertArrayHasKey('departments', $dashboard['swot']);
        $this->assertArrayHasKey('founder_brief', $dashboard);
        $this->assertArrayHasKey('organization_stage', $dashboard);
        $this->assertArrayHasKey('function_coverage', $dashboard);
        $this->assertArrayHasKey('function_performance', $dashboard);
        $this->assertArrayHasKey('user_function_profiles', $dashboard);
        $this->assertArrayHasKey('founder_load', $dashboard);
        $this->assertArrayHasKey('function_dependency_risks', $dashboard);
        $this->assertArrayHasKey('department_readiness', $dashboard);
        $this->assertArrayHasKey('next_function_to_formalize', $dashboard);
        $this->assertArrayHasKey('contextual_recommendations', $dashboard);
        $this->assertArrayHasKey('organization_health', $dashboard);
        $this->assertArrayHasKey('leadership_attention', $dashboard);
        $this->assertArrayHasKey('people_risks', $dashboard);
        $this->assertArrayHasKey('department_intelligence', $dashboard);
        $this->assertArrayHasKey('evidence_confidence', $dashboard);
        $this->assertArrayHasKey('operating_trends', $dashboard);
        $this->assertNotEmpty($dashboard['operating_trends']['metrics'] ?? []);
        $this->assertSame([], $dashboard['operating_trends']['series'] ?? []);
        $this->assertSame('baseline', $dashboard['operating_trends']['series_status'] ?? '');
        $this->assertContains('system_active_minutes', array_column((array) ($dashboard['operating_trends']['metrics'] ?? []), 'key'));
        $this->assertContains('workload_pressure', array_column((array) ($dashboard['operating_trends']['metrics'] ?? []), 'key'));
        $trendMetricsByKey = array_column((array) ($dashboard['operating_trends']['metrics'] ?? []), null, 'key');
        $this->assertSame('Active time', (string) ($trendMetricsByKey['system_active_minutes']['label'] ?? ''));
        $this->assertSame('h', (string) ($trendMetricsByKey['system_active_minutes']['unit'] ?? ''));
        $this->assertSame('measured_current', (string) ($trendMetricsByKey['system_active_minutes']['basis'] ?? ''));
        $this->assertSame(
            (float) ($trendMetricsByKey['workload_pressure']['current'] ?? -1),
            (float) ($trendMetricsByKey['workload_pressure']['visual_score'] ?? -2)
        );
        $trendSeries = (array) ($dashboard['operating_trends']['series'] ?? []);
        $this->assertSame([], $trendSeries, 'Historical points must come from stored daily snapshots only.');
        $this->assertNotEmpty($dashboard['founder_brief']['recommended_action'] ?? '');
        $this->assertNotEmpty($dashboard['leadership_attention']);
        $this->assertNotEmpty($dashboard['people_risks']);
        $this->assertContains('workload_overload', array_column($dashboard['people_risks'], 'risk_type'));
        $this->assertContains('data_visibility_risk', array_column($dashboard['people_risks'], 'risk_type'));
        $this->assertTrue(array_reduce(
            (array) ($dashboard['department_summaries'] ?? []),
            static fn(bool $carry, array $department): bool => $carry && ($department['avg_score'] ?? null) === null,
            true
        ), 'Single-person departments must not receive aggregate scores.');
        $this->assertArrayHasKey('score', $dashboard['organization_health']);
        $this->assertArrayHasKey('level', $dashboard['evidence_confidence']);
        $this->assertArrayHasKey('marketing', $dashboard['function_coverage']);
        $this->assertArrayHasKey('sales', $dashboard['function_performance']);
        $this->assertNotEmpty($dashboard['employees'][0]['function_profiles'] ?? []);

        $actionPlan = $service->buildActionPlanDraftFromDashboard($dashboard, null, null, false);
        $this->assertArrayHasKey('diagnosis', $actionPlan);
        $this->assertArrayHasKey('manager_questions', $actionPlan);
        $this->assertArrayHasKey('seven_day_actions', $actionPlan);
        $this->assertArrayHasKey('thirty_day_actions', $actionPlan);
        $this->assertArrayHasKey('success_metrics', $actionPlan);
        $this->assertArrayHasKey('escalation_triggers', $actionPlan);
        $this->assertArrayHasKey('what_not_to_do', $actionPlan);
        $this->assertArrayHasKey('actions', $actionPlan);
        $this->assertArrayHasKey('task_candidates', $actionPlan);
        $this->assertNotEmpty($actionPlan['task_candidates']);
        $this->assertArrayHasKey('key', $actionPlan['task_candidates'][0]);
        $this->assertArrayHasKey('source_scope', $actionPlan['task_candidates'][0]);
        $this->assertArrayHasKey('source_risk_type', $actionPlan['task_candidates'][0]);

        $summaryPayload = $service->buildSummaryPayload(['timeframe' => 'month']);
        $this->assertNotEmpty($summaryPayload['strategy_leaderboard']);
        $this->assertArrayHasKey('marketing', $summaryPayload['role_summaries']);
    }

    public function testFastDashboardPathSkipsAiGeneration(): void
    {
        [$marketingId, $salesId, $opsId] = $this->seedUsers();
        $this->seedTaskPerformance($marketingId, $salesId, $opsId);
        $this->seedActivities($marketingId, $salesId);
        $this->seedDeals($salesId);
        $this->seedCampaigns($marketingId);
        $this->seedOutcomeEvents($marketingId, $salesId);

        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => true]);

        $ai = new class extends HRAnalyticsAiService {
            public int $aiCalls = 0;

            public function buildSwot(string $scopeLabel, array $summary, array $bestStrategies, array $gaps, bool $allowAi = true): array
            {
                if ($allowAi) {
                    $this->aiCalls++;
                }

                return [
                    'strengths' => ['Measured baseline'],
                    'weaknesses' => ['Needs follow-through'],
                    'opportunities' => ['Codify best practices'],
                    'threats' => ['Hidden overload'],
                ];
            }

            public function buildManagerTips(array $summary, array $gaps, array $strategies, string $departmentLabel = 'All Teams', bool $allowAi = true): array
            {
                if ($allowAi) {
                    $this->aiCalls++;
                }

                return ['Coach from evidence'];
            }

            public function buildStrategicPointers(array $roleSummaries, array $strategies, array $gaps, bool $allowAi = true): array
            {
                if ($allowAi) {
                    $this->aiCalls++;
                }

                return [
                    'marketing' => ['Marketing pointer'],
                    'sales' => ['Sales pointer'],
                    'general' => ['General pointer'],
                ];
            }
        };

        $service = new HRAnalyticsService($settings, $ai);
        $dashboard = $service->buildDashboard(['timeframe' => 'month'], false);

        $this->assertSame(0, $ai->aiCalls);
        $this->assertNotEmpty($dashboard['manager_tips']);
        $this->assertNotEmpty($dashboard['strategic_pointers']['marketing']);
    }

    public function testAiFallbacksReturnSpecialistShapesAndPromptFocusIsUsed(): void
    {
        $settings = new HRAnalyticsSettings();
        $settings->save([
            'ai_enabled' => true,
            'prompt_config' => [
                'manager_focus' => 'Use founder-level intervention language.',
                'swot_focus' => 'Prioritize operating risk and leadership decisions.',
            ],
        ]);

        $aiRouter = new class extends \CRM\Services\AIService {
            public array $prompts = [];

            public function __construct()
            {
            }

            public function process(string $task, array $data, array $context = []): string
            {
                $this->prompts[] = (string) ($data['prompt'] ?? '');
                return 'not valid json';
            }
        };

        $languageLevels = new class extends WorkspaceLanguageLevelService {
            public function currentContext(?int $userId = null): array
            {
                return $this->context(self::LEVEL_1);
            }
        };

        $ai = new HRAnalyticsAiService($aiRouter, $settings, null, $languageLevels);
        $swot = $ai->buildSwot(
            'Organization',
            [
                'staff_count' => 3,
                'high_performers' => 1,
                'at_risk_count' => 1,
                'overloaded_count' => 1,
                'organization_stage' => ['stage' => 'founder_led'],
                'function_coverage' => ['finance' => ['name' => 'Finance', 'state' => 'unassigned', 'relevance_status' => 'active']],
                'founder_load' => ['level' => 'high'],
            ],
            [['name' => 'Execution Discipline']],
            [['title' => 'Workload overload', 'summary' => 'Visible queue pressure']],
            true
        );
        $plan = $ai->buildActionPlanDraft(
            'Organization',
            ['avg_score' => 52, 'overdue_open_tasks' => 3],
            [['summary' => 'Visible risk signal']],
            true
        );
        $tips = $ai->buildManagerTips(['avg_score' => 52], [['summary' => 'Risk']], [], 'All Teams', true);

        $this->assertIsArray($swot['strengths'][0] ?? null);
        $this->assertArrayHasKey('confidence', $swot['strengths'][0]);
        $this->assertArrayHasKey('diagnosis', $plan);
        $this->assertArrayHasKey('manager_questions', $plan);
        $this->assertArrayHasKey('actions', $plan);
        $this->assertNotEmpty($tips);
        $this->assertStringContainsString('Prioritize operating risk and leadership decisions.', implode("\n", $aiRouter->prompts));
        $this->assertStringContainsString('Use founder-level intervention language.', implode("\n", $aiRouter->prompts));
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 1', implode("\n", $aiRouter->prompts));
        $this->assertStringContainsString('do not reduce nuance', implode("\n", $aiRouter->prompts));
        $this->assertStringContainsString('assignment types', strtolower(implode("\n", $aiRouter->prompts)));
        $this->assertStringContainsString('function_coverage', implode("\n", $aiRouter->prompts));
    }

    public function testActionPlanFallbackMarksThinEvidenceAsLowConfidence(): void
    {
        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => false]);

        $plan = (new HRAnalyticsAiService(null, $settings))->buildActionPlanDraft(
            'Organization',
            ['staff_count' => 1, 'avg_score' => 0],
            [],
            false
        );

        $this->assertSame('low', (string) ($plan['confidence'] ?? ''));
        $this->assertStringContainsString('Limited evidence', (string) ($plan['summary'] ?? ''));
        $this->assertContains('insufficient_evidence', (array) ($plan['source_metrics'] ?? []));
    }

    public function testSettingsAndDashboardAreScopedByWorkspace(): void
    {
        $this->ensureWorkspace(2, 'hr-scope-two', 'HR Scope Two');
        $workspaceOneDepartmentId = $this->departmentId(1, 'sales');
        $workspaceTwoDepartmentId = $this->departmentId(2, 'marketing');
        $workspaceOneUserId = $this->createWorkspaceUser(1, 'workspace-one-hr@example.test', 'sales', $workspaceOneDepartmentId);
        $workspaceTwoUserId = $this->createWorkspaceUser(2, 'workspace-two-hr@example.test', 'marketing', $workspaceTwoDepartmentId);

        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => false], null, 1);
        $settings->save(['ai_enabled' => true, 'thresholds' => ['high_performer' => 90]], null, 2);

        $this->assertFalse($settings->get(1)['ai_enabled']);
        $this->assertTrue($settings->get(2)['ai_enabled']);
        $this->assertSame(75, (int) $settings->get(1)['thresholds']['high_performer']);
        $this->assertSame(90, (int) $settings->get(2)['thresholds']['high_performer']);

        WorkspaceContext::activateRuntimeWorkspace(2);
        $dashboard = (new HRAnalyticsService($settings, new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);
        $emails = array_column($dashboard['employees'], 'email');

        $this->assertContains('workspace-two-hr@example.test', $emails);
        $this->assertNotContains('workspace-one-hr@example.test', $emails);
        $this->assertSame([$workspaceTwoUserId], array_map(static fn(array $employee): int => (int) $employee['id'], $dashboard['employees']));
        $this->assertNotContains($workspaceOneUserId, array_map(static fn(array $employee): int => (int) $employee['id'], $dashboard['employees']));
        $this->assertSame('Marketing', (string) ($dashboard['employees'][0]['department'] ?? ''));
    }

    public function testAssignAccessRoleOnlyUpdatesActiveWorkspace(): void
    {
        $this->ensureWorkspace(2, 'hr-role-two', 'HR Role Two');
        $actorId = $this->createWorkspaceUser(1, 'hr-superadmin@example.test', 'owner', $this->departmentId(1, 'admin'));
        $targetId = $this->createWorkspaceUser(1, 'hr-target@example.test', 'viewer', $this->departmentId(1, 'operations'));
        $this->addMembership($actorId, 2, 'owner', $this->departmentId(2, 'admin'));
        $this->addMembership($targetId, 2, 'viewer', $this->departmentId(2, 'operations'));

        $this->assignGlobalRole($actorId, 'superadmin');
        $this->assignWorkspaceRole($actorId, 1, 'owner');
        $this->assignWorkspaceRole($targetId, 1, 'viewer');
        $this->assignWorkspaceRole($targetId, 2, 'viewer');

        Session::set('user_id', $actorId);
        Session::set('user_uuid', 'hr-superadmin');
        Session::set('user_email', 'hr-superadmin@example.test');
        Session::set('user_role', 'admin');
        WorkspaceContext::activateRuntimeWorkspace(1, $actorId, 'owner');

        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        $this->assertNotNull($salesRole);

        (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))
            ->assignAccessRole($actorId, $targetId, (int) $salesRole['id']);

        $workspaceOneRole = Database::queryOne(
            "SELECT r.slug, wm.role_slug
             FROM workspace_user_roles wur
             JOIN roles r ON r.id = wur.role_id
             JOIN workspace_memberships wm ON wm.workspace_id = wur.workspace_id AND wm.user_id = wur.user_id
             WHERE wur.workspace_id = 1 AND wur.user_id = ?",
            [$targetId]
        );
        $workspaceTwoRole = Database::queryOne(
            "SELECT r.slug, wm.role_slug
             FROM workspace_user_roles wur
             JOIN roles r ON r.id = wur.role_id
             JOIN workspace_memberships wm ON wm.workspace_id = wur.workspace_id AND wm.user_id = wur.user_id
             WHERE wur.workspace_id = 2 AND wur.user_id = ?",
            [$targetId]
        );

        $this->assertSame('sales', (string) ($workspaceOneRole['slug'] ?? ''));
        $this->assertSame('sales', (string) ($workspaceOneRole['role_slug'] ?? ''));
        $this->assertSame('viewer', (string) ($workspaceTwoRole['slug'] ?? ''));
        $this->assertSame('viewer', (string) ($workspaceTwoRole['role_slug'] ?? ''));
        $this->assertNull(Database::queryOne("SELECT user_id FROM user_roles WHERE user_id = ? LIMIT 1", [$targetId]));
    }

    public function testOrganizationFunctionsCanBeCustomizedAndAssigned(): void
    {
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(1);
        $functions = $functionService->listActiveFunctions(1);

        $this->assertNotEmpty($functions);
        $this->assertContains('leadership', array_column($functions, 'slug'));
        $this->assertContains('marketing', array_column($functions, 'slug'));

        $partnershipsId = $functionService->createFunction(1, [
            'name' => 'Partnerships',
            'description' => 'Channel and partner ownership.',
            'category' => 'core',
            'measurement_strength' => 'partial',
        ]);
        $userId = $this->createWorkspaceUser(1, 'functions-owner@example.test', 'owner', $this->departmentId(1, 'admin'));
        $leadershipId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 1 AND slug = 'leadership' LIMIT 1")['id'] ?? 0));

        $functionService->saveUserAssignments(1, $userId, [$leadershipId, $partnershipsId], $leadershipId, [$leadershipId => 'owner', $partnershipsId => 'oversight']);
        $assignments = $functionService->assignmentsForUser(1, $userId);

        $this->assertCount(2, $assignments);
        $this->assertSame('owner', (string) ($assignments[0]['assignment_type'] ?? ''));
        $this->assertSame(1, count(array_filter($assignments, static fn(array $assignment): bool => !empty($assignment['is_primary']))));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM user_function_assignments WHERE workspace_id = 1 AND user_id = ? AND function_id = ?",
            [$userId, $leadershipId]
        )['c'] ?? 0));
        $this->assertSame('Leadership', (string) ($assignments[0]['name'] ?? ''));
    }

    public function testSuggestedFunctionAssignmentsApplyInBulkForMissingMembers(): void
    {
        $this->ensureWorkspace(9, 'suggested-functions', 'Suggested Functions');
        $ownerId = $this->createWorkspaceUser(9, 'suggested-owner@example.test', 'owner', $this->departmentId(9, 'admin'));
        $salesId = $this->createWorkspaceUser(9, 'suggested-sales@example.test', 'sales', $this->departmentId(9, 'sales'));
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(9);

        $result = $functionService->applySuggestedAssignmentsForUsers(9, [$ownerId, $salesId]);

        $this->assertSame(2, (int) ($result['requested'] ?? 0));
        $this->assertSame(2, (int) ($result['applied'] ?? 0));
        $this->assertSame(0, (int) ($result['skipped'] ?? -1));
        $ownerAssignments = $functionService->assignmentsForUser(9, $ownerId, true);
        $salesAssignments = $functionService->assignmentsForUser(9, $salesId, true);

        $this->assertNotEmpty($ownerAssignments);
        $this->assertGreaterThan(1, count($ownerAssignments));
        $this->assertSame(['Sales'], array_column($salesAssignments, 'name'));

        $secondPass = $functionService->applySuggestedAssignmentsForUsers(9, [$ownerId, $salesId]);
        $this->assertSame(0, (int) ($secondPass['applied'] ?? -1));
        $this->assertSame(2, (int) ($secondPass['skipped'] ?? 0));
        $this->assertSame('already_assigned', (string) ($secondPass['skipped_users'][0]['reason'] ?? ''));
    }

    public function testCreateActionPlanTasksTagsTasksAndRecordsRuntimeEvent(): void
    {
        $this->ensureWorkspace(10, 'action-plan-tasks', 'Action Plan Tasks');
        $actorId = $this->createWorkspaceUser(10, 'action-plan-owner@example.test', 'owner', $this->departmentId(10, 'admin'));
        $this->assignWorkspaceRole($actorId, 10, 'owner');
        $this->assignGlobalRole($actorId, 'sales');
        Session::set('user_id', $actorId);
        Session::set('user_uuid', 'action-plan-owner');
        Session::set('user_email', 'action-plan-owner@example.test');
        Session::set('user_role', 'admin');
        WorkspaceContext::activateRuntimeWorkspace(10, $actorId, 'owner');

        $result = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))->createActionPlanTasks($actorId, [
            [
                'key' => 'seven-day-coaching',
                'title' => 'Run seven-day coaching review',
                'description' => 'Validate workload, clarity, and one measurable follow-up.',
                'priority' => 'high',
                'due_date_offset' => 7,
                'source_scope' => 'Organization',
                'source_risk_type' => 'workload_follow_through',
            ],
        ], $actorId);

        $this->assertSame(1, (int) ($result['created_count'] ?? 0));
        $taskId = (int) ($result['task_ids'][0] ?? 0);
        $this->assertGreaterThan(0, $taskId);
        $task = Database::queryOne("SELECT * FROM tasks WHERE workspace_id = 10 AND id = ?", [$taskId]);
        $this->assertSame('hr_analytics', (string) ($task['source_surface'] ?? ''));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, (string) ($task['source_skill_key'] ?? ''));
        $this->assertSame('hr_analytics.action_plan', (string) ($task['source_capability_key'] ?? ''));
        $metadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true);
        $this->assertTrue((bool) ($metadata['action_plan_task'] ?? false));
        $this->assertSame('workload_follow_through', (string) ($metadata['source_risk_type'] ?? ''));

        $event = Database::queryOne(
            "SELECT * FROM workspace_plugin_runtime_events
             WHERE workspace_id = 10
               AND skill_key = ?
               AND capability_key = 'hr_analytics.action_plan'
               AND event_type = 'workflow_action_executed'
             ORDER BY id DESC
             LIMIT 1",
            [WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        $this->assertNotEmpty($event);
    }

    public function testFunctionAssignmentSemanticsAndRelevanceAffectCoverage(): void
    {
        $this->ensureWorkspace(5, 'function-semantics', 'Function Semantics');
        $ownerId = $this->createWorkspaceUser(5, 'function-owner@example.test', 'owner', $this->departmentId(5, 'admin'));
        $supportId = $this->createWorkspaceUser(5, 'function-support@example.test', 'viewer', $this->departmentId(5, 'operations'));
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(5);
        $leadershipId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 5 AND slug = 'leadership' LIMIT 1")['id'] ?? 0));
        $financeId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 5 AND slug = 'finance' LIMIT 1")['id'] ?? 0));
        $productId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 5 AND slug = 'product' LIMIT 1")['id'] ?? 0));
        $peopleId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 5 AND slug = 'people_hr' LIMIT 1")['id'] ?? 0));

        $functionService->updateFunction(5, $productId, [
            'name' => 'Product',
            'slug' => 'product',
            'category' => 'core',
            'measurement_strength' => 'weak',
            'relevance_status' => 'deferred',
            'relevance_note' => 'Formalize after first repeatable delivery cycle.',
            'is_active' => 1,
        ]);
        $functionService->updateFunction(5, $peopleId, [
            'name' => 'People / HR',
            'slug' => 'people_hr',
            'category' => 'support',
            'measurement_strength' => 'partial',
            'relevance_status' => 'not_applicable',
            'is_active' => 1,
        ]);

        $functionService->saveUserAssignments(5, $ownerId, [$leadershipId, $financeId], $leadershipId, [$leadershipId => 'owner', $financeId => 'oversight']);
        $functionService->saveUserAssignments(5, $supportId, [$financeId], $financeId, [$financeId => 'oversight']);
        (new HRAnalyticsSettings())->save(['ai_enabled' => false], null, 5);
        WorkspaceContext::activateRuntimeWorkspace(5);

        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);

        $this->assertSame('unassigned', (string) ($dashboard['function_coverage']['finance']['state'] ?? ''));
        $this->assertSame(0, (int) ($dashboard['function_coverage']['finance']['owner_count'] ?? -1));
        $this->assertSame(2, (int) ($dashboard['function_coverage']['finance']['participant_count'] ?? 0));
        $this->assertSame('deferred', (string) ($dashboard['function_coverage']['product']['state'] ?? ''));
        $this->assertSame('not_applicable', (string) ($dashboard['function_coverage']['people_hr']['state'] ?? ''));
        $this->assertNotContains('product', array_column($dashboard['function_dependency_risks'], 'scope_label'));
        $financeProfiles = [];
        foreach ($dashboard['employees'] as $employee) {
            foreach ((array) ($employee['function_profiles'] ?? []) as $profile) {
                if ((string) ($profile['slug'] ?? '') === 'finance') {
                    $financeProfiles[] = $profile;
                }
            }
        }
        $this->assertNotEmpty($financeProfiles);
        $this->assertSame('insufficient_evidence', (string) ($financeProfiles[0]['measurement_reason'] ?? ''));
    }

    public function testTemporaryOwnerResolvesCoverageAndOnlyOnePrimarySurvives(): void
    {
        $this->ensureWorkspace(6, 'temporary-owner', 'Temporary Owner');
        $userId = $this->createWorkspaceUser(6, 'temporary-owner@example.test', 'owner', $this->departmentId(6, 'admin'));
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(6);
        $leadershipId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 6 AND slug = 'leadership' LIMIT 1")['id'] ?? 0));
        $financeId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 6 AND slug = 'finance' LIMIT 1")['id'] ?? 0));

        $functionService->saveUserAssignments(6, $userId, [$leadershipId, $financeId], $leadershipId, [$leadershipId => 'owner', $financeId => 'temporary_owner']);
        $functionService->saveUserAssignments(6, $userId, [$leadershipId, $financeId], $financeId, [$leadershipId => 'oversight', $financeId => 'temporary_owner']);
        (new HRAnalyticsSettings())->save(['ai_enabled' => false], null, 6);
        WorkspaceContext::activateRuntimeWorkspace(6);

        $primaryCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM user_function_assignments WHERE workspace_id = 6 AND user_id = ? AND is_primary = 1",
            [$userId]
        )['c'] ?? 0);
        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);

        $this->assertSame(1, $primaryCount);
        $this->assertSame('founder_owned', (string) ($dashboard['function_coverage']['finance']['state'] ?? ''));
        $this->assertSame(1, (int) ($dashboard['function_coverage']['finance']['owner_count'] ?? 0));
        $this->assertArrayHasKey('leadership_cohort', $dashboard['founder_load']);
    }

    public function testFounderOnlyWorkspaceUsesFounderLoadAndCoverageInsteadOfDepartmentFailure(): void
    {
        $this->ensureWorkspace(3, 'founder-only-functions', 'Founder Only Functions');
        $founderId = $this->createWorkspaceUser(3, 'founder-only@example.test', 'owner', $this->departmentId(3, 'admin'));
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(3);
        $leadershipId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 3 AND slug = 'leadership' LIMIT 1")['id'] ?? 0));
        $strategyId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 3 AND slug = 'strategy' LIMIT 1")['id'] ?? 0));
        $marketingId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 3 AND slug = 'marketing' LIMIT 1")['id'] ?? 0));
        $financeId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 3 AND slug = 'finance' LIMIT 1")['id'] ?? 0));
        $functionService->saveUserAssignments(3, $founderId, [$leadershipId, $strategyId, $marketingId, $financeId], $leadershipId, [
            $leadershipId => 'owner',
            $strategyId => 'temporary_owner',
            $marketingId => 'temporary_owner',
            $financeId => 'temporary_owner',
        ]);

        WorkspaceContext::activateRuntimeWorkspace(3);
        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => false], null, 3);

        $dashboard = (new HRAnalyticsService($settings, new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);

        $this->assertSame('solo_founder', (string) ($dashboard['organization_stage']['stage'] ?? ''));
        $this->assertSame('moderate', (string) ($dashboard['founder_load']['level'] ?? ''));
        $this->assertSame('founder_owned', (string) ($dashboard['function_coverage']['leadership']['state'] ?? ''));
        $this->assertSame('low', (string) ($dashboard['function_coverage']['finance']['confidence'] ?? ''));
        $this->assertStringContainsString('one founder', strtolower((string) ($dashboard['organization_stage']['summary'] ?? '')));
        $this->assertNotEmpty($dashboard['next_function_to_formalize']['function'] ?? '');
    }

    public function testInferredRoleDefaultsDoNotCountAsExplicitFunctionCoverage(): void
    {
        $this->ensureWorkspace(7, 'inferred-defaults', 'Inferred Defaults');
        $this->createWorkspaceUser(7, 'inferred-owner@example.test', 'owner', $this->departmentId(7, 'admin'));
        (new OrganizationFunctionService())->ensureDefaults(7);
        (new HRAnalyticsSettings())->save(['ai_enabled' => false], null, 7);
        WorkspaceContext::activateRuntimeWorkspace(7);

        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);
        $leadershipCoverage = (array) ($dashboard['function_coverage']['leadership'] ?? []);
        $employeeProfile = (array) (($dashboard['employees'][0]['function_profiles'] ?? [])[0] ?? []);

        $this->assertSame('unassigned', (string) ($leadershipCoverage['state'] ?? ''));
        $this->assertSame(0, (int) ($leadershipCoverage['explicit_owner_count'] ?? -1));
        $this->assertSame(1, (int) ($leadershipCoverage['inferred_owner_count'] ?? 0));
        $this->assertSame('inferred_only', (string) ($leadershipCoverage['coverage_source'] ?? ''));
        $this->assertTrue((bool) ($employeeProfile['is_inferred'] ?? false));
        $this->assertNull($employeeProfile['score'] ?? null);
        $this->assertSame('inferred_role_default', (string) ($dashboard['founder_load']['ownership_source'] ?? ''));
        $this->assertSame('low', (string) ($dashboard['founder_load']['confidence'] ?? ''));
        $this->assertSame(0, (int) ($dashboard['founder_load']['function_count'] ?? -1));
        $leadershipRisk = array_values(array_filter(
            (array) ($dashboard['function_dependency_risks'] ?? []),
            static fn(array $risk): bool => (string) ($risk['scope_label'] ?? '') === 'Leadership'
        ))[0] ?? [];
        $this->assertSame('low', (string) ($leadershipRisk['confidence'] ?? ''));
    }

    public function testDeferredFunctionAssignmentsDoNotInfluenceRuntimeCoverageOrProfiles(): void
    {
        $this->ensureWorkspace(8, 'deferred-assignments', 'Deferred Assignments');
        $ownerId = $this->createWorkspaceUser(8, 'deferred-owner@example.test', 'owner', $this->departmentId(8, 'admin'));
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(8);
        $productId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 8 AND slug = 'product' LIMIT 1")['id'] ?? 0));
        $this->assertGreaterThan(0, $productId);

        $functionService->saveUserAssignments(8, $ownerId, [$productId], $productId, [$productId => 'owner']);
        $functionService->updateFunction(8, $productId, [
            'name' => 'Product',
            'slug' => 'product',
            'category' => 'core',
            'measurement_strength' => 'weak',
            'relevance_status' => 'deferred',
            'is_active' => 1,
        ]);
        (new HRAnalyticsSettings())->save(['ai_enabled' => false], null, 8);
        WorkspaceContext::activateRuntimeWorkspace(8);

        $dashboard = (new HRAnalyticsService(new HRAnalyticsSettings(), new HRAnalyticsAiService()))->buildDashboard(['timeframe' => 'month'], false);
        $productCoverage = (array) ($dashboard['function_coverage']['product'] ?? []);
        $profileSlugs = [];
        foreach ((array) ($dashboard['employees'][0]['function_profiles'] ?? []) as $profile) {
            $profileSlugs[] = (string) ($profile['slug'] ?? '');
        }

        $this->assertSame('deferred', (string) ($productCoverage['state'] ?? ''));
        $this->assertSame(0, (int) ($productCoverage['explicit_owner_count'] ?? -1));
        $this->assertSame(0, (int) ($productCoverage['participant_count'] ?? -1));
        $this->assertNotContains('product', $profileSlugs);
    }

    public function testSetupReadinessUsesFunctionAssignmentsBeforeDepartments(): void
    {
        $this->ensureWorkspace(4, 'function-ready-no-department', 'Function Ready No Department');
        $founderId = $this->createWorkspaceUser(4, 'function-ready@example.test', 'owner', $this->departmentId(4, 'admin'));
        Database::execute(
            "UPDATE workspace_memberships SET department_id = NULL WHERE workspace_id = 4 AND user_id = ?",
            [$founderId]
        );

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(4);
        $leadershipId = (int) ((Database::queryOne("SELECT id FROM organization_functions WHERE workspace_id = 4 AND slug = 'leadership' LIMIT 1")['id'] ?? 0));
        $functionService->saveUserAssignments(4, $founderId, [$leadershipId], $leadershipId);
        (new HRAnalyticsSettings())->save(['ai_enabled' => false], null, 4);

        $status = (new WorkspaceHRAnalyticsSetupService())->status(4);

        $this->assertTrue((bool) ($status['ready'] ?? false));
        $this->assertSame(0, (int) ($status['counts']['assigned_members'] ?? 0));
        $this->assertSame(1, (int) ($status['counts']['function_assignments'] ?? 0));
    }

    public function testSummaryPayloadRecordsRuntimeEventAndSystemTime(): void
    {
        [$marketingId, $salesId, $opsId] = $this->seedUsers();
        $this->seedTaskPerformance($marketingId, $salesId, $opsId);
        $this->seedActivities($marketingId, $salesId);
        Database::execute(
            "INSERT INTO user_system_sessions (
                workspace_id, user_id, session_id_hash, started_at, last_seen_at, ended_at,
                duration_seconds, active_seconds, end_reason
             ) VALUES (1, ?, ?, DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE), NOW(), 1200, 900, 'logout')",
            [$marketingId, hash('sha256', 'hr-summary-runtime')]
        );

        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => false]);

        $payload = (new HRAnalyticsService($settings, new HRAnalyticsAiService()))->buildSummaryPayload(['timeframe' => 'month']);

        $this->assertGreaterThanOrEqual(15.0, (float) ($payload['summary']['system_active_minutes'] ?? 0));
        $this->assertGreaterThanOrEqual(0.2, (float) ($payload['summary']['system_active_hours'] ?? 0));
        $this->assertGreaterThanOrEqual(15.0, (float) ($payload['best_performers'][0]['system_active_minutes'] ?? 0));
        $this->assertGreaterThanOrEqual(0.2, (float) ($payload['best_performers'][0]['system_active_hours'] ?? 0));

        $event = Database::queryOne(
            "SELECT * FROM workspace_plugin_runtime_events
             WHERE workspace_id = 1
               AND skill_key = ?
               AND capability_key = 'hr_analytics.summary'
               AND event_type = 'capability_succeeded'
             ORDER BY id DESC
             LIMIT 1",
            [WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        $this->assertNotEmpty($event);
        $this->assertGreaterThanOrEqual(0, (int) ($event['duration_ms'] ?? -1));
    }

    public function testAiSwotRejectsUnsupportedEvidenceSources(): void
    {
        $settings = new HRAnalyticsSettings();
        $settings->save(['ai_enabled' => true]);
        $aiRouter = new class extends \CRM\Services\AIService {
            public function __construct()
            {
            }

            public function process(string $task, array $data, array $context = []): string
            {
                return json_encode([
                    'strengths' => [[
                        'title' => 'Imaginary revenue surge',
                        'evidence' => 'Revenue doubled in a market that is not in the dashboard.',
                        'impact' => 'Scale immediately.',
                        'action' => 'Hire aggressively.',
                        'severity' => 'high',
                        'confidence' => 'high',
                        'source_metrics' => ['made_up_revenue'],
                    ]],
                    'weaknesses' => [],
                    'opportunities' => [],
                    'threats' => [],
                ]);
            }
        };

        $swot = (new HRAnalyticsAiService($aiRouter, $settings))->buildSwot(
            'Organization',
            ['staff_count' => 2, 'avg_score' => 61, 'high_performers' => 0, 'at_risk_count' => 1],
            [],
            [],
            true
        );

        $this->assertNotSame('Imaginary revenue surge', (string) ($swot['strengths'][0]['title'] ?? ''));
        $this->assertSame('low', (string) ($swot['strengths'][0]['confidence'] ?? ''));
        $this->assertNotEmpty($swot['strengths'][0]['source_metrics'] ?? []);
    }

    private function seedUsers(): array
    {
        $ids = [];
        $departments = [];
        foreach (Database::query("SELECT id, slug FROM departments WHERE workspace_id = 1") as $department) {
            $departments[(string) $department['slug']] = (int) $department['id'];
        }

        foreach ([
            ['marketing', 'marketer@example.com', 'Mia', 'Market', $departments['marketing'] ?? null],
            ['sales', 'seller@example.com', 'Sam', 'Seller', $departments['sales'] ?? null],
            ['viewer', 'ops@example.com', 'Ola', 'Ops', $departments['operations'] ?? null],
        ] as [$role, $email, $firstName, $lastName, $departmentId]) {
            Database::execute(
                "INSERT INTO users (uuid, email, password_hash, role, department_id, first_name, last_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [uniqid('hr-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $role, $departmentId, $firstName, $lastName]
            );
            $userId = (int) Database::lastInsertId();
            Database::execute(
                "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status) VALUES (1, ?, ?, ?, 'active')",
                [$userId, $role === 'viewer' ? 'member' : $role, $departmentId]
            );
            $ids[] = $userId;
        }
        return $ids;
    }

    private function seedTaskPerformance(int $marketingId, int $salesId, int $opsId): void
    {
        for ($i = 0; $i < 5; $i++) {
            Database::execute(
                "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, due_date, completed_at, created_at)
                 VALUES (1, ?, ?, ?, 'completed', 'high', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW())",
                ['Marketing task ' . $i, $marketingId, $marketingId]
            );
        }

        for ($i = 0; $i < 4; $i++) {
            Database::execute(
                "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, due_date, completed_at, created_at)
                 VALUES (1, ?, ?, ?, 'completed', 'medium', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW())",
                ['Sales task ' . $i, $salesId, $salesId]
            );
        }

        for ($i = 0; $i < 8; $i++) {
            Database::execute(
                "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, due_date, created_at)
                 VALUES (1, ?, ?, ?, 'pending', 'high', DATE_SUB(NOW(), INTERVAL 2 DAY), NOW())",
                ['Ops backlog ' . $i, $opsId, $opsId]
            );
        }
    }

    private function seedStrategySnapshots(int $marketingId, int $salesId): void
    {
        $profile = new UserStrategyProfile();
        $idea = new IdeaValidationContext();
        $snapshots = new UserStrategySnapshot();

        $profile->save($marketingId, [
            'target_market_focus' => 'Founder-led agencies',
            'ideal_customer_profile' => 'Agency owners',
            'offer_angle' => 'Pipeline clarity sprint',
            'market_view' => 'Agencies need practical proof before adopting AI workflows.',
            'strategy_hypothesis' => 'Campaign-led proof assets will produce stronger engagement.',
            'sales_motion' => 'Proof-led nurture',
        ]);
        $idea->save($marketingId, [
            'value_proposition' => 'Reliable marketing follow-through',
            'target_market' => 'Founder-led agencies',
            'pain_points' => 'Campaigns stall after launch',
            'competitors' => 'Spreadsheets and disconnected task boards',
            'differentiator' => 'Strategy snapshots tied to outcomes',
        ]);
        $snapshots->syncForUser(1, $marketingId);

        $profile->save($salesId, [
            'target_market_focus' => 'Expansion-ready SaaS accounts',
            'ideal_customer_profile' => 'Revenue leaders',
            'offer_angle' => 'Deal hygiene and follow-through',
            'market_view' => 'Revenue teams need trust before automating follow-up.',
            'strategy_hypothesis' => 'Pipeline-stage coaching will improve win rates.',
            'sales_motion' => 'Consultative account selling',
        ]);
        $idea->save($salesId, [
            'value_proposition' => 'Deal movement clarity',
            'target_market' => 'Revenue teams',
            'pain_points' => 'Deals stall without next steps',
            'competitors' => 'Manual CRM reminders',
            'differentiator' => 'Coach-guided follow-through',
        ]);
        $snapshots->syncForUser(1, $salesId);
    }

    private function seedActivities(int $marketingId, int $salesId): void
    {
        $contactId = $this->seedContact();
        for ($i = 0; $i < 4; $i++) {
            Database::execute(
                "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at) VALUES (1, ?, ?, 'note', ?, DATE_SUB(NOW(), INTERVAL ? DAY))",
                [$contactId, $marketingId, 'Marketing activity', $i]
            );
        }
        for ($i = 0; $i < 3; $i++) {
            Database::execute(
                "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, created_at) VALUES (1, ?, ?, 'call', ?, DATE_SUB(NOW(), INTERVAL ? DAY))",
                [$contactId, $salesId, 'Sales activity', $i]
            );
        }
    }

    private function seedDeals(int $salesId): void
    {
        $contactId = $this->seedContact();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, probability, actual_close_date, created_at, updated_at)
             VALUES (1, 'Won deal', ?, ?, ?, 'closed_won', 15000, 100, CURDATE(), NOW(), NOW())",
            [$contactId, $salesId, $salesId]
        );
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, probability, created_at, updated_at)
             VALUES (1, 'Active deal', ?, ?, ?, 'proposal', 6000, 60, NOW(), NOW())",
            [$contactId, $salesId, $salesId]
        );
    }

    private function seedCampaigns(int $marketingId): void
    {
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, description, objective, status, created_by, created_at)
             VALUES (1, ?, 'Growth Sprint', 'Launch campaign', 'nurture', 'active', ?, NOW())",
            [uniqid('campaign-', true), $marketingId]
        );
        $campaignId = (int) Database::lastInsertId();
        $contactId = $this->seedContact();
        Database::execute(
            "INSERT INTO touchpoints (contact_id, campaign_id, source_table, source_id, channel, touch_type, occurred_at, created_at)
             VALUES (?, ?, 'campaigns', ?, 'campaign', 'campaign_touch', NOW(), NOW())",
            [$contactId, $campaignId, $campaignId]
        );
    }

    private function seedOutcomeEvents(int $marketingId, int $salesId): void
    {
        if (Database::columnExists('outcome_events', 'workspace_id')) {
            Database::execute(
                "INSERT INTO outcome_events (workspace_id, user_id, event_key, event_source, event_at) VALUES (1, ?, 'deal.created', 'test', NOW())",
                [$marketingId]
            );
            Database::execute(
                "INSERT INTO outcome_events (workspace_id, user_id, event_key, event_source, event_at) VALUES (1, ?, 'deal.closed_won', 'test', NOW())",
                [$salesId]
            );
            return;
        }

        Database::execute(
            "INSERT INTO outcome_events (user_id, event_key, event_source, event_at) VALUES (?, 'deal.created', 'test', NOW())",
            [$marketingId]
        );
        Database::execute(
            "INSERT INTO outcome_events (user_id, event_key, event_source, event_at) VALUES (?, 'deal.closed_won', 'test', NOW())",
            [$salesId]
        );
    }

    private function seedContact(): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at) VALUES (1, ?, 'Test', 'Contact', ?, NOW())",
            [uniqid('contact-uuid-', true), uniqid('contact-', true) . '@example.com']
        );
        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug)",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );

        foreach (Database::query("SELECT name, slug, description, is_active, is_system FROM departments WHERE workspace_id = 1") as $department) {
            Database::execute(
                "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active), is_system = VALUES(is_system)",
                [
                    $id,
                    $department['name'],
                    $department['slug'],
                    $department['description'],
                    (int) $department['is_active'],
                    (int) $department['is_system'],
                ]
            );
        }
    }

    private function departmentId(int $workspaceId, string $slug): int
    {
        $department = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND slug = ? LIMIT 1",
            [$workspaceId, $slug]
        );

        return (int) ($department['id'] ?? 0);
    }

    private function createWorkspaceUser(int $workspaceId, string $email, string $role, int $departmentId): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, department_id, first_name, last_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'User', NOW())",
            [uniqid('hr-scope-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $role, $departmentId, strtok($email, '@')]
        );
        $userId = (int) Database::lastInsertId();
        $this->addMembership($userId, $workspaceId, $role, $departmentId);

        return $userId;
    }

    private function addMembership(int $userId, int $workspaceId, string $roleSlug, int $departmentId): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), department_id = VALUES(department_id), membership_status = 'active', is_owner = VALUES(is_owner)",
            [$workspaceId, $userId, $roleSlug, $departmentId, $roleSlug === 'owner' ? 1 : 0]
        );
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
    }

    private function assignWorkspaceRole(int $userId, int $workspaceId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$workspaceId, $userId, (int) ($role['id'] ?? 0), $userId]
        );
    }
}
