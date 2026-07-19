<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplacePerformanceService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplacePerformanceServiceTest extends DatabaseTestCase
{
    public function testRuntimeUsageCountersStayScopedToWorkspace(): void
    {
        $first = $this->seedWorkspace('marketplace-runtime-a');
        $second = $this->seedWorkspace('marketplace-runtime-b');

        $firstRun = $this->insertEmailAssistantRun($first['workspace_id'], $first['user_id'], 'executed');
        $secondRun = $this->insertEmailAssistantRun($second['workspace_id'], $second['user_id'], 'failed');
        Database::execute(
            "INSERT INTO email_assistant_action_queue (workspace_id, run_id, action_key, status, created_at) VALUES (?, ?, 'reply', 'pending', NOW())",
            [$first['workspace_id'], $firstRun]
        );
        Database::execute(
            "INSERT INTO email_assistant_action_queue (workspace_id, run_id, action_key, status, created_at) VALUES (?, ?, 'reply', 'pending', NOW())",
            [$second['workspace_id'], $secondRun]
        );

        $firstSms = $this->insertSmsMessage($first['workspace_id'], $first['user_id'], 'sent');
        $secondSms = $this->insertSmsMessage($second['workspace_id'], $second['user_id'], 'failed');
        Database::execute(
            "INSERT INTO sms_queue (workspace_id, message_id, status, created_at) VALUES (?, ?, 'pending', NOW())",
            [$first['workspace_id'], $firstSms]
        );
        Database::execute(
            "INSERT INTO sms_queue (workspace_id, message_id, status, created_at) VALUES (?, ?, 'pending', NOW())",
            [$second['workspace_id'], $secondSms]
        );

        $this->insertWhatsAppRuntimeRows($first['user_id'], 'received', 'session_open');
        $this->insertWhatsAppRuntimeRows($second['user_id'], 'failed', 'session_open');
        $this->insertMeetingRuntimeRows($first['user_id'], 'scheduled');
        $this->insertMeetingRuntimeRows($second['user_id'], 'failed');
        Database::execute(
            "INSERT INTO ai_advice_feedback (workspace_id, user_id, surface, feedback_type, created_at) VALUES (?, ?, 'coach', 'helpful', NOW())",
            [$first['workspace_id'], $first['user_id']]
        );
        Database::execute(
            "INSERT INTO ai_advice_feedback (workspace_id, user_id, surface, feedback_type, created_at) VALUES (?, ?, 'coach', 'helpful', NOW())",
            [$second['workspace_id'], $second['user_id']]
        );

        $service = new WorkspaceMarketplacePerformanceService();

        $email = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 30);
        $this->assertSame(1, (int) ($email['runs_30d'] ?? 0));
        $this->assertSame(1, (int) ($email['queued_actions'] ?? 0));
        $this->assertSame('executed', (string) ($email['latest_run_status'] ?? ''));

        $sms = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL, 30);
        $this->assertSame(1, (int) ($sms['sent_30d'] ?? 0));
        $this->assertSame(0, (int) ($sms['failed_30d'] ?? 0));
        $this->assertSame(1, (int) ($sms['queued_messages'] ?? 0));
        $this->assertSame('sent', (string) ($sms['latest_sms_status'] ?? ''));

        $whatsapp = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 30);
        $this->assertSame(1, (int) ($whatsapp['messages_30d'] ?? 0));
        $this->assertSame(1, (int) ($whatsapp['digests_30d'] ?? 0));
        $this->assertSame(1, (int) ($whatsapp['active_sessions'] ?? 0));
        $this->assertSame('received', (string) ($whatsapp['latest_message_status'] ?? ''));

        $meetings = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS, 30);
        $this->assertSame(1, (int) ($meetings['meeting_bot_runs_30d'] ?? 0));
        $this->assertSame(1, (int) ($meetings['notes_ingested_30d'] ?? 0));
        $this->assertSame('scheduled', (string) ($meetings['latest_bot_status'] ?? ''));

        $coach = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::SKILL_AI_COACH, 30);
        $this->assertSame(1, (int) ($coach['feedback_events_30d'] ?? 0));

        Database::execute(
            "INSERT INTO workspace_plugin_runtime_events (
                workspace_id, user_id, skill_key, capability_key, event_type, status, duration_ms, metadata_json
             ) VALUES (?, ?, ?, 'hr_analytics.summary', 'capability_succeeded', 'success', 42, '{}')",
            [$first['workspace_id'], $first['user_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        Database::execute(
            "INSERT INTO workspace_plugin_runtime_events (
                workspace_id, user_id, skill_key, capability_key, event_type, status, duration_ms, metadata_json
             ) VALUES (?, ?, ?, 'hr_analytics.summary', 'readiness_blocked', 'blocked', 0, '{}')",
            [$second['workspace_id'], $second['user_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP]
        );
        Database::execute(
            "INSERT INTO user_system_sessions (
                workspace_id, user_id, session_id_hash, started_at, last_seen_at, ended_at,
                duration_seconds, active_seconds, end_reason
             ) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE), NOW(), 1800, 1200, 'logout')",
            [$first['workspace_id'], $first['user_id'], hash('sha256', 'marketplace-hr-runtime')]
        );

        $hr = $service->buildModuleRuntimeUsage($first['workspace_id'], WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 30);
        $this->assertSame(1, (int) ($hr['events_30d'] ?? 0));
        $this->assertSame(1, (int) ($hr['successful_runs_30d'] ?? 0));
        $this->assertSame(0, (int) ($hr['blocked_runs_30d'] ?? 0));
        $this->assertSame(42.0, (float) ($hr['avg_duration_ms'] ?? 0));
        $this->assertSame(20.0, (float) ($hr['active_minutes_30d'] ?? 0));
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            ['user-' . $suffix, $slugPrefix . '-' . $suffix . '@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, created_by, created_at) VALUES (?, ?, ?, 'active', ?, NOW())",
            ['workspace-' . $suffix, 'Runtime ' . $suffix, $slugPrefix . '-' . $suffix, $userId]
        );
        $workspaceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at) VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceId, $userId]
        );

        return ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    private function insertEmailAssistantRun(int $workspaceId, int $userId, string $status): int
    {
        Database::execute(
            "INSERT INTO email_assistant_runs (workspace_id, user_id, execution_status, created_at) VALUES (?, ?, ?, NOW())",
            [$workspaceId, $userId, $status]
        );

        return (int) Database::lastInsertId();
    }

    private function insertSmsMessage(int $workspaceId, int $userId, string $status): int
    {
        Database::execute(
            "INSERT INTO sms_messages (workspace_id, uuid, user_id, to_number, from_number, message_body, status, direction, created_at) VALUES (?, ?, ?, '+15550000000', '+15551111111', 'Runtime test', ?, 'outbound', NOW())",
            [$workspaceId, uniqid('sms-', true), $userId, $status]
        );

        return (int) Database::lastInsertId();
    }

    private function insertWhatsAppRuntimeRows(int $userId, string $messageStatus, string $sessionState): void
    {
        $phone = '+1555' . random_int(1000000, 9999999);
        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers (phone_number, user_id, is_active, created_at) VALUES (?, ?, 1, NOW())",
            [$phone, $userId]
        );
        $authorizedNumberId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO whatsapp_assistant_messages (uuid, user_id, authorized_number_id, phone_number, direction, message_body, status, created_at) VALUES (?, ?, ?, ?, 'inbound', 'Runtime test', ?, NOW())",
            [uniqid('wa-', true), $userId, $authorizedNumberId, $phone, $messageStatus]
        );
        Database::execute(
            "INSERT INTO whatsapp_assistant_digest_log (user_id, phone_number, sent_at, status) VALUES (?, ?, NOW(), 'sent')",
            [$userId, $phone]
        );
        Database::execute(
            "INSERT INTO whatsapp_assistant_sessions (authorized_number_id, user_id, phone_number, session_state, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$authorizedNumberId, $userId, $phone, $sessionState]
        );
    }

    private function insertMeetingRuntimeRows(int $userId, string $status): void
    {
        $workspaceId = (int) (Database::queryOne(
            "SELECT workspace_id FROM workspace_memberships WHERE user_id = ? AND membership_status = 'active' ORDER BY id ASC LIMIT 1",
            [$userId]
        )['workspace_id'] ?? 1);
        Database::execute(
            "INSERT INTO meeting_bot_runs (workspace_id, bot_display_name_used, status, created_by, created_at) VALUES (?, 'Runtime Bot', ?, ?, NOW())",
            [$workspaceId, $status, $userId]
        );
        Database::execute(
            "INSERT INTO meeting_note_taker_runs (workspace_id, provider, created_by, created_at) VALUES (?, 'generic', ?, NOW())",
            [$workspaceId, $userId]
        );
    }
}
