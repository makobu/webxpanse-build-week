<?php

declare(strict_types=1);

namespace CRM\Services;

class DemoCryptoService
{
    private const CIPHER = 'aes-256-cbc';

    public function encrypt(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($value, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt demo visitor data.');
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $this->key(), true);
        return base64_encode($iv . $mac . $ciphertext);
    }

    public function decrypt(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 49) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $ciphertext = substr($raw, 48);
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $this->key(), true);
        if (!hash_equals($expectedMac, $mac)) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? null : $plain;
    }

    public function lookupHash(?string $value): ?string
    {
        $normalized = $this->normalizeLookupValue($value);
        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, $this->key());
    }

    public function normalizeEmail(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    public function normalizePhone(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $prefix = str_starts_with($value, '+') ? '+' : '';
        return $prefix . preg_replace('/\D+/', '', $value);
    }

    private function normalizeLookupValue(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '@')) {
            return $this->normalizeEmail($value);
        }

        return $this->normalizePhone($value);
    }

    private function key(): string
    {
        $material = (string) (
            getenv('DEMO_DATA_KEY')
            ?: getenv('APP_KEY')
            ?: ($_ENV['DEMO_DATA_KEY'] ?? $_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? '')
        );
        if (trim($material) === '') {
            $material = dirname(__DIR__) . '|protected-demo-workspace';
        }

        return hash('sha256', $material, true);
    }
}
