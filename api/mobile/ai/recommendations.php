<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Services\AIExecutionStatusService;

$auth = mobileRequireAuth();
$readiness = mobileAiCoachReadiness((int) $auth['user_id']);

if (empty($readiness['recommendations_ready'])) {
    mobileJson([
        'success' => true,
        'data' => array_merge([
            'cards' => [],
            'sections' => [
                'priority_actions' => [],
                'inbox_copilot' => [],
                'pipeline_copilot' => [],
            ],
            'recommended_prompts' => [],
            'generated_at' => date(DATE_ATOM),
            'feed_mode' => 'rich',
            'diagnostics' => [],
            'assumption_conflicts' => [],
            'generation_status' => [
                'source' => 'readiness_blocked',
                'generated_at' => date(DATE_ATOM),
                'cache_hit' => false,
                'provider_message' => '',
                'fallback_used' => false,
                'ai_status' => (new AIExecutionStatusService())->present([], [
                    'surface' => 'coach',
                    'blocked_reason' => 'readiness_incomplete',
                    'message' => 'Complete AI Coach setup before generating workspace recommendations.',
                ]),
            ],
            'ai_status' => (new AIExecutionStatusService())->present([], [
                'surface' => 'coach',
                'blocked_reason' => 'readiness_incomplete',
                'message' => 'Complete AI Coach setup before generating workspace recommendations.',
            ]),
        ], mobileAiReadinessEnvelope($readiness)),
    ]);
}

$recommendationPayload = mobileAiBuildRecommendationPayload((int) $auth['user_id']);
$cards = array_values((array) ($recommendationPayload['cards'] ?? []));
$generationStatus = (array) ($recommendationPayload['generation_status'] ?? []);

mobileJson([
    'success' => true,
    'data' => array_merge([
        'cards' => $cards,
        'sections' => [
            'priority_actions' => array_slice($cards, 0, 5),
            'inbox_copilot' => [],
            'pipeline_copilot' => [],
        ],
        'recommended_prompts' => [],
        'generated_at' => date(DATE_ATOM),
        'feed_mode' => 'rich',
        'diagnostics' => (array) ($recommendationPayload['diagnostics'] ?? []),
        'generation_status' => $generationStatus,
        'ai_status' => (array) ($generationStatus['ai_status'] ?? []),
        'operating_maturity' => (string) ($recommendationPayload['operating_maturity'] ?? ($readiness['operating_maturity'] ?? 'pre_clarity_journey')),
        'operating_maturity_context' => (array) ($recommendationPayload['operating_maturity_context'] ?? ($readiness['operating_maturity_context'] ?? [])),
        'assumption_conflicts' => (array) ($recommendationPayload['assumption_conflicts'] ?? []),
    ], mobileAiReadinessEnvelope($readiness)),
]);
