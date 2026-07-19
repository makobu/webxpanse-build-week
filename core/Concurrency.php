<?php
declare(strict_types=1);

namespace CRM;

class Concurrency
{
    /**
     * @param array<string,mixed> $data
     */
    public static function expectedVersionFromData(array $data): ?int
    {
        if (!array_key_exists('expected_lock_version', $data)) {
            return null;
        }

        $value = $data['expected_lock_version'];
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @param list<string> $setClauses
     * @param list<mixed> $params
     * @param callable(): array<string,mixed>|null $currentLoader
     * @param array<string,mixed> $submittedFields
     */
    public static function executeWorkspaceUpdate(
        string $table,
        int $workspaceId,
        int $id,
        array $setClauses,
        array $params,
        ?int $expectedVersion,
        callable $currentLoader,
        array $submittedFields = [],
        ?string $entityType = null
    ): int {
        if (!Database::columnExists($table, 'lock_version')) {
            return Database::execute(
                "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE workspace_id = ? AND id = ?",
                array_merge($params, [$workspaceId, $id])
            );
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . ", lock_version = lock_version + 1 WHERE workspace_id = ? AND id = ?";
        $executeParams = array_merge($params, [$workspaceId, $id]);
        if ($expectedVersion !== null) {
            $sql .= " AND lock_version = ?";
            $executeParams[] = $expectedVersion;
        }

        $affectedRows = Database::execute($sql, $executeParams);
        if ($expectedVersion !== null && $affectedRows === 0) {
            $current = $currentLoader() ?: [];
            self::logConflict($table, $workspaceId, $id, $expectedVersion, isset($current['lock_version']) ? (int) $current['lock_version'] : null);
            throw new ConcurrencyConflictException($entityType ?? $table, $id, $expectedVersion, $current, $submittedFields);
        }

        return $affectedRows;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $exclude
     */
    public static function hiddenInputs(array $data, array $exclude = ['csrf_token']): string
    {
        $html = '';
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $exclude, true)) {
                continue;
            }
            $html .= self::hiddenInput((string) $key, $value);
        }

        return $html;
    }

    /**
     * @param mixed $value
     */
    private static function hiddenInput(string $name, mixed $value): string
    {
        if (is_array($value)) {
            $html = '';
            foreach ($value as $childKey => $childValue) {
                $html .= self::hiddenInput($name . '[' . (string) $childKey . ']', $childValue);
            }
            return $html;
        }

        return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    /**
     * @param array<string,string> $labels
     * @return list<array{label:string,current:string,submitted:string}>
     */
    public static function conflictDiffRows(ConcurrencyConflictException $conflict, array $labels = []): array
    {
        $current = $conflict->getCurrentRecord();
        $submitted = $conflict->getSubmittedFields();
        $rows = [];

        foreach ($submitted as $field => $submittedValue) {
            if (!array_key_exists($field, $current)) {
                continue;
            }
            $currentValue = $current[$field];
            if (self::stringify($currentValue) === self::stringify($submittedValue)) {
                continue;
            }

            $rows[] = [
                'label' => $labels[$field] ?? ucwords(str_replace('_', ' ', (string) $field)),
                'current' => self::stringify($currentValue),
                'submitted' => self::stringify($submittedValue),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public static function conflictPayload(ConcurrencyConflictException $conflict): array
    {
        $current = $conflict->getCurrentRecord();

        return [
            'success' => false,
            'error_code' => 'conflict',
            'message' => $conflict->getMessage(),
            'current' => [
                'lock_version' => isset($current['lock_version']) ? (int) $current['lock_version'] : null,
                'updated_at' => $current['updated_at'] ?? null,
                'fields' => $current,
            ],
            'submitted' => [
                'fields' => $conflict->getSubmittedFields(),
            ],
        ];
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return trim((string) $value);
    }

    private static function logConflict(string $table, int $workspaceId, int $id, int $expectedVersion, ?int $currentVersion): void
    {
        error_log(sprintf(
            'Concurrency conflict table=%s workspace_id=%d id=%d expected_lock_version=%d current_lock_version=%s user_id=%d',
            $table,
            $workspaceId,
            $id,
            $expectedVersion,
            $currentVersion === null ? 'null' : (string) $currentVersion,
            (int) ($_SESSION['user_id'] ?? 0)
        ));
    }
}
