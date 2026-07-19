<?php
/**
 * Authentication Flow Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Tests\Support\EndpointHarness;

class AuthFlowTest extends DatabaseTestCase
{
    use EndpointHarness;

    private string $testEmail = 'flowtest@example.com';
    private string $testPassword = 'FlowTest123!';
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM users WHERE email = ?", [$this->testEmail]);
        Session::destroy();
        parent::tearDown();
    }
    
    public function testCompleteAuthFlow(): void
    {
        // 1. Create user
        $userId = Auth::createUser($this->testEmail, $this->testPassword, 'sales');
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
        
        // 2. Verify user exists in database
        $user = Database::queryOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $this->assertNotNull($user);
        $this->assertEquals($this->testEmail, $user['email']);
        
        // 3. Login
        $loginResult = Auth::login($this->testEmail, $this->testPassword);
        $this->assertTrue($loginResult);
        
        // 4. Verify session
        $this->assertTrue(Auth::check());
        $this->assertEquals($userId, Auth::userId());
        $this->assertEquals('sales', Auth::role());
        
        // 5. Get user data
        $currentUser = Auth::user();
        $this->assertNotNull($currentUser);
        $this->assertEquals($this->testEmail, $currentUser['email']);
        
        // 6. Verify last login was updated
        $updatedUser = Database::queryOne("SELECT last_login FROM users WHERE id = ?", [$userId]);
        $this->assertNotNull($updatedUser['last_login']);
        
        // 7. Logout
        Auth::logout();
        $this->assertFalse(Auth::check());
        $this->assertNull(Auth::userId());
    }
    
    public function testRoleBasedAccess(): void
    {
        // Create users with different roles
        $adminId = Auth::createUser('admin@test.com', 'password', 'admin');
        $salesId = Auth::createUser('sales@test.com', 'password', 'sales');
        $viewerId = Auth::createUser('viewer@test.com', 'password', 'viewer');
        
        try {
            // Test admin has all roles
            Auth::login('admin@test.com', 'password');
            $this->assertTrue(Auth::hasRole('admin'));
            $this->assertTrue(Auth::hasRole('sales'));
            $this->assertTrue(Auth::hasRole('viewer'));
            Auth::logout();
            
            // Test sales role
            Auth::login('sales@test.com', 'password');
            $this->assertTrue(Auth::hasRole('sales'));
            $this->assertFalse(Auth::hasRole('admin'));
            Auth::logout();
            
            // Test viewer role
            Auth::login('viewer@test.com', 'password');
            $this->assertTrue(Auth::hasRole('viewer'));
            $this->assertFalse(Auth::hasRole('admin'));
            $this->assertFalse(Auth::hasRole('sales'));
            Auth::logout();
        } finally {
            // Clean up
            Database::execute("DELETE FROM users WHERE id IN (?, ?, ?)", [$adminId, $salesId, $viewerId]);
        }
    }

    public function testInvalidTwoFactorCodeRendersInlineAccessibleErrorBelowCodeInput(): void
    {
        $userId = Auth::createUser($this->testEmail, $this->testPassword, 'sales');
        Database::execute(
            "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?",
            ['JBSWY3DPEHPK3PXP', $userId]
        );

        $response = $this->runWebEndpoint('public/login_2fa.php', [
            'pending_2fa' => true,
            'pending_2fa_user_id' => $userId,
            'pending_2fa_remember' => 0,
            'csrf_token' => 'csrf-login-2fa',
        ], [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-login-2fa',
                'code' => '000000',
            ],
        ]);

        $body = (string) ($response['body'] ?? '');
        $inputPosition = strpos($body, 'id="code"');
        $errorPosition = strpos($body, 'id="code-error"');
        $buttonPosition = strpos($body, 'type="submit"');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('That code is incorrect.', $body);
        $this->assertStringContainsString('class="input-group has-error"', $body);
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="code-error"', $body);
        $this->assertStringContainsString('id="code-error" class="error-message" role="alert" aria-live="assertive"', $body);
        $this->assertNotFalse($inputPosition);
        $this->assertNotFalse($errorPosition);
        $this->assertNotFalse($buttonPosition);
        $this->assertGreaterThan($inputPosition, $errorPosition, 'The error should render below the code input.');
        $this->assertLessThan($buttonPosition, $errorPosition, 'The error should render before the Verify button.');
    }
}
