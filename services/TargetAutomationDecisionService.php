<?php

namespace CRM\Services;

class TargetAutomationDecisionService
{
    private const SENSITIVE_TERMS = [
        'legal', 'compliance', 'pricing', 'payment', 'security', 'access', 'delete',
        'contract', 'approval', 'publish', 'send externally', 'revenue commitment',
    ];

    private AIService $ai;

    public function __construct(?AIService $ai = null)
    {
        $this->ai = $ai ?? new AIService();
    }

    public function decide(array $target, array $measurement, array $settings, bool $requiresAiJudgment = false): array
    {
        $fingerprints = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => trim((string) ($item['fingerprint'] ?? '')),
            (array) ($measurement['evidence'] ?? [])
        ))));
        $missing = array_values(array_filter(array_map('strval', (array) ($measurement['missing_configuration'] ?? []))));
        $conflicts = array_values(array_filter(array_map('strval', (array) ($measurement['conflicts'] ?? []))));
        $risk = $this->risk((string) ($target['title'] ?? '') . ' ' . (string) ($target['description'] ?? ''));
        $thresholdMet = (float) ($target['target_value'] ?? 0) > 0
            && (float) ($target['current_value'] ?? $measurement['rollup_value'] ?? 0) >= (float) ($target['target_value'] ?? 0);

        if (!$thresholdMet || $fingerprints === [] || $missing !== [] || $conflicts !== []) {
            return $this->result('keep_open', 1.0, $risk, 'The target does not have conflict-free evidence at its threshold.', $fingerprints, $missing, $conflicts, 'deterministic');
        }
        if ($risk !== 'low' && !empty($settings['low_risk_only'])) {
            return $this->result('review', 1.0, $risk, 'This target is sensitive and requires human confirmation.', $fingerprints, [], ['sensitive_target'], 'deterministic');
        }
        if (!$requiresAiJudgment) {
            return $this->result('complete', 1.0, 'low', 'The configured deterministic measurement reached its target with current CRM evidence.', $fingerprints, [], [], 'deterministic');
        }

        $minimum = (float) ($settings['confidence_threshold'] ?? WorkspaceTargetAutomationSettingsService::DEFAULT_CONFIDENCE);
        try {
            $raw = $this->ai->process('ai_target_automation_judge', [
                'target' => [
                    'title' => (string) ($target['title'] ?? ''),
                    'description' => (string) ($target['description'] ?? ''),
                    'value' => (float) ($target['target_value'] ?? 0),
                    'current_value' => (float) ($target['current_value'] ?? 0),
                    'measurement' => $measurement,
                ],
                'rules' => [
                    'allowed_decisions' => ['complete', 'review', 'keep_open'],
                    'minimum_confidence' => $minimum,
                    'must_cite_only_supplied_fingerprints' => $fingerprints,
                    'low_risk_only' => !empty($settings['low_risk_only']),
                ],
            ], [
                'surface' => 'target_automation',
                'workspace_id' => (int) ($target['workspace_id'] ?? 0),
                'user_id' => (int) ($target['user_id'] ?? 0),
            ]);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $decision = (string) ($decoded['decision'] ?? '');
                $confidence = max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0)));
                $aiRisk = (string) ($decoded['risk'] ?? '');
                $cited = array_values(array_unique(array_filter(array_map('strval', (array) ($decoded['evidence_fingerprints'] ?? [])))));
                if (in_array($decision, ['complete', 'review', 'keep_open'], true)
                    && in_array($aiRisk, ['low', 'medium', 'high'], true)
                    && $cited !== [] && array_diff($cited, $fingerprints) === []) {
                    if ($decision === 'complete' && ($confidence < $minimum || ($aiRisk !== 'low' && !empty($settings['low_risk_only'])))) {
                        $decision = 'review';
                    }
                    return $this->result($decision, $confidence, $aiRisk,
                        trim((string) ($decoded['explanation'] ?? '')) ?: 'Clarity evaluated the structured target evidence.',
                        $cited, (array) ($decoded['missing_evidence'] ?? []), (array) ($decoded['conflicts'] ?? []), 'ai');
                }
            }
            return $this->result('review', 0.0, $risk, 'The AI judge returned an invalid response.', $fingerprints, ['valid_ai_judgment'], [], 'invalid_ai_response');
        } catch (\Throwable $e) {
            error_log('TargetAutomationDecisionService provider unavailable: ' . $e->getMessage());
            return $this->result('review', 0.0, $risk, 'The AI judge is unavailable; the target remains open for review.', $fingerprints, ['valid_ai_judgment'], [], 'provider_unavailable');
        }
    }

    private function risk(string $text): string
    {
        $text = strtolower($text);
        foreach (self::SENSITIVE_TERMS as $term) {
            if (str_contains($text, $term)) {
                return 'high';
            }
        }
        return 'low';
    }

    private function result(string $decision, float $confidence, string $risk, string $explanation, array $fingerprints, array $missing, array $conflicts, string $source): array
    {
        return [
            'decision' => $decision,
            'confidence' => round(max(0, min(1, $confidence)), 4),
            'risk' => $risk,
            'explanation' => $explanation,
            'evidence_fingerprints' => array_values(array_unique(array_filter(array_map('strval', $fingerprints)))),
            'missing_evidence' => array_values(array_filter(array_map('strval', $missing))),
            'conflicts' => array_values(array_filter(array_map('strval', $conflicts))),
            'judge_source' => $source,
        ];
    }
}
