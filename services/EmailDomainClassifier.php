<?php

namespace CRM\Services;

class EmailDomainClassifier
{
    private const DEFAULT_PUBLIC_PROVIDER_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'live.com',
        'msn.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'zoho.com',
        'gmx.com',
        'yandex.com',
        'mail.com',
    ];

    public function extractDomain(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        return $this->normalizeDomain($domain);
    }

    public function extractBusinessDomainFromEmail(?string $email): ?string
    {
        $domain = $this->extractDomain($email);
        if ($domain === null || $this->isPublicEmailProviderDomain($domain)) {
            return null;
        }

        return $domain;
    }

    public function normalizeDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = preg_replace('#:\d+$#', '', $domain) ?? $domain;
        $domain = ltrim($domain, '@');
        $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;
        $domain = trim($domain, " \t\n\r\0\x0B.");

        return $domain !== '' ? $domain : null;
    }

    public function isPublicEmailProviderDomain(?string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        if ($domain === null) {
            return false;
        }

        return in_array($domain, $this->getExcludedDomains(), true);
    }

    public function getExcludedDomains(): array
    {
        $domains = self::DEFAULT_PUBLIC_PROVIDER_DOMAINS;
        $raw = trim((string) ($_ENV['PUBLIC_EMAIL_PROVIDER_EXCLUSIONS'] ?? ''));
        if ($raw !== '') {
            foreach (explode(',', $raw) as $item) {
                $normalized = $this->normalizeDomain($item);
                if ($normalized !== null) {
                    $domains[] = $normalized;
                }
            }
        }

        $domains = array_values(array_unique(array_filter($domains)));
        sort($domains);

        return $domains;
    }
}
