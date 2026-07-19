<?php

namespace CRM\Services;

class ClarityResponsePresentationService
{
    private const INTERNAL_SECTION_PATTERN = '/^\s*(?:#{1,6}\s*)?(?:\*{0,2})?(?:strongest factors?|measured facts?|derived signals?|missing evidence|evidence trace|processing(?: logic)?|reasoning(?: trace)?|internal (?:analysis|context)|source context)(?:\*{0,2})?\s*(?::.*)?$/i';
    private const INTERNAL_TOKEN_PATTERN = '/\b(?:missing_context_flags|clarity_explanation_context|organization_intelligence_context|response_style_contract|reasoning_effort|api_surface|context_bundle|prompt_version|provider_source|new_customer_marketing_allowed|cold_outreach_allowed)\b|\b[a-z][a-z0-9]+(?:_[a-z0-9]+){2,}\s*=\s*(?:true|false|null|\d+)/i';

    /** @return array{answer:string,guard_applied:bool,removed_section_count:int,language_level:string} */
    public function present(string $answer, array $styleContract = [], array $questionIntent = []): array
    {
        $normalized = (new AITextResponseNormalizerService())->normalize($answer);
        $level = (new WorkspaceLanguageLevelService())->normalize($styleContract['level'] ?? null);
        $guardApplied = false;
        $removed = 0;
        $kept = [];

        foreach (preg_split('/\R/', $normalized) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($kept !== [] && end($kept) !== '') {
                    $kept[] = '';
                }
                continue;
            }
            if (preg_match(self::INTERNAL_SECTION_PATTERN, $trimmed) === 1) {
                $guardApplied = true;
                $removed++;
                break;
            }
            if (preg_match(self::INTERNAL_TOKEN_PATTERN, $trimmed) === 1) {
                $guardApplied = true;
                $removed++;
                continue;
            }
            $kept[] = $line;
        }

        $presented = trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $kept)) ?? '');
        if ((string) ($questionIntent['intent'] ?? 'general') === 'explanation') {
            $withoutFormatting = preg_replace('/^\s*(?:#{1,6}\s+|[-*]\s+)/m', '', $presented) ?? $presented;
            $withoutFormatting = trim(preg_replace('/\s*\n+\s*/', ' ', $withoutFormatting) ?? $withoutFormatting);
            $limited = $this->limitSentences($withoutFormatting, $this->sentenceLimit($level));
            if ($limited !== $presented) {
                $guardApplied = true;
            }
            $presented = $limited;
        }

        if ($presented === '') {
            $guardApplied = true;
            $presented = $level === WorkspaceLanguageLevelService::LEVEL_1
                ? 'I can see the result, but the available information does not show a reliable reason yet.'
                : 'I can see the result, but the available evidence does not show a reliable cause yet.';
        }

        return [
            'answer' => $presented,
            'guard_applied' => $guardApplied,
            'removed_section_count' => $removed,
            'language_level' => $level,
        ];
    }

    private function sentenceLimit(string $level): int
    {
        return match ($level) {
            WorkspaceLanguageLevelService::LEVEL_1 => 2,
            WorkspaceLanguageLevelService::LEVEL_3 => 4,
            default => 3,
        };
    }

    private function limitSentences(string $text, int $limit): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', trim($text)) ?: [];
        if (count($sentences) <= $limit) {
            return trim($text);
        }

        return trim(implode(' ', array_slice($sentences, 0, $limit)));
    }
}
