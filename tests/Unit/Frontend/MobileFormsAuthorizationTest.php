<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class MobileFormsAuthorizationTest extends TestCase
{
    public function testMobileFormsEndpointRequiresMarketingReadAndHidesInternalErrors(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../api/mobile/forms.php');

        $this->assertStringContainsString(
            "mobileRequireAnyPermission(\$user, ['marketing.read']",
            $source
        );
        $this->assertStringContainsString("'error' => 'Forms could not be loaded.'", $source);
        $this->assertStringNotContainsString("'error' => \$e->getMessage()", $source);
    }
}
