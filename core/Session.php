<?php
/**
 * Session Management
 * 
 * Secure session handling with CSRF protection
 */

namespace CRM;

class Session
{
    private static bool $started = false;
    private static bool $starting = false;
    private const DEFAULT_LIFETIME_SECONDS = 7200;
    
    /**
     * Start session with secure settings
     */
    public static function start(): void
    {
        if (self::$started || self::$starting) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        self::$starting = true;
        try {
        $config = require __DIR__ . '/../config/security.php';
        $sessionConfig = $config['session_security'];
        $cookieSecure = self::isSecureCookieRequest();
        $lifetimeSeconds = self::lifetimeSeconds($sessionConfig);
        
        // Set secure session parameters
        ini_set('session.cookie_httponly', $sessionConfig['cookie_httponly'] ? '1' : '0');
        ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
        ini_set('session.cookie_samesite', $sessionConfig['cookie_samesite']);
        ini_set('session.cookie_path', self::cookiePath());
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);
        ini_set('session.use_strict_mode', '1');
        self::configureSaveHandler();
        self::configureWritableSavePath();
        
        session_start();
        self::$started = true;
        self::evaluateIdleTimeout();
        
        // Regenerate periodically without deleting the old id, so in-flight AJAX
        // requests do not lose the session. Login/restore still regenerate strictly.
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > $sessionConfig['session_regenerate']) {
            session_regenerate_id(false);
            $_SESSION['last_regeneration'] = time();
        }
        
        // Generate CSRF token if not exists
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        } finally {
            self::$starting = false;
        }
    }
    
    /**
     * Get session value
     */
    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Set session value
     */
    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Check if session key exists
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove session value
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }
    
    /**
     * Get CSRF token
     */
    public static function getCsrfToken(): string
    {
        self::start();
        return $_SESSION['csrf_token'];
    }

    /**
     * Persist session data and release the write lock for the rest of the request.
     * The current request can still read from $_SESSION afterwards.
     */
    public static function closeWrite(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function lifetimeSeconds(?array $sessionConfig = null): int
    {
        if ($sessionConfig === null) {
            $config = require __DIR__ . '/../config/security.php';
            $sessionConfig = (array) ($config['session_security'] ?? []);
        }

        $envLifetime = self::envPositiveInt('SESSION_LIFETIME');
        if ($envLifetime > 0) {
            return $envLifetime;
        }

        $configured = (int) ($sessionConfig['session_timeout'] ?? self::DEFAULT_LIFETIME_SECONDS);
        return $configured > 0 ? $configured : self::DEFAULT_LIFETIME_SECONDS;
    }

    public static function isIdleExpired(): bool
    {
        self::start();
        self::evaluateIdleTimeout();
        return !empty($_SESSION['__session_idle_expired']);
    }

    public static function markIdleExpired(?int $expiredAt = null): void
    {
        self::start();
        $_SESSION['__session_idle_expired'] = true;
        $_SESSION['__session_expired_at'] = $expiredAt ?? time();
    }

    public static function idleExpiredAt(): ?int
    {
        self::start();
        self::evaluateIdleTimeout();
        $expiredAt = (int) ($_SESSION['__session_expired_at'] ?? 0);
        return $expiredAt > 0 ? $expiredAt : null;
    }

    public static function secondsUntilIdleExpiry(): ?int
    {
        self::start();
        self::evaluateIdleTimeout();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        if (!empty($_SESSION['__session_idle_expired'])) {
            return 0;
        }

        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity <= 0) {
            return self::lifetimeSeconds();
        }

        return max(0, ($lastActivity + self::lifetimeSeconds()) - time());
    }

    public static function lastActivityAt(): ?int
    {
        self::start();
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        return $lastActivity > 0 ? $lastActivity : null;
    }

    public static function markAuthenticatedActivity(?int $timestamp = null): void
    {
        self::start();
        $_SESSION['last_activity'] = $timestamp ?? time();
        unset($_SESSION['__session_idle_expired'], $_SESSION['__session_expired_at']);
    }

    public static function isPassiveRequest(): bool
    {
        $value = strtolower(trim((string) ($_SERVER['HTTP_X_CRM_SESSION_PASSIVE'] ?? '')));
        return in_array($value, ['1', 'true', 'yes'], true);
    }
    
    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken(string $token): bool
    {
        self::start();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Destroy session
     */
    public static function destroy(): void
    {
        if (!self::$started) {
            return;
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get("session.use_cookies")) {
                setcookie(
                    session_name(),
                    '',
                    self::cookieOptions(time() - 42000)
                );
            }
            session_destroy();
        }
        self::$started = false;
    }

    /**
     * Cookie options shared by PHP sessions and persistent auth cookies.
     *
     * @return array<string,mixed>
     */
    public static function cookieOptions(int $expires = 0): array
    {
        $config = require __DIR__ . '/../config/security.php';
        $sessionConfig = $config['session_security'];

        return [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isSecureCookieRequest(),
            'httponly' => $sessionConfig['cookie_httponly'] ? true : false,
            'samesite' => (string) ($sessionConfig['cookie_samesite'] ?? 'Strict'),
        ];
    }

    private static function isSecureCookieRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwardedProto === 'https';
    }

    private static function cookiePath(): string
    {
        if (function_exists('getApiBasePath')) {
            $basePath = (string) getApiBasePath();
            if ($basePath !== '') {
                return $basePath;
            }
        }

        if (function_exists('getBasePath')) {
            $basePath = (string) getBasePath();
            if ($basePath !== '') {
                $normalized = rtrim(str_replace('/public', '', $basePath), '/');
                return $normalized !== '' ? $normalized : '/';
            }
        }

        return '/';
    }

    private static function configureWritableSavePath(): void
    {
        if (ini_get('session.save_handler') !== 'files') {
            return;
        }

        $configuredPath = self::normalizeSessionSavePath((string) session_save_path());
        if ($configuredPath !== '' && is_dir($configuredPath) && is_writable($configuredPath)) {
            return;
        }

        $fallbackPath = dirname(__DIR__) . '/cache/sessions';
        if (!is_dir($fallbackPath)) {
            @mkdir($fallbackPath, 0775, true);
        }

        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
            session_save_path($fallbackPath);
        }
    }

    private static function configureSaveHandler(): void
    {
        $driver = strtolower(self::envString('SESSION_DRIVER', 'auto'));
        if ($driver === '' || $driver === 'auto') {
            return;
        }
        if (!in_array($driver, ['files', 'redis', 'memcached'], true)) {
            throw new \RuntimeException('SESSION_DRIVER must be auto, files, redis, or memcached.');
        }
        if ($driver === 'files') {
            ini_set('session.save_handler', 'files');
            return;
        }

        if ($driver === 'redis') {
            if (!extension_loaded('redis')) {
                throw new \RuntimeException('SESSION_DRIVER=redis requires the PHP Redis extension.');
            }
            $host = self::envString('REDIS_HOST', '127.0.0.1');
            $port = self::envPositiveInt('REDIS_PORT') ?: 6379;
            $database = max(0, self::envPositiveInt('REDIS_DATABASE'));
            $query = ['database=' . $database, 'prefix=' . rawurlencode(self::envString('SESSION_REDIS_PREFIX', 'crm_session:'))];
            $password = self::envString('REDIS_PASSWORD', '');
            if ($password !== '') {
                $query[] = 'auth=' . rawurlencode($password);
            }
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', 'tcp://' . $host . ':' . $port . '?' . implode('&', $query));
            return;
        }

        if (!extension_loaded('memcached')) {
            throw new \RuntimeException('SESSION_DRIVER=memcached requires the PHP Memcached extension.');
        }
        $host = self::envString('MEMCACHED_HOST', '127.0.0.1');
        $port = self::envPositiveInt('MEMCACHED_PORT') ?: 11211;
        ini_set('session.save_handler', 'memcached');
        ini_set('session.save_path', $host . ':' . $port);
    }

    private static function normalizeSessionSavePath(string $savePath): string
    {
        $savePath = trim($savePath);
        if ($savePath === '') {
            return '';
        }

        $parts = explode(';', $savePath);
        return (string) end($parts);
    }

    private static function evaluateIdleTimeout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['__session_idle_expired'])) {
            return;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity <= 0) {
            $_SESSION['last_activity'] = time();
            return;
        }

        $expiresAt = $lastActivity + self::lifetimeSeconds();
        if (time() >= $expiresAt) {
            $_SESSION['__session_idle_expired'] = true;
            $_SESSION['__session_expired_at'] = $expiresAt;
        }
    }

    private static function envPositiveInt(string $key): int
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        $value = trim((string) $value);
        return ctype_digit($value) ? max(0, (int) $value) : 0;
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }
        if ($value === false || $value === null) {
            return $default;
        }

        return trim((string) $value);
    }
}
