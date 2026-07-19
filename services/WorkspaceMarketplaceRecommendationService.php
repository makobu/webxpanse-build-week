<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\UserStrategyProfile;

class WorkspaceMarketplaceRecommendationService
{
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspaceMarketplaceRecommendationAdaptiveSignalService $adaptiveSignals;
    private WorkspaceMarketplaceRecommendationControlService $controls;
    private WorkspaceMarketplaceAccessService $access;

    public function __construct(
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceSkillInstallService $installer = null,
        ?WorkspaceMarketplaceRecommendationAdaptiveSignalService $adaptiveSignals = null,
        ?WorkspaceMarketplaceRecommendationControlService $controls = null,
        ?WorkspaceMarketplaceAccessService $access = null
    ) {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
        $this->adaptiveSignals = $adaptiveSignals ?? new WorkspaceMarketplaceRecommendationAdaptiveSignalService();
        $this->controls = $controls ?? new WorkspaceMarketplaceRecommendationControlService();
        $this->access = $access ?? new WorkspaceMarketplaceAccessService($this->catalog, $this->installer);
    }

    public function recommendationsForWorkspace(int $workspaceId, int $userId, int $limit = 0, string $surface = 'marketplace'): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $surface = $this->normalizeSurface($surface);

        $available = $this->catalog->available();
        $installed = $this->installer->installedForWorkspace($workspaceId);
        $installedByKey = [];
        foreach ($installed as $module) {
            $installedByKey[(string) ($module['key'] ?? '')] = $module;
        }

        $moduleContext = $this->installer->buildContextForWorkspace($workspaceId, $userId);
        $readinessByKey = (array) ($moduleContext['readiness'] ?? []);
        $onboarding = $this->getOnboardingState($workspaceId);
        $channels = $this->getChannelHealth($workspaceId);
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $leanStatus = $userId > 0 ? (new UserStrategyProfile())->getLeanCanvasStatus($userId) : [];
        $company = $this->getCompanyContext();
        $feedback = $this->feedbackBySkill($workspaceId);
        $adaptiveBySkill = $this->adaptiveSignals->signalsBySkill([
            'workspace_id' => $workspaceId,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
        ]);
        $controlsBySkill = $this->controls->activeControlsBySkill($workspaceId);

        $recommendations = [];
        foreach ($available as $module) {
            $key = (string) ($module['key'] ?? '');
            if ($key === '' || $this->isSuppressed($feedback[$key] ?? null)) {
                continue;
            }

            $recommendation = $this->scoreModule(
                $module,
                $workspaceId,
                $userId,
                $installedByKey,
                $readinessByKey,
                $onboarding,
                $channels,
                $strategy,
                $leanStatus,
                $company,
                $feedback[$key] ?? null,
                (array) ($adaptiveBySkill[$key] ?? []),
                $this->access->accessForDefinition($workspaceId, $userId, $module)
            );
            if ($recommendation !== null) {
                $recommendation = $this->applyAdminControls($recommendation, (array) ($controlsBySkill[$key] ?? []), $surface);
                if ($recommendation !== null) {
                    $recommendations[] = $recommendation;
                }
            }
        }

        usort($recommendations, static function (array $left, array $right): int {
            $pinned = ((int) !empty($right['is_pinned'])) <=> ((int) !empty($left['is_pinned']));
            if ($pinned !== 0) {
                return $pinned;
            }
            $score = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $recommendations = array_values($recommendations);
        return $limit > 0 ? array_slice($recommendations, 0, $limit) : $recommendations;
    }

    public function clarityNudgesForWorkspace(int $workspaceId, int $userId, bool $feedbackEnabled = false, int $limit = 1): array
    {
        $recommendations = $this->recommendationsForWorkspace($workspaceId, $userId, max(1, $limit), 'clarity_chat');
        return $this->clarityNudgesFromRecommendations($recommendations, $feedbackEnabled, $limit);
    }

    public function clarityNudgesFromRecommendations(array $recommendations, bool $feedbackEnabled = false, int $limit = 1): array
    {
        $nudges = [];
        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation)) {
                continue;
            }
            $setupUrl = trim((string) ($recommendation['setup_url'] ?? ''));
            $isInstalled = !empty($recommendation['is_installed']);
            $nudges[] = [
                'skill_key' => (string) ($recommendation['skill_key'] ?? ''),
                'label' => (string) ($recommendation['label'] ?? 'Marketplace item'),
                'priority' => (string) ($recommendation['priority'] ?? 'medium'),
                'why_now' => (string) ($recommendation['why_now'] ?? 'Recommended from this workspace context.'),
                'expected_benefit' => (string) ($recommendation['expected_benefit'] ?? 'Sharper recommendations and setup guidance.'),
                'setup_blockers' => array_values(array_filter(array_map('strval', (array) ($recommendation['setup_blockers'] ?? [])))),
                'cta_label' => $isInstalled ? 'Open setup' : 'Open Marketplace',
                'cta_url' => $setupUrl !== '' ? $setupUrl : 'workspace_skills.php',
                'feedback_enabled' => $feedbackEnabled,
                'setup_journey' => $feedbackEnabled ? (array) ($recommendation['setup_journey'] ?? []) : [],
                'admin_control_type' => (string) ($recommendation['admin_control_type'] ?? ''),
                'admin_control_surface' => (string) ($recommendation['admin_control_surface'] ?? ''),
                'admin_control_reason' => (string) ($recommendation['admin_control_reason'] ?? ''),
                'access_state' => (string) ($recommendation['access_state'] ?? ''),
                'blocked_skill_key' => (string) ($recommendation['blocked_skill_key'] ?? ''),
                'root_blocker_skill_key' => (string) ($recommendation['root_blocker_skill_key'] ?? ''),
                'pending_requirements' => array_values((array) ($recommendation['pending_requirements'] ?? [])),
                'next_action_url' => (string) ($recommendation['next_action_url'] ?? $setupUrl),
            ];
        }

        return $limit > 0 ? array_slice($nudges, 0, $limit) : $nudges;
    }

    public function recordFeedback(
        int $workspaceId,
        int $userId,
        string $skillKey,
        string $feedbackType,
        string $reasonCode = '',
        ?string $snoozedUntil = null,
        array $metadata = []
    ): void {
        if ($workspaceId <= 0 || !$this->feedbackTableReady()) {
            return;
        }

        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            return;
        }

        $feedbackType = $feedbackType === 'snoozed' ? 'snoozed' : 'dismissed';
        Database::execute(
            "INSERT INTO workspace_marketplace_recommendation_feedback
                (workspace_id, user_id, skill_key, feedback_type, reason_code, snoozed_until, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $skillKey,
                $feedbackType,
                trim($reasonCode) !== '' ? trim($reasonCode) : null,
                $feedbackType === 'snoozed' ? $snoozedUntil : null,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function scoreModule(
        array $module,
        int $workspaceId,
        int $userId,
        array $installedByKey,
        array $readinessByKey,
        array $onboarding,
        array $channels,
        array $strategy,
        array $leanStatus,
        array $company,
        ?array $feedback,
        array $adaptiveSignal,
        array $access
    ): ?array {
        $key = (string) ($module['key'] ?? '');
        $installed = isset($installedByKey[$key]);
        $readiness = (array) ($readinessByKey[$key] ?? []);
        $isReady = $installed && !empty($readiness) && !empty($readiness['ready']);
        $isLocked = !empty($access['is_locked']);
        if ($installed && ($readiness === [] || $isReady) && !$isLocked) {
            return null;
        }

        $score = $installed ? 62 : 0;
        $reasonCodes = [];
        $setupBlockers = [];
        $whyNow = '';
        $expectedBenefit = '';

        if ($installed && !$isReady) {
            $reasonCodes[] = 'installed_needs_setup';
            $setupBlockers[] = (string) ($readiness['message'] ?? 'Open setup to finish configuration.');
            $whyNow = (string) ($readiness['message'] ?? 'This module is installed but still needs setup before it can help.');
            $expectedBenefit = 'Finish setup so Clarity and Coach can safely use this workspace capability.';
        }

        $selectedChannel = (string) ($onboarding['communication_channel'] ?? '');
        $mainEmailStatus = (string) ($channels['main_email']['status'] ?? '');
        $assistantEmailStatus = (string) ($channels['assistant_email']['status'] ?? '');
        $whatsAppStatus = (string) ($channels['whatsapp']['status'] ?? '');

        if ($key === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT) {
            if (in_array($selectedChannel, ['whatsapp', 'both'], true)) {
                $score += 50;
                $reasonCodes[] = 'selected_channel_whatsapp';
            }
            if ($whatsAppStatus === 'ready') {
                $score += 25;
                $reasonCodes[] = 'whatsapp_connected';
            } elseif (in_array($selectedChannel, ['whatsapp', 'both'], true)) {
                $score += 15;
                $reasonCodes[] = 'whatsapp_setup_incomplete';
                foreach ((array) ($channels['whatsapp']['actions'] ?? []) as $action) {
                    $setupBlockers[] = (string) $action;
                }
            }
            if ($whatsAppStatus === 'disabled') {
                $setupBlockers[] = 'Meta embedded signup is not available for this platform yet.';
            }
            $whyNow = $whyNow ?: 'This workspace is set up around WhatsApp, so assistant support belongs close to that channel.';
            $expectedBenefit = $expectedBenefit ?: 'Let Clarity and Coach account for WhatsApp instructions, digests, and session follow-up.';
        } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT) {
            if (in_array($selectedChannel, ['email', 'both'], true)) {
                $score += 45;
                $reasonCodes[] = 'selected_channel_email';
            }
            if (in_array($mainEmailStatus, ['ready', 'warning'], true)) {
                $score += 20;
                $reasonCodes[] = 'main_email_available';
            }
            if ($assistantEmailStatus !== 'ready') {
                $score += 22;
                $reasonCodes[] = 'assistant_email_incomplete';
                foreach ((array) ($channels['assistant_email']['actions'] ?? []) as $action) {
                    $setupBlockers[] = (string) $action;
                }
            }
            if ($assistantEmailStatus === 'disabled') {
                $setupBlockers[] = 'Assistant email OAuth or SMTP setup is not available yet.';
            }
            $whyNow = $whyNow ?: 'Email is part of this workspace motion, but assistant runtime setup is not complete.';
            $expectedBenefit = $expectedBenefit ?: 'Add inbound instructions, customer reply drafts, and daily digest context.';
        } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL) {
            if (in_array($selectedChannel, ['sms', 'both'], true)) {
                $score += 48;
                $reasonCodes[] = 'selected_channel_sms';
            }
            $smsHealth = (array) ($channels['sms'] ?? []);
            $smsReady = !empty($smsHealth['outbound_ready']) || (string) ($smsHealth['status'] ?? '') === 'ready';
            if ($smsReady) {
                $score += 18;
                $reasonCodes[] = 'sms_credentials_available';
            } else {
                $score += 24;
                $reasonCodes[] = 'sms_setup_incomplete';
                foreach ((array) ($smsHealth['actions'] ?? []) as $action) {
                    $setupBlockers[] = (string) $action;
                }
                if ($setupBlockers === []) {
                    $setupBlockers[] = 'Twilio account SID, auth token, and sender number are not complete for this workspace.';
                }
            }
            $whyNow = $whyNow ?: 'SMS is useful as an optional fast-response channel once sender credentials and delivery tracking are explicit.';
            $expectedBenefit = $expectedBenefit ?: 'Enable short reminders, confirmations, and campaign nudges without exposing SMS tools before setup.';
        } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) {
            $calendarConnections = 0;
            try {
                if (Database::tableExists('calendar_integrations')) {
                    $calendarConnections = (int) ((Database::queryOne(
                        "SELECT COUNT(*) AS c FROM calendar_integrations WHERE workspace_id = ? AND sync_enabled = 1",
                        [$workspaceId]
                    )['c'] ?? 0));
                }
            } catch (\Throwable $e) {
                $calendarConnections = 0;
            }
            if ($calendarConnections > 0) {
                $score += 38;
                $reasonCodes[] = 'calendar_connected';
            } else {
                $score += 28;
                $reasonCodes[] = 'calendar_not_connected';
                $setupBlockers[] = 'No active Google or Outlook calendar connection was found for this workspace.';
            }
            if (trim((string) ($strategy['target_market_focus'] ?? '')) !== '') {
                $score += 12;
                $reasonCodes[] = 'meeting_context_useful';
            }
            $whyNow = $whyNow ?: 'Meeting-heavy work becomes more useful when calendar sync, bot joins, notes, and prep are managed as one module.';
            $expectedBenefit = $expectedBenefit ?: 'Give Clarity better meeting context for prep, follow-up, and recommendation timing.';
        } elseif ($key === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
            $coachEnabled = false;
            try {
                $coachEnabled = (new AICoachWorkspaceSetupService())->isWorkspaceEnabled($workspaceId);
            } catch (\Throwable $e) {
                $coachEnabled = false;
            }
            if (!$coachEnabled) {
                $score += 44;
                $reasonCodes[] = 'ai_coach_disabled';
                $setupBlockers[] = 'AI Coach is not enabled for this workspace.';
            }
            if (!empty($installedByKey)) {
                $score += 16;
                $reasonCodes[] = 'workspace_ready_for_guidance';
            }
            $whyNow = $whyNow ?: 'The workspace has enough context to benefit from proactive coaching and follow-through recommendations.';
            $expectedBenefit = $expectedBenefit ?: 'Surface better next actions from installed modules, deals, tasks, and workspace context.';
        } elseif ($key === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS) {
            $missingBlocks = (array) ($leanStatus['missing_blocks'] ?? []);
            $completeness = (int) ($leanStatus['completeness'] ?? 0);
            if ($missingBlocks !== [] || $completeness < 70) {
                $score += 48;
                $reasonCodes[] = 'business_model_context_incomplete';
                $setupBlockers[] = 'Complete Lean Canvas blocks: ' . implode(', ', array_slice($missingBlocks, 0, 4));
            }
            if (trim((string) ($company['company_description'] ?? '')) === '') {
                $score += 12;
                $reasonCodes[] = 'company_context_missing';
            }
            $whyNow = $whyNow ?: 'Clarity and Coach need a sharper business-model frame before recommending advanced execution.';
            $expectedBenefit = $expectedBenefit ?: 'Improve recommendations around customer, promise, channels, money, and metrics.';
        } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
            if (in_array($selectedChannel, ['email', 'whatsapp', 'both'], true) || !empty($installedByKey[WorkspaceSkillCatalogService::PLUGIN_EMAIL]) || !empty($installedByKey[WorkspaceSkillCatalogService::PLUGIN_WHATSAPP])) {
                $score += 34;
                $reasonCodes[] = 'channel_strategy_needed';
            }
            if (trim((string) ($strategy['target_market_focus'] ?? '')) !== '' || trim((string) ($strategy['segment_focus'] ?? '')) !== '') {
                $score += 18;
                $reasonCodes[] = 'content_distribution_needed';
            }
            $whyNow = $whyNow ?: 'The workspace is ready to turn campaign context into social posts, channel variants, and distribution packages.';
            $expectedBenefit = $expectedBenefit ?: 'Create cleaner content workflows, social channel readiness, and exportable post bundles.';
        } elseif ($key === WorkspaceSkillCatalogService::PLUGIN_DESIGN) {
            if (trim((string) ($strategy['offer_angle'] ?? '')) !== '' || trim((string) ($strategy['positioning_notes'] ?? '')) !== '') {
                $score += 32;
                $reasonCodes[] = 'landing_page_needed';
            }
            if (!empty($installedByKey[WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA])) {
                $score += 18;
                $reasonCodes[] = 'creative_assets_needed';
            }
            $whyNow = $whyNow ?: 'The workspace needs focused conversion surfaces for forms, landing pages, and campaign creative.';
            $expectedBenefit = $expectedBenefit ?: 'Package landing pages, lead capture, and page creative separately from broader marketing management.';
        } elseif ($key === WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER || $key === WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO) {
            $missing = [];
            foreach (['target_market_focus', 'segment_focus', 'outreach_posture', 'positioning_notes'] as $field) {
                if (trim((string) ($strategy[$field] ?? '')) === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $score += 52;
                $reasonCodes[] = 'marketing_context_incomplete';
                $setupBlockers[] = 'Missing marketing context: ' . implode(', ', array_map(static fn(string $field): string => str_replace('_', ' ', $field), array_slice($missing, 0, 4)));
            }
            if (in_array($selectedChannel, ['email', 'whatsapp', 'both'], true)) {
                $score += 10;
                $reasonCodes[] = 'channel_strategy_needed';
            }
            $whyNow = $whyNow ?: 'The workspace has enough setup to benefit from clearer positioning and campaign context.';
            $expectedBenefit = $expectedBenefit ?: 'Sharpen campaign management, positioning, analytics, and outreach recommendations.';
        }

        if ($isLocked) {
            $score = max($score, $installed ? 72 : 64);
            $reasonCodes[] = 'blocked_by_marketplace_access';
            $pendingLabels = array_values(array_filter(array_map(
                static fn(array $requirement): string => (string) ($requirement['label'] ?? ''),
                (array) ($access['pending_requirements'] ?? [])
            )));
            foreach ($pendingLabels as $pendingLabel) {
                $setupBlockers[] = $pendingLabel;
            }
            $whyNow = (string) ($access['message'] ?? '') ?: ($whyNow ?: 'This module is useful, but prerequisite workspace context is not ready yet.');
            $expectedBenefit = $expectedBenefit ?: 'Complete prerequisite context so this module can use a mature workspace instead of guessing.';
        }

        if ($score <= 0 || $reasonCodes === []) {
            return null;
        }

        $baseScore = min(100, $score);
        $adaptiveDelta = max(-12, min(10, (int) ($adaptiveSignal['adaptive_score_delta'] ?? 0)));
        $finalScore = max(0, min(100, $baseScore + $adaptiveDelta));
        $adaptiveReasonCodes = array_values(array_unique(array_filter(array_map('strval', (array) ($adaptiveSignal['adaptive_reason_codes'] ?? [])))));
        $priority = $finalScore >= 75 ? 'high' : ($finalScore >= 45 ? 'medium' : 'low');
        $setupUrl = $isLocked
            ? (string) ($access['next_action_url'] ?? 'workspace_skills.php')
            : (string) ($module['settings_schema']['settings_url'] ?? $module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php');

        return [
            'skill_key' => $key,
            'label' => (string) ($module['label'] ?? $key),
            'module_type' => (string) ($module['module_type'] ?? 'skill'),
            'score' => $finalScore,
            'base_score' => $baseScore,
            'adaptive_score_delta' => $adaptiveDelta,
            'adaptive_reason_codes' => $adaptiveReasonCodes,
            'adaptive_guidance' => (string) ($adaptiveSignal['adaptive_guidance'] ?? ''),
            'adaptive_confidence' => (string) ($adaptiveSignal['adaptive_confidence'] ?? 'none'),
            'priority' => $priority,
            'reason_codes' => array_values(array_unique(array_merge($reasonCodes, $adaptiveReasonCodes))),
            'why_now' => $whyNow,
            'expected_benefit' => $expectedBenefit,
            'setup_url' => $setupUrl,
            'setup_blockers' => array_values(array_unique(array_filter($setupBlockers))),
            'is_installed' => $installed,
            'readiness' => $readiness,
            'access_state' => (string) ($access['state'] ?? ''),
            'blocked_skill_key' => $isLocked ? $key : '',
            'root_blocker_skill_key' => (string) ($access['root_blocker_skill_key'] ?? ''),
            'pending_requirements' => array_values((array) ($access['pending_requirements'] ?? [])),
            'next_action_url' => $setupUrl,
            'suppressed_until' => (string) ($feedback['suppressed_until'] ?? ''),
            'coach_bucket' => (string) ($module['coach_bucket'] ?? ($key === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS ? 'foundation_gaps' : 'missing_features')),
            'category' => (string) ($module['category'] ?? ''),
            'is_pinned' => false,
            'admin_controls' => [],
            'admin_control_type' => '',
            'admin_control_surface' => '',
            'admin_control_reason' => '',
        ];
    }

    private function applyAdminControls(array $recommendation, array $controls, string $surface): ?array
    {
        if ($controls === []) {
            return $recommendation;
        }

        $shaped = $this->controls->shapeControls($controls);
        $isInstalledIncomplete = !empty($recommendation['is_installed']);
        $surfaceDisabled = in_array('all', (array) ($shaped['surface_disabled'] ?? []), true)
            || in_array($surface, (array) ($shaped['surface_disabled'] ?? []), true);

        if ($surfaceDisabled) {
            return null;
        }
        if (!empty($shaped['muted']) && !$isInstalledIncomplete) {
            return null;
        }

        $recommendation['admin_controls'] = $shaped;
        $recommendation['admin_control_type'] = (string) ($shaped['admin_control_type'] ?? '');
        $recommendation['admin_control_surface'] = (string) ($shaped['admin_control_surface'] ?? '');
        $recommendation['admin_control_reason'] = (string) ($shaped['admin_control_reason'] ?? '');

        if (!empty($shaped['pinned'])) {
            $recommendation['is_pinned'] = true;
            $recommendation['score'] = min(100, (int) ($recommendation['score'] ?? 0) + 6);
            $recommendation['priority'] = ((int) ($recommendation['score'] ?? 0)) >= 75 ? 'high' : (string) ($recommendation['priority'] ?? 'medium');
            $reasonCodes = (array) ($recommendation['reason_codes'] ?? []);
            $reasonCodes[] = 'admin_pinned';
            $recommendation['reason_codes'] = array_values(array_unique(array_filter(array_map('strval', $reasonCodes))));
            if ($recommendation['admin_control_type'] === '') {
                $recommendation['admin_control_type'] = 'pinned';
                $recommendation['admin_control_surface'] = 'all';
            }
        }

        return $recommendation;
    }

    private function getOnboardingState(int $workspaceId): array
    {
        if (!Database::tableExists('workspace_onboarding_state')) {
            return [];
        }

        return Database::queryOne(
            "SELECT communication_channel, optional_setup_json, launch_summary_json
             FROM workspace_onboarding_state
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
    }

    private function getChannelHealth(int $workspaceId): array
    {
        try {
            return (new WorkspaceChannelHealthService())->summarize($workspaceId, Auth::user() ?: null);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getCompanyContext(): array
    {
        try {
            return (new CompanyProfile())->get() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function feedbackBySkill(int $workspaceId): array
    {
        if (!$this->feedbackTableReady()) {
            return [];
        }

        $rows = Database::query(
            "SELECT skill_key, feedback_type, snoozed_until, created_at
             FROM workspace_marketplace_recommendation_feedback
             WHERE workspace_id = ?
             ORDER BY created_at DESC, id DESC",
            [$workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['skill_key'] ?? '');
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = [
                    'feedback_type' => (string) ($row['feedback_type'] ?? ''),
                    'suppressed_until' => (string) ($row['snoozed_until'] ?? ''),
                ];
            }
        }
        return $out;
    }

    private function isSuppressed(?array $feedback): bool
    {
        if ($feedback === null) {
            return false;
        }

        $type = (string) ($feedback['feedback_type'] ?? '');
        if ($type === 'dismissed') {
            return true;
        }

        $until = trim((string) ($feedback['suppressed_until'] ?? ''));
        return $type === 'snoozed' && $until !== '' && strtotime($until) !== false && strtotime($until) > time();
    }

    private function feedbackTableReady(): bool
    {
        return Database::tableExists('workspace_marketplace_recommendation_feedback');
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim($surface));
        return in_array($surface, ['marketplace', 'clarity_chat', 'coach'], true) ? $surface : 'marketplace';
    }
}
