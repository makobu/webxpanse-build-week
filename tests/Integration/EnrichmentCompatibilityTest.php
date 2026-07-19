<?php
/**
 * Enrichment compatibility regression tests
 */

namespace CRM\Tests\Integration;

use PHPUnit\Framework\TestCase;

class EnrichmentCompatibilityTest extends TestCase
{
    public function testBatchDefaultsIncludeThirdPartySourceAndFlag(): void
    {
        $batchEndpoint = file_get_contents(__DIR__ . '/../../api/enrichment/batch.php');

        $this->assertIsString($batchEndpoint);
        $this->assertStringContainsString("'use_third_party' => true", $batchEndpoint);
        $this->assertStringContainsString("'sources' => ['third_party'", $batchEndpoint);
    }

    public function testEnumMigrationCoversRuntimeLoggedSourceTypes(): void
    {
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/090_expand_enrichment_enums.sql');

        $this->assertIsString($migration);
        $this->assertStringContainsString("'clearbit'", $migration);
        $this->assertStringContainsString("'pdl'", $migration);
        $this->assertStringContainsString("'hunter'", $migration);
    }

    public function testEnumMigrationCoversContextGenerationAndUndoHistoryTypes(): void
    {
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/090_expand_enrichment_enums.sql');

        $this->assertIsString($migration);
        $this->assertStringContainsString("'context_generation'", $migration);
        $this->assertStringContainsString("'undo'", $migration);
    }
}
