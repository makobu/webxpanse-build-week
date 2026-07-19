<?php

namespace CRM\Services;

class EmailTemplatePresentationService
{
    public const SHELL_MARKER = 'data-crm-generated-email-shell="v1"';

    public function shouldWrap(array $template): bool
    {
        return $this->isGeneratedWorkspaceTemplate($template)
            || $this->isDefaultWorkspaceOpsTemplate($template);
    }

    public function wrapGeneratedTemplate(array $template, string $subject, string $bodyHtml, string $bodyText): string
    {
        if (!$this->shouldWrap($template)) {
            return $bodyHtml;
        }

        if (str_contains($bodyHtml, self::SHELL_MARKER)) {
            return $bodyHtml;
        }

        $content = $this->normalizeBodyHtml($bodyHtml, $bodyText);
        $preheader = $this->buildPreheader($bodyText !== '' ? $bodyText : strip_tags($content));
        $title = htmlspecialchars($subject !== '' ? $subject : 'Email', ENT_QUOTES, 'UTF-8');
        $platformOps = $this->isDefaultWorkspaceOpsTemplate($template);
        $category = $this->categoryLabel((string) ($template['category'] ?? ''));
        $brandLine = $platformOps ? 'webXpanse · Clarity' : 'A note from your team';
        $footer = $platformOps
            ? 'Clear next steps, delivered by webXpanse.'
            : 'Thoughtful communication from your workspace.';

        return '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $title . '</title>'
            . '<style>'
            . ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
            . 'body{margin:0;padding:0;background:#e9f0f5;font-family:Arial,Helvetica,sans-serif;color:#0f172a;-webkit-text-size-adjust:100%;text-size-adjust:100%;}'
            . '.crm-generated-email-body p{margin:0 0 16px 0;color:#0f172a;font-size:15px;line-height:1.7;}'
            . '.crm-generated-email-body p:last-child{margin-bottom:0;}'
            . '.crm-generated-email-body a{color:#145c7d;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px;font-weight:700;}'
            . '.crm-generated-email-body strong{color:#0f172a;font-weight:700;}'
            . '.crm-generated-email-body ul,.crm-generated-email-body ol{margin:0 0 16px 22px;padding:0;color:#0f172a;font-size:15px;line-height:1.7;}'
            . '.crm-generated-email-body li{margin:0 0 8px 0;}'
            . '@media (prefers-color-scheme: dark){'
            . 'body,.crm-email-outer{background:#0b1220!important;}'
            . '.crm-email-card,.crm-email-content{background:#162235!important;border-color:#2a3b50!important;}'
            . '.crm-email-header{background:#071b2d!important;}'
            . '.crm-generated-email-body,.crm-generated-email-body p,.crm-generated-email-body li,.crm-generated-email-body strong{color:#e5edf5!important;}'
            . '.crm-generated-email-body a{color:#8bd3f7!important;}'
            . '.crm-email-footer{color:#9fb0c3!important;border-color:#2a3b50!important;}'
            . '}'
            . '@media only screen and (max-width:640px){.crm-email-pad{padding:20px 14px!important;}.crm-email-header,.crm-email-content{padding-left:24px!important;padding-right:24px!important;}.crm-email-title{font-size:25px!important;line-height:1.2!important;}}'
            . '</style>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#e9f0f5;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="crm-email-outer" style="width:100%;background:#e9f0f5;border-collapse:collapse;">'
            . '<tr><td align="center" class="crm-email-pad" style="padding:38px 14px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" ' . self::SHELL_MARKER . ' class="crm-email-card" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d6e1e9;border-radius:20px;border-collapse:separate;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.10);">'
            . '<tr><td class="crm-email-header" style="padding:30px 38px 28px 38px;background:#0b2942;background-image:linear-gradient(135deg,#0b2942 0%,#145c7d 72%,#168b91 100%);">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td style="vertical-align:middle;"><span style="display:inline-block;width:11px;height:11px;border-radius:999px;background:#35d0ba;box-shadow:0 0 0 6px rgba(53,208,186,.16);font-size:0;line-height:0;">&nbsp;</span></td>'
            . '<td align="right" style="color:#b9dce8;font-size:11px;line-height:1.2;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr></table>'
            . '<p style="margin:22px 0 8px 0;color:#9dd8df;font-size:12px;line-height:1.3;font-weight:700;letter-spacing:1px;text-transform:uppercase;">' . htmlspecialchars($brandLine, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<h1 class="crm-email-title" style="margin:0;color:#ffffff;font-size:30px;line-height:1.18;font-weight:750;letter-spacing:-.5px;">' . $title . '</h1>'
            . '</td></tr>'
            . '<tr><td class="crm-email-content crm-generated-email-body" style="padding:36px 38px 34px 38px;background:#ffffff;color:#0f172a;font-size:15px;line-height:1.7;">'
            . $content
            . '</td></tr>'
            . '<tr><td class="crm-email-footer" style="padding:18px 38px 22px 38px;border-top:1px solid #e7edf2;color:#64748b;font-size:12px;line-height:1.5;">' . htmlspecialchars($footer, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body>'
            . '</html>';
    }

    private function normalizeBodyHtml(string $bodyHtml, string $bodyText): string
    {
        $html = trim($bodyHtml);
        if ($html === '') {
            return $this->plainTextToHtml($bodyText);
        }

        if ($html === strip_tags($html)) {
            return $this->plainTextToHtml($html);
        }

        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $matches) === 1) {
            $html = (string) $matches[1];
        } else {
            $html = preg_replace('/<!doctype[^>]*>/i', '', $html) ?? $html;
            $html = preg_replace('/<html\b[^>]*>|<\/html>/i', '', $html) ?? $html;
            $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;
            $html = preg_replace('/<body\b[^>]*>|<\/body>/i', '', $html) ?? $html;
        }

        $html = $this->stripUnsafeGeneratedMarkup($html);
        $html = trim($html);

        if ($html === '') {
            return $this->plainTextToHtml($bodyText);
        }

        if (!preg_match('/<(p|div|table|ul|ol|blockquote|h[1-6]|br)\b/i', $html)) {
            $html = '<p>' . $html . '</p>';
        }

        return $this->inlineBodyStyles($html);
    }

    private function stripUnsafeGeneratedMarkup(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|object|embed|meta|link|title)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|iframe|object|embed|meta|link)\b[^>]*\/?>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;
        $html = preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/is', '', $html) ?? $html;

        return $html;
    }

    private function inlineBodyStyles(string $html): string
    {
        $html = preg_replace_callback('/<p\b([^>]*)>/i', function (array $matches): string {
            return $this->tagWithStyle('p', (string) $matches[1], 'margin:0 0 16px 0;color:#0f172a;font-size:15px;line-height:1.7;');
        }, $html) ?? $html;

        $linkIndex = 0;
        $html = preg_replace_callback('/<a\b([^>]*)>/i', function (array $matches) use (&$linkIndex): string {
            $linkIndex++;
            $style = $linkIndex === 1
                ? 'display:inline-block;margin:4px 0 6px 0;padding:12px 20px;background:#145c7d;color:#ffffff!important;text-decoration:none;border-radius:999px;font-size:14px;line-height:1.2;font-weight:700;box-shadow:0 8px 18px rgba(20,92,125,.18);'
                : 'color:#145c7d;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px;font-weight:700;';
            return $this->tagWithStyle('a', (string) $matches[1], $style);
        }, $html) ?? $html;

        $html = preg_replace_callback('/<(ul|ol)\b([^>]*)>/i', function (array $matches): string {
            return $this->tagWithStyle((string) $matches[1], (string) $matches[2], 'margin:0 0 16px 22px;padding:0;color:#0f172a;font-size:15px;line-height:1.7;');
        }, $html) ?? $html;

        $html = preg_replace_callback('/<li\b([^>]*)>/i', function (array $matches): string {
            return $this->tagWithStyle('li', (string) $matches[1], 'margin:0 0 8px 0;');
        }, $html) ?? $html;

        return $html;
    }

    private function tagWithStyle(string $tag, string $attributes, string $style): string
    {
        if (preg_match('/\sstyle\s*=/i', $attributes) === 1) {
            return '<' . $tag . $attributes . '>';
        }

        return '<' . $tag . $attributes . ' style="' . $style . '">';
    }

    private function plainTextToHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p style="margin:0;color:#0f172a;font-size:15px;line-height:1.7;">&nbsp;</p>';
        }

        $paragraphs = preg_split('/\R{2,}/', $text) ?: [$text];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p style="margin:0 0 16px 0;color:#0f172a;font-size:15px;line-height:1.7;">'
                . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8'))
                . '</p>';
        }

        return $html !== '' ? $html : '<p style="margin:0;color:#0f172a;font-size:15px;line-height:1.7;">&nbsp;</p>';
    }

    private function buildPreheader(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')) ?? '');
        if ($text === '') {
            return 'A CRM email template generated from your workspace context.';
        }

        if (function_exists('mb_strlen') && mb_strlen($text) > 140) {
            return rtrim(mb_substr($text, 0, 137)) . '...';
        }

        return strlen($text) > 140 ? rtrim(substr($text, 0, 137)) . '...' : $text;
    }

    private function isGeneratedWorkspaceTemplate(array $template): bool
    {
        return (int) ($template['is_ai_generated'] ?? 0) === 1;
    }

    private function isDefaultWorkspaceOpsTemplate(array $template): bool
    {
        if ((int) ($template['is_active'] ?? 0) !== 1 || (int) ($template['workspace_id'] ?? 0) !== 1) {
            return false;
        }

        $slug = (string) ($template['slug'] ?? '');
        if (str_starts_with($slug, 'platform-ops-')) {
            return true;
        }

        $tags = $template['tags'] ?? [];
        if (is_string($tags)) {
            return str_contains($tags, 'platform_ops_owner_helpline');
        }

        return is_array($tags) && in_array('platform_ops_owner_helpline', $tags, true);
    }

    private function categoryLabel(string $category): string
    {
        $category = strtolower(trim($category));
        return match ($category) {
            'platform_ops' => 'Workspace update',
            'workspace_starter', 'onboarding starter' => 'Customer note',
            'sales' => 'Growth conversation',
            'support' => 'Support update',
            'marketing' => 'News and insights',
            default => 'Personal message',
        };
    }
}
