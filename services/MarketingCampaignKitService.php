<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use RuntimeException;
use Throwable;

final class MarketingCampaignKitService
{
    private const MAX_CHANNELS = 6;

    private Marketing $marketing;
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?Marketing $marketing = null, ?WorkspaceScopeService $workspaceScope = null)
    {
        $this->marketing = $marketing ?? new Marketing();
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    /**
     * Generate coordinated channel drafts into a review-only staging ledger.
     * No content item, media record, distribution job, or external publication is created here.
     */
    public function generate(array $input, ?int $userId = null): array
    {
        $this->assertPermission('marketing.write');
        $this->assertTables();

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
        $normalized = $this->normalizeRunInput($input, $workspaceId);
        $learningContext = $this->reusableLearningContext($workspaceId, (array) $normalized['channels']);
        $messageHouse = $this->messageHouse($normalized, $learningContext);
        $uuid = $this->uuid();

        Database::execute(
            "INSERT INTO marketing_generation_runs
             (workspace_id, uuid, run_type, status, title, objective, target_audience, offer_text, cta_text,
              funnel_stage, campaign_id, campaign_brief_id, brand_profile_id, persona_id, audience_segment_id,
              channels_json, message_house_json, context_json, created_by)
             VALUES (?, ?, 'campaign_kit', 'generating', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $uuid,
                $normalized['title'],
                $normalized['objective'],
                $normalized['target_audience'],
                $normalized['offer_text'],
                $normalized['cta_text'],
                $normalized['funnel_stage'],
                $normalized['campaign_id'],
                $normalized['campaign_brief_id'],
                $normalized['brand_profile_id'],
                $normalized['persona_id'],
                $normalized['audience_segment_id'],
                $this->json($normalized['channels']),
                $this->json($messageHouse),
                $this->json([
                    'submitted_inputs' => $normalized,
                    'generation_boundary' => [
                        'staged_only' => true,
                        'external_publish' => false,
                        'external_send' => false,
                        'records_overwritten' => false,
                        'acceptance_required_per_artifact' => true,
                    ],
                ]),
                $userId > 0 ? $userId : null,
            ]
        );
        $runId = (int) Database::lastInsertId();

        $providerRows = [];
        $qualityRows = [];
        $generatedCount = 0;
        $errors = [];

        foreach ($normalized['channels'] as $ordinal => $channel) {
            $contentType = $this->contentTypeForChannel($channel);
            $artifactTitle = $normalized['title'] . ' - ' . $this->channelLabel($channel);
            $creativeDirection = $this->creativeDirection($channel, $normalized, $messageHouse);

            try {
                $draft = $this->marketing->generateDraft([
                    'title' => $artifactTitle,
                    'content_type' => $contentType,
                    'channel' => $channel,
                    'funnel_stage' => $normalized['funnel_stage'],
                    'objective' => $this->generationObjective($normalized, $messageHouse),
                    'target_audience' => $normalized['target_audience'],
                    'campaign_id' => $normalized['campaign_id'],
                    'campaign_brief_id' => $normalized['campaign_brief_id'],
                    'brand_profile_id' => $normalized['brand_profile_id'],
                    'persona_id' => $normalized['persona_id'],
                    'created_by' => $userId,
                ]);
                $body = trim((string) ($draft['body'] ?? ''));
                if ($body === '') {
                    throw new RuntimeException('The generation provider returned an empty draft.');
                }

                $aiContext = (array) ($draft['ai_context'] ?? []);
                $provider = (array) ($aiContext['provider'] ?? []);
                $quality = $this->draftQuality($body, $normalized['cta_text'], $channel);
                $providerRows[] = [
                    'channel' => $channel,
                    'mode' => (string) ($provider['mode'] ?? $provider['provider'] ?? 'unknown'),
                    'fallback_used' => !empty($provider['fallback_used']),
                ];
                $qualityRows[] = $quality;

                $this->insertArtifact(
                    $workspaceId,
                    $runId,
                    $channel,
                    $contentType,
                    $artifactTitle,
                    $body,
                    $creativeDirection,
                    $quality,
                    [
                        'prompt_inputs' => (array) ($aiContext['prompt_inputs'] ?? []),
                        'context_bundle' => (array) ($aiContext['context_bundle'] ?? []),
                        'context_snapshot_id' => $aiContext['context_snapshot_id'] ?? null,
                        'message_house' => $messageHouse,
                    ],
                    $provider,
                    (int) $ordinal,
                    'generated'
                );
                $generatedCount++;
            } catch (Throwable $e) {
                $errors[] = $this->channelLabel($channel) . ': ' . $e->getMessage();
                $this->insertArtifact(
                    $workspaceId,
                    $runId,
                    $channel,
                    $contentType,
                    $artifactTitle,
                    '',
                    $creativeDirection,
                    ['score' => 0, 'warnings' => ['Generation failed before review.']],
                    ['message_house' => $messageHouse],
                    ['mode' => 'failed', 'error' => $e->getMessage()],
                    (int) $ordinal,
                    'failed'
                );
            }
        }

        $runQuality = [
            'artifact_count' => count($normalized['channels']),
            'generated_count' => $generatedCount,
            'failed_count' => count($errors),
            'average_score' => $qualityRows === []
                ? 0
                : (int) round(array_sum(array_column($qualityRows, 'score')) / count($qualityRows)),
            'review_required' => true,
        ];
        Database::execute(
            "UPDATE marketing_generation_runs
             SET status = ?, provider_json = ?, quality_json = ?, error_text = ?, generated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [
                $generatedCount > 0 ? 'ready' : 'failed',
                $this->json(['artifacts' => $providerRows]),
                $this->json($runQuality),
                $errors === [] ? null : implode("\n", $errors),
                $workspaceId,
                $runId,
            ]
        );

        return $this->getRun($runId) ?? throw new RuntimeException('The generated campaign kit could not be reloaded.');
    }

    public function getRun(int $runId): ?array
    {
        $this->assertPermission('marketing.read');
        if (!Database::tableExists('marketing_generation_runs')) {
            return null;
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $run = Database::queryOne(
            "SELECT r.*, c.name AS campaign_name, b.title AS campaign_brief_title,
                    bp.name AS brand_profile_name, p.name AS persona_name, s.name AS audience_segment_name,
                    creator.email AS created_by_email
             FROM marketing_generation_runs r
             LEFT JOIN campaigns c ON c.id = r.campaign_id AND c.workspace_id = r.workspace_id
             LEFT JOIN marketing_campaign_briefs b ON b.id = r.campaign_brief_id AND b.workspace_id = r.workspace_id
             LEFT JOIN marketing_brand_profiles bp ON bp.id = r.brand_profile_id AND bp.workspace_id = r.workspace_id
             LEFT JOIN marketing_personas p ON p.id = r.persona_id AND p.workspace_id = r.workspace_id
             LEFT JOIN marketing_audience_segments s ON s.id = r.audience_segment_id AND s.workspace_id = r.workspace_id
             LEFT JOIN users creator ON creator.id = r.created_by
             WHERE r.workspace_id = ? AND r.id = ?
             LIMIT 1",
            [$workspaceId, $runId]
        );
        if (!$run) {
            return null;
        }

        $run = $this->hydrateJson($run, [
            'channels_json',
            'message_house_json',
            'context_json',
            'provider_json',
            'quality_json',
            'outcome_json',
        ]);
        $run['artifacts'] = array_map(
            fn(array $row): array => $this->hydrateJson($row, [
                'creative_direction_json',
                'quality_json',
                'source_context_json',
                'provider_json',
                'handoff_json',
                'last_outcome_json',
            ]),
            Database::query(
                "SELECT a.*, ci.title AS accepted_content_title, mr.title AS visual_request_title,
                        acceptor.email AS accepted_by_email,
                        dp.status AS distribution_status, dp.published_url AS distribution_published_url,
                        dp.scheduled_at AS distribution_scheduled_at,
                        utm.generated_url AS tracked_url,
                        cmk.title AS channel_media_kit_title, cmk.status AS channel_media_kit_status
                 FROM marketing_generation_artifacts a
                 LEFT JOIN marketing_content_items ci
                    ON ci.id = a.accepted_content_item_id AND ci.workspace_id = a.workspace_id
                 LEFT JOIN marketing_ai_media_requests mr
                    ON mr.id = a.visual_request_id AND mr.workspace_id = a.workspace_id
                 LEFT JOIN marketing_distribution_posts dp
                    ON dp.id = a.distribution_post_id AND dp.workspace_id = a.workspace_id
                 LEFT JOIN marketing_utm_links utm
                    ON utm.id = a.utm_link_id AND utm.workspace_id = a.workspace_id
                 LEFT JOIN marketing_channel_media_kits cmk
                    ON cmk.id = a.channel_media_kit_id AND cmk.workspace_id = a.workspace_id
                 LEFT JOIN users acceptor ON acceptor.id = a.accepted_by
                 WHERE a.workspace_id = ? AND a.generation_run_id = ?
                 ORDER BY a.ordinal ASC, a.id ASC",
                [$workspaceId, $runId]
            )
        );
        $run['state_summary'] = $this->stateSummary($run['artifacts']);

        return $run;
    }

    public function listRuns(int $limit = 12): array
    {
        $this->assertPermission('marketing.read');
        if (!Database::tableExists('marketing_generation_runs')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $limit = max(1, min(50, $limit));

        return array_map(
            fn(array $row): array => $this->hydrateJson($row, ['channels_json', 'quality_json']),
            Database::query(
                "SELECT r.*,
                        COUNT(a.id) AS artifact_count,
                        SUM(CASE WHEN a.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
                        SUM(CASE WHEN a.status = 'generated' THEN 1 ELSE 0 END) AS review_count
                 FROM marketing_generation_runs r
                 LEFT JOIN marketing_generation_artifacts a
                    ON a.generation_run_id = r.id AND a.workspace_id = r.workspace_id
                 WHERE r.workspace_id = ?
                 GROUP BY r.id
                 ORDER BY r.updated_at DESC, r.id DESC
                 LIMIT {$limit}",
                [$workspaceId]
            )
        );
    }

    public function updateArtifact(int $artifactId, array $data): array
    {
        $this->assertPermission('marketing.write');
        $artifact = $this->getArtifact($artifactId);
        if (!$artifact) {
            throw new RuntimeException('Campaign Kit artifact not found.');
        }
        if ((string) $artifact['status'] === 'accepted') {
            throw new RuntimeException('Accepted artifacts are edited from Content Studio.');
        }
        if ((string) $artifact['status'] === 'failed') {
            throw new RuntimeException('Failed artifacts must be regenerated in a new kit.');
        }

        $title = $this->cleanText($data['title'] ?? $artifact['title'], 255);
        $body = $this->cleanText($data['body'] ?? $artifact['body'], 60000, true);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Artifact title and draft copy are required.');
        }

        $creative = (array) ($artifact['creative_direction_json'] ?? []);
        if (array_key_exists('visual_prompt', $data)) {
            $creative['prompt'] = $this->cleanText($data['visual_prompt'], 6000, true);
            $creative['edited_by_user'] = true;
        }
        $quality = $this->draftQuality($body, '', (string) $artifact['channel']);
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        Database::execute(
            "UPDATE marketing_generation_artifacts
             SET title = ?, body = ?, creative_direction_json = ?, quality_json = ?, status = 'generated', rejection_reason = NULL
             WHERE workspace_id = ? AND id = ?",
            [$title, $body, $this->json($creative), $this->json($quality), $workspaceId, $artifactId]
        );
        $this->refreshRunStatus((int) $artifact['generation_run_id'], $workspaceId);

        return $this->getArtifact($artifactId) ?? throw new RuntimeException('The artifact could not be reloaded.');
    }

    public function acceptArtifact(int $artifactId, ?int $userId = null): int
    {
        $this->assertPermission('marketing.write');
        $artifact = $this->getArtifact($artifactId);
        if (!$artifact) {
            throw new RuntimeException('Campaign Kit artifact not found.');
        }
        if ((string) $artifact['status'] === 'accepted' && (int) ($artifact['accepted_content_item_id'] ?? 0) > 0) {
            return (int) $artifact['accepted_content_item_id'];
        }
        if ((string) $artifact['status'] !== 'generated') {
            throw new RuntimeException('Only a generated artifact can be accepted.');
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
        $run = $this->getRun((int) $artifact['generation_run_id']);
        if (!$run) {
            throw new RuntimeException('Campaign Kit run not found.');
        }

        Database::beginTransaction();
        try {
            $contentId = $this->marketing->createContentItem([
                'title' => (string) $artifact['title'],
                'content_type' => (string) $artifact['content_type'],
                'channel' => (string) $artifact['channel'],
                'status' => 'draft',
                'funnel_stage' => (string) ($run['funnel_stage'] ?? ''),
                'objective' => (string) ($run['objective'] ?? ''),
                'target_audience' => (string) ($run['target_audience'] ?? ''),
                'campaign_id' => (int) ($run['campaign_id'] ?? 0) ?: null,
                'campaign_brief_id' => (int) ($run['campaign_brief_id'] ?? 0) ?: null,
                'brand_profile_id' => (int) ($run['brand_profile_id'] ?? 0) ?: null,
                'persona_id' => (int) ($run['persona_id'] ?? 0) ?: null,
                'draft_body' => (string) ($artifact['body'] ?? ''),
                'ai_context_json' => [
                    'source' => 'campaign_kit',
                    'generation_run_id' => (int) $run['id'],
                    'generation_run_uuid' => (string) $run['uuid'],
                    'generation_artifact_id' => (int) $artifact['id'],
                    'generation_artifact_uuid' => (string) $artifact['uuid'],
                    'message_house' => (array) ($run['message_house_json'] ?? []),
                    'source_context' => (array) ($artifact['source_context_json'] ?? []),
                    'provider' => (array) ($artifact['provider_json'] ?? []),
                ],
                'metadata_json' => [
                    'source' => 'campaign_kit',
                    'generation_lineage' => [
                        'run_id' => (int) $run['id'],
                        'run_uuid' => (string) $run['uuid'],
                        'artifact_id' => (int) $artifact['id'],
                        'artifact_uuid' => (string) $artifact['uuid'],
                    ],
                    'creative_direction' => (array) ($artifact['creative_direction_json'] ?? []),
                    'reviewed_before_creation' => true,
                    'external_publish' => false,
                ],
                'created_by' => $userId,
            ]);

            $updated = Database::execute(
                "UPDATE marketing_generation_artifacts
                 SET status = 'accepted', accepted_content_item_id = ?, accepted_by = ?, accepted_at = NOW()
                 WHERE workspace_id = ? AND id = ? AND status = 'generated'",
                [$contentId, $userId > 0 ? $userId : null, $workspaceId, $artifactId]
            );
            if ($updated !== 1) {
                throw new RuntimeException('The artifact changed while it was being accepted.');
            }
            $this->refreshRunStatus((int) $artifact['generation_run_id'], $workspaceId);
            Database::commit();

            return $contentId;
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function rejectArtifact(int $artifactId, string $reason = ''): bool
    {
        $this->assertPermission('marketing.write');
        $artifact = $this->getArtifact($artifactId);
        if (!$artifact) {
            throw new RuntimeException('Campaign Kit artifact not found.');
        }
        if ((string) $artifact['status'] === 'accepted') {
            throw new RuntimeException('Accepted artifacts cannot be rejected from the generation ledger.');
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        Database::execute(
            "UPDATE marketing_generation_artifacts
             SET status = 'rejected', rejection_reason = ?
             WHERE workspace_id = ? AND id = ? AND status <> 'accepted'",
            [$this->cleanText($reason, 500) ?: null, $workspaceId, $artifactId]
        );
        $this->refreshRunStatus((int) $artifact['generation_run_id'], $workspaceId);

        return true;
    }

    /**
     * Hand the visual direction to the existing Design-owned advisory bridge.
     * The bridge currently creates a prompt/output record, not generated pixels.
     */
    public function queueVisualBrief(int $artifactId, ?int $userId = null): int
    {
        $this->assertPermission('marketing.write');
        $artifact = $this->getArtifact($artifactId);
        if (!$artifact) {
            throw new RuntimeException('Campaign Kit artifact not found.');
        }
        if ((int) ($artifact['visual_request_id'] ?? 0) > 0) {
            return (int) $artifact['visual_request_id'];
        }
        if (in_array((string) $artifact['status'], ['failed', 'rejected'], true)) {
            throw new RuntimeException('Only an active generated or accepted artifact can be sent to Design.');
        }

        $creative = (array) ($artifact['creative_direction_json'] ?? []);
        $prompt = trim((string) ($creative['prompt'] ?? ''));
        if ($prompt === '') {
            throw new RuntimeException('This artifact does not have a visual direction prompt.');
        }

        $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
        $bridge = $this->marketing->generateAiMediaRequest([
            'title' => (string) $artifact['title'] . ' visual direction',
            'request_type' => 'image',
            'content_item_id' => (int) ($artifact['accepted_content_item_id'] ?? 0) ?: null,
            'prompt_text' => $prompt,
            'prompt_json' => $creative,
            'context_json' => [
                'source' => 'campaign_kit',
                'generation_run_id' => (int) $artifact['generation_run_id'],
                'generation_artifact_id' => (int) $artifact['id'],
                'channel' => (string) $artifact['channel'],
            ],
            'created_by' => $userId,
        ]);
        $requestId = (int) ($bridge['request']['id'] ?? 0);
        if ($requestId <= 0) {
            throw new RuntimeException('The Design visual brief could not be created.');
        }

        $creative['bridge_state'] = 'prompt_ready';
        $creative['external_generation'] = false;
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        Database::execute(
            "UPDATE marketing_generation_artifacts
             SET visual_request_id = ?, creative_direction_json = ?
             WHERE workspace_id = ? AND id = ?",
            [$requestId, $this->json($creative), $workspaceId, $artifactId]
        );

        return $requestId;
    }

    /**
     * Prepare a traceable launch package without scheduling, publishing, or creating a social job.
     */
    public function prepareLaunchHandoff(int $artifactId, array $data, ?int $userId = null): array
    {
        $this->assertPermission('marketing.write');
        $this->assertLaunchTables();

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
        $destinationUrl = trim((string) ($data['destination_url'] ?? ''));
        if (!filter_var($destinationUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $destinationUrl)) {
            throw new \InvalidArgumentException('A valid HTTP or HTTPS destination URL is required to prepare tracking.');
        }

        Database::beginTransaction();
        try {
            $locked = Database::queryOne(
                'SELECT * FROM marketing_generation_artifacts WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [$workspaceId, $artifactId]
            );
            if (!$locked) {
                throw new RuntimeException('Campaign Kit artifact not found.');
            }
            $artifact = $this->hydrateJson($locked, [
                'creative_direction_json',
                'quality_json',
                'source_context_json',
                'provider_json',
                'handoff_json',
                'last_outcome_json',
            ]);
            $contentItemId = (int) ($artifact['accepted_content_item_id'] ?? 0);
            if ((string) ($artifact['status'] ?? '') !== 'accepted' || $contentItemId <= 0) {
                throw new RuntimeException('Accept this artifact into Content Studio before preparing its launch handoff.');
            }

            if ((int) ($artifact['distribution_post_id'] ?? 0) > 0
                && (int) ($artifact['utm_link_id'] ?? 0) > 0
                && (int) ($artifact['channel_media_kit_id'] ?? 0) > 0) {
                Database::commit();
                return $this->getArtifact($artifactId) ?? $artifact;
            }

            $run = $this->getRun((int) $artifact['generation_run_id']);
            if (!$run) {
                throw new RuntimeException('Campaign Kit run not found.');
            }

            $channel = (string) ($artifact['channel'] ?? 'other');
            $utmCampaign = $this->utmToken(
                (string) ($data['utm_campaign'] ?? ''),
                $this->utmToken((string) ($run['title'] ?? ''), 'campaign-kit-' . (int) $run['id'])
            );
            $utmSource = $this->utmToken((string) ($data['utm_source'] ?? ''), $channel ?: 'campaign-kit');
            $utmMedium = $this->utmToken((string) ($data['utm_medium'] ?? ''), $this->utmMediumForChannel($channel));
            $utmContent = 'kit-' . (int) $run['id'] . '-artifact-' . $artifactId;

            $utmId = $this->marketing->createUtmLink([
                'campaign_id' => (int) ($run['campaign_id'] ?? 0) ?: null,
                'content_item_id' => $contentItemId,
                'url' => $destinationUrl,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'utm_content' => $utmContent,
                'created_by' => $userId,
            ]);
            $utm = Database::queryOne(
                'SELECT * FROM marketing_utm_links WHERE workspace_id = ? AND id = ? LIMIT 1',
                [$workspaceId, $utmId]
            ) ?: [];
            $trackedUrl = (string) ($utm['generated_url'] ?? '');
            if ($trackedUrl === '') {
                throw new RuntimeException('The tracked destination could not be prepared.');
            }

            $distributionId = $this->marketing->createDistributionPost([
                'content_item_id' => $contentItemId,
                'channel' => $channel,
                'planned_copy' => (string) ($artifact['body'] ?? ''),
                'publishing_checklist' => [
                    'Confirm destination and UTM parameters',
                    'Review copy and creative for this channel',
                    'Obtain the required human approval',
                    'Publish or schedule from the owning channel workspace',
                    'Record the published URL or provider proof',
                ],
                'required_fields' => ['approved copy', 'destination', 'channel owner', 'schedule or export decision'],
                'asset_rules' => [
                    'Use only approved or rights-cleared media',
                    'Follow the Campaign Kit visual direction and channel dimensions',
                    'Do not publish automatically from this handoff',
                ],
                'status' => 'draft',
                'created_by' => $userId,
            ]);
            $mediaKitId = $this->marketing->createChannelMediaKitFromDistribution($distributionId, [
                'utm_link_id' => $utmId,
                'destination_url' => $trackedUrl,
                'metadata_json' => [
                    'source' => 'campaign_kit',
                    'generation_run_id' => (int) $run['id'],
                    'generation_artifact_id' => $artifactId,
                    'manual_export_only' => true,
                ],
            ], $userId);

            $handoff = [
                'prepared_at' => date('c'),
                'destination_url' => $destinationUrl,
                'tracked_url' => $trackedUrl,
                'utm' => [
                    'source' => $utmSource,
                    'medium' => $utmMedium,
                    'campaign' => $utmCampaign,
                    'content' => $utmContent,
                ],
                'guardrails' => [
                    'external_publish' => false,
                    'social_job_created' => false,
                    'manual_or_social_review_required' => true,
                ],
            ];
            Database::execute(
                "UPDATE marketing_generation_artifacts
                 SET distribution_post_id = ?, utm_link_id = ?, channel_media_kit_id = ?,
                     handoff_status = 'prepared', handoff_json = ?
                 WHERE workspace_id = ? AND id = ?",
                [$distributionId, $utmId, $mediaKitId, $this->json($handoff), $workspaceId, $artifactId]
            );
            Database::commit();
        } catch (Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->getArtifact($artifactId)
            ?? throw new RuntimeException('The prepared launch handoff could not be reloaded.');
    }

    /**
     * Refresh evidence-backed business outcomes for every accepted artifact in a kit.
     */
    public function refreshOutcomes(int $runId, ?int $userId = null): array
    {
        $this->assertPermission('marketing.write');
        $this->assertLaunchTables();
        if (!Database::tableExists('marketing_generation_learning_signals')) {
            throw new RuntimeException('Campaign Kit learning is unavailable until the latest database migration is installed.');
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $userId = $userId ?: (int) ($_SESSION['user_id'] ?? 0);
        $run = $this->getRun($runId);
        if (!$run) {
            throw new RuntimeException('Campaign Kit run not found.');
        }

        $totals = $this->emptyOutcomeMetrics();
        $signalCounts = [
            'insufficient_data' => 0,
            'emerging' => 0,
            'winning' => 0,
            'underperforming' => 0,
            'revenue_proven' => 0,
        ];
        $artifactOutcomes = [];
        $refreshedAt = date('Y-m-d H:i:s');

        Database::beginTransaction();
        try {
            foreach ((array) ($run['artifacts'] ?? []) as $artifact) {
                $artifactId = (int) ($artifact['id'] ?? 0);
                $contentItemId = (int) ($artifact['accepted_content_item_id'] ?? 0);
                if ($artifactId <= 0 || $contentItemId <= 0 || (string) ($artifact['status'] ?? '') !== 'accepted') {
                    continue;
                }

                $utmLinkId = (int) ($artifact['utm_link_id'] ?? 0);
                $distributionPostId = (int) ($artifact['distribution_post_id'] ?? 0);
                $metrics = $this->artifactOutcomeMetrics(
                    $workspaceId,
                    $contentItemId,
                    $utmLinkId,
                    $distributionPostId
                );
                [$signalType, $evidenceScore, $recommendation] = $this->learningSignal($metrics);
                $signalCounts[$signalType]++;
                foreach ($totals as $key => $value) {
                    if (array_key_exists($key, $metrics) && is_numeric($metrics[$key])) {
                        $totals[$key] += $metrics[$key];
                    }
                }

                $outcome = [
                    'signal_type' => $signalType,
                    'evidence_score' => $evidenceScore,
                    'metrics' => $metrics,
                    'recommendation' => $recommendation,
                    'refreshed_at' => date('c'),
                    'evidence_boundary' => 'CRM and provider records only; no estimated outcomes',
                ];
                $artifactOutcomes[] = [
                    'artifact_id' => $artifactId,
                    'channel' => (string) ($artifact['channel'] ?? 'other'),
                    'signal_type' => $signalType,
                    'evidence_score' => $evidenceScore,
                    'recommendation' => $recommendation,
                ];

                Database::execute(
                    "INSERT INTO marketing_generation_learning_signals
                     (workspace_id, generation_run_id, generation_artifact_id, content_item_id,
                      distribution_post_id, utm_link_id, signal_type, evidence_score, metrics_json,
                      recommendation, first_observed_at, last_observed_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        content_item_id = VALUES(content_item_id),
                        distribution_post_id = VALUES(distribution_post_id),
                        utm_link_id = VALUES(utm_link_id),
                        signal_type = VALUES(signal_type),
                        evidence_score = VALUES(evidence_score),
                        metrics_json = VALUES(metrics_json),
                        recommendation = VALUES(recommendation),
                        first_observed_at = COALESCE(first_observed_at, VALUES(first_observed_at)),
                        last_observed_at = VALUES(last_observed_at)",
                    [
                        $workspaceId,
                        $runId,
                        $artifactId,
                        $contentItemId,
                        $distributionPostId ?: null,
                        $utmLinkId ?: null,
                        $signalType,
                        $evidenceScore,
                        $this->json($metrics),
                        $recommendation,
                        $evidenceScore > 0 ? $refreshedAt : null,
                        $refreshedAt,
                        $userId > 0 ? $userId : null,
                    ]
                );

                $handoffStatus = $this->resolvedHandoffStatus($artifact, $metrics);
                Database::execute(
                    "UPDATE marketing_generation_artifacts
                     SET last_outcome_json = ?, last_outcome_at = ?, handoff_status = ?
                     WHERE workspace_id = ? AND id = ?",
                    [$this->json($outcome), $refreshedAt, $handoffStatus, $workspaceId, $artifactId]
                );
            }

            $totals['conversion_rate'] = $totals['unique_visitors'] > 0
                ? round(($totals['conversions'] / $totals['unique_visitors']) * 100, 2)
                : 0.0;

            $aggregate = [
                'totals' => $totals,
                'signal_counts' => $signalCounts,
                'artifacts' => $artifactOutcomes,
                'accepted_artifact_count' => count($artifactOutcomes),
                'refreshed_at' => date('c'),
                'evidence_boundary' => 'CRM tracking, conversions, attribution, and latest provider metric snapshots only',
            ];
            Database::execute(
                'UPDATE marketing_generation_runs SET outcome_json = ?, outcome_refreshed_at = ? WHERE workspace_id = ? AND id = ?',
                [$this->json($aggregate), $refreshedAt, $workspaceId, $runId]
            );
            Database::commit();
        } catch (Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->getRun($runId)
            ?? throw new RuntimeException('The refreshed Campaign Kit could not be reloaded.');
    }

    private function getArtifact(int $artifactId): ?array
    {
        if (!Database::tableExists('marketing_generation_artifacts')) {
            return null;
        }
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $row = Database::queryOne(
            "SELECT * FROM marketing_generation_artifacts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $artifactId]
        );

        return $row ? $this->hydrateJson($row, [
            'creative_direction_json',
            'quality_json',
            'source_context_json',
            'provider_json',
            'handoff_json',
            'last_outcome_json',
        ]) : null;
    }

    private function insertArtifact(
        int $workspaceId,
        int $runId,
        string $channel,
        string $contentType,
        string $title,
        string $body,
        array $creativeDirection,
        array $quality,
        array $sourceContext,
        array $provider,
        int $ordinal,
        string $status
    ): void {
        Database::execute(
            "INSERT INTO marketing_generation_artifacts
             (workspace_id, generation_run_id, uuid, artifact_type, channel, content_type, status, title, body,
              creative_direction_json, quality_json, source_context_json, provider_json, ordinal)
             VALUES (?, ?, ?, 'channel_copy', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $runId,
                $this->uuid(),
                $channel,
                $contentType,
                $status,
                $title,
                $body !== '' ? $body : null,
                $this->json($creativeDirection),
                $this->json($quality),
                $this->json($sourceContext),
                $this->json($provider),
                $ordinal,
            ]
        );
    }

    private function normalizeRunInput(array $input, int $workspaceId): array
    {
        $title = $this->cleanText($input['title'] ?? '', 255);
        $objective = $this->cleanText($input['objective'] ?? '', 2000, true);
        $targetAudience = $this->cleanText($input['target_audience'] ?? '', 2000, true);
        if ($title === '' || $objective === '' || $targetAudience === '') {
            throw new \InvalidArgumentException('Campaign name, business objective, and target audience are required.');
        }

        $channels = $input['channels'] ?? [];
        if (is_string($channels)) {
            $channels = array_filter(array_map('trim', explode(',', $channels)));
        }
        $channels = array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtolower(trim((string) $value)),
            is_array($channels) ? $channels : []
        ), static fn(string $channel): bool => in_array($channel, Marketing::CHANNELS, true) && $channel !== 'other')));
        if ($channels === []) {
            throw new \InvalidArgumentException('Choose at least one campaign channel.');
        }
        if (count($channels) > self::MAX_CHANNELS) {
            throw new \InvalidArgumentException('Choose no more than ' . self::MAX_CHANNELS . ' channels per Campaign Kit.');
        }

        $linked = [
            'campaign_id' => ['campaigns', (int) ($input['campaign_id'] ?? 0)],
            'campaign_brief_id' => ['marketing_campaign_briefs', (int) ($input['campaign_brief_id'] ?? 0)],
            'brand_profile_id' => ['marketing_brand_profiles', (int) ($input['brand_profile_id'] ?? 0)],
            'persona_id' => ['marketing_personas', (int) ($input['persona_id'] ?? 0)],
            'audience_segment_id' => ['marketing_audience_segments', (int) ($input['audience_segment_id'] ?? 0)],
        ];
        foreach ($linked as [$table, $id]) {
            if ($id > 0) {
                $this->workspaceScope->assertSameWorkspace($table, $id, $workspaceId);
            }
        }

        return [
            'title' => $title,
            'objective' => $objective,
            'target_audience' => $targetAudience,
            'offer_text' => $this->cleanText($input['offer_text'] ?? '', 4000, true) ?: null,
            'cta_text' => $this->cleanText($input['cta_text'] ?? '', 500) ?: null,
            'funnel_stage' => $this->cleanText($input['funnel_stage'] ?? 'consideration', 80) ?: 'consideration',
            'campaign_id' => $linked['campaign_id'][1] ?: null,
            'campaign_brief_id' => $linked['campaign_brief_id'][1] ?: null,
            'brand_profile_id' => $linked['brand_profile_id'][1] ?: null,
            'persona_id' => $linked['persona_id'][1] ?: null,
            'audience_segment_id' => $linked['audience_segment_id'][1] ?: null,
            'channels' => $channels,
        ];
    }

    private function messageHouse(array $input, array $learningContext = []): array
    {
        $offer = trim((string) ($input['offer_text'] ?? ''));
        $cta = trim((string) ($input['cta_text'] ?? ''));

        return [
            'campaign_promise' => $offer !== '' ? $offer : (string) $input['objective'],
            'audience_need' => (string) $input['target_audience'],
            'core_message' => (string) $input['title'] . ' helps ' . (string) $input['target_audience'] . ' achieve ' . (string) $input['objective'] . '.',
            'supporting_points' => array_values(array_filter([
                $offer !== '' ? 'Lead with the offer: ' . $offer : null,
                'Tie every claim to the business objective: ' . (string) $input['objective'],
                'Keep the audience and their next decision explicit.',
            ])),
            'cta' => $cta !== '' ? $cta : 'Invite the audience to take one clear next step.',
            'guardrails' => [
                'Do not invent customer proof, prices, guarantees, or performance claims.',
                'Keep one recognizable campaign idea across every channel.',
                'Adapt format and length without changing the offer or primary CTA.',
                'Use prior outcome signals as guidance only; never copy a past claim or assume it will perform again.',
            ],
            'evidence_backed_patterns' => $learningContext,
        ];
    }

    private function generationObjective(array $input, array $messageHouse): string
    {
        $parts = [
            (string) $input['objective'],
            'Core campaign message: ' . (string) $messageHouse['core_message'],
            'Primary CTA: ' . (string) $messageHouse['cta'],
        ];
        if (!empty($input['offer_text'])) {
            $parts[] = 'Offer: ' . (string) $input['offer_text'];
        }
        $patterns = array_slice((array) ($messageHouse['evidence_backed_patterns'] ?? []), 0, 3);
        if ($patterns !== []) {
            $guidance = [];
            foreach ($patterns as $pattern) {
                $line = $this->channelLabel((string) ($pattern['channel'] ?? 'other'))
                    . ' prior signal: ' . (string) ($pattern['signal_type'] ?? 'winning') . '.';
                if (!empty($pattern['offer'])) {
                    $line .= ' Prior offer pattern: ' . (string) $pattern['offer'] . '.';
                }
                if (!empty($pattern['cta'])) {
                    $line .= ' Prior CTA pattern: ' . (string) $pattern['cta'] . '.';
                }
                if (!empty($pattern['recommendation'])) {
                    $line .= ' Recorded recommendation: ' . (string) $pattern['recommendation'];
                }
                $guidance[] = $line;
            }
            $parts[] = 'Workspace performance evidence to consider, not copy blindly: '
                . $this->cleanText(implode(' ', $guidance), 1800, true);
        }

        return implode(' ', $parts);
    }

    private function reusableLearningContext(int $workspaceId, array $channels): array
    {
        if (!Database::tableExists('marketing_generation_learning_signals')) {
            return [];
        }
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));
        if ($channels === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($channels), '?'));
        $rows = Database::query(
            "SELECT ls.signal_type, ls.evidence_score, ls.metrics_json, ls.recommendation,
                    a.channel, a.title AS artifact_title,
                    r.title AS campaign_title, r.offer_text, r.cta_text, r.target_audience
             FROM marketing_generation_learning_signals ls
             JOIN marketing_generation_artifacts a
               ON a.id = ls.generation_artifact_id AND a.workspace_id = ls.workspace_id
             JOIN marketing_generation_runs r
               ON r.id = ls.generation_run_id AND r.workspace_id = ls.workspace_id
             WHERE ls.workspace_id = ?
               AND ls.signal_type IN ('revenue_proven','winning')
               AND a.channel IN ({$placeholders})
             ORDER BY FIELD(ls.signal_type, 'revenue_proven', 'winning'),
                      ls.evidence_score DESC, ls.last_observed_at DESC
             LIMIT 6",
            array_merge([$workspaceId], $channels)
        );

        return array_map(function (array $row): array {
            $metrics = json_decode((string) ($row['metrics_json'] ?? ''), true);
            $metrics = is_array($metrics) ? $metrics : [];
            return [
                'channel' => (string) ($row['channel'] ?? 'other'),
                'signal_type' => (string) ($row['signal_type'] ?? 'winning'),
                'evidence_score' => (int) ($row['evidence_score'] ?? 0),
                'source_campaign' => $this->cleanText($row['campaign_title'] ?? '', 255),
                'source_artifact' => $this->cleanText($row['artifact_title'] ?? '', 255),
                'audience' => $this->cleanText($row['target_audience'] ?? '', 500, true),
                'offer' => $this->cleanText($row['offer_text'] ?? '', 500, true),
                'cta' => $this->cleanText($row['cta_text'] ?? '', 300),
                'recorded_revenue' => round((float) ($metrics['revenue'] ?? 0), 2),
                'recorded_conversions' => (int) ($metrics['conversions'] ?? 0),
                'recommendation' => $this->cleanText($row['recommendation'] ?? '', 700, true),
                'reuse_boundary' => 'guidance_only_human_review_required',
            ];
        }, $rows);
    }

    private function creativeDirection(string $channel, array $input, array $messageHouse): array
    {
        $ratio = match ($channel) {
            'instagram' => '4:5',
            'facebook', 'linkedin' => '1.91:1',
            'x', 'email', 'blog', 'youtube', 'website' => '16:9',
            default => '1:1',
        };
        $prompt = 'Create a polished ' . $this->channelLabel($channel) . ' campaign visual for "' . $input['title'] . '". '
            . 'Audience: ' . $input['target_audience'] . '. '
            . 'Core message: ' . $messageHouse['core_message'] . ' '
            . 'Use a clean, credible business style with a clear focal point and enough negative space for optional copy. '
            . 'Do not add logos, prices, testimonials, statistics, or claims that are not supplied. '
            . 'Target aspect ratio: ' . $ratio . '.';

        return [
            'prompt' => $prompt,
            'channel' => $channel,
            'aspect_ratio' => $ratio,
            'safe_text_overlay' => (string) $input['title'],
            'alt_text_direction' => 'Describe the main subject and its relationship to the campaign objective without promotional claims.',
            'external_generation' => false,
            'design_handoff_available' => true,
        ];
    }

    private function draftQuality(string $body, string $cta, string $channel): array
    {
        $wordCount = str_word_count(strip_tags($body));
        $hasCta = $cta !== ''
            ? stripos($body, $cta) !== false
            : preg_match('/\b(reply|book|learn|start|contact|download|visit|join|call)\b/i', $body) === 1;
        $minimum = in_array($channel, ['x', 'sms'], true) ? 8 : 20;
        $warnings = [];
        if ($wordCount < $minimum) {
            $warnings[] = 'Draft may be too short to communicate the offer clearly.';
        }
        if (!$hasCta) {
            $warnings[] = 'Review the draft and make the next action more explicit.';
        }
        if (preg_match('/\b(guaranteed|best ever|100%|risk-free)\b/i', $body) === 1) {
            $warnings[] = 'Review absolute or unverified promotional claims.';
        }

        return [
            'score' => max(0, 100 - (count($warnings) * 20)),
            'word_count' => $wordCount,
            'cta_present' => $hasCta,
            'warnings' => $warnings,
            'method' => 'deterministic_preflight',
            'human_review_required' => true,
        ];
    }

    private function artifactOutcomeMetrics(
        int $workspaceId,
        int $contentItemId,
        int $utmLinkId,
        int $distributionPostId
    ): array {
        $metrics = $this->emptyOutcomeMetrics();

        if (Database::tableExists('marketing_tracking_events')) {
            [$where, $params] = $this->contentEvidenceWhere('te', $workspaceId, $contentItemId, $utmLinkId);
            $tracking = Database::queryOne(
                "SELECT COUNT(*) AS tracking_events,
                        COUNT(DISTINCT te.visitor_session_id) AS unique_visitors,
                        COALESCE(SUM(te.event_type = 'page_view'), 0) AS page_views,
                        COALESCE(SUM(te.event_type = 'cta_click'), 0) AS cta_clicks,
                        COALESCE(SUM(te.event_type = 'form_start'), 0) AS form_starts,
                        COALESCE(SUM(te.event_type = 'form_submit'), 0) AS form_submits,
                        COALESCE(SUM(te.event_type = 'conversion'), 0) AS tracked_conversions
                 FROM marketing_tracking_events te
                 WHERE {$where}",
                $params
            ) ?: [];
            foreach (['tracking_events', 'unique_visitors', 'page_views', 'cta_clicks', 'form_starts', 'form_submits'] as $field) {
                $metrics[$field] = (int) ($tracking[$field] ?? 0);
            }
            $trackedConversions = (int) ($tracking['tracked_conversions'] ?? 0);

            if (Database::tableExists('marketing_conversion_events')) {
                $conversion = Database::queryOne(
                    "SELECT COUNT(DISTINCT ce.id) AS conversions,
                            COUNT(DISTINCT ce.contact_id) AS contacts,
                            COUNT(DISTINCT ce.deal_id) AS deals,
                            COALESCE(SUM(ce.conversion_value), 0) AS conversion_value
                     FROM marketing_conversion_events ce
                     JOIN marketing_tracking_events te
                       ON te.id = ce.tracking_event_id AND te.workspace_id = ce.workspace_id
                     WHERE {$where}",
                    $params
                ) ?: [];
                $metrics['conversions'] = max($trackedConversions, (int) ($conversion['conversions'] ?? 0));
                $metrics['contacts'] = (int) ($conversion['contacts'] ?? 0);
                $metrics['deals'] = (int) ($conversion['deals'] ?? 0);
                $metrics['conversion_value'] = round((float) ($conversion['conversion_value'] ?? 0), 2);
            } else {
                $metrics['conversions'] = $trackedConversions;
            }
        }

        if (Database::tableExists('marketing_attribution_touchpoints')) {
            [$where, $params] = $this->contentEvidenceWhere('atp', $workspaceId, $contentItemId, $utmLinkId);
            $attribution = Database::queryOne(
                "SELECT COUNT(*) AS attribution_touchpoints,
                        COUNT(DISTINCT atp.contact_id) AS contacts,
                        COUNT(DISTINCT atp.deal_id) AS deals,
                        COALESCE(SUM(atp.revenue_amount), 0) AS revenue
                 FROM marketing_attribution_touchpoints atp
                 WHERE {$where}",
                $params
            ) ?: [];
            $metrics['attribution_touchpoints'] = (int) ($attribution['attribution_touchpoints'] ?? 0);
            $metrics['contacts'] = max($metrics['contacts'], (int) ($attribution['contacts'] ?? 0));
            $metrics['deals'] = max($metrics['deals'], (int) ($attribution['deals'] ?? 0));
            $metrics['revenue'] = round((float) ($attribution['revenue'] ?? 0), 2);
        }

        if (Database::tableExists('social_media_publish_jobs') && Database::tableExists('social_media_metric_snapshots')) {
            $conditions = ['j.content_item_id = ?'];
            $params = [$workspaceId, $contentItemId];
            if ($distributionPostId > 0) {
                $conditions[] = 'j.distribution_post_id = ?';
                $params[] = $distributionPostId;
            }
            $social = Database::queryOne(
                "SELECT COUNT(DISTINCT j.id) AS social_jobs,
                        COALESCE(SUM(j.status = 'published'), 0) AS social_published_jobs,
                        COALESCE(SUM(j.status IN ('pending_approval','approved','queued','processing')), 0) AS social_active_jobs,
                        COALESCE(SUM(sm.impressions), 0) AS impressions,
                        COALESCE(SUM(sm.reach), 0) AS reach,
                        COALESCE(SUM(sm.engagements), 0) AS engagements,
                        COALESCE(SUM(sm.clicks), 0) AS social_clicks,
                        COALESCE(SUM(sm.video_views), 0) AS video_views
                 FROM social_media_publish_jobs j
                 LEFT JOIN social_media_metric_snapshots sm
                   ON sm.id = (
                        SELECT sm2.id
                        FROM social_media_metric_snapshots sm2
                        WHERE sm2.workspace_id = j.workspace_id AND sm2.publish_job_id = j.id
                        ORDER BY sm2.captured_at DESC, sm2.id DESC
                        LIMIT 1
                   )
                 WHERE j.workspace_id = ? AND (" . implode(' OR ', $conditions) . ')',
                $params
            ) ?: [];
            foreach (['social_jobs', 'social_published_jobs', 'social_active_jobs', 'impressions', 'reach', 'engagements', 'social_clicks', 'video_views'] as $field) {
                $metrics[$field] = (int) ($social[$field] ?? 0);
            }
        }

        $metrics['conversion_rate'] = $metrics['unique_visitors'] > 0
            ? round(($metrics['conversions'] / $metrics['unique_visitors']) * 100, 2)
            : 0.0;

        return $metrics;
    }

    private function contentEvidenceWhere(string $alias, int $workspaceId, int $contentItemId, int $utmLinkId): array
    {
        $conditions = ["{$alias}.content_item_id = ?"];
        $params = [$workspaceId, $contentItemId];
        if ($utmLinkId > 0) {
            $conditions[] = "{$alias}.utm_link_id = ?";
            $params[] = $utmLinkId;
        }

        return ["{$alias}.workspace_id = ? AND (" . implode(' OR ', $conditions) . ')', $params];
    }

    private function emptyOutcomeMetrics(): array
    {
        return [
            'tracking_events' => 0,
            'unique_visitors' => 0,
            'page_views' => 0,
            'cta_clicks' => 0,
            'form_starts' => 0,
            'form_submits' => 0,
            'conversions' => 0,
            'contacts' => 0,
            'deals' => 0,
            'conversion_value' => 0.0,
            'attribution_touchpoints' => 0,
            'revenue' => 0.0,
            'social_jobs' => 0,
            'social_published_jobs' => 0,
            'social_active_jobs' => 0,
            'impressions' => 0,
            'reach' => 0,
            'engagements' => 0,
            'social_clicks' => 0,
            'video_views' => 0,
            'conversion_rate' => 0.0,
        ];
    }

    private function learningSignal(array $metrics): array
    {
        $visitors = (int) ($metrics['unique_visitors'] ?? 0);
        $views = (int) ($metrics['page_views'] ?? 0);
        $clicks = (int) ($metrics['cta_clicks'] ?? 0) + (int) ($metrics['social_clicks'] ?? 0);
        $conversions = (int) ($metrics['conversions'] ?? 0);
        $deals = (int) ($metrics['deals'] ?? 0);
        $revenue = (float) ($metrics['revenue'] ?? 0);
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $engagements = (int) ($metrics['engagements'] ?? 0);

        $score = min(100, (int) round(
            min(20, $visitors)
            + min(15, $views / 2)
            + min(15, $clicks * 3)
            + min(15, $engagements / 2)
            + min(25, $conversions * 10)
            + min(20, $deals * 20)
            + ($revenue > 0 ? 25 : 0)
        ));

        if ($revenue > 0) {
            return [
                'revenue_proven',
                max(90, $score),
                'Preserve this message, offer, CTA, and channel pattern as a reusable campaign template; it has CRM-attributed revenue evidence.',
            ];
        }
        if ($deals >= 1 || $conversions >= 2) {
            return [
                'winning',
                max(70, $score),
                'Reuse the strongest parts of this artifact and test one controlled variation before changing the campaign promise.',
            ];
        }
        if (($visitors >= 20 || $clicks >= 10 || $impressions >= 1000) && $conversions === 0 && $deals === 0) {
            return [
                'underperforming',
                max(60, $score),
                'Traffic exists without a recorded outcome. Review offer clarity, CTA strength, destination continuity, and tracking before increasing distribution.',
            ];
        }
        if (($visitors + $views + $clicks + $impressions + $engagements + $conversions) > 0) {
            return [
                'emerging',
                max(25, $score),
                'Early evidence is present but not decisive. Keep the artifact stable long enough to collect more comparable visits, clicks, and CRM outcomes.',
            ];
        }

        return [
            'insufficient_data',
            0,
            'No attributable evidence is available yet. Prepare a tracked launch, publish through the owning channel, and refresh after activity is recorded.',
        ];
    }

    private function resolvedHandoffStatus(array $artifact, array $metrics): string
    {
        if ((int) ($metrics['social_published_jobs'] ?? 0) > 0
            || (string) ($artifact['distribution_status'] ?? '') === 'published') {
            return 'published';
        }
        if ((int) ($metrics['social_active_jobs'] ?? 0) > 0
            || (string) ($artifact['distribution_status'] ?? '') === 'scheduled') {
            return 'scheduled';
        }
        if ((string) ($artifact['distribution_status'] ?? '') === 'exported'
            || (string) ($artifact['channel_media_kit_status'] ?? '') === 'exported') {
            return 'exported';
        }

        return (int) ($artifact['distribution_post_id'] ?? 0) > 0 ? 'prepared' : 'not_prepared';
    }

    private function utmMediumForChannel(string $channel): string
    {
        return match ($channel) {
            'email', 'newsletter' => 'email',
            'ads' => 'paid-social',
            'blog', 'website', 'youtube' => 'content',
            'sms' => 'sms',
            'whatsapp' => 'messaging',
            default => 'organic-social',
        };
    }

    private function utmToken(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        $value = trim($value, '-_');
        if ($value === '') {
            $value = $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 160) : substr($value, 0, 160);
    }

    private function stateSummary(array $artifacts): array
    {
        $summary = ['total' => count($artifacts), 'generated' => 0, 'accepted' => 0, 'rejected' => 0, 'failed' => 0];
        foreach ($artifacts as $artifact) {
            $status = (string) ($artifact['status'] ?? 'failed');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    private function refreshRunStatus(int $runId, int $workspaceId): void
    {
        $counts = Database::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'accepted') AS accepted_count,
                    SUM(status = 'failed') AS failed_count
             FROM marketing_generation_artifacts
             WHERE workspace_id = ? AND generation_run_id = ?",
            [$workspaceId, $runId]
        ) ?: [];
        $total = (int) ($counts['total'] ?? 0);
        $accepted = (int) ($counts['accepted_count'] ?? 0);
        $failed = (int) ($counts['failed_count'] ?? 0);
        $status = 'ready';
        if ($total > 0 && $accepted === $total) {
            $status = 'accepted';
        } elseif ($accepted > 0) {
            $status = 'partially_accepted';
        } elseif ($total > 0 && $failed === $total) {
            $status = 'failed';
        }
        Database::execute(
            "UPDATE marketing_generation_runs SET status = ? WHERE workspace_id = ? AND id = ?",
            [$status, $workspaceId, $runId]
        );
    }

    private function contentTypeForChannel(string $channel): string
    {
        return match ($channel) {
            'blog' => 'blog_post',
            'email' => 'email',
            'newsletter' => 'newsletter',
            'whatsapp' => 'whatsapp',
            'sms' => 'sms',
            'ads' => 'ad_copy',
            'youtube' => 'video_script',
            default => 'social_post',
        };
    }

    private function channelLabel(string $channel): string
    {
        return $channel === 'x' ? 'X' : ucwords(str_replace('_', ' ', $channel));
    }

    private function hydrateJson(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            $row[$field] = is_array($value)
                ? $value
                : (is_string($value) && $value !== '' ? (json_decode($value, true) ?: []) : []);
        }

        return $row;
    }

    private function cleanText(mixed $value, int $maxLength, bool $multiline = false): string
    {
        $value = str_replace("\0", '', (string) $value);
        $value = strip_tags($value);
        if (!$multiline) {
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        } else {
            $value = preg_replace("/\r\n?|\n/", "\n", $value) ?? $value;
        }
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Campaign Kit data could not be encoded.');
        }

        return $json;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function assertPermission(string $permission): void
    {
        if (!Authorization::can($permission, Auth::user())) {
            throw new RuntimeException('You do not have permission to use Campaign Kit generation.');
        }
    }

    private function assertTables(): void
    {
        foreach (['marketing_generation_runs', 'marketing_generation_artifacts'] as $table) {
            if (!Database::tableExists($table)) {
                throw new RuntimeException('Campaign Kit generation is unavailable until the latest database migration is installed.');
            }
        }
    }

    private function assertLaunchTables(): void
    {
        $this->assertTables();
        foreach (['marketing_distribution_posts', 'marketing_utm_links', 'marketing_channel_media_kits'] as $table) {
            if (!Database::tableExists($table)) {
                throw new RuntimeException('Campaign Kit launch handoffs are unavailable until the latest database migrations are installed.');
            }
        }
        foreach (['distribution_post_id', 'utm_link_id', 'channel_media_kit_id', 'handoff_json', 'last_outcome_json'] as $column) {
            if (!Database::columnExists('marketing_generation_artifacts', $column)) {
                throw new RuntimeException('Campaign Kit launch handoffs are unavailable until the latest database migration is installed.');
            }
        }
    }
}
