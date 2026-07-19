<?php

namespace CRM\Services;

class BillingReturnUrlService
{
    public function defaultReturnTo(): string
    {
        return function_exists('publicUrl')
            ? publicUrl('billing_payment_required.php')
            : '/public/billing_payment_required.php';
    }

    public function defaultCallbackUrl(): string
    {
        return function_exists('publicUrl')
            ? publicUrl('billing_callback.php')
            : '/public/billing_callback.php';
    }

    public function sanitizeWebReturnTo(?string $candidate, ?string $fallback = null): string
    {
        $fallback = $fallback !== null && $this->isSafeRelativeUrl($fallback)
            ? $fallback
            : $this->defaultReturnTo();

        $candidate = trim((string) $candidate);
        if (!$this->isSafeRelativeUrl($candidate)) {
            return $fallback;
        }

        return $candidate;
    }

    /**
     * @return array{callback_url:string,return_to:string}
     */
    public function providerCallbackForWebReturn(?string $returnTo = null, ?string $fallback = null): array
    {
        $safeReturnTo = $this->sanitizeWebReturnTo($returnTo, $fallback);

        return [
            'callback_url' => $this->appendQuery($this->defaultCallbackUrl(), ['return_to' => $safeReturnTo]),
            'return_to' => $safeReturnTo,
        ];
    }

    /**
     * @return array{callback_url:string,return_to:string}
     */
    public function providerCallbackForMobileReturn(?string $returnTo = null, ?string $configuredMobileReturnUrl = null): array
    {
        $safeReturnTo = $this->sanitizeMobileReturnUrl($returnTo, $configuredMobileReturnUrl);

        return [
            'callback_url' => $this->appendQuery($this->defaultCallbackUrl(), ['return_to' => $safeReturnTo]),
            'return_to' => $safeReturnTo,
        ];
    }

    /**
     * Raw provider callbacks are deliberately ignored. We only read a relative
     * return_to hint from them so older form flows keep working.
     *
     * @return array{callback_url:string,return_to:string}
     */
    public function providerCallbackFromRequest(?string $callbackUrl = null, ?string $returnTo = null, ?string $fallback = null): array
    {
        $returnTo = trim((string) $returnTo);
        if ($returnTo === '') {
            $returnTo = $this->extractReturnToHint($callbackUrl);
        }

        return $this->providerCallbackForWebReturn($returnTo, $fallback);
    }

    public function sanitizeMobileReturnUrl(?string $candidate, ?string $configuredMobileReturnUrl = null): string
    {
        $candidate = trim((string) $candidate);
        $configuredMobileReturnUrl = trim((string) $configuredMobileReturnUrl);

        if ($candidate === '' && $this->isTrustedMobileUrl($configuredMobileReturnUrl, $configuredMobileReturnUrl)) {
            return $configuredMobileReturnUrl;
        }

        if ($this->isTrustedMobileUrl($candidate, $configuredMobileReturnUrl)) {
            return $candidate;
        }

        return $this->defaultCallbackUrl();
    }

    public function isSafeRelativeUrl(?string $candidate): bool
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || strlen($candidate) > 2048) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
            return false;
        }

        if (str_contains($candidate, '\\') || str_starts_with($candidate, '//')) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate)) {
            return false;
        }

        return true;
    }

    public function isTrustedMobileUrl(?string $candidate, ?string $configuredMobileReturnUrl = null): bool
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || strlen($candidate) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
            return false;
        }

        if ($this->isSafeRelativeUrl($candidate)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        if ($scheme === '') {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return $this->matchesConfiguredHttpOrigin($candidate, (string) $configuredMobileReturnUrl)
                || $this->matchesConfiguredPrefixes($candidate);
        }

        return $this->matchesConfiguredDeepLink($candidate, (string) $configuredMobileReturnUrl)
            || $this->matchesConfiguredPrefixes($candidate)
            || $this->matchesConfiguredScheme($candidate);
    }

    private function extractReturnToHint(?string $callbackUrl): string
    {
        $callbackUrl = trim((string) $callbackUrl);
        if (!$this->isSafeRelativeUrl($callbackUrl)) {
            return '';
        }

        $query = (string) (parse_url($callbackUrl, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        return is_string($params['return_to'] ?? null) ? (string) $params['return_to'] : '';
    }

    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function matchesConfiguredHttpOrigin(string $candidate, string $configuredMobileReturnUrl): bool
    {
        if ($configuredMobileReturnUrl === '') {
            return false;
        }

        $candidateScheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        $candidateHost = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $candidatePort = (string) (parse_url($candidate, PHP_URL_PORT) ?? '');
        $configuredScheme = strtolower((string) parse_url($configuredMobileReturnUrl, PHP_URL_SCHEME));
        $configuredHost = strtolower((string) parse_url($configuredMobileReturnUrl, PHP_URL_HOST));
        $configuredPort = (string) (parse_url($configuredMobileReturnUrl, PHP_URL_PORT) ?? '');

        return $candidateScheme !== ''
            && $candidateScheme === $configuredScheme
            && $candidateHost !== ''
            && $candidateHost === $configuredHost
            && $candidatePort === $configuredPort;
    }

    private function matchesConfiguredDeepLink(string $candidate, string $configuredMobileReturnUrl): bool
    {
        if ($configuredMobileReturnUrl === '') {
            return false;
        }

        $configuredScheme = strtolower((string) parse_url($configuredMobileReturnUrl, PHP_URL_SCHEME));
        if ($configuredScheme === '' || in_array($configuredScheme, ['http', 'https'], true)) {
            return false;
        }

        return strtolower((string) parse_url($candidate, PHP_URL_SCHEME)) === $configuredScheme;
    }

    private function matchesConfiguredScheme(string $candidate): bool
    {
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        $configured = strtolower(trim((string) ($_ENV['MOBILE_APP_SCHEME'] ?? getenv('MOBILE_APP_SCHEME') ?: '')));
        if ($configured !== '') {
            $configured = rtrim($configured, ':');
            return $scheme === $configured;
        }

        return false;
    }

    private function matchesConfiguredPrefixes(string $candidate): bool
    {
        $rawPrefixes = [
            (string) ($_ENV['MOBILE_RETURN_URL_PREFIX'] ?? getenv('MOBILE_RETURN_URL_PREFIX') ?: ''),
            (string) ($_ENV['MOBILE_APP_RETURN_URL_PREFIX'] ?? getenv('MOBILE_APP_RETURN_URL_PREFIX') ?: ''),
            (string) ($_ENV['MOBILE_RETURN_URL_PREFIXES'] ?? getenv('MOBILE_RETURN_URL_PREFIXES') ?: ''),
        ];

        $prefixes = [];
        foreach ($rawPrefixes as $raw) {
            foreach (explode(',', $raw) as $prefix) {
                $prefix = trim($prefix);
                if ($prefix !== '') {
                    $prefixes[] = $prefix;
                }
            }
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($candidate, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
