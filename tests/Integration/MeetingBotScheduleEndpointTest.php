<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\MeetingBotConfig;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class MeetingBotScheduleEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testScheduleEndpointAcceptsValidHeaderSecret(): void
    {
        $this->configureBot('schedule-header-secret');

        $response = $this->runEndpointScript('api/meeting_bot/schedule.php', [
            'method' => 'POST',
            'headers' => ['X-Meeting-Bot-Schedule-Key' => 'schedule-header-secret'],
            'post' => [
                'external_meeting_id' => 'schedule-header-meeting',
                'title' => 'Header Secret Meeting',
                'join_url' => 'https://zoom.us/j/header',
            ],
        ]);
        $payload = $this->decodePayload($response);
        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE external_meeting_id = ?", ['schedule-header-meeting']);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . ' ' . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('api', (string) ($run['join_request_source'] ?? ''));
    }

    public function testInvalidScheduleSecretDoesNotFallBackToAuthenticatedSession(): void
    {
        $userId = $this->createSchedulingUser();
        $this->configureBot('real-schedule-secret');

        $response = $this->runWebEndpoint('api/meeting_bot/schedule.php', $this->sessionFor($userId, 'csrf-schedule'), [
            'method' => 'POST',
            'headers' => ['X-Meeting-Bot-Schedule-Key' => 'wrong-secret'],
            'post' => [
                'csrf_token' => 'csrf-schedule',
                'external_meeting_id' => 'invalid-secret-meeting',
                'title' => 'Invalid Secret Meeting',
                'join_url' => 'https://zoom.us/j/invalid',
            ],
        ]);
        $runCount = Database::queryOne("SELECT COUNT(*) AS count FROM meeting_bot_runs WHERE external_meeting_id = ?", ['invalid-secret-meeting']);

        $this->assertSame(401, (int) ($response['status'] ?? 0));
        $this->assertSame(0, (int) ($runCount['count'] ?? 0));
    }

    public function testAuthenticatedCsrfFallbackStillSchedulesWithoutSecret(): void
    {
        $userId = $this->createSchedulingUser();
        $this->configureBot('unused-schedule-secret');

        $response = $this->runWebEndpoint('api/meeting_bot/schedule.php', $this->sessionFor($userId, 'csrf-schedule'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-schedule',
                'external_meeting_id' => 'auth-fallback-meeting',
                'title' => 'Authenticated Fallback Meeting',
                'join_url' => 'https://zoom.us/j/auth',
            ],
        ]);
        $payload = $this->decodePayload($response);
        $run = Database::queryOne("SELECT * FROM meeting_bot_runs WHERE external_meeting_id = ?", ['auth-fallback-meeting']);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . ' ' . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('manual', (string) ($run['join_request_source'] ?? ''));
    }

    public function testQuerySecretRequiresEnvironmentGate(): void
    {
        $this->configureBot('query-schedule-secret');

        $blocked = $this->runEndpointScript('api/meeting_bot/schedule.php', [
            'method' => 'POST',
            'query' => ['key' => 'query-schedule-secret'],
            'post' => [
                'external_meeting_id' => 'query-gated-meeting',
                'title' => 'Query Gated Meeting',
                'join_url' => 'https://zoom.us/j/query-blocked',
            ],
        ]);
        $allowed = $this->runEndpointScript('api/meeting_bot/schedule.php', [
            'method' => 'POST',
            'query' => ['key' => 'query-schedule-secret'],
            'env' => ['ALLOW_MEETING_QUERY_SECRET_AUTH' => '1'],
            'post' => [
                'external_meeting_id' => 'query-allowed-meeting',
                'title' => 'Query Allowed Meeting',
                'join_url' => 'https://zoom.us/j/query-allowed',
            ],
        ]);
        $payload = $this->decodePayload($allowed);
        $blockedRunCount = Database::queryOne("SELECT COUNT(*) AS count FROM meeting_bot_runs WHERE external_meeting_id = ?", ['query-gated-meeting']);

        $this->assertSame(401, (int) ($blocked['status'] ?? 0));
        $this->assertSame(0, (int) ($blockedRunCount['count'] ?? 0));
        $this->assertSame(200, (int) ($allowed['status'] ?? 0), (string) ($allowed['body'] ?? '') . ' ' . (string) ($allowed['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
    }

    private function configureBot(string $schedulingSecret): void
    {
        (new MeetingBotConfig())->save([
            'enabled' => true,
            'provider' => 'zoom',
            'scheduling_secret' => $schedulingSecret,
            'webhook_secret' => 'webhook-' . $schedulingSecret,
        ]);
    }

    private function createSchedulingUser(): int
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'superadmin', NOW())",
            ['meeting-bot-schedule-' . bin2hex(random_bytes(3)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $superAdminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole($userId, (int) ($superAdminRole['id'] ?? 0), $userId);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active'",
            [$userId]
        );
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, installed_by_user_id, updated_by_user_id)
             VALUES (1, 'calendar_meetings', 'installed', ?, ?)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL",
            [$userId, $userId]
        );

        return $userId;
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionFor(int $userId, string $csrfToken): array
    {
        return [
            'user_id' => $userId,
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => 1,
            'csrf_token' => $csrfToken,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodePayload(array $response): array
    {
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($payload, (string) ($response['body'] ?? ''));
        return $payload;
    }
}
