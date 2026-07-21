<?php

namespace CRM\Tests\Unit\Core;

use CRM\MigrationStatementNormalizer;
use PHPUnit\Framework\TestCase;

final class MigrationStatementNormalizerTest extends TestCase
{
    public function testNormalizesMariaDbAlterClausesForMySqlWithoutLosingNewChanges(): void
    {
        $existing = [
            'column:sample:existing_column',
            'index:sample:uniq_existing',
        ];
        $lookup = static fn(string $kind, string $table, string $name): bool => in_array(
            $kind . ':' . $table . ':' . $name,
            $existing,
            true
        );

        $normalized = MigrationStatementNormalizer::normalizeWithLookup(
            "ALTER TABLE sample\n"
                . "ADD COLUMN IF NOT EXISTS existing_column INT NULL,\n"
                . "ADD COLUMN IF NOT EXISTS new_status ENUM('new','won') NULL,\n"
                . "ADD UNIQUE KEY IF NOT EXISTS uniq_existing (existing_column),\n"
                . "ADD INDEX IF NOT EXISTS idx_new_status (new_status),\n"
                . "ADD CONSTRAINT fk_sample_owner FOREIGN KEY IF NOT EXISTS (existing_column) REFERENCES users(id)",
            $lookup
        );

        $this->assertStringNotContainsString('existing_column INT', $normalized);
        $this->assertStringNotContainsString('uniq_existing', $normalized);
        $this->assertStringContainsString("ADD COLUMN new_status ENUM('new','won') NULL", $normalized);
        $this->assertStringContainsString('ADD INDEX idx_new_status (new_status)', $normalized);
        $this->assertStringContainsString('ADD CONSTRAINT fk_sample_owner FOREIGN KEY (existing_column)', $normalized);
        $this->assertStringNotContainsString('IF NOT EXISTS', $normalized);
    }

    public function testReturnsEmptyAlterWhenEveryConditionalChangeAlreadyExists(): void
    {
        $normalized = MigrationStatementNormalizer::normalizeWithLookup(
            'ALTER TABLE sample ADD COLUMN IF NOT EXISTS existing_column INT NULL',
            static fn(): bool => true
        );

        $this->assertSame('', $normalized);
    }

    public function testNormalizesConditionalAlterInsideDynamicSql(): void
    {
        $normalized = MigrationStatementNormalizer::normalizeWithLookup(
            "SET @sql = 'ALTER TABLE sample ADD COLUMN IF NOT EXISTS workspace_id INT, ADD INDEX IF NOT EXISTS idx_workspace (workspace_id)'",
            static fn(): bool => false
        );

        $this->assertStringContainsString('ADD COLUMN workspace_id', $normalized);
        $this->assertStringContainsString('ADD INDEX idx_workspace', $normalized);
        $this->assertStringNotContainsString('IF NOT EXISTS', $normalized);
    }
}
