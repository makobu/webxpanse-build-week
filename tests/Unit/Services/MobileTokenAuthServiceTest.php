<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\MobileTokenAuthService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Tests\DatabaseTestCase;
use PragmaRX\Google2FA\Google2FA;

class MobileTokenAuthServiceTest extends DatabaseTestCase
{
    public function testBootstrapBrandingUrlsHonorSubdirectoryInstallFromMobileRequest(): void
    {
        $original = [
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? null,
            'HTTPS' => $_SERVER['HTTPS'] ?? null,
            'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        ];

        try {
            $_SERVER['HTTP_HOST'] = '10.0.2.2';
            $_SERVER['HTTPS'] = 'off';
            $_SERVER['SCRIPT_NAME'] = '/crm/api/mobile/bootstrap.php';
            $_SERVER['REQUEST_URI'] = '/crm/api/mobile/bootstrap.php';

            $payload = (new MobileTokenAuthService())->bootstrapPayload();

            $this->assertSame(
                'http://10.0.2.2/crm/public/assets/images/logo-mobile.png',
                (string) ($payload['branding']['logo_url'] ?? '')
            );
            $this->assertSame(
                'http://10.0.2.2/crm/public/assets/images/clarity-logo-128.png',
                (string) ($payload['branding']['clarity_icon_url'] ?? '')
            );
        } finally {
            foreach ($original as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function testBuildUserPayloadIncludesTaskAndNotificationScopeFlags(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('mobile-owner-', true), 'mobile-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $ownerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('mobile-viewer-', true), 'mobile-viewer@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $viewerUserId = (int) Database::lastInsertId();

        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        $viewerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'viewer' LIMIT 1");
        Authorization::assignUserRole($ownerUserId, (int) ($ownerRole['id'] ?? 0), $ownerUserId);
        Authorization::assignUserRole($viewerUserId, (int) ($viewerRole['id'] ?? 0), $ownerUserId);

        $service = new MobileTokenAuthService();

        $ownerPayload = $service->buildUserPayload(['user_id' => $ownerUserId]);
        $viewerPayload = $service->buildUserPayload(['user_id' => $viewerUserId]);

        $this->assertTrue($ownerPayload['scopes']['can_view_all_tasks']);
        $this->assertTrue($ownerPayload['scopes']['can_view_all_notifications']);
        $this->assertFalse($viewerPayload['scopes']['can_view_all_tasks']);
        $this->assertFalse($viewerPayload['scopes']['can_view_all_notifications']);
    }

    public function testIssueTokenPairIncludesAbsoluteExpiryTimestamps(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('mobile-expiry-', true), 'mobile-expiry@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $service = new MobileTokenAuthService();
        $session = $service->issueTokenPair([
            'id' => $userId,
            'uuid' => 'mobile-expiry-user',
            'email' => 'mobile-expiry@example.com',
            'role' => 'viewer',
        ]);

        $this->assertNotEmpty($session['access_expires_at'] ?? null);
        $this->assertNotEmpty($session['refresh_expires_at'] ?? null);
        $this->assertNotFalse(strtotime((string) ($session['access_expires_at'] ?? '')));
        $this->assertNotFalse(strtotime((string) ($session['refresh_expires_at'] ?? '')));
    }

    public function testBuildUserPayloadIncludesActiveWorkspaceAndMemberships(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mobile Workspace',
            'workspace_slug' => 'mobile-workspace',
            'first_name' => 'Mobile',
            'last_name' => 'Owner',
            'email' => 'mobile-owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $service = new MobileTokenAuthService();
        $payload = $service->buildUserPayload([
            'user_id' => (int) ($result['user_id'] ?? 0),
            'workspace_id' => (int) ($result['workspace_id'] ?? 0),
        ]);

        $this->assertSame('mobile-workspace', (string) ($payload['active_workspace']['slug'] ?? ''));
        $this->assertNotEmpty($payload['memberships']);
        $this->assertTrue((bool) ($payload['active_workspace']['can_manage_members'] ?? false));
        $this->assertTrue((bool) ($payload['active_workspace']['can_manage_owners'] ?? false));
        $this->assertTrue((bool) ($payload['active_workspace']['can_transfer_ownership'] ?? false));
        $this->assertSame('active', (string) ($payload['active_workspace']['membership_status'] ?? ''));
        $this->assertSame(0, (int) ($payload['pending_invite_count'] ?? -1));
        $this->assertSame('active', (string) ($payload['subscription_status'] ?? ''));
        $this->assertFalse((bool) ($payload['billing_blocked'] ?? true));
        $this->assertSame('', (string) ($payload['ai_blocked_reason'] ?? ''));
        $this->assertNull($payload['workspace_billing'] ?? null);
    }

    public function testBuildUserPayloadIgnoresInactiveWorkspaceHintForAccessResolution(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mobile Active Workspace',
            'workspace_slug' => 'mobile-active-workspace',
            'first_name' => 'Mobile',
            'last_name' => 'Owner',
            'email' => 'mobile.active.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $userId = (int) ($result['user_id'] ?? 0);
        $activeWorkspaceId = (int) ($result['workspace_id'] ?? 0);

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Suspended Membership Workspace', 'suspended-membership-workspace', 'active', 'inactive', NOW(), NOW())",
            [uniqid('workspace-', true)]
        );
        $inactiveWorkspaceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'suspended', 0, NOW())",
            [$inactiveWorkspaceId, $userId]
        );

        $service = new MobileTokenAuthService();
        $payload = $service->buildUserPayload([
            'user_id' => $userId,
            'workspace_id' => $inactiveWorkspaceId,
        ]);

        $this->assertSame($activeWorkspaceId, (int) ($payload['active_workspace']['id'] ?? 0));
        $this->assertSame('mobile-active-workspace', (string) ($payload['active_workspace']['slug'] ?? ''));
    }

    public function testBuildUserPayloadIncludesLegacyCompatibilityOnlyWhenExplicitlyEnabled(): void
    {
        $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Legacy Compatibility Workspace',
            'workspace_slug' => 'legacy-compatibility-workspace',
            'first_name' => 'Legacy',
            'last_name' => 'Owner',
            'email' => 'legacy-owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        Database::execute(
            "UPDATE workspace_billing_settings
             SET billing_mode = 'local_provider',
                 enabled = 1,
                 updated_at = NOW()
             WHERE id = 1"
        );

        $service = new MobileTokenAuthService();
        $payload = $service->buildUserPayload([
            'user_id' => (int) ($result['user_id'] ?? 0),
            'workspace_id' => (int) ($result['workspace_id'] ?? 0),
        ]);

        $this->assertSame('active', (string) ($payload['subscription_status'] ?? ''));
        $this->assertIsArray($payload['workspace_billing'] ?? null);
        $this->assertSame('active', (string) ($payload['workspace_billing']['status'] ?? 'inactive'));
        $this->assertFalse((bool) ($payload['workspace_billing']['is_trial_active'] ?? true));
        $this->assertSame('', (string) ($payload['workspace_billing']['trial_ends_at'] ?? 'unexpected'));
        $this->assertFalse((bool) ($payload['workspace_billing']['show_prompt'] ?? true));
    }

    public function testPasswordLoginWithEnabledTwoFactorReturnsChallengeWithoutTokens(): void
    {
        $seed = $this->provisionMobileWorkspaceUser('mobile-2fa-login', 'mobile.2fa.login@example.com');
        $this->enableTwoFactor((int) $seed['user_id']);

        $result = (new MobileTokenAuthService())->login('mobile.2fa.login@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
            'device_id' => 'login-device',
        ]);

        $this->assertTrue((bool) ($result['requires_2fa'] ?? false));
        $this->assertFalse((bool) ($result['requires_2fa_setup'] ?? true));
        $this->assertSame('two_factor_required', (string) ($result['auth_state'] ?? ''));
        $this->assertArrayNotHasKey('session', $result);
        $this->assertNotEmpty($result['challenge_token'] ?? '');
        $this->assertSame('login_2fa', (string) ($result['challenge_purpose'] ?? ''));
        $this->assertTrue((bool) ($result['security']['two_factor_enabled'] ?? false));

        $challenge = $this->challengeRow((string) $result['challenge_token']);
        $this->assertSame('login_2fa', (string) ($challenge['purpose'] ?? ''));
        $this->assertSame((int) $seed['workspace_id'], (int) ($challenge['workspace_id'] ?? 0));
        $this->assertSame(0, (int) ($challenge['failed_attempts'] ?? -1));
    }

    public function testValidTotpConsumesLoginChallengeAndIssuesWorkspaceScopedTokens(): void
    {
        $seed = $this->provisionMobileWorkspaceUser('mobile-2fa-verify', 'mobile.2fa.verify@example.com');
        $secret = $this->enableTwoFactor((int) $seed['user_id']);
        $service = new MobileTokenAuthService();
        $challenge = $service->login('mobile.2fa.verify@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
            'device_id' => 'challenge-device',
        ]);

        $session = $service->verifyTwoFactor((string) $challenge['challenge_token'], $this->currentOtp($secret), [
            'app_version' => 'test-suite',
        ]);

        $this->assertSame('authenticated', (string) ($session['auth_state'] ?? ''));
        $this->assertNotEmpty($session['access_token'] ?? '');
        $this->assertNotEmpty($session['refresh_token'] ?? '');
        $this->assertSame('challenge-device', (string) ($session['device_id'] ?? ''));
        $this->assertSame((int) $seed['workspace_id'], (int) ($session['user']['active_workspace']['id'] ?? 0));
        $this->assertNotEmpty($this->challengeRow((string) $challenge['challenge_token'])['consumed_at'] ?? null);
    }

    public function testLoginChallengeRejectsInvalidExpiredConsumedWrongPurposeAndMaxAttempts(): void
    {
        $seed = $this->provisionMobileWorkspaceUser('mobile-2fa-hardening', 'mobile.2fa.hardening@example.com');
        $secret = $this->enableTwoFactor((int) $seed['user_id']);
        $service = new MobileTokenAuthService();

        $invalid = $service->login('mobile.2fa.hardening@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        $this->assertChallengeRejected(static fn() => $service->verifyTwoFactor((string) $invalid['challenge_token'], '000000'));
        $this->assertSame(1, (int) ($this->challengeRow((string) $invalid['challenge_token'])['failed_attempts'] ?? 0));

        $expired = $service->login('mobile.2fa.hardening@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        Database::execute(
            "UPDATE mobile_auth_challenges SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE challenge_hash = ?",
            [hash('sha256', (string) $expired['challenge_token'])]
        );
        $this->assertChallengeRejected(fn() => $service->verifyTwoFactor((string) $expired['challenge_token'], $this->currentOtp($secret)));

        $consumed = $service->login('mobile.2fa.hardening@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        $service->verifyTwoFactor((string) $consumed['challenge_token'], $this->currentOtp($secret));
        $this->assertChallengeRejected(fn() => $service->verifyTwoFactor((string) $consumed['challenge_token'], $this->currentOtp($secret)));

        Database::execute(
            "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?",
            [(int) $seed['user_id']]
        );
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA((int) $seed['workspace_id'], true, (int) $seed['user_id']);
        $setup = $service->login('mobile.2fa.hardening@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        $this->assertSame('setup_2fa', (string) ($setup['challenge_purpose'] ?? ''));
        $this->assertChallengeRejected(static fn() => $service->verifyTwoFactor((string) $setup['challenge_token'], '000000'));

        (new WorkspaceSecuritySettingsService())->setRequireMember2FA((int) $seed['workspace_id'], false, (int) $seed['user_id']);
        $this->enableTwoFactor((int) $seed['user_id'], $secret);
        $maxed = $service->login('mobile.2fa.hardening@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        for ($i = 0; $i < 5; $i++) {
            $this->assertChallengeRejected(static fn() => $service->verifyTwoFactor((string) $maxed['challenge_token'], '000000'));
        }
        $maxedRow = $this->challengeRow((string) $maxed['challenge_token']);
        $this->assertSame(5, (int) ($maxedRow['failed_attempts'] ?? 0));
        $this->assertNotEmpty($maxedRow['consumed_at'] ?? null);
    }

    public function testRecoveryCodeWorksOnceForMobileTwoFactorLogin(): void
    {
        $seed = $this->provisionMobileWorkspaceUser('mobile-2fa-recovery', 'mobile.2fa.recovery@example.com');
        $this->enableTwoFactor((int) $seed['user_id']);
        $recoveryCode = 'ABCD1234';
        Database::execute(
            "INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)",
            [(int) $seed['user_id'], hash('sha256', $recoveryCode)]
        );

        $service = new MobileTokenAuthService();
        $first = $service->login('mobile.2fa.recovery@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        $session = $service->verifyTwoFactor((string) $first['challenge_token'], strtolower($recoveryCode));
        $this->assertNotEmpty($session['access_token'] ?? '');
        $this->assertNotEmpty(Database::queryOne(
            "SELECT used_at FROM user_recovery_codes WHERE user_id = ? LIMIT 1",
            [(int) $seed['user_id']]
        )['used_at'] ?? null);

        $second = $service->login('mobile.2fa.recovery@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
        ]);
        $this->assertChallengeRejected(static fn() => $service->verifyTwoFactor((string) $second['challenge_token'], $recoveryCode));
    }

    public function testWorkspaceRequiredTwoFactorSetupFlowEnables2FAAndIssuesTokens(): void
    {
        $seed = $this->provisionMobileWorkspaceUser('mobile-2fa-setup', 'mobile.2fa.setup@example.com');
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA((int) $seed['workspace_id'], true, (int) $seed['user_id']);
        $service = new MobileTokenAuthService();

        $login = $service->login('mobile.2fa.setup@example.com', 'P@ssword123!', [
            'workspace_id' => (int) $seed['workspace_id'],
            'device_id' => 'setup-device',
        ]);

        $this->assertTrue((bool) ($login['requires_2fa'] ?? false));
        $this->assertTrue((bool) ($login['requires_2fa_setup'] ?? false));
        $this->assertSame('two_factor_setup_required', (string) ($login['auth_state'] ?? ''));
        $this->assertArrayNotHasKey('session', $login);
        $this->assertFalse((bool) ($login['security']['two_factor_enabled'] ?? true));
        $this->assertTrue((bool) ($login['security']['workspace_requires_2fa'] ?? false));

        $setup = $service->startTwoFactorSetup((string) $login['challenge_token']);
        $secret = (string) ($setup['secret'] ?? '');
        $this->assertSame('two_factor_setup_pending', (string) ($setup['auth_state'] ?? ''));
        $this->assertNotSame('', $secret);
        $this->assertStringContainsString('otpauth://', (string) ($setup['otpauth_url'] ?? ''));

        $session = $service->verifyTwoFactorSetup((string) $login['challenge_token'], $secret, $this->currentOtp($secret));

        $this->assertSame('authenticated', (string) ($session['auth_state'] ?? ''));
        $this->assertNotEmpty($session['access_token'] ?? '');
        $this->assertCount(10, (array) ($session['recovery_codes'] ?? []));
        $this->assertSame('setup-device', (string) ($session['device_id'] ?? ''));
        $this->assertSame((int) $seed['workspace_id'], (int) ($session['user']['active_workspace']['id'] ?? 0));
        $this->assertTrue((bool) ($session['security']['two_factor_enabled'] ?? false));
        $this->assertNotEmpty($this->challengeRow((string) $login['challenge_token'])['consumed_at'] ?? null);

        $user = Database::queryOne("SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?", [(int) $seed['user_id']]);
        $this->assertSame(1, (int) ($user['two_factor_enabled'] ?? 0));
        $this->assertSame($secret, (string) ($user['two_factor_secret'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionMobileWorkspaceUser(string $slug, string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => ucwords(str_replace('-', ' ', $slug)),
            'workspace_slug' => $slug,
            'first_name' => 'Mobile',
            'last_name' => 'Tester',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function enableTwoFactor(int $userId, string $secret = 'JBSWY3DPEHPK3PXP'): string
    {
        Database::execute(
            "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?",
            [$secret, $userId]
        );

        return $secret;
    }

    /**
     * @return array<string,mixed>
     */
    private function challengeRow(string $challengeToken): array
    {
        return Database::queryOne(
            "SELECT * FROM mobile_auth_challenges WHERE challenge_hash = ? LIMIT 1",
            [hash('sha256', $challengeToken)]
        ) ?: [];
    }

    private function currentOtp(string $secret): string
    {
        return (string) (new Google2FA())->getCurrentOtp($secret);
    }

    private function assertChallengeRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the mobile 2FA challenge to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }
}
