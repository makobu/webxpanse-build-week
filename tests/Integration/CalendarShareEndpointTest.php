<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Authorization;
use CRM\Services\CalendarShareService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class CalendarShareEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testComposerLoadsForAuthenticatedCalendarUser(): void
    {
        $fixture = $this->seedEndpointFixture();

        $response = $this->runWebEndpoint('public/calendar_share.php', $this->webSession($fixture), [
            'method' => 'GET',
            'query' => ['contact_id' => (string) $fixture['contact_id']],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Share Calendar', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Prepare share', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('calendar_share\\/create.php', (string) ($response['body'] ?? ''));
    }

    public function testPublicSchedulePageLoadsActiveShareAndRejectsExpiredShare(): void
    {
        $fixture = $this->seedEndpointFixture();
        [$from, $to] = $this->shareWindow();
        $share = (new CalendarShareService())->createOrUpdateShare((int) $fixture['workspace_id'], (int) $fixture['user_id'], [
            'profile_id' => $fixture['profile_id'],
            'contact_id' => $fixture['contact_id'],
            'date_from' => $from,
            'date_to' => $to,
        ]);

        $active = $this->runEndpointScript('public/meeting_schedule.php', [
            'method' => 'GET',
            'query' => ['share' => (string) $share['token']],
        ]);

        $this->assertSame(200, (int) ($active['status'] ?? 0), (string) ($active['stderr'] ?? ''));
        $this->assertStringContainsString('Private invitation', (string) ($active['body'] ?? ''));
        $this->assertStringContainsString('client@example.test', (string) ($active['body'] ?? ''));
        $this->assertStringContainsString('calendar_share_token', (string) ($active['body'] ?? ''));

        Database::execute("UPDATE meeting_calendar_shares SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?", [(int) $share['id']]);
        $expired = $this->runEndpointScript('public/meeting_schedule.php', [
            'method' => 'GET',
            'query' => ['share' => (string) $share['token']],
        ]);

        $this->assertSame(404, (int) ($expired['status'] ?? 0));
        $this->assertStringContainsString('Meeting unavailable', (string) ($expired['body'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,user_id:int,membership_id:int,profile_id:int,contact_id:int}
     */
    private function seedEndpointFixture(): array
    {
        $userId = $this->createUser();
        $workspaceId = 1;
        Database::execute(
            "UPDATE workspaces SET uuid = '00000000-0000-4000-8000-000000000001', slug = 'calendar-share.test', name = 'Calendar Share Test' WHERE id = 1"
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active', role_slug = 'owner'",
            [$workspaceId, $userId]
        );
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, installed_by_user_id, updated_by_user_id)
             VALUES (?, 'calendar_meetings', 'installed', ?, ?)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL",
            [$workspaceId, $userId, $userId]
        );
        $membership = Database::queryOne("SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1", [$workspaceId, $userId]) ?: [];
        $contactId = $this->createContact($workspaceId, $userId);
        $profileId = $this->createProfile($workspaceId, $userId);

        return [
            'workspace_id' => $workspaceId,
            'workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'workspace_slug' => 'calendar-share.test',
            'workspace_name' => 'Calendar Share Test',
            'user_id' => $userId,
            'membership_id' => (int) ($membership['id'] ?? 0),
            'profile_id' => $profileId,
            'contact_id' => $contactId,
        ];
    }

    private function createUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (UUID(), ?, ?, 'admin', NOW())",
            ['calendar-share-endpoint-' . bin2hex(random_bytes(4)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );

        $userId = (int) Database::lastInsertId();
        Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

        return $userId;
    }

    private function createContact(int $workspaceId, int $userId): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, workspace_id, first_name, last_name, email, phone, company, assigned_to, created_by)
             VALUES (UUID(), ?, 'Client', 'Example', 'client@example.test', '+15555550100', 'Example Co', ?, ?)",
            [$workspaceId, $userId, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createProfile(int $workspaceId, int $userId): int
    {
        $slug = 'endpoint-share-' . bin2hex(random_bytes(4));
        Database::execute(
            "INSERT INTO meeting_booking_profiles (
                workspace_id, owner_user_id, slug, title, description, public_enabled, timezone,
                default_duration_minutes, allowed_durations_json, buffer_before_minutes, buffer_after_minutes,
                min_notice_hours, max_advance_days, allowed_meeting_formats_json, approval_mode, status, created_by
             ) VALUES (?, ?, ?, 'Endpoint booking', 'Choose a time with our team.', 1, 'UTC', 30, ?, 0, 0, 0, 45, ?, 'manual', 'active', ?)",
            [$workspaceId, $userId, $slug, json_encode([30]), json_encode(['google_meet', 'phone_call']), $userId]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function shareWindow(): array
    {
        $from = new \DateTimeImmutable('tomorrow');
        while ((int) $from->format('N') > 5) {
            $from = $from->modify('+1 day');
        }

        return [$from->format('Y-m-d'), $from->modify('+4 weekdays')->format('Y-m-d')];
    }

    /**
     * @param array{workspace_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,user_id:int,membership_id:int} $fixture
     * @return array<string,mixed>
     */
    private function webSession(array $fixture): array
    {
        return [
            'user_id' => (int) $fixture['user_id'],
            'user_role' => 'superadmin',
            'active_workspace_id' => (int) $fixture['workspace_id'],
            'active_workspace_uuid' => (string) $fixture['workspace_uuid'],
            'active_workspace_slug' => (string) $fixture['workspace_slug'],
            'active_workspace_name' => (string) $fixture['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $fixture['membership_id'],
            'csrf_token' => 'csrf-calendar-share',
            '__remember_restore_attempted' => true,
        ];
    }
}
