<?php

namespace CRM\Services;

class ClarityQuestionIntentService
{
    /** @return array<string,mixed> */
    public function classify(string $message, ?string $currentPage = null): array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $message) ?? $message));
        $isExplanation = $this->matchesExplanation($normalized);

        return [
            'intent' => $isExplanation ? 'explanation' : 'general',
            'requires_reasoning' => $isExplanation,
            'reasoning_effort' => $isExplanation ? 'medium' : 'low',
            'references_self' => preg_match('/\b(my|me|mine|myself)\b/', $normalized) === 1,
            'references_score' => preg_match('/\b(score|risk|rating|band|threshold|calculated|calculation|metric|kpi)\b/', $normalized) === 1,
            'current_page' => strtolower(trim((string) ($currentPage ?? ''))),
            'answer_contract' => $isExplanation ? [
                'lead_with_answer' => true,
                'include_top_factors' => true,
                'include_evidence' => true,
                'separate_measured_from_inferred' => true,
                'state_missing_evidence' => true,
                'include_next_move_only_when_supported' => true,
            ] : [],
        ];
    }

    private function matchesExplanation(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        return preg_match('/\b(why|how come|what caused|what causes|what drove|what drives|what changed|explain|break down|breakdown|calculated|calculation|made .* (?:high|low)|reason for)\b/', $message) === 1
            || preg_match('/\b(how|what)\b.*\b(score|risk|rating|band|threshold|metric|kpi)\b/', $message) === 1;
    }
}
