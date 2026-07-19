<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailDomainClassifier;
use PHPUnit\Framework\TestCase;

class EmailDomainClassifierTest extends TestCase
{
    private ?string $originalExclusions = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalExclusions = $_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS'] ?? null;
        unset($_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS']);
    }

    protected function tearDown(): void
    {
        if ($this->originalExclusions === null) {
            unset($_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS']);
        } else {
            $_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS'] = $this->originalExclusions;
        }

        parent::tearDown();
    }

    public function testNormalizesAndClassifiesPublicProviderDomains(): void
    {
        $classifier = new EmailDomainClassifier();

        $this->assertSame('gmail.com', $classifier->extractDomain(' Person@GMAIL.COM '));
        $this->assertTrue($classifier->isPublicEmailProviderDomain('Gmail.com'));
        $this->assertNull($classifier->extractBusinessDomainFromEmail('person@gmail.com'));
    }

    public function testHonorsAdditionalExcludedDomainsFromEnv(): void
    {
        $_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS'] = ' custommail.test , team.mail ';
        $classifier = new EmailDomainClassifier();

        $this->assertTrue($classifier->isPublicEmailProviderDomain('custommail.test'));
        $this->assertTrue($classifier->isPublicEmailProviderDomain('team.mail'));
        $this->assertNull($classifier->extractBusinessDomainFromEmail('person@custommail.test'));
    }

    public function testAllowsBusinessDomains(): void
    {
        $classifier = new EmailDomainClassifier();

        $this->assertFalse($classifier->isPublicEmailProviderDomain('acme.com'));
        $this->assertSame('acme.com', $classifier->extractBusinessDomainFromEmail('person@acme.com'));
        $this->assertSame('acme.com', $classifier->normalizeDomain('https://www.acme.com/about'));
    }
}
