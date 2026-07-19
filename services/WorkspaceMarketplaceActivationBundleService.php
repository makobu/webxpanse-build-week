<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceActivationBundleService
{
    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspaceMarketplaceRecommendationService $recommendations;
    private WorkspaceMarketplaceSetupJourneyService $setupJourneys;
    private WorkspaceMarketplaceActivationBundleAdaptiveSignalService $adaptiveSignals;
    private WorkspaceMarketplaceAccessService $access;

    public function __construct(
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceSkillInstallService $installer = null,
        ?WorkspaceMarketplaceRecommendationService $recommendations = null,
        ?WorkspaceMarketplaceSetupJourneyService $setupJourneys = null,
        ?WorkspaceMarketplaceActivationBundleAdaptiveSignalService $adaptiveSignals = null,
        ?WorkspaceMarketplaceAccessService $access = null
    ) {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
        $this->recommendations = $recommendations ?? new WorkspaceMarketplaceRecommendationService($this->catalog, $this->installer);
        $this->setupJourneys = $setupJourneys ?? new WorkspaceMarketplaceSetupJourneyService($this->catalog);
        $this->adaptiveSignals = $adaptiveSignals ?? new WorkspaceMarketplaceActivationBundleAdaptiveSignalService();
        $this->access = $access ?? new WorkspaceMarketplaceAccessService($this->catalog, $this->installer);
    }

    public function bundlesForWorkspace(int $workspaceId, int $userId, int $limit = 0, string $surface = 'marketplace'): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $recommendations = $this->recommendations->recommendationsForWorkspace($workspaceId, $userId, 0, $surface);
        $installed = $this->installer->installedForWorkspace($workspaceId);
        $installedByKey = [];
        foreach ($installed as $module) {
            $installedByKey[(string) ($module['key'] ?? '')] = $module;
        }
        $moduleContext = $this->installer->buildContextForWorkspace($workspaceId, $userId);
        $readinessByKey = (array) ($moduleContext['readiness'] ?? []);
        $accessByKey = [];
        foreach ($this->catalog->availableForWorkspace($workspaceId, true) as $module) {
            $skillKey = (string) ($module['key'] ?? '');
            if ($skillKey !== '') {
                $accessByKey[$skillKey] = $this->access->accessForDefinition($workspaceId, $userId, $module);
            }
        }
        $journeysBySkill = $this->setupJourneys->continuityBySkill($workspaceId, $userId, $recommendations, $installedByKey, $readinessByKey);
        $stateByBundle = $this->stateByBundle($workspaceId);
        $adaptiveByBundle = $this->adaptiveSignals->signalsByBundle([
            'workspace_id' => $workspaceId,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
        ]);

        $recommendationByKey = [];
        foreach ($recommendations as $recommendation) {
            $skillKey = (string) ($recommendation['skill_key'] ?? '');
            if ($skillKey !== '') {
                $recommendationByKey[$skillKey] = $recommendation;
            }
        }

        $bundles = [];
        foreach ($this->definitions() as $definition) {
            $bundleKey = (string) ($definition['bundle_key'] ?? '');
            $state = (array) ($stateByBundle[$bundleKey] ?? []);
            $status = (string) ($state['status'] ?? '');
            if (in_array($status, ['dismissed', 'completed'], true)) {
                continue;
            }

            $bundle = $this->buildBundle($definition, $recommendationByKey, $installedByKey, $readinessByKey, $accessByKey, $journeysBySkill, $status, (array) ($adaptiveByBundle[$bundleKey] ?? []));
            if ($bundle !== null) {
                $bundles[] = $bundle;
            }
        }

        usort($bundles, static function (array $left, array $right): int {
            $score = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            $priority = ['high' => 3, 'medium' => 2, 'low' => 1];
            $prio = ((int) ($priority[(string) ($right['priority'] ?? 'low')] ?? 0)) <=> ((int) ($priority[(string) ($left['priority'] ?? 'low')] ?? 0));
            if ($prio !== 0) {
                return $prio;
            }
            $recommended = ((int) ($right['progress']['recommended'] ?? 0)) <=> ((int) ($left['progress']['recommended'] ?? 0));
            if ($recommended !== 0) {
                return $recommended;
            }
            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $limit > 0 ? array_slice(array_values($bundles), 0, $limit) : array_values($bundles);
    }

    public function contextBundlesForSurface(int $workspaceId, int $userId, string $surface, int $limit = 3): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $limit = max(1, min(5, $limit));
        $insightsByBundle = (new WorkspaceMarketplaceActivationBundleInsightService())->marketplaceInsightsByBundle([
            'workspace_id' => $workspaceId,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
        ]);

        $out = [];
        foreach ($this->bundlesForWorkspace($workspaceId, $userId, $limit, $surface) as $bundle) {
            $bundleKey = $this->normalizeKey((string) ($bundle['bundle_key'] ?? ''));
            $out[] = $this->shapeContextBundle($bundle, (array) ($insightsByBundle[$bundleKey] ?? []));
        }

        return $out;
    }

    public function updateState(int $workspaceId, int $userId, string $bundleKey, string $status, array $metadata = []): void
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $bundleKey = $this->normalizeKey($bundleKey);
        if ($bundleKey === '' || !isset($this->definitions()[$bundleKey])) {
            throw new \InvalidArgumentException('Unknown Marketplace activation bundle.');
        }

        $status = strtolower(trim($status));
        if (!in_array($status, ['selected', 'dismissed', 'completed'], true)) {
            throw new \InvalidArgumentException('Unsupported Marketplace activation bundle status.');
        }

        Database::execute(
            "INSERT INTO workspace_marketplace_activation_bundle_state
                (workspace_id, user_id, bundle_key, status, metadata_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                status = VALUES(status),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $bundleKey,
                $status,
                json_encode($this->filterMetadata($metadata), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function summary(array $filters = []): array
    {
        $workspaceId = (int) ($filters['workspace_id'] ?? 0);
        $state = $this->stateByBundle($workspaceId);
        $counts = ['selected' => 0, 'dismissed' => 0, 'completed' => 0];
        foreach ($state as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return [
            'counts' => $counts,
            'active_bundles' => $this->bundlesForWorkspace($workspaceId, (int) ($filters['user_id'] ?? 0), 5, (string) ($filters['surface'] ?? 'marketplace')),
            'total_state_rows' => count($state),
        ];
    }

    public function definitions(): array
    {
        if ($this->definitionTableReady()) {
            $rows = Database::query(
                "SELECT *
                 FROM workspace_marketplace_activation_bundle_definitions
                 WHERE is_active = 1 AND archived_at IS NULL
                 ORDER BY display_order ASC, label ASC, id ASC"
            );

            $definitions = [];
            foreach ($rows as $row) {
                $definition = $this->normalizeDefinitionRow($row);
                $bundleKey = (string) ($definition['bundle_key'] ?? '');
                if ($bundleKey !== '') {
                    $definitions[$bundleKey] = $definition;
                }
            }

            return $definitions;
        }

        return $this->defaultDefinitions();
    }

    public function allDefinitionsForAdmin(): array
    {
        if (!$this->definitionTableReady()) {
            return array_values(array_map(
                static fn(array $definition): array => array_merge($definition, [
                    'is_active' => true,
                    'archived_at' => '',
                    'display_order' => (int) ($definition['display_order'] ?? 100),
                    'thumbnail_url' => '',
                    'banner_url' => '',
                    'explainer_video_url' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                ]),
                $this->defaultDefinitions()
            ));
        }

        $rows = Database::query(
            "SELECT *
             FROM workspace_marketplace_activation_bundle_definitions
             ORDER BY archived_at IS NULL DESC, is_active DESC, display_order ASC, label ASC, id ASC"
        );

        return array_values(array_map(fn(array $row): array => $this->normalizeDefinitionRow($row), $rows));
    }

    public function saveDefinition(array $data, int $userId): array
    {
        if (!$this->definitionTableReady()) {
            throw new \RuntimeException('Marketplace activation bundle definitions table is not installed.');
        }

        $currentKey = $this->normalizeKey((string) ($data['current_bundle_key'] ?? ''));
        $bundleKey = $this->normalizeKey((string) ($data['bundle_key'] ?? $currentKey));
        if ($bundleKey === '') {
            throw new \InvalidArgumentException('Bundle key is required.');
        }
        if ($currentKey !== '' && $currentKey !== $bundleKey) {
            throw new \InvalidArgumentException('Bundle keys cannot be changed after creation because events and workspace state use the key.');
        }

        $label = $this->sanitizeString((string) ($data['label'] ?? ''), 160);
        if ($label === '') {
            throw new \InvalidArgumentException('Bundle title is required.');
        }

        $summary = $this->sanitizeString((string) ($data['summary'] ?? ''), 4000);
        if ($summary === '') {
            throw new \InvalidArgumentException('Bundle summary is required.');
        }

        $includedSkillKeys = $this->normalizeIncludedSkillKeys((array) ($data['included_skill_keys'] ?? []));
        if ($includedSkillKeys === []) {
            throw new \InvalidArgumentException('Choose at least one valid Marketplace module for this bundle.');
        }

        $payload = [
            'bundle_key' => $bundleKey,
            'label' => $label,
            'summary' => $summary,
            'included_skill_keys_json' => json_encode($includedSkillKeys, JSON_UNESCAPED_SLASHES),
            'thumbnail_url' => $this->sanitizeString((string) ($data['thumbnail_url'] ?? ''), 500),
            'banner_url' => $this->sanitizeString((string) ($data['banner_url'] ?? ''), 500),
            'explainer_video_url' => $this->sanitizeString((string) ($data['explainer_video_url'] ?? ''), 500),
            'why_this_bundle' => $this->sanitizeString((string) ($data['why_this_bundle'] ?? ''), 4000),
            'expected_outcome' => $this->sanitizeString((string) ($data['expected_outcome'] ?? ''), 4000),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'display_order' => max(0, min(100000, (int) ($data['display_order'] ?? 100))),
            'user_id' => $userId > 0 ? $userId : null,
        ];

        if ($currentKey !== '' && $this->definitionExists($currentKey)) {
            Database::execute(
                "UPDATE workspace_marketplace_activation_bundle_definitions
                 SET label = ?,
                     summary = ?,
                     included_skill_keys_json = ?,
                     thumbnail_url = ?,
                     banner_url = ?,
                     explainer_video_url = ?,
                     why_this_bundle = ?,
                     expected_outcome = ?,
                     is_active = ?,
                     archived_at = NULL,
                     display_order = ?,
                     updated_by_user_id = ?,
                     updated_at = NOW()
                 WHERE bundle_key = ?",
                [
                    $payload['label'],
                    $payload['summary'],
                    $payload['included_skill_keys_json'],
                    $payload['thumbnail_url'],
                    $payload['banner_url'],
                    $payload['explainer_video_url'],
                    $payload['why_this_bundle'],
                    $payload['expected_outcome'],
                    $payload['is_active'],
                    $payload['display_order'],
                    $payload['user_id'],
                    $currentKey,
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO workspace_marketplace_activation_bundle_definitions (
                    bundle_key, label, summary, included_skill_keys_json, thumbnail_url, banner_url,
                    explainer_video_url, why_this_bundle,
                    expected_outcome, is_active, archived_at, display_order,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    summary = VALUES(summary),
                    included_skill_keys_json = VALUES(included_skill_keys_json),
                    thumbnail_url = VALUES(thumbnail_url),
                    banner_url = VALUES(banner_url),
                    explainer_video_url = VALUES(explainer_video_url),
                    why_this_bundle = VALUES(why_this_bundle),
                    expected_outcome = VALUES(expected_outcome),
                    is_active = VALUES(is_active),
                    archived_at = NULL,
                    display_order = VALUES(display_order),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = NOW()",
                [
                    $payload['bundle_key'],
                    $payload['label'],
                    $payload['summary'],
                    $payload['included_skill_keys_json'],
                    $payload['thumbnail_url'],
                    $payload['banner_url'],
                    $payload['explainer_video_url'],
                    $payload['why_this_bundle'],
                    $payload['expected_outcome'],
                    $payload['is_active'],
                    $payload['display_order'],
                    $payload['user_id'],
                    $payload['user_id'],
                ]
            );
        }

        return $this->definitionByKey($bundleKey) ?? [];
    }

    public function setDefinitionActive(string $bundleKey, bool $active, int $userId): void
    {
        if (!$this->definitionTableReady()) {
            throw new \RuntimeException('Marketplace activation bundle definitions table is not installed.');
        }

        $bundleKey = $this->normalizeKey($bundleKey);
        if ($bundleKey === '' || !$this->definitionExists($bundleKey)) {
            throw new \InvalidArgumentException('Marketplace activation bundle definition not found.');
        }

        Database::execute(
            "UPDATE workspace_marketplace_activation_bundle_definitions
             SET is_active = ?,
                 archived_at = CASE WHEN ? = 1 THEN NULL ELSE archived_at END,
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE bundle_key = ?",
            [$active ? 1 : 0, $active ? 1 : 0, $userId > 0 ? $userId : null, $bundleKey]
        );
    }

    public function archiveDefinition(string $bundleKey, int $userId): void
    {
        if (!$this->definitionTableReady()) {
            throw new \RuntimeException('Marketplace activation bundle definitions table is not installed.');
        }

        $bundleKey = $this->normalizeKey($bundleKey);
        if ($bundleKey === '' || !$this->definitionExists($bundleKey)) {
            throw new \InvalidArgumentException('Marketplace activation bundle definition not found.');
        }

        Database::execute(
            "UPDATE workspace_marketplace_activation_bundle_definitions
             SET is_active = 0,
                 archived_at = COALESCE(archived_at, NOW()),
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE bundle_key = ?",
            [$userId > 0 ? $userId : null, $bundleKey]
        );
    }

    public function deleteDefinition(string $bundleKey): void
    {
        if (!$this->definitionTableReady()) {
            throw new \RuntimeException('Marketplace activation bundle definitions table is not installed.');
        }

        $bundleKey = $this->normalizeKey($bundleKey);
        if ($bundleKey === '' || !$this->definitionExists($bundleKey)) {
            throw new \InvalidArgumentException('Marketplace activation bundle definition not found.');
        }

        Database::execute(
            "DELETE FROM workspace_marketplace_activation_bundle_definitions
             WHERE bundle_key = ?",
            [$bundleKey]
        );
    }

    public function definitionTableReady(): bool
    {
        return Database::tableExists('workspace_marketplace_activation_bundle_definitions');
    }

    private function defaultDefinitions(): array
    {
        return [
            'strategy_foundation' => [
                'bundle_key' => 'strategy_foundation',
                'label' => 'Strategy foundation',
                'summary' => 'Clarify the business model and marketing context before scaling execution.',
                'included_skill_keys' => [
                    WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                ],
                'why_this_bundle' => 'This gives Clarity and Coach a cleaner strategic frame for recommendations.',
                'expected_outcome' => 'Sharper customer, offer, positioning, and campaign guidance.',
                'display_order' => 10,
            ],
            'email_led_growth' => [
                'bundle_key' => 'email_led_growth',
                'label' => 'Email-led growth',
                'summary' => 'Pair strategy context with Email Assistant setup for email-heavy businesses.',
                'included_skill_keys' => [
                    WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                    WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
                    WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                ],
                'why_this_bundle' => 'Email is strongest when replies, digests, and positioning share the same context.',
                'expected_outcome' => 'More useful customer replies, inbound instructions, and email growth workflows.',
                'display_order' => 20,
            ],
            'whatsapp_led_growth' => [
                'bundle_key' => 'whatsapp_led_growth',
                'label' => 'WhatsApp-led growth',
                'summary' => 'Combine strategy context with WhatsApp Assistant setup for WhatsApp-first businesses.',
                'included_skill_keys' => [
                    WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                    WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
                    WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                ],
                'why_this_bundle' => 'WhatsApp-led teams need assistant context close to the channel they actually use.',
                'expected_outcome' => 'Better WhatsApp instructions, digests, session continuity, and campaign suggestions.',
                'display_order' => 30,
            ],
            'omnichannel_assistant' => [
                'bundle_key' => 'omnichannel_assistant',
                'label' => 'Omnichannel assistant',
                'summary' => 'Coordinate Email and WhatsApp assistants around the same growth motion.',
                'included_skill_keys' => [
                    WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                    WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                    WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
                    WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                ],
                'why_this_bundle' => 'When customers use more than one channel, assistant setup should stay coordinated.',
                'expected_outcome' => 'Cleaner cross-channel follow-up, digests, and assistant recommendations.',
                'display_order' => 40,
            ],
            'communication_ai_operator' => [
                'bundle_key' => 'communication_ai_operator',
                'label' => 'Communication + AI Operator',
                'summary' => 'Activate the main optional channels, meeting context, and AI Coach as one operating path.',
                'included_skill_keys' => [
                    WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
                    WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
                    WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
                    WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
                    WorkspaceSkillCatalogService::SKILL_AI_COACH,
                ],
                'why_this_bundle' => 'Phase 1 modularization works best when channels, meetings, and proactive coaching share the same installed-module model.',
                'expected_outcome' => 'Owners can activate communication tools and AI guidance from the Marketplace without exposing uninstalled features in normal UI.',
                'display_order' => 50,
            ],
        ];
    }

    public function tableReady(): bool
    {
        return Database::tableExists('workspace_marketplace_activation_bundle_state');
    }

    private function buildBundle(array $definition, array $recommendationByKey, array $installedByKey, array $readinessByKey, array $accessByKey, array $journeysBySkill, string $status, array $adaptiveSignal = []): ?array
    {
        $included = array_values(array_filter(array_map('strval', (array) ($definition['included_skill_keys'] ?? []))));
        $recommended = [];
        $installed = [];
        $blocked = [];
        $accessStates = [];
        $rootBlockerSkillKey = '';
        $nextAction = null;

        foreach ($included as $skillKey) {
            $access = (array) ($accessByKey[$skillKey] ?? []);
            $accessState = (string) ($access['state'] ?? '');
            if ($accessState !== '') {
                $accessStates[$skillKey] = $accessState;
            }
            if (isset($recommendationByKey[$skillKey])) {
                $recommended[] = $skillKey;
                if ($nextAction === null) {
                    $rec = (array) $recommendationByKey[$skillKey];
                    $blockedSkillKey = (string) ($rec['blocked_skill_key'] ?? '');
                    $rootKey = (string) ($rec['root_blocker_skill_key'] ?? '');
                    $rootModule = $rootKey !== '' ? ($this->catalog->find($rootKey, true) ?? []) : [];
                    if ($rootBlockerSkillKey === '' && $rootKey !== '') {
                        $rootBlockerSkillKey = $rootKey;
                    }
                    $nextAction = [
                        'skill_key' => $rootKey !== '' ? $rootKey : $skillKey,
                        'blocked_skill_key' => $blockedSkillKey,
                        'root_blocker_skill_key' => $rootKey,
                        'label' => $rootKey !== '' ? (string) ($rootModule['label'] ?? $rootKey) : (string) ($rec['label'] ?? $skillKey),
                        'action' => $blockedSkillKey !== '' ? 'open_prerequisite' : (!empty($rec['is_installed']) ? 'open_setup' : 'install_or_open_marketplace'),
                        'url' => (string) ($rec['next_action_url'] ?? $rec['setup_url'] ?? 'workspace_skills.php'),
                    ];
                }
            } elseif (isset($installedByKey[$skillKey])) {
                $installed[] = $skillKey;
                if (!empty($access['is_locked'])) {
                    $recommended[] = $skillKey;
                    $blocked[] = $skillKey;
                    $rootKey = (string) ($access['root_blocker_skill_key'] ?? '');
                    $rootModule = $rootKey !== '' ? ($this->catalog->find($rootKey, true) ?? []) : [];
                    if ($rootBlockerSkillKey === '' && $rootKey !== '') {
                        $rootBlockerSkillKey = $rootKey;
                    }
                    if ($nextAction === null) {
                        $nextAction = [
                            'skill_key' => $rootKey !== '' ? $rootKey : $skillKey,
                            'blocked_skill_key' => $skillKey,
                            'root_blocker_skill_key' => $rootKey,
                            'label' => $rootKey !== '' ? (string) ($rootModule['label'] ?? $rootKey) : (string) ($access['label'] ?? $skillKey),
                            'action' => 'open_prerequisite',
                            'url' => (string) ($access['next_action_url'] ?? 'workspace_skills.php'),
                        ];
                    }
                    continue;
                }
                $readiness = (array) ($readinessByKey[$skillKey] ?? []);
                if (!empty($readiness) && empty($readiness['ready'])) {
                    $recommended[] = $skillKey;
                    if ($nextAction === null) {
                        $module = $this->catalog->find($skillKey) ?? [];
                        $nextAction = [
                            'skill_key' => $skillKey,
                            'label' => (string) ($module['label'] ?? $skillKey),
                            'action' => 'open_setup',
                            'url' => (string) ($module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php'),
                        ];
                    }
                }
            } else {
                $blocked[] = $skillKey;
            }
        }

        if ($recommended === []) {
            return null;
        }

        $total = max(1, count($included));
        $completed = count($installed);
        $priority = count($recommended) >= 2 ? 'high' : 'medium';
        $setupUrl = (string) ($nextAction['url'] ?? 'workspace_skills.php');
        $baseScore = $this->baseScore($priority, $status, count(array_unique($recommended)), $completed, $total);
        $adaptiveDelta = max(-10, min(10, (int) ($adaptiveSignal['adaptive_score_delta'] ?? 0)));
        $score = max(0, min(100, $baseScore + $adaptiveDelta));
        $adaptiveReasonCodes = array_values(array_unique(array_filter(array_map('strval', (array) ($adaptiveSignal['adaptive_reason_codes'] ?? [])))));

        return [
            'bundle_key' => (string) ($definition['bundle_key'] ?? ''),
            'label' => (string) ($definition['label'] ?? 'Activation bundle'),
            'summary' => (string) ($definition['summary'] ?? ''),
            'thumbnail_url' => (string) ($definition['thumbnail_url'] ?? ''),
            'banner_url' => (string) ($definition['banner_url'] ?? ''),
            'explainer_video_url' => (string) ($definition['explainer_video_url'] ?? ''),
            'priority' => $priority,
            'score' => $score,
            'base_score' => $baseScore,
            'adaptive_score_delta' => $adaptiveDelta,
            'adaptive_reason_codes' => $adaptiveReasonCodes,
            'adaptive_guidance' => (string) ($adaptiveSignal['adaptive_guidance'] ?? ''),
            'adaptive_confidence' => (string) ($adaptiveSignal['adaptive_confidence'] ?? 'none'),
            'included_skill_keys' => $included,
            'recommended_skill_keys' => array_values(array_unique($recommended)),
            'installed_skill_keys' => array_values(array_unique($installed)),
            'blocked_skill_keys' => array_values(array_unique($blocked)),
            'root_blocker_skill_key' => $rootBlockerSkillKey,
            'access_states' => $accessStates,
            'progress' => [
                'total' => $total,
                'installed' => $completed,
                'recommended' => count(array_unique($recommended)),
                'percent' => round($completed / $total, 4),
            ],
            'next_action' => $nextAction ?? ['action' => 'open_marketplace', 'url' => 'workspace_skills.php'],
            'why_this_bundle' => (string) ($definition['why_this_bundle'] ?? ''),
            'expected_outcome' => (string) ($definition['expected_outcome'] ?? ''),
            'setup_url' => $setupUrl,
            'status' => $status !== '' ? $status : 'suggested',
            'setup_journeys' => array_intersect_key($journeysBySkill, array_flip($included)),
        ];
    }

    private function baseScore(string $priority, string $status, int $recommendedCount, int $installedCount, int $totalCount): int
    {
        $priorityScore = $priority === 'high' ? 58 : ($priority === 'medium' ? 44 : 30);
        $statusScore = $status === 'selected' ? 18 : 0;
        $recommendedScore = min(16, $recommendedCount * 8);
        $progressScore = $totalCount > 0 ? (int) round(($installedCount / $totalCount) * 10) : 0;

        return max(0, min(100, $priorityScore + $statusScore + $recommendedScore + $progressScore));
    }

    private function shapeContextBundle(array $bundle, array $insight): array
    {
        $nextAction = (array) ($bundle['next_action'] ?? []);
        $progress = (array) ($bundle['progress'] ?? []);

        return [
            'bundle_key' => (string) ($bundle['bundle_key'] ?? ''),
            'label' => (string) ($bundle['label'] ?? 'Activation bundle'),
            'summary' => (string) ($bundle['summary'] ?? ''),
            'priority' => (string) ($bundle['priority'] ?? 'medium'),
            'status' => (string) ($bundle['status'] ?? 'suggested'),
            'progress' => [
                'total' => max(0, (int) ($progress['total'] ?? 0)),
                'installed' => max(0, (int) ($progress['installed'] ?? 0)),
                'recommended' => max(0, (int) ($progress['recommended'] ?? 0)),
                'percent' => max(0.0, min(1.0, (float) ($progress['percent'] ?? 0.0))),
            ],
            'next_action' => [
                'skill_key' => (string) ($nextAction['skill_key'] ?? ''),
                'blocked_skill_key' => (string) ($nextAction['blocked_skill_key'] ?? ''),
                'root_blocker_skill_key' => (string) ($nextAction['root_blocker_skill_key'] ?? ''),
                'label' => (string) ($nextAction['label'] ?? 'Open Marketplace'),
                'action' => (string) ($nextAction['action'] ?? 'open_marketplace'),
                'url' => $this->safeRoute((string) ($nextAction['url'] ?? 'workspace_skills.php')),
            ],
            'recommended_skill_keys' => $this->safeSkillKeys((array) ($bundle['recommended_skill_keys'] ?? [])),
            'installed_skill_keys' => $this->safeSkillKeys((array) ($bundle['installed_skill_keys'] ?? [])),
            'root_blocker_skill_key' => (string) ($bundle['root_blocker_skill_key'] ?? ''),
            'adaptive_guidance' => (string) ($bundle['adaptive_guidance'] ?? ''),
            'insight_label' => (string) ($insight['label'] ?? ''),
        ];
    }

    private function safeSkillKeys(array $keys): array
    {
        $safe = [];
        foreach ($keys as $key) {
            $normalized = $this->normalizeKey((string) $key);
            if ($normalized !== '') {
                $safe[] = $normalized;
            }
        }

        return array_values(array_unique($safe));
    }

    private function safeRoute(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'workspace_skills.php';
        }

        $lower = strtolower($url);
        if (str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($lower, '//')
            || str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:')
        ) {
            return 'workspace_skills.php';
        }

        return $url;
    }

    private function stateByBundle(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return [];
        }

        $rows = Database::query(
            "SELECT bundle_key, status, metadata_json, updated_at
             FROM workspace_marketplace_activation_bundle_state
             WHERE workspace_id = ?
             ORDER BY updated_at DESC, id DESC",
            [$workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $key = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'status' => (string) ($row['status'] ?? ''),
                'metadata' => $this->safeMetadata($row['metadata_json'] ?? null),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    private function filterMetadata(array $metadata): array
    {
        $safe = [];
        foreach (['source', 'label', 'bundle_key', 'status'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $safe[$key] = (string) $metadata[$key];
            }
        }
        return $safe;
    }

    private function safeMetadata(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $this->filterMetadata($decoded) : [];
    }

    private function definitionByKey(string $bundleKey): ?array
    {
        if (!$this->definitionTableReady()) {
            return $this->defaultDefinitions()[$this->normalizeKey($bundleKey)] ?? null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_marketplace_activation_bundle_definitions
             WHERE bundle_key = ?
             LIMIT 1",
            [$this->normalizeKey($bundleKey)]
        );

        return $row ? $this->normalizeDefinitionRow($row) : null;
    }

    private function definitionExists(string $bundleKey): bool
    {
        if (!$this->definitionTableReady()) {
            return isset($this->defaultDefinitions()[$this->normalizeKey($bundleKey)]);
        }

        return (bool) Database::queryOne(
            "SELECT 1
             FROM workspace_marketplace_activation_bundle_definitions
             WHERE bundle_key = ?
             LIMIT 1",
            [$this->normalizeKey($bundleKey)]
        );
    }

    private function normalizeDefinitionRow(array $row): array
    {
        $bundleKey = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
        $included = $this->decodeJsonList($row['included_skill_keys_json'] ?? null);

        return [
            'bundle_key' => $bundleKey,
            'label' => (string) ($row['label'] ?? ''),
            'summary' => (string) ($row['summary'] ?? ''),
            'included_skill_keys' => $this->safeSkillKeys($included),
            'thumbnail_url' => (string) ($row['thumbnail_url'] ?? ''),
            'banner_url' => (string) ($row['banner_url'] ?? ''),
            'explainer_video_url' => (string) ($row['explainer_video_url'] ?? ''),
            'why_this_bundle' => (string) ($row['why_this_bundle'] ?? ''),
            'expected_outcome' => (string) ($row['expected_outcome'] ?? ''),
            'is_active' => !empty($row['is_active']),
            'archived_at' => (string) ($row['archived_at'] ?? ''),
            'display_order' => (int) ($row['display_order'] ?? 100),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'updated_by_user_id' => isset($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function decodeJsonList(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn(string $value): bool => trim($value) !== ''));
    }

    private function normalizeIncludedSkillKeys(array $keys): array
    {
        $available = $this->availablePlatformSkillKeys();
        $normalized = [];
        foreach ($keys as $key) {
            $safeKey = $this->normalizeKey((string) $key);
            if ($safeKey !== '' && isset($available[$safeKey])) {
                $normalized[] = $safeKey;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function availablePlatformSkillKeys(): array
    {
        $available = [
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER => true,
        ];
        foreach ($this->catalog->available() as $module) {
            $key = $this->normalizeKey((string) ($module['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (array_key_exists('owner_workspace_id', $module) && (int) ($module['owner_workspace_id'] ?? 0) > 0) {
                continue;
            }
            $available[$key] = true;
        }

        return $available;
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }

    private function normalizeKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? '', '_');
    }
}
