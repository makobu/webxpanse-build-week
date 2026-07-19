<?php
declare(strict_types=1);

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;

class ReadinessCaptureSettings
{
    public const CONNECTOR_KEY = 'business_ai_readiness';
    public const DEFAULT_SOURCE = 'web_assessment';

    /**
     * Keep source labels compatible with the contacts.lead_source enum.
     */
    private const ALLOWED_SOURCES = [
        'form',
        'whatsapp',
        'ad',
        'referral',
        'social',
        'import',
        'other',
        'mobile_app',
        'web_assessment',
    ];

    public function getOrCreate(?int $userId = null): array
    {
        if (!$this->tableExists()) {
            return $this->defaults();
        }

        $row = Database::queryOne(
            "SELECT *
             FROM readiness_capture_settings
             WHERE connector_key = ?
             LIMIT 1",
            [self::CONNECTOR_KEY]
        );

        if ($row) {
            return $this->hydrateRow($row);
        }

        $secret = $this->generateSecret();
        Database::execute(
            "INSERT INTO readiness_capture_settings
                (connector_key, is_enabled, capture_secret, source_label, origin_notes, created_by_user_id, updated_by_user_id)
             VALUES (?, 0, ?, ?, NULL, ?, ?)",
            [
                self::CONNECTOR_KEY,
                $secret,
                self::DEFAULT_SOURCE,
                $userId ?: null,
                $userId ?: null,
            ]
        );

        $created = Database::queryOne(
            "SELECT *
             FROM readiness_capture_settings
             WHERE connector_key = ?
             LIMIT 1",
            [self::CONNECTOR_KEY]
        );

        return $created ? $this->hydrateRow($created) : $this->defaults();
    }

    public function update(array $data, int $userId): array
    {
        $current = $this->getOrCreate($userId);
        if (!$this->tableExists() || empty($current['id'])) {
            return $current;
        }

        $source = $this->normalizeSourceLabel((string) ($data['source_label'] ?? $current['source_label']));
        $originNotes = trim((string) ($data['origin_notes'] ?? $current['origin_notes']));
        $originNotes = $originNotes !== '' ? Security::sanitizeInput($originNotes, 'string') : null;

        Database::execute(
            "UPDATE readiness_capture_settings
             SET is_enabled = ?,
                 source_label = ?,
                 origin_notes = ?,
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                !empty($data['is_enabled']) ? 1 : 0,
                $source,
                $originNotes,
                $userId,
                (int) $current['id'],
            ]
        );

        return $this->getOrCreate($userId);
    }

    public function rotateSecret(int $userId): array
    {
        $current = $this->getOrCreate($userId);
        if (!$this->tableExists() || empty($current['id'])) {
            return $current;
        }

        Database::execute(
            "UPDATE readiness_capture_settings
             SET capture_secret = ?,
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $this->generateSecret(),
                $userId,
                (int) $current['id'],
            ]
        );

        return $this->getOrCreate($userId);
    }

    public function authenticate(string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '' || !$this->tableExists()) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM readiness_capture_settings
             WHERE connector_key = ?
               AND is_enabled = 1
             LIMIT 1",
            [self::CONNECTOR_KEY]
        );

        if (!$row) {
            return null;
        }

        $settings = $this->hydrateRow($row);
        if (!hash_equals((string) ($settings['capture_secret'] ?? ''), $secret)) {
            return null;
        }

        return $settings;
    }

    public function buildCaptureUrl(?string $baseUrl = null): string
    {
        $baseUrl = rtrim((string) $baseUrl, '/');
        if ($baseUrl === '') {
            return '/api/marketing/assessment_capture.php';
        }

        return $baseUrl . '/api/marketing/assessment_capture.php';
    }

    public function getAllowedSources(): array
    {
        return self::ALLOWED_SOURCES;
    }

    private function defaults(): array
    {
        return [
            'id' => null,
            'connector_key' => self::CONNECTOR_KEY,
            'is_enabled' => false,
            'capture_secret' => '',
            'source_label' => self::DEFAULT_SOURCE,
            'origin_notes' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    private function hydrateRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'connector_key' => (string) ($row['connector_key'] ?? self::CONNECTOR_KEY),
            'is_enabled' => !empty($row['is_enabled']),
            'capture_secret' => (string) ($row['capture_secret'] ?? ''),
            'source_label' => $this->normalizeSourceLabel((string) ($row['source_label'] ?? self::DEFAULT_SOURCE)),
            'origin_notes' => !empty($row['origin_notes']) ? (string) $row['origin_notes'] : null,
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'updated_by_user_id' => isset($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
        ];
    }

    private function normalizeSourceLabel(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            return self::DEFAULT_SOURCE;
        }

        return $source;
    }

    private function generateSecret(): string
    {
        return 'wxrc_' . bin2hex(random_bytes(24));
    }

    private function tableExists(): bool
    {
        static $tableExists = null;
        if ($tableExists !== null) {
            return $tableExists;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'readiness_capture_settings'"
            );
            $tableExists = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            $tableExists = false;
        }

        return $tableExists;
    }
}
