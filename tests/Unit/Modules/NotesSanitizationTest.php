<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Modules\Notes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NotesSanitizationTest extends TestCase
{
    public function testRichTextSanitizerRejectsExecutableAttributesAndSchemes(): void
    {
        $html = $this->sanitize(
            '<p class="lead" onclick="alert(1)">Hello '
            . '<a href="jav&#x61;script:alert(2)" onmouseover=alert(3)>link</a>'
            . '<span onmouseover=alert(4)>there</span></p>'
        );

        $this->assertSame('<p>Hello <a>link</a><span>there</span></p>', $html);
    }

    public function testRichTextSanitizerKeepsSafeLinksOnly(): void
    {
        $html = $this->sanitize(
            '<a href="https://example.com/path?q=1&amp;x=2" target="_blank">web</a>'
            . '<a href="/contacts/1">local</a>'
            . '<a href="mailto:owner@example.com">mail</a>'
        );

        $this->assertSame(
            '<a href="https://example.com/path?q=1&amp;x=2">web</a>'
            . '<a href="/contacts/1">local</a>'
            . '<a href="mailto:owner@example.com">mail</a>',
            $html
        );
    }

    private function sanitize(string $html): string
    {
        $method = new ReflectionMethod(Notes::class, 'sanitizeHtml');
        return (string) $method->invoke(null, $html);
    }
}
