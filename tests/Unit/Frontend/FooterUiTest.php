<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class FooterUiTest extends TestCase
{
    public function testSharedFooterUsesOptimizedAppFooterMarkupAndStyles(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/main.css');

        $this->assertNotFalse($layout);
        $this->assertNotFalse($css);

        $this->assertStringContainsString('main.css?v=visual-system-20260525-footer', (string) $layout);
        $this->assertStringContainsString('<footer class="footer app-footer" aria-label="Application footer">', (string) $layout);
        $this->assertStringContainsString('class="container app-footer__inner"', (string) $layout);
        $this->assertStringContainsString('class="app-footer__brand"', (string) $layout);
        $this->assertStringContainsString('class="app-footer__links" aria-label="Legal and privacy links"', (string) $layout);
        $this->assertStringContainsString('class="app-footer__link-button"', (string) $layout);

        $this->assertStringContainsString('.app-footer__inner', (string) $css);
        $this->assertStringContainsString('.app-footer__links a,', (string) $css);
        $this->assertStringContainsString('@media (max-width: 720px)', (string) $css);
    }
}
