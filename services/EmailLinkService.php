<?php

namespace CRM\Services;

class EmailLinkService
{
    public const DEFAULT_PUBLIC_BASE_URL = 'https://webxpanse.com';

    /** @var array<int,string> */
    private const LEGACY_PUBLIC_HOSTS = [
        'crm.makdennis.dev',
        'www.crm.makdennis.dev',
    ];

    public function publicBaseUrl(): string
    {
        $candidates = [
            $this->env('EMAIL_PUBLIC_BASE_URL'),
            $this->env('APP_URL'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim($candidate), '/');
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                continue;
            }
            if ($this->isProduction() && $this->isNonPublicHost($host)) {
                continue;
            }

            return $candidate;
        }

        return self::DEFAULT_PUBLIC_BASE_URL;
    }

    public function absolutePublicUrl(string $path): string
    {
        return $this->normalizeHref($path) ?? $this->publicBaseUrl();
    }

    public function absoluteApiUrl(string $path): string
    {
        $path = preg_replace('#^/?(?:crm/)?api/#i', '', trim($path)) ?? trim($path);
        return $this->publicBaseUrl() . '/api/' . ltrim($path, '/');
    }

    public function normalizeHtmlLinks(string $html): string
    {
        if (trim($html) === '' || stripos($html, '<a') === false) {
            return $html;
        }

        return preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function (array $matches): string {
                $attributes = (string) ($matches[1] ?? '');
                $content = (string) ($matches[2] ?? '');
                if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/is', $attributes, $hrefMatch) !== 1
                    && preg_match('/\shref\s*=\s*([^\s>]+)/is', $attributes, $hrefMatch) !== 1) {
                    return $content;
                }

                $rawHref = (string) ($hrefMatch[2] ?? $hrefMatch[1] ?? '');
                $href = $this->normalizeHref($rawHref);
                if ($href === null) {
                    return $content;
                }

                $attributes = preg_replace(
                    '/\s+href\s*=\s*(?:(["\']).*?\1|[^\s>]+)/is',
                    '',
                    $attributes,
                    1
                ) ?? $attributes;

                return '<a' . $attributes . ' href="'
                    . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                    . '">' . $content . '</a>';
            },
            $html
        ) ?? $html;
    }

    public function normalizePlainTextLinks(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $text = preg_replace_callback(
            '#https?://[^\s<>"\']+#i',
            function (array $matches): string {
                $url = (string) ($matches[0] ?? '');
                $trailing = '';
                while ($url !== '' && preg_match('/[.,;:!?\)]$/', $url) === 1) {
                    $trailing = substr($url, -1) . $trailing;
                    $url = substr($url, 0, -1);
                }
                return ($this->normalizeHref($url) ?? '') . $trailing;
            },
            $text
        ) ?? $text;

        return preg_replace_callback(
            '#(?<![A-Za-z0-9])/(?:crm/public|public)/[^\s<>"\']+#i',
            fn(array $matches): string => $this->normalizeHref((string) ($matches[0] ?? '')) ?? '',
            $text
        ) ?? $text;
    }

    /**
     * @return array<int,array{href:string,normalized:?string,status:string}>
     */
    public function inspectHtmlLinks(string $html): array
    {
        preg_match_all('/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/is', $html, $matches);
        $findings = [];
        foreach ((array) ($matches[2] ?? []) as $rawHref) {
            $href = html_entity_decode(trim((string) $rawHref), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^\{\{?\s*[A-Za-z_][A-Za-z0-9_.-]*\s*\}?\}$/', $href) === 1) {
                $findings[] = ['href' => $href, 'normalized' => null, 'status' => 'placeholder'];
                continue;
            }
            $normalized = $this->normalizeHref($href);
            $findings[] = [
                'href' => $href,
                'normalized' => $normalized,
                'status' => $normalized === null ? 'broken' : ($normalized === $href ? 'ok' : 'normalized'),
            ];
        }
        return $findings;
    }

    public function normalizeHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href === '#' || str_contains($href, '{') || str_contains($href, '}')) {
            return null;
        }

        if (preg_match('#^(mailto|tel):#i', $href) === 1) {
            return $href;
        }
        if (preg_match('#^(javascript|data|file|vbscript):#i', $href) === 1) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme === '') {
            $path = $this->normalizeApplicationPath($href);
            return $path === '' ? $this->publicBaseUrl() : $this->publicBaseUrl() . '/' . ltrim($path, '/');
        }
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($href, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($this->isNonPublicHost($host) || in_array($host, self::LEGACY_PUBLIC_HOSTS, true)) {
            $path = (string) parse_url($href, PHP_URL_PATH);
            $query = (string) parse_url($href, PHP_URL_QUERY);
            $fragment = (string) parse_url($href, PHP_URL_FRAGMENT);
            $normalized = $this->publicBaseUrl() . '/' . ltrim($this->normalizeApplicationPath($path), '/');
            if ($query !== '') {
                $normalized .= '?' . $query;
            }
            if ($fragment !== '') {
                $normalized .= '#' . $fragment;
            }
            return rtrim($normalized, '/');
        }

        if ($scheme === 'http' && in_array($host, ['webxpanse.com', 'www.webxpanse.com'], true)) {
            return 'https://' . substr($href, strlen('http://'));
        }

        return $href;
    }

    private function normalizeApplicationPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#^(?:\.\./)+#', '', $path) ?? $path;
        $path = preg_replace('#^/?(?:crm/public|public)/#i', '/', $path) ?? $path;
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return ltrim($path, '/');
    }

    private function isProduction(): bool
    {
        return strtolower($this->env('APP_ENV')) === 'production';
    }

    private function isNonPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid');
    }

    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return is_string($value) ? trim($value) : '';
    }
}
