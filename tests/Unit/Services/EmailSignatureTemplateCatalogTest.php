<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailSignatureTemplateCatalog;
use PHPUnit\Framework\TestCase;

class EmailSignatureTemplateCatalogTest extends TestCase
{
    public function testCatalogProvidesThreeSafeQuickStartTemplates(): void
    {
        $templates = EmailSignatureTemplateCatalog::all([
            'first_name' => '<Alex>',
            'last_name' => 'Morgan',
            'email' => 'alex@example.com',
            'company' => 'Clarity & Co',
        ]);

        $this->assertSame(['professional', 'sales', 'minimal'], array_keys($templates));
        $this->assertStringContainsString('&lt;Alex&gt; Morgan', $templates['professional']['content_html']);
        $this->assertStringContainsString('Clarity &amp; Co', $templates['professional']['content_html']);
        $this->assertStringNotContainsString('<Alex>', $templates['professional']['content_html']);
    }

    public function testUnknownTemplateFallsBackAndEmptyEditorMarkupIsRejected(): void
    {
        $this->assertSame('professional', EmailSignatureTemplateCatalog::normalizeKey('unknown'));
        $this->assertFalse(EmailSignatureTemplateCatalog::hasMeaningfulContent('<p><br></p>'));
        $this->assertFalse(EmailSignatureTemplateCatalog::hasMeaningfulContent('<p>&nbsp;</p>'));
        $this->assertTrue(EmailSignatureTemplateCatalog::hasMeaningfulContent('<p>Alex Morgan</p>'));
    }
}
