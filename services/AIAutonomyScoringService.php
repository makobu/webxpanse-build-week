<?php

namespace CRM\Services;

class AIAutonomyScoringService
{
    public function __construct(
        private ?AIActionSimilarityService $similarityService = null,
        private ?AITenantPolicyResolverService $resolver = null
    ) {
        $this->similarityService = $this->similarityService ?? new AIActionSimilarityService();
        $this->resolver = $this->resolver ?? new AITenantPolicyResolverService();
    }

    public function score(string $tenantKey, string $domainKey, string $actionKey, array $context = []): array
    {
        $examples = $this->similarityService->findSimilarExamples($tenantKey, $domainKey, $actionKey, $context, 3);
        $resolved = $this->resolver->resolve($tenantKey, $domainKey, array_merge($context, ['action_key' => $actionKey]));
        $base = (float) ($context['heuristic_confidence'] ?? $context['assistant_confidence'] ?? 0.78);

        $topSimilarity = (float) ($examples[0]['score'] ?? 0.0);
        $actionObservation = (array) ($resolved['action_observation'] ?? []);
        $evidence = max(1, (int) ($actionObservation['evidence_count'] ?? 0));
        $tenantSuccessRate = $actionObservation !== []
            ? round((int) ($actionObservation['success_count'] ?? 0) / $evidence, 4)
            : 0.0;
        $reversalRisk = (float) ($resolved['reversal_risk'] ?? 0.0);

        $contextCompleteness = 0.7;
        foreach (['recipient', 'document_type'] as $key) {
            if (!empty($context[$key])) {
                $contextCompleteness += 0.08;
            }
        }
        if (!empty($context['deal']['stage'])) {
            $contextCompleteness += 0.07;
        }
        if (!empty($context['task']['status']) || !empty($context['task_status'])) {
            $contextCompleteness += 0.06;
        }
        if (array_key_exists('task_evidence_strength', $context)) {
            $contextCompleteness += min(0.08, (float) $context['task_evidence_strength'] * 0.08);
        }
        if (array_key_exists('thread_completeness', $context)) {
            $contextCompleteness += min(0.1, (float) $context['thread_completeness'] * 0.1);
        }
        if (array_key_exists('stage_evidence_quality', $context)) {
            $contextCompleteness += min(0.08, (float) $context['stage_evidence_quality'] * 0.08);
        }
        if (!empty($context['workflow_trigger_type'])) {
            $contextCompleteness += 0.05;
        }
        if (!empty($context['contact_id'])) {
            $contextCompleteness += 0.04;
        }
        if (array_key_exists('workflow_customer_facing', $context)) {
            $contextCompleteness += 0.03;
        }
        $contextCompleteness = min(1.0, $contextCompleteness);

        $riskPenalty = match ($actionKey) {
            'mark_paid' => 0.05,
            'finalize_invoice', 'convert_to_invoice' => 0.035,
            'send_document', 'resend_document' => 0.025,
            'send_customer_reply' => 0.03,
            'send_email', 'send_whatsapp', 'send_sms' => 0.03,
            'update_contact_field', 'update_deal_stage', 'change_stage' => 0.028,
            'create_task' => 0.022,
            'mark_complete' => 0.028,
            'progress_stage', 'reopen_deal' => 0.03,
            default => 0.015,
        };

        $learningWeight = 0.0;
        if ($topSimilarity > 0) {
            $learningWeight += 0.15;
        }
        if ($actionObservation !== []) {
            $learningWeight += min(0.25, 0.05 * $evidence);
        }
        $learningWeight = min(0.40, $learningWeight);

        $learnedComposite = $topSimilarity > 0 || $actionObservation !== []
            ? (($topSimilarity * 0.55) + ($tenantSuccessRate * 0.45))
            : $base;

        $contextAdjustment = ($contextCompleteness - 0.75) * 0.08;

        $score = ($base * (1 - $learningWeight))
            + ($learnedComposite * $learningWeight)
            + $contextAdjustment
            - ($reversalRisk * 0.12)
            - $riskPenalty;

        $score = max(0.0, min(0.995, round($score, 4)));

        return [
            'assistant_confidence' => $score,
            'similar_examples' => $examples,
            'tenant_policy' => $resolved,
            'confidence_basis' => [
                'heuristic_confidence' => round($base, 4),
                'top_similarity_score' => round($topSimilarity, 4),
                'tenant_success_rate' => round($tenantSuccessRate, 4),
                'context_completeness' => round($contextCompleteness, 4),
                'learning_weight' => round($learningWeight, 4),
                'reversal_risk' => round($reversalRisk, 4),
                'risk_penalty' => round($riskPenalty, 4),
            ],
        ];
    }
}
