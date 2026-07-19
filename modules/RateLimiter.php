<?php
/**
 * Rate Limiter Module
 *
 * File-based rate limiting for web endpoints (login, forgot password, form submit).
 * Uses sliding window: e.g. 5 failed logins per 15 min per IP.
 */

namespace CRM\Modules;

class RateLimiter
{
    private string $cacheDir;
    private int $maxAttempts;
    private int $windowSeconds;
    private string $identifier;

    /**
     * @param string $identifier Unique identifier (e.g. 'login', 'forgot_password', 'form_submit')
     * @param int|null $maxAttempts Override from env (default from RATE_LIMIT_*)
     * @param int|null $windowSeconds Override from env
     */
    public function __construct(string $identifier, ?int $maxAttempts = null, ?int $windowSeconds = null)
    {
        $this->identifier = $identifier;
        $this->cacheDir = (defined('CACHE_PATH') ? CACHE_PATH : (__DIR__ . '/../cache')) . '/rate_limit';
        $this->maxAttempts = $maxAttempts ?? (int) ($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5);
        $this->windowSeconds = $windowSeconds ?? (int) ($_ENV['RATE_LIMIT_WINDOW_SECONDS'] ?? 900); // 15 min

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get client identifier (IP + optional suffix)
     */
    private function getClientKey(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return md5($this->identifier . '_' . $ip);
    }

    /**
     * Get file path for this client
     */
    private function getFilePath(): string
    {
        return $this->cacheDir . '/' . $this->getClientKey() . '.json';
    }

    /**
     * Check if rate limit is exceeded
     */
    public function isLimited(): bool
    {
        $attempts = $this->getAttemptsInWindow();
        return $attempts >= $this->maxAttempts;
    }

    /**
     * Record an attempt
     */
    public function recordAttempt(): void
    {
        $file = $this->getFilePath();
        $now = time();
        $timestamps = [];

        if (file_exists($file)) {
            $data = @json_decode(file_get_contents($file), true);
            $timestamps = $data['timestamps'] ?? [];
        }

        $timestamps[] = $now;
        $cutoff = $now - $this->windowSeconds;
        $timestamps = array_filter($timestamps, fn($t) => $t > $cutoff);
        sort($timestamps);

        @file_put_contents($file, json_encode([
            'timestamps' => $timestamps,
            'updated' => $now
        ]), LOCK_EX);
    }

    /**
     * Get number of attempts in current window
     */
    private function getAttemptsInWindow(): int
    {
        $file = $this->getFilePath();
        if (!file_exists($file)) {
            return 0;
        }

        $data = @json_decode(file_get_contents($file), true);
        $timestamps = $data['timestamps'] ?? [];
        $cutoff = time() - $this->windowSeconds;

        return count(array_filter($timestamps, fn($t) => $t > $cutoff));
    }

    /**
     * Get remaining attempts before limit
     */
    public function getRemainingAttempts(): int
    {
        $attempts = $this->getAttemptsInWindow();
        return max(0, $this->maxAttempts - $attempts);
    }

    /**
     * Get seconds until window resets (oldest attempt + window - now)
     */
    public function getRetryAfterSeconds(): int
    {
        $file = $this->getFilePath();
        if (!file_exists($file)) {
            return 0;
        }

        $data = @json_decode(file_get_contents($file), true);
        $timestamps = $data['timestamps'] ?? [];
        $cutoff = time() - $this->windowSeconds;
        $validTimestamps = array_filter($timestamps, fn($t) => $t > $cutoff);

        if (empty($validTimestamps)) {
            return 0;
        }

        $oldest = min($validTimestamps);
        $resetAt = $oldest + $this->windowSeconds;
        return max(0, $resetAt - time());
    }

    /**
     * Clear attempts for this client (e.g. after successful login)
     */
    public function clear(): void
    {
        $file = $this->getFilePath();
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
