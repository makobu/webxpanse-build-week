<?php

namespace CRM\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class BasePathResolutionTest extends TestCase
{
    private array $originalServer;
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    public function testConfiguredBasePathIsTheDeploymentContract(): void
    {
        $_ENV['BASE_PATH'] = '/custom-install/public/';
        $_ENV['APP_URL'] = 'http://localhost/ignored/public';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SCRIPT_NAME'] = '/different/public/dashboard.php';
        $_SERVER['REQUEST_URI'] = '/different/public/dashboard.php';

        $this->assertSame('/custom-install/public', getBasePath());
        $this->assertSame('/custom-install/public/dashboard.php', publicUrl('dashboard.php'));
        $this->assertSame('/custom-install/api/health.php', apiUrl('health.php'));
    }

    public function testDocumentedRepositorySubdirectoryIsDerivedOnLocalhost(): void
    {
        unset($_ENV['BASE_PATH']);
        $_ENV['APP_URL'] = '';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SCRIPT_NAME'] = '/webxpanse-build-week/public/presentation_magic_login.php';
        $_SERVER['REQUEST_URI'] = '/webxpanse-build-week/public/presentation_magic_login.php?token=redacted';

        $this->assertSame('/webxpanse-build-week/public', getBasePath());
        $this->assertSame(
            '/webxpanse-build-week/public/dashboard.php?presentation=1',
            publicUrl('dashboard.php?presentation=1')
        );
        $this->assertSame(
            '/webxpanse-build-week/api/dashboard/founder_command_center.php',
            apiUrl('dashboard/founder_command_center.php')
        );
    }

    public function testAppUrlProvidesTheFallbackForAPublicDocumentRoot(): void
    {
        unset($_ENV['BASE_PATH']);
        $_ENV['APP_URL'] = 'http://localhost:8080/reviewer/public';
        $_SERVER['HTTP_HOST'] = 'localhost:8080';
        $_SERVER['SCRIPT_NAME'] = '/dashboard.php';
        $_SERVER['REQUEST_URI'] = '/dashboard.php';

        $this->assertSame('/reviewer/public', getBasePath());
    }
}
