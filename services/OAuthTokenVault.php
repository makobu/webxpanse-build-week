<?php
/**
 * Encrypts OAuth tokens while preserving read compatibility for legacy plaintext values.
 */

namespace CRM\Services;

use CRM\Security;

class OAuthTokenVault
{
    public function encrypt(?string $token): ?string
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        if ($this->isEncrypted($token)) {
            return $token;
        }

        return json_encode([
            'encrypted' => true,
            'value' => Security::encryptSensitiveData($token, $this->encryptionKey()),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function decrypt(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return '';
        }

        $decoded = json_decode($stored, true);
        if (is_array($decoded) && !empty($decoded['encrypted']) && isset($decoded['value'])) {
            try {
                return Security::decryptSensitiveData((string) $decoded['value'], $this->encryptionKey());
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $stored;
    }

    public function isEncrypted(?string $stored): bool
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return false;
        }

        $decoded = json_decode($stored, true);
        return is_array($decoded) && !empty($decoded['encrypted']) && isset($decoded['value']);
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
