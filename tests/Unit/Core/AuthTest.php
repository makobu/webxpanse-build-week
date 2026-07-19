<?php
/**
 * Authentication Tests
 */

namespace CRM\Tests\Unit\Core;

use CRM\Tests\DatabaseTestCase;
use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;

class AuthTest extends DatabaseTestCase
{
    private string $testEmail = 'test@example.com';
    private string $testPassword = 'TestPassword123!';
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $uuid = 'test-uuid-' . uniqid();
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, ?)",
            [$uuid, $this->testEmail, password_hash($this->testPassword, PASSWORD_DEFAULT), 'viewer']
        );
        $this->testUserId = (int) Database::lastInsertId();
    }
    
    protected function tearDown(): void
    {
        // Clean up
        if ($this->testUserId) {
            Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        }
        Session::destroy();
        parent::tearDown();
    }
    
    public function testLoginWithValidCredentials(): void
    {
        $result = Auth::login($this->testEmail, $this->testPassword);
        $this->assertTrue($result);
        $this->assertTrue(Auth::check());
    }
    
    public function testLoginWithInvalidEmail(): void
    {
        $result = Auth::login('nonexistent@example.com', $this->testPassword);
        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }
    
    public function testLoginWithInvalidPassword(): void
    {
        $result = Auth::login($this->testEmail, 'WrongPassword');
        $this->assertFalse($result);
        $this->assertFalse(Auth::check());
    }
    
    public function testLogout(): void
    {
        Auth::login($this->testEmail, $this->testPassword);
        $this->assertTrue(Auth::check());
        
        Auth::logout();
        $this->assertFalse(Auth::check());
    }
    
    public function testUserId(): void
    {
        Auth::login($this->testEmail, $this->testPassword);
        $userId = Auth::userId();
        $this->assertEquals($this->testUserId, $userId);
    }
    
    public function testUser(): void
    {
        Auth::login($this->testEmail, $this->testPassword);
        $user = Auth::user();
        
        $this->assertIsArray($user);
        $this->assertEquals($this->testEmail, $user['email']);
        $this->assertEquals('viewer', $user['role']);
    }
    
    public function testRole(): void
    {
        Auth::login($this->testEmail, $this->testPassword);
        $role = Auth::role();
        $this->assertEquals('viewer', $role);
    }
    
    public function testHasRole(): void
    {
        Auth::login($this->testEmail, $this->testPassword);
        $this->assertTrue(Auth::hasRole('viewer'));
        $this->assertFalse(Auth::hasRole('admin'));
    }
    
    public function testCreateUser(): void
    {
        $newEmail = 'newuser@example.com';
        $userId = Auth::createUser($newEmail, 'NewPassword123!', 'sales');
        
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
        
        // Clean up
        Database::execute("DELETE FROM users WHERE id = ?", [$userId]);
    }

    public function testCreateUserAssignsRequestedRbacRoleWhenAvailable(): void
    {
        $newEmail = 'superadmin-user@example.com';
        $userId = Auth::createUser($newEmail, 'NewPassword123!', 'superadmin');

        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);

        $user = Database::queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $this->assertSame('admin', $user['role'] ?? null);

        $assignedRole = Authorization::getUserRole($userId);
        $this->assertSame('superadmin', $assignedRole['slug'] ?? null);

        Database::execute("DELETE FROM users WHERE id = ?", [$userId]);
    }

    public function testLoginWithoutRememberMeDoesNotCreatePersistentToken(): void
    {
        Auth::login($this->testEmail, $this->testPassword, false);

        $row = Database::queryOne("SELECT COUNT(*) AS c FROM web_auth_tokens");
        $this->assertSame(0, (int) ($row['c'] ?? 0));
    }

    public function testSessionLifetimeUsesEnvironmentValue(): void
    {
        $previous = $_ENV['SESSION_LIFETIME'] ?? null;
        $_ENV['SESSION_LIFETIME'] = '7300';

        try {
            $this->assertSame(7300, Session::lifetimeSeconds());
        } finally {
            if ($previous === null) {
                unset($_ENV['SESSION_LIFETIME']);
            } else {
                $_ENV['SESSION_LIFETIME'] = $previous;
            }
        }
    }

    public function testAuthenticationCanSkipIdleActivityTouch(): void
    {
        Auth::login($this->testEmail, $this->testPassword, false);
        $lastActivity = time() - 120;
        Session::markAuthenticatedActivity($lastActivity);

        $this->assertTrue(Auth::check(false));
        $this->assertSame($lastActivity, Session::lastActivityAt());
    }

    public function testPassiveRequestDoesNotTouchIdleActivity(): void
    {
        Auth::login($this->testEmail, $this->testPassword, false);
        $lastActivity = time() - 120;
        Session::markAuthenticatedActivity($lastActivity);
        $previous = $_SERVER['HTTP_X_CRM_SESSION_PASSIVE'] ?? null;
        $_SERVER['HTTP_X_CRM_SESSION_PASSIVE'] = '1';

        try {
            $this->assertTrue(Auth::check());
            $this->assertSame($lastActivity, Session::lastActivityAt());
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_X_CRM_SESSION_PASSIVE']);
            } else {
                $_SERVER['HTTP_X_CRM_SESSION_PASSIVE'] = $previous;
            }
        }
    }

    public function testReauthenticationLoginUrlPreservesOnlySafeReturnPaths(): void
    {
        $url = Auth::loginUrl('/crm/public/settings.php?tab=ai', false, true);
        $this->assertStringContainsString('reauth=1', $url);
        $this->assertStringContainsString('settings.php', urldecode($url));

        $external = Auth::loginUrl('https://attacker.example/steal', false, true);
        $this->assertStringContainsString('reauth=1', $external);
        $this->assertStringNotContainsString('attacker.example', $external);
    }

    public function testReauthenticationPasswordSuccessSuspendsOldSessionBeforeTwoFactor(): void
    {
        Auth::login($this->testEmail, $this->testPassword, false);
        $this->assertTrue(Auth::check());
        Database::execute(
            'UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?',
            ['JBSWY3DPEHPK3PXP', $this->testUserId]
        );

        $this->assertTrue(Auth::login($this->testEmail, $this->testPassword, false));
        $this->assertTrue(Auth::isPending2FA());
        $this->assertFalse(Auth::check(false));
        $this->assertNull(Session::get('user_id'));
    }

    public function testIdleExpiryWithoutRememberMeClearsWebLoginAndReportsExpired(): void
    {
        Auth::login($this->testEmail, $this->testPassword, false);
        $this->assertTrue(Auth::check());

        Session::markAuthenticatedActivity(time() - Session::lifetimeSeconds() - 5);

        $this->assertFalse(Auth::check());
        $this->assertTrue(Session::isIdleExpired());

        $payload = Auth::authPayload();
        $this->assertSame('expired', $payload['state']);
        $this->assertStringContainsString('expired=1', (string) ($payload['login_url'] ?? ''));
        $this->assertArrayNotHasKey('crm_remember', $_COOKIE);
    }

    public function testRememberMeRestoresAfterIdleExpiryWithoutRevokingToken(): void
    {
        $this->addDefaultWorkspaceMembership($this->testUserId);
        Auth::login($this->testEmail, $this->testPassword, true);

        $selector = (string) Database::queryOne(
            "SELECT selector FROM web_auth_tokens WHERE user_id = ? LIMIT 1",
            [$this->testUserId]
        )['selector'];
        $this->assertNotSame('', $selector);

        Session::markAuthenticatedActivity(time() - Session::lifetimeSeconds() - 5);

        $this->assertTrue(Auth::check());
        $this->assertSame($this->testUserId, Auth::userId());
        $this->assertFalse(Session::isIdleExpired());

        $liveToken = Database::queryOne(
            "SELECT COUNT(*) AS c FROM web_auth_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()",
            [$this->testUserId]
        );
        $this->assertGreaterThan(0, (int) ($liveToken['c'] ?? 0));
        $this->assertArrayHasKey('crm_remember', $_COOKIE);
    }

    public function testRememberMeCanRestoreSessionAfterSessionLoss(): void
    {
        $this->addDefaultWorkspaceMembership($this->testUserId);
        Auth::login($this->testEmail, $this->testPassword, true);

        $row = Database::queryOne(
            "SELECT selector FROM web_auth_tokens WHERE user_id = ? LIMIT 1",
            [$this->testUserId]
        );
        $this->assertNotEmpty($row['selector'] ?? null);
        $this->assertArrayHasKey('crm_remember', $_COOKIE);

        Session::destroy();

        $this->assertTrue(Auth::check());
        $this->assertSame($this->testUserId, Auth::userId());
    }

    public function testRememberMeDiscardedWhenNoWorkspaceCanBeRestored(): void
    {
        Auth::login($this->testEmail, $this->testPassword, true);

        $selector = (string) Database::queryOne(
            "SELECT selector FROM web_auth_tokens WHERE user_id = ? LIMIT 1",
            [$this->testUserId]
        )['selector'];
        $this->assertNotSame('', $selector);

        Session::destroy();

        $this->assertFalse(Auth::check());
        $this->assertArrayNotHasKey('crm_remember', $_COOKIE);

        $revoked = Database::queryOne(
            "SELECT revoked_at FROM web_auth_tokens WHERE selector = ? LIMIT 1",
            [$selector]
        );
        $this->assertNotEmpty($revoked['revoked_at'] ?? null);
    }

    public function testLogoutRevokesRememberMeTokenAndClearsCookie(): void
    {
        Auth::login($this->testEmail, $this->testPassword, true);

        $selector = (string) Database::queryOne(
            "SELECT selector FROM web_auth_tokens WHERE user_id = ? LIMIT 1",
            [$this->testUserId]
        )['selector'];

        $this->assertNotSame('', $selector);
        $this->assertArrayHasKey('crm_remember', $_COOKIE);

        Auth::logout();

        $revokedAt = Database::queryOne(
            "SELECT revoked_at FROM web_auth_tokens WHERE selector = ? LIMIT 1",
            [$selector]
        );

        $this->assertNotEmpty($revokedAt['revoked_at'] ?? null);
        $this->assertArrayNotHasKey('crm_remember', $_COOKIE);
        $this->assertFalse(Auth::check());
    }

    private function addDefaultWorkspaceMembership(int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'viewer', 'active', 0, NOW())
             ON DUPLICATE KEY UPDATE membership_status = 'active', role_slug = VALUES(role_slug)",
            [$userId]
        );
    }
}
