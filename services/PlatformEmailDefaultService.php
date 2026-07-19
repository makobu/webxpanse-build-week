<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Security;

class PlatformEmailDefaultService
{
    private const TABLE = 'platform_email_defaults';
    private const MAIN_SCOPE = EmailIntegrationService::SCOPE_MAIN_EMAIL;
    private const ASSISTANT_SCOPE = EmailIntegrationService::SCOPE_ASSISTANT_EMAIL;
    private const SECRET_KEYS = [
        'smtp_password',
        'imap_password',
    ];

    public function isAvailable(): bool
    {
        return Database::tableExists(self::TABLE);
    }

    public function save(string $scope, array $settings, ?int $userId = null): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('platform_email_defaults table is missing. Run migrations first.');
        }

        $scope = $this->normalizeScope($scope);
        $existing = $this->get($scope, true);
        $safeSettings = $this->prepareForStorage($settings, (array) ($existing['settings'] ?? []));

        Database::execute(
            "INSERT INTO " . self::TABLE . "
                (scope, settings_json, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                settings_json = VALUES(settings_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $scope,
                json_encode($safeSettings, JSON_UNESCAPED_SLASHES),
                $userId && $userId > 0 ? $userId : null,
            ]
        );

        return $this->get($scope);
    }

    public function get(string $scope = self::MAIN_SCOPE, bool $withSecrets = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $row = Database::queryOne(
            "SELECT *
             FROM " . self::TABLE . "
             WHERE scope = ?
             LIMIT 1",
            [$this->normalizeScope($scope)]
        );

        return $row ? $this->hydrate($row, $withSecrets) : [];
    }

    public function getEffective(string $scope = self::MAIN_SCOPE, bool $withSecrets = false): array
    {
        return $this->get($scope, $withSecrets);
    }

    public function smtpConfig(string $scope = self::MAIN_SCOPE): array
    {
        $config = $this->getEffective($scope, true);
        $settings = (array) ($config['settings'] ?? []);
        return [
            'host' => trim((string) ($settings['smtp_host'] ?? '')),
            'port' => (int) (($settings['smtp_port'] ?? 0) ?: 587),
            'username' => trim((string) ($settings['smtp_username'] ?? '')),
            'password' => (string) ($settings['smtp_password'] ?? ''),
            'encryption' => $this->normalizeEncryption((string) ($settings['smtp_encryption'] ?? 'tls')),
            'from_email' => trim((string) ($settings['from_email'] ?? '')),
            'from_name' => trim((string) ($settings['from_name'] ?? '')),
            'source' => $config !== [] ? 'platform_default' : '',
        ];
    }

    public function imapConfig(string $scope = self::MAIN_SCOPE): array
    {
        $config = $this->getEffective($scope, true);
        $settings = (array) ($config['settings'] ?? []);
        return [
            'enabled' => !empty($settings['imap_enabled']),
            'host' => trim((string) ($settings['imap_host'] ?? '')),
            'port' => (int) (($settings['imap_port'] ?? 0) ?: 993),
            'username' => trim((string) ($settings['imap_username'] ?? '')),
            'password' => (string) ($settings['imap_password'] ?? ''),
            'protocol' => trim((string) ($settings['imap_protocol'] ?? 'imap')) ?: 'imap',
            'encryption' => $this->normalizeEncryption((string) ($settings['imap_encryption'] ?? 'ssl')),
            'folder' => trim((string) ($settings['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
            'source' => $config !== [] ? 'platform_default' : '',
        ];
    }

    public function summary(string $scope = self::MAIN_SCOPE): array
    {
        $smtp = $this->smtpConfig($scope);
        $imap = $this->imapConfig($scope);

        return [
            'smtp_configured' => $this->smtpReady($smtp),
            'imap_configured' => $this->imapReady($imap),
            'from_email' => trim((string) ($smtp['from_email'] ?? '')),
            'from_name' => trim((string) ($smtp['from_name'] ?? '')),
            'source' => ($smtp['source'] ?? '') === 'platform_default' || ($imap['source'] ?? '') === 'platform_default'
                ? 'platform_default'
                : '',
        ];
    }

    public function smtpReady(array $smtp): bool
    {
        return trim((string) ($smtp['host'] ?? '')) !== ''
            && trim((string) ($smtp['username'] ?? '')) !== ''
            && (string) ($smtp['password'] ?? '') !== '';
    }

    public function imapReady(array $imap): bool
    {
        return !empty($imap['enabled'])
            && trim((string) ($imap['host'] ?? '')) !== ''
            && trim((string) ($imap['username'] ?? '')) !== ''
            && (string) ($imap['password'] ?? '') !== '';
    }

    private function hydrate(array $row, bool $withSecrets): array
    {
        $settings = [];
        $decoded = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        if (is_array($decoded)) {
            $settings = $withSecrets ? $this->restoreFromStorage($decoded) : $this->maskSecrets($decoded);
        }

        return [
            'scope' => (string) ($row['scope'] ?? ''),
            'settings' => $settings,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function prepareForStorage(array $settings, array $existingSettings): array
    {
        $fromEmail = trim((string) ($settings['from_email'] ?? $existingSettings['from_email'] ?? ''));
        $smtpUsername = trim((string) ($settings['smtp_username'] ?? ''));
        if ($smtpUsername === '' && $fromEmail !== '') {
            $smtpUsername = $fromEmail;
        }

        $imapEnabled = !empty($settings['imap_enabled']);
        $imapUsername = trim((string) ($settings['imap_username'] ?? ''));
        if ($imapEnabled && $imapUsername === '' && $fromEmail !== '') {
            $imapUsername = $fromEmail;
        }

        $out = [
            'from_email' => $fromEmail,
            'from_name' => trim((string) ($settings['from_name'] ?? $existingSettings['from_name'] ?? '')),
            'smtp_host' => trim((string) ($settings['smtp_host'] ?? '')),
            'smtp_port' => (int) (($settings['smtp_port'] ?? 0) ?: 587),
            'smtp_username' => $smtpUsername,
            'smtp_encryption' => $this->normalizeEncryption((string) ($settings['smtp_encryption'] ?? 'tls')),
            'imap_enabled' => $imapEnabled,
            'imap_host' => trim((string) ($settings['imap_host'] ?? '')),
            'imap_port' => (int) (($settings['imap_port'] ?? 0) ?: 993),
            'imap_username' => $imapUsername,
            'imap_protocol' => trim((string) ($settings['imap_protocol'] ?? 'imap')) ?: 'imap',
            'imap_encryption' => $this->normalizeEncryption((string) ($settings['imap_encryption'] ?? 'ssl')),
            'imap_folder' => trim((string) ($settings['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
        ];

        $smtpPassword = trim((string) ($settings['smtp_password'] ?? ''));
        if ($smtpPassword !== '') {
            $out['smtp_password'] = $this->encryptSecret($smtpPassword);
        } elseif (array_key_exists('smtp_password', $existingSettings)) {
            $out['smtp_password'] = $this->encryptSecret((string) $existingSettings['smtp_password']);
        }

        $imapPassword = trim((string) ($settings['imap_password'] ?? ''));
        if ($imapPassword === '' && !array_key_exists('imap_password', $existingSettings) && $smtpPassword !== '') {
            $imapPassword = $smtpPassword;
        }
        if ($imapPassword !== '') {
            $out['imap_password'] = $this->encryptSecret($imapPassword);
        } elseif (array_key_exists('imap_password', $existingSettings)) {
            $out['imap_password'] = $this->encryptSecret((string) $existingSettings['imap_password']);
        }

        return $out;
    }

    private function restoreFromStorage(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $settings[$key] ?? null;
            if (is_array($value) && !empty($value['encrypted']) && isset($value['value'])) {
                try {
                    $settings[$key] = Security::decryptSensitiveData((string) $value['value'], $this->encryptionKey());
                } catch (\Throwable $e) {
                    $settings[$key] = '';
                }
            }
        }
        return $settings;
    }

    private function maskSecrets(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = 'saved';
            }
        }
        return $settings;
    }

    private function encryptSecret(string $secret): array
    {
        return [
            'encrypted' => true,
            'value' => Security::encryptSensitiveData($secret, $this->encryptionKey()),
        ];
    }

    private function normalizeScope(string $scope): string
    {
        $scope = trim($scope);
        return $scope === self::ASSISTANT_SCOPE ? self::ASSISTANT_SCOPE : self::MAIN_SCOPE;
    }

    private function normalizeEncryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));
        return in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'tls';
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
