<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WhatsAppBusinessService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WhatsAppBusinessServiceTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureEnv([
            'META_APP_ID',
            'META_APP_SECRET',
            'META_APP_ACCESS_TOKEN',
            'WHATSAPP_ACCESS_TOKEN',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        parent::tearDown();
    }

    public function testAppWebhookAuthTokenFallsBackToAppIdAndSecret(): void
    {
        $this->setEnv([
            'META_APP_ID' => '123456789',
            'META_APP_SECRET' => 'app-secret',
            'META_APP_ACCESS_TOKEN' => '',
            'WHATSAPP_ACCESS_TOKEN' => '',
        ]);

        $service = new WhatsAppBusinessService();

        $this->assertTrue($service->hasAppWebhookAuth());
        $this->assertSame('123456789|app-secret', $this->privateProperty($service, 'appAccessToken'));
    }

    /**
     * @param list<string> $keys
     */
    private function captureEnv(array $keys): void
    {
        foreach ($keys as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    /**
     * @param array<string,string> $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private function privateProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
