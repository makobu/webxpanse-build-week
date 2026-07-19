<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Nurture;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\CustomerCareAutomationService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class CustomerCareAutomationServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('care-user-', true), 'care-automation@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        Authorization::assignUserRoleBySlug($this->userId, 'owner', $this->userId);
        Session::set('user_id', $this->userId);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (1, ?, 'owner', 'active', 1, NOW(), ?)",
            [$this->userId, $this->userId]
        );

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_by, lead_score, engagement_score, metadata_json, created_at)
             VALUES
                (1, ?, 'Casey', 'Care', 'casey.care@example.com', 'won', ?, ?, 45, 60, ?, NOW())",
            [
                uniqid('care-contact-', true),
                $this->userId,
                $this->userId,
                json_encode([
                    'source' => 'default_workspace_owner_contact',
                    'default_workspace_contact_scope' => 'current_paying_customer',
                    'current_paying_customer' => true,
                ]),
            ]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals
                (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, actual_close_date, currency, created_at)
             VALUES
                (1, 'Care automation package', 'Customer purchase evidence', ?, ?, ?, 'closed_won', 1200, 100, CURDATE(), 'USD', NOW())",
            [$this->contactId, $this->userId, $this->userId]
        );

        (new Nurture())->getOrCreateProfile($this->contactId);
    }

    public function testSuggestOnlyDoesNotCreateCheckInTask(): void
    {
        $service = new CustomerCareAutomationService();
        $before = $this->taskCount();

        $result = $service->createCheckInTask($this->contactId, $this->userId);

        $this->assertSame('suggest_only', $result['decision']);
        $this->assertFalse($result['can_execute']);
        $this->assertSame($before, $this->taskCount());
    }

    public function testAutoSafeCanCreateCheckInTaskAndCaptureLearningOutcome(): void
    {
        $this->setCustomerCareControl('auto_safe');
        $service = new CustomerCareAutomationService();

        $result = $service->createCheckInTask($this->contactId, $this->userId);

        $this->assertSame('allow', $result['decision']);
        $this->assertTrue($result['can_execute']);
        $this->assertGreaterThan(0, (int) ($result['result']['task_id'] ?? 0));

        $demo = Database::queryOne(
            "SELECT * FROM ai_operator_demonstrations
             WHERE domain_key = 'customer_care'
               AND action_key = 'create_check_in_task'
               AND entity_id = ?
             ORDER BY id DESC LIMIT 1",
            [$this->contactId]
        );

        $this->assertNotEmpty($demo);
        $this->assertSame('accepted', (string) ($demo['outcome_label'] ?? ''));
        $this->assertSame('system', (string) ($demo['actor_type'] ?? ''));
        $metadata = json_decode((string) ($demo['metadata_json'] ?? '{}'), true);
        $this->assertSame('customer_relationship_follow_up', $metadata['task_intent'] ?? null);
        $this->assertArrayHasKey('care_reason', $metadata);
    }

    public function testPausedCustomerCareDomainBlocksMutation(): void
    {
        $this->setCustomerCareControl('auto_safe', ['paused' => true]);
        $service = new CustomerCareAutomationService();
        $before = $this->taskCount();

        $result = $service->createCheckInTask($this->contactId, $this->userId);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('domain_paused', $result['reason_codes']);
        $this->assertSame($before, $this->taskCount());
    }

    public function testDisallowedCustomerCareActionReturnsEnvelopeBlocker(): void
    {
        $this->setCustomerCareControl('auto_safe', [
            'allowed_actions' => ['record_check_in'],
        ]);
        $service = new CustomerCareAutomationService();
        $before = $this->taskCount();

        $result = $service->createCheckInTask($this->contactId, $this->userId);

        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('action_outside_envelope', $result['reason_codes']);
        $this->assertSame($before, $this->taskCount());
    }

    public function testDraftCustomerReplyRequiresCustomerThreadApprovalAndDoesNotSend(): void
    {
        $this->setCustomerCareControl('auto_safe');
        $service = new CustomerCareAutomationService();

        $result = $service->draftCustomerReply($this->contactId, $this->userId, [
            'messages' => [],
        ]);

        $this->assertContains($result['decision'], ['approval_required', 'blocked']);
        $this->assertFalse($result['can_execute']);
        $this->assertArrayHasKey('draft', $result['result']);
        $this->assertArrayHasKey('thread_governance', $result['result']);
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS count FROM email_assistant_runs WHERE mode = 'customer_thread'")['count'] ?? 0));
    }

    public function testRecordOutcomeStoresEditedRejectedAndCompletedLabels(): void
    {
        $service = new CustomerCareAutomationService();

        $service->recordOutcome('schedule_check_in', $this->contactId, $this->userId, 'edited', ['scheduled_at' => date('Y-m-d H:i:s')]);
        $service->recordOutcome('schedule_check_in', $this->contactId, $this->userId, 'rejected');
        $service->recordOutcome('record_check_in', $this->contactId, $this->userId, 'completed');

        $labels = Database::query(
            "SELECT outcome_label
             FROM ai_operator_demonstrations
             WHERE domain_key = 'customer_care'
               AND entity_id = ?
             ORDER BY id ASC",
            [$this->contactId]
        );

        $this->assertContains('edited', array_column($labels, 'outcome_label'));
        $this->assertContains('rejected', array_column($labels, 'outcome_label'));
        $this->assertContains('completed', array_column($labels, 'outcome_label'));
    }

    public function testDailyAutoActionCapCountsCustomerCareSystemActions(): void
    {
        $this->setCustomerCareControl('auto_safe', [
            'max_daily_auto_actions' => 1,
        ]);
        $service = new CustomerCareAutomationService();

        $first = $service->createCheckInTask($this->contactId, $this->userId);
        $second = $service->scheduleCheckIn($this->contactId, $this->userId, date('Y-m-d H:i:s', strtotime('+3 days')));

        $this->assertSame('allow', $first['decision']);
        $this->assertSame('blocked', $second['decision']);
        $this->assertContains('daily_auto_action_cap_exceeded', $second['reason_codes']);
    }

    private function setCustomerCareControl(string $mode, array $metadata = []): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'customer_care', [
            'autonomy_mode' => $mode,
            'promotion_status' => $mode,
            'metadata' => $metadata,
        ], $this->userId);
    }

    private function taskCount(): int
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS count FROM tasks WHERE workspace_id = 1 AND contact_id = ?",
            [$this->contactId]
        );

        return (int) ($row['count'] ?? 0);
    }
}
