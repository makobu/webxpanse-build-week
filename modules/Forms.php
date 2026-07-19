<?php
/**
 * Forms Module - Form builder definitions
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class Forms
{
    /** @var bool|null */
    private static ?bool $hasSettingsColumn = null;
    /** @var bool */
    private static bool $schemaEnsured = false;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->ensureSchema();
    }

    private static function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function create(array $data): int
    {
        $name = Security::sanitizeInput($data['name'] ?? '', 'string');
        $fields = $data['fields'] ?? [];
        $successMessage = Security::sanitizeInput($data['success_message'] ?? 'Thank you! Your submission has been received.', 'string');
        $redirectUrl = Security::sanitizeRedirectUrl((string) ($data['redirect_url'] ?? ''), '');
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0) ?: null;

        if (empty($name)) {
            throw new \Exception('Form name is required');
        }

        $uuid = self::generateUuid();
        $fieldsJson = is_array($fields) ? json_encode($fields) : $fields;
        $settings = $data['settings'] ?? [];
        $settingsJson = is_array($settings) ? json_encode($settings) : (is_string($settings) ? $settings : '{}');

        if ($this->hasSettingsColumn()) {
            Database::execute(
                "INSERT INTO forms (workspace_id, uuid, name, fields, success_message, redirect_url, settings, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$this->workspaceId(), $uuid, $name, $fieldsJson, $successMessage, $redirectUrl ?: null, $settingsJson, $createdBy]
            );
        } else {
            Database::execute(
                "INSERT INTO forms (workspace_id, uuid, name, fields, success_message, redirect_url, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$this->workspaceId(), $uuid, $name, $fieldsJson, $successMessage, $redirectUrl ?: null, $createdBy]
            );
        }

        $id = (int) Database::lastInsertId();
        if ($id <= 0) {
            $row = Database::queryOne("SELECT id FROM forms WHERE uuid = ? LIMIT 1", [$uuid]);
            $id = (int) ($row['id'] ?? 0);
        }

        return $id;
    }

    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause();
        $form = Database::queryOne(
            "SELECT * FROM forms WHERE {$workspace['sql']} AND id = ?",
            array_merge($workspace['params'], [$id])
        );
        if ($form) {
            if (!empty($form['fields'])) {
                $form['fields'] = is_string($form['fields']) ? json_decode($form['fields'], true) : $form['fields'];
            }
            if (isset($form['settings'])) {
                $form['settings'] = is_string($form['settings']) ? json_decode($form['settings'], true) : $form['settings'];
                $form['settings'] = is_array($form['settings']) ? $form['settings'] : [];
            } else {
                $form['settings'] = [];
            }
            foreach (['design_document_json', 'design_validation_json', 'published_document_json'] as $jsonField) {
                if (array_key_exists($jsonField, $form)) {
                    $decoded = is_string($form[$jsonField]) ? json_decode($form[$jsonField], true) : $form[$jsonField];
                    $form[$jsonField] = is_array($decoded) ? $decoded : [];
                }
            }
        }
        return $form;
    }

    public function getByUuid(string $uuid): ?array
    {
        $workspaceId = $this->workspaceScope->currentWorkspaceId();
        if ($workspaceId !== null && $workspaceId > 0) {
            $form = Database::queryOne(
                "SELECT * FROM forms WHERE workspace_id = ? AND uuid = ?",
                [$workspaceId, $uuid]
            );
        } else {
            $form = Database::queryOne("SELECT * FROM forms WHERE uuid = ?", [$uuid]);
        }
        if ($form) {
            if (!empty($form['fields'])) {
                $form['fields'] = is_string($form['fields']) ? json_decode($form['fields'], true) : $form['fields'];
            }
            if (isset($form['settings'])) {
                $form['settings'] = is_string($form['settings']) ? json_decode($form['settings'], true) : $form['settings'];
                $form['settings'] = is_array($form['settings']) ? $form['settings'] : [];
            } else {
                $form['settings'] = [];
            }
            foreach (['design_document_json', 'design_validation_json', 'published_document_json'] as $jsonField) {
                if (array_key_exists($jsonField, $form)) {
                    $decoded = is_string($form[$jsonField]) ? json_decode($form[$jsonField], true) : $form[$jsonField];
                    $form[$jsonField] = is_array($decoded) ? $decoded : [];
                }
            }
        }
        return $form;
    }

    public function list(): array
    {
        $workspace = $this->workspaceClause('f.');
        $rows = Database::query(
            "SELECT f.*, u.email as created_by_email,
             (SELECT COUNT(*)
                FROM form_submissions fs
               WHERE fs.workspace_id = f.workspace_id
                 AND (fs.form_id = f.uuid
                  OR (f.id > 0 AND fs.form_definition_id = f.id))
             ) as submission_count
             FROM forms f
             LEFT JOIN users u ON f.created_by = u.id
             WHERE {$workspace['sql']}
             ORDER BY f.updated_at DESC",
            $workspace['params']
        );
        foreach ($rows as &$r) {
            if (!empty($r['fields']) && is_string($r['fields'])) {
                $r['fields'] = json_decode($r['fields'], true);
            }
        }
        return $rows;
    }

    public function update(int $id, array $data): bool
    {
        $form = $this->getById($id);
        if (!$form) {
            throw new \Exception('Form not found');
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $name = Security::sanitizeInput($data['name'], 'string');
            if ($name === '') {
                throw new \InvalidArgumentException('Form name is required');
            }
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if (isset($data['fields'])) {
            $updates[] = 'fields = ?';
            $params[] = is_array($data['fields']) ? json_encode($data['fields']) : $data['fields'];
        }
        if (array_key_exists('success_message', $data)) {
            $updates[] = 'success_message = ?';
            $params[] = Security::sanitizeInput($data['success_message'] ?? '', 'string');
        }
        if (array_key_exists('redirect_url', $data)) {
            $updates[] = 'redirect_url = ?';
            $params[] = Security::sanitizeRedirectUrl((string) ($data['redirect_url'] ?? ''), '') ?: null;
        }
        if ($this->hasSettingsColumn() && array_key_exists('settings', $data)) {
            $updates[] = 'settings = ?';
            $params[] = is_array($data['settings']) ? json_encode($data['settings']) : ($data['settings'] ?? '{}');
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $this->workspaceId();
        $params[] = $id;
        Database::execute("UPDATE forms SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?", $params);
        return true;
    }

    public function updateByUuid(string $uuid, array $data): bool
    {
        $form = $this->getByUuid($uuid);
        if (!$form) {
            throw new \Exception('Form not found');
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $name = Security::sanitizeInput($data['name'], 'string');
            if ($name === '') {
                throw new \InvalidArgumentException('Form name is required');
            }
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if (isset($data['fields'])) {
            $updates[] = 'fields = ?';
            $params[] = is_array($data['fields']) ? json_encode($data['fields']) : $data['fields'];
        }
        if (array_key_exists('success_message', $data)) {
            $updates[] = 'success_message = ?';
            $params[] = Security::sanitizeInput($data['success_message'] ?? '', 'string');
        }
        if (array_key_exists('redirect_url', $data)) {
            $updates[] = 'redirect_url = ?';
            $params[] = Security::sanitizeRedirectUrl((string) ($data['redirect_url'] ?? ''), '') ?: null;
        }
        if ($this->hasSettingsColumn() && array_key_exists('settings', $data)) {
            $updates[] = 'settings = ?';
            $params[] = is_array($data['settings']) ? json_encode($data['settings']) : ($data['settings'] ?? '{}');
        }

        if (empty($updates)) {
            return true;
        }

        $params[] = $this->workspaceId();
        $params[] = $uuid;
        Database::execute("UPDATE forms SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND uuid = ?", $params);
        return true;
    }

    public function delete(int $id): bool
    {
        $form = $this->getById($id);
        if (!$form) {
            return false;
        }

        $formUuid = (string) ($form['uuid'] ?? '');
        Database::beginTransaction();
        try {
            if ($formUuid !== '') {
                Database::execute(
                    "DELETE FROM form_submissions
                     WHERE workspace_id = ?
                       AND (form_id = ?
                        OR form_definition_id = ?)",
                    [$this->workspaceId(), $formUuid, $id]
                );
            } else {
                Database::execute(
                    "DELETE FROM form_submissions
                     WHERE workspace_id = ?
                       AND form_definition_id = ?",
                    [$this->workspaceId(), $id]
                );
            }

            Database::execute("DELETE FROM forms WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return true;
    }

    public function getSubmissionCount(int $id): int
    {
        $form = $this->getById($id);
        if (!$form) {
            return 0;
        }

        $params = [$this->workspaceId(), (string) ($form['uuid'] ?? '')];
        $where = "workspace_id = ? AND (form_id = ?";
        if ((int) ($form['id'] ?? 0) > 0) {
            $where .= " OR form_definition_id = ?";
            $params[] = (int) $form['id'];
        }
        $where .= ")";

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM form_submissions
             WHERE {$where}",
            $params
        )['c'] ?? 0);
    }

    private function hasSettingsColumn(): bool
    {
        if (self::$hasSettingsColumn !== null) {
            return self::$hasSettingsColumn;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'forms'
                   AND COLUMN_NAME = 'settings'"
            );
            self::$hasSettingsColumn = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$hasSettingsColumn = false;
        }

        return self::$hasSettingsColumn;
    }

    public function getSubmissions(int $formId, int $limit = 100): array
    {
        $form = $this->getById($formId);
        if (!$form) {
            return [];
        }
        $params = [$this->workspaceId(), $form['uuid']];
        $where = "fs.workspace_id = ? AND (fs.form_id = ?";
        if ((int) ($form['id'] ?? 0) > 0) {
            $where .= " OR fs.form_definition_id = ?";
            $params[] = (int) $form['id'];
        }
        $where .= ")";
        $params[] = $limit;

        return Database::query(
            "SELECT fs.*, c.first_name, c.last_name, c.email
             FROM form_submissions fs
             LEFT JOIN contacts c ON fs.contact_id = c.id AND c.workspace_id = fs.workspace_id
             WHERE {$where}
             ORDER BY fs.submitted_at DESC
             LIMIT ?",
            $params
        );
    }

    public function getSubmissionsByUuid(string $uuid, int $limit = 100): array
    {
        $form = $this->getByUuid($uuid);
        if (!$form) {
            return [];
        }

        $params = [$this->workspaceId(), $form['uuid']];
        $where = "fs.workspace_id = ? AND (fs.form_id = ?";
        if ((int) ($form['id'] ?? 0) > 0) {
            $where .= " OR fs.form_definition_id = ?";
            $params[] = (int) $form['id'];
        }
        $where .= ")";
        $params[] = $limit;

        return Database::query(
            "SELECT fs.*, c.first_name, c.last_name, c.email
             FROM form_submissions fs
             LEFT JOIN contacts c ON fs.contact_id = c.id AND c.workspace_id = fs.workspace_id
             WHERE {$where}
             ORDER BY fs.submitted_at DESC
             LIMIT ?",
            $params
        );
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        // Create table if missing.
        Database::execute(
            "CREATE TABLE IF NOT EXISTS forms (
                id INT NOT NULL,
                workspace_id INT NOT NULL DEFAULT 1,
                uuid VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                fields JSON NOT NULL,
                success_message TEXT NULL,
                redirect_url VARCHAR(500) NULL,
                settings JSON NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Add settings column if missing.
        if (!$this->hasSettingsColumn()) {
            try {
                Database::execute("ALTER TABLE forms ADD COLUMN settings JSON NULL AFTER redirect_url");
                self::$hasSettingsColumn = true;
            } catch (\Throwable $e) {
                // Ignore if already exists.
            }
        }

        $workspaceColumn = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forms'
               AND COLUMN_NAME = 'workspace_id'"
        );
        if ((int) ($workspaceColumn['c'] ?? 0) === 0) {
            try {
                Database::execute("ALTER TABLE forms ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id");
            } catch (\Throwable $e) {
                // Ignore if already exists.
            }
        }

        // Normalize invalid IDs (0/NULL) using uuid as stable row key.
        $maxIdRow = Database::queryOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM forms");
        $nextId = (int) ($maxIdRow['max_id'] ?? 0);

        $missingIdRows = Database::query("SELECT uuid FROM forms WHERE id IS NULL OR id = 0 ORDER BY created_at ASC");
        foreach ($missingIdRows as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $nextId++;
            Database::execute("UPDATE forms SET id = ? WHERE uuid = ?", [$nextId, $uuid]);
        }

        // Resolve duplicate IDs by reassigning all but first row.
        $duplicateIds = Database::query("SELECT id, COUNT(*) AS c FROM forms GROUP BY id HAVING COUNT(*) > 1");
        foreach ($duplicateIds as $dup) {
            $dupId = (int) ($dup['id'] ?? 0);
            $rows = Database::query("SELECT uuid FROM forms WHERE id = ? ORDER BY created_at ASC", [$dupId]);
            $isFirst = true;
            foreach ($rows as $row) {
                if ($isFirst) {
                    $isFirst = false;
                    continue;
                }
                $uuid = (string) ($row['uuid'] ?? '');
                if ($uuid === '') {
                    continue;
                }
                $nextId++;
                Database::execute("UPDATE forms SET id = ? WHERE uuid = ?", [$nextId, $uuid]);
            }
        }

        // Ensure primary key and auto increment on id.
        $pkCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'forms'
               AND CONSTRAINT_TYPE = 'PRIMARY KEY'"
        )['c'] ?? 0);

        if ($pkCount === 0) {
            try {
                Database::execute("ALTER TABLE forms ADD PRIMARY KEY (id)");
            } catch (\Throwable $e) {
                // Ignore if cannot add due to edge cases.
            }
        }

        try {
            Database::execute("ALTER TABLE forms MODIFY id INT NOT NULL AUTO_INCREMENT");
        } catch (\Throwable $e) {
            // Ignore if already set.
        }

        // Ensure unique uuid index.
        try {
            Database::execute("ALTER TABLE forms ADD UNIQUE INDEX uq_forms_uuid (uuid)");
        } catch (\Throwable $e) {
            // Ignore if already exists.
        }

        self::$schemaEnsured = true;
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    private function workspaceClause(string $alias = '', string $column = 'workspace_id'): array
    {
        return $this->workspaceScope->workspaceClause($alias, $column);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
