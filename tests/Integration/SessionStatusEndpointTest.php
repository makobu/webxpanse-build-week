<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class SessionStatusEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int) Auth::createUser('session-status@example.com', 'P@ssword123!', 'viewer');
    }

    public function testStatusEndpointReportsAuthenticatedSession(): void
    {
        $lastActivity = time() - 120;
        $response = $this->runWebEndpoint('api/session/status.php', $this->activeSession([
            'last_activity' => $lastActivity,
        ]), [
            'method' => 'GET',
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/dashboard.php'],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue((bool) ($payload['authenticated'] ?? false));
        $this->assertSame('authenticated', (string) ($payload['state'] ?? ''));
        $this->assertSame('csrf-session-status', (string) ($payload['csrf_token'] ?? ''));
        $this->assertFalse((bool) ($payload['renewed'] ?? true));
        $this->assertLessThanOrEqual(Session::lifetimeSeconds() - 110, (int) ($payload['seconds_remaining'] ?? 0));
        $this->assertGreaterThan(Session::lifetimeSeconds() - 140, (int) ($payload['seconds_remaining'] ?? 0));
    }

    public function testStatusEndpointRenewsAuthenticatedSessionWithValidCsrf(): void
    {
        $response = $this->runWebEndpoint('api/session/status.php', $this->activeSession([
            'last_activity' => time() - 600,
        ]), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['csrf_token' => 'csrf-session-status', 'action' => 'renew']),
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/settings.php'],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue((bool) ($payload['authenticated'] ?? false));
        $this->assertTrue((bool) ($payload['renewed'] ?? false));
        $this->assertGreaterThanOrEqual(Session::lifetimeSeconds() - 5, (int) ($payload['seconds_remaining'] ?? 0));
        $this->assertStringContainsString('reauth=1', (string) ($payload['reauth_url'] ?? ''));
    }

    public function testStatusEndpointRejectsRenewalWithInvalidCsrf(): void
    {
        $response = $this->runWebEndpoint('api/session/status.php', $this->activeSession([
            'last_activity' => time() - 600,
        ]), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['csrf_token' => 'wrong-token', 'action' => 'renew']),
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/settings.php'],
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('csrf_invalid', (string) ($payload['auth']['state'] ?? ''));
        $this->assertLessThan(Session::lifetimeSeconds() - 500, (int) ($payload['auth']['seconds_remaining'] ?? Session::lifetimeSeconds()));
    }

    public function testStatusEndpointRejectsRenewalWithMissingCsrf(): void
    {
        $response = $this->runWebEndpoint('api/session/status.php', $this->activeSession([
            'last_activity' => time() - 600,
        ]), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['action' => 'renew']),
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/settings.php'],
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('csrf_invalid', (string) ($payload['auth']['state'] ?? ''));
        $this->assertLessThan(Session::lifetimeSeconds() - 500, (int) ($payload['auth']['seconds_remaining'] ?? Session::lifetimeSeconds()));
    }

    public function testStatusEndpointCanRestoreRememberedSessionWithoutTreatingGetAsActivity(): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'viewer', 'active', 0, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active', role_slug = VALUES(role_slug)",
            [$this->userId]
        );
        $this->assertTrue(Auth::login('session-status@example.com', 'P@ssword123!', true));
        $rememberCookie = (string) ($_COOKIE['crm_remember'] ?? '');
        $this->assertNotSame('', $rememberCookie);

        $response = $this->runWebEndpoint('api/session/status.php', [], [
            'method' => 'GET',
            'cookie' => ['crm_remember' => $rememberCookie],
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/dashboard.php'],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue((bool) ($payload['authenticated'] ?? false));
        $this->assertFalse((bool) ($payload['renewed'] ?? true));
        $this->assertNotSame('', (string) ($payload['csrf_token'] ?? ''));
    }

    public function testStatusEndpointReportsExpiredSessionWithLoginUrl(): void
    {
        $session = $this->activeSession([
            'last_activity' => time() - 8000,
        ]);

        $response = $this->runWebEndpoint('api/session/status.php', $session, [
            'method' => 'GET',
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/startup_journey.php'],
        ]);

        $this->assertSame(401, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertFalse((bool) ($payload['authenticated'] ?? true));
        $this->assertSame('expired', (string) ($payload['state'] ?? ''));
        $this->assertSame('expired', (string) ($payload['auth']['state'] ?? ''));
        $this->assertStringContainsString('expired=1', (string) ($payload['auth']['login_url'] ?? ''));
        $this->assertStringContainsString('startup_journey.php', urldecode((string) ($payload['auth']['login_url'] ?? '')));
    }

    public function testPostloadUnauthorizedResponseIncludesExpiredAuthPayload(): void
    {
        $session = $this->activeSession([
            'last_activity' => time() - 8000,
        ]);

        $response = $this->runWebEndpoint('api/session/postload.php', $session, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'csrf_token' => 'csrf-session-status',
                'current_page' => 'dashboard.php',
            ], JSON_UNESCAPED_SLASHES),
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/dashboard.php'],
        ]);

        $this->assertSame(401, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('expired', (string) ($payload['auth']['state'] ?? ''));
        $this->assertStringContainsString('dashboard.php', urldecode((string) ($payload['auth']['login_url'] ?? '')));
    }

    public function testPostloadInvalidCsrfResponseIncludesAuthPayload(): void
    {
        $response = $this->runWebEndpoint('api/session/postload.php', $this->activeSession(), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'csrf_token' => 'wrong-token',
                'current_page' => 'dashboard.php',
            ], JSON_UNESCAPED_SLASHES),
            'server' => ['HTTP_REFERER' => 'http://localhost/crm/public/dashboard.php'],
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('csrf_invalid', (string) ($payload['auth']['state'] ?? ''));
        $this->assertTrue((bool) ($payload['auth']['authenticated'] ?? false));
        $this->assertStringContainsString('reauth=1', (string) ($payload['auth']['reauth_url'] ?? ''));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function activeSession(array $overrides = []): array
    {
        return array_merge([
            '__remember_restore_attempted' => true,
            'user_id' => $this->userId,
            'user_uuid' => 'session-status-user',
            'user_email' => 'session-status@example.com',
            'user_role' => 'viewer',
            'csrf_token' => 'csrf-session-status',
            'last_activity' => time(),
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($payload, (string) ($response['body'] ?? ''));
        return $payload;
    }
}
