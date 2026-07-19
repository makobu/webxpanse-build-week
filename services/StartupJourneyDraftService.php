<?php

namespace CRM\Services;

class StartupJourneyDraftService
{
    private StartupJourneyService $journeyService;
    private AIService $aiService;

    public function __construct(?StartupJourneyService $journeyService = null, ?AIService $aiService = null)
    {
        $this->journeyService = $journeyService ?? new StartupJourneyService();
        $this->aiService = $aiService ?? new AIService();
    }

    public function draft(int $workspaceId, int $userId, string $stageKey, string $fieldKey, string $currentValue, array $currentResponses = []): array
    {
        $definitions = $this->journeyService->stageDefinitions();
        if (!isset($definitions[$stageKey])) {
            throw new \InvalidArgumentException('Unknown Clarity Journey stage.');
        }
        $stage = (array) $definitions[$stageKey];
        $fields = (array) ($stage['fields'] ?? []);
        if (!isset($fields[$fieldKey])) {
            throw new \InvalidArgumentException('Unknown Clarity Journey field.');
        }

        $context = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'stage' => [
                'key' => $stageKey,
                'label' => (string) ($stage['label'] ?? $stageKey),
                'prompt' => (string) ($stage['prompt'] ?? ''),
            ],
            'field' => [
                'key' => $fieldKey,
                'label' => (string) ($fields[$fieldKey] ?? $fieldKey),
                'current_value' => $currentValue,
            ],
            'current_responses' => $this->cleanContext($currentResponses),
        ];

        $draft = '';
        $draftSource = 'ai';
        try {
            $prompt = $this->buildPrompt($context);
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('startup_journey', 'field_draft', [
                'surface' => 'startup_journey',
                'prompt_key' => 'field_draft',
                'blocks' => [
                    ['key' => 'stage', 'content' => $context['stage']],
                    ['key' => 'field', 'content' => $context['field']],
                    ['key' => 'responses', 'content' => $context['current_responses']],
                ],
            ], [
                'legacy_prompt' => $prompt,
                'stage_key' => $stageKey,
                'field_key' => $fieldKey,
            ]);
            $draft = $this->cleanDraft($this->aiService->processWithPrompt('startup_journey_field_draft', $resolvedPrompt));
        } catch (\Throwable $e) {
            $draft = '';
        }

        if ($draft === '') {
            $draft = $this->fallbackDraft($stageKey, $fieldKey, (string) ($fields[$fieldKey] ?? $fieldKey), $currentResponses);
            $draftSource = 'fallback';
        }

        $confidence = trim($currentValue) !== '' || $this->cleanContext($currentResponses) !== [] ? 'medium' : 'low';
        return [
            'success' => true,
            'stage_key' => $stageKey,
            'field_key' => $fieldKey,
            'draft' => $draft,
            'reasoning' => $draftSource === 'ai'
                ? 'Drafted from the current stage context. Review it before using.'
                : 'Starter text shown because AI drafting was unavailable or context was too thin.',
            'confidence' => $confidence,
            'draft_source' => $draftSource,
            'source_label' => $draftSource === 'ai' ? 'AI generated' : 'Starter fallback',
            'fallback_used' => $draftSource === 'fallback',
        ];
    }

    private function buildPrompt(array $context): string
    {
        return "You are helping someone complete Clarity Journey.\n"
            . "Draft ONLY the answer text for the requested field. No markdown, no labels, no quotes.\n"
            . "Be specific but conservative. If context is thin, write a useful starter answer the founder can edit.\n"
            . "Keep it under 450 characters.\n\n"
            . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function cleanDraft(string $draft): string
    {
        $draft = trim($draft);
        $draft = preg_replace('/^["\']|["\']$/', '', $draft) ?? $draft;
        $draft = preg_replace('/^\s*(draft|answer|field-ready text)\s*:\s*/i', '', $draft) ?? $draft;
        $draft = trim($draft);
        if (mb_strlen($draft) > 450) {
            $draft = rtrim(mb_substr($draft, 0, 447)) . '...';
        }
        return $draft;
    }

    private function cleanContext(array $responses): array
    {
        $clean = [];
        foreach ($responses as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $clean[(string) $key] = mb_substr($value, 0, 700);
            }
        }
        return $clean;
    }

    private function fallbackDraft(string $stageKey, string $fieldKey, string $label, array $responses): string
    {
        $seed = trim((string) ($responses['target_customer'] ?? $responses['customer_segments'] ?? $responses['beachhead_segment'] ?? 'our first target customers'));
        $problem = trim((string) ($responses['observed_problem'] ?? $responses['problem'] ?? $responses['pains'] ?? 'the most urgent problem'));
        return match ($fieldKey) {
            'interview_count' => 'Start with the number of real customer conversations completed so far, then note where they came from.',
            'evidence' => 'Add proof such as customer quotes, repeated interview patterns, paid tests, usage data, or sales activity.',
            'key_result_1', 'key_result_2', 'key_result_3' => 'Set one measurable result with a number and deadline, such as interviews completed, demos booked, or paid pilots won.',
            default => 'For ' . $label . ', describe how ' . $seed . ' experience ' . $problem . ' and what would prove this assumption is true.',
        };
    }
}
