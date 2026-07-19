<?php
/**
 * Application helper functions
 */

if (!function_exists('uuid_v4')) {
    /**
     * Generate a UUID v4 (36 characters, e.g. 550e8400-e29b-41d4-a716-446655440000).
     * Use for communications.uuid and other CHAR(36) columns.
     */
    function uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('linkifyUrls')) {
    /**
     * Convert URLs in text to clickable links (https and http only).
     * Escapes non-URL text to prevent XSS.
     *
     * @param string $text Plain text that may contain URLs
     * @return string HTML with links, rest escaped
     */
    function linkifyUrls(string $text): string
    {
        $pattern = '#\b(https?://[^\s<>"\'\)\]\[]+)#iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $result = '';
        foreach ($parts as $part) {
            if (preg_match('#^https?://#i', $part)) {
                $url = rtrim($part, '.,;:!?');
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $parsed = parse_url($url);
                    $scheme = strtolower($parsed['scheme'] ?? '');
                    if (in_array($scheme, ['http', 'https'], true)) {
                        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                        $result .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
                        continue;
                    }
                }
            }
            $result .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        }
        return $result;
    }
}
