<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIUserWorkContextService;
use CRM\Tests\DatabaseTestCase;

class AIUserWorkContextServiceTest extends DatabaseTestCase
{
    public function testBuildsLoggedInUserWorkContextSummary(): void
    {
        Database::execute(
            "INSERT INTO users (first_name, last_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, 'sales', NOW())",
            ['Role', 'Aware', 'work-context@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'member', 'active')",
            [$userId]
        );

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, status, assigned_to, created_by, due_date, created_at)
             VALUES (1, 'Open follow-up', 'pending', ?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())",
            [$userId, $userId]
        );
        $taskId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO task_subtasks (task_id, title, description, completed, `order`) VALUES
             (?, 'Send first follow-up', 'Email the contact', 1, 0),
             (?, 'Record reply outcome', 'Log the response or next step', 0, 1)",
            [$taskId, $taskId]
        );
        Database::execute(
            "INSERT INTO deals (workspace_id, title, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, 'Owned deal', ?, ?, 'proposal', 1000, 'USD', NOW())",
            [$userId, $userId]
        );
        Database::execute(
            "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (?, 'ai_context_strictness', 'balanced')",
            [$userId]
        );

        $context = (new AIUserWorkContextService())->buildContext($userId, 'clarity_chat');

        $this->assertSame('Role Aware', $context['identity']['display_name']);
        $this->assertSame('sales', $context['identity']['role']);
        $this->assertSame(1, $context['my_workload']['open_tasks']);
        $this->assertSame(1, $context['my_workload']['overdue_tasks']);
        $this->assertSame(1, $context['task_gap_context']['tasks_with_gaps']);
        $this->assertSame(1, $context['task_gap_context']['open_gap_subtasks']);
        $this->assertSame('Record reply outcome', $context['task_gap_context']['top_gap_tasks'][0]['missing_subtasks'][0]['title']);
        $this->assertSame(1, $context['my_workload']['owned_open_deals']);
        $this->assertSame('balanced', $context['ai_preferences']['context_strictness']);
        $this->assertStringContainsString('Task gaps', $context['summary']);
        $this->assertNotEmpty($context['summary']);
    }
}
