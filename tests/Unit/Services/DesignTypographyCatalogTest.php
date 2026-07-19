<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\DesignTypographyCatalog;
use PHPUnit\Framework\TestCase;

final class DesignTypographyCatalogTest extends TestCase
{
    public function testWebCatalogProvidesCuratedFontsAndSizePresets(): void
    {
        $fonts = DesignTypographyCatalog::webFonts();
        $scales = DesignTypographyCatalog::webScales();

        $this->assertCount(8, $fonts);
        $this->assertSame(['system', 'arial', 'georgia', 'verdana', 'trebuchet', 'tahoma', 'times', 'courier'], array_column($fonts, 'value'));
        $this->assertSame(['compact', 'balanced', 'expressive'], array_column($scales, 'value'));
        $this->assertSame('system', DesignTypographyCatalog::normalizeWebFont('Inter, system-ui, sans-serif'));
        $this->assertSame('georgia', DesignTypographyCatalog::normalizeWebFont('Georgia, serif'));
        $this->assertSame('system', DesignTypographyCatalog::normalizeWebFont('url(javascript:alert(1))'));
        $this->assertSame('balanced', DesignTypographyCatalog::normalizeWebScale('oversized'));
    }

    public function testEmailCatalogIsRestrictedToEmailSafeFontsAndPresetSizes(): void
    {
        $this->assertSame(['arial', 'verdana', 'tahoma', 'georgia', 'times'], array_column(DesignTypographyCatalog::emailFonts(), 'value'));
        $this->assertSame(['compact', 'standard', 'comfortable'], array_column(DesignTypographyCatalog::emailSizes(), 'value'));
        $this->assertSame('arial', DesignTypographyCatalog::normalizeEmailFont('Comic Sans MS'));
        $this->assertSame(16, DesignTypographyCatalog::emailSize('comfortable')['pixels']);
    }
}
