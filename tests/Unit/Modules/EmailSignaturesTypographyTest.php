<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Modules\EmailSignatures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EmailSignaturesTypographyTest extends TestCase
{
    public function testRenderedSignatureUsesOnlyCuratedEmailTypography(): void
    {
        /** @var EmailSignatures $module */
        $module = (new ReflectionClass(EmailSignatures::class))->newInstanceWithoutConstructor();
        $html = $module->getSignatureHtml([
            'id' => 12,
            'content_html' => '<p>Alex Morgan</p>',
            'settings' => [
                'font_family' => 'georgia',
                'font_size_preset' => 'comfortable',
            ],
        ]);

        $this->assertStringContainsString('font-family: Georgia, &quot;Times New Roman&quot;, serif', $html);
        $this->assertStringContainsString('font-size: 16px', $html);
        $this->assertStringContainsString('line-height: 1.5', $html);

        $fallback = $module->getSignatureHtml([
            'content_html' => '<p>Safe fallback</p>',
            'settings' => ['font_family' => 'url(javascript:alert(1))', 'font_size_preset' => '99px'],
        ]);
        $this->assertStringContainsString('font-family: Arial, Helvetica, sans-serif', $fallback);
        $this->assertStringContainsString('font-size: 14px', $fallback);
        $this->assertStringNotContainsString('javascript', $fallback);
    }
}
