<?php

namespace CRM\Services;

use CRM\Database;
use RuntimeException;
use Throwable;

class SmartTemplateGenerationService
{
    public const EMAIL_TEMPLATE_KEYS = [
        'lead_intro',
        'demo_or_discovery_invite',
        'proposal_follow_up',
        're_engagement',
        'post_win_onboarding',
    ];

    public const WORKFLOW_TEMPLATE_KEYS = [
        'new_lead_nurture',
        'demo_follow_up',
        'proposal_stall_recovery',
        'silent_lead_reengagement',
        'closed_won_onboarding',
    ];

    private AIService $aiService;
    private SmartTemplateContextService $contextService;
    private EmailTemplateLearningReadinessService $learningReadinessService;
    private WorkspaceScopeService $workspaceScope;

    public function __construct(
        ?AIService $aiService = null,
        ?SmartTemplateContextService $contextService = null,
        ?EmailTemplateLearningReadinessService $learningReadinessService = null,
        ?WorkspaceScopeService $workspaceScope = null
    ) {
        $this->aiService = $aiService ?? new AIService();
        $this->contextService = $contextService ?? new SmartTemplateContextService();
        $this->learningReadinessService = $learningReadinessService ?? new EmailTemplateLearningReadinessService();
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function getStatus(int $userId): array
    {
        $profileReadiness = $this->contextService->getReadiness($userId);
        $learningReadiness = $this->learningReadinessService->getReadiness($userId, $profileReadiness);
        $readiness = $this->combinedReadiness($profileReadiness, $learningReadiness);
        $workspaceId = $this->workspaceId();
        $activeSet = $this->loadLatestSet($userId, $workspaceId, 'active');
        $candidateSet = $this->loadLatestSet($userId, $workspaceId, 'candidate');
        $refreshDue = $this->learningReadinessService->isRefreshDue($activeSet, $learningReadiness);
        $learningState = !empty($activeSet)
            ? ($refreshDue ? 'refresh_due' : 'active')
            : (string) ($learningReadiness['state'] ?? 'learning');

        return [
            'is_ready' => $readiness['is_ready'],
            'learning_state' => $learningState,
            'readiness' => $readiness,
            'profile_readiness' => $profileReadiness,
            'learning_readiness' => $learningReadiness,
            'has_active_set' => $activeSet !== null,
            'active_set' => $activeSet ? $this->serializeSet($activeSet) : null,
            'has_candidate_set' => $candidateSet !== null,
            'candidate_set' => $candidateSet ? $this->serializeSet($candidateSet) : null,
            'refresh_due' => $refreshDue,
            'can_generate' => $readiness['is_ready'] && $candidateSet === null,
            'can_regenerate' => $readiness['is_ready'] && $activeSet !== null && $candidateSet === null,
        ];
    }

    public function generateForUser(int $userId, array $options = []): array
    {
        $status = $this->getStatus($userId);
        $readiness = $status['readiness'];
        $learningReadiness = (array) ($status['learning_readiness'] ?? []);

        if (!$readiness['is_ready']) {
            $labels = array_map(
                static fn(array $item): string => $item['label'],
                $readiness['missing_requirements']
            );

            throw new RuntimeException(
                'Smart templates require more context before generation. Missing: ' . implode(', ', $labels)
            );
        }

        if (!empty($status['candidate_set']) && empty($options['allow_existing_candidate'])) {
            throw new RuntimeException('A learned template candidate pack is already waiting for review.');
        }

        $asCandidate = array_key_exists('as_candidate', $options)
            ? (bool) $options['as_candidate']
            : true;
        if (!empty($options['force_activate'])) {
            $asCandidate = false;
        }
        $defaultGenerationMode = $asCandidate ? 'manual_candidate' : 'manual';
        $defaultGeneratedReason = $asCandidate
            ? (!empty($status['has_active_set']) ? 'manual_refresh_candidate' : 'initial_candidate_generation')
            : 'initial_generation';

        $learningBlock = $this->learningReadinessService->buildPromptBlock($userId, $learningReadiness);
        $emailBundle = $this->contextService->buildContextBundle($userId, 'email_pack_generation', $learningBlock);
        $workflowBundle = $this->contextService->buildContextBundle($userId, 'workflow_pack_generation', $learningBlock);
        $snapshot = $readiness['context_snapshot'];

        $emailDefinitions = $this->generateEmailDefinitions($emailBundle, $snapshot);
        $workflowDefinitions = $this->generateWorkflowDefinitions($workflowBundle, $snapshot);
        $previousActiveSetId = (int) ($status['active_set']['id'] ?? 0);
        $setStatus = $asCandidate ? 'candidate' : 'active';
        $templatesActive = !$asCandidate;

        $transactionStarted = false;

        try {
            Database::beginTransaction();
            $transactionStarted = true;

            Database::execute(
                "INSERT INTO smart_template_sets (
                    workspace_id,
                    user_id,
                    status,
                    generation_mode,
                    learning_state,
                    context_hash,
                    learning_hash,
                    context_snapshot_json,
                    metrics_snapshot_json,
                    generated_reason,
                    refresh_due_at,
                    activated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)",
                [
                    $this->workspaceId(),
                    $userId,
                    $setStatus,
                    (string) ($options['generation_mode'] ?? $defaultGenerationMode),
                    (string) ($status['learning_state'] ?? 'ready'),
                    $readiness['context_hash'],
                    (string) ($learningReadiness['learning_hash'] ?? ''),
                    json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    json_encode($learningReadiness['metrics_snapshot'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) ($options['generated_reason'] ?? $defaultGeneratedReason),
                    EmailTemplateLearningReadinessService::REFRESH_DAYS,
                    $asCandidate ? null : date('Y-m-d H:i:s'),
                ]
            );

            $smartTemplateSetId = (int) Database::lastInsertId();
            $emailTemplateIds = $this->insertEmailTemplates($smartTemplateSetId, $userId, $emailDefinitions, $templatesActive);
            $this->insertWorkflowTemplates($smartTemplateSetId, $userId, $workflowDefinitions, $emailTemplateIds, $templatesActive);

            if (!$asCandidate && $previousActiveSetId > 0) {
                $this->archiveTemplateSet($previousActiveSetId);
            }

            Database::commit();

            return [
                'success' => true,
                'smart_template_set_id' => $smartTemplateSetId,
                'status' => $setStatus,
                'candidate' => $asCandidate,
                'archived_previous_set_id' => (!$asCandidate && $previousActiveSetId > 0) ? $previousActiveSetId : null,
                'email_template_count' => count($emailDefinitions),
                'workflow_template_count' => count($workflowDefinitions),
                'email_template_ids' => $emailTemplateIds,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                Database::rollBack();
            }

            throw $e;
        }
    }

    public function generateCandidateForUser(int $userId, string $reason = 'periodic_refresh'): array
    {
        return $this->generateForUser($userId, [
            'as_candidate' => true,
            'generation_mode' => 'scheduled_candidate',
            'generated_reason' => $reason,
        ]);
    }

    public function approveCandidate(int $userId, int $smartTemplateSetId): array
    {
        $workspaceId = $this->workspaceId();
        $candidate = Database::queryOne(
            "SELECT * FROM smart_template_sets
             WHERE id = ? AND user_id = ? AND workspace_id = ? AND status = 'candidate'
             LIMIT 1",
            [$smartTemplateSetId, $userId, $workspaceId]
        );
        if (!$candidate) {
            throw new RuntimeException('Candidate template pack not found.');
        }

        $previousActiveSet = $this->loadLatestSet($userId, $workspaceId, 'active');
        $previousActiveSetId = (int) ($previousActiveSet['id'] ?? 0);
        $transactionStarted = false;

        try {
            Database::beginTransaction();
            $transactionStarted = true;

            if ($previousActiveSetId > 0) {
                $this->archiveTemplateSet($previousActiveSetId);
            }

            Database::execute(
                "UPDATE smart_template_sets
                 SET status = 'active', reviewed_at = NOW(), reviewed_by = ?, activated_at = NOW()
                 WHERE id = ? AND user_id = ? AND workspace_id = ? AND status = 'candidate'",
                [$userId, $smartTemplateSetId, $userId, $workspaceId]
            );
            Database::execute(
                "UPDATE email_templates
                 SET is_active = 1
                 WHERE smart_template_set_id = ? AND is_ai_generated = 1 AND workspace_id = ?",
                [$smartTemplateSetId, $workspaceId]
            );
            Database::execute(
                "UPDATE workflow_templates
                 SET is_active = 1
                 WHERE smart_template_set_id = ? AND is_ai_generated = 1",
                [$smartTemplateSetId]
            );

            Database::commit();

            return [
                'success' => true,
                'smart_template_set_id' => $smartTemplateSetId,
                'archived_previous_set_id' => $previousActiveSetId > 0 ? $previousActiveSetId : null,
            ];
        } catch (Throwable $e) {
            if ($transactionStarted) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public function rejectCandidate(int $userId, int $smartTemplateSetId): array
    {
        $workspaceId = $this->workspaceId();
        Database::execute(
            "UPDATE smart_template_sets
             SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejected_at = NOW()
             WHERE id = ? AND user_id = ? AND workspace_id = ? AND status = 'candidate'",
            [$userId, $smartTemplateSetId, $userId, $workspaceId]
        );
        Database::execute(
            "UPDATE email_templates SET is_active = 0 WHERE smart_template_set_id = ? AND is_ai_generated = 1",
            [$smartTemplateSetId]
        );
        Database::execute(
            "UPDATE workflow_templates SET is_active = 0 WHERE smart_template_set_id = ? AND is_ai_generated = 1",
            [$smartTemplateSetId]
        );

        return [
            'success' => true,
            'smart_template_set_id' => $smartTemplateSetId,
        ];
    }

    private function combinedReadiness(array $profileReadiness, array $learningReadiness): array
    {
        $snapshot = (array) ($profileReadiness['context_snapshot'] ?? []);
        $snapshot['email_learning'] = $learningReadiness['metrics_snapshot'] ?? [];
        $contextHash = hash(
            'sha256',
            json_encode([
                'profile' => $profileReadiness['context_hash'] ?? '',
                'learning' => $learningReadiness['learning_hash'] ?? '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'is_ready' => !empty($profileReadiness['is_ready']) && !empty($learningReadiness['is_ready']),
            'state' => (string) ($learningReadiness['state'] ?? 'learning'),
            'sections' => (array) ($profileReadiness['sections'] ?? []),
            'missing_requirements' => array_values((array) ($learningReadiness['missing_requirements'] ?? [])),
            'context_snapshot' => $snapshot,
            'context_hash' => $contextHash,
            'learning_hash' => (string) ($learningReadiness['learning_hash'] ?? ''),
        ];
    }

    private function loadLatestSet(int $userId, int $workspaceId, string $status): ?array
    {
        return Database::queryOne(
            "SELECT sts.*,
                    (SELECT COUNT(*) FROM email_templates et WHERE et.smart_template_set_id = sts.id AND et.is_ai_generated = 1) AS email_template_count,
                    (SELECT COUNT(*) FROM workflow_templates wt WHERE wt.smart_template_set_id = sts.id AND wt.is_ai_generated = 1) AS workflow_template_count
             FROM smart_template_sets sts
             WHERE sts.user_id = ? AND sts.workspace_id = ? AND sts.status = ?
             ORDER BY sts.id DESC
             LIMIT 1",
            [$userId, $workspaceId, $status]
        );
    }

    private function serializeSet(array $set): array
    {
        return [
            'id' => (int) ($set['id'] ?? 0),
            'status' => (string) ($set['status'] ?? ''),
            'generation_mode' => (string) ($set['generation_mode'] ?? ''),
            'learning_state' => (string) ($set['learning_state'] ?? ''),
            'context_hash' => (string) ($set['context_hash'] ?? ''),
            'learning_hash' => (string) ($set['learning_hash'] ?? ''),
            'email_template_count' => (int) ($set['email_template_count'] ?? 0),
            'workflow_template_count' => (int) ($set['workflow_template_count'] ?? 0),
            'generated_reason' => (string) ($set['generated_reason'] ?? ''),
            'refresh_due_at' => (string) ($set['refresh_due_at'] ?? ''),
            'created_at' => (string) ($set['created_at'] ?? ''),
            'updated_at' => (string) ($set['updated_at'] ?? ''),
        ];
    }

    private function archiveTemplateSet(int $smartTemplateSetId): void
    {
        Database::execute(
            "UPDATE smart_template_sets SET status = 'archived' WHERE id = ?",
            [$smartTemplateSetId]
        );
        Database::execute(
            "UPDATE email_templates
             SET is_active = 0
             WHERE smart_template_set_id = ? AND is_ai_generated = 1",
            [$smartTemplateSetId]
        );
        Database::execute(
            "UPDATE workflow_templates
             SET is_active = 0
             WHERE smart_template_set_id = ? AND is_ai_generated = 1",
            [$smartTemplateSetId]
        );
    }

    private function insertEmailTemplates(int $smartTemplateSetId, int $userId, array $definitions, bool $isActive = true): array
    {
        $ids = [];

        foreach ($definitions as $definition) {
            Database::execute(
                "INSERT INTO email_templates (
                    workspace_id,
                    name,
                    slug,
                    subject,
                    body_html,
                    body_text,
                    category,
                    variables,
                    is_active,
                    created_by,
                    is_library,
                    description,
                    smart_template_set_id,
                    is_ai_generated,
                    template_key,
                    match_metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, ?)",
                [
                    $this->workspaceId(),
                    $definition['name'],
                    $this->buildEmailSlug($definition['template_key'], $userId, $smartTemplateSetId),
                    $definition['subject'],
                    $definition['body_html'],
                    $definition['body_text'],
                    $definition['category'],
                    json_encode(array_values($definition['variables'])),
                    $isActive ? 1 : 0,
                    $userId,
                    $definition['description'],
                    $smartTemplateSetId,
                    $definition['template_key'],
                    json_encode($definition['match_metadata_json'], JSON_UNESCAPED_SLASHES),
                ]
            );

            $ids[$definition['template_key']] = (int) Database::lastInsertId();
        }

        return $ids;
    }

    private function insertWorkflowTemplates(
        int $smartTemplateSetId,
        int $userId,
        array $definitions,
        array $emailTemplateIds,
        bool $isActive = true
    ): void {
        foreach ($definitions as $definition) {
            $actions = [];
            foreach ($definition['actions'] as $action) {
                if (($action['type'] ?? '') === 'send_email') {
                    $emailTemplateKey = (string) ($action['email_template_key'] ?? $action['preferred_template_key'] ?? '');
                    if ($emailTemplateKey !== '' && !isset($emailTemplateIds[$emailTemplateKey])) {
                        throw new RuntimeException(
                            'Workflow template references unknown email template key: ' . $emailTemplateKey
                        );
                    }
                    unset($action['email_template_key']);
                    unset($action['preferred_template_key']);
                    if (!isset($action['template_query']) || !is_array($action['template_query'])) {
                        $action['template_query'] = $this->templateQueryForEmailKey(
                            $emailTemplateKey ?: $this->defaultEmailKeyForWorkflow((string) $definition['template_key']),
                            (string) $definition['template_key']
                        );
                    } elseif ($emailTemplateKey !== '' && empty($action['template_query']['preferred_template_key'])) {
                        $action['template_query']['preferred_template_key'] = $emailTemplateKey;
                    }
                }
                $actions[] = $action;
            }

            Database::execute(
                "INSERT INTO workflow_templates (
                    name,
                    description,
                    category,
                    trigger_config,
                    conditions,
                    actions,
                    variables,
                    is_public,
                    usage_count,
                    created_by,
                    is_active,
                    smart_template_set_id,
                    is_ai_generated,
                    template_key,
                    recipe_metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 1, ?, ?)",
                [
                    $definition['name'],
                    $definition['description'],
                    $definition['category'],
                    json_encode($definition['trigger_config'], JSON_UNESCAPED_SLASHES),
                    json_encode($definition['conditions'], JSON_UNESCAPED_SLASHES),
                    json_encode($actions, JSON_UNESCAPED_SLASHES),
                    json_encode($definition['variables'], JSON_UNESCAPED_SLASHES),
                    $userId,
                    $isActive ? 1 : 0,
                    $smartTemplateSetId,
                    $definition['template_key'],
                    json_encode($definition['recipe_metadata_json'], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function generateEmailDefinitions(array $bundle, array $snapshot): array
    {
        $payload = $this->requestPack(
            'smart_email_template_pack',
            'email_pack_generation',
            $bundle,
            ['required_template_keys' => self::EMAIL_TEMPLATE_KEYS]
        );

        if (is_array($payload) && $this->isValidEmailPack($payload)) {
            return $this->normalizeEmailPack($payload['emails']);
        }

        throw new RuntimeException('AI email template pack did not meet the learned-template quality contract.');
    }

    private function generateWorkflowDefinitions(array $bundle, array $snapshot): array
    {
        $payload = $this->requestPack(
            'smart_workflow_template_pack',
            'workflow_pack_generation',
            $bundle,
            [
                'required_template_keys' => self::WORKFLOW_TEMPLATE_KEYS,
                'required_email_template_keys' => self::EMAIL_TEMPLATE_KEYS,
            ]
        );

        if (is_array($payload) && $this->isValidWorkflowPack($payload)) {
            return $this->normalizeWorkflowPack($payload['workflows']);
        }

        throw new RuntimeException('AI workflow template pack did not meet the learned-template quality contract.');
    }

    private function requestPack(string $task, string $promptKey, array $bundle, array $inputs): ?array
    {
        $resolvedPrompt = $this->aiService->buildPromptFromRegistry(
            'smart_templates',
            $promptKey,
            $bundle,
            $inputs
        );
        $response = trim($this->aiService->processWithPrompt($task, $resolvedPrompt));

        if ($response === '') {
            return null;
        }

        return $this->decodeJsonPayload($response);
    }

    private function decodeJsonPayload(string $response): ?array
    {
        $trimmed = trim($response);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');
        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isValidEmailPack(array $payload): bool
    {
        if (!isset($payload['emails']) || !is_array($payload['emails'])) {
            return false;
        }

        $keys = array_map(
            static fn(array $item): string => (string) ($item['template_key'] ?? ''),
            $payload['emails']
        );

        sort($keys);
        $required = self::EMAIL_TEMPLATE_KEYS;
        sort($required);

        if ($keys !== $required) {
            return false;
        }

        foreach ($payload['emails'] as $email) {
            if (!$this->hasUsableEmailTemplateShape($email)) {
                return false;
            }
        }

        return true;
    }

    private function isValidWorkflowPack(array $payload): bool
    {
        if (!isset($payload['workflows']) || !is_array($payload['workflows'])) {
            return false;
        }

        $keys = array_map(
            static fn(array $item): string => (string) ($item['template_key'] ?? ''),
            $payload['workflows']
        );

        sort($keys);
        $required = self::WORKFLOW_TEMPLATE_KEYS;
        sort($required);

        return $keys === $required;
    }

    private function normalizeEmailPack(array $emails): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            $normalized[] = [
                'template_key' => (string) ($email['template_key'] ?? ''),
                'name' => trim((string) ($email['name'] ?? 'Untitled Smart Template')),
                'description' => trim((string) ($email['description'] ?? 'AI-generated smart template')),
                'category' => trim((string) ($email['category'] ?? 'general')) ?: 'general',
                'subject' => trim((string) ($email['subject'] ?? '')),
                'body_html' => trim((string) ($email['body_html'] ?? '')),
                'body_text' => trim((string) ($email['body_text'] ?? '')),
                'variables' => array_values(array_filter((array) ($email['variables'] ?? []))),
                'match_metadata_json' => $this->normalizeEmailMatchMetadata(
                    (string) ($email['template_key'] ?? ''),
                    is_array($email['match_metadata_json'] ?? null) ? $email['match_metadata_json'] : []
                ),
            ];
        }

        usort(
            $normalized,
            static fn(array $left, array $right): int => array_search($left['template_key'], self::EMAIL_TEMPLATE_KEYS, true)
                <=> array_search($right['template_key'], self::EMAIL_TEMPLATE_KEYS, true)
        );

        return $normalized;
    }

    private function hasUsableEmailTemplateShape(array $email): bool
    {
        foreach (['template_key', 'name', 'description', 'category', 'subject', 'body_html', 'body_text'] as $field) {
            if (trim((string) ($email[$field] ?? '')) === '') {
                return false;
            }
        }

        if (!in_array((string) ($email['template_key'] ?? ''), self::EMAIL_TEMPLATE_KEYS, true)) {
            return false;
        }

        if (!is_array($email['variables'] ?? null)) {
            return false;
        }

        $combinedBody = trim(strip_tags((string) ($email['body_html'] ?? '')) . ' ' . (string) ($email['body_text'] ?? ''));
        return strlen($combinedBody) >= 80;
    }

    private function normalizeWorkflowPack(array $workflows): array
    {
        $normalized = [];

        foreach ($workflows as $workflow) {
            $normalized[] = [
                'template_key' => (string) ($workflow['template_key'] ?? ''),
                'name' => trim((string) ($workflow['name'] ?? 'Untitled Smart Workflow')),
                'description' => trim((string) ($workflow['description'] ?? 'AI-generated smart workflow')),
                'category' => trim((string) ($workflow['category'] ?? 'general')) ?: 'general',
                'trigger_config' => is_array($workflow['trigger_config'] ?? null) ? $workflow['trigger_config'] : ['type' => 'contact_created'],
                'conditions' => is_array($workflow['conditions'] ?? null) ? $workflow['conditions'] : [],
                'actions' => is_array($workflow['actions'] ?? null) ? $workflow['actions'] : [],
                'variables' => array_values(array_filter((array) ($workflow['variables'] ?? []))),
                'recipe_metadata_json' => $this->normalizeWorkflowRecipeMetadata(
                    (string) ($workflow['template_key'] ?? ''),
                    is_array($workflow['recipe_metadata_json'] ?? null) ? $workflow['recipe_metadata_json'] : []
                ),
            ];
        }

        usort(
            $normalized,
            static fn(array $left, array $right): int => array_search($left['template_key'], self::WORKFLOW_TEMPLATE_KEYS, true)
                <=> array_search($right['template_key'], self::WORKFLOW_TEMPLATE_KEYS, true)
        );

        return $normalized;
    }

    private function fallbackEmailPack(array $snapshot): array
    {
        $company = $snapshot['company_profile'];
        $strategy = $snapshot['strategy_profile'];
        $idea = $snapshot['idea_validation'];

        $companyName = $company['company_name'] ?: 'our team';
        $industry = $company['company_industry'] ?: $company['icp_industries'];
        $icp = $strategy['ideal_customer_profile'] ?: $strategy['target_market_focus'];
        $offerAngle = $strategy['offer_angle'] ?: $idea['value_proposition'];
        $salesMotion = $strategy['sales_motion'] ?: 'consultative follow-up';
        $painPoints = $idea['pain_points'] ?: $company['icp_pain_points'];
        $differentiator = $idea['differentiator'] ?: $strategy['positioning_notes'];
        $targetMarket = $idea['target_market'] ?: $strategy['target_market_focus'];

        return [
            [
                'template_key' => 'lead_intro',
                'name' => 'Smart Lead Intro',
                'description' => 'Intro email tailored to your offer angle and target market.',
                'category' => 'sales',
                'subject' => 'A practical idea for {company}',
                'body_text' => implode("\n\n", [
                    'Hi {first_name},',
                    "I'm reaching out from {$companyName}. We help {$targetMarket} teams move faster on {$painPoints}.",
                    "The reason I thought of you is that many {$icp} teams are looking for {$offerAngle}. {$differentiator}",
                    "If it's helpful, I can share two concrete ways we'd approach this for {company}.",
                    'Best,',
                    '{sender_name}',
                ]),
                'body_html' => $this->wrapHtml([
                    'Hi {first_name},',
                    "I'm reaching out from {$companyName}. We help {$targetMarket} teams move faster on {$painPoints}.",
                    "The reason I thought of you is that many {$icp} teams are looking for {$offerAngle}. {$differentiator}",
                    "If it's helpful, I can share two concrete ways we'd approach this for {company}.",
                    "Best,\n{sender_name}",
                ]),
                'variables' => ['first_name', 'company', 'sender_name'],
            ],
            [
                'template_key' => 'demo_or_discovery_invite',
                'name' => 'Smart Demo Or Discovery Invite',
                'description' => 'Invite prospects into a consultative discovery or demo call.',
                'category' => 'sales',
                'subject' => 'Would a short discovery call help, {first_name}?',
                'body_text' => implode("\n\n", [
                    'Hi {first_name},',
                    "Based on what we're seeing in {$industry}, teams like {company} usually want a practical view of what {$companyName} would look like in their workflow.",
                    "In a short call, we can cover your current motion, where {$offerAngle} fits, and whether there's a fast win worth testing.",
                    'If you want to explore it, you can grab a time here: {meeting_link}.',
                    'Best,',
                    '{sender_name}',
                ]),
                'body_html' => $this->wrapHtml([
                    'Hi {first_name},',
                    "Based on what we're seeing in {$industry}, teams like {company} usually want a practical view of what {$companyName} would look like in their workflow.",
                    "In a short call, we can cover your current motion, where {$offerAngle} fits, and whether there's a fast win worth testing.",
                    'If you want to explore it, you can grab a time here: {meeting_link}.',
                    "Best,\n{sender_name}",
                ]),
                'variables' => ['first_name', 'company', 'meeting_link', 'sender_name'],
            ],
            [
                'template_key' => 'proposal_follow_up',
                'name' => 'Smart Proposal Follow Up',
                'description' => 'Follow-up email for proposals that need a decision push.',
                'category' => 'follow_up',
                'subject' => 'Quick follow-up on the proposal for {company}',
                'body_text' => implode("\n\n", [
                    'Hi {first_name},',
                    "I wanted to follow up on the proposal we shared for {company}. The main outcome we designed for is {$offerAngle}.",
                    "If anything in the plan needs to be adjusted around timing, scope, or rollout, I'm happy to tighten it up.",
                    "A quick call can also help us unblock any concerns and keep momentum in your {$salesMotion}.",
                    'Best,',
                    '{sender_name}',
                ]),
                'body_html' => $this->wrapHtml([
                    'Hi {first_name},',
                    "I wanted to follow up on the proposal we shared for {company}. The main outcome we designed for is {$offerAngle}.",
                    "If anything in the plan needs to be adjusted around timing, scope, or rollout, I'm happy to tighten it up.",
                    "A quick call can also help us unblock any concerns and keep momentum in your {$salesMotion}.",
                    "Best,\n{sender_name}",
                ]),
                'variables' => ['first_name', 'company', 'sender_name'],
            ],
            [
                'template_key' => 're_engagement',
                'name' => 'Smart Re-Engagement',
                'description' => 'Re-engagement email for quiet leads or stalled conversations.',
                'category' => 're_engagement',
                'subject' => 'Still exploring this for {company}?',
                'body_text' => implode("\n\n", [
                    'Hi {first_name},',
                    "Circling back in case improving {$painPoints} is still on your list for {company}.",
                    "Since we last spoke, we've kept refining how {$companyName} helps {$targetMarket} teams create momentum without adding operational drag.",
                    "If priorities changed, no problem. If it's still relevant, I can resend the most useful next step.",
                    'Best,',
                    '{sender_name}',
                ]),
                'body_html' => $this->wrapHtml([
                    'Hi {first_name},',
                    "Circling back in case improving {$painPoints} is still on your list for {company}.",
                    "Since we last spoke, we've kept refining how {$companyName} helps {$targetMarket} teams create momentum without adding operational drag.",
                    "If priorities changed, no problem. If it's still relevant, I can resend the most useful next step.",
                    "Best,\n{sender_name}",
                ]),
                'variables' => ['first_name', 'company', 'sender_name'],
            ],
            [
                'template_key' => 'post_win_onboarding',
                'name' => 'Smart Post-Win Onboarding',
                'description' => 'Welcome and onboarding email for newly won customers.',
                'category' => 'onboarding',
                'subject' => 'Welcome aboard, {first_name}',
                'body_text' => implode("\n\n", [
                    'Hi {first_name},',
                    "We're excited to get started with {company}. Our goal is to make your rollout of {$companyName} clear, fast, and practical.",
                    "We'll start with the priorities that matter most to {$targetMarket} teams like yours, especially around {$offerAngle}.",
                    "Here's the first next step: {meeting_link}",
                    'Looking forward to working together,',
                    '{sender_name}',
                ]),
                'body_html' => $this->wrapHtml([
                    'Hi {first_name},',
                    "We're excited to get started with {company}. Our goal is to make your rollout of {$companyName} clear, fast, and practical.",
                    "We'll start with the priorities that matter most to {$targetMarket} teams like yours, especially around {$offerAngle}.",
                    "Here's the first next step: {meeting_link}",
                    "Looking forward to working together,\n{sender_name}",
                ]),
                'variables' => ['first_name', 'company', 'meeting_link', 'sender_name'],
            ],
        ];
    }

    private function fallbackWorkflowPack(array $snapshot): array
    {
        $strategy = $snapshot['strategy_profile'];
        $idea = $snapshot['idea_validation'];
        $motion = $strategy['sales_motion'] ?: 'sales process';
        $painPoints = $idea['pain_points'] ?: 'the core commercial bottleneck';

        return [
            [
                'template_key' => 'new_lead_nurture',
                'name' => 'Smart New Lead Nurture',
                'description' => 'Start every new lead with an intro email and a follow-up reminder.',
                'category' => 'nurturing',
                'trigger_config' => ['type' => 'contact_created'],
                'conditions' => [
                    'field' => 'stage',
                    'operator' => 'equals',
                    'value' => 'new',
                ],
                'actions' => [
                    ['type' => 'send_email', 'email_template_key' => 'lead_intro'],
                    ['type' => 'wait_for_days', 'days' => 3],
                    ['type' => 'create_task', 'title' => 'Review new lead response', 'description' => 'Check whether the lead engaged with the smart intro email.'],
                ],
                'variables' => ['first_name', 'company'],
            ],
            [
                'template_key' => 'demo_follow_up',
                'name' => 'Smart Demo Follow Up',
                'description' => 'Keep qualified leads moving after discovery or demo conversations.',
                'category' => 'sales',
                'trigger_config' => ['type' => 'stage_changed'],
                'conditions' => [
                    'field' => 'stage',
                    'operator' => 'equals',
                    'value' => 'qualified',
                ],
                'actions' => [
                    ['type' => 'send_email', 'email_template_key' => 'demo_or_discovery_invite'],
                    ['type' => 'wait_for_days', 'days' => 2],
                    ['type' => 'create_task', 'title' => 'Follow up after discovery call invite', 'description' => 'Confirm whether the prospect booked time or needs a custom follow-up.'],
                ],
                'variables' => ['first_name', 'company', 'meeting_link'],
            ],
            [
                'template_key' => 'proposal_stall_recovery',
                'name' => 'Smart Proposal Stall Recovery',
                'description' => 'Recover stalled proposal-stage opportunities with a targeted follow-up.',
                'category' => 'sales',
                'trigger_config' => ['type' => 'no_activity_for_days', 'days' => 7],
                'conditions' => [
                    'field' => 'stage',
                    'operator' => 'equals',
                    'value' => 'proposal',
                ],
                'actions' => [
                    ['type' => 'send_email', 'email_template_key' => 'proposal_follow_up'],
                    ['type' => 'create_task', 'title' => 'Unblock stalled proposal', 'description' => 'Reach out with a concrete answer to any blockers in the proposal stage.'],
                    ['type' => 'send_in_app_notification', 'title' => 'Proposal needs attention', 'message' => 'A proposal-stage contact has been quiet for 7 days.'],
                ],
                'variables' => ['first_name', 'company'],
            ],
            [
                'template_key' => 'silent_lead_reengagement',
                'name' => 'Smart Silent Lead Re-Engagement',
                'description' => 'Re-engage leads that have gone quiet during your ' . $motion . '.',
                'category' => 're_engagement',
                'trigger_config' => ['type' => 'no_activity_for_days', 'days' => 21],
                'conditions' => [
                    'field' => 'stage',
                    'operator' => 'equals',
                    'value' => 'nurturing',
                ],
                'actions' => [
                    ['type' => 'send_email', 'email_template_key' => 're_engagement'],
                    ['type' => 'wait_for_days', 'days' => 5],
                    ['type' => 'create_task', 'title' => 'Check silent lead', 'description' => 'Decide whether the lead should stay active or move out of the current nurture path.'],
                ],
                'variables' => ['first_name', 'company'],
            ],
            [
                'template_key' => 'closed_won_onboarding',
                'name' => 'Smart Closed Won Onboarding',
                'description' => 'Kick off onboarding as soon as an opportunity is won.',
                'category' => 'onboarding',
                'trigger_config' => ['type' => 'deal_won'],
                'conditions' => [],
                'actions' => [
                    ['type' => 'send_email', 'email_template_key' => 'post_win_onboarding'],
                    ['type' => 'create_task', 'title' => 'Prepare onboarding handoff', 'description' => 'Align onboarding around ' . $painPoints . ' and the first implementation milestone.'],
                    ['type' => 'send_in_app_notification', 'title' => 'Closed-won onboarding ready', 'message' => 'A new customer is ready for onboarding handoff.'],
                ],
                'variables' => ['first_name', 'company', 'meeting_link'],
            ],
        ];
    }

    private function buildEmailSlug(string $templateKey, int $userId, int $smartTemplateSetId): string
    {
        return sprintf('smart-%s-u%d-s%d', str_replace('_', '-', $templateKey), $userId, $smartTemplateSetId);
    }

    private function normalizeEmailMatchMetadata(string $templateKey, array $metadata): array
    {
        $defaults = [
            'lead_intro' => [
                'purposes' => ['first_touch', 'lead_intro'],
                'workflow_intents' => ['new_lead_nurture'],
                'lifecycle_stages' => ['new', 'lead'],
                'audiences' => ['lead', 'prospect'],
                'tones' => ['consultative', 'helpful'],
                'required_variables' => ['first_name', 'company', 'sender_name'],
            ],
            'demo_or_discovery_invite' => [
                'purposes' => ['discovery_invite', 'demo_follow_up'],
                'workflow_intents' => ['demo_follow_up'],
                'lifecycle_stages' => ['qualified', 'discovery'],
                'audiences' => ['prospect'],
                'tones' => ['consultative', 'direct'],
                'required_variables' => ['first_name', 'company', 'meeting_link'],
            ],
            'proposal_follow_up' => [
                'purposes' => ['follow_up', 'proposal_follow_up'],
                'workflow_intents' => ['proposal_stall_recovery'],
                'lifecycle_stages' => ['proposal'],
                'audiences' => ['prospect', 'decision_maker'],
                'tones' => ['consultative', 'executive'],
                'required_variables' => ['first_name', 'company', 'sender_name'],
            ],
            're_engagement' => [
                'purposes' => ['re_engagement', 'winback'],
                'workflow_intents' => ['silent_lead_reengagement'],
                'lifecycle_stages' => ['nurturing', 'stalled'],
                'audiences' => ['lead', 'prospect'],
                'tones' => ['helpful', 'low_pressure'],
                'required_variables' => ['first_name', 'company', 'sender_name'],
            ],
            'post_win_onboarding' => [
                'purposes' => ['onboarding', 'customer_welcome'],
                'workflow_intents' => ['closed_won_onboarding'],
                'lifecycle_stages' => ['closed_won', 'customer'],
                'audiences' => ['customer'],
                'tones' => ['confident', 'welcoming'],
                'required_variables' => ['first_name', 'company', 'meeting_link'],
            ],
        ];

        return $this->mergeMetadata($defaults[$templateKey] ?? [], $metadata);
    }

    private function normalizeWorkflowRecipeMetadata(string $workflowKey, array $metadata): array
    {
        $defaults = [
            'new_lead_nurture' => [
                'intent_key' => 'new_lead_nurture',
                'expected_outcome' => 'respond quickly and create first follow-up ownership',
                'audience' => 'new lead',
                'safety_level' => 'approval_recommended',
                'recommended_template_query' => $this->templateQueryForEmailKey('lead_intro', 'new_lead_nurture'),
                'stop_conditions' => ['email_replied', 'manual_pause', 'contact_unsubscribed'],
            ],
            'demo_follow_up' => [
                'intent_key' => 'demo_follow_up',
                'expected_outcome' => 'move qualified prospects into a discovery or demo conversation',
                'audience' => 'qualified prospect',
                'safety_level' => 'approval_recommended',
                'recommended_template_query' => $this->templateQueryForEmailKey('demo_or_discovery_invite', 'demo_follow_up'),
                'stop_conditions' => ['meeting_booked', 'email_replied', 'manual_pause'],
            ],
            'proposal_stall_recovery' => [
                'intent_key' => 'proposal_stall_recovery',
                'expected_outcome' => 'restart momentum on stalled proposal-stage deals',
                'audience' => 'proposal-stage prospect',
                'safety_level' => 'approval_recommended',
                'recommended_template_query' => $this->templateQueryForEmailKey('proposal_follow_up', 'proposal_stall_recovery'),
                'stop_conditions' => ['email_replied', 'deal_won', 'deal_lost', 'manual_pause'],
            ],
            'silent_lead_reengagement' => [
                'intent_key' => 'silent_lead_reengagement',
                'expected_outcome' => 're-open quiet lead conversations without pressure',
                'audience' => 'stalled lead',
                'safety_level' => 'approval_recommended',
                'recommended_template_query' => $this->templateQueryForEmailKey('re_engagement', 'silent_lead_reengagement'),
                'stop_conditions' => ['email_replied', 'manual_pause', 'contact_unsubscribed'],
            ],
            'closed_won_onboarding' => [
                'intent_key' => 'closed_won_onboarding',
                'expected_outcome' => 'start onboarding and assign a human handoff',
                'audience' => 'new customer',
                'safety_level' => 'approval_recommended',
                'recommended_template_query' => $this->templateQueryForEmailKey('post_win_onboarding', 'closed_won_onboarding'),
                'stop_conditions' => ['onboarding_started', 'manual_pause'],
            ],
        ];

        return $this->mergeMetadata($defaults[$workflowKey] ?? ['intent_key' => $workflowKey], $metadata);
    }

    private function templateQueryForEmailKey(string $emailTemplateKey, string $workflowKey): array
    {
        $emailMetadata = $this->normalizeEmailMatchMetadata($emailTemplateKey, []);
        return [
            'intent_key' => $workflowKey,
            'preferred_template_key' => $emailTemplateKey,
            'purpose' => (string) ($emailMetadata['purposes'][0] ?? 'follow_up'),
            'tone' => (string) ($emailMetadata['tones'][0] ?? 'consultative'),
            'lifecycle_stage' => (string) ($emailMetadata['lifecycle_stages'][0] ?? ''),
            'audience' => (string) ($emailMetadata['audiences'][0] ?? 'prospect'),
            'required_variables' => (array) ($emailMetadata['required_variables'] ?? []),
        ];
    }

    private function defaultEmailKeyForWorkflow(string $workflowKey): string
    {
        return [
            'new_lead_nurture' => 'lead_intro',
            'demo_follow_up' => 'demo_or_discovery_invite',
            'proposal_stall_recovery' => 'proposal_follow_up',
            'silent_lead_reengagement' => 're_engagement',
            'closed_won_onboarding' => 'post_win_onboarding',
        ][$workflowKey] ?? 'lead_intro';
    }

    private function mergeMetadata(array $defaults, array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = array_values(array_unique(array_merge($defaults[$key], $value)));
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function wrapHtml(array $paragraphs): string
    {
        $htmlParts = array_map(
            static fn(string $paragraph): string => '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8')) . '</p>',
            $paragraphs
        );

        return '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #0f172a; max-width: 640px; margin: 0 auto; padding: 24px;">'
            . implode('', $htmlParts)
            . '</body></html>';
    }
}
