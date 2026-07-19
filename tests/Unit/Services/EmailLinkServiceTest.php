<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailLinkService;
use CRM\Tests\TestCase;

class EmailLinkServiceTest extends TestCase
{
    /** @var array<string,array{env:mixed,server:mixed}> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENV', 'APP_URL', 'EMAIL_PUBLIC_BASE_URL'] as $key) {
            $this->original[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => getenv($key),
            ];
        }
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['APP_URL'], $_ENV['EMAIL_PUBLIC_BASE_URL']);
        putenv('APP_URL');
        putenv('EMAIL_PUBLIC_BASE_URL');
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $values) {
            if ($values['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }
            if ($values['server'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $values['server']);
            }
        }
        parent::tearDown();
    }

    public function testProductionFallbackAndLegacyPathsResolveToWebxpanse(): void
    {
        $links = new EmailLinkService();

        $this->assertSame('https://webxpanse.com', $links->publicBaseUrl());
        $this->assertSame(
            'https://webxpanse.com/onboarding.php?signup_success=1',
            $links->absolutePublicUrl('/crm/public/onboarding.php?signup_success=1')
        );
        $this->assertSame(
            'https://webxpanse.com/dashboard.php',
            $links->normalizeHref('http://localhost/crm/public/dashboard.php')
        );
        $this->assertSame(
            'https://webxpanse.com/api/track/email/open/abc',
            $links->absoluteApiUrl('track/email/open/abc')
        );
    }

    public function testHtmlAndPlainTextLinksCannotKeepBrokenLocalDestinations(): void
    {
        $links = new EmailLinkService();
        $html = $links->normalizeHtmlLinks(
            '<p><a href="/crm/public/onboarding.php">Continue</a> '
            . '<a href="">Missing</a> '
            . '<a href="javascript:alert(1)">Unsafe</a> '
            . '<a href="mailto:help@webxpanse.com">Email</a></p>'
        );

        $this->assertStringContainsString('href="https://webxpanse.com/onboarding.php"', $html);
        $this->assertStringContainsString('href="mailto:help@webxpanse.com"', $html);
        $this->assertStringContainsString('Missing', $html);
        $this->assertStringContainsString('Unsafe', $html);
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame(
            'Continue at https://webxpanse.com/onboarding.php.',
            $links->normalizePlainTextLinks('Continue at http://localhost/crm/public/onboarding.php.')
        );
    }
}
