<?php

namespace CRM\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class MarketingEmailTemplateOptionSourceTest extends TestCase
{
    public function testMarketingOptionQueriesExcludeRetiredLibraryTemplates(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../modules/Marketing.php');

        $this->assertNotFalse($source);
        $source = str_replace(["\r\n", "\r"], "\n", (string) $source);

        $this->assertGreaterThanOrEqual(2, substr_count($source, 'LEFT JOIN smart_template_sets sts ON sts.id = et.smart_template_set_id'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'COALESCE(et.is_library, 0) = 0'));
        $this->assertStringContainsString("OR sts.status = 'active'", $source);
    }
}
