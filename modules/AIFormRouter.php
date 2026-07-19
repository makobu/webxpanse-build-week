<?php
/**
 * AI Form Router
 *
 * Analyzes form submissions for tags, suggested stage, and follow-up actions.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;

class AIFormRouter
{
    private AIService $aiService;

    public function __construct()
    {
        $this->aiService = new AIService();
    }

    /**
     * Analyze a form submission.
     *
     * @return array { tags: string[], suggested_stage: string|null, follow_up_suggestion: string }
     */
    public function analyzeSubmission(array $formData, ?int $formId = null): array
    {
        $text = is_array($formData) ? json_encode($formData, JSON_PRETTY_PRINT) : (string) $formData;
        $text = mb_substr($text, 0, 4000);

        $raw = $this->aiService->process('form_submission_analysis', [
            'form_data' => $formData,
            'text' => $text,
        ]);

        return $this->parseResponse($raw);
    }

    private function parseResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'tags' => $decoded['tags'] ?? [],
                'suggested_stage' => $decoded['suggested_stage'] ?? null,
                'follow_up_suggestion' => $decoded['follow_up_suggestion'] ?? '',
            ];
        }
        return ['tags' => [], 'suggested_stage' => null, 'follow_up_suggestion' => ''];
    }

    /**
     * Save AI analysis to a form submission.
     */
    public function saveAnalysis(int $submissionId, array $analysis): bool
    {
        $tagsJson = !empty($analysis['tags']) ? json_encode($analysis['tags']) : null;
        $followUp = $analysis['follow_up_suggestion'] ?? null;
        $stage = $analysis['suggested_stage'] ?? null;

        Database::execute(
            "UPDATE form_submissions SET ai_tags = ?, ai_follow_up = ?, ai_suggested_stage = ? WHERE id = ?",
            [$tagsJson, $followUp, $stage, $submissionId]
        );
        return true;
    }
}
