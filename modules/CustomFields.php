<?php
/**
 * Custom Fields Management
 * 
 * Handles custom field definitions and data
 */

namespace CRM\Modules;

use CRM\Concurrency;
use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class CustomFields
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function decodeFieldOptions(array $field): array
    {
        $decoded = json_decode((string) ($field['field_options'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getLabel(array $field): string
    {
        $options = $this->decodeFieldOptions($field);
        $label = trim((string) ($options['_label'] ?? ''));
        return $label !== '' ? $label : (string) ($field['field_name'] ?? '');
    }

    public function getSelectableOptions(array $field): array
    {
        $options = $this->decodeFieldOptions($field);
        unset($options['_label']);
        return $this->normalizeSelectableOptions($options);
    }

    /**
     * Create custom field
     */
    public function create(array $data): int
    {
        $fieldName = Security::sanitizeInput((string) ($data['field_name'] ?? ''), 'string');
        $fieldType = (string) ($data['field_type'] ?? '');
        $fieldOptions = isset($data['field_options']) ? json_encode($data['field_options']) : null;
        $module = Security::sanitizeInput((string) ($data['module'] ?? 'contacts'), 'string');
        $isRequired = $data['is_required'] ?? false;
        $displayOrder = (int) ($data['display_order'] ?? 0);

        if ($fieldName === '') {
            throw new \InvalidArgumentException('Custom field name is required');
        }

        if (mb_strlen($fieldName) > 100) {
            throw new \InvalidArgumentException('Custom field name must be 100 characters or fewer');
        }

        if (!in_array($fieldType, $this->allowedFieldTypes(), true)) {
            throw new \Exception("Invalid field type: $fieldType");
        }

        if (!in_array($module, $this->allowedModules(), true)) {
            throw new \Exception("Invalid custom field module: $module");
        }

        if ($displayOrder < 0) {
            throw new \InvalidArgumentException('Display order cannot be negative');
        }
        
        Database::execute(
            "INSERT INTO custom_fields (workspace_id, field_name, field_type, field_options, module, is_required, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$this->workspaceId(), $fieldName, $fieldType, $fieldOptions, $module, $isRequired ? 1 : 0, $displayOrder]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get all custom fields
     */
    public function getAll(): array
    {
        $workspace = $this->workspaceClause();
        return Database::query(
            "SELECT * FROM custom_fields WHERE {$workspace['sql']} ORDER BY module ASC, display_order ASC",
            $workspace['params']
        );
    }
    
    /**
     * Get all custom fields for a module
     */
    public function getByModule(string $module): array
    {
        $workspace = $this->workspaceClause();
        return Database::query(
            "SELECT * FROM custom_fields WHERE {$workspace['sql']} AND module = ? ORDER BY display_order ASC",
            array_merge($workspace['params'], [$module])
        );
    }
    
    /**
     * Get custom field by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause();
        return Database::queryOne(
            "SELECT * FROM custom_fields WHERE {$workspace['sql']} AND id = ?",
            array_merge($workspace['params'], [$id])
        );
    }
    
    /**
     * Update custom field
     */
    public function update(int $id, array $data): bool
    {
        $existingField = $this->getById($id);
        if (!$existingField) {
            throw new \RuntimeException('Custom field not found.');
        }

        $updates = [];
        $params = [];
        $submittedFields = [];
        
        $allowedFields = ['field_name', 'field_type', 'field_options', 'is_required', 'display_order'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'field_options') {
                    $value = json_encode($data[$field]);
                } elseif ($field === 'field_name') {
                    $value = Security::sanitizeInput((string) $data[$field], 'string');
                    if ($value === '') {
                        throw new \InvalidArgumentException('Custom field name is required');
                    }
                    if (mb_strlen($value) > 100) {
                        throw new \InvalidArgumentException('Custom field name must be 100 characters or fewer');
                    }
                } elseif ($field === 'field_type') {
                    $value = (string) $data[$field];
                    if (!in_array($value, $this->allowedFieldTypes(), true)) {
                        throw new \Exception("Invalid field type: $value");
                    }
                } else {
                    $value = $data[$field];
                }

                if ($field === 'display_order' && (!is_numeric($value) || (int) $value < 0)) {
                    throw new \InvalidArgumentException('Display order cannot be negative');
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
                $submittedFields[$field] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $workspaceId = $this->workspaceId();
        Concurrency::executeWorkspaceUpdate(
            'custom_fields',
            $workspaceId,
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->getById($id),
            $submittedFields,
            'custom field'
        );
        
        return true;
    }

    /**
     * @return array<int, string>
     */
    private function allowedFieldTypes(): array
    {
        return ['text', 'number', 'date', 'select', 'checkbox', 'textarea'];
    }

    /**
     * @return array<int, string>
     */
    private function allowedModules(): array
    {
        return ['contacts'];
    }
    
    /**
     * Delete custom field
     */
    public function delete(int $id): bool
    {
        $existingField = $this->getById($id);
        if (!$existingField) {
            throw new \RuntimeException('Custom field not found.');
        }

        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM contact_custom_data WHERE workspace_id = ? AND field_id = ?", [$this->workspaceId(), $id]);
            Database::execute("DELETE FROM custom_fields WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return true;
    }
    
    /**
     * Set custom field value for contact
     */
    public function setContactValue(int $contactId, int $fieldId, $value): bool
    {
        $field = $this->getById($fieldId);
        if (!$field || !$this->contactBelongsToWorkspace($contactId)) {
            return false;
        }

        $valueStr = $this->normalizeContactValue($field, $value);
        
        Database::execute(
            "INSERT INTO contact_custom_data (workspace_id, contact_id, field_id, field_value)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE field_value = ?",
            [$this->workspaceId(), $contactId, $fieldId, $valueStr, $valueStr]
        );
        
        return true;
    }

    /**
     * Validate a submitted contact value against its field definition.
     *
     * @param array<string,mixed> $field
     * @param mixed $value
     */
    private function normalizeContactValue(array $field, $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Custom field value must be a single value');
        }

        $type = (string) ($field['field_type'] ?? 'text');
        $valueStr = trim((string) ($value ?? ''));
        if ((int) ($field['is_required'] ?? 0) === 1 && $valueStr === '') {
            throw new \InvalidArgumentException('A value is required for ' . $this->getLabel($field));
        }

        if ($valueStr === '') {
            return '';
        }

        if ($type === 'number') {
            if (!is_numeric($valueStr) || !is_finite((float) $valueStr)) {
                throw new \InvalidArgumentException('Custom field value must be a valid number');
            }
            return $valueStr;
        }

        if ($type === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valueStr);
            if (!$date || $date->format('Y-m-d') !== $valueStr) {
                throw new \InvalidArgumentException('Custom field value must be a valid date');
            }
            return $valueStr;
        }

        if ($type === 'select') {
            if (!in_array($valueStr, $this->getSelectableOptions($field), true)) {
                throw new \InvalidArgumentException('Custom field value is not an allowed option');
            }
            return $valueStr;
        }

        if ($type === 'checkbox') {
            $normalized = strtolower($valueStr);
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return '1';
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return '0';
            }
            throw new \InvalidArgumentException('Custom field value must be a valid checkbox value');
        }

        return $valueStr;
    }
    
    /**
     * Get custom field value for contact
     */
    public function getContactValue(int $contactId, int $fieldId): ?string
    {
        $result = Database::queryOne(
            "SELECT field_value FROM contact_custom_data WHERE workspace_id = ? AND contact_id = ? AND field_id = ?",
            [$this->workspaceId(), $contactId, $fieldId]
        );
        
        return $result['field_value'] ?? null;
    }
    
    /**
     * Get all custom field values for contact
     */
    public function getContactValues(int $contactId): array
    {
        return Database::query(
            "SELECT cfd.field_id, cfd.field_value, cf.field_name, cf.field_type 
             FROM contact_custom_data cfd
             JOIN custom_fields cf ON cfd.field_id = cf.id AND cf.workspace_id = cfd.workspace_id
             WHERE cfd.workspace_id = ? AND cfd.contact_id = ?
             ORDER BY cf.display_order ASC",
            [$this->workspaceId(), $contactId]
        );
    }

    public function getUsageCount(int $fieldId): int
    {
        if (!$this->getById($fieldId)) {
            return 0;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM contact_custom_data WHERE workspace_id = ? AND field_id = ?",
            [$this->workspaceId(), $fieldId]
        )['count'] ?? 0);
    }

    private function normalizeSelectableOptions(array $options): array
    {
        if (isset($options['options']) && is_array($options['options'])) {
            $options = $options['options'];
        } elseif (isset($options['choices']) && is_array($options['choices'])) {
            $options = $options['choices'];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (!is_scalar($option)) {
                continue;
            }

            $value = trim((string) $option);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values($normalized);
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

    private function contactBelongsToWorkspace(int $contactId): bool
    {
        if ($contactId <= 0) {
            return false;
        }

        return Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$this->workspaceId(), $contactId]
        ) !== null;
    }
}
