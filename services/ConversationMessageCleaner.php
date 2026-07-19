<?php

namespace CRM\Services;

class ConversationMessageCleaner
{
    public static function cleanBody(string $body, string $channel): string
    {
        $value = self::decodeHtmlEntitiesDeep($body);
        if (strtolower(trim($channel)) === 'email') {
            $value = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $value);
            $value = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $value);
            $value = preg_replace('/<\s*p[^>]*>/i', '', $value);
            $value = strip_tags($value);
            $value = self::extractVisibleEmailContent($value);
        } else {
            $value = trim(strip_tags($value));
        }

        return trim($value);
    }

    private static function decodeHtmlEntitiesDeep(string $text, int $passes = 3): string
    {
        $value = $text;
        for ($i = 0; $i < $passes; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }

    private static function extractVisibleEmailContent(string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $patterns = [
            '/\n---\s*Original Message\s*---[\s\S]*$/i',
            '/\nOn .{0,200} wrote:\s*[\s\S]*$/i',
            '/\nFrom:\s.*\nDate:\s.*\nSubject:\s.*[\s\S]*$/i',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        $quoteMarkerPattern = '/On\s+.{0,260}?wrote:\s*/iu';
        if (preg_match($quoteMarkerPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $cutAt = (int) $matches[0][1];
            if ($cutAt > 0) {
                $text = substr($text, 0, $cutAt);
            }
        }

        $inlineTailMarkers = [
            '/From:\s+.+<[^>]+>\s*$/iu',
            '/Sent:\s+.+$/iu',
        ];
        foreach ($inlineTailMarkers as $marker) {
            if (preg_match($marker, $text, $matches, PREG_OFFSET_CAPTURE)) {
                $cutAt = (int) $matches[0][1];
                if ($cutAt > 0) {
                    $text = substr($text, 0, $cutAt);
                }
            }
        }

        $cleanLines = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*>/', $line)) {
                continue;
            }
            $cleanLines[] = $line;
        }

        $text = implode("\n", $cleanLines);
        $text = preg_replace('/\n(?:best regards|regards|thanks(?: and regards)?|kind regards|sincerely)[\s\S]*$/i', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? trim($text);

        return trim($text);
    }
}
