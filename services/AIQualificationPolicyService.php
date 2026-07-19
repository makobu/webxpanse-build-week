<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UserPreferences;

class AIQualificationPolicyService
{
    private UserPreferences $preferences;
    private AIThresholdUpdateService $thresholds;
    private AIControlDecisionBridge $controlBridge;
    private AIRoleProfileService $roleProfiles;
    private AIUserWorkContextService $userWorkContext;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->thresholds = new AIThresholdUpdateService();
        $this->controlBridge = new AIControlDecisionBridge();
        $this->roleProfiles = new AIRoleProfileService();
        $this->userWorkContext = new AIUserWorkContextService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function evaluateAdviceEligibility(array $context): array
    {
        $contextScore = $this->scoreContextQuality($context);
        $threshold = $this->resolveConfidenceThreshold((string) ($context['surface']['name'] ?? 'coach'), 'advice', (int) ($context['identity']['user_id'] ?? 0));
        $goalScore = (float) ($context['goal_state']['goal_relevance_score'] ?? 0.0);
        $mode = (string) ($context['qualification_state']['effective_mode'] ?? '1');
        $confidence = (float) ($context['assistant_confidence'] ?? $context['confidence_score'] ?? 1.0);

        $decision = 'allow';
        $reasons = [];
        if ($mode === '3') {
            $decision = 'suggest_only';
            $reasons[] = 'guardian_mode';
        }
        if ($contextScore['score'] < 0.65) {
            $decision = 'allow_with_warning';
            $reasons[] = 'low_context_quality';
        }
        if ($goalScore > 0 && $goalScore < (float) ($context['ai_settings']['goal_relevance_min_score'] ?? 0.70)) {
            $decision = 'allow_with_warning';
            $reasons[] = 'low_goal_relevance';
        }
        if (!empty($context['missing_context_flags']) && ($context['ai_settings']['missing_context_behavior'] ?? 'warn') === 'block_high_risk') {
            $decision = 'suggest_only';
            $reasons[] = 'missing_context_flags';
        }
        if ($confidence > 0 && $confidence < $threshold) {
            $decision = 'suggest_only';
            $reasons[] = 'confidence_below_threshold';
        }

        $decisionPayload = [
            'decision' => $decision,
            'confidence_score' => $confidence,
            'confidence_threshold' => $threshold,
            'context_quality_score' => $contextScore['score'],
            'goal_relevance_score' => $goalScore,
            'mode' => $mode,
            'reasons' => $reasons,
        ];
        $decisionPayload += $this->buildRoleAwareDiagnostics($context, 'advice', $threshold);

        return $this->controlBridge->applyControlToAdviceDecision($this->resolveSurface($context), $decisionPayload);
    }

    public function evaluateActionEligibility(string $action, array $context): array
    {
        $userId = (int) ($context['identity']['user_id'] ?? 0);
        $threshold = $this->resolveConfidenceThreshold((string) ($context['surface']['name'] ?? 'assistant'), 'action', $userId);
        $contextScore = $this->scoreContextQuality($context);
        $mode = (string) ($context['qualification_state']['effective_mode'] ?? '1');
        $goalScore = (float) ($context['goal_state']['goal_relevance_score'] ?? 0.0);
        $confidence = (float) ($context['assistant_confidence'] ?? $context['confidence_score'] ?? 1.0);
        $decision = 'allow';
        $reasons = [];

        if ($mode === '3') {
            $decision = 'blocked';
            $reasons[] = 'guardian_mode';
        }
        if ($contextScore['score'] < 0.72) {
            $decision = 'suggest_only';
            $reasons[] = 'insufficient_context';
        }
        if (str_contains($action, 'task_complete') && empty($context['task_state']['explicit_evidence_available'])) {
            $decision = 'blocked';
            $reasons[] = 'missing_explicit_evidence';
        }
        if ($confidence > 0 && $confidence < $threshold) {
            $decision = 'blocked';
            $reasons[] = 'confidence_below_threshold';
        }

        $decisionPayload = [
            'decision' => $decision,
            'confidence_score' => $confidence,
            'confidence_threshold' => $threshold,
            'context_quality_score' => $contextScore['score'],
            'goal_relevance_score' => $goalScore,
            'mode' => $mode,
            'reasons' => $reasons,
        ];
        $decisionPayload += $this->buildRoleAwareDiagnostics($context, 'action', $threshold, $action);

        return $this->controlBridge->applyControlToActionDecision($this->resolveSurface($context), $decisionPayload);
    }

    public function evaluateAssistantAction(string $action, array $context): array
    {
        return $this->evaluateActionEligibility($action, $context);
    }

    public function evaluateAssistantAdvice(string $surface, array $context): array
    {
        $context['surface']['name'] = $surface;
        return $this->evaluateAdviceEligibility($context);
    }

    public function scoreContextQuality(array $context): array
    {
        $score = 1.0;
        $issues = [];
        $missing = (array) ($context['missing_context_flags'] ?? []);
        $score -= min(0.35, count($missing) * 0.08);
        if (empty($context['goal_state']['active_goals'])) {
            $score -= 0.10;
            $issues[] = 'no_active_goals';
        }
        if (empty($context['feature_state']['company_profile_ready'])) {
            $score -= 0.12;
            $issues[] = 'company_profile_incomplete';
        }
        if (empty($context['feature_state']['products_priced'])) {
            $score -= 0.12;
            $issues[] = 'products_unpriced';
        }
        if (empty($context['feature_state']['invoicing_ready'])) {
            $score -= 0.08;
            $issues[] = 'invoicing_not_ready';
        }

        return [
            'score' => max(0.0, min(1.0, $score)),
            'issues' => array_merge($issues, $missing),
        ];
    }

    public function resolveConfidenceThreshold(string $surface, string $actionType = 'advice', int $userId = 0): float
    {
        $target = $this->thresholds->resolveThresholdTarget($surface, $actionType);
        $resolved = $this->thresholds->getCurrentThreshold(
            (string) ($target['threshold_key'] ?? ($actionType === 'action' ? 'ai_action_min_confidence' : 'ai_advice_min_confidence')),
            (string) ($target['surface'] ?? $surface),
            (string) ($target['action_type'] ?? $actionType),
            $userId
        );
        if ($resolved !== null) {
            return $resolved;
        }
        if ($actionType === 'action') {
            $value = $userId > 0 ? $this->preferences->getAIActionMinConfidence($userId) : null;
            return $value ?? 0.92;
        }
        $value = $userId > 0 ? $this->preferences->getAIAdviceMinConfidence($userId) : null;
        return $value ?? 0.88;
    }

    public function logGuidanceRun(int $userId, string $surface, string $mode, array $decision, array $input, array $output): int
    {
        try {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
            Database::execute(
                "INSERT INTO ai_guidance_runs
                    (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    $surface,
                    $mode,
                    (string) ($decision['decision'] ?? 'suggest_only'),
                    (float) ($decision['confidence_score'] ?? 0),
                    (float) ($decision['context_quality_score'] ?? 0),
                    (float) ($input['goal_state']['goal_relevance_score'] ?? 0),
                    json_encode($decision),
                    json_encode($input),
                    json_encode($output),
                ]
            );
            return (int) Database::lastInsertId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function resolveSurface(array $context): string
    {
        $surface = (string) ($context['surface']['name'] ?? $context['surface'] ?? 'assistant');
        return match ($surface) {
            'coach',
            'clarity_chat',
            'assistant',
            'customer_thread',
            'commercial_assistant',
            'task_automation',
            'autonomous_tuning',
            'global' => $surface,
            default => 'assistant',
        };
    }

    private function buildRoleAwareDiagnostics(array $context, string $decisionType, float $threshold, string $action = ''): array
    {
        $surface = $this->resolveSurface($context);
        $userId = (int) ($context['identity']['user_id'] ?? 0);
        $roleProfile = is_array($context['role_profile'] ?? null)
            ? $context['role_profile']
            : ($userId > 0 ? $this->roleProfiles->buildProfile($userId, $context) : []);
        $userWorkContext = is_array($context['user_work_context'] ?? null)
            ? $context['user_work_context']
            : ($userId > 0 ? $this->userWorkContext->buildContext($userId, $surface, $context) : []);

        return [
            'role_profile' => $roleProfile,
            'role_summary' => (string) ($roleProfile['summary'] ?? ''),
            'user_work_context_summary' => $this->userWorkContext->summarize($userWorkContext),
            'role_threshold_recommendations' => $this->thresholds->getRoleAwareThresholdRecommendation(
                $surface,
                $action !== '' ? $action : $decisionType,
                $userId,
                $roleProfile
            ),
            'role_decision_slice' => [
                'surface' => $surface,
                'decision_type' => $decisionType,
                'priority_focus' => (array) ($roleProfile['priority_focus'] ?? []),
                'prompt_bias' => (array) ($roleProfile['prompt_bias'] ?? []),
                'ownership_focus' => (string) ($roleProfile['ownership_focus'] ?? ''),
                'threshold_used' => $threshold,
                'threshold_posture' => (string) ($roleProfile['threshold_posture'] ?? ''),
            ],
        ];
    }
}
