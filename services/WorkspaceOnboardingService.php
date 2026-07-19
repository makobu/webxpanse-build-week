<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Products;
use CRM\Modules\Targets;
use CRM\Modules\Tasks;
use CRM\Modules\UserPreferences;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Modules\WorkflowRecommendationService;
use CRM\Modules\WorkflowTemplates;

class WorkspaceOnboardingService
{
    private const REQUIRED_STEPS = [
        'company',
        'review',
    ];
    public const STEP_COUNT = 5;

    public function tableReady(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_onboarding_state'"
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function createInProgress(int $workspaceId): void
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_onboarding_state
                (workspace_id, status, current_step, required_steps_json, completed_steps_json, skipped_optional_json, deal_automation_enabled)
             VALUES (?, 'in_progress', 1, ?, JSON_ARRAY(), JSON_ARRAY(), NULL)
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'completed', status, VALUES(status)),
                current_step = IF(status = 'completed', current_step, GREATEST(current_step, VALUES(current_step))),
                required_steps_json = VALUES(required_steps_json),
                updated_at = NOW()",
            [$workspaceId, json_encode(self::REQUIRED_STEPS)]
        );
    }

    public function getState(int $workspaceId, int $userId = 0): array
    {
        $row = $this->loadRow($workspaceId);
        $computed = $this->computeReadiness($workspaceId, $userId, $row);
        $completedSteps = array_values(array_unique(array_merge(
            $this->decodeList($row['completed_steps_json'] ?? null),
            $computed['completed_steps']
        )));

        return [
            'row' => $row,
            'status' => (string) ($row['status'] ?? 'not_started'),
            'current_step' => max(1, min(self::STEP_COUNT, (int) ($row['current_step'] ?? $this->firstIncompleteStep($completedSteps)))),
            'required_steps' => self::REQUIRED_STEPS,
            'completed_steps' => $completedSteps,
            'skipped_optional' => $this->decodeList($row['skipped_optional_json'] ?? null),
            'all_complete' => $this->requiredComplete($completedSteps),
            'readiness' => $computed,
            'is_completed' => (string) ($row['status'] ?? '') === 'completed',
        ];
    }

    public function shouldGateWorkspace(int $workspaceId, array $user, string $page): bool
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return false;
        }

        if (\CRM\Authorization::isSuperAdmin($user)) {
            return false;
        }

        if ($this->isAllowedPage($page)) {
            return false;
        }

        $role = strtolower((string) (WorkspaceContext::currentRoleSlug() ?? ''));
        if (!in_array($role, ['owner', 'admin'], true)) {
            return false;
        }

        $row = $this->loadRow($workspaceId);
        return $row !== null && (string) ($row['status'] ?? '') !== 'completed';
    }

    public function saveStep(int $workspaceId, int $userId, int $step, array $data): array
    {
        $this->createInProgress($workspaceId);
        $row = $this->loadRow($workspaceId) ?? [];
        $completed = $this->decodeList($row['completed_steps_json'] ?? null);
        $skipped = $this->decodeList($row['skipped_optional_json'] ?? null);
        $updates = [];
        $params = [];

        if ($step === 1) {
            $this->saveStartContext($data);
            $completed[] = 'company';
        } elseif ($step === 2) {
            $this->saveProductsContext($userId, $data);
            $completed[] = 'products';
        } elseif ($step === 3) {
            $tone = $this->saveVoiceContext($userId, $data);
            $updates[] = 'relationship_style = ?';
            $updates[] = 'tone_json = ?';
            $params[] = (string) ($tone['relationship_style'] ?? '');
            $params[] = json_encode($tone, JSON_UNESCAPED_SLASHES);
            $row['relationship_style'] = (string) ($tone['relationship_style'] ?? '');
            $row['tone_json'] = json_encode($tone, JSON_UNESCAPED_SLASHES);
            $completed[] = 'voice';
        } elseif ($step === 4) {
            $technicalLevel = $this->normalizeTechnicalLevel((string) ($data['technical_level'] ?? 'work_with_me'));
            $mode = $this->launchModeForTechnicalLevel($technicalLevel);
            $autoresponderMode = $this->autoresponderModeForTechnicalLevel($technicalLevel);
            $bestPractices = !empty($data['ai_best_practices_enabled']);
            $dealEnabled = !array_key_exists('deal_automation_enabled', $data) || !empty($data['deal_automation_enabled']);
            $commercialEnabled = !empty($data['commercial_layer_enabled']);

            $updates[] = 'technical_level = ?';
            $updates[] = 'automation_launch_mode = ?';
            $updates[] = 'ai_autoresponder_mode = ?';
            $updates[] = 'ai_best_practices_enabled = ?';
            $updates[] = 'commercial_layer_enabled = ?';
            $updates[] = 'deal_automation_enabled = ?';
            $params[] = $technicalLevel;
            $params[] = $mode;
            $params[] = $autoresponderMode;
            $params[] = $bestPractices ? 1 : 0;
            $params[] = $commercialEnabled ? 1 : 0;
            $params[] = $dealEnabled ? 1 : 0;
            $row['technical_level'] = $technicalLevel;
            $row['automation_launch_mode'] = $mode;
            $row['ai_autoresponder_mode'] = $autoresponderMode;
            $row['ai_best_practices_enabled'] = $bestPractices ? 1 : 0;
            $row['commercial_layer_enabled'] = $commercialEnabled ? 1 : 0;
            $row['deal_automation_enabled'] = $dealEnabled ? 1 : 0;

            $completed[] = 'automation';
        } elseif ($step === 5) {
            $workflowPayload = $this->buildRecommendedWorkflows($userId);
            $gapService = new WorkspaceOnboardingContextGapService();
            $readiness = $this->computeReadiness($workspaceId, $userId, $row);
            $profile = (new CompanyProfile())->get() ?: [];
            $products = (new Products())->list();
            $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
            $invoice = (new InvoiceSettings())->get();
            $review = $gapService->review($profile, $products, $strategy, $invoice, $row, $readiness);
            $answersSubmitted = !empty($data['clarity_context_submitted']);
            $answers = is_array($data['clarity_answer'] ?? null) ? (array) $data['clarity_answer'] : [];
            $tone = $this->decodeAssoc($row['tone_json'] ?? null);

            if ($answersSubmitted && !empty($review['questions'])) {
                $tone = $gapService->applyAnswers($userId, $answers, (array) $review['questions'], $tone);
                $updates[] = 'tone_json = ?';
                $params[] = json_encode($tone, JSON_UNESCAPED_SLASHES);
                $profile = (new CompanyProfile())->get() ?: [];
                $products = (new Products())->list();
                $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
                $row['tone_json'] = json_encode($tone, JSON_UNESCAPED_SLASHES);
                $review = $gapService->review($profile, $products, $strategy, $invoice, $row, $readiness);
            }

            $review['requires_answers'] = false;
            $launchSummary = $this->buildLaunchSummary($workspaceId, $userId);
            $starterKit = ['created' => false, 'skipped_at' => gmdate('c'), 'reason' => 'context_brief_only'];
            $welcome = $gapService->welcome($launchSummary, $review, $answers, $starterKit);
            $row['launch_summary_json'] = json_encode($welcome, JSON_UNESCAPED_SLASHES);
            $updates[] = 'recommended_workflows_json = ?';
            $updates[] = 'launch_summary_json = ?';
            $updates[] = 'starter_kit_json = ?';
            $params[] = json_encode($workflowPayload, JSON_UNESCAPED_SLASHES);
            $params[] = json_encode($welcome, JSON_UNESCAPED_SLASHES);
            $params[] = json_encode($starterKit, JSON_UNESCAPED_SLASHES);
            $completed[] = 'review';
        }

        $completed = array_values(array_unique(array_intersect($completed, self::REQUIRED_STEPS)));
        $skipped = array_values(array_unique($skipped));
        $nextStep = $this->firstIncompleteStep($completed);
        $status = $this->requiredComplete($completed) ? 'completed' : 'in_progress';

        $optionalSetup = $this->buildOptionalSetupStatus($workspaceId, $userId);
        $row['optional_setup_json'] = json_encode($optionalSetup, JSON_UNESCAPED_SLASHES);
        $row['completed_steps_json'] = json_encode($completed);
        $row['skipped_optional_json'] = json_encode($skipped);
        $row['status'] = $status;
        $freshReadiness = $this->computeReadiness($workspaceId, $userId, $row);
        $updates[] = 'readiness_score = ?';
        $updates[] = 'optional_setup_json = ?';
        $updates[] = 'completed_steps_json = ?';
        $updates[] = 'skipped_optional_json = ?';
        $updates[] = 'current_step = ?';
        $updates[] = 'status = ?';
        $updates[] = 'completed_at = IF(? = "completed", COALESCE(completed_at, NOW()), completed_at)';
        $params[] = (int) ($freshReadiness['readiness_score'] ?? 0);
        $params[] = json_encode($optionalSetup, JSON_UNESCAPED_SLASHES);
        $params[] = json_encode($completed);
        $params[] = json_encode($skipped);
        $params[] = $nextStep;
        $params[] = $status;
        $params[] = $status;
        $params[] = $workspaceId;

        Database::execute(
            "UPDATE workspace_onboarding_state SET " . implode(', ', $updates) . " WHERE workspace_id = ?",
            $params
        );

        if ($status === 'completed') {
            $this->ensureOperatingBrief($workspaceId, $userId);
        }

        return $this->getState($workspaceId, $userId);
    }

    public function saveStepDraft(int $workspaceId, int $userId, int $step, array $data): array
    {
        $this->createInProgress($workspaceId);
        $step = max(1, min(self::STEP_COUNT - 1, $step));
        $row = $this->loadRow($workspaceId) ?? [];
        $updates = [];
        $params = [];

        if ($step === 1) {
            $this->saveStartContext($data);
        } elseif ($step === 2) {
            $this->saveProductsContext($userId, $data);
        } elseif ($step === 3) {
            $tone = $this->saveVoiceContext($userId, $data);
            $updates[] = 'relationship_style = ?';
            $updates[] = 'tone_json = ?';
            $params[] = (string) ($tone['relationship_style'] ?? '');
            $params[] = json_encode($tone, JSON_UNESCAPED_SLASHES);
            $row['relationship_style'] = (string) ($tone['relationship_style'] ?? '');
            $row['tone_json'] = json_encode($tone, JSON_UNESCAPED_SLASHES);
        } elseif ($step === 4) {
            $technicalLevel = $this->normalizeTechnicalLevel((string) ($data['technical_level'] ?? ($row['technical_level'] ?? 'work_with_me')));
            $mode = $this->launchModeForTechnicalLevel($technicalLevel);
            $autoresponderMode = $this->autoresponderModeForTechnicalLevel($technicalLevel);
            $bestPractices = !empty($data['ai_best_practices_enabled']);
            $dealEnabled = !array_key_exists('deal_automation_enabled', $data) || !empty($data['deal_automation_enabled']);
            $commercialEnabled = !empty($data['commercial_layer_enabled']);

            $updates[] = 'technical_level = ?';
            $updates[] = 'automation_launch_mode = ?';
            $updates[] = 'ai_autoresponder_mode = ?';
            $updates[] = 'ai_best_practices_enabled = ?';
            $updates[] = 'commercial_layer_enabled = ?';
            $updates[] = 'deal_automation_enabled = ?';
            $params[] = $technicalLevel;
            $params[] = $mode;
            $params[] = $autoresponderMode;
            $params[] = $bestPractices ? 1 : 0;
            $params[] = $commercialEnabled ? 1 : 0;
            $params[] = $dealEnabled ? 1 : 0;
            $row['technical_level'] = $technicalLevel;
            $row['automation_launch_mode'] = $mode;
        }

        $freshReadiness = $this->computeReadiness($workspaceId, $userId, $row);
        $updates[] = 'readiness_score = ?';
        $updates[] = 'updated_at = NOW()';
        $params[] = (int) ($freshReadiness['readiness_score'] ?? 0);

        if (!empty($updates)) {
            $params[] = $workspaceId;
            Database::execute(
                "UPDATE workspace_onboarding_state SET " . implode(', ', $updates) . " WHERE workspace_id = ?",
                $params
            );
        }

        $state = $this->getState($workspaceId, $userId);
        return [
            'ok' => true,
            'saved_at' => gmdate('c'),
            'readiness' => $state['readiness'] ?? [],
            'launch_available' => $this->launchAvailable((array) ($state['readiness'] ?? [])),
        ];
    }

    public function launchAvailable(array $readiness): bool
    {
        return !empty($readiness['profile_ready']);
    }

    public function firstMissingLaunchStep(array $readiness): int
    {
        if (empty($readiness['profile_ready'])) {
            return 1;
        }
        return 5;
    }

    public function completeQuickStart(int $workspaceId, int $userId, array $data): array
    {
        $this->createInProgress($workspaceId);
        $row = $this->loadRow($workspaceId) ?? [];
        $existingSkipped = $this->decodeList($row['skipped_optional_json'] ?? null);
        $skipped = array_values(array_unique(array_merge($existingSkipped, [
            'quick_start',
            'channel_connections_deferred',
            'invoice_setup_deferred',
            'runtime_automation_deferred',
        ])));

        $pdo = Database::getInstance();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            Database::beginTransaction();
        }

        try {
            $this->saveStartContext($data);

            $hasVoiceInput = $this->hasAnyInput($data, [
                'draft_tone_preset',
                'relationship_style',
                'draft_cta_style',
                'draft_formality_level',
                'draft_reading_level',
                'draft_voice_notes',
                'words_to_avoid',
                'escalation_preference',
            ]);
            $tone = $hasVoiceInput ? $this->saveVoiceContext($userId, $data) : $this->decodeAssoc($row['tone_json'] ?? null);

            if ($this->hasAnyInput($data, ['product_name', 'product_description', 'pricing_info', 'target_audience', 'ideal_customer_profile'])) {
                $this->saveProductsContext($userId, $data);
            }

            $hasAutomationInput = $this->hasAnyInput($data, ['technical_level', 'ai_best_practices_enabled', 'deal_automation_enabled', 'commercial_layer_enabled']);
            $technicalLevel = $hasAutomationInput ? $this->normalizeTechnicalLevel((string) ($data['technical_level'] ?? 'guide_me')) : null;
            $mode = $technicalLevel !== null ? $this->launchModeForTechnicalLevel($technicalLevel) : null;
            $autoresponderMode = $technicalLevel !== null ? $this->autoresponderModeForTechnicalLevel($technicalLevel) : null;
            $bestPractices = $hasAutomationInput && (!array_key_exists('ai_best_practices_enabled', $data) || !empty($data['ai_best_practices_enabled']));
            $dealEnabled = $hasAutomationInput && !empty($data['deal_automation_enabled']);
            $commercialEnabled = $hasAutomationInput && !empty($data['commercial_layer_enabled']);

            $row['relationship_style'] = (string) ($tone['relationship_style'] ?? ($row['relationship_style'] ?? ''));
            $row['technical_level'] = $technicalLevel;
            $row['automation_launch_mode'] = $mode;
            $row['ai_autoresponder_mode'] = $autoresponderMode;
            $summary = $this->buildQuickStartLaunchSummary($workspaceId, $userId);
            $row['launch_summary_json'] = json_encode($summary, JSON_UNESCAPED_SLASHES);
            $freshReadiness = $this->computeReadiness($workspaceId, $userId, $row);
            $completed = array_values(array_unique(array_intersect(
                ['company', 'review'],
                self::REQUIRED_STEPS
            )));

            Database::execute(
                "UPDATE workspace_onboarding_state
                 SET relationship_style = ?,
                     tone_json = ?,
                     technical_level = ?,
                     automation_launch_mode = ?,
                     ai_autoresponder_mode = ?,
                     ai_best_practices_enabled = ?,
                     commercial_layer_enabled = ?,
                     deal_automation_enabled = ?,
                     readiness_score = ?,
                     optional_setup_json = ?,
                     completed_steps_json = ?,
                     skipped_optional_json = ?,
                     launch_summary_json = ?,
                     current_step = ?,
                     status = 'completed',
                     completed_at = COALESCE(completed_at, NOW()),
                     updated_at = NOW()
                 WHERE workspace_id = ?",
                [
                    $row['relationship_style'] !== '' ? $row['relationship_style'] : null,
                    $tone !== [] ? json_encode($tone, JSON_UNESCAPED_SLASHES) : null,
                    $technicalLevel,
                    $mode,
                    $autoresponderMode,
                    $bestPractices ? 1 : 0,
                    $commercialEnabled ? 1 : 0,
                    $dealEnabled ? 1 : 0,
                    (int) ($freshReadiness['readiness_score'] ?? 0),
                    json_encode($this->buildOptionalSetupStatus($workspaceId, $userId), JSON_UNESCAPED_SLASHES),
                    json_encode($completed),
                    json_encode($skipped),
                    json_encode($summary, JSON_UNESCAPED_SLASHES),
                    self::STEP_COUNT,
                    $workspaceId,
                ]
            );

            if ($startedTransaction) {
                Database::commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->ensureOperatingBrief($workspaceId, $userId);

        return $this->getState($workspaceId, $userId);
    }

    public function getDashboardSetupImpact(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return [
                'visible' => false,
                'score' => 0,
                'headline' => 'Your workspace is 0% ready for daily work',
                'summary' => '',
                'actions' => [],
                'completed_actions' => [],
            ];
        }

        $state = $this->getState($workspaceId, $userId);
        $readiness = (array) ($state['readiness'] ?? []);
        $row = (array) ($state['row'] ?? []);
        $skipped = $this->decodeList($row['skipped_optional_json'] ?? null);
        $quickStart = in_array('quick_start', $skipped, true);
        $optional = (array) ($readiness['optional_setup'] ?? []);
        $invoice = (array) ($optional['invoice_setup'] ?? []);
        $dealAutomation = (new DealAutomationConfig())->get();

        $onboardingScore = max(0, min(100, (int) ($readiness['readiness_score'] ?? 0)));
        $selectedChannel = $this->normalizeChannel((string) ($row['communication_channel'] ?? 'email')) ?? 'email';
        $emailReady = !empty($readiness['connected_email']);
        $whatsAppReady = !empty($readiness['connected_whatsapp']);
        $defaultPlatformOpsReady = $this->isDefaultPlatformOpsWorkspace($workspaceId);
        $selectedChannelReady = $selectedChannel === 'whatsapp'
            ? $whatsAppReady
            : ($selectedChannel === 'both' ? ($emailReady && $whatsAppReady) : $emailReady);
        if ($defaultPlatformOpsReady) {
            $selectedChannelReady = true;
        }
        $invoiceReady = !empty($invoice['enabled']) && !empty($invoice['has_payment_instructions']);
        $seatLimit = 1;
        try {
            $seatLimit = (int) ((new WorkspacePlanEntitlementService())->entitlementsForWorkspace($workspaceId)['seat_limit'] ?? 1);
        } catch (\Throwable $e) {
            $seatLimit = 1;
        }
        $teamSetupApplicable = $seatLimit === 0 || $seatLimit > 1;
        $teamReady = $defaultPlatformOpsReady || !$teamSetupApplicable || $this->activeWorkspaceMemberCount($workspaceId) > 1;
        $automationReady = !empty($dealAutomation['enabled']);

        $bonusChecks = [
            'channel' => $selectedChannelReady,
            'invoice' => $invoiceReady,
            'team' => $teamReady,
            'automation' => $automationReady,
        ];
        $score = (int) round(($onboardingScore * 0.6) + (count(array_filter($bonusChecks)) * 10));
        $score = max(0, min(100, $score));

        $actions = [];
        if (!$selectedChannelReady) {
            $channelLabel = $selectedChannel === 'whatsapp' ? 'WhatsApp' : ($selectedChannel === 'both' ? 'Email and WhatsApp' : 'Email');
            $actions[] = [
                'key' => 'connect_channel',
                'title' => 'Connect ' . $channelLabel,
                'description' => 'Finish the selected conversation channel so customer messages can flow in.',
                'url' => 'workspace_skills.php?module=' . ($selectedChannel === 'whatsapp' ? 'whatsapp&setup_tab=manual' : 'email&setup_tab=outreach_email') . '#setup',
                'icon' => $selectedChannel === 'whatsapp' ? 'fa-brands fa-whatsapp' : 'fa-solid fa-envelope',
            ];
        }
        if (!$invoiceReady) {
            $actions[] = [
                'key' => 'add_invoice_settings',
                'title' => 'Add invoice settings',
                'description' => 'Save payment terms and instructions for quotes and invoices.',
                'url' => 'workspace_skills.php?module=finance#setup',
                'icon' => 'fa-solid fa-file-invoice-dollar',
            ];
        }
        if (!$teamReady) {
            $actions[] = [
                'key' => 'invite_team',
                'title' => 'Invite team',
                'description' => 'Add at least one teammate so work can be assigned cleanly.',
                'url' => 'settings.php?tab=workspace_governance#workspace-team',
                'icon' => 'fa-solid fa-user-plus',
            ];
        }
        if (!$automationReady) {
            $actions[] = [
                'key' => 'enable_automation',
                'title' => 'Choose how much approval AI needs',
                'description' => 'Choose whether Clarity suggests, drafts for approval, or runs approved actions.',
                'url' => 'settings.php?tab=deal_automation',
                'icon' => 'fa-solid fa-bolt',
            ];
        }

        $completed = [];
        foreach ($bonusChecks as $key => $ready) {
            if ($ready) {
                $completed[] = $key;
            }
        }

        return [
            'visible' => true,
            'score' => $score,
            'onboarding_score' => $onboardingScore,
            'headline' => 'Your workspace is ' . $score . '% ready for daily work',
            'summary' => $quickStart
                ? 'The business setup is done. Finish these when you are ready.'
                : ($actions === []
                ? 'Core setup is in place. Keep watching usage and guided next actions from here.'
                : ($teamSetupApplicable
                    ? 'Next setup actions are based on business setup, channel, billing, team, and AI approval readiness.'
                    : 'Next setup actions are based on business setup, channel, billing, and AI approval readiness.')),
            'actions' => array_slice($actions, 0, 4),
            'completed_actions' => $completed,
            'quick_start' => $quickStart,
        ];
    }

    public function buildRecommendedWorkflows(int $userId): array
    {
        try {
            $recommendations = (new WorkflowRecommendationService())->getRecommendedTemplates($userId);
        } catch (\Throwable $e) {
            $recommendations = [];
        }

        return [
            'generated_at' => gmdate('c'),
            'recommendations' => array_slice(array_map(static function (array $item): array {
                $template = (array) ($item['template'] ?? []);
                return [
                    'template_id' => (int) ($template['id'] ?? 0),
                    'name' => (string) ($template['name'] ?? 'Recommended workflow'),
                    'category' => (string) ($template['category'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? 'Recommended from your company context.'),
                    'impact' => (string) ($item['impact'] ?? 'medium'),
                    'effort' => (string) ($item['effort'] ?? 'low'),
                ];
            }, $recommendations), 0, 5),
        ];
    }

    public function generateBestPractices(int $workspaceId): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $company = (string) ($profile['company_name'] ?? 'your company');
        $industry = (string) ($profile['company_industry'] ?? 'your market');
        $productNames = array_values(array_filter(array_map(static fn(array $p): string => (string) ($p['name'] ?? ''), $products)));

        return [
            'tone' => 'Be clear, helpful, specific, and honest about next steps.',
            'context' => 'Use the saved profile for ' . $company . ' in ' . $industry . '.',
            'products' => $productNames,
            'reply_rules' => [
                'Answer the direct question first.',
                'Use pricing and service details only when they are saved in the CRM.',
                'Ask one clarifying question when intent is unclear.',
                'Escalate legal, refund, complaint, and angry messages to a human.',
                'End with a concrete next action when appropriate.',
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    private function saveCompanyContext(array $data): void
    {
        $profile = [
            'company_name' => trim((string) ($data['company_name'] ?? '')),
            'company_industry' => trim((string) ($data['company_industry'] ?? '')),
            'company_location' => trim((string) ($data['company_location'] ?? '')),
            'company_website' => trim((string) ($data['company_website'] ?? '')),
            'company_description' => trim((string) ($data['company_description'] ?? '')),
            'owner_company_context' => trim((string) ($data['owner_company_context'] ?? '')),
            'icp_job_titles' => trim((string) ($data['icp_job_titles'] ?? '')),
            'icp_industries' => trim((string) ($data['icp_industries'] ?? '')),
            'icp_pain_points' => trim((string) ($data['icp_pain_points'] ?? '')),
            'icp_channels' => trim((string) ($data['icp_channels'] ?? '')),
        ];
        (new CompanyProfile())->update(array_filter($profile, static fn(string $value): bool => $value !== ''));

        $productName = trim((string) ($data['product_name'] ?? ''));
        if ($productName !== '') {
            $products = new Products();
            $existingProducts = $products->list();
            $payload = [
                'name' => $productName,
                'description' => trim((string) ($data['product_description'] ?? '')),
                'pricing_info' => trim((string) ($data['pricing_info'] ?? '')),
                'target_audience' => trim((string) ($data['target_audience'] ?? '')),
                'use_cases' => trim((string) ($data['sales_process'] ?? '')),
                'benefits' => trim((string) ($data['objections'] ?? '')),
            ];
            if (!empty($existingProducts[0]['id'])) {
                $products->update((int) $existingProducts[0]['id'], $payload);
            } else {
                $products->create($payload);
            }
        }
    }

    private function saveStartContext(array $data): void
    {
        $profile = [
            'company_name' => trim((string) ($data['company_name'] ?? '')),
            'company_industry' => trim((string) ($data['company_industry'] ?? '')),
            'company_location' => trim((string) ($data['company_location'] ?? '')),
            'company_website' => trim((string) ($data['company_website'] ?? '')),
            'company_description' => trim((string) ($data['company_description'] ?? '')),
            'owner_company_context' => trim((string) ($data['success_outcome'] ?? '')),
        ];
        (new CompanyProfile())->update(array_filter($profile, static fn(string $value): bool => $value !== ''));
    }

    private function saveManualMailIfPresent(int $workspaceId, int $userId, array $data): void
    {
        if (empty($data['manual_mail_enabled'])) {
            return;
        }

        $smtpHost = trim((string) ($data['smtp_host'] ?? ''));
        $smtpUser = trim((string) ($data['smtp_username'] ?? ''));
        if ($smtpHost === '' || $smtpUser === '') {
            return;
        }

        (new EmailIntegrationService())->storeManualMailIntegration($userId, [
            'smtp_host' => $smtpHost,
            'smtp_port' => (int) ($data['smtp_port'] ?? 587),
            'smtp_username' => $smtpUser,
            'smtp_password' => (string) ($data['smtp_password'] ?? ''),
            'smtp_encryption' => (string) ($data['smtp_encryption'] ?? 'tls'),
            'from_email' => trim((string) ($data['smtp_from_email'] ?? $smtpUser)),
            'from_name' => trim((string) ($data['smtp_from_name'] ?? '')),
            'imap_enabled' => !empty($data['imap_enabled']),
            'imap_host' => trim((string) ($data['imap_host'] ?? '')),
            'imap_port' => (int) ($data['imap_port'] ?? 993),
            'imap_username' => trim((string) ($data['imap_username'] ?? '')),
            'imap_password' => (string) ($data['imap_password'] ?? ''),
            'imap_protocol' => (string) ($data['imap_protocol'] ?? 'imap'),
            'imap_encryption' => (string) ($data['imap_encryption'] ?? 'ssl'),
            'imap_folder' => trim((string) ($data['imap_folder'] ?? 'INBOX')),
        ], $workspaceId);
    }

    private function saveAssistantConfigs(int $workspaceId, int $userId, array $data): void
    {
        $service = new WorkspaceAssistantConfigService();
        if (!empty($data['email_assistant_enabled'])) {
            $service->save($workspaceId, 'email', [
                'system_email' => trim((string) ($data['email_assistant_system_email'] ?? '')),
                'smtp_host' => trim((string) ($data['assistant_smtp_host'] ?? '')),
                'smtp_port' => (int) ($data['assistant_smtp_port'] ?? 587),
                'smtp_username' => trim((string) ($data['assistant_smtp_username'] ?? '')),
                'smtp_password' => (string) ($data['assistant_smtp_password'] ?? ''),
                'smtp_encryption' => (string) ($data['assistant_smtp_encryption'] ?? 'tls'),
                'from_email' => trim((string) ($data['assistant_from_email'] ?? '')),
                'from_name' => trim((string) ($data['assistant_from_name'] ?? 'Email Assistant')),
                'imap_host' => trim((string) ($data['assistant_imap_host'] ?? '')),
                'imap_port' => (int) ($data['assistant_imap_port'] ?? 993),
                'imap_username' => trim((string) ($data['assistant_imap_username'] ?? '')),
                'imap_password' => (string) ($data['assistant_imap_password'] ?? ''),
            ], true, $userId);
            $this->installWorkspaceModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'onboarding_assistant_config');
        }

        if (!empty($data['whatsapp_assistant_enabled'])) {
            $service->save($workspaceId, 'whatsapp', [
                'assistant_phone_number' => trim((string) ($data['whatsapp_assistant_phone_number'] ?? '')),
                'assistant_phone_number_id' => trim((string) ($data['whatsapp_assistant_phone_number_id'] ?? '')),
                'access_token' => (string) ($data['whatsapp_assistant_access_token'] ?? ''),
                'digest_enabled' => !empty($data['whatsapp_assistant_digest_enabled']),
                'digest_time' => trim((string) ($data['whatsapp_assistant_digest_time'] ?? '07:00')),
            ], true, $userId);
            $this->installWorkspaceModule($workspaceId, $userId, WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT, 'onboarding_assistant_config');
        }
    }

    private function saveVoiceContext(int $userId, array $data): array
    {
        $tone = [
            'draft_tone_preset' => $this->normalizeTonePreset((string) ($data['draft_tone_preset'] ?? 'consultative')),
            'draft_voice_notes' => trim((string) ($data['draft_voice_notes'] ?? '')),
            'draft_cta_style' => $this->normalizeCtaStyle((string) ($data['draft_cta_style'] ?? 'clear')),
            'draft_formality_level' => $this->normalizeFormality((string) ($data['draft_formality_level'] ?? 'balanced')),
            'draft_reading_level' => $this->normalizeReadingLevel((string) ($data['draft_reading_level'] ?? 'professional')),
            'relationship_style' => $this->normalizeRelationshipStyle((string) ($data['relationship_style'] ?? 'trusted_advisor')),
            'words_to_avoid' => trim((string) ($data['words_to_avoid'] ?? '')),
            'escalation_preference' => trim((string) ($data['escalation_preference'] ?? '')),
        ];
        $this->saveStrategyProfile($userId, $tone);
        return $tone;
    }

    private function saveOfferContext(int $userId, array $data): void
    {
        $this->saveProductsContext($userId, $data);
    }

    private function saveProductsContext(int $userId, array $data): void
    {
        if (isset($data['company_name']) || isset($data['company_description']) || isset($data['company_industry'])) {
            $this->saveStartContext($data);
        }
        $productIds = (array) ($data['product_id'] ?? []);
        $names = (array) ($data['product_name'] ?? []);
        $descriptions = (array) ($data['product_description'] ?? []);
        $pricing = (array) ($data['pricing_info'] ?? []);
        $targets = (array) ($data['target_audience'] ?? []);
        $useCases = (array) ($data['use_cases'] ?? []);
        $benefits = (array) ($data['benefits'] ?? []);
        $unitPrices = (array) ($data['unit_price'] ?? []);

        if (!is_array($data['product_name'] ?? null)) {
            $productIds = [(string) ($data['product_id'] ?? '')];
            $names = [(string) ($data['product_name'] ?? '')];
            $descriptions = [(string) ($data['product_description'] ?? '')];
            $pricing = [(string) ($data['pricing_info'] ?? '')];
            $targets = [(string) ($data['target_audience'] ?? '')];
            $useCases = [(string) ($data['sales_process'] ?? $data['use_cases'] ?? '')];
            $benefits = [(string) ($data['objections'] ?? $data['benefits'] ?? '')];
            $unitPrices = [(string) ($data['unit_price'] ?? '')];
        }

        $productsModule = new Products();
        $existingProducts = $productsModule->list();
        $createdOrUpdated = false;
        $rowCount = max(count($names), count($descriptions), count($pricing), count($targets));

        for ($i = 0; $i < $rowCount; $i++) {
            $name = trim((string) ($names[$i] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'name' => $name,
                'description' => trim((string) ($descriptions[$i] ?? '')),
                'pricing_info' => trim((string) ($pricing[$i] ?? '')),
                'target_audience' => trim((string) ($targets[$i] ?? '')),
                'use_cases' => trim((string) ($useCases[$i] ?? '')),
                'benefits' => trim((string) ($benefits[$i] ?? '')),
                'unit_price' => trim((string) ($unitPrices[$i] ?? '')),
                'display_order' => $i,
            ];
            $productId = (int) ($productIds[$i] ?? 0);
            if ($productId > 0 && $productsModule->update($productId, $payload)) {
                $createdOrUpdated = true;
                continue;
            }

            $fallbackProduct = (array) ($existingProducts[$i] ?? []);
            if (!empty($fallbackProduct['id']) && $productsModule->update((int) $fallbackProduct['id'], $payload)) {
                $createdOrUpdated = true;
                continue;
            }

            $productsModule->create($payload);
            $createdOrUpdated = true;
        }

        $this->saveStrategyProfile($userId, [
            'target_market_focus' => trim((string) ($data['target_market_focus'] ?? '')),
            'ideal_customer_profile' => trim((string) ($data['ideal_customer_profile'] ?? '')),
            'offer_angle' => trim((string) ($data['offer_angle'] ?? '')),
            'segment_focus' => trim((string) ($data['segment_focus'] ?? '')),
            'sales_motion' => trim((string) ($data['sales_motion'] ?? '')),
            'deal_movement_strategy' => trim((string) ($data['deal_movement_strategy'] ?? '')),
            'outreach_posture' => trim((string) ($data['outreach_posture'] ?? '')),
            'positioning_notes' => trim((string) ($data['positioning_notes'] ?? '')),
            'market_view' => trim((string) ($data['market_view'] ?? '')),
            'strategy_hypothesis' => trim((string) ($data['strategy_hypothesis'] ?? '')),
        ]);
    }

    private function hasAnyInput(array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (trim((string) $item) !== '') {
                        return true;
                    }
                }
                continue;
            }
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function saveVisionContext(int $userId, array $data): void
    {
        if (empty($data['lean_canvas_enabled'])) {
            (new UserPreferences())->setLeanCanvasModeEnabled($userId, false);
            return;
        }

        (new UserPreferences())->setLeanCanvasModeEnabled($userId, true);
        $this->saveStrategyProfile($userId, [
            'lean_problem' => trim((string) ($data['lean_problem'] ?? '')),
            'lean_customer_segments' => trim((string) ($data['lean_customer_segments'] ?? '')),
            'lean_unique_value_proposition' => trim((string) ($data['lean_unique_value_proposition'] ?? '')),
            'lean_solution' => trim((string) ($data['lean_solution'] ?? '')),
            'lean_channels' => trim((string) ($data['lean_channels'] ?? '')),
            'lean_revenue_streams' => trim((string) ($data['lean_revenue_streams'] ?? '')),
            'lean_cost_structure' => trim((string) ($data['lean_cost_structure'] ?? '')),
            'lean_key_metrics' => trim((string) ($data['lean_key_metrics'] ?? '')),
            'lean_unfair_advantage' => trim((string) ($data['lean_unfair_advantage'] ?? '')),
        ]);
    }

    private function saveWorkspaceSkills(int $workspaceId, int $userId, array $data): void
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return;
        }

        $selected = array_values(array_unique(array_filter((array) ($data['workspace_skills'] ?? []), 'is_string')));
        if (!empty($data['lean_canvas_enabled']) && !in_array(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $selected, true)) {
            $selected[] = WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS;
        }

        if ($selected === []) {
            return;
        }

        $installer = new WorkspaceSkillInstallService();
        foreach ($selected as $skillKey) {
            try {
                $installer->install($workspaceId, (string) $skillKey, $userId, ['source' => 'onboarding']);
            } catch (\Throwable $e) {
                error_log('WorkspaceOnboardingService skill install failed: ' . $e->getMessage());
            }
        }
    }

    private function installWorkspaceModule(int $workspaceId, int $userId, string $moduleKey, string $source): void
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return;
        }

        try {
            (new WorkspaceSkillInstallService())->install($workspaceId, $moduleKey, $userId, ['source' => $source]);
        } catch (\Throwable $e) {
            error_log('WorkspaceOnboardingService module install failed: ' . $e->getMessage());
        }
    }

    private function saveMoneyContext(int $userId, array $data): void
    {
        if (empty($data['invoice_setup_enabled'])) {
            return;
        }

        $profile = (new CompanyProfile())->get();
        $profilePayload = [
            'company_legal_name' => trim((string) ($data['company_legal_name'] ?? '')),
            'company_tax_id' => trim((string) ($data['company_tax_id'] ?? '')),
            'company_address' => trim((string) ($data['company_address'] ?? '')),
            'company_email' => trim((string) ($data['invoice_company_email'] ?? '')),
            'company_phone' => trim((string) ($data['invoice_company_phone'] ?? '')),
        ];
        if (!$profile && $profilePayload['company_legal_name'] !== '') {
            $profilePayload['company_name'] = $profilePayload['company_legal_name'];
        }
        if (!empty(array_filter($profilePayload, static fn($value): bool => trim((string) $value) !== ''))) {
            (new CompanyProfile())->update($profilePayload);
        }

        (new InvoiceSettings())->save([
            'enabled' => true,
            'default_currency' => trim((string) ($data['default_currency'] ?? 'USD')) ?: 'USD',
            'default_tax_mode' => (string) ($data['default_tax_mode'] ?? 'exclusive'),
            'default_tax_rate' => (float) ($data['default_tax_rate'] ?? 0),
            'default_payment_terms_days' => (int) ($data['default_payment_terms_days'] ?? 14),
            'invoice_prefix' => trim((string) ($data['invoice_prefix'] ?? 'INV-')) ?: 'INV-',
            'quote_prefix' => trim((string) ($data['quote_prefix'] ?? 'QT-')) ?: 'QT-',
            'bank_name' => trim((string) ($data['bank_name'] ?? '')),
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')),
            'bank_account_number' => trim((string) ($data['bank_account_number'] ?? '')),
            'bank_instructions' => trim((string) ($data['bank_instructions'] ?? '')),
            'default_notes' => trim((string) ($data['default_notes'] ?? 'Thank you for your business.')),
            'default_terms' => trim((string) ($data['default_terms'] ?? 'Payment due within the stated terms.')),
        ], $userId);
    }

    private function saveStrategyProfile(int $userId, array $changes): void
    {
        $profile = new UserStrategyProfile();
        $existing = $profile->get($userId) ?: [];
        $profile->save($userId, array_merge($existing, $changes));
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            (new UserStrategySnapshot())->syncForUser($workspaceId, $userId);
        }
    }

    private function computeReadiness(int $workspaceId, int $userId, ?array $row): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $companyName = trim((string) ($profile['company_name'] ?? ''));
        $profileReady = $companyName !== '' && strcasecmp($companyName, 'Your Company Name') !== 0;
        $productReady = false;
        foreach ($products as $product) {
            if (trim((string) ($product['name'] ?? '')) !== '') {
                $productReady = true;
                break;
            }
        }
        $channelReady = $this->hasConnectedEmail($workspaceId) || $this->hasConnectedWhatsApp($workspaceId) || $this->isDefaultPlatformOpsWorkspace($workspaceId);
        $voiceReady = trim((string) ($strategy['draft_tone_preset'] ?? '')) !== ''
            && trim((string) ($row['relationship_style'] ?? ($row['tone_json'] ?? ''))) !== '';
        $offerReady = $productReady
            && (trim((string) ($strategy['ideal_customer_profile'] ?? '')) !== ''
                || trim((string) ($profile['icp_job_titles'] ?? '')) !== ''
                || trim((string) ($products[0]['target_audience'] ?? '')) !== '');
        $autopilotReady = !empty($row['technical_level']) && !empty($row['automation_launch_mode']);
        $launchSummary = [];
        if (!empty($row['launch_summary_json'])) {
            $decoded = json_decode((string) $row['launch_summary_json'], true);
            $launchSummary = is_array($decoded) ? $decoded : [];
        }
        $launchReady = !empty($launchSummary)
            && (
                (string) ($row['status'] ?? '') === 'completed'
                || !empty($launchSummary['ready_to_enter'])
                || (string) ($launchSummary['welcome_state'] ?? '') === 'quick_start_complete'
            );

        $completed = [];
        if ($profileReady) {
            $completed[] = 'company';
        }
        if ($offerReady) {
            $completed[] = 'products';
        }
        if ($voiceReady) {
            $completed[] = 'voice';
        }
        if ($autopilotReady) {
            $completed[] = 'automation';
        }
        if ($launchReady) {
            $completed[] = 'review';
        }

        $requiredChecks = [
            $profileReady,
            $launchReady,
        ];
        $readinessScore = (int) round((count(array_filter($requiredChecks)) / count($requiredChecks)) * 100);

        return [
            'channel_ready' => $channelReady,
            'profile_ready' => $profileReady,
            'product_ready' => $productReady,
            'voice_ready' => $voiceReady,
            'offer_ready' => $offerReady,
            'autopilot_ready' => $autopilotReady,
            'readiness_score' => $readinessScore,
            'connected_email' => $this->hasConnectedEmail($workspaceId),
            'connected_whatsapp' => $this->hasConnectedWhatsApp($workspaceId),
            'completed_steps' => $completed,
            'profile' => $profile,
            'products' => $products,
            'strategy' => $strategy,
            'optional_setup' => $this->buildOptionalSetupStatus($workspaceId, $userId),
            'launch_summary' => $launchSummary,
        ];
    }

    private function applyAutomationLaunchMode(string $mode, int $userId): void
    {
        $prefs = new UserPreferences();
        if ($mode === 'manual_review') {
            $prefs->setAIGuidanceMode($userId, '1');
            $this->saveWorkflowMode('suggest_only', $userId);
            return;
        }

        $prefs->setAIGuidanceMode($userId, 'auto');
        $this->saveWorkflowMode($mode === 'full_auto' ? 'full_auto' : 'auto_safe', $userId);
    }

    private function buildOptionalSetupStatus(int $workspaceId, int $userId): array
    {
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $leanFields = [
            'lean_problem',
            'lean_customer_segments',
            'lean_unique_value_proposition',
            'lean_solution',
            'lean_channels',
            'lean_revenue_streams',
            'lean_cost_structure',
            'lean_key_metrics',
            'lean_unfair_advantage',
        ];
        $leanCount = 0;
        foreach ($leanFields as $field) {
            if (trim((string) ($strategy[$field] ?? '')) !== '') {
                $leanCount++;
            }
        }

        $invoice = (new InvoiceSettings())->get();
        $assistantService = new WorkspaceAssistantConfigService();
        $emailAssistant = $assistantService->get($workspaceId, 'email');
        $whatsAppAssistant = $assistantService->get($workspaceId, 'whatsapp');
        $row = $this->loadRow($workspaceId) ?: [];

        return [
            'lean_canvas' => [
                'enabled' => $leanCount > 0,
                'filled_blocks' => $leanCount,
                'total_blocks' => count($leanFields),
            ],
            'invoice_setup' => [
                'enabled' => !empty($invoice['enabled']),
                'currency' => (string) ($invoice['default_currency'] ?? 'USD'),
                'has_payment_instructions' => trim((string) ($invoice['bank_instructions'] ?? $invoice['bank_account_number'] ?? '')) !== '',
            ],
            'email_assistant' => [
                'enabled' => !empty($emailAssistant['enabled']),
            ],
            'whatsapp_assistant' => [
                'enabled' => !empty($whatsAppAssistant['enabled']),
            ],
            'automation_preferences' => [
                'technical_level' => (string) ($row['technical_level'] ?? ''),
                'launch_mode' => (string) ($row['automation_launch_mode'] ?? ''),
                'autoresponder_mode' => (string) ($row['ai_autoresponder_mode'] ?? ''),
                'best_practices_enabled' => !empty($row['ai_best_practices_enabled']),
                'deal_automation_requested' => !empty($row['deal_automation_enabled']),
                'commercial_layer_requested' => !empty($row['commercial_layer_enabled']),
            ],
        ];
    }

    private function activeWorkspaceMemberCount(int $workspaceId): int
    {
        try {
            return (int) (Database::queryOne(
                "SELECT COUNT(*) AS count
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND membership_status = 'active'",
                [$workspaceId]
            )['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function isDefaultPlatformOpsWorkspace(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }
        try {
            $row = Database::queryOne(
                "SELECT id, slug, settings_json
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            );
            if (!$row || ((int) ($row['id'] ?? 0) !== 1 && (string) ($row['slug'] ?? '') !== 'default')) {
                return false;
            }
            $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
            return is_array($settings)
                && ($settings['workspace_purpose'] ?? '') === 'platform_ops'
                && !empty($settings['internal_channel_ready']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildLaunchSummary(int $workspaceId, int $userId): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $invoice = (new InvoiceSettings())->get();
        $primaryProduct = (array) ($products[0] ?? []);

        return [
            'company' => [
                'name' => (string) ($profile['company_name'] ?? ''),
                'industry' => (string) ($profile['company_industry'] ?? ''),
                'location' => (string) ($profile['company_location'] ?? ''),
                'description' => (string) ($profile['company_description'] ?? ''),
            ],
            'offer' => [
                'name' => (string) ($primaryProduct['name'] ?? ''),
                'pricing' => (string) ($primaryProduct['pricing_info'] ?? ''),
                'ideal_customer' => (string) ($strategy['ideal_customer_profile'] ?? ($primaryProduct['target_audience'] ?? '')),
                'offer_angle' => (string) ($strategy['offer_angle'] ?? ''),
                'all_products' => array_values(array_map(static fn(array $product): array => [
                    'name' => (string) ($product['name'] ?? ''),
                    'description' => (string) ($product['description'] ?? ''),
                    'pricing' => (string) ($product['pricing_info'] ?? ''),
                    'target_audience' => (string) ($product['target_audience'] ?? ''),
                ], $products)),
            ],
            'voice' => [
                'tone' => (string) ($strategy['draft_tone_preset'] ?? ''),
                'formality' => (string) ($strategy['draft_formality_level'] ?? ''),
                'cta' => (string) ($strategy['draft_cta_style'] ?? ''),
                'notes' => (string) ($strategy['draft_voice_notes'] ?? ''),
            ],
            'automation' => [
                'technical_level' => (string) (($this->loadRow($workspaceId)['technical_level'] ?? 'work_with_me')),
                'launch_mode_preference' => (string) (($this->loadRow($workspaceId)['automation_launch_mode'] ?? 'manual_review')),
                'autoresponder_preference' => (string) (($this->loadRow($workspaceId)['ai_autoresponder_mode'] ?? 'draft_only')),
                'best_practices_enabled' => !empty($this->loadRow($workspaceId)['ai_best_practices_enabled']),
                'deal_automation_requested' => !empty($this->loadRow($workspaceId)['deal_automation_enabled']),
                'commercial_layer_requested' => !empty($this->loadRow($workspaceId)['commercial_layer_enabled']),
                'live_channel_setup_deferred' => true,
            ],
            'money' => [
                'currency' => (string) ($invoice['default_currency'] ?? 'USD'),
                'payment_terms_days' => (int) ($invoice['default_payment_terms_days'] ?? 14),
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    private function buildQuickStartLaunchSummary(int $workspaceId, int $userId): array
    {
        $summary = $this->buildLaunchSummary($workspaceId, $userId);
        $summary['quick_start'] = true;
        $summary['welcome_state'] = 'quick_start_complete';
        $summary['deferred_setup'] = [
            'connect_channel',
            'invoice_settings',
            'team_invites',
            'runtime_automation',
        ];
        $summary['message'] = 'The business setup is complete. The workspace is open and channel, invoice, team, and live automation setup can be finished from the dashboard checklist.';

        return $summary;
    }

    private function createStarterKit(int $workspaceId, int $userId, array $launchSummary): array
    {
        $existingRow = $this->loadRow($workspaceId);
        $existingStarterKit = $this->decodeAssoc($existingRow['starter_kit_json'] ?? null);
        if (!empty($existingStarterKit['created'])) {
            return $existingStarterKit + ['reused' => true];
        }

        $created = [
            'tasks' => [],
            'targets' => [],
            'templates' => [],
            'workflows' => [],
        ];
        $company = (string) ($launchSummary['company']['name'] ?? 'the workspace');
        $offer = (string) ($launchSummary['offer']['name'] ?? 'the core offer');
        $idealCustomer = (string) ($launchSummary['offer']['ideal_customer'] ?? 'ideal customers');

        try {
            $tasks = new Tasks();
            foreach ([
                'Review the generated workspace brief',
                'Prepare first outreach list for ' . ($idealCustomer ?: 'ideal customers'),
                'Send one warm follow-up for ' . ($offer ?: 'the core offer'),
                'Check invoice/payment details before first quote',
                'Review automation suggestions after first customer reply',
            ] as $title) {
                $created['tasks'][] = $tasks->create([
                    'title' => $title,
                    'description' => 'Created from onboarding so ' . $company . ' can start with useful next actions.',
                    'created_by' => $userId,
                    'actor_user_id' => $userId,
                    'priority' => 'medium',
                    'status' => 'pending',
                    'metadata_json' => ['source' => 'onboarding_starter_kit'],
                ]);
            }
        } catch (\Throwable $e) {
            $created['tasks_error'] = $e->getMessage();
        }

        try {
            $targetPolicy = new TargetCreationPolicy();
            $created['targets'][] = $targetPolicy->createAutomated([
                'user_id' => $userId,
                'title' => 'Book first qualified conversation',
                'description' => 'Starter target from onboarding.',
                'target_type' => 'sales',
                'target_value' => 1,
                'current_value' => 0,
                'unit' => 'conversation',
                'target_date' => date('Y-m-d', strtotime('+14 days')),
                'scope' => 'personal',
                'progress_mode' => 'auto_rollup',
                'rollup_source' => 'meetings',
                'rollup_metric' => 'completed_count',
                'rollup_window' => 'target_period',
                'metadata_json' => ['source' => 'onboarding_starter_kit'],
            ], [
                'surface' => 'onboarding',
                'run_id' => (string) ($launchSummary['run_id'] ?? 'starter-kit-v2'),
                'dedupe_key' => 'onboarding:starter-kit-v2:first-qualified-conversation',
                'automation_mode' => 'review',
            ]);
            $created['targets'][] = $targetPolicy->createAutomated([
                'user_id' => $userId,
                'title' => 'Create first proposal or invoice',
                'description' => 'Starter target from onboarding.',
                'target_type' => 'sales',
                'target_value' => 1,
                'current_value' => 0,
                'unit' => 'document',
                'target_date' => date('Y-m-d', strtotime('+21 days')),
                'scope' => 'personal',
                'progress_mode' => 'auto_rollup',
                'rollup_source' => 'documents',
                'rollup_metric' => 'proposal_created_count',
                'rollup_window' => 'target_period',
                'metadata_json' => ['source' => 'onboarding_starter_kit'],
            ], [
                'surface' => 'onboarding',
                'run_id' => (string) ($launchSummary['run_id'] ?? 'starter-kit-v2'),
                'dedupe_key' => 'onboarding:starter-kit-v2:first-proposal-or-invoice',
                'automation_mode' => 'review',
            ]);
        } catch (\Throwable $e) {
            $created['targets_error'] = $e->getMessage();
        }

        try {
            $seededTemplates = (new WorkspaceEmailTemplateSeederService())->seed($workspaceId, $userId, [
                'tone' => (string) ($launchSummary['voice']['tone'] ?? 'consultative'),
                'offer' => $offer,
            ]);
            $created['templates'] = (array) ($seededTemplates['template_ids'] ?? []);
            $created['templates_seeded'] = $seededTemplates;
        } catch (\Throwable $e) {
            $created['templates_error'] = $e->getMessage();
        }

        try {
            $workflowTemplates = new WorkflowTemplates();
            $workflowPayload = $this->buildRecommendedWorkflows($userId);
            foreach ((array) ($workflowPayload['recommendations'] ?? []) as $recommendation) {
                $templateId = (int) ($recommendation['template_id'] ?? 0);
                if ($templateId <= 0) {
                    continue;
                }
                $name = trim((string) ($recommendation['name'] ?? 'Starter workflow'));
                $created['workflows'][] = $workflowTemplates->createFromTemplate(
                    $templateId,
                    'Starter: ' . ($name !== '' ? $name : 'Recommended workflow'),
                    [
                        'company_name' => $company,
                        'offer_name' => $offer,
                        'ideal_customer' => $idealCustomer,
                    ],
                    $userId
                );
            }
        } catch (\Throwable $e) {
            $created['workflows_error'] = $e->getMessage();
        }

        $created['created'] = true;
        $created['created_at'] = gmdate('c');
        return $created;
    }

    private function saveWorkflowMode(string $mode, int $userId): void
    {
        try {
            (new WorkflowAutomationControlService())->saveWorkspaceControl([
                'autonomy_mode' => $mode,
                'promotion_status' => $mode,
                'policy_learning_enabled' => true,
                'review_ui_enabled' => $mode === 'suggest_only',
                'metadata' => ['updated_by' => 'onboarding'],
            ], $userId);
        } catch (\Throwable $e) {
        }
    }

    private function applyAutoresponder(string $mode, bool $bestPractices): void
    {
        (new AIAutoResponderConfig())->save([
            'enabled' => $mode !== 'off',
            'mode' => $mode,
            'default_confidence_threshold' => 0.85,
            'safety' => [
                'forbid_hallucinations' => true,
                'require_human_for_sensitive_intents' => true,
            ],
        ]);
    }

    private function applyCommercialLayer(bool $enabled): void
    {
        (new CommercialAutomationConfig())->save([
            'enabled' => $enabled,
            'mode' => $enabled ? 'auto_safe' : 'suggest_only',
        ]);
    }

    private function applyDealAutomation(bool $enabled, string $launchMode): void
    {
        (new DealAutomationConfig())->save([
            'enabled' => $enabled,
            'mode' => $enabled ? ($launchMode === 'full_auto' ? 'full_auto' : 'auto_safe') : 'suggest_only',
            'min_confidence' => 0.85,
            'lookback_days' => 14,
            'cooldown_hours' => 24,
            'require_approval_terminal' => true,
            'min_terminal_confidence' => 0.92,
            'inactivity_days_for_loss' => 14,
            'reopen_lost_on_reengagement' => true,
            'auto_create_from_inbound' => $enabled,
            'auto_create_require_non_negative' => true,
            'auto_create_require_meaningful_reply' => true,
            'auto_create_channels' => ['email' => true, 'whatsapp' => true, 'sms' => false],
            'inbox_triage_enabled' => $enabled,
            'inbox_triage_auto_apply' => $enabled,
            'inbox_triage_channels' => ['email' => true, 'whatsapp' => true],
            'transitions' => (new DealAutomationConfig())->get()['transitions'] ?? [],
        ]);
    }

    private function loadRow(int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return null;
        }
        return Database::queryOne("SELECT * FROM workspace_onboarding_state WHERE workspace_id = ? LIMIT 1", [$workspaceId]) ?: null;
    }

    private function decodeList(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : []);
        return array_values(array_filter(array_map('strval', is_array($decoded) ? $decoded : [])));
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function firstIncompleteStep(array $completedSteps): int
    {
        foreach (self::REQUIRED_STEPS as $index => $key) {
            if (!in_array($key, $completedSteps, true)) {
                return $index + 1;
            }
        }
        return self::STEP_COUNT;
    }

    private function requiredComplete(array $completedSteps): bool
    {
        return count(array_intersect(self::REQUIRED_STEPS, $completedSteps)) === count(self::REQUIRED_STEPS);
    }

    private function ensureOperatingBrief(int $workspaceId, int $userId): void
    {
        try {
            $briefs = new WorkspaceOperatingBriefService();
            $latest = $briefs->latest($workspaceId);
            if (!is_array($latest) || trim((string) ($latest['markdown'] ?? '')) === '') {
                $briefs->generate($workspaceId, $userId);
            }
        } catch (\Throwable $e) {
            error_log('Workspace onboarding operating brief generation failed: ' . $e->getMessage());
        }
    }

    private function normalizeChannel(string $channel): ?string
    {
        return in_array($channel, ['email', 'whatsapp', 'both'], true) ? $channel : null;
    }

    private function normalizeLaunchMode(string $mode): string
    {
        return in_array($mode, ['learning_on_the_go', 'manual_review', 'full_auto'], true) ? $mode : 'learning_on_the_go';
    }

    private function normalizeTechnicalLevel(string $level): string
    {
        return in_array($level, ['guide_me', 'work_with_me', 'run_quietly'], true) ? $level : 'work_with_me';
    }

    private function launchModeForTechnicalLevel(string $level): string
    {
        return match ($this->normalizeTechnicalLevel($level)) {
            'guide_me' => 'manual_review',
            'run_quietly' => 'full_auto',
            default => 'learning_on_the_go',
        };
    }

    private function autoresponderModeForTechnicalLevel(string $level): string
    {
        return match ($this->normalizeTechnicalLevel($level)) {
            'guide_me' => 'draft_only',
            'run_quietly' => 'hybrid',
            default => 'hybrid',
        };
    }

    private function normalizeAutoresponderMode(string $mode): string
    {
        return in_array($mode, ['off', 'draft_only', 'hybrid', 'full_auto'], true) ? $mode : 'draft_only';
    }

    private function normalizeTonePreset(string $tone): string
    {
        return in_array($tone, ['professional', 'warm', 'consultative', 'direct', 'friendly'], true) ? $tone : 'consultative';
    }

    private function normalizeCtaStyle(string $style): string
    {
        return in_array($style, ['soft', 'clear', 'direct'], true) ? $style : 'clear';
    }

    private function normalizeFormality(string $level): string
    {
        return in_array($level, ['formal', 'balanced', 'casual'], true) ? $level : 'balanced';
    }

    private function normalizeReadingLevel(string $level): string
    {
        return (new WorkspaceLanguageLevelService())->normalize($level);
    }

    private function normalizeRelationshipStyle(string $style): string
    {
        return in_array($style, ['trusted_advisor', 'friendly_operator', 'direct_expert', 'premium_concierge'], true) ? $style : 'trusted_advisor';
    }

    private function hasConnectedEmail(int $workspaceId): bool
    {
        try {
            $email = new EmailIntegrationService();
            if ($email->isStrictRoleOutboundReady('outreach', $workspaceId) || $email->isStrictRoleOutboundReady('nurture', $workspaceId)) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    private function hasConnectedWhatsApp(int $workspaceId): bool
    {
        try {
            if ((new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId) !== null) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        try {
            $assistant = (new WorkspaceAssistantConfigService())->whatsappRuntimeConfig($workspaceId);
            if (!empty($assistant['enabled']) && (trim((string) ($assistant['assistant_phone_number'] ?? '')) !== '' || trim((string) ($assistant['assistant_phone_number_id'] ?? '')) !== '')) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        return trim((string) ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '')) !== ''
            && trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')) !== '';
    }

    private function isAllowedPage(string $page): bool
    {
        return in_array($page, [
            'onboarding.php',
            'settings.php',
            'workspace_skills.php',
            'billing_payment_required.php',
            'billing_start_payment.php',
            'billing_callback.php',
            'workspaces.php',
            'workspace_admin.php',
            'workspace_provision.php',
            'owner_support.php',
            'owner_support_admin.php',
            'owner_help_expert.php',
            'owner_help_experts_admin.php',
            'workspace_delete.php',
            'workspace_delete_2fa.php',
            'logout.php',
            'login.php',
            'login_2fa.php',
            'verify_email.php',
            'workspace_switch.php',
        ], true);
    }
}
