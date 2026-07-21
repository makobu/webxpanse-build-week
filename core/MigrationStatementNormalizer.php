<?php

namespace CRM;

use PDO;

final class MigrationStatementNormalizer
{
    public static function normalize(PDO $pdo, string $statement): string
    {
        return self::normalizeWithLookup(
            $statement,
            static function (string $kind, string $table, string $name) use ($pdo): bool {
                $sources = [
                    'column' => ['information_schema.COLUMNS', 'COLUMN_NAME'],
                    'index' => ['information_schema.STATISTICS', 'INDEX_NAME'],
                    'constraint' => ['information_schema.TABLE_CONSTRAINTS', 'CONSTRAINT_NAME'],
                ];
                if (!isset($sources[$kind])) {
                    return false;
                }

                [$source, $nameColumn] = $sources[$kind];
                $query = $pdo->prepare(
                    "SELECT 1 FROM {$source} "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND {$nameColumn} = ? LIMIT 1"
                );
                $query->execute([$table, $name]);
                return $query->fetchColumn() !== false;
            }
        );
    }

    /**
     * MySQL 8 does not accept MariaDB's ALTER TABLE ... IF [NOT] EXISTS
     * clause syntax. Normalize each top-level ALTER clause after checking the
     * active schema so migrations remain repeatable on both engines.
     *
     * @param callable(string,string,string):bool $exists
     */
    public static function normalizeWithLookup(string $statement, callable $exists): string
    {
        $statement = self::normalizeTenantKeyNumericCasts($statement);

        if (stripos($statement, 'IF NOT EXISTS') === false && stripos($statement, 'IF EXISTS') === false) {
            return $statement;
        }

        if (preg_match(
            '/^\s*ALTER\s+TABLE\s+((?:`[^`]+`|[A-Za-z0-9_]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_]+))?)\s+([\s\S]+)$/i',
            $statement,
            $matches
        ) !== 1) {
            return self::normalizeDynamicAlterSql($statement);
        }

        $tableExpression = trim((string) $matches[1]);
        $tableParts = preg_split('/\s*\.\s*/', str_replace('`', '', $tableExpression)) ?: [];
        $table = (string) end($tableParts);
        $clauses = self::splitTopLevelClauses((string) $matches[2]);
        $normalized = [];

        foreach ($clauses as $clause) {
            if (preg_match('/^\s*ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $clause, $part) === 1) {
                if ($exists('column', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace('/^(\s*ADD\s+COLUMN)\s+IF\s+NOT\s+EXISTS\s+/i', '$1 ', $clause, 1) ?? $clause;
            } elseif (preg_match('/^\s*DROP\s+COLUMN\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $clause, $part) === 1) {
                if (!$exists('column', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace('/^(\s*DROP\s+COLUMN)\s+IF\s+EXISTS\s+/i', '$1 ', $clause, 1) ?? $clause;
            } elseif (preg_match('/^\s*ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $clause, $part) === 1) {
                if ($exists('index', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace(
                    '/^(\s*ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY))\s+IF\s+NOT\s+EXISTS\s+/i',
                    '$1 ',
                    $clause,
                    1
                ) ?? $clause;
            } elseif (preg_match('/^\s*DROP\s+INDEX\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $clause, $part) === 1) {
                if (!$exists('index', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace('/^(\s*DROP\s+INDEX)\s+IF\s+EXISTS\s+/i', '$1 ', $clause, 1) ?? $clause;
            } elseif (preg_match('/^\s*ADD\s+CONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY\s+IF\s+NOT\s+EXISTS\b/i', $clause, $part) === 1) {
                if ($exists('constraint', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace('/\bFOREIGN\s+KEY\s+IF\s+NOT\s+EXISTS\b/i', 'FOREIGN KEY', $clause, 1) ?? $clause;
            } elseif (preg_match('/^\s*ADD\s+CONSTRAINT\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $clause, $part) === 1) {
                if ($exists('constraint', $table, (string) $part[1])) {
                    continue;
                }
                $clause = preg_replace('/^(\s*ADD\s+CONSTRAINT)\s+IF\s+NOT\s+EXISTS\s+/i', '$1 ', $clause, 1) ?? $clause;
            }

            $normalized[] = trim($clause);
        }

        if ($normalized === []) {
            return '';
        }

        return 'ALTER TABLE ' . $tableExpression . "\n    " . implode(",\n    ", $normalized);
    }

    /** @return array<int,string> */
    private static function splitTopLevelClauses(string $sql): array
    {
        $clauses = [];
        $current = '';
        $depth = 0;
        $quote = '';
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== '') {
                $current .= $character;
                if ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote && $quote !== '`') {
                        $current .= $sql[++$index];
                    } elseif ($index === 0 || $sql[$index - 1] !== '\\') {
                        $quote = '';
                    }
                }
                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $current .= $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }
            if ($character === ',' && $depth === 0) {
                $clauses[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $character;
        }

        if (trim($current) !== '') {
            $clauses[] = trim($current);
        }

        return $clauses;
    }

    private static function normalizeDynamicAlterSql(string $statement): string
    {
        if (stripos($statement, 'ALTER TABLE') === false) {
            return $statement;
        }

        return preg_replace(
            [
                '/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i',
                '/\bADD\s+((?:UNIQUE\s+)?(?:INDEX|KEY))\s+IF\s+NOT\s+EXISTS\b/i',
                '/\bDROP\s+INDEX\s+IF\s+EXISTS\b/i',
                '/\bFOREIGN\s+KEY\s+IF\s+NOT\s+EXISTS\b/i',
            ],
            ['ADD COLUMN', 'ADD $1', 'DROP INDEX', 'FOREIGN KEY'],
            $statement
        ) ?? $statement;
    }

    private static function normalizeTenantKeyNumericCasts(string $statement): string
    {
        return preg_replace(
            "/CAST\\(SUBSTRING_INDEX\\(([A-Za-z0-9_]+\\.tenant_key),\\s*':',\\s*-1\\)\\s+AS\\s+UNSIGNED\\)/i",
            "CASE WHEN $1 REGEXP ':[0-9]+$' THEN CAST(SUBSTRING_INDEX($1, ':', -1) AS UNSIGNED) ELSE NULL END",
            $statement
        ) ?? $statement;
    }
}
