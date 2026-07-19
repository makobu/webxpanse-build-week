<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\MeetingBotConfig;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Tests\DatabaseTestCase;

class MeetingBotConfigTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        \CRM\Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['meeting-bot-config@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) \CRM\Database::lastInsertId();
    }

    public function testSaveAndGetMeetingBotConfig(): void
    {
        $module = new MeetingBotConfig();
        $module->save([
            'enabled' => true,
            'provider' => 'google_meet',
            'bot_display_name' => 'Acme Meeting Assistant',
            'join_policy' => 'auto_join_eligible',
            'recording_mode' => 'bot_requested',
            'transcript_required' => true,
            'auto_apply_mode' => 'full_auto',
            'consent_notice' => 'This meeting is captured by Acme Meeting Assistant.',
            'zoom_account_id' => 'acct_123',
            'zoom_client_id' => 'client_123',
            'zoom_client_secret' => 'secret_123',
            'google_workspace_client_id' => 'google_client_123',
            'google_workspace_client_secret' => 'google_secret_123',
            'google_workspace_project_id' => 'project_123',
            'google_calendar_integration_id' => '42',
            'google_transcript_mode' => 'workspace_export',
            'webhook_secret' => 'hook_secret',
            'scheduling_secret' => 'sched_secret',
        ], $this->userId);

        $config = $module->get();

        $this->assertTrue($config['enabled']);
        $this->assertSame('google_meet', $config['provider']);
        $this->assertSame('Acme Meeting Assistant', $config['bot_display_name']);
        $this->assertSame('auto_join_eligible', $config['join_policy']);
        $this->assertSame('bot_requested', $config['recording_mode']);
        $this->assertTrue($config['transcript_required']);
        $this->assertSame('full_auto', $config['auto_apply_mode']);
        $this->assertSame('acct_123', $config['zoom_account_id']);
        $this->assertSame('google_client_123', $config['google_workspace_client_id']);
        $this->assertSame('google_secret_123', $config['google_workspace_client_secret']);
        $this->assertSame('project_123', $config['google_workspace_project_id']);
        $this->assertSame('42', $config['google_calendar_integration_id']);
        $this->assertSame('workspace_export', $config['google_transcript_mode']);
        $this->assertSame('hook_secret', $config['webhook_secret']);
        $this->assertSame('sched_secret', $config['scheduling_secret']);
    }

    public function testSaveGeneratesSecretsWhenBlank(): void
    {
        $module = new MeetingBotConfig();
        $module->save([
            'webhook_secret' => '',
            'scheduling_secret' => '',
        ], $this->userId);

        $config = $module->get();

        $this->assertNotSame('', $config['webhook_secret']);
        $this->assertNotSame('', $config['scheduling_secret']);
    }

    public function testMeetingSettingsAreIsolatedAndSecretsCanRouteWorkspaces(): void
    {
        $secondWorkspaceId = $this->createWorkspace('calendar-config-two');
        $bot = new MeetingBotConfig();
        $notes = new MeetingNoteTakerConfig();

        $bot->save([
            'enabled' => true,
            'provider' => 'zoom',
            'bot_display_name' => 'Workspace One Bot',
            'webhook_secret' => 'hook-one',
            'scheduling_secret' => 'schedule-one',
        ], $this->userId, 1);
        $bot->save([
            'enabled' => true,
            'provider' => 'google_meet',
            'bot_display_name' => 'Workspace Two Bot',
            'webhook_secret' => 'hook-two',
            'scheduling_secret' => 'schedule-two',
        ], $this->userId, $secondWorkspaceId);

        $notes->save([
            'enabled' => true,
            'auto_apply_mode' => 'suggest_only',
            'ingest_secret' => 'notes-one',
        ], $this->userId, 1);
        $notes->save([
            'enabled' => true,
            'auto_apply_mode' => 'full_auto',
            'ingest_secret' => 'notes-two',
        ], $this->userId, $secondWorkspaceId);

        $this->assertSame('Workspace One Bot', (string) $bot->get(1)['bot_display_name']);
        $this->assertSame('Workspace Two Bot', (string) $bot->get($secondWorkspaceId)['bot_display_name']);
        $this->assertSame('suggest_only', (string) $notes->get(1)['auto_apply_mode']);
        $this->assertSame('full_auto', (string) $notes->get($secondWorkspaceId)['auto_apply_mode']);

        $maskedBot = $bot->get(1, false);
        $maskedNotes = $notes->get(1, false);
        $this->assertSame('saved', (string) $maskedBot['webhook_secret']);
        $this->assertSame('saved', (string) $maskedBot['scheduling_secret']);
        $this->assertSame('saved', (string) $maskedNotes['ingest_secret']);

        $this->assertSame(1, (int) ($bot->findByWebhookSecret('hook-one')['workspace_id'] ?? 0));
        $this->assertSame($secondWorkspaceId, (int) ($bot->findBySchedulingSecret('schedule-two')['workspace_id'] ?? 0));
        $this->assertSame($secondWorkspaceId, (int) ($notes->findByIngestSecret('notes-two')['workspace_id'] ?? 0));
        $this->assertNull($bot->findByWebhookSecret('missing-secret'));
        $this->assertNull($notes->findByIngestSecret('missing-secret'));
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'active', ?)",
            ['Calendar Config Test', $slug, $this->userId]
        );

        return (int) Database::lastInsertId();
    }
}
