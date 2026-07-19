<?php
/**
 * AI Coach Recommendations API
 * GET: Returns AI-generated recommendations for the current user.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AICoach;
use CRM\Modules\AITaskAutomationService;
use CRM\Modules\UserPreferences;
use CRM\Modules\OnboardingProgress;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\PluginRuntimeAuthorizationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;

header('Content-Type: application/json');

/**
 * @return array<string,mixed>
 */
function getFallbackPayload(string $effectiveMode = '2', string $generationSource = 'deterministic_fallback'): array
{
    $fallbackUsed = in_array($generationSource, ['deterministic_fallback', 'runtime_blocked'], true);
    return [
        'effective_mode' => $effectiveMode,
        'mode_variant' => 'standard',
        'lean_canvas_enabled' => false,
        'lean_canvas_completeness' => 0,
        'lean_canvas_missing_blocks' => [],
        'recommendations' => [
            'why_this_matters' => '',
            'priorities' => [],
            'quick_wins' => [],
            'missing_features' => [],
            'foundation_gaps' => [],
        ],
        'recommendations_ready' => false,
        'operating_maturity' => AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY,
        'operating_maturity_context' => [],
        'assumption_conflicts' => [],
        'financial_evidence' => [],
        'missing_requirements' => [],
        'marketplace_url' => 'workspace_skills.php?source=coach',
        'onboarding_payload' => null,
        'generation_status' => [
            'source' => $generationSource,
            'generated_at' => gmdate('c'),
            'cache_hit' => false,
            'provider_message' => '',
            'fallback_used' => $fallbackUsed,
            'ai_status' => (new AIExecutionStatusService())->present([], [
                'surface' => 'coach',
                'fallback' => $fallbackUsed,
                'source' => $generationSource,
                'blocked_reason' => $generationSource === 'runtime_blocked' ? 'runtime_paused' : '',
            ]),
        ],
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'coach',
            'fallback' => $fallbackUsed,
            'source' => $generationSource,
            'blocked_reason' => $generationSource === 'runtime_blocked' ? 'runtime_paused' : '',
        ]),
    ];
}

/**
 * @param array<string,mixed> $readiness
 * @return array<string,mixed>
 */
function getReadinessBlockedPayload(array $readiness, string $effectiveMode = '2'): array
{
    $payload = getFallbackPayload($effectiveMode, 'readiness_blocked');
    $payload['recommendations_ready'] = false;
    $payload['missing_requirements'] = (array) ($readiness['missing_requirements'] ?? []);
    $payload['clarity_journey_ready'] = !empty($readiness['clarity_journey_ready']);
    $payload['inherited_context_ready'] = !empty($readiness['inherited_context_ready']);
    $payload['personal_brief_source'] = (string) ($readiness['personal_brief_source'] ?? 'missing');
    $payload['context_sources'] = (array) ($readiness['context_sources'] ?? []);
    $payload['remaining_personal_requirements'] = (array) ($readiness['remaining_personal_requirements'] ?? []);
    $payload['operating_maturity'] = (string) ($readiness['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY);
    $payload['operating_maturity_context'] = (array) ($readiness['operating_maturity_context'] ?? []);
    $payload['assumption_conflicts'] = [];
    $payload['marketplace_url'] = (string) ($readiness['marketplace_url'] ?? 'workspace_skills.php?source=coach');
    $payload['onboarding_payload'] = $readiness['onboarding_payload'] ?? null;
    $payload['ai_coach_readiness'] = $readiness;
    $payload['why_this_matters'] = empty($readiness['clarity_journey_ready'])
        ? 'AI Coach needs Clarity Journey completed before it can generate stronger recommendations.'
        : 'AI Coach needs the shared company baseline and product or offer context before it can generate recommendations.';
    $payload['recommendations']['why_this_matters'] = $payload['why_this_matters'];
    $payload['ai_status'] = (new AIExecutionStatusService())->present([], [
        'surface' => 'coach',
        'source' => 'readiness_blocked',
        'blocked_reason' => 'readiness_incomplete',
        'message' => $payload['why_this_matters'],
    ]);
    $payload['generation_status']['ai_status'] = $payload['ai_status'];
    if (empty($readiness['clarity_journey_ready'])) {
        $journey = (array) ($readiness['clarity_journey_readiness'] ?? []);
        $stage = trim((string) ($journey['current_stage_key'] ?? ''));
        $label = $stage !== '' ? ucwords(str_replace('_', ' ', $stage)) : 'next';
        $payload['recommendations']['priorities'] = [[
            'title' => 'Complete the ' . $label . ' Clarity Journey stage',
            'impact' => 'High',
            'effort' => 'Medium',
            'reason' => 'AI Coach needs the Journey foundation before it can generate stronger recommendations.',
            'target_id' => 0,
            'suggested_subtasks' => [
                'Open Clarity Journey and finish the highlighted stage',
                'Save the customer, problem, offer, GTM, MVP, metrics, and OKR foundation',
                'Return to AI Coach after the Journey progress reaches 100%',
            ],
            'source_recommendation_type' => 'clarity_journey_gate',
        ]];
    }
    return $payload;
}

/**
 * @param array<string,mixed> $context
 */
function logAiCoachError(string $stage, string $message, array $context = []): void
{
    $parts = ['stage=' . $stage, 'message=' . $message];
    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
    }
    error_log('AI Coach API error: ' . implode(' | ', $parts));
}

/**
 * @param array<string,mixed> $context
 */
function respondWithError(int $status, string $error, string $effectiveMode = '2', array $context = []): void
{
    logAiCoachError('response_error', $error, $context);
    http_response_code($status);
    $generationSource = ($status === 403 && (string) ($context['stage'] ?? '') === 'runtime_gate')
        ? 'runtime_blocked'
        : 'deterministic_fallback';
    $payload = getFallbackPayload($effectiveMode, $generationSource);
    $payload['generation_status']['provider_message'] = $error;
    echo json_encode(array_merge(['error' => $error], $payload));
    exit;
}

try {
    Database::init(require __DIR__ . '/../../config/database.php');
    Session::start();

    if (!Auth::check()) {
        respondWithError(401, 'Unauthorized', '2', ['stage' => 'auth_check']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        respondWithError(405, 'Method not allowed', '2', ['stage' => 'method_check', 'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    }

    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        respondWithError(400, 'No active workspace', '2', ['stage' => 'workspace_check', 'user_id' => $userId]);
    }
    if (!(new PluginRuntimeAuthorizationService())->canAccessRuntimeModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_AI_COACH)) {
        respondWithError(403, 'AI Coach runtime is not available for this workspace.', '2', ['stage' => 'runtime_gate', 'user_id' => $userId]);
    }

    $preferences = new UserPreferences();
    $effectiveMode = $preferences->getEffectiveAIGuidanceMode($userId);
    $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);

    if (empty($readiness['recommendations_ready'])) {
        echo json_encode(getReadinessBlockedPayload($readiness, $effectiveMode));
        exit;
    }

    try {
        $automation = new AITaskAutomationService();
        $coach = new AICoach();
        $recommendations = $coach->generateRecommendations($userId, $effectiveMode, [
            'live_ai' => true,
            'force_refresh' => (string) ($_GET['refresh'] ?? '') === '1',
            'include_provider_status' => true,
        ]);
        $recommendations = $automation->annotateRecommendationsWithTaskState($userId, $recommendations);
        $generationStatus = (array) ($recommendations['generation_status'] ?? getFallbackPayload($effectiveMode)['generation_status']);
        $aiStatus = (array) ($generationStatus['ai_status'] ?? getFallbackPayload($effectiveMode)['ai_status']);
        try {
            $marketplaceItems = [];
            foreach (['foundation_gaps', 'missing_features'] as $bucket) {
                foreach ((array) ($recommendations[$bucket] ?? []) as $item) {
                    if (!empty($item['marketplace_skill_key'])) {
                        $marketplaceItems[] = $item;
                    }
                }
            }
            if ($marketplaceItems !== []) {
                (new WorkspaceMarketplaceRecommendationEventService())->recordRecommendationEvents(
                    (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                    $userId,
                    'coach',
                    'impression',
                    $marketplaceItems,
                    ['source' => 'ai_coach_recommendations']
                );
            }
        } catch (\Throwable $inner) {
            // Marketplace analytics should never block Coach recommendations.
        }
        $onboardingProgress = null;
        $celebration = null;
        if ($effectiveMode === '1') {
            $onboarding = new OnboardingProgress();
            $onboardingProgress = $onboarding->getProgress($userId);
            $celebratedRaw = $preferences->getPreference($userId, 'celebrated_milestones') ?? '';
            $celebrated = $celebratedRaw !== '' ? array_filter(explode(',', $celebratedRaw)) : [];
            $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
            $contactCount = (int) (Database::queryOne(
                "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?",
                [$workspaceId]
            )['count'] ?? 0);
            $taskCount = (int) (Database::queryOne(
                "SELECT COUNT(*) as count
                 FROM tasks
                 WHERE workspace_id = ?
                   AND (created_by = ? OR assigned_to = ?)",
                [$workspaceId, $userId, $userId]
            )['count'] ?? 0);
            $dealCount = (int) (Database::queryOne(
                "SELECT COUNT(*) as count FROM deals WHERE workspace_id = ?",
                [$workspaceId]
            )['count'] ?? 0);
            if ($contactCount >= 1 && !in_array('first_contact', $celebrated, true)) {
                $celebration = 'first_contact';
            } elseif ($taskCount >= 1 && !in_array('first_task', $celebrated, true)) {
                $celebration = 'first_task';
            } elseif ($dealCount >= 1 && !in_array('first_deal', $celebrated, true)) {
                $celebration = 'first_deal';
            }
        }
        echo json_encode([
            'effective_mode' => $effectiveMode,
            'mode_variant' => $recommendations['mode_variant'] ?? 'standard',
            'lean_canvas_enabled' => !empty($recommendations['lean_canvas_enabled']),
            'lean_canvas_completeness' => (int) ($recommendations['lean_canvas_completeness'] ?? 0),
            'lean_canvas_missing_blocks' => $recommendations['lean_canvas_missing_blocks'] ?? [],
            'recommendations' => $recommendations,
            'recommendations_ready' => true,
            'operating_maturity' => (string) ($recommendations['operating_maturity'] ?? ($readiness['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY)),
            'operating_maturity_context' => (array) ($recommendations['operating_maturity_context'] ?? ($readiness['operating_maturity_context'] ?? [])),
            'assumption_conflicts' => (array) ($recommendations['assumption_conflicts'] ?? []),
            'financial_evidence' => (array) ($recommendations['financial_evidence'] ?? []),
            'clarity_journey_ready' => !empty($readiness['clarity_journey_ready']),
            'inherited_context_ready' => !empty($readiness['inherited_context_ready']),
            'personal_brief_source' => (string) ($readiness['personal_brief_source'] ?? 'missing'),
            'context_sources' => (array) ($readiness['context_sources'] ?? []),
            'remaining_personal_requirements' => (array) ($readiness['remaining_personal_requirements'] ?? []),
            'missing_requirements' => [],
            'marketplace_url' => (string) ($readiness['marketplace_url'] ?? 'workspace_skills.php?source=coach'),
            'onboarding_payload' => $readiness['onboarding_payload'] ?? null,
            'ai_coach_readiness' => $readiness,
            'why_this_matters' => $recommendations['why_this_matters'] ?? '',
            'priorities' => $recommendations['priorities'] ?? [],
            'quick_wins' => $recommendations['quick_wins'] ?? [],
            'missing_features' => $recommendations['missing_features'] ?? [],
            'foundation_gaps' => $recommendations['foundation_gaps'] ?? [],
            'activation_bundle_guidance' => $recommendations['activation_bundle_guidance'] ?? null,
            'generation_status' => $generationStatus,
            'ai_status' => $aiStatus,
            'diagnostics' => $recommendations['diagnostics'] ?? [],
            'suppressed_recommendations' => $recommendations['suppressed_recommendations'] ?? [],
            'onboarding_progress' => $onboardingProgress,
            'celebration' => $celebration,
        ]);
    } catch (\Throwable $e) {
        respondWithError(
            500,
            'Failed to generate recommendations',
            $effectiveMode ?? '2',
            ['stage' => 'generate_recommendations', 'user_id' => $userId, 'mode' => $effectiveMode ?? '2', 'exception' => $e->getMessage()]
        );
    }
} catch (\Throwable $e) {
    logAiCoachError('bootstrap_or_unhandled', $e->getMessage(), ['exception' => get_class($e)]);
    http_response_code(500);
    echo json_encode(array_merge(['error' => 'Failed to load AI Coach recommendations'], getFallbackPayload('2')));
}
