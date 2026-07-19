<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class BulkEmailAutoPreviewTest extends TestCase
{
    public function testBulkEmailAutoPreviewIgnoresDefaultCompanyZero(): void
    {
        $page = file_get_contents(__DIR__ . '/../../../public/bulk_email.php');

        $this->assertNotFalse($page);
        $page = (string) $page;

        $this->assertStringContainsString('const initialFilters = getFilters();', $page);
        $this->assertStringContainsString('(initialFilters.contact_ids && initialFilters.contact_ids.length > 0) || initialFilters.company_id', $page);
        $this->assertStringNotContainsString("document.getElementById('company_id').value.trim()) {\n        btnPreview.click();", $page);
    }
}
