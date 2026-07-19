<?php

namespace CRM\Services;

final class EmailSignatureTemplateCatalog
{
    /**
     * @param array<string, mixed> $identity
     * @return array<string, array<string, string>>
     */
    public static function all(array $identity = []): array
    {
        $name = self::escape(self::displayName($identity));
        $email = self::escape(trim((string) ($identity['email'] ?? 'you@example.com')) ?: 'you@example.com');
        $company = self::escape(trim((string) ($identity['company'] ?? 'Your company')) ?: 'Your company');
        $role = self::escape(trim((string) ($identity['role'] ?? 'Your role')) ?: 'Your role');
        $phone = self::escape(trim((string) ($identity['phone'] ?? '+254 700 000 000')) ?: '+254 700 000 000');

        return [
            'professional' => [
                'key' => 'professional',
                'name' => 'Professional',
                'description' => 'Balanced and polished for everyday client email.',
                'accent_color' => '#2f6fed',
                'text_color' => '#111827',
                'content_html' => '<p><strong style="font-size: 18px;">' . $name . '</strong></p>'
                    . '<p>' . $role . ' · ' . $company . '</p>'
                    . '<p>' . $phone . ' · <a href="mailto:' . $email . '">' . $email . '</a></p>',
            ],
            'sales' => [
                'key' => 'sales',
                'name' => 'Sales',
                'description' => 'Friendly and action-oriented for follow-ups and outreach.',
                'accent_color' => '#7c3aed',
                'text_color' => '#172033',
                'content_html' => '<p><strong style="font-size: 18px;">' . $name . '</strong></p>'
                    . '<p>' . $role . ' · ' . $company . '</p>'
                    . '<p>' . $phone . ' · <a href="mailto:' . $email . '">' . $email . '</a></p>'
                    . '<p><em>Let&rsquo;s keep the conversation moving.</em></p>',
            ],
            'minimal' => [
                'key' => 'minimal',
                'name' => 'Minimal',
                'description' => 'Clean, compact, and ideal for reply-heavy inboxes.',
                'accent_color' => '#2563eb',
                'text_color' => '#1f2937',
                'content_html' => '<p><strong>' . $name . '</strong> · ' . $role . '</p>'
                    . '<p>' . $company . ' · ' . $phone . '</p>'
                    . '<p><a href="mailto:' . $email . '">' . $email . '</a></p>',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, string>
     */
    public static function get(string $key, array $identity = []): array
    {
        $templates = self::all($identity);

        return $templates[$key] ?? $templates['professional'];
    }

    public static function normalizeKey(string $key): string
    {
        return in_array($key, ['professional', 'sales', 'minimal'], true) ? $key : 'professional';
    }

    public static function hasMeaningfulContent(string $html): bool
    {
        $plain = str_ireplace(['<br>', '<br/>', '<br />', '&nbsp;'], ['', '', '', ' '], $html);
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($plain) !== '';
    }

    /** @param array<string, mixed> $identity */
    private static function displayName(array $identity): string
    {
        $name = trim((string) ($identity['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) ($identity['first_name'] ?? '') . ' ' . (string) ($identity['last_name'] ?? ''));

        return $name !== '' ? $name : 'Your name';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
