<?php
/**
 * Security Utilities
 * 
 * Input sanitization, CSRF protection, encryption
 */

namespace CRM;

class Security
{
    /**
     * Sanitize input based on type
     */
    public static function sanitizeInput($input, string $type = 'string')
    {
        if ($input === null) {
            return null;
        }
        
        switch ($type) {
            case 'email':
                $input = filter_var($input, FILTER_SANITIZE_EMAIL);
                return filter_var($input, FILTER_VALIDATE_EMAIL) ? $input : null;
                
            case 'url':
                $input = filter_var($input, FILTER_SANITIZE_URL);
                return filter_var($input, FILTER_VALIDATE_URL) ? $input : null;
                
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
                
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                
            case 'string':
            default:
                $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
                $input = strip_tags($input);
                return trim($input);
        }
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCSRF(string $token): bool
    {
        return Session::verifyCsrfToken($token);
    }
    
    /**
     * Get CSRF token for forms
     */
    public static function getCsrfToken(): string
    {
        return Session::getCsrfToken();
    }
    
    /**
     * Encrypt sensitive data
     */
    public static function encryptSensitiveData(string $data, string $key): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $data,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public static function decryptSensitiveData(string $encryptedData, string $key): string
    {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }
        
        return $decrypted;
    }
    
    /**
     * Validate email format
     */
    public static function validateEmail(string $email): bool
    {
        $config = require __DIR__ . '/../config/security.php';
        return (bool) preg_match($config['input_validation']['email_regex'], $email);
    }
    
    /**
     * Validate phone format
     */
    public static function validatePhone(string $phone): bool
    {
        $config = require __DIR__ . '/../config/security.php';
        return (bool) preg_match($config['input_validation']['phone_regex'], $phone);
    }

    /**
     * Normalize a redirect target and reject control characters or unsafe schemes.
     *
     * Absolute URLs are limited to http/https. Relative paths are allowed for
     * local redirects, but protocol-relative URLs are not.
     */
    public static function sanitizeRedirectUrl(?string $url, string $fallback = '/'): string
    {
        $candidate = trim((string) $url);
        if ($candidate === '' || str_contains($candidate, "\r") || str_contains($candidate, "\n")) {
            return $fallback;
        }

        if (str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        if ($scheme !== null) {
            $scheme = strtolower((string) $scheme);
            return in_array($scheme, ['http', 'https'], true) ? $candidate : $fallback;
        }

        return $candidate;
    }

    /**
     * Sanitize a filename before placing it in a Content-Disposition header.
     */
    public static function sanitizeHeaderFilename(?string $filename, string $fallback = 'file'): string
    {
        $safe = basename(str_replace(["\r", "\n", "\0"], '', (string) $filename));
        $safe = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '_', $safe) ?? '';
        $safe = trim($safe);

        return $safe !== '' && $safe !== '.' && $safe !== '..' ? $safe : $fallback;
    }
    
    /**
     * Generate secure random string
     */
    public static function generateRandomString(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Validate password strength
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validatePasswordStrength(string $password): array
    {
        $errors = [];
        $minLength = (int) ($_ENV['PASSWORD_MIN_LENGTH'] ?? 8);
        $requireUppercase = ($_ENV['PASSWORD_REQUIRE_UPPERCASE'] ?? 'true') !== 'false';
        $requireLowercase = ($_ENV['PASSWORD_REQUIRE_LOWERCASE'] ?? 'true') !== 'false';
        $requireNumber = ($_ENV['PASSWORD_REQUIRE_NUMBER'] ?? 'true') !== 'false';
        $requireSpecial = ($_ENV['PASSWORD_REQUIRE_SPECIAL'] ?? 'true') !== 'false';

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters.";
        }
        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if ($requireNumber && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if ($requireSpecial && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&* etc.).';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
