<?php
/**
 * Authentication System
 * 
 * Handles user authentication, authorization, and 2FA
 */

namespace CRM;

use CRM\Database;
use CRM\Session;
use CRM\Modules\AuditLog;
use CRM\Services\UserSystemTimeService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSecuritySettingsService;
use PragmaRX\Google2FA\Google2FA;

class Auth
{
    private const REMEMBER_COOKIE_NAME = 'crm_remember';
    private const REMEMBER_TTL_SECONDS = 2592000;
    private const REMEMBER_SELECTOR_BYTES = 12;
    private const REMEMBER_TOKEN_BYTES = 32;
    private static ?array $currentUserCache = null;
    private static ?int $currentUserCacheId = null;
    private static ?int $currentUserWorkspaceCacheId = null;
    private static ?string $currentUserRoleCache = null;

    /**
     * Login user with email and password
     * Returns: true = full login or pending 2FA, false = invalid credentials
     * When 2FA is enabled, sets pending_2fa in session and returns true
     */
    public static function login(string $email, string $password, bool $rememberMe = false): bool
    {
        try {
            $email = strtolower(trim($email));
            if ($email === '') {
                return false;
            }

            $user = Database::queryOne(
                "SELECT * FROM users WHERE LOWER(TRIM(email)) = ?",
                [$email]
            );
            
            if (!$user) {
                return false;
            }
            
            if (!password_verify($password, $user['password_hash'])) {
                return false;
            }

            if (!self::temporaryUserIsActive($user)) {
                return false;
            }
            
            // Ensure session is started (don't restart if already started)
            if (session_status() === PHP_SESSION_NONE) {
                Session::start();
            }
            session_regenerate_id(true);
            
            // Never treat user id 0 as valid
            if (empty($user['id']) || (int) $user['id'] <= 0) {
                return false;
            }
            
            // Check if 2FA is enabled - require second factor before completing login
            $twoFactorEnabled = !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']);
            if ($twoFactorEnabled) {
                // Password verification succeeded. Suspend any existing authenticated
                // browser state before requiring the second factor so re-authentication
                // cannot bypass 2FA via the old session.
                self::clearWebLoginSession();
                Session::set('pending_2fa_user_id', $user['id']);
                Session::set('pending_2fa', true);
                Session::set('pending_2fa_remember', $rememberMe ? 1 : 0);
                return true;
            }
            
            self::completeWebLogin($user, $rememberMe, ['email' => $email, 'role' => $user['role']]);
            return true;
        } catch (\PDOException $e) {
            // Re-throw database errors so they can be caught and handled by the caller
            error_log('Auth::login database error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            // Re-throw other errors
            error_log('Auth::login error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if login is pending 2FA verification
     */
    public static function isPending2FA(): bool
    {
        return Session::has('pending_2fa') && Session::has('pending_2fa_user_id');
    }

    /**
     * @param array<string,mixed> $user
     */
    private static function temporaryUserIsActive(array $user): bool
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
    
    /**
     * Verify 2FA TOTP code and complete login
     */
    public static function verify2FA(string $code): bool
    {
        if (!self::isPending2FA()) {
            return false;
        }
        
        $userId = (int) Session::get('pending_2fa_user_id');
        $user = Database::queryOne("SELECT two_factor_secret FROM users WHERE id = ?", [$userId]);
        
        if (!$user || empty($user['two_factor_secret'])) {
            self::clearPending2FA();
            return false;
        }
        
        $code = preg_replace('/\s+/', '', $code);
        
        // Try TOTP verification first
        try {
            $google2fa = new Google2FA();
            if ($google2fa->verifyKey($user['two_factor_secret'], $code)) {
                return self::complete2FALogin($userId);
            }
        } catch (\Exception $e) {
            // Fall through to recovery code check
        }
        
        // Try recovery code
        if (self::verifyRecoveryCode($userId, $code)) {
            return self::complete2FALogin($userId);
        }
        
        return false;
    }
    
    /**
     * Complete login after 2FA verification
     */
    private static function complete2FALogin(int $userId): bool
    {
        $user = Database::queryOne(
            "SELECT id, uuid, email, role FROM users WHERE id = ?",
            [$userId]
        );
        
        if (!$user) {
            self::clearPending2FA();
            return false;
        }
        
        $rememberMe = Session::get('pending_2fa_remember') == 1;
        self::clearPending2FA();

        if ($userId <= 0) {
            return false;
        }

        self::completeWebLogin($user, $rememberMe, ['2fa_verified' => true]);
        return true;
    }
    
    /**
     * Clear pending 2FA state from session
     */
    public static function clearPending2FA(): void
    {
        Session::remove('pending_2fa');
        Session::remove('pending_2fa_user_id');
        Session::remove('pending_2fa_remember');
    }

    public static function isPendingWorkspace2FASetup(): bool
    {
        $userId = (int) (Session::get('user_id') ?? 0);
        return $userId > 0 && WorkspaceSecuritySettingsService::hasPendingSetupForUser($userId);
    }

    public static function pendingWorkspace2FASetup(): ?array
    {
        return WorkspaceSecuritySettingsService::pendingSetup();
    }

    public static function clearPendingWorkspace2FASetup(): void
    {
        WorkspaceSecuritySettingsService::clearPendingSetup();
    }
    
    /**
     * Verify and consume a recovery code
     */
    private static function verifyRecoveryCode(int $userId, string $code): bool
    {
        $codeHash = hash('sha256', strtoupper($code));
        
        $row = Database::queryOne(
            "SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL",
            [$userId, $codeHash]
        );
        
        if (!$row) {
            return false;
        }
        
        Database::execute(
            "UPDATE user_recovery_codes SET used_at = NOW() WHERE id = ?",
            [$row['id']]
        );
        
        return true;
    }
    
    /**
     * Generate new 2FA secret for setup
     */
    public static function generate2FASecret(string $email): array
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey(32);
        $companyName = $_ENV['COMPANY_NAME'] ?? 'CRM';
        $qrCodeUrl = $google2fa->getQRCodeUrl($companyName, $email, $secret);
        
        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl
        ];
    }
    
    /**
     * Enable 2FA for user (store secret, generate recovery codes)
     */
    public static function enable2FA(int $userId, string $secret, string $verificationCode): array
    {
        $user = Database::queryOne("SELECT email, two_factor_secret FROM users WHERE id = ?", [$userId]);
        if (!$user || !empty($user['two_factor_secret'])) {
            return ['success' => false, 'error' => '2FA already enabled or user not found'];
        }
        
        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($secret, $verificationCode)) {
            return ['success' => false, 'error' => 'Invalid verification code'];
        }
        
        Database::execute(
            "UPDATE users SET two_factor_secret = ?, two_factor_enabled = TRUE WHERE id = ?",
            [$secret, $userId]
        );
        
        $recoveryCodes = self::generateRecoveryCodes($userId);
        
        return ['success' => true, 'recovery_codes' => $recoveryCodes];
    }
    
    /**
     * Generate recovery codes for user
     */
    private static function generateRecoveryCodes(int $userId): array
    {
        $codes = [];
        $hashes = [];
        
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = $code;
            $hashes[] = [$userId, hash('sha256', $code)];
        }
        
        $stmt = Database::getInstance()->prepare(
            "INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)"
        );
        foreach ($hashes as $h) {
            $stmt->execute($h);
        }
        
        return $codes;
    }
    
    /**
     * Disable 2FA for user
     */
    public static function disable2FA(int $userId, string $password): bool
    {
        $user = Database::queryOne("SELECT password_hash FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        
        Database::execute(
            "UPDATE users SET two_factor_secret = NULL, two_factor_enabled = FALSE WHERE id = ?",
            [$userId]
        );
        
        Database::execute("DELETE FROM user_recovery_codes WHERE user_id = ?", [$userId]);
        
        return true;
    }
    
    /**
     * Logout current user
     */
    public static function logout(): void
    {
        // Log audit trail before destroying session
        if (self::check()) {
            try {
                $userId = self::userId();
                (new UserSystemTimeService())->endSession($userId, 'logout');
                $auditLog = new AuditLog();
                $auditLog->log('logout', 'user', $userId, null, null);
            } catch (\Exception $e) {
                // Don't fail logout if audit logging fails
            }
        }

        self::revokeRememberedSession();
        Session::destroy();
    }

    public static function discardRememberedBrowserSession(): void
    {
        self::revokeRememberedSession();
        self::clearWebLoginSession();
    }

    /**
     * Build a consistent auth payload for JSON endpoints and frontend recovery.
     *
     * @return array<string,mixed>
     */
    public static function authPayload(string $state = '', ?string $redirectTo = null, bool $touchActivity = true): array
    {
        $state = trim($state) !== '' ? trim($state) : self::currentAuthState();
        return [
            'state' => $state,
            'authenticated' => self::check($touchActivity),
            'login_url' => self::loginUrl($redirectTo, $state === 'expired'),
            'reauth_url' => self::loginUrl($redirectTo, false, true),
            'seconds_remaining' => Session::secondsUntilIdleExpiry(),
            'lifetime_seconds' => Session::lifetimeSeconds(),
        ];
    }

    public static function jsonAuthError(
        string $state = '',
        int $status = 401,
        string $message = '',
        bool $touchActivity = true
    ): void
    {
        $state = trim($state) !== '' ? trim($state) : self::currentAuthState();
        if ($message === '') {
            $message = $state === 'csrf_invalid' ? 'Invalid security token' : 'Unauthorized';
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'auth' => self::authPayload($state, null, $touchActivity),
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function currentAuthState(): string
    {
        return Session::isIdleExpired() ? 'expired' : 'unauthorized';
    }
    
    /**
     * Check if user is logged in (valid non-zero user_id in session)
     */
    public static function check(bool $touchActivity = true): bool
    {
        return self::userId($touchActivity) !== null;
    }
    
    /**
     * Get current user ID (null if not logged in or session has invalid id e.g. 0)
     */
    public static function userId(bool $touchActivity = true): ?int
    {
        if (Session::isIdleExpired()) {
            $expiredAt = Session::idleExpiredAt();
            self::clearWebLoginSession();
            Session::remove('__remember_restore_attempted');
            self::restoreRememberedSession();

            if (!Session::has('user_id')) {
                Session::markIdleExpired($expiredAt);
                Session::set('__remember_restore_attempted', true);
                return null;
            }
        }

        if (!Session::has('__remember_restore_attempted')) {
            Session::set('__remember_restore_attempted', true);
            if (!Session::has('user_id')) {
                self::restoreRememberedSession();
            }
        }

        $id = Session::get('user_id');
        if ($id === null || $id === '') {
            return null;
        }
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        if ($touchActivity && !Session::isPassiveRequest()) {
            Session::markAuthenticatedActivity();
            (new UserSystemTimeService())->heartbeat($id, WorkspaceContext::currentWorkspaceId());
        }
        return $id;
    }
    
    /**
     * Get current user data
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $userId = self::userId();
        $workspaceId = WorkspaceContext::currentWorkspaceId();
        $workspaceRole = WorkspaceContext::currentRoleSlug();
        if (
            self::$currentUserCache !== null
            && self::$currentUserCacheId === $userId
            && self::$currentUserWorkspaceCacheId === $workspaceId
            && self::$currentUserRoleCache === $workspaceRole
        ) {
            return self::$currentUserCache;
        }

        $user = Database::queryOne(
            "SELECT id, uuid, first_name, last_name, email, role, last_login, email_verified_at FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user) {
            return null;
        }

        $workspace = WorkspaceContext::currentWorkspace();
        if ($workspace !== null) {
            $user['active_workspace_id'] = (int) ($workspace['id'] ?? 0);
            $user['active_workspace_uuid'] = (string) ($workspace['uuid'] ?? '');
            $user['active_workspace_slug'] = (string) ($workspace['slug'] ?? '');
            $user['active_workspace_name'] = (string) ($workspace['name'] ?? '');
            $user['workspace_role'] = $workspaceRole;
        }

        self::$currentUserCache = $user;
        self::$currentUserCacheId = $userId;
        self::$currentUserWorkspaceCacheId = $workspaceId;
        self::$currentUserRoleCache = $workspaceRole;

        return $user;
    }

    /**
     * Check if current user's email is verified
     */
    public static function isEmailVerified(): bool
    {
        $user = self::user();
        return $user && !empty($user['email_verified_at']);
    }

    /**
     * Generate and store email verification token for user
     */
    public static function generateEmailVerificationToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Database::execute(
            "UPDATE users SET email_verification_token = ? WHERE id = ?",
            [$token, $userId]
        );
        return $token;
    }

    /**
     * Validate email verification token and mark user verified
     */
    public static function verifyEmailToken(string $token): ?int
    {
        $row = Database::queryOne(
            "SELECT id FROM users WHERE email_verification_token = ?",
            [$token]
        );
        if (!$row) {
            return null;
        }
        $userId = (int) $row['id'];
        Database::execute(
            "UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?",
            [$userId]
        );
        return $userId;
    }
    
    /**
     * Get current user role
     */
    public static function role(): ?string
    {
        return Session::get('user_role');
    }
    
    /**
     * Check if user has specific role
     */
    public static function hasRole(string $role): bool
    {
        $userRole = self::role();
        return $userRole === $role || $userRole === 'admin';
    }
    
    /**
     * Require user to have specific role (redirect if not)
     */
    public static function requireRole(string $role): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
        
        if (!self::hasRole($role)) {
            http_response_code(403);
            die('Access denied: Insufficient permissions');
        }
    }
    
    /**
     * Require user to be logged in
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function requireLogin(): void
    {
        self::requireAuth();
    }

    public static function loginUrl(?string $redirectTo = null, bool $expired = false, bool $reauth = false): string
    {
        $basePath = function_exists('getBasePath') ? rtrim((string) getBasePath(), '/') : '';
        $loginPath = function_exists('publicUrl')
            ? (string) publicUrl('login.php')
            : (($basePath !== '' ? $basePath : '') . '/login.php');
        $redirect = self::safeRedirectPath($redirectTo ?? self::currentReturnPath(), $basePath);
        $query = [];
        if ($expired) {
            $query['expired'] = '1';
        }
        if ($reauth) {
            $query['reauth'] = '1';
        }
        if ($redirect !== '') {
            $query['redirect_to'] = $redirect;
        }

        return $query === [] ? $loginPath : $loginPath . '?' . http_build_query($query);
    }
    
    /**
     * Create new user
     */
    public static function createUser(
        string $email,
        string $password,
        string $role = 'viewer',
        ?string $firstName = null,
        ?string $lastName = null
    ): ?int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $uuid = self::generateUuid();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $firstName = Security::sanitizeInput($firstName, 'string');
        $lastName = Security::sanitizeInput($lastName, 'string');
        $roleSlug = trim($role);
        $legacyRole = $roleSlug === 'superadmin' ? 'admin' : $roleSlug;
        
        try {
            Database::execute(
                "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)",
                [$uuid, $firstName, $lastName, $email, $passwordHash, $legacyRole]
            );

            $userId = (int) Database::lastInsertId();
            if ($userId > 0 && Authorization::isRbacAvailable()) {
                Authorization::assignUserRoleBySlug($userId, $roleSlug !== '' ? $roleSlug : $legacyRole);
            }

            return $userId;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Generate UUID v4
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Generate password reset token
     */
    public static function generatePasswordResetToken(string $email): ?string
    {
        $user = Database::queryOne(
            "SELECT id FROM users WHERE email = ?",
            [$email]
        );
        
        if (!$user) {
            // Don't reveal if user exists
            return null;
        }
        
        // Invalidate any existing tokens for this user
        Database::execute(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL",
            [$user['id']]
        );
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
        
        Database::execute(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            [$user['id'], $token, $expiresAt]
        );
        
        return $token;
    }
    
    /**
     * Validate password reset token
     */
    public static function validatePasswordResetToken(string $token): ?int
    {
        $result = Database::queryOne(
            "SELECT user_id FROM password_reset_tokens 
             WHERE token = ? AND expires_at > NOW() AND used_at IS NULL",
            [$token]
        );
        
        return $result ? (int) $result['user_id'] : null;
    }
    
    /**
     * Mark password reset token as used
     */
    public static function markPasswordResetTokenUsed(string $token): void
    {
        Database::execute(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?",
            [$token]
        );
    }
    
    /**
     * Reset password using token
     */
    public static function resetPassword(string $token, string $newPassword): bool
    {
        $userId = self::validatePasswordResetToken($token);
        
        if (!$userId) {
            return false;
        }
        
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        Database::execute(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$passwordHash, $userId]
        );
        
        // Mark token as used
        self::markPasswordResetTokenUsed($token);
        
        // Invalidate all other tokens for this user
        Database::execute(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );
        
        return true;
    }
    
    /**
     * Update user password (for logged-in users)
     */
    public static function updatePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = Database::queryOne(
            "SELECT password_hash FROM users WHERE id = ?",
            [$userId]
        );
        
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }
        
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        Database::execute(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$passwordHash, $userId]
        );
        
        return true;
    }

    public static function completeProvisionedLogin(int $userId, bool $rememberMe = false): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = Database::queryOne(
            "SELECT id, uuid, email, role FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            Session::start();
        }
        session_regenerate_id(true);

        self::completeWebLogin($user, $rememberMe, ['provisioned_login' => true]);
        return true;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $auditMetadata
     */
    private static function completeWebLogin(array $user, bool $rememberMe, array $auditMetadata = []): void
    {
        self::clearCurrentUserCache();
        Session::set('user_id', $user['id']);
        Session::set('user_uuid', $user['uuid']);
        Session::set('user_email', $user['email']);
        Session::set('user_role', $user['role']);
        Session::set('open_della_on_login', true);
        Session::markAuthenticatedActivity();
        self::restoreActiveDemoGuestSession((int) $user['id']);
        $workspace = WorkspaceContext::activateForUser((int) $user['id']);
        $workspaceId = $workspace !== null ? (int) ($workspace['workspace_id'] ?? $workspace['id'] ?? 0) : 0;
        if ($workspaceId > 0) {
            $workspaceSecurity = new WorkspaceSecuritySettingsService();
            if ($workspaceSecurity->requiresMember2FA($workspaceId) && !WorkspaceSecuritySettingsService::userHasTwoFactor((int) $user['id'])) {
                WorkspaceSecuritySettingsService::setPendingSetup((int) $user['id'], $workspaceId, $workspace);
                WorkspaceContext::clear();
                $workspace = null;
            } else {
                WorkspaceSecuritySettingsService::clearPendingSetup();
            }
        }

        (new UserSystemTimeService())->startSession(
            (int) $user['id'],
            $workspace !== null ? (int) ($workspace['workspace_id'] ?? $workspace['id'] ?? 0) : WorkspaceContext::currentWorkspaceId(),
            array_merge($auditMetadata, ['source' => 'web_login'])
        );

        Database::execute(
            "UPDATE users SET last_login = NOW() WHERE id = ?",
            [(int) $user['id']]
        );

        if ($rememberMe) {
            self::issueRememberedSession((int) $user['id']);
        } else {
            self::revokeRememberedSession();
        }

        try {
            $auditLog = new AuditLog();
            $auditLog->log('login', 'user', (int) $user['id'], null, $auditMetadata);
        } catch (\Exception $e) {
            error_log('Audit log error during login: ' . $e->getMessage());
        }

        if (!empty($auditMetadata['remember_login']) && $workspace === null) {
            error_log('Remembered login discarded because no active workspace could be restored for user ' . (int) $user['id']);
            self::revokeRememberedSession();
            self::clearWebLoginSession();
        }
    }

    private static function restoreActiveDemoGuestSession(int $userId): void
    {
        if ($userId <= 0 || !Database::tableExists('demo_visitor_sessions')) {
            return;
        }

        try {
            $user = Database::queryOne(
                "SELECT is_demo_guest, demo_expires_at, demo_session_uuid
                 FROM users
                 WHERE id = ?
                 LIMIT 1",
                [$userId]
            );

            if (!$user || (int) ($user['is_demo_guest'] ?? 0) !== 1) {
                return;
            }

            $expiresAt = strtotime((string) ($user['demo_expires_at'] ?? ''));
            if ($expiresAt !== false && $expiresAt <= time()) {
                return;
            }

            $session = Database::queryOne(
                "SELECT id, session_uuid, workspace_id
                 FROM demo_visitor_sessions
                 WHERE (user_id = ? OR guest_user_id = ?)
                   AND status = 'active'
                   AND expires_at > NOW()
                 ORDER BY CASE WHEN session_uuid = ? THEN 0 ELSE 1 END, created_at DESC, id DESC
                 LIMIT 1",
                [$userId, $userId, (string) ($user['demo_session_uuid'] ?? '')]
            );

            if (!$session) {
                return;
            }

            Session::set('demo_visitor_session_id', (int) $session['id']);
            Session::set('demo_visitor_session_uuid', (string) $session['session_uuid']);
            Session::set('demo_workspace_id', (int) $session['workspace_id']);
        } catch (\Throwable $e) {
            error_log('Auth demo session restore failed: ' . $e->getMessage());
        }
    }

    private static function restoreRememberedSession(): void
    {
        $cookieValue = trim((string) ($_COOKIE[self::REMEMBER_COOKIE_NAME] ?? ''));
        if ($cookieValue === '' || strpos($cookieValue, ':') === false) {
            if ($cookieValue !== '') {
                self::clearRememberCookie();
            }
            return;
        }

        [$selector, $token] = explode(':', $cookieValue, 2);
        $selector = trim($selector);
        $token = trim($token);
        if ($selector === '' || $token === '') {
            self::clearRememberCookie();
            return;
        }

        $record = Database::queryOne(
            "SELECT t.*, u.id AS auth_user_id, u.uuid, u.email, u.role
             FROM web_auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.selector = ?
             LIMIT 1",
            [$selector]
        );
        if (!$record) {
            self::clearRememberCookie();
            return;
        }

        $expiresAt = strtotime((string) ($record['expires_at'] ?? ''));
        $isExpired = $expiresAt !== false && $expiresAt <= time();
        $isRevoked = !empty($record['revoked_at']);
        $isTokenValid = hash_equals((string) ($record['token_hash'] ?? ''), hash('sha256', $token));

        if ($isExpired || $isRevoked || !$isTokenValid) {
            Database::execute(
                "UPDATE web_auth_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?",
                [(int) $record['id']]
            );
            self::clearRememberCookie();
            return;
        }

        session_regenerate_id(true);
        Database::execute(
            "UPDATE web_auth_tokens SET last_used_at = NOW() WHERE id = ?",
            [(int) $record['id']]
        );
        self::completeWebLogin([
            'id' => (int) ($record['auth_user_id'] ?? 0),
            'uuid' => (string) ($record['uuid'] ?? ''),
            'email' => (string) ($record['email'] ?? ''),
            'role' => (string) ($record['role'] ?? ''),
        ], true, ['remember_login' => true]);
    }

    private static function issueRememberedSession(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        self::revokeRememberedSession(clearCookie: false);

        $selector = bin2hex(random_bytes(self::REMEMBER_SELECTOR_BYTES));
        $token = bin2hex(random_bytes(self::REMEMBER_TOKEN_BYTES));
        Database::execute(
            "INSERT INTO web_auth_tokens (user_id, selector, token_hash, user_agent, ip_address, expires_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $selector,
                hash('sha256', $token),
                self::sanitizeNullableString($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
                self::sanitizeNullableString($_SERVER['REMOTE_ADDR'] ?? null, 64),
                date('Y-m-d H:i:s', time() + self::REMEMBER_TTL_SECONDS),
            ]
        );

        $_COOKIE[self::REMEMBER_COOKIE_NAME] = $selector . ':' . $token;
        setcookie(
            self::REMEMBER_COOKIE_NAME,
            $selector . ':' . $token,
            Session::cookieOptions(time() + self::REMEMBER_TTL_SECONDS)
        );
    }

    private static function revokeRememberedSession(bool $clearCookie = true): void
    {
        $cookieValue = trim((string) ($_COOKIE[self::REMEMBER_COOKIE_NAME] ?? ''));
        if ($cookieValue !== '' && strpos($cookieValue, ':') !== false) {
            [$selector] = explode(':', $cookieValue, 2);
            if (trim($selector) !== '') {
                Database::execute(
                    "UPDATE web_auth_tokens
                     SET revoked_at = COALESCE(revoked_at, NOW())
                     WHERE selector = ?",
                    [trim($selector)]
                );
            }
        }

        if ($clearCookie) {
            self::clearRememberCookie();
        }
    }

    private static function clearRememberCookie(): void
    {
        unset($_COOKIE[self::REMEMBER_COOKIE_NAME]);
        setcookie(
            self::REMEMBER_COOKIE_NAME,
            '',
            Session::cookieOptions(time() - 42000)
        );
    }

    private static function clearWebLoginSession(): void
    {
        self::clearCurrentUserCache();
        Session::remove('user_id');
        Session::remove('user_uuid');
        Session::remove('user_email');
        Session::remove('user_role');
        Session::remove('open_della_on_login');
        Session::remove('demo_visitor_session_id');
        Session::remove('demo_visitor_session_uuid');
        Session::remove('demo_workspace_id');
        self::clearPending2FA();
        self::clearPendingWorkspace2FASetup();
        WorkspaceContext::clear();
    }

    private static function clearCurrentUserCache(): void
    {
        self::$currentUserCache = null;
        self::$currentUserCacheId = null;
        self::$currentUserWorkspaceCacheId = null;
        self::$currentUserRoleCache = null;
    }

    private static function currentReturnPath(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $candidates = [
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parts = parse_url($candidate);
            if (is_array($parts) && isset($parts['host']) && $host !== '' && strtolower((string) $parts['host']) !== $host) {
                continue;
            }

            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : $candidate;
            if ($path === '' || str_starts_with($path, '/api/') || str_contains($path, '/api/')) {
                continue;
            }
            $query = is_array($parts) && isset($parts['query']) ? '?' . (string) $parts['query'] : '';
            return $path . $query;
        }

        return function_exists('publicUrl') ? (string) publicUrl('dashboard.php') : '/dashboard.php';
    }

    private static function safeRedirectPath(string $path, string $basePath): string
    {
        $path = trim(str_replace(["\r", "\n"], '', $path));
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return '';
        }

        if ($path[0] !== '/') {
            $path = ($basePath !== '' ? $basePath . '/' : '/') . ltrim($path, '/');
        }

        $basePrefix = rtrim($basePath, '/');
        if ($basePrefix !== '' && $path !== $basePrefix && strpos($path, $basePrefix . '/') !== 0) {
            return '';
        }

        return $path;
    }

    private static function sanitizeNullableString(?string $value, int $maxLength = 191): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }
}
