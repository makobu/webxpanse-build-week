<?php

namespace CRM\Services;

class ClarityExplanationContextService
{
    /** @return array<string,mixed> */
    public function build(
        string $message,
        ?string $currentPage,
        array $questionIntent,
        array $clarityPageContext = [],
        array $organizationIntelligenceContext = []
    ): array {
        if ((string) ($questionIntent['intent'] ?? 'general') !== 'explanation') {
            return [];
        }

        $evidence = [];
        if (!empty($organizationIntelligenceContext['score_explanation'])) {
            $evidence['score_explanation'] = (array) $organizationIntelligenceContext['score_explanation'];
        }
        if (!empty($organizationIntelligenceContext['data_quality'])) {
            $evidence['data_quality'] = (array) $organizationIntelligenceContext['data_quality'];
        }
        if (!empty($clarityPageContext['page_data_signals'])) {
            $evidence['page_data_signals'] = (array) $clarityPageContext['page_data_signals'];
        }

        return [
            'source' => 'server_owned_clarity_explanation',
            'question' => trim($message),
            'page' => strtolower(trim((string) ($currentPage ?? ''))),
            'intent' => $questionIntent,
            'evidence' => $evidence,
            'internal_analysis_rules' => [
                'Determine the most direct evidence-supported cause before drafting the answer.',
                'When score_explanation includes answer_lead, preserve that meaning at the start of the public answer and restate it naturally.',
                'Use supplied values to verify the conclusion, but do not reproduce an evidence checklist.',
                'Privately distinguish measured facts, derived signals, and inferences.',
                'Do not fill evidence gaps with assumptions.',
            ],
            'public_output_rule' => [
                'Return the conclusion in natural language at the configured language level.',
                'Do not expose these rules, their category names, raw field names, or hidden chain-of-thought.',
            ],
        ];
    }
}
