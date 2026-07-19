<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\WorkflowTemplates;
use CRM\Services\AIService;
use CRM\Services\SmartTemplateGenerationService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class SmartTemplateGenerationServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('smart-template-user-', true), 'smart-template@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        (new CompanyProfile())->update([
            'company_name' => 'Bluebird Ops',
            'company_description' => 'We help teams make AI execution repeatable.',
            'company_industry' => 'Consulting',
            'icp_pain_points' => 'messy follow-up and weak visibility',
        ]);
        (new UserStrategyProfile())->save($this->userId, [
            'target_market_focus' => 'Professional services firms',
            'ideal_customer_profile' => 'Operations leads',
            'offer_angle' => 'Repeatable AI operating systems',
            'sales_motion' => 'Consultative outbound',
        ]);
        (new IdeaValidationContext())->save($this->userId, [
            'value_proposition' => 'Structured AI execution',
            'target_market' => 'Founder-led agencies',
            'pain_points' => 'Dropped follow-ups',
            'differentiator' => 'Done-with-you rollout',
        ]);
        $this->seedLearningEvidence($this->userId, 1, 'default');
    }

    public function testGenerateCreatesFullLinkedPack(): void
    {
        $ai = new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                if ($task === 'smart_email_template_pack') {
                    return json_encode([
                        'emails' => [
                            ['template_key' => 'lead_intro', 'name' => 'Lead Intro', 'description' => 'desc', 'category' => 'sales', 'subject' => 'Lead intro', 'body_html' => '<html><body><p>Hi {first_name}, this is a specific note about improving follow-up quality for your team and deciding whether a short next step is useful.</p></body></html>', 'body_text' => 'Hi {first_name}, this is a specific note about improving follow-up quality for your team and deciding whether a short next step is useful.', 'variables' => ['first_name'], 'match_metadata_json' => ['purposes' => ['lead_intro'], 'workflow_intents' => ['new_lead_nurture'], 'lifecycle_stages' => ['new'], 'audiences' => ['lead'], 'tones' => ['consultative'], 'required_variables' => ['first_name']]],
                            ['template_key' => 'demo_or_discovery_invite', 'name' => 'Demo Invite', 'description' => 'desc', 'category' => 'sales', 'subject' => 'Demo invite', 'body_html' => '<html><body><p>Hi {first_name}, here is a focused invite to review the workflow, confirm the fit, and book time through {meeting_link}.</p></body></html>', 'body_text' => 'Hi {first_name}, here is a focused invite to review the workflow, confirm the fit, and book time through {meeting_link}.', 'variables' => ['first_name', 'meeting_link'], 'match_metadata_json' => ['purposes' => ['discovery_invite'], 'workflow_intents' => ['demo_follow_up'], 'lifecycle_stages' => ['qualified'], 'audiences' => ['prospect'], 'tones' => ['consultative'], 'required_variables' => ['first_name', 'meeting_link']]],
                            ['template_key' => 'proposal_follow_up', 'name' => 'Proposal Follow Up', 'description' => 'desc', 'category' => 'follow_up', 'subject' => 'Proposal', 'body_html' => '<html><body><p>Hi {first_name}, this follows up on the proposal with a clear path to resolve scope, timing, or rollout questions before a decision.</p></body></html>', 'body_text' => 'Hi {first_name}, this follows up on the proposal with a clear path to resolve scope, timing, or rollout questions before a decision.', 'variables' => ['first_name'], 'match_metadata_json' => ['purposes' => ['proposal_follow_up'], 'workflow_intents' => ['proposal_stall_recovery'], 'lifecycle_stages' => ['proposal'], 'audiences' => ['prospect'], 'tones' => ['consultative'], 'required_variables' => ['first_name']]],
                            ['template_key' => 're_engagement', 'name' => 'Re-Engagement', 'description' => 'desc', 'category' => 're_engagement', 'subject' => 'Re-engagement', 'body_html' => '<html><body><p>Hi {first_name}, this re-opens the conversation around the original priority and offers a simple next step if the problem is still active.</p></body></html>', 'body_text' => 'Hi {first_name}, this re-opens the conversation around the original priority and offers a simple next step if the problem is still active.', 'variables' => ['first_name'], 'match_metadata_json' => ['purposes' => ['re_engagement'], 'workflow_intents' => ['silent_lead_reengagement'], 'lifecycle_stages' => ['stalled'], 'audiences' => ['lead'], 'tones' => ['helpful'], 'required_variables' => ['first_name']]],
                            ['template_key' => 'post_win_onboarding', 'name' => 'Post Win', 'description' => 'desc', 'category' => 'onboarding', 'subject' => 'Welcome', 'body_html' => '<html><body><p>Hi {first_name}, welcome aboard. This sets a clear first milestone and points the customer to {meeting_link} for the kickoff.</p></body></html>', 'body_text' => 'Hi {first_name}, welcome aboard. This sets a clear first milestone and points the customer to {meeting_link} for the kickoff.', 'variables' => ['first_name', 'meeting_link'], 'match_metadata_json' => ['purposes' => ['customer_welcome'], 'workflow_intents' => ['closed_won_onboarding'], 'lifecycle_stages' => ['closed_won'], 'audiences' => ['customer'], 'tones' => ['welcoming'], 'required_variables' => ['first_name', 'meeting_link']]],
                        ],
                    ], JSON_UNESCAPED_SLASHES);
                }

                return json_encode([
                    'workflows' => [
                        ['template_key' => 'new_lead_nurture', 'name' => 'Lead Nurture', 'description' => 'desc', 'category' => 'nurturing', 'trigger_config' => ['type' => 'contact_created'], 'conditions' => [], 'actions' => [['type' => 'send_email', 'template_query' => ['intent_key' => 'new_lead_nurture', 'preferred_template_key' => 'lead_intro', 'purpose' => 'lead_intro']]], 'variables' => [], 'recipe_metadata_json' => ['intent_key' => 'new_lead_nurture']],
                        ['template_key' => 'demo_follow_up', 'name' => 'Demo Follow Up', 'description' => 'desc', 'category' => 'sales', 'trigger_config' => ['type' => 'stage_changed'], 'conditions' => [], 'actions' => [['type' => 'send_email', 'email_template_key' => 'demo_or_discovery_invite']], 'variables' => []],
                        ['template_key' => 'proposal_stall_recovery', 'name' => 'Proposal Recovery', 'description' => 'desc', 'category' => 'sales', 'trigger_config' => ['type' => 'no_activity_for_days', 'days' => 7], 'conditions' => [], 'actions' => [['type' => 'send_email', 'email_template_key' => 'proposal_follow_up']], 'variables' => []],
                        ['template_key' => 'silent_lead_reengagement', 'name' => 'Silent Lead', 'description' => 'desc', 'category' => 're_engagement', 'trigger_config' => ['type' => 'no_activity_for_days', 'days' => 21], 'conditions' => [], 'actions' => [['type' => 'send_email', 'email_template_key' => 're_engagement']], 'variables' => []],
                        ['template_key' => 'closed_won_onboarding', 'name' => 'Closed Won', 'description' => 'desc', 'category' => 'onboarding', 'trigger_config' => ['type' => 'deal_won'], 'conditions' => [], 'actions' => [['type' => 'send_email', 'email_template_key' => 'post_win_onboarding']], 'variables' => []],
                    ],
                ], JSON_UNESCAPED_SLASHES);
            }
        };

        $service = new SmartTemplateGenerationService($ai);
        $result = $service->generateForUser($this->userId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['candidate']);
        $this->assertSame('candidate', $result['status']);
        $this->assertSame(5, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ?", [$result['smart_template_set_id']])['c'] ?? 0));
        $this->assertSame(5, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workflow_templates WHERE smart_template_set_id = ?", [$result['smart_template_set_id']])['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$result['smart_template_set_id']])['c'] ?? 0));

        $workflow = Database::queryOne(
            "SELECT actions FROM workflow_templates WHERE smart_template_set_id = ? AND template_key = 'new_lead_nurture' LIMIT 1",
            [$result['smart_template_set_id']]
        );
        $actions = json_decode($workflow['actions'] ?? '[]', true);
        $this->assertSame('send_email', $actions[0]['type'] ?? null);
        $this->assertSame('new_lead_nurture', $actions[0]['template_query']['intent_key'] ?? null);
        $this->assertArrayNotHasKey('template_id', $actions[0]);

        $emailMetadata = Database::queryOne(
            "SELECT match_metadata_json FROM email_templates WHERE smart_template_set_id = ? AND template_key = 'lead_intro' LIMIT 1",
            [$result['smart_template_set_id']]
        );
        $this->assertSame('new_lead_nurture', json_decode($emailMetadata['match_metadata_json'] ?? '{}', true)['workflow_intents'][0] ?? null);

        $workspaceIds = Database::query(
            "SELECT DISTINCT workspace_id FROM email_templates WHERE smart_template_set_id = ?",
            [$result['smart_template_set_id']]
        );
        $this->assertSame([1], array_values(array_map(static fn(array $row): int => (int) $row['workspace_id'], $workspaceIds)));
    }

    public function testRegenerationCreatesCandidateAndArchivesPreviousOnlyAfterApproval(): void
    {
        $service = new SmartTemplateGenerationService($this->learnedTemplateAi());

        $first = $service->generateForUser($this->userId);
        $firstCandidateSet = Database::queryOne("SELECT status FROM smart_template_sets WHERE id = ?", [$first['smart_template_set_id']]);
        $firstCandidateEmailsActive = Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$first['smart_template_set_id']]);

        $this->assertSame('candidate', $firstCandidateSet['status'] ?? null);
        $this->assertSame(0, (int) ($firstCandidateEmailsActive['c'] ?? 0));

        $service->approveCandidate($this->userId, (int) $first['smart_template_set_id']);
        $second = $service->generateForUser($this->userId);

        $firstSet = Database::queryOne("SELECT status FROM smart_template_sets WHERE id = ?", [$first['smart_template_set_id']]);
        $secondSet = Database::queryOne("SELECT status FROM smart_template_sets WHERE id = ?", [$second['smart_template_set_id']]);
        $firstEmailsActive = Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$first['smart_template_set_id']]);
        $secondEmailsActive = Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$second['smart_template_set_id']]);

        $this->assertSame('active', $firstSet['status'] ?? null);
        $this->assertSame('candidate', $secondSet['status'] ?? null);
        $this->assertSame(5, (int) ($firstEmailsActive['c'] ?? 0));
        $this->assertSame(0, (int) ($secondEmailsActive['c'] ?? 0));

        $service->approveCandidate($this->userId, (int) $second['smart_template_set_id']);

        $approvedFirstSet = Database::queryOne("SELECT status FROM smart_template_sets WHERE id = ?", [$first['smart_template_set_id']]);
        $approvedSecondSet = Database::queryOne("SELECT status FROM smart_template_sets WHERE id = ?", [$second['smart_template_set_id']]);
        $approvedFirstEmailsActive = Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$first['smart_template_set_id']]);
        $approvedSecondEmailsActive = Database::queryOne("SELECT COUNT(*) AS c FROM email_templates WHERE smart_template_set_id = ? AND is_active = 1", [$second['smart_template_set_id']]);

        $this->assertSame('archived', $approvedFirstSet['status'] ?? null);
        $this->assertSame('active', $approvedSecondSet['status'] ?? null);
        $this->assertSame(0, (int) ($approvedFirstEmailsActive['c'] ?? 0));
        $this->assertSame(5, (int) ($approvedSecondEmailsActive['c'] ?? 0));
    }

    public function testGenerationIsBlockedWhenReadinessIsIncomplete(): void
    {
        Database::execute("DELETE FROM idea_validation_context WHERE user_id = ?", [$this->userId]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Smart templates require more context');

        (new SmartTemplateGenerationService())->generateForUser($this->userId);
    }

    public function testSmartTemplatePacksAreScopedPerActiveWorkspace(): void
    {
        $service = new SmartTemplateGenerationService($this->learnedTemplateAi());

        $defaultResult = $service->generateForUser($this->userId);
        $service->approveCandidate($this->userId, (int) $defaultResult['smart_template_set_id']);
        $tenantWorkspaceId = $this->createTenantWorkspace();
        WorkspaceContext::activateRuntimeWorkspace($tenantWorkspaceId, $this->userId, 'owner');

        $statusBeforeTenantGeneration = $service->getStatus($this->userId);
        $this->assertFalse($statusBeforeTenantGeneration['has_active_set']);

        (new CompanyProfile())->update([
            'company_name' => 'Tenant Ops',
            'company_description' => 'Tenant-specific CRM services.',
            'company_industry' => 'SaaS',
            'icp_pain_points' => 'slow qualification',
        ]);
        (new UserStrategyProfile())->save($this->userId, [
            'target_market_focus' => 'Tenant workspace buyers',
            'ideal_customer_profile' => 'Revenue operators',
            'offer_angle' => 'Faster handoffs',
            'sales_motion' => 'Founder-led sales',
        ]);
        (new IdeaValidationContext())->save($this->userId, [
            'value_proposition' => 'Cleaner qualification loops',
            'target_market' => 'SaaS teams',
            'pain_points' => 'Messy pipeline ownership',
            'differentiator' => 'Workspace-specific playbooks',
        ]);
        $this->seedLearningEvidence($this->userId, $tenantWorkspaceId, 'tenant');

        $tenantResult = $service->generateForUser($this->userId);
        $service->approveCandidate($this->userId, (int) $tenantResult['smart_template_set_id']);

        $this->assertSame(5, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM email_templates WHERE workspace_id = ? AND smart_template_set_id = ?",
            [1, $defaultResult['smart_template_set_id']]
        )['c'] ?? 0));
        $this->assertSame(5, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM email_templates WHERE workspace_id = ? AND smart_template_set_id = ?",
            [$tenantWorkspaceId, $tenantResult['smart_template_set_id']]
        )['c'] ?? 0));

        $tenantSmartWorkflows = (new WorkflowTemplates())->getUserSmartTemplates($this->userId);
        $this->assertSame(5, count($tenantSmartWorkflows));
        $this->assertSame($tenantResult['smart_template_set_id'], (int) ($tenantSmartWorkflows[0]['smart_template_set_id'] ?? 0));

        WorkspaceContext::activateRuntimeWorkspace(1, $this->userId, 'owner');
        $defaultStatus = $service->getStatus($this->userId);
        $this->assertSame($defaultResult['smart_template_set_id'], (int) ($defaultStatus['active_set']['id'] ?? 0));
    }

    private function createTenantWorkspace(): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Tenant Smart Templates', ?, 'active', 'active', NOW(), NOW())",
            ['00000000-0000-4000-8000-' . substr(md5(uniqid('', true)), 0, 12), 'tenant-smart-templates-' . uniqid()]
        );
        $workspaceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceId, $this->userId]
        );

        return $workspaceId;
    }

    private function seedLearningEvidence(int $userId, int $workspaceId, string $prefix): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, stage, created_at)
             VALUES (?, UUID(), ?, 'Contact', ?, ?, 'qualified', NOW())",
            [$workspaceId, ucfirst($prefix), $prefix . '-learning@example.com', ucfirst($prefix) . ' Co']
        );
        $contactId = (int) Database::lastInsertId();

        for ($i = 1; $i <= 25; $i++) {
            $status = $i <= 3 ? ($i === 1 ? 'clicked' : 'opened') : 'sent';
            Database::execute(
                "INSERT INTO emails
                    (workspace_id, uuid, contact_id, user_id, to_email, from_email, from_name, subject, body, body_html, status, sent_at, opened_at, clicked_at, created_at)
                 VALUES (?, UUID(), ?, ?, ?, 'sender@example.com', 'Sender', ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))",
                [
                    $workspaceId,
                    $contactId,
                    $userId,
                    $prefix . '-learning@example.com',
                    "Learning email {$i}",
                    "Hi {$prefix}, this is a representative outbound message about implementation clarity, next steps, and a useful decision path.",
                    "<p>Hi {$prefix}, this is a representative outbound message about implementation clarity, next steps, and a useful decision path.</p>",
                    $status,
                    $i,
                    $i <= 3 ? date('Y-m-d H:i:s', time() - ($i * 86400) + 3600) : null,
                    $i === 1 ? date('Y-m-d H:i:s', time() - ($i * 86400) + 7200) : null,
                    $i,
                ]
            );
        }

        for ($i = 1; $i <= 10; $i++) {
            Database::execute(
                "INSERT INTO email_template_learning_samples
                    (workspace_id, user_id, contact_id, sample_kind, source, draft_mode, draft_intention, subject, body_hash, body_excerpt, outcome_label, generated_at, created_at)
                 VALUES (?, ?, ?, 'draft', 'ai_intention_draft', 'draft_from_intention', ?, ?, SHA2(?, 256), ?, 'generated', DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))",
                [
                    $workspaceId,
                    $userId,
                    $contactId,
                    "Draft intention {$i}",
                    "Draft subject {$i}",
                    "Draft body {$i}",
                    "Draft body {$i} with clear structure, a useful customer-specific point, and one next step.",
                    $i,
                    $i,
                ]
            );
        }
    }

    private function learnedTemplateAi(): AIService
    {
        return new class extends AIService {
            public function process(string $task, array $data, array $context = []): string
            {
                if ($task === 'smart_email_template_pack') {
                    return json_encode([
                        'emails' => [
                            $this->email('lead_intro', 'Lead Intro', 'sales', 'Lead intro', 'new_lead_nurture'),
                            $this->email('demo_or_discovery_invite', 'Demo Invite', 'sales', 'Demo invite', 'demo_follow_up'),
                            $this->email('proposal_follow_up', 'Proposal Follow Up', 'follow_up', 'Proposal follow-up', 'proposal_stall_recovery'),
                            $this->email('re_engagement', 'Re-Engagement', 're_engagement', 'Re-engagement', 'silent_lead_reengagement'),
                            $this->email('post_win_onboarding', 'Post Win', 'onboarding', 'Welcome aboard', 'closed_won_onboarding'),
                        ],
                    ], JSON_UNESCAPED_SLASHES);
                }

                return json_encode([
                    'workflows' => [
                        $this->workflow('new_lead_nurture', 'Lead Nurture', 'lead_intro'),
                        $this->workflow('demo_follow_up', 'Demo Follow Up', 'demo_or_discovery_invite'),
                        $this->workflow('proposal_stall_recovery', 'Proposal Recovery', 'proposal_follow_up'),
                        $this->workflow('silent_lead_reengagement', 'Silent Lead', 're_engagement'),
                        $this->workflow('closed_won_onboarding', 'Closed Won', 'post_win_onboarding'),
                    ],
                ], JSON_UNESCAPED_SLASHES);
            }

            private function email(string $key, string $name, string $category, string $subject, string $workflowIntent): array
            {
                $body = "Hi {first_name}, this learned email uses observed language about implementation clarity, practical next steps, and a specific decision path so it is long enough for validation.";
                return [
                    'template_key' => $key,
                    'name' => $name,
                    'description' => 'Learned template',
                    'category' => $category,
                    'subject' => $subject,
                    'body_html' => '<html><body><p>' . $body . '</p></body></html>',
                    'body_text' => $body,
                    'variables' => ['first_name', 'sender_name'],
                    'match_metadata_json' => [
                        'purposes' => [$key],
                        'workflow_intents' => [$workflowIntent],
                        'lifecycle_stages' => ['qualified'],
                        'audiences' => ['prospect'],
                        'tones' => ['consultative'],
                        'required_variables' => ['first_name', 'sender_name'],
                    ],
                ];
            }

            private function workflow(string $key, string $name, string $emailKey): array
            {
                return [
                    'template_key' => $key,
                    'name' => $name,
                    'description' => 'Learned workflow',
                    'category' => 'sales',
                    'trigger_config' => ['type' => 'contact_created'],
                    'conditions' => [],
                    'actions' => [['type' => 'send_email', 'email_template_key' => $emailKey]],
                    'variables' => [],
                    'recipe_metadata_json' => ['intent_key' => $key],
                ];
            }
        };
    }
}
