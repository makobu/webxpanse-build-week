<?php
/**
 * Deal Automation AI Stage Suggestion Service
 *
 * Suggests deal stage changes based on evidence payload.
 * Returns strict JSON: suggested_stage, confidence, reasoning, evidence_flags[].
 * Constrains output to existing deal stages. Fallback when AI unavailable.
 */

namespace CRM\Services;

use CRM\Modules\DealAutomationConfig;

class DealAutomationAIStageSuggestion
{
    private AIService $aiService;
    private DealAutomationConfig $config;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->config = new DealAutomationConfig();
    }

    /**
     * Get AI suggestion for stage change based on evidence.
     *
     * @param array $evidence Evidence payload from DealAutomationEvidenceBuilder
     * @return array { suggested_stage, confidence, reasoning, evidence_flags[], fallback: bool }
     */
    public function suggest(array $evidence): array
    {
        $allowedStages = DealAutomationConfig::getAllowedStages();
        $currentStage = $evidence['current_stage'] ?? 'prospecting';

        try {
            $raw = $this->aiService->process('deal_stage_suggestion', [
                'evidence' => $evidence,
                'text' => json_encode($evidence, JSON_PRETTY_PRINT),
                'allowed_stages' => $allowedStages,
                'current_stage' => $currentStage,
            ]);
            return $this->parseResponse($raw, $allowedStages, $currentStage, false);
        } catch (\Throwable $e) {
            error_log("DealAutomationAIStageSuggestion: AI unavailable - " . $e->getMessage());
            return $this->rulesOnlyFallback($evidence, $allowedStages, $currentStage);
        }
    }

    private function parseResponse(string $raw, array $allowedStages, string $currentStage, bool $fallback): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->rulesOnlyFallback([], $allowedStages, $currentStage);
        }

        $suggested = $decoded['suggested_stage'] ?? null;
        if (!$suggested || !in_array($suggested, $allowedStages)) {
            return [
                'suggested_stage' => null,
                'confidence' => 0,
                'reasoning' => 'AI returned invalid or unsupported stage.',
                'evidence_flags' => [],
                'fallback' => $fallback,
            ];
        }

        $confidence = (float) ($decoded['confidence'] ?? 0);
        $confidence = max(0, min(1, $confidence));

        return [
            'suggested_stage' => $suggested,
            'confidence' => $confidence,
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
            'evidence_flags' => is_array($decoded['evidence_flags'] ?? null) ? $decoded['evidence_flags'] : [],
            'fallback' => $fallback,
        ];
    }

    /**
     * Rules-only fallback when AI is unavailable.
     */
    private function rulesOnlyFallback(array $evidence, array $allowedStages, string $currentStage): array
    {
        $suggested = null;
        $confidence = 0;
        $reasoning = 'AI unavailable; using rules-only heuristic.';
        $flags = [];

        if (empty($evidence)) {
            return [
                'suggested_stage' => null,
                'confidence' => 0,
                'reasoning' => $reasoning,
                'evidence_flags' => [],
                'fallback' => true,
            ];
        }

        $proposalSent = !empty($evidence['proposal_sent']);
        $leadScore = (int) ($evidence['lead_score'] ?? 0);
        $intentCounts = $evidence['intent_counts'] ?? [];
        $sentiment = $evidence['sentiment_summary'] ?? [];
        $bidirectional = !empty($evidence['bidirectional_exchange']);
        $lastActivity = $evidence['last_activity_at'] ?? null;
        $inactivityDays = $lastActivity ? max(0, (int) ((time() - strtotime($lastActivity)) / 86400)) : 999;

        // Heuristic: qualification -> proposal if proposal_sent
        if ($currentStage === 'qualification' && $proposalSent) {
            $suggested = 'proposal';
            $confidence = 0.75;
            $flags[] = 'proposal_sent';
        }
        // Heuristic: prospecting -> qualification if purchase/inquiry intent + warm lead
        elseif ($currentStage === 'prospecting') {
            $purchaseCount = ($intentCounts['purchase'] ?? 0) + ($intentCounts['inquiry'] ?? 0);
            if ($purchaseCount >= 1 && $leadScore >= 30) {
                $suggested = 'qualification';
                $confidence = 0.7;
                $flags[] = 'intent_evidence';
                $flags[] = 'lead_score_ok';
            }
        }
        // Heuristic: proposal -> negotiation if bidirectional + purchase intent
        elseif ($currentStage === 'proposal' && $bidirectional) {
            $purchaseCount = $intentCounts['purchase'] ?? 0;
            if ($purchaseCount >= 1) {
                $suggested = 'negotiation';
                $confidence = 0.72;
                $flags[] = 'bidirectional_exchange';
                $flags[] = 'purchase_intent';
            }
        }

        return [
            'suggested_stage' => $suggested,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'evidence_flags' => $flags,
            'fallback' => true,
        ];
    }
}
