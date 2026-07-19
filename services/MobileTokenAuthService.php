<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use PragmaRX\Google2FA\Google2FA;
use CRM\Services\WorkspaceBillingService;
use CRM\Services\SaaSBillingService;

class MobileTokenAuthService
{
    private const ACCESS_TTL_SECONDS = 3600;
    private const REFRESH_TTL_SECONDS = 2592000;
    private const CHALLENGE_TTL_SECONDS = 600;
    private const CHALLENGE_PURPOSE_LOGIN_2FA = 'login_2fa';
    private const CHALLENGE_PURPOSE_SETUP_2FA = 'setup_2fa';
    private const MAX_CHALLENGE_FAILED_ATTEMPTS = 5;
    private const REFRESH_SLIDE_THRESHOLD_SECONDS = 604800; // slide refresh window when < 7 days remain

    private ?WorkspaceBillingService $workspaceBillingService = null;

    public function bootstrapPayload(): array
    {
        $minVersion = trim((string) ($_ENV['MOBILE_MIN_SUPPORTED_VERSION'] ?? ''));
        $latestVersion = trim((string) ($_ENV['MOBILE_LATEST_VERSION'] ?? ''));
        $upgradeUrl = trim((string) ($_ENV['MOBILE_UPGRADE_URL'] ?? ''));
        $forceUpgrade = filter_var($_ENV['MOBILE_FORCE_UPGRADE'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'app' => [
                'name' => \brandProductName(),
                'display_name' => $_ENV['BRAND_PRODUCT_NAME'] ?? 'webXpanse',
                'tagline_primary' => \brandTaglinePrimary(),
                'tagline_secondary' => \brandTaglineSecondary(),
                'positioning_line' => \brandPositioningLine(),
                'outcome_line' => \brandOutcomeLine(),
                'version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
                'timezone' => date_default_timezone_get(),
            ],
            'branding' => [
                'accent_blue' => '#2563eb',
                'midnight_black' => '#1a1a1a',
                'charcoal_grey' => '#4d4d4d',
                'logo_url' => $this->absolutePublicUrl('/assets/images/logo-mobile.png'),
                'logo_2x_url' => $this->absolutePublicUrl('/assets/images/logo-web@2x.png'),
                'clarity_icon_url' => $this->absolutePublicUrl('/assets/images/clarity-logo-128.png'),
                'clarity_icon_2x_url' => $this->absolutePublicUrl('/assets/images/clarity-logo-256.png'),
            ],
            'auth' => [
                'mode' => 'token',
                'supports_password_login' => true,
                'supports_two_factor' => true,
                'supports_refresh' => true,
            ],
            'release' => [
                'min_supported_version' => $minVersion,
                'latest_version' => $latestVersion,
                'upgrade_url' => $upgradeUrl,
                'force_upgrade' => $forceUpgrade,
            ],
        ];
    }

    public function login(string $email, string $password, array $deviceContext = []): array
    {
        $user = $this->findUserByEmail($email);
        if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? '')) || !$this->temporaryUserIsActive($user)) {
            error_log('Mobile login failed for email=' . strtolower(trim($email)));
            throw new \RuntimeException('Invalid email or password.');
        }

        $workspaceAccess = $this->resolveWorkspaceAccessForUser(
            (int) $user['id'],
            isset($deviceContext['workspace_slug']) ? (string) $deviceContext['workspace_slug'] : null,
            !empty($deviceContext['workspace_id']) ? (int) $deviceContext['workspace_id'] : null,
            false
        );
        $workspaceRequiresTwoFactor = $this->workspaceRequiresTwoFactor($workspaceAccess);
        $twoFactorEnabled = !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']);

        if ($twoFactorEnabled) {
            return $this->createChallengeResponse(
                $user,
                $workspaceAccess,
                $deviceContext,
                self::CHALLENGE_PURPOSE_LOGIN_2FA,
                'two_factor_required'
            );
        }

        if ($workspaceRequiresTwoFactor) {
            return $this->createChallengeResponse(
                $user,
                $workspaceAccess,
                $deviceContext,
                self::CHALLENGE_PURPOSE_SETUP_2FA,
                'two_factor_setup_required'
            );
        }

        return [
            'requires_2fa' => false,
            'auth_state' => 'authenticated',
            'security' => $this->buildSecurityPayload($user, $workspaceAccess, 'authenticated'),
            'session' => $this->issueTokenPair($user, $deviceContext, $workspaceAccess),
        ];
    }

    public function verifyTwoFactor(string $challengeToken, string $code, array $deviceContext = []): array
    {
        $challenge = $this->validChallenge($challengeToken, self::CHALLENGE_PURPOSE_LOGIN_2FA);

        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $challenge['user_id']]);
        if (!$user || empty($user['two_factor_secret'])) {
            throw new \RuntimeException('Two-factor authentication is not available for this account.');
        }

        $cleanCode = preg_replace('/\s+/', '', $code);
        $google2fa = new Google2FA();
        $validTotp = $google2fa->verifyKey((string) $user['two_factor_secret'], $cleanCode);
        $validRecovery = !$validTotp && $this->verifyRecoveryCode((int) $user['id'], $cleanCode);

        if (!$validTotp && !$validRecovery) {
            error_log('Mobile 2FA verification failed for user_id=' . (int) ($user['id'] ?? 0));
            $this->registerChallengeFailure($challenge);
        }

        $this->consumeChallenge($challenge);
        $deviceContext = $this->deviceContextFromChallenge($challenge, $deviceContext);

        return array_merge($this->issueTokenPair($user, $deviceContext, $this->resolveWorkspaceAccessForUser(
            (int) $user['id'],
            isset($deviceContext['workspace_slug']) ? (string) $deviceContext['workspace_slug'] : null,
            !empty($deviceContext['workspace_id']) ? (int) $deviceContext['workspace_id'] : null
        )), [
            'auth_state' => 'authenticated',
        ]);
    }

    public function startTwoFactorSetup(string $challengeToken): array
    {
        $challenge = $this->validChallenge($challengeToken, self::CHALLENGE_PURPOSE_SETUP_2FA);
        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $challenge['user_id']]);
        if (!$user) {
            throw new \RuntimeException('The user for this challenge was not found.');
        }
        if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
            throw new \RuntimeException('Two-factor authentication is already enabled for this account.');
        }

        $secretData = Auth::generate2FASecret((string) ($user['email'] ?? ''));
        $otpauthUrl = (string) ($secretData['qr_code_url'] ?? '');

        return [
            'auth_state' => 'two_factor_setup_pending',
            'challenge_token' => $challengeToken,
            'expires_in' => max(0, strtotime((string) $challenge['expires_at']) - time()),
            'secret' => (string) ($secretData['secret'] ?? ''),
            'otpauth_url' => $otpauthUrl,
            'qr_code_url' => $otpauthUrl !== ''
                ? 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauthUrl)
                : '',
            'workspace' => $this->workspaceChallengePayload($challenge),
            'security' => $this->buildSecurityPayload($user, $this->workspaceAccessFromChallenge($challenge), 'two_factor_setup_pending'),
        ];
    }

    public function verifyTwoFactorSetup(string $challengeToken, string $secret, string $code, array $deviceContext = []): array
    {
        $challenge = $this->validChallenge($challengeToken, self::CHALLENGE_PURPOSE_SETUP_2FA);
        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $challenge['user_id']]);
        if (!$user) {
            throw new \RuntimeException('The user for this challenge was not found.');
        }
        if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
            throw new \RuntimeException('Two-factor authentication is already enabled for this account.');
        }

        $result = Auth::enable2FA((int) $user['id'], trim($secret), trim($code));
        if (empty($result['success'])) {
            $this->registerChallengeFailure($challenge, (string) ($result['error'] ?? 'Invalid verification code.'));
        }

        $this->consumeChallenge($challenge);
        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $challenge['user_id']]) ?? $user;
        $deviceContext = $this->deviceContextFromChallenge($challenge, $deviceContext);

        return array_merge($this->issueTokenPair($user, $deviceContext, $this->resolveWorkspaceAccessForUser(
            (int) $user['id'],
            isset($deviceContext['workspace_slug']) ? (string) $deviceContext['workspace_slug'] : null,
            !empty($deviceContext['workspace_id']) ? (int) $deviceContext['workspace_id'] : null
        )), [
            'auth_state' => 'authenticated',
            'recovery_codes' => array_values((array) ($result['recovery_codes'] ?? [])),
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        $record = $this->findTokenRecordByRefresh($refreshToken);
        if (!$record) {
            error_log('Mobile refresh failed: token invalid or expired.');
            throw new \RuntimeException('The refresh token is invalid or has expired.');
        }

        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $record['user_id']]);
        if (!$user) {
            error_log('Mobile refresh failed: user missing for token row id=' . (int) ($record['id'] ?? 0));
            throw new \RuntimeException('The user for this session was not found.');
        }

        Database::execute(
            "UPDATE mobile_auth_tokens SET revoked_at = NOW() WHERE id = ?",
            [(int) $record['id']]
        );

        return $this->issueTokenPair($user, [
            'device_id' => $record['device_id'] ?? null,
            'device_name' => $record['device_name'] ?? null,
            'platform' => $record['platform'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'push_token' => $record['push_token'] ?? null,
            'push_provider' => $record['push_provider'] ?? null,
            'push_preferences_json' => $record['push_preferences_json'] ?? null,
            'device_locale' => $record['device_locale'] ?? null,
            'workspace_id' => $record['workspace_id'] ?? null,
        ], $this->resolveWorkspaceAccessForUser((int) $user['id'], null, (int) ($record['workspace_id'] ?? 0)));
    }

    public function revokeByAccessToken(string $accessToken): void
    {
        Database::execute(
            "UPDATE mobile_auth_tokens SET revoked_at = NOW() WHERE access_token_hash = ? AND revoked_at IS NULL",
            [hash('sha256', $accessToken)]
        );
    }

    public function authenticate(string $accessToken): ?array
    {
        $record = Database::queryOne(
            "SELECT t.*, u.email, u.role, u.uuid, u.first_name, u.last_name
             FROM mobile_auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.access_token_hash = ?
               AND t.revoked_at IS NULL
               AND t.access_expires_at > NOW()
             LIMIT 1",
            [hash('sha256', $accessToken)]
        );

        if (!$record) {
            return null;
        }

        if (empty($record['workspace_id'])) {
            $workspaceAccess = $this->resolveWorkspaceAccessForUser((int) ($record['user_id'] ?? 0));
            if (!empty($workspaceAccess['workspace_id'])) {
                $record['workspace_id'] = (int) $workspaceAccess['workspace_id'];
                Database::execute(
                    "UPDATE mobile_auth_tokens SET workspace_id = ? WHERE id = ?",
                    [(int) $workspaceAccess['workspace_id'], (int) $record['id']]
                );
            }
        }

        Database::execute(
            "UPDATE mobile_auth_tokens
             SET last_used_at = NOW(),
                 refresh_expires_at = CASE
                     WHEN refresh_expires_at < DATE_ADD(NOW(), INTERVAL ? SECOND)
                     THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                     ELSE refresh_expires_at
                 END
             WHERE id = ?",
            [self::REFRESH_SLIDE_THRESHOLD_SECONDS, self::REFRESH_TTL_SECONDS, (int) $record['id']]
        );

        return $record;
    }

    public function diagnoseFailure(string $accessToken): string
    {
        $record = Database::queryOne(
            "SELECT revoked_at, access_expires_at
             FROM mobile_auth_tokens
             WHERE access_token_hash = ?
             LIMIT 1",
            [hash('sha256', $accessToken)]
        );

        if (!$record) {
            return 'invalid';
        }

        if (!empty($record['revoked_at'])) {
            return 'revoked';
        }

        $expiresAt = isset($record['access_expires_at']) ? strtotime((string) $record['access_expires_at']) : false;
        if ($expiresAt !== false && $expiresAt <= time()) {
            return 'expired';
        }

        return 'unknown';
    }

    public function registerPushToken(
        int $tokenRowId,
        ?string $pushToken,
        ?string $appVersion = null,
        ?string $pushProvider = null,
        ?array $preferences = null,
        ?string $deviceLocale = null
    ): void
    {
        if ($tokenRowId <= 0) {
            throw new \RuntimeException('Invalid mobile session.');
        }

        Database::execute(
            "UPDATE mobile_auth_tokens
             SET push_token = ?,
                 push_provider = CASE WHEN ? IS NULL THEN push_provider ELSE ? END,
                 push_preferences_json = CASE WHEN ? IS NULL THEN push_preferences_json ELSE ? END,
                 device_locale = COALESCE(?, device_locale),
                 push_token_updated_at = CASE WHEN ? IS NULL OR ? = '' THEN push_token_updated_at ELSE NOW() END,
                 push_disabled_at = CASE WHEN ? IS NULL OR ? = '' THEN NOW() ELSE NULL END,
                 app_version = COALESCE(?, app_version),
                 last_push_error = NULL,
                 last_used_at = NOW()
             WHERE id = ?",
            [
                $this->sanitizeNullableString($pushToken, 255),
                $this->sanitizeNullableString($pushProvider, 32),
                $this->sanitizeNullableString($pushProvider, 32),
                $preferences === null ? null : json_encode($preferences, JSON_UNESCAPED_SLASHES),
                $preferences === null ? null : json_encode($preferences, JSON_UNESCAPED_SLASHES),
                $this->sanitizeNullableString($deviceLocale, 32),
                $this->sanitizeNullableString($pushToken, 255),
                $this->sanitizeNullableString($pushToken, 255),
                $this->sanitizeNullableString($pushToken, 255),
                $this->sanitizeNullableString($pushToken, 255),
                $this->sanitizeNullableString($appVersion, 64),
                $tokenRowId,
            ]
        );
        error_log('Mobile push token updated for token_row_id=' . $tokenRowId . ' push=' . (!empty($pushToken) ? 'present' : 'empty'));
    }

    public function buildUserPayload(array $authRecord): array
    {
        $userId = (int) ($authRecord['user_id'] ?? $authRecord['id'] ?? 0);
        $user = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];
        $workspaceAccess = $this->resolveWorkspaceAccessForPayload($userId, (int) ($authRecord['workspace_id'] ?? 0));
        $memberships = (new WorkspaceMembershipService())->listForUser($userId);
        $saasBilling = [
            'subscription_status' => 'inactive',
            'token_balance' => 0,
            'available_tokens' => 0,
            'billing_blocked' => false,
            'ai_blocked_reason' => null,
        ];
        if (!empty($workspaceAccess['workspace_id'])) {
            try {
                $saasBilling = (new SaaSBillingService())->getWorkspaceSnapshot((int) $workspaceAccess['workspace_id'], $user);
            } catch (WorkspaceLaunchReadinessException $e) {
                $saasBilling['launch_readiness'] = $e->readiness();
            }
        }
        $workspaceBilling = $this->shouldExposeWorkspaceBillingCompatibility()
            ? $this->buildWorkspaceBillingCompatibility(
                $this->workspaceBillingService()->getBillingStateForUser($user),
                $saasBilling
            )
            : null;
        $workspaceRole = (string) ($workspaceAccess['role_slug'] ?? 'viewer');
        $canManageWorkspace = in_array($workspaceRole, ['owner', 'admin'], true);
        $canManageOwners = $workspaceRole === 'owner';
        $pendingInviteCount = 0;
        if ($canManageWorkspace && !empty($workspaceAccess['workspace_id'])) {
            $pendingInviteCount = (new WorkspaceGovernanceService())->countPendingInvites((int) $workspaceAccess['workspace_id']);
        }

        return [
            'id' => $userId,
            'uuid' => (string) ($authRecord['uuid'] ?? $user['uuid'] ?? ''),
            'email' => (string) ($authRecord['email'] ?? $user['email'] ?? ''),
            'first_name' => (string) ($authRecord['first_name'] ?? $user['first_name'] ?? ''),
            'last_name' => (string) ($authRecord['last_name'] ?? $user['last_name'] ?? ''),
            'role' => (string) ($authRecord['role'] ?? $user['role'] ?? ''),
            'permissions' => $this->getPermissionKeys($userId),
            'scopes' => [
                'can_view_all_contacts' => Authorization::can('contacts.view_all', $user),
                'can_view_all_tasks' => Authorization::can('tasks.view_all', $user),
                'can_view_all_notifications' => Authorization::can('notifications.view_all', $user),
                'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
                'can_view_all_analytics' => Authorization::can('analytics.view_all', $user),
            ],
            'workspace_billing' => $workspaceBilling ? [
                'status' => (string) ($workspaceBilling['status'] ?? 'current'),
                'amount_due' => (float) ($workspaceBilling['amount_due'] ?? 0),
                'currency' => (string) ($workspaceBilling['currency'] ?? 'KES'),
                'due_date' => (string) ($workspaceBilling['due_date'] ?? ''),
                'grace_expires_at' => (string) ($workspaceBilling['grace_expires_at'] ?? ''),
                'is_trial_active' => false,
                'trial_starts_at' => '',
                'trial_ends_at' => '',
                'restricted' => !empty($workspaceBilling['restricted']),
                'show_prompt' => !empty($workspaceBilling['show_prompt']),
                'payment_url' => (string) ($workspaceBilling['payment_url'] ?? ''),
            ] : null,
            'active_workspace' => $workspaceAccess ? [
                'id' => (int) ($workspaceAccess['workspace_id'] ?? 0),
                'uuid' => (string) ($workspaceAccess['workspace_uuid'] ?? ''),
                'slug' => (string) ($workspaceAccess['workspace_slug'] ?? ''),
                'name' => (string) ($workspaceAccess['workspace_name'] ?? ''),
                'role' => $workspaceRole,
                'is_owner' => !empty($workspaceAccess['is_owner']),
                'can_manage_members' => $canManageWorkspace,
                'can_manage_slug' => $canManageWorkspace,
                'can_manage_owners' => $canManageOwners,
                'can_transfer_ownership' => $canManageOwners,
                'membership_status' => (string) ($workspaceAccess['membership_status'] ?? 'active'),
                'pending_invite_count' => $pendingInviteCount,
                'status' => (string) ($workspaceAccess['workspace_status'] ?? 'active'),
                'plan_status' => (string) ($workspaceAccess['plan_status'] ?? 'inactive'),
            ] : null,
            'memberships' => array_map(static function (array $membership): array {
                return [
                    'workspace_id' => (int) ($membership['workspace_id'] ?? 0),
                    'workspace_uuid' => (string) ($membership['workspace_uuid'] ?? ''),
                    'workspace_slug' => (string) ($membership['workspace_slug'] ?? ''),
                    'workspace_name' => (string) ($membership['workspace_name'] ?? ''),
                    'role' => (string) ($membership['role_slug'] ?? 'viewer'),
                    'is_owner' => !empty($membership['is_owner']),
                    'status' => (string) ($membership['membership_status'] ?? 'active'),
                ];
            }, $memberships),
            'workspace_capabilities' => [
                'can_manage_workspace' => $canManageWorkspace,
                'can_manage_members' => $canManageWorkspace,
                'can_manage_slug' => $canManageWorkspace,
                'can_manage_owners' => $canManageOwners,
                'can_transfer_ownership' => $canManageOwners,
            ],
            'pending_invite_count' => $pendingInviteCount,
            'subscription_status' => (string) ($saasBilling['subscription_status'] ?? 'inactive'),
            'token_balance' => (int) ($saasBilling['token_balance'] ?? 0),
            'available_tokens' => (int) ($saasBilling['available_tokens'] ?? 0),
            'billing_blocked' => !empty($saasBilling['billing_blocked']),
            'ai_blocked_reason' => $saasBilling['ai_blocked_reason'] ?? null,
            'security' => $this->buildSecurityPayload($user, $workspaceAccess, 'authenticated'),
        ];
    }

    public function buildWorkspaceBillingCompatibility(array $legacyBilling, array $saasBilling): array
    {
        $status = (string) ($saasBilling['subscription_status'] ?? $legacyBilling['status'] ?? 'inactive');
        $billingBlocked = !empty($saasBilling['billing_blocked']);

        $legacyBilling['status'] = $status;
        $legacyBilling['restricted'] = $billingBlocked;
        $legacyBilling['show_prompt'] = $billingBlocked;
        $legacyBilling['is_trial_active'] = false;
        $legacyBilling['trial_starts_at'] = '';
        $legacyBilling['trial_ends_at'] = '';

        if (!isset($legacyBilling['payment_url']) || trim((string) $legacyBilling['payment_url']) === '') {
            $legacyBilling['payment_url'] = function_exists('publicUrl')
                ? publicUrl('billing_payment_required.php')
                : '/public/billing_payment_required.php';
        }

        return $legacyBilling;
    }

    public function shouldExposeWorkspaceBillingCompatibility(): bool
    {
        return $this->workspaceBillingService()->shouldExposeCompatibilityState();
    }

    public function issueTokenPair(array $user, array $deviceContext = [], ?array $workspaceAccess = null): array
    {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(48));
        $deviceId = $this->sanitizeNullableString($deviceContext['device_id'] ?? null) ?: bin2hex(random_bytes(16));
        $workspaceAccess = $workspaceAccess ?? $this->resolveWorkspaceAccessForUser(
            (int) ($user['id'] ?? 0),
            isset($deviceContext['workspace_slug']) ? (string) $deviceContext['workspace_slug'] : null,
            !empty($deviceContext['workspace_id']) ? (int) $deviceContext['workspace_id'] : null
        );

        Database::execute(
            "INSERT INTO mobile_auth_tokens
                (user_id, workspace_id, device_id, device_name, platform, access_token_hash, refresh_token_hash, access_expires_at, refresh_expires_at, push_token, push_provider, push_preferences_json, device_locale, push_token_updated_at, app_version, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? IS NULL OR ? = '' THEN NULL ELSE NOW() END, ?, NOW())",
            [
                (int) $user['id'],
                !empty($workspaceAccess['workspace_id']) ? (int) $workspaceAccess['workspace_id'] : null,
                $deviceId,
                $this->sanitizeNullableString($deviceContext['device_name'] ?? null),
                $this->sanitizeNullableString($deviceContext['platform'] ?? null),
                hash('sha256', $accessToken),
                hash('sha256', $refreshToken),
                $this->dateTimeFromNow(self::ACCESS_TTL_SECONDS),
                $this->dateTimeFromNow(self::REFRESH_TTL_SECONDS),
                $this->sanitizeNullableString($deviceContext['push_token'] ?? null, 255),
                $this->sanitizeNullableString($deviceContext['push_provider'] ?? null, 32),
                !empty($deviceContext['push_preferences_json'])
                    ? (is_string($deviceContext['push_preferences_json'])
                        ? $deviceContext['push_preferences_json']
                        : json_encode($deviceContext['push_preferences_json'], JSON_UNESCAPED_SLASHES))
                    : null,
                $this->sanitizeNullableString($deviceContext['device_locale'] ?? null, 32),
                $this->sanitizeNullableString($deviceContext['push_token'] ?? null, 255),
                $this->sanitizeNullableString($deviceContext['push_token'] ?? null, 255),
                $this->sanitizeNullableString($deviceContext['app_version'] ?? null, 64),
            ]
        );

        $tokenId = (int) Database::lastInsertId();
        $payload = $this->buildUserPayload(array_merge($user, [
            'user_id' => $user['id'],
            'workspace_id' => !empty($workspaceAccess['workspace_id']) ? (int) $workspaceAccess['workspace_id'] : null,
        ]));

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'auth_state' => 'authenticated',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'refresh_expires_in' => self::REFRESH_TTL_SECONDS,
            'access_expires_at' => gmdate(DATE_ATOM, time() + self::ACCESS_TTL_SECONDS),
            'refresh_expires_at' => gmdate(DATE_ATOM, time() + self::REFRESH_TTL_SECONDS),
            'device_id' => $deviceId,
            'session_id' => $tokenId,
            'security' => $payload['security'] ?? $this->buildSecurityPayload($user, $workspaceAccess, 'authenticated'),
            'user' => $payload,
        ];
    }

    private function resolveWorkspaceAccessForUser(
        int $userId,
        ?string $workspaceSlug = null,
        ?int $workspaceId = null,
        bool $enforceTwoFactor = true
    ): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $membershipService = new WorkspaceMembershipService();
        if ($workspaceId !== null && $workspaceId > 0) {
            $membership = $membershipService->getActiveMembership($workspaceId, $userId);
            if ($membership !== null) {
                if ($enforceTwoFactor) {
                    $this->assertWorkspaceTwoFactorSatisfied($membership, $userId);
                }
                return $membership;
            }
            throw new \RuntimeException('You do not have access to that workspace.');
        }

        if ($workspaceSlug !== null && trim($workspaceSlug) !== '') {
            $membership = $membershipService->getActiveMembershipBySlug(trim($workspaceSlug), $userId);
            if ($membership !== null) {
                if ($enforceTwoFactor) {
                    $this->assertWorkspaceTwoFactorSatisfied($membership, $userId);
                }
                return $membership;
            }
            throw new \RuntimeException('You do not have access to that workspace.');
        }

        $memberships = $membershipService->listForUser($userId);
        $membership = $memberships[0] ?? null;
        if ($membership !== null && $enforceTwoFactor) {
            $this->assertWorkspaceTwoFactorSatisfied($membership, $userId);
        }

        return $membership;
    }

    private function assertWorkspaceTwoFactorSatisfied(array $membership, int $userId): void
    {
        $workspaceId = (int) ($membership['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        $security = new WorkspaceSecuritySettingsService();
        if ($security->requiresMember2FA($workspaceId) && !WorkspaceSecuritySettingsService::userHasTwoFactor($userId)) {
            throw new \RuntimeException('This workspace requires two-factor authentication. Enable 2FA before accessing it.');
        }
    }

    private function workspaceRequiresTwoFactor(?array $workspaceAccess): bool
    {
        $workspaceId = (int) ($workspaceAccess['workspace_id'] ?? 0);
        return $workspaceId > 0 && (new WorkspaceSecuritySettingsService())->requiresMember2FA($workspaceId);
    }

    private function createChallengeResponse(
        array $user,
        ?array $workspaceAccess,
        array $deviceContext,
        string $purpose,
        string $authState
    ): array {
        $challengeToken = bin2hex(random_bytes(32));
        Database::execute(
            "INSERT INTO mobile_auth_challenges
                (user_id, workspace_id, challenge_hash, purpose, device_id, device_name, platform, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $user['id'],
                !empty($workspaceAccess['workspace_id']) ? (int) $workspaceAccess['workspace_id'] : null,
                hash('sha256', $challengeToken),
                $purpose,
                $this->sanitizeNullableString($deviceContext['device_id'] ?? null),
                $this->sanitizeNullableString($deviceContext['device_name'] ?? null),
                $this->sanitizeNullableString($deviceContext['platform'] ?? null),
                $this->currentIpAddress(),
                $this->sanitizeNullableString($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
                $this->dateTimeFromNow(self::CHALLENGE_TTL_SECONDS),
            ]
        );

        return [
            'requires_2fa' => true,
            'requires_2fa_setup' => $purpose === self::CHALLENGE_PURPOSE_SETUP_2FA,
            'auth_state' => $authState,
            'challenge_token' => $challengeToken,
            'challenge_purpose' => $purpose,
            'expires_in' => self::CHALLENGE_TTL_SECONDS,
            'workspace' => $workspaceAccess ? [
                'id' => (int) ($workspaceAccess['workspace_id'] ?? 0),
                'slug' => (string) ($workspaceAccess['workspace_slug'] ?? ''),
                'name' => (string) ($workspaceAccess['workspace_name'] ?? ''),
            ] : null,
            'security' => $this->buildSecurityPayload($user, $workspaceAccess, $authState),
        ];
    }

    private function validChallenge(string $challengeToken, string $purpose): array
    {
        $challenge = Database::queryOne(
            "SELECT * FROM mobile_auth_challenges
             WHERE challenge_hash = ?
               AND purpose = ?
               AND consumed_at IS NULL
               AND expires_at > NOW()
             LIMIT 1",
            [hash('sha256', $challengeToken), $purpose]
        );

        if (!$challenge) {
            error_log('Mobile 2FA challenge invalid, expired, consumed, or wrong purpose.');
            throw new \RuntimeException('The 2FA challenge is invalid or has expired.');
        }
        if ((int) ($challenge['failed_attempts'] ?? 0) >= self::MAX_CHALLENGE_FAILED_ATTEMPTS) {
            throw new \RuntimeException('Too many invalid verification attempts. Start again.');
        }

        return $challenge;
    }

    private function registerChallengeFailure(array $challenge, string $message = 'Invalid verification code.'): void
    {
        $nextAttempts = (int) ($challenge['failed_attempts'] ?? 0) + 1;
        Database::execute(
            "UPDATE mobile_auth_challenges
             SET failed_attempts = ?,
                 consumed_at = CASE WHEN ? >= ? THEN NOW() ELSE consumed_at END
             WHERE id = ?
               AND consumed_at IS NULL",
            [$nextAttempts, $nextAttempts, self::MAX_CHALLENGE_FAILED_ATTEMPTS, (int) $challenge['id']]
        );

        if ($nextAttempts >= self::MAX_CHALLENGE_FAILED_ATTEMPTS) {
            throw new \RuntimeException('Too many invalid verification attempts. Start again.');
        }

        throw new \RuntimeException($message);
    }

    private function consumeChallenge(array $challenge): void
    {
        Database::execute(
            "UPDATE mobile_auth_challenges SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL",
            [(int) $challenge['id']]
        );
    }

    private function deviceContextFromChallenge(array $challenge, array $deviceContext): array
    {
        foreach (['device_id', 'device_name', 'platform'] as $key) {
            if (empty($deviceContext[$key]) && !empty($challenge[$key])) {
                $deviceContext[$key] = $challenge[$key];
            }
        }
        if (!empty($challenge['workspace_id'])) {
            $deviceContext['workspace_id'] = (int) $challenge['workspace_id'];
        }

        return $deviceContext;
    }

    private function workspaceAccessFromChallenge(array $challenge): ?array
    {
        if (empty($challenge['workspace_id'])) {
            return null;
        }

        try {
            return $this->resolveWorkspaceAccessForUser((int) $challenge['user_id'], null, (int) $challenge['workspace_id'], false);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    private function workspaceChallengePayload(array $challenge): ?array
    {
        $workspaceAccess = $this->workspaceAccessFromChallenge($challenge);
        if (!$workspaceAccess) {
            return null;
        }

        return [
            'id' => (int) ($workspaceAccess['workspace_id'] ?? 0),
            'slug' => (string) ($workspaceAccess['workspace_slug'] ?? ''),
            'name' => (string) ($workspaceAccess['workspace_name'] ?? ''),
        ];
    }

    private function buildSecurityPayload(array $user, ?array $workspaceAccess, string $authState): array
    {
        return [
            'two_factor_enabled' => !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']),
            'workspace_requires_2fa' => $this->workspaceRequiresTwoFactor($workspaceAccess),
            'auth_state' => $authState,
        ];
    }

    private function temporaryUserIsActive(array $user): bool
    {
        if (empty($user['is_demo_guest'])) {
            return true;
        }

        $expiresAt = trim((string) ($user['demo_expires_at'] ?? ''));
        if ($expiresAt === '') {
            return true;
        }

        $timestamp = strtotime($expiresAt);
        return $timestamp === false || $timestamp > time();
    }

    private function resolveWorkspaceAccessForPayload(int $userId, int $workspaceId): ?array
    {
        try {
            return $this->resolveWorkspaceAccessForUser($userId, null, $workspaceId > 0 ? $workspaceId : null);
        } catch (\RuntimeException $e) {
            if ($workspaceId <= 0) {
                throw $e;
            }

            return $this->resolveWorkspaceAccessForUser($userId);
        }
    }

    private function workspaceBillingService(): WorkspaceBillingService
    {
        if ($this->workspaceBillingService === null) {
            $this->workspaceBillingService = new WorkspaceBillingService();
        }

        return $this->workspaceBillingService;
    }

    private function findUserByEmail(string $email): ?array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1",
            [$normalized]
        );
    }

    private function findTokenRecordByRefresh(string $refreshToken): ?array
    {
        return Database::queryOne(
            "SELECT * FROM mobile_auth_tokens
             WHERE refresh_token_hash = ?
               AND revoked_at IS NULL
               AND refresh_expires_at > NOW()
             LIMIT 1",
            [hash('sha256', $refreshToken)]
        );
    }

    private function verifyRecoveryCode(int $userId, string $code): bool
    {
        if ($userId <= 0 || trim($code) === '') {
            return false;
        }

        $codeHash = hash('sha256', strtoupper($code));
        $row = Database::queryOne(
            "SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1",
            [$userId, $codeHash]
        );

        if (!$row) {
            return false;
        }

        Database::execute(
            "UPDATE user_recovery_codes SET used_at = NOW() WHERE id = ?",
            [(int) $row['id']]
        );

        return true;
    }

    private function getPermissionKeys(int $userId): array
    {
        if ($userId <= 0 || !Authorization::isRbacAvailable()) {
            return [];
        }

        $rows = Database::query(
            "SELECT p.permission_key
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
             JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?",
            [$userId]
        );

        $keys = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['permission_key'] ?? ''));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    private function absolutePublicUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $publicPath = function_exists('publicUrl') ? publicUrl($path) : $path;
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#^(.*?)/api/mobile(?:/|$)#', $scriptName, $matches)) {
            $installPrefix = rtrim((string) ($matches[1] ?? ''), '/');
            if ($installPrefix !== '' && !str_starts_with($publicPath, $installPrefix . '/')) {
                $publicPath = $installPrefix . '/public/' . ltrim($path, '/');
            }
        }
        return $scheme . '://' . $host . $publicPath;
    }

    private function currentIpAddress(): ?string
    {
        return $this->sanitizeNullableString($_SERVER['REMOTE_ADDR'] ?? null, 64);
    }

    private function sanitizeNullableString(?string $value, int $maxLength = 191): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    private function dateTimeFromNow(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }
}
