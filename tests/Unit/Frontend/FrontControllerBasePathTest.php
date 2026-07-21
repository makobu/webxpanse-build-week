<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class FrontControllerBasePathTest extends TestCase
{
    public function testFrontControllerUsesDynamicRoutingWithoutTemporaryDebugWrites(): void
    {
        $frontController = (string) file_get_contents(__DIR__ . '/../../../public/index.php');

        $this->assertStringContainsString('$routeBasePath = trim(normalizePathPrefix(getBasePath()),', $frontController);
        $this->assertStringContainsString("header('Location: ' . publicUrl('dashboard.php'))", $frontController);
        $this->assertStringContainsString("header('Location: ' . publicUrl('login.php'))", $frontController);
        $this->assertStringNotContainsString("strpos(\$path, 'crm/public/')", $frontController);
        $this->assertStringNotContainsString("../.cursor/debug.log", $frontController);
        $this->assertStringNotContainsString("'hypothesisId' =>", $frontController);
    }
}
