<?php
/**
 * Email Signatures Module
 * 
 * Handles email signature management
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Auth;
use CRM\Services\DesignTypographyCatalog;

class EmailSignatures
{
    /** @var bool|null */
    private static ?bool $hasSettingsColumn = null;
    /** @var bool */
    private static bool $schemaEnsured = false;

    public function __construct()
    {
        $this->ensureSchema();
    }

    /**
     * Create a new email signature
     */
    public function create(array $data): int
    {
        if (empty($data['name']) || empty($data['content_html'])) {
            throw new \Exception("Name and HTML content are required");
        }
        
        $userId = Auth::userId();
        if (!$userId) {
            throw new \Exception("User must be logged in");
        }
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $contentHtml = $data['content_html'];
        $contentText = $data['content_text'] ?? strip_tags($contentHtml);
        $isDefault = isset($data['is_default']) && $data['is_default'] ? 1 : 0;
        $settings = $data['settings'] ?? [];
        $settingsJson = is_array($settings) ? json_encode($settings) : (is_string($settings) ? $settings : '{}');
        
        // If this is set as default, unset other defaults for this user
        if ($isDefault) {
            Database::execute(
                "UPDATE email_signatures SET is_default = 0 WHERE user_id = ?",
                [$userId]
            );
        }
        
        if ($this->hasSettingsColumn()) {
            Database::execute(
                "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default, settings) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $name, $contentHtml, $contentText, $isDefault, $settingsJson]
            );
        } else {
            Database::execute(
                "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default) 
                 VALUES (?, ?, ?, ?, ?)",
                [$userId, $name, $contentHtml, $contentText, $isDefault]
            );
        }
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get all signatures for current user
     */
    public function getUserSignatures(): array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return [];
        }

        return $this->getUserSignaturesForUser($userId);
    }

    public function getUserSignaturesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $rows = Database::query(
                "SELECT * FROM email_signatures WHERE user_id = ? ORDER BY is_default DESC, created_at DESC",
                [$userId]
            );
            foreach ($rows as &$row) {
                $row = $this->normalizeSignature($row);
            }
            return $rows;
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $this->ensureSchema();
                $rows = Database::query(
                    "SELECT * FROM email_signatures WHERE user_id = ? ORDER BY is_default DESC, created_at DESC",
                    [$userId]
                );
                foreach ($rows as &$row) {
                    $row = $this->normalizeSignature($row);
                }
                return $rows;
            }

            throw $e;
        }
    }
    
    /**
     * Get signature by ID (must belong to current user)
     */
    public function getById(int $id): ?array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return null;
        }
        
        $signature = Database::queryOne(
            "SELECT * FROM email_signatures WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        if ($signature) {
            $signature = $this->normalizeSignature($signature);
        }
        return $signature;
    }
    
    /**
     * Get default signature for current user
     */
    public function getDefault(): ?array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return null;
        }

        return $this->getDefaultForUser($userId);
    }

    public function getDefaultForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $signature = Database::queryOne(
            "SELECT * FROM email_signatures WHERE user_id = ? AND is_default = 1 LIMIT 1",
            [$userId]
        );

        return $signature ? $this->normalizeSignature($signature) : null;
    }
    
    /**
     * Update signature
     */
    public function update(int $id, array $data): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        
        // Verify signature belongs to user
        $signature = $this->getById($id);
        if (!$signature) {
            return false;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = Security::sanitizeInput($data['name'], 'string');
        }
        
        if (isset($data['content_html'])) {
            $updates[] = "content_html = ?";
            $params[] = $data['content_html'];
        }
        
        if (isset($data['content_text'])) {
            $updates[] = "content_text = ?";
            $params[] = $data['content_text'];
        }
        
        if (isset($data['is_default'])) {
            $isDefault = $data['is_default'] ? 1 : 0;
            $updates[] = "is_default = ?";
            $params[] = $isDefault;
            
            // If setting as default, unset other defaults
            if ($isDefault) {
                Database::execute(
                    "UPDATE email_signatures SET is_default = 0 WHERE user_id = ? AND id != ?",
                    [$userId, $id]
                );
            }
        }
        
        if ($this->hasSettingsColumn() && array_key_exists('settings', $data)) {
            $settings = $data['settings'];
            $settingsJson = is_array($settings) ? json_encode($settings) : (is_string($settings) ? $settings : '{}');
            $updates[] = "settings = ?";
            $params[] = $settingsJson;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        $params[] = $userId;
        
        Database::execute(
            "UPDATE email_signatures SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?",
            $params
        );
        
        return true;
    }
    
    /**
     * Delete signature
     */
    public function delete(int $id): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        
        Database::execute(
            "DELETE FROM email_signatures WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        
        return true;
    }

    /**
     * Duplicate a signature for the current user without changing their default.
     */
    public function duplicate(int $id): int
    {
        $signature = $this->getById($id);
        if (!$signature) {
            throw new \Exception('Signature not found or you do not have permission to duplicate it.');
        }

        $name = trim((string) ($signature['name'] ?? 'Signature'));
        if ($name === '') {
            $name = 'Signature';
        }

        return $this->create([
            'name' => $name . ' copy',
            'content_html' => (string) ($signature['content_html'] ?? ''),
            'content_text' => (string) ($signature['content_text'] ?? ''),
            'is_default' => 0,
            'settings' => is_array($signature['settings'] ?? null) ? $signature['settings'] : [],
        ]);
    }
    
    /**
     * Set signature as default
     */
    public function setDefault(int $id): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        
        // Verify signature belongs to user
        $signature = $this->getById($id);
        if (!$signature) {
            return false;
        }
        
        // Unset other defaults
        Database::execute(
            "UPDATE email_signatures SET is_default = 0 WHERE user_id = ?",
            [$userId]
        );
        
        // Set this as default
        Database::execute(
            "UPDATE email_signatures SET is_default = 1 WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        
        return true;
    }
    
    /**
     * Get full signature HTML including logo (for use in emails - uses absolute URLs)
     */
    public function getSignatureHtml(array $signature): string
    {
        $html = $signature['content_html'] ?? '';
        $settings = $signature['settings'] ?? [];
        $settings = is_array($settings) ? $settings : [];
        $textColor = $this->sanitizeColor($settings['text_color'] ?? '#1a1a1a', '#1a1a1a');
        $accentColor = $this->sanitizeColor($settings['accent_color'] ?? '#2563eb', '#2563eb');
        $templateStyle = $this->sanitizeTemplateStyle((string) ($settings['template_style'] ?? 'minimal'));
        $fontFamily = DesignTypographyCatalog::emailFontCss((string) ($settings['font_family'] ?? 'arial'));
        $fontSize = DesignTypographyCatalog::emailSize((string) ($settings['font_size_preset'] ?? 'standard'));
        $logoPath = $settings['logo_path'] ?? null;
        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/crm', '/');
        $logoUrl = $baseUrl . '/public/signature_asset.php?signature_id=' . (int) ($signature['id'] ?? 0) . '&type=logo';

        $wrapperStyles = [
            'color: ' . htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8'),
            'font-family: ' . htmlspecialchars($fontFamily, ENT_QUOTES, 'UTF-8'),
            'font-size: ' . (int) $fontSize['pixels'] . 'px',
            'line-height: ' . (string) $fontSize['line_height'],
        ];
        if ($templateStyle === 'professional') {
            $wrapperStyles[] = 'border-left: 3px solid ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8');
            $wrapperStyles[] = 'padding-left: 12px';
        } elseif ($templateStyle === 'sales') {
            $wrapperStyles[] = 'border-top: 2px solid ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8');
            $wrapperStyles[] = 'padding-top: 10px';
        }
        $html = '<div style="' . implode('; ', $wrapperStyles) . ';">' . $html . '</div>';
        $html = preg_replace(
            '/<a\b(?![^>]*\bstyle=)/i',
            '<a style="color: ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') . '; text-decoration: none;"',
            $html
        );

        if (!empty($logoPath)) {
            $logoHtml = '<div style="margin-bottom: 8px;"><img src="' . htmlspecialchars($logoUrl) . '" alt="Logo" style="max-height: 60px; max-width: 180px; display: block;" /></div>';
            $html = $logoHtml . $html;
        }
        return $html;
    }

    public function getSignatureText(array $signature): string
    {
        $contentText = trim((string) ($signature['content_text'] ?? ''));
        if ($contentText !== '') {
            return $contentText;
        }

        $html = str_replace(['<br>', '<br/>', '<br />'], "\n", (string) ($signature['content_html'] ?? ''));
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text) ?? $text;

        return trim((string) $text);
    }

    public function stripKnownSignaturesFromText(string $text, array $signatures): string
    {
        $clean = str_replace(["\r\n", "\r"], "\n", $text);

        foreach ($signatures as $signature) {
            if (!is_array($signature)) {
                continue;
            }

            $fragment = $this->getSignatureText($signature);
            if ($fragment === '') {
                continue;
            }

            $clean = preg_replace(
                '/(?:\s*\n\s*|\s*)' . preg_quote($fragment, '/') . '\s*$/u',
                '',
                $clean
            ) ?? $clean;
        }

        return trim((string) $clean);
    }

    public function stripKnownSignaturesFromHtml(string $html, array $signatures): string
    {
        $clean = $html;

        foreach ($signatures as $signature) {
            if (!is_array($signature)) {
                continue;
            }

            $fragment = trim($this->getSignatureHtml($signature));
            if ($fragment === '') {
                continue;
            }

            $clean = preg_replace(
                '/(?:<br\s*\/?>|\s|&nbsp;)*' . preg_quote($fragment, '/') . '\s*$/iu',
                '',
                $clean
            ) ?? $clean;
        }

        return trim((string) $clean);
    }

    /**
     * Ensure email_signatures table exists
     */
    public function hasSettingsColumn(): bool
    {
        if (self::$hasSettingsColumn !== null) {
            return self::$hasSettingsColumn;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'email_signatures'
                   AND COLUMN_NAME = 'settings'"
            );
            self::$hasSettingsColumn = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$hasSettingsColumn = false;
        }

        return self::$hasSettingsColumn;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        // Keep compatibility with environments where migrations were skipped.
        Database::execute(
            "CREATE TABLE IF NOT EXISTS email_signatures (
                id INT NOT NULL,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                content_html TEXT NOT NULL,
                content_text TEXT NULL,
                is_default TINYINT(1) DEFAULT 0,
                settings LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_is_default (is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!$this->hasSettingsColumn()) {
            try {
                Database::execute("ALTER TABLE email_signatures ADD COLUMN settings LONGTEXT NULL AFTER content_text");
                self::$hasSettingsColumn = true;
            } catch (\Throwable $e) {
                // Ignore when column already exists or alter is unsupported.
            }
        }

        $pkCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'email_signatures'
               AND CONSTRAINT_TYPE = 'PRIMARY KEY'"
        )['c'] ?? 0);

        if ($pkCount === 0) {
            try {
                Database::execute("ALTER TABLE email_signatures ADD PRIMARY KEY (id)");
            } catch (\Throwable $e) {
                // Ignore if another key shape already exists.
            }
        }

        try {
            Database::execute("ALTER TABLE email_signatures MODIFY id INT NOT NULL AUTO_INCREMENT");
        } catch (\Throwable $e) {
            // Ignore if already configured.
        }

        self::$schemaEnsured = true;
    }

    private function normalizeSignature(array $signature): array
    {
        if (isset($signature['settings'])) {
            $signature['settings'] = is_string($signature['settings']) ? json_decode($signature['settings'], true) : $signature['settings'];
            $signature['settings'] = is_array($signature['settings'] ?? null) ? $signature['settings'] : [];
        } else {
            $signature['settings'] = [];
        }
        return $signature;
    }

    private function sanitizeColor(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }
        return $fallback;
    }

    private function sanitizeTemplateStyle(string $value): string
    {
        return in_array($value, ['professional', 'sales', 'minimal'], true) ? $value : 'minimal';
    }
}
