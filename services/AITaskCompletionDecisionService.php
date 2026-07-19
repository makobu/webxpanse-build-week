<?php

namespace CRM\Services;

class AITaskCompletionDecisionService
{
    private const HIGH_RISK_TERMS = [
        'legal', 'compliance', 'approve pricing', 'pricing approval', 'payment approval',
        'mark paid', 'delete', 'remove access', 'grant access', 'security', 'send externally',
        'publish', 'final approval', 'contract approval',
    ];

    private AIService $ai;

    public function __construct(?AIService $ai = null)
    {
        $this->ai = $ai ?? new AIService();
    }

    /**
     * @return array{decision:string,confidence:float,risk:string,explanation:string,evidence_fingerprints:array<int,string>,missing_evidence:array<int,string>,conflicts:array<int,string>,judge_source:string}
     */
    public function decide(array $task, array $evidence, array $settings = []): array
    {
        $fingerprint = trim((string) ($evidence['evidence_fingerprint'] ?? ''));
        $confidence = max(0.0, min(1.0, (float) ($evidence['confidence_score'] ?? 0.0)));
        $text = strtolower(trim((string) ($task['title'] ?? '') . ' ' . (string) ($task['description'] ?? '')));
        $risk = $this->riskLevel($text);
        $minimum = max(0.0, min(1.0, (float) ($settings['min_confidence'] ?? WorkspaceTaskAutomationSettingsService::DEFAULT_MIN_CONFIDENCE)));

        if ($fingerprint === '' || $confidence <= 0.0) {
            return $this->result('keep_open', $confidence, $risk, 'No verifiable completion evidence was found.', [], ['completion_evidence'], [], 'deterministic');
        }
        if ($risk !== 'low' && !empty($settings['low_risk_only'])) {
            return $this->result('review', $confidence, $risk, 'This task is sensitive and requires human confirmation.', [$fingerprint], [], ['sensitive_task'], 'deterministic');
        }

        $payload = [
            'task' => [
                'title' => (string) ($task['title'] ?? ''),
                'description' => (string) ($task['description'] ?? ''),
                'completion_mode' => (string) ($task['completion_mode'] ?? 'manual'),
                'origin_type' => (string) ($task['origin_type'] ?? 'manual'),
            ],
            'evidence' => [
                'type' => (string) ($evidence['evidence_type'] ?? ''),
                'entity_type' => (string) ($evidence['entity_type'] ?? ''),
                'confidence' => $confidence,
                'fingerprint' => $fingerprint,
                'facts' => (array) ($evidence['evidence_json'] ?? []),
            ],
            'rules' => [
                'minimum_confidence' => $minimum,
                'allowed_decisions' => ['complete', 'review', 'keep_open'],
                'must_cite_evidence_fingerprint' => true,
                'low_risk_only' => !empty($settings['low_risk_only']),
            ],
        ];

        $fallbackSource = 'invalid_ai_response';
        try {
            $raw = $this->ai->process('ai_task_completion_match', $payload, [
                'surface' => 'task_automation',
                'workspace_id' => (int) ($task['workspace_id'] ?? 0),
                'user_id' => (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0)),
            ]);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $decision = (string) ($decoded['decision'] ?? '');
                $aiFingerprint = trim((string) (($decoded['evidence_fingerprints'][0] ?? '')));
                $aiConfidence = max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0.0)));
                $aiRisk = in_array((string) ($decoded['risk'] ?? ''), ['low', 'medium', 'high'], true) ? (string) $decoded['risk'] : $risk;
                if (
                    in_array($decision, ['complete', 'review', 'keep_open'], true)
                    && $aiFingerprint === $fingerprint
                    && $aiConfidence > 0.0
                ) {
                    if ($aiRisk !== 'low' && !empty($settings['low_risk_only'])) {
                        $decision = 'review';
                    }
                    if ($decision === 'complete' && $aiConfidence < $minimum) {
                        $decision = 'review';
                    }
                    return $this->result(
                        $decision,
                        min($confidence, $aiConfidence),
                        $aiRisk,
                        trim((string) ($decoded['explanation'] ?? '')) ?: 'Clarity compared the task with the recorded evidence.',
                        [$fingerprint],
                        array_values(array_filter(array_map('strval', (array) ($decoded['missing_evidence'] ?? [])))),
                        array_values(array_filter(array_map('strval', (array) ($decoded['conflicts'] ?? [])))),
                        'ai'
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('AITaskCompletionDecisionService AI judge fallback: ' . $e->getMessage());
            $fallbackSource = 'provider_unavailable';
        }

        return $this->result(
            'review',
            $confidence,
            $risk,
            $fallbackSource === 'provider_unavailable'
                ? 'Structured evidence exists, but the AI completion judge is unavailable.'
                : 'Structured evidence exists, but the AI completion judge did not return a valid decision.',
            [$fingerprint],
            ['valid_ai_judgment'],
            [],
            $fallbackSource
        );
    }

    private function riskLevel(string $text): string
    {
        foreach (self::HIGH_RISK_TERMS as $term) {
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
            'confidence' => round($confidence, 4),
            'risk' => $risk,
            'explanation' => $explanation,
            'evidence_fingerprints' => array_values(array_unique(array_filter(array_map('strval', $fingerprints)))),
            'missing_evidence' => array_values($missing),
            'conflicts' => array_values($conflicts),
            'judge_source' => $source,
        ];
    }
}
