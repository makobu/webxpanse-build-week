<?php

namespace CRM\Services;

class EmailDraftOutputNormalizer
{
    public function normalizeEmailDraftPayload($rawDraft, array $defaults = []): array
    {
        $defaults = array_merge([
            'subject' => '',
            'body_html' => '',
            'body_text' => '',
        ], $defaults);

        $payload = is_array($rawDraft) ? $rawDraft : $this->extractStructuredPayload((string) $rawDraft);

        $subject = $this->cleanupScalar($payload['subject'] ?? $defaults['subject']);
        $bodyHtml = $this->extractBodyHtml($payload);
        $bodyText = $this->extractBodyText($payload);

        if ($bodyHtml === '' && $bodyText === '' && !is_array($rawDraft)) {
            $bodyText = $this->salvagePlainText((string) $rawDraft);
        }

        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = $this->htmlToText($bodyHtml);
        }
        if ($bodyHtml === '' && $bodyText !== '') {
            $bodyHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));
        }

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
        ];
    }

    public function normalizeCanonicalDraft(array $draft): array
    {
        $normalized = $this->normalizeEmailDraftPayload([
            'subject' => $draft['subject'] ?? '',
            'body_html' => $draft['body_html'] ?? $draft['html_body'] ?? '',
            'body_text' => $draft['body_text'] ?? $draft['plain_body'] ?? $draft['reply_text'] ?? $draft['message'] ?? $draft['body'] ?? '',
        ]);

        $draft['subject'] = $normalized['subject'];
        $draft['body_html'] = $normalized['body_html'];
        $draft['body_text'] = $normalized['body_text'];
        $draft['html_body'] = $normalized['body_html'];
        $draft['plain_body'] = $normalized['body_text'];

        return $draft;
    }

    private function extractStructuredPayload(string $rawDraft): array
    {
        $clean = $this->stripCodeFences($rawDraft);
        if ($clean === '') {
            return [];
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $clean, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function extractBodyHtml(array $payload): string
    {
        $candidate = $this->cleanupScalar($payload['body_html'] ?? '');
        if ($candidate === '') {
            return '';
        }
        if ($this->looksLikePromptContractEcho($candidate)) {
            return '';
        }

        $candidate = $this->stripCodeFences($candidate);
        if ($this->looksLikeRawJsonPayload($candidate)) {
            return '';
        }

        $sanitized = $this->sanitizeHtmlFragment($candidate);
        if ($sanitized === '' || $this->looksLikePromptContractEcho($sanitized) || $this->looksLikeRawJsonPayload($sanitized)) {
            return '';
        }

        return $sanitized;
    }

    private function extractBodyText(array $payload): string
    {
        $candidates = [
            $payload['body_text'] ?? null,
            $payload['plain_body'] ?? null,
            $payload['reply_text'] ?? null,
            $payload['message'] ?? null,
            $payload['body'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $clean = $this->cleanupScalar($candidate);
            if ($clean === '') {
                continue;
            }
            if ($this->looksLikePromptContractEcho($clean) || $this->looksLikeRawJsonPayload($clean)) {
                continue;
            }
            if ($this->containsHtmlMarkup($clean)) {
                $clean = $this->htmlToText($this->sanitizeHtmlFragment($clean));
            }
            if ($clean === '' || $this->looksLikePromptContractEcho($clean) || $this->looksLikeRawJsonPayload($clean)) {
                continue;
            }
            return $clean;
        }

        return '';
    }

    private function salvagePlainText(string $raw): string
    {
        $clean = $this->stripCodeFences($raw);
        if ($clean === '') {
            return '';
        }
        if ($this->looksLikePromptContractEcho($clean) || $this->looksLikeRawJsonPayload($clean)) {
            return '';
        }

        if ($this->containsHtmlMarkup($clean)) {
            $clean = $this->htmlToText($this->sanitizeHtmlFragment($clean));
        } else {
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $clean = strip_tags($clean);
        $clean = preg_replace("/[ \t]+/", ' ', $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;
        return trim($clean);
    }

    private function stripCodeFences(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^\s*```[a-z0-9_-]*\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*```\s*$/', '', $value) ?? $value;
        return trim($value);
    }

    private function cleanupScalar($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $clean = trim((string) $value);
        if ($clean === '') {
            return '';
        }

        return $this->stripCodeFences($clean);
    }

    private function looksLikeRawJsonPayload(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        if (!preg_match('/^\{[\s\S]*\}$/', $trimmed)) {
            return false;
        }

        return preg_match('/"(subject|body_html|body_text|plain_body|reply_text|message)"\s*:/i', $trimmed) === 1;
    }

    private function looksLikePromptContractEcho(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'html formatted email body')
            || str_contains($normalized, 'plain text version')
            || str_contains($normalized, 'return json')
            || str_contains($normalized, 'output contract')
            || str_contains($normalized, 'context bundle')
            || str_contains($normalized, 'draft contract')
            || str_contains($normalized, 'structured payload')
            || str_contains($normalized, '"body_html": "html')
            || str_contains($normalized, '"body_text": "plain text');
    }

    private function sanitizeHtmlFragment(string $html): string
    {
        $clean = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/<!DOCTYPE[^>]*>/i', '', $clean) ?? $clean;
        $clean = preg_replace('/<!--[\s\S]*?-->/', '', $clean) ?? $clean;
        $clean = preg_replace('/<(script|style|iframe|svg|object|embed|noscript)\b[^>]*>[\s\S]*?<\/\1>/i', '', $clean) ?? $clean;
        $clean = preg_replace('/<link\b[^>]*>/i', '', $clean) ?? $clean;
        $clean = preg_replace('/<base\b[^>]*>/i', '', $clean) ?? $clean;

        if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', $clean, $matches)) {
            $clean = $matches[1];
        }

        $clean = preg_replace('/<\/?(?:html|head|body|meta|title)[^>]*>/i', '', $clean) ?? $clean;
        return trim($clean);
    }

    private function htmlToText(string $html): string
    {
        $normalized = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $normalized = preg_replace('/<\s*\/p\s*>/i', "\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\s*p\b[^>]*>/i', '', $normalized) ?? $normalized;
        $text = trim(strip_tags($normalized));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function containsHtmlMarkup(string $value): bool
    {
        return preg_match('/<\/?[a-z][\s\S]*>/i', $value) === 1;
    }
}
