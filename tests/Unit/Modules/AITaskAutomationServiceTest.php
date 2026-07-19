<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\AITaskAutomationService;
use CRM\Modules\Tasks;
use CRM\Services\SkillTaskTemplateService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AITaskAutomationServiceTest extends DatabaseTestCase
{
    public function testRecommendationMatcherFindsExistingNearDuplicateCoachTask(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('coach-match-', true), 'coachmatch@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($salesRole['id'] ?? 0), $userId);

        $taskId = (new Tasks())->create([
            'title' => 'Product Pricing Setup',
            'description' => '[AI-COACH][AUTO] Existing coach task',
            'created_by' => $userId,
            'assigned_to' => $userId,
            'status' => 'pending',
            'metadata_json' => [
                'source_surface' => 'ai_coach',
                'recommendation_key' => 'coach_existing_key',
                'auto_complete_allowed' => true,
            ],
        ]);

        $service = new AITaskAutomationService();
        $matched = $service->findOpenTaskForRecommendation($userId, [
            'title' => 'Define Product Pricing',
            'recommendation_key' => 'new_guidance_key',
            'target_id' => 0,
        ]);

        $this->assertNotNull($matched);
        $this->assertSame($taskId, (int) ($matched['id'] ?? 0));
    }

    public function testPricingTaskReplacesGenericChecklistWithSpecificBlueprint(): void
    {
        $task = [
            'title' => 'Product Pricing Strategy',
            'description' => "[AI-COACH][AUTO]\nReason: Undefined product pricing can lead to missed revenue opportunities.",
            'metadata_json' => json_encode([
                'source_surface' => 'ai_coach',
            ]),
        ];

        $reconciled = AITaskAutomationService::reconcileTaskSubtasks($task, [
            ['title' => 'Open the related CRM area'],
            ['title' => 'Save the required change'],
            ['title' => 'Verify the expected result'],
        ]);

        $this->assertSame([
            'Define offer pricing',
            'Save pricing in CRM',
            'Confirm pricing appears',
        ], array_column($reconciled, 'title'));
        $this->assertStringContainsString('set price', strtolower($reconciled[0]['description']));
    }

    public function testPricingTaskReplacesVagueAiSubtasks(): void
    {
        $task = [
            'title' => 'Define Product Pricing',
            'description' => "[AI-COACH][AUTO]\nReason: Pricing is not yet defined.",
            'metadata_json' => json_encode([
                'source_surface' => 'ai_coach',
            ]),
        ];

        $reconciled = AITaskAutomationService::reconcileTaskSubtasks($task, [
            ['title' => 'Configure pricing'],
            ['title' => 'Review setup'],
            ['title' => 'Define Product Pricing'],
        ]);

        $this->assertSame([
            'Define offer pricing',
            'Save pricing in CRM',
            'Confirm pricing appears',
        ], array_column($reconciled, 'title'));
    }

    public function testConcreteAiSubtasksArePreserved(): void
    {
        $task = [
            'title' => 'Product Pricing Strategy',
            'description' => "[AI-COACH][AUTO]\nReason: Undefined product pricing can lead to missed revenue opportunities.",
            'metadata_json' => json_encode([
                'source_surface' => 'ai_coach',
            ]),
        ];

        $reconciled = AITaskAutomationService::reconcileTaskSubtasks($task, [
            ['title' => 'Define monthly and annual pricing tiers'],
            ['title' => 'Save pricing on the core offer record'],
            ['title' => 'Verify the offer preview shows the saved pricing'],
        ]);

        $this->assertSame([
            'Define monthly and annual pricing tiers',
            'Save pricing on the core offer record',
            'Verify the offer preview shows the saved pricing',
        ], array_column($reconciled, 'title'));
    }

    public function testResolveCompletionGuidanceUsesPricingSpecificSummaryAndEvidence(): void
    {
        $task = [
            'title' => 'Product Pricing Strategy',
            'description' => "[AI-COACH][AUTO]\nReason: Undefined product pricing can lead to missed revenue opportunities.",
            'metadata_json' => json_encode([
                'task_intent' => 'pricing',
                'completion_evidence_types' => ['workflow_step_completed'],
            ]),
        ];

        $guidance = AITaskAutomationService::resolveCompletionGuidance($task, [
            ['title' => 'Define offer pricing'],
            ['title' => 'Save pricing in CRM'],
            ['title' => 'Confirm pricing appears'],
        ]);

        $this->assertStringContainsString('pricing is saved', strtolower((string) ($guidance['summary'] ?? '')));
        $this->assertContains('Saved pricing or product configuration in the CRM', $guidance['evidence_signals']);
    }

    public function testResolveCompletionGuidanceUsesWorkflowSpecificSummary(): void
    {
        $task = [
            'title' => 'Add Lead Follow-up Workflow',
            'description' => "[AI-COACH][AUTO]\nReason: Leads are not being followed up consistently.",
            'metadata_json' => json_encode([
                'task_intent' => 'ops',
                'completion_evidence_types' => ['workflow_step_completed'],
            ]),
        ];

        $guidance = AITaskAutomationService::resolveCompletionGuidance($task, [
            ['title' => 'Define the workflow trigger conditions'],
            ['title' => 'Save the workflow actions and ownership steps'],
            ['title' => 'Run a test and confirm the automation works'],
        ]);

        $this->assertStringContainsString('setup is saved and can run', strtolower((string) ($guidance['summary'] ?? '')));
        $this->assertContains('A saved workflow or billing step in the CRM', $guidance['evidence_signals']);
    }

    public function testGetSubtasksRewritesExistingGenericAiChecklist(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('coach-rewrite-', true), 'coachrewrite@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($salesRole['id'] ?? 0), $userId);

        $tasks = new Tasks();
        $taskId = $tasks->createWithSubtasks([
            'title' => 'Product Pricing Strategy',
            'description' => "[AI-COACH][AUTO]\nReason: Undefined product pricing can lead to missed revenue opportunities.",
            'created_by' => $userId,
            'assigned_to' => $userId,
            'status' => 'pending',
            'metadata_json' => [
                'source_surface' => 'ai_coach',
                'auto_complete_allowed' => true,
            ],
        ], [
            ['title' => 'Open the related CRM area'],
            ['title' => 'Save the required change'],
            ['title' => 'Verify the expected result'],
        ]);

        $subtasks = $tasks->getSubtasks($taskId);

        $this->assertSame([
            'Define offer pricing',
            'Save pricing in CRM',
            'Confirm pricing appears',
        ], array_column($subtasks, 'title'));
    }

    public function testPrepareCoachTaskCreationPayloadMarksUnsupportedPricingAsReviewOnly(): void
    {
        $service = new AITaskAutomationService();
        $prepared = $service->prepareCoachTaskCreationPayload([
            'title' => 'Recommendation: Define Product Pricing Strategy for the Initial Founder Offer',
            'description' => 'AI Coach recommends adding pricing because the current offer has no visible amount, billing frequency, or saved product record for follow-up.',
            'metadata_json' => [
                'source_surface' => 'ai_coach',
                'source_recommendation_type' => 'priorities',
            ],
        ], [
            ['title' => 'Configure pricing'],
        ]);

        $taskData = $prepared['task_data'];
        $metadata = (array) ($taskData['metadata_json'] ?? []);

        $this->assertSame('Define Product Pricing Strategy for the Initial Founder Offer', $taskData['title']);
        $this->assertStringStartsWith('Reason: adding pricing because', (string) $taskData['description']);
        $this->assertLessThanOrEqual(190, strlen((string) $taskData['description']));
        $this->assertFalse($metadata['auto_complete_allowed']);
        $this->assertTrue($metadata['protected_from_auto_complete']);
        $this->assertSame('review_only', $metadata['completion_detector_state']);
        $this->assertSame('pricing', $metadata['task_intent']);
        $this->assertContains('workflow_step_completed', $metadata['completion_evidence_types']);
        $this->assertNotContains('invoice_paid', $metadata['completion_evidence_types']);
        $this->assertSame([
            'Define offer pricing',
            'Save pricing in CRM',
            'Confirm pricing appears',
        ], array_column($prepared['subtasks'], 'title'));
        $this->assertStringContainsString('pricing is saved', strtolower((string) ($metadata['completion_guidance']['summary'] ?? '')));
        $this->assertTrue(AITaskAutomationService::isAIAutoTask([
            'description' => (string) $taskData['description'],
            'metadata_json' => json_encode($metadata),
        ]));
    }

    public function testPrepareCoachFinanceSetupTaskUsesFinanceEvidenceType(): void
    {
        $service = new AITaskAutomationService();
        $prepared = $service->prepareCoachTaskCreationPayload([
            'title' => 'Finish Finance setup',
            'description' => 'Complete Finance setup before opening Finance.',
            'metadata_json' => [
                'source_surface' => 'ai_coach',
                'marketplace_skill_key' => 'finance',
            ],
        ]);

        $metadata = (array) ($prepared['task_data']['metadata_json'] ?? []);

        $this->assertSame('Finish Finance setup', $prepared['task_data']['title']);
        $this->assertTrue($metadata['auto_complete_allowed']);
        $this->assertFalse($metadata['protected_from_auto_complete']);
        $this->assertSame('supported', $metadata['completion_detector_state']);
        $this->assertContains('finance_setup_ready', $metadata['completion_evidence_types']);
        $this->assertContains('Finance setup is ready', $metadata['completion_guidance']['evidence_signals']);
        $this->assertNotEmpty($prepared['subtasks']);
    }

    public function testRecommendationMatcherDoesNotCrossWorkspaceBoundary(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('coach-workspace-', true), 'coachworkspace@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($salesRole['id'] ?? 0), $userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Coach Workspace Two', 'coach-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $userId]
        );

        WorkspaceContext::activateRuntimeWorkspace($workspaceTwoId, $userId, 'owner');
        (new Tasks())->create([
            'title' => 'Product Pricing Setup',
            'description' => '[AI-COACH][AUTO] Existing coach task elsewhere',
            'created_by' => $userId,
            'assigned_to' => $userId,
            'status' => 'pending',
            'metadata_json' => [
                'source_surface' => 'ai_coach',
                'recommendation_key' => 'coach_existing_key',
                'auto_complete_allowed' => false,
            ],
        ]);

        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');
        $matched = (new AITaskAutomationService())->findOpenTaskForRecommendation($userId, [
            'title' => 'Product Pricing Setup',
            'recommendation_key' => 'coach_existing_key',
        ]);

        $this->assertNull($matched);
    }

    public function testSkillTaskTemplateEvidenceSurvivesRecommendationEnrichment(): void
    {
        $service = new SkillTaskTemplateService();
        $recommendations = [
            'priorities' => [[
                'title' => 'Finish finance onboarding',
                'reason' => 'Finance setup is incomplete.',
                'category' => 'finance',
            ]],
        ];
        $context = [
            'installed_skill_contracts' => [[
                'key' => 'finance',
                'module_type' => 'skill',
                'boundary_policy' => 'strict',
                'readiness' => ['ready' => true],
                'advice_domains' => ['finance'],
                'task_templates' => [[
                    'title' => 'Complete finance setup',
                    'description' => 'Save opening balances.',
                    'completion_evidence_types' => ['finance_setup_ready'],
                    'target_metric_hint' => 'finance_ready',
                ]],
            ]],
        ];

        $enriched = $service->applyTaskTemplates($recommendations, $context);
        $item = $enriched['priorities'][0];

        $this->assertSame(['finance'], $item['skill_task_template_source']);
        $this->assertSame(['finance_setup_ready'], $item['completion_evidence_types']);
        $this->assertSame(['finance_ready'], $item['skill_task_template_target_metric_hints']);
    }
}
