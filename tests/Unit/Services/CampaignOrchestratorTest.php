<?php
/**
 * Campaign Scheduler Ownership Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\CampaignQueueProcessor;
use CRM\Services\CampaignScheduler;
use CRM\Tests\DatabaseTestCase;

class CampaignOrchestratorTest extends DatabaseTestCase
{
    private int $userId;
    private int $campaignId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (UUID(), ?, ?, 'admin')",
            ['campaign-test@example.com', password_hash('secret123', PASSWORD_BCRYPT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, created_by, created_at)
             VALUES (1, ?, 'Campaign', 'Contact', 'campaign.contact@example.com', 'new', ?, NOW())",
            [function_exists('uuid_v4') ? uuid_v4() : $this->fallbackUuid(), $this->userId]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO campaigns
             (workspace_id, uuid, name, description, objective, channel_mix, status, schedule_type, timezone, attribution_model_default, created_by, created_at, updated_at)
             VALUES (1, ?, 'Unit Campaign', 'Campaign test', 'nurture', ?, 'draft', 'immediate', 'UTC', 'last_touch', ?, NOW(), NOW())",
            [
                function_exists('uuid_v4') ? uuid_v4() : $this->fallbackUuid(),
                json_encode(['email']),
                $this->userId,
            ]
        );
        $this->campaignId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO campaign_steps
             (campaign_id, step_order, step_name, action_type, channel, subject, content, wait_minutes, is_active, created_at, updated_at)
             VALUES (?, 1, 'Step A', 'send_email', 'email', 'Hello', 'Body', 0, 1, NOW(), NOW())",
            [$this->campaignId]
        );
        $stepId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO campaign_enrollments
             (workspace_id, campaign_id, contact_id, status, current_step_id, current_step_order, next_run_at, entered_at, updated_at)
             VALUES (1, ?, ?, 'active', ?, 1, NOW(), NOW(), NOW())",
            [$this->campaignId, $this->contactId, $stepId]
        );
    }

    public function testLaunchCampaignCreatesEnrollmentAndQueue(): void
    {
        $scheduler = new CampaignScheduler();
        $scheduled = $scheduler->scheduleDueEnrollments(50);

        $queueRows = Database::query(
            "SELECT id, workspace_id
             FROM campaign_queue
             WHERE campaign_id = ?",
            [$this->campaignId]
        );

        $this->assertGreaterThanOrEqual(1, $scheduled);
        $this->assertNotEmpty($queueRows);
        $this->assertSame(1, (int) ($queueRows[0]['workspace_id'] ?? 0));
    }

    public function testNurtureCampaignEmailUsesNurtureSenderProfile(): void
    {
        Database::execute(
            "INSERT INTO email_integrations
             (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json, created_at, updated_at)
             VALUES
             (1, 'manual_smtp', 'nurture_email', 'nurture@example.com', 1, NULL, ?, NOW(), NOW())",
            [json_encode([
                'smtp_host' => 'smtp.example.com',
                'smtp_username' => 'nurture@example.com',
                'smtp_password' => 'secret',
                'from_email' => 'nurture@example.com',
                'from_name' => 'Nurture Team',
            ], JSON_UNESCAPED_SLASHES)]
        );
        Database::execute("UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1");

        (new CampaignScheduler())->scheduleDueEnrollments(50);
        $processed = (new CampaignQueueProcessor())->processQueue(10);

        $email = Database::queryOne(
            "SELECT sender_profile, from_email
             FROM emails
             WHERE contact_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$this->contactId]
        );

        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertNotNull($email);
        $this->assertSame('nurture', (string) ($email['sender_profile'] ?? ''));
        $this->assertSame('nurture@example.com', (string) ($email['from_email'] ?? ''));
    }

    public function testNonNurtureCampaignEmailKeepsDefaultSenderProfile(): void
    {
        Database::execute("UPDATE campaigns SET objective = 'outbound' WHERE id = ?", [$this->campaignId]);
        Database::execute("UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1");

        (new CampaignScheduler())->scheduleDueEnrollments(50);
        $processed = (new CampaignQueueProcessor())->processQueue(10);

        $email = Database::queryOne(
            "SELECT sender_profile
             FROM emails
             WHERE contact_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$this->contactId]
        );

        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertNotNull($email);
        $this->assertSame('default', (string) ($email['sender_profile'] ?? ''));
    }

    private function fallbackUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
