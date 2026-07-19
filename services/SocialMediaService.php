<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Workspace-scoped production runtime for the Social Media Marketplace plugin.
 *
 * The service deliberately owns provider tokens, approvals, idempotency,
 * publishing retries, and provider evidence separately from the broad Marketing
 * module. Every public mutation asserts that the plugin is installed.
 */
class SocialMediaService
{
    public const PROVIDERS = ['meta', 'linkedin'];
    public const CHANNELS = ['facebook', 'instagram', 'linkedin'];
    public const JOB_STATUSES = [
        'draft', 'pending_approval', 'approved', 'queued', 'processing',
        'published', 'failed', 'cancelled',
    ];

    private int $workspaceId;
    private OAuthTokenVault $vault;
    private bool $installedVerified = false;
    private array $lastVariantGenerationStatus = [
        'used_ai' => false,
        'fallback_used' => true,
        'message' => 'No variants have been generated yet.',
    ];
    /** @var callable|null */
    private $httpClient;

    public function __construct(?int $workspaceId = null, ?OAuthTokenVault $vault = null, ?callable $httpClient = null)
    {
        $workspaceId ??= (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new RuntimeException('Select a workspace before using Social Media.');
        }

        $this->workspaceId = $workspaceId;
        $this->vault = $vault ?? new OAuthTokenVault();
        $this->httpClient = $httpClient;
    }

    public function assertInstalled(): void
    {
        if ($this->installedVerified) {
            return;
        }
        if (!(new WorkspaceSkillInstallService())->isInstalled($this->workspaceId, WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA)) {
            throw new RuntimeException('Install the Social Media plugin before using social publishing features.');
        }
        $this->installedVerified = true;
    }

    public function platformReadiness(): array
    {
        $this->assertInstalled();
        $metaReady = $this->env('META_APP_ID') !== '' && $this->env('META_APP_SECRET') !== '';
        $linkedinReady = $this->env('LINKEDIN_CLIENT_ID') !== '' && $this->env('LINKEDIN_CLIENT_SECRET') !== '';

        return [
            'meta' => [
                'configured' => $metaReady,
                'label' => 'Facebook & Instagram',
                'message' => $metaReady
                    ? 'Meta OAuth credentials are configured.'
                    : 'Set META_APP_ID and META_APP_SECRET before connecting Facebook or Instagram.',
            ],
            'linkedin' => [
                'configured' => $linkedinReady,
                'label' => 'LinkedIn',
                'message' => $linkedinReady
                    ? 'LinkedIn OAuth credentials are configured.'
                    : 'Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET before connecting LinkedIn.',
            ],
            'worker_command' => 'php cli/process_social_media_queue.php all 25',
            'metrics_command' => 'php cli/process_social_media_metrics.php all 50',
        ];
    }

    public function contentGenerationReadiness(): array
    {
        $resolved = (new WorkspaceAIProviderResolverService())->resolveForWorkspace(
            $this->workspaceId,
            'social_post_variants',
            WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
        );

        return [
            'available' => !empty($resolved['available']),
            'source' => (string) ($resolved['source'] ?? WorkspaceAIProviderResolverService::SOURCE_WORKSPACE_API),
            'blocked_reason' => (string) ($resolved['blocked_reason'] ?? ''),
            'message' => (string) ($resolved['message'] ?? 'Content Generation key is not configured.'),
            'common_daily_token_cap' => max(0, (int) ($resolved['common_daily_token_cap'] ?? 0)),
            'common_used_today' => max(0, (int) ($resolved['common_used_today'] ?? 0)),
            'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
        ];
    }

    public function getLastVariantGenerationStatus(): array
    {
        return $this->lastVariantGenerationStatus;
    }

    public function getSettings(): array
    {
        $this->assertInstalled();
        $row = Database::queryOne(
            'SELECT * FROM social_media_settings WHERE workspace_id = ? LIMIT 1',
            [$this->workspaceId]
        );

        return array_merge([
            'workspace_id' => $this->workspaceId,
            'enabled' => 1,
            'approval_required' => 1,
            'timezone' => 'Africa/Nairobi',
            'brand_name' => '',
            'brand_voice' => '',
            'target_audience' => '',
            'products_services' => '',
            'approved_claims' => '',
            'prohibited_claims' => '',
            'preferred_cta' => '',
            'default_utm_source' => 'social',
            'default_utm_medium' => 'organic_social',
            'metrics_sync_enabled' => 1,
        ], $row ?: []);
    }

    public function saveSettings(array $data, int $userId): array
    {
        $this->assertInstalled();
        $timezone = trim((string) ($data['timezone'] ?? 'Africa/Nairobi'));
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new RuntimeException('Choose a valid timezone.');
        }

        $values = [
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'approval_required' => !empty($data['approval_required']) ? 1 : 0,
            'timezone' => mb_substr($timezone, 0, 80),
            'brand_name' => $this->clean($data['brand_name'] ?? '', 180),
            'brand_voice' => $this->cleanMultiline($data['brand_voice'] ?? '', 6000),
            'target_audience' => $this->cleanMultiline($data['target_audience'] ?? '', 6000),
            'products_services' => $this->cleanMultiline($data['products_services'] ?? '', 6000),
            'approved_claims' => $this->cleanMultiline($data['approved_claims'] ?? '', 6000),
            'prohibited_claims' => $this->cleanMultiline($data['prohibited_claims'] ?? '', 6000),
            'preferred_cta' => $this->cleanMultiline($data['preferred_cta'] ?? '', 2000),
            'default_utm_source' => $this->utmValue($data['default_utm_source'] ?? 'social', 'social'),
            'default_utm_medium' => $this->utmValue($data['default_utm_medium'] ?? 'organic_social', 'organic_social'),
            'metrics_sync_enabled' => !empty($data['metrics_sync_enabled']) ? 1 : 0,
        ];

        Database::execute(
            "INSERT INTO social_media_settings
             (workspace_id, enabled, approval_required, timezone, brand_name, brand_voice, target_audience,
              products_services, approved_claims, prohibited_claims, preferred_cta, default_utm_source,
              default_utm_medium, metrics_sync_enabled, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), approval_required = VALUES(approval_required), timezone = VALUES(timezone),
                brand_name = VALUES(brand_name), brand_voice = VALUES(brand_voice), target_audience = VALUES(target_audience),
                products_services = VALUES(products_services), approved_claims = VALUES(approved_claims),
                prohibited_claims = VALUES(prohibited_claims), preferred_cta = VALUES(preferred_cta),
                default_utm_source = VALUES(default_utm_source), default_utm_medium = VALUES(default_utm_medium),
                metrics_sync_enabled = VALUES(metrics_sync_enabled), updated_by = VALUES(updated_by)",
            [
                $this->workspaceId, $values['enabled'], $values['approval_required'], $values['timezone'],
                $values['brand_name'], $values['brand_voice'], $values['target_audience'], $values['products_services'],
                $values['approved_claims'], $values['prohibited_claims'], $values['preferred_cta'],
                $values['default_utm_source'], $values['default_utm_medium'], $values['metrics_sync_enabled'],
                $userId > 0 ? $userId : null, $userId > 0 ? $userId : null,
            ]
        );
        $this->event('settings_saved', 'success', 'workspace', null, 'Social Media settings saved.', [], $userId);

        return $this->getSettings();
    }

    public function listAccounts(bool $includeDisconnected = false): array
    {
        $this->assertInstalled();
        $where = $includeDisconnected ? '' : " AND status <> 'disconnected'";
        $rows = Database::query(
            "SELECT id, workspace_id, uuid, provider, channel, external_account_id, parent_account_id,
                    account_name, account_handle, status, scopes_json, token_expires_at, last_verified_at,
                    last_metrics_sync_at, last_error, metadata_json, connected_by, created_at, updated_at
             FROM social_media_accounts
             WHERE workspace_id = ?{$where}
             ORDER BY FIELD(status, 'active','pending','expired','error','revoked','disconnected'), account_name ASC",
            [$this->workspaceId]
        );

        return array_map(fn(array $row): array => $this->hydrateAccount($row), $rows);
    }

    public function getAccount(int $accountId, bool $includeToken = false): ?array
    {
        $this->assertInstalled();
        $columns = $includeToken
            ? '*'
            : "id, workspace_id, uuid, provider, channel, external_account_id, parent_account_id,
               account_name, account_handle, status, scopes_json, token_expires_at, last_verified_at,
               last_metrics_sync_at, last_error, metadata_json, connected_by, created_at, updated_at";
        $row = Database::queryOne(
            "SELECT {$columns} FROM social_media_accounts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$this->workspaceId, $accountId]
        );

        return $row ? $this->hydrateAccount($row, $includeToken) : null;
    }

    public function beginOAuth(string $provider, int $userId, string $returnPath = ''): array
    {
        $this->assertInstalled();
        $provider = $this->provider($provider);
        $config = $this->providerConfig($provider);
        if (empty($config['configured'])) {
            throw new RuntimeException((string) $config['missing_message']);
        }
        if ($userId <= 0) {
            throw new RuntimeException('Sign in again before connecting a social account.');
        }

        Database::execute(
            "DELETE FROM social_media_oauth_states
             WHERE workspace_id = ? AND (expires_at < NOW() OR consumed_at IS NOT NULL)
               AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$this->workspaceId]
        );

        $state = bin2hex(random_bytes(32));
        $safeReturnPath = $this->safeReturnPath($returnPath);
        Database::execute(
            "INSERT INTO social_media_oauth_states
             (workspace_id, provider, state_hash, requested_by, return_path, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))",
            [$this->workspaceId, $provider, hash('sha256', $state), $userId, $safeReturnPath]
        );
        $this->event('oauth_started', 'info', 'workspace', null, ucfirst($provider) . ' connection started.', [], $userId);

        $query = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode($provider === 'meta' ? ',' : ' ', (array) $config['scopes']),
        ];
        if ($provider === 'meta') {
            $query['auth_type'] = 'rerequest';
        }

        return [
            'provider' => $provider,
            'url' => $config['authorize_url'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'expires_in' => 900,
        ];
    }

    public function completeOAuth(string $provider, string $state, string $code, int $userId): array
    {
        $this->assertInstalled();
        $provider = $this->provider($provider);
        $state = trim($state);
        $code = trim($code);
        if ($state === '' || $code === '') {
            throw new RuntimeException('The social connection response is incomplete.');
        }

        $stateRow = Database::queryOne(
            "SELECT * FROM social_media_oauth_states
             WHERE workspace_id = ? AND provider = ? AND state_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
             LIMIT 1",
            [$this->workspaceId, $provider, hash('sha256', $state)]
        );
        if (!$stateRow || (int) ($stateRow['requested_by'] ?? 0) !== $userId) {
            throw new RuntimeException('This social connection request expired or does not belong to the signed-in user.');
        }
        $claimed = Database::execute(
            'UPDATE social_media_oauth_states SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL',
            [(int) $stateRow['id']]
        );
        if ($claimed !== 1) {
            throw new RuntimeException('This social connection response has already been used.');
        }

        $config = $this->providerConfig($provider);
        $token = $this->exchangeOAuthCode($provider, $code, $config);
        $accounts = $provider === 'meta'
            ? $this->discoverMetaAccounts($token, $userId)
            : $this->discoverLinkedInAccounts($token, $userId);

        if ($accounts === []) {
            throw new RuntimeException($provider === 'meta'
                ? 'No manageable Facebook Page or connected Instagram Professional account was returned.'
                : 'No LinkedIn Company Page with administrator access was returned.');
        }

        return [
            'provider' => $provider,
            'connected_count' => count($accounts),
            'accounts' => $accounts,
            'return_path' => $this->safeReturnPath((string) ($stateRow['return_path'] ?? '')),
        ];
    }

    public function verifyAccount(int $accountId, int $userId = 0): array
    {
        $this->assertInstalled();
        $account = $this->runtimeAccount($accountId);
        $token = $this->vault->decrypt((string) ($account['access_token_encrypted'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('The account token is unavailable. Reconnect this account.');
        }

        try {
            if ((string) $account['provider'] === 'meta') {
                $fields = (string) $account['channel'] === 'instagram' ? 'id,username,name' : 'id,name';
                $response = $this->request('GET', $this->metaGraphUrl((string) $account['external_account_id']), [], [
                    'fields' => $fields,
                    'access_token' => $token,
                ]);
            } else {
                $response = $this->request('GET', 'https://api.linkedin.com/rest/organizations/' . rawurlencode((string) $account['external_account_id']), $this->linkedInHeaders($token));
            }
            $this->requireSuccess($response, 'Account verification failed');
            Database::execute(
                "UPDATE social_media_accounts
                 SET status = 'active', last_verified_at = NOW(), last_error = NULL
                 WHERE workspace_id = ? AND id = ?",
                [$this->workspaceId, $accountId]
            );
            $this->event('account_verified', 'success', 'account', $accountId, 'Social account verified.', [], $userId);
        } catch (Throwable $e) {
            Database::execute(
                "UPDATE social_media_accounts SET status = 'error', last_error = ? WHERE workspace_id = ? AND id = ?",
                [mb_substr($e->getMessage(), 0, 2000), $this->workspaceId, $accountId]
            );
            $this->event('account_verified', 'failed', 'account', $accountId, 'Social account verification failed.', ['error' => $e->getMessage()], $userId);
            throw $e;
        }

        return $this->getAccount($accountId) ?: [];
    }

    public function disconnectAccount(int $accountId, int $userId): void
    {
        $this->assertInstalled();
        $account = $this->runtimeAccount($accountId);
        Database::execute(
            "UPDATE social_media_accounts
             SET status = 'disconnected', access_token_encrypted = '', refresh_token_encrypted = NULL,
                 token_expires_at = NULL, last_error = NULL
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $accountId]
        );
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = 'cancelled', error_class = 'account_disconnected',
                 error_message = 'The destination account was disconnected before publishing.', updated_by = ?
             WHERE workspace_id = ? AND account_id = ? AND status IN ('draft','pending_approval','approved','queued')",
            [$userId > 0 ? $userId : null, $this->workspaceId, $accountId]
        );
        Database::execute(
            "UPDATE marketing_distribution_posts d
             JOIN social_media_publish_jobs j
               ON j.distribution_post_id = d.id AND j.workspace_id = d.workspace_id
             SET d.status = 'cancelled'
             WHERE j.workspace_id = ? AND j.account_id = ?
               AND j.status = 'cancelled' AND j.error_class = 'account_disconnected'",
            [$this->workspaceId, $accountId]
        );
        $this->event('account_disconnected', 'warning', 'account', $accountId, (string) $account['account_name'] . ' disconnected.', [], $userId);
    }

    public function generateVariants(string $idea, array $accountIds, int $userId, bool $useAi = true, array $brief = []): array
    {
        $this->assertInstalled();
        $idea = $this->cleanMultiline($idea, 6000);
        if ($idea === '') {
            throw new RuntimeException('Enter the core message before generating channel variants.');
        }
        $accounts = $this->selectedAccounts($accountIds, true);
        if ($accounts === []) {
            throw new RuntimeException('Choose at least one active social account.');
        }
        $settings = $this->getSettings();
        $objective = $this->clean($brief['objective'] ?? '', 500);
        $offer = $this->cleanMultiline($brief['offer'] ?? '', 2000);
        $tone = $this->clean($brief['tone'] ?? '', 180);
        $audience = $this->cleanMultiline($brief['audience'] ?? '', 2000);
        $cta = $this->cleanMultiline($brief['cta'] ?? '', 1000);
        if ($cta !== '') {
            $settings['preferred_cta'] = $cta;
        }
        $channels = array_values(array_unique(array_column($accounts, 'channel')));
        $fallback = [];
        foreach ($channels as $channel) {
            $fallback[$channel] = $this->deterministicVariant($idea, (string) $channel, $settings);
        }

        if (!$useAi) {
            $this->lastVariantGenerationStatus = [
                'used_ai' => false,
                'fallback_used' => true,
                'mode' => 'deterministic_requested',
                'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
                'message' => 'AI was turned off; channel-safe local variants were generated.',
            ];
            return $fallback;
        }

        $properties = [];
        $limits = ['facebook' => 5000, 'instagram' => 2200, 'linkedin' => 3000];
        foreach ($channels as $channel) {
            $properties[(string) $channel] = [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => $limits[(string) $channel] ?? 3000,
            ];
        }

        $prompt = "Create distinct, publish-ready organic social variants from the supplied core message.\n"
            . "Return only one JSON object whose keys are exactly: " . implode(', ', $channels) . ".\n"
            . "Each value must be plain text. Preserve factual meaning, do not invent claims, prices, customers, or guarantees.\n"
            . "Facebook may be conversational, Instagram should use a strong opening and at most 5 relevant hashtags, LinkedIn should be professional and insight-led.\n\n"
            . "BRAND NAME: " . (string) ($settings['brand_name'] ?? '') . "\n"
            . "BRAND VOICE: " . (string) ($settings['brand_voice'] ?? '') . "\n"
            . "AUDIENCE: " . ($audience !== '' ? $audience : (string) ($settings['target_audience'] ?? '')) . "\n"
            . "OBJECTIVE: " . $objective . "\n"
            . "OFFER OR PRODUCT CONTEXT: " . $offer . "\n"
            . "REQUESTED TONE: " . ($tone !== '' ? $tone : (string) ($settings['brand_voice'] ?? '')) . "\n"
            . "APPROVED CLAIMS: " . (string) ($settings['approved_claims'] ?? '') . "\n"
            . "PROHIBITED CLAIMS: " . (string) ($settings['prohibited_claims'] ?? '') . "\n"
            . "PREFERRED CTA: " . (string) ($settings['preferred_cta'] ?? '') . "\n"
            . "CORE MESSAGE:\n{$idea}";

        try {
            $ai = new AIService();
            $response = $ai->process('social_post_variants', [
                'prompt' => $prompt,
                '_resolved_prompt' => [
                    'user_prompt' => $prompt,
                    'system_prompt' => 'You are a careful social media editor. Output valid JSON only.',
                    'output_contract' => [
                        'type' => 'json_schema',
                        'name' => 'social_post_variants',
                        'schema' => [
                            'type' => 'object',
                            'properties' => $properties,
                            'required' => $channels,
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ], [
                'workspace_id' => $this->workspaceId,
                'user_id' => $userId,
                'surface' => 'marketing',
                'force_refresh' => true,
                'max_tokens' => 1800,
                'temperature' => 0.4,
                'output_type' => 'json',
                'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
            ]);
            $decoded = $this->decodeJsonObject($response);
            $accepted = 0;
            foreach ($channels as $channel) {
                $candidate = $this->cleanMultiline($decoded[$channel] ?? '', 6000);
                try {
                    $this->validateCaption($candidate, (string) $channel);
                    $valid = !$this->containsProhibitedClaim($candidate, (string) ($settings['prohibited_claims'] ?? ''));
                } catch (Throwable $e) {
                    $valid = false;
                }
                if ($valid) {
                    $fallback[$channel] = $candidate;
                    $accepted++;
                }
            }
            $this->lastVariantGenerationStatus = array_merge($ai->getLastProviderStatus(), [
                'used_ai' => $accepted > 0,
                'fallback_used' => $accepted < count($channels),
                'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
                'accepted_variants' => $accepted,
                'requested_variants' => count($channels),
            ]);
        } catch (Throwable $e) {
            $this->lastVariantGenerationStatus = [
                'used_ai' => false,
                'fallback_used' => true,
                'success' => false,
                'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
                'message' => $e->getMessage(),
            ];
        }

        return $fallback;
    }

    public function createPublishJobs(array $data, int $userId): array
    {
        $this->assertInstalled();
        $settings = $this->getSettings();
        if (empty($settings['enabled'])) {
            throw new RuntimeException('Social publishing is paused in Marketplace setup.');
        }

        $accounts = $this->selectedAccounts((array) ($data['account_ids'] ?? []), true);
        if ($accounts === []) {
            throw new RuntimeException('Choose at least one active social account.');
        }
        $baseCaption = $this->cleanMultiline($data['caption'] ?? '', 6000);
        if ($baseCaption === '') {
            throw new RuntimeException('Enter a social post message.');
        }
        $scheduledAt = $this->normalizeSchedule((string) ($data['scheduled_at'] ?? ''), (string) ($settings['timezone'] ?? 'Africa/Nairobi'));
        $linkUrl = $this->optionalUrl($data['link_url'] ?? '');
        $media = $this->normalizeMedia((array) ($data['media'] ?? []));
        $variants = is_array($data['variants'] ?? null) ? (array) $data['variants'] : [];
        $campaignId = max(0, (int) ($data['campaign_id'] ?? 0));
        $contentItemId = max(0, (int) ($data['content_item_id'] ?? 0));
        $sourceDistributionId = max(0, (int) ($data['distribution_post_id'] ?? 0));
        $utmLinkId = max(0, (int) ($data['utm_link_id'] ?? 0));
        [$campaignId, $contentItemId, $sourceDistribution, $utmLink] = $this->validatePublishingContext(
            $campaignId,
            $contentItemId,
            $sourceDistributionId,
            $utmLinkId
        );
        if (!empty($utmLink['generated_url'])) {
            $linkUrl = (string) $utmLink['generated_url'];
        }
        $objective = $this->clean($data['objective'] ?? '', 500);
        $targetAudience = $this->cleanMultiline($data['target_audience'] ?? '', 2000);
        $offer = $this->cleanMultiline($data['offer'] ?? '', 2000);
        $tone = $this->clean($data['tone'] ?? '', 180);
        $cta = $this->cleanMultiline($data['cta'] ?? '', 1000);
        $title = $this->clean($data['title'] ?? '', 220);
        if ($title === '') {
            $title = mb_substr(trim(preg_replace('/\s+/', ' ', $baseCaption) ?? $baseCaption), 0, 80);
        }
        $clientRequestId = $this->clean($data['client_request_id'] ?? '', 120);
        if ($clientRequestId === '') {
            $clientRequestId = bin2hex(random_bytes(16));
        }

        $prepared = [];
        foreach ($accounts as $account) {
            $accountId = (int) $account['id'];
            $channel = (string) $account['channel'];
            $caption = $this->cleanMultiline($variants[$accountId] ?? $variants[$channel] ?? $baseCaption, 6000);
            $this->validateCaption($caption, $channel);
            $this->validateMediaForChannel($media, $channel);
            $trackedLink = $this->trackedLinkUrl($linkUrl, $channel, $settings, $campaignId, $clientRequestId);
            $prepared[] = [
                'account' => $account,
                'caption' => $caption,
                'link_url' => $trackedLink,
                'idempotency_key' => hash('sha256', implode('|', [
                    $this->workspaceId, $accountId, $clientRequestId, $caption, $trackedLink, $scheduledAt, json_encode($media),
                ])),
            ];
        }

        $idempotencyKeys = array_column($prepared, 'idempotency_key');
        $existingJobs = $this->existingPublishJobs($idempotencyKeys);
        if (count($existingJobs) === count($prepared)) {
            return [
                'job_ids' => array_map(static fn(array $row): int => (int) $row['id'], $existingJobs),
                'count' => count($existingJobs),
                'approval_required' => !empty($settings['approval_required']),
                'idempotent_replay' => true,
            ];
        }
        if ($existingJobs !== []) {
            throw new RuntimeException('This publish request was only partially recorded. Submit it again as a new post to avoid duplicate destinations.');
        }

        Database::beginTransaction();
        try {
            if ($contentItemId <= 0) {
                $marketing = new Marketing();
                $contentItemId = $marketing->createContentItem([
                    'title' => $title,
                    'content_type' => 'social_post',
                    'channel' => (string) ($accounts[0]['channel'] ?? 'linkedin'),
                    'status' => !empty($settings['approval_required']) ? 'review' : 'approved',
                    'production_stage' => 'ready',
                    'funnel_stage' => 'awareness',
                    'objective' => $objective !== '' ? $objective : 'Publish an approved social update from the Social Media plugin.',
                    'target_audience' => $targetAudience !== '' ? $targetAudience : (string) ($settings['target_audience'] ?? ''),
                    'campaign_id' => $campaignId > 0 ? $campaignId : null,
                    'scheduled_at' => $scheduledAt,
                    'draft_body' => $baseCaption,
                    'metadata_json' => [
                        'source' => 'social_media_plugin',
                        'client_request_id' => $clientRequestId,
                        'credential_scope' => WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION,
                        'content_brief' => [
                            'offer' => $offer,
                            'tone' => $tone,
                            'cta' => $cta,
                        ],
                    ],
                    'owner_user_id' => $userId,
                    'created_by' => $userId,
                ]);
            }

            $jobIds = [];
            $marketing = new Marketing();
            foreach ($prepared as $destination) {
                $account = (array) $destination['account'];
                $accountId = (int) $account['id'];
                $channel = (string) $account['channel'];
                $caption = (string) $destination['caption'];
                $destinationLink = (string) $destination['link_url'];
                $approvalRequired = !empty($settings['approval_required']);
                $distributionData = [
                    'content_item_id' => $contentItemId,
                    'channel' => $channel,
                    'planned_copy' => $caption,
                    'scheduled_at' => $scheduledAt,
                    'status' => $approvalRequired ? 'draft' : 'scheduled',
                    'publishing_checklist_json' => [
                        'copy_approved' => empty($settings['approval_required']),
                        'destination_connected' => true,
                        'media_validated' => true,
                        'utm_reviewed' => $destinationLink !== '',
                    ],
                    'required_fields_json' => ['caption', 'destination_account', 'schedule'],
                    'asset_rules_json' => [
                        'Channel: ' . $channel,
                        'Source: social_media_plugin',
                        $sourceDistributionId > 0 ? 'Campaign Kit source distribution: ' . $sourceDistributionId : 'Direct Social Media composition',
                    ],
                    'created_by' => $userId,
                ];
                $reusePreparedHandoff = count($prepared) === 1
                    && $sourceDistributionId > 0
                    && (string) ($sourceDistribution['channel'] ?? '') === $channel;
                if ($reusePreparedHandoff) {
                    $marketing->updateDistributionPost($sourceDistributionId, $distributionData);
                    $distributionId = $sourceDistributionId;
                } else {
                    $distributionId = $marketing->createDistributionPost($distributionData);
                }

                $status = $approvalRequired ? 'pending_approval' : 'queued';
                Database::execute(
                    "INSERT INTO social_media_publish_jobs
                     (workspace_id, uuid, account_id, content_item_id, distribution_post_id, campaign_id, status,
                      caption, link_url, media_json, scheduled_at, approved_by, approved_at, next_attempt_at,
                      idempotency_key, request_json, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $this->workspaceId, $this->uuid(), $accountId, $contentItemId, $distributionId,
                        $campaignId > 0 ? $campaignId : null, $status, $caption, $destinationLink !== '' ? $destinationLink : null,
                        $this->json($media), $scheduledAt,
                        $approvalRequired ? null : ($userId > 0 ? $userId : null), $approvalRequired ? null : date('Y-m-d H:i:s'),
                        $approvalRequired ? null : $scheduledAt, (string) $destination['idempotency_key'],
                        $this->json([
                            'client_request_id' => $clientRequestId,
                            'channel' => $channel,
                            'campaign_kit_handoff' => $sourceDistributionId > 0,
                            'source_distribution_post_id' => $sourceDistributionId ?: null,
                            'utm_link_id' => $utmLinkId ?: null,
                        ]),
                        $userId > 0 ? $userId : null, $userId > 0 ? $userId : null,
                    ]
                );
                $jobId = (int) Database::lastInsertId();
                $jobIds[] = $jobId;
                $this->event('job_created', 'success', 'publish_job', $jobId, 'Social publish job created for ' . $account['account_name'] . '.', ['status' => $status], $userId);
            }
            Database::commit();
        } catch (Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $replayedJobs = $this->existingPublishJobs($idempotencyKeys);
            if (count($replayedJobs) === count($prepared)) {
                return [
                    'job_ids' => array_map(static fn(array $row): int => (int) $row['id'], $replayedJobs),
                    'count' => count($replayedJobs),
                    'approval_required' => !empty($settings['approval_required']),
                    'idempotent_replay' => true,
                ];
            }
            throw $e;
        }

        return ['job_ids' => $jobIds, 'count' => count($jobIds), 'approval_required' => !empty($settings['approval_required'])];
    }

    public function approveJob(int $jobId, int $userId): array
    {
        $this->assertInstalled();
        $job = $this->job($jobId);
        if (!in_array((string) $job['status'], ['draft', 'pending_approval', 'approved'], true)) {
            throw new RuntimeException('Only draft or awaiting-approval posts can be approved.');
        }
        $due = (string) (($job['scheduled_at'] ?? null) ?: date('Y-m-d H:i:s'));
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = 'queued', approved_by = ?, approved_at = NOW(), next_attempt_at = ?,
                 error_class = NULL, error_message = NULL, updated_by = ?
             WHERE workspace_id = ? AND id = ?",
            [$userId > 0 ? $userId : null, $due, $userId > 0 ? $userId : null, $this->workspaceId, $jobId]
        );
        if (!empty($job['distribution_post_id'])) {
            Database::execute(
                "UPDATE marketing_distribution_posts SET status = 'scheduled', scheduled_at = ?
                 WHERE workspace_id = ? AND id = ?",
                [$due, $this->workspaceId, (int) $job['distribution_post_id']]
            );
        }
        if (!empty($job['content_item_id'])) {
            Database::execute(
                "UPDATE marketing_content_items SET status = 'approved'
                 WHERE workspace_id = ? AND id = ? AND status = 'review'",
                [$this->workspaceId, (int) $job['content_item_id']]
            );
        }
        $this->event('job_approved', 'success', 'publish_job', $jobId, 'Social post approved and queued.', [], $userId);
        return $this->job($jobId);
    }

    public function cancelJob(int $jobId, int $userId): void
    {
        $this->assertInstalled();
        $job = $this->job($jobId);
        if (in_array((string) $job['status'], ['published', 'cancelled'], true)) {
            throw new RuntimeException('Published or already-cancelled posts cannot be cancelled here.');
        }
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = 'cancelled', error_class = 'cancelled_by_user', error_message = 'Cancelled by an authorised user.', updated_by = ?
             WHERE workspace_id = ? AND id = ?",
            [$userId > 0 ? $userId : null, $this->workspaceId, $jobId]
        );
        if (!empty($job['distribution_post_id'])) {
            Database::execute(
                "UPDATE marketing_distribution_posts SET status = 'cancelled'
                 WHERE workspace_id = ? AND id = ?",
                [$this->workspaceId, (int) $job['distribution_post_id']]
            );
        }
        $this->event('job_cancelled', 'warning', 'publish_job', $jobId, 'Social post cancelled.', [], $userId);
    }

    public function retryJob(int $jobId, int $userId): array
    {
        $this->assertInstalled();
        $job = $this->job($jobId);
        if ((string) $job['status'] !== 'failed') {
            throw new RuntimeException('Only failed social posts can be retried.');
        }
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = 'queued', attempts = 0, next_attempt_at = NOW(), error_class = NULL,
                 error_message = NULL, updated_by = ? WHERE workspace_id = ? AND id = ?",
            [$userId > 0 ? $userId : null, $this->workspaceId, $jobId]
        );
        $this->event('job_retried', 'warning', 'publish_job', $jobId, 'Failed social post returned to the queue by an operator.', [], $userId);
        return $this->job($jobId);
    }

    public function processDueJobs(int $limit = 25): array
    {
        $this->assertInstalled();
        $limit = max(1, min(100, $limit));
        $settings = $this->getSettings();
        $recovered = $this->recoverStaleProcessingJobs();
        if (empty($settings['enabled'])) {
            return ['processed' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0, 'recovered' => $recovered, 'message' => 'Social publishing is paused.'];
        }

        $rows = Database::query(
            "SELECT id FROM social_media_publish_jobs
             WHERE workspace_id = ? AND status = 'queued'
               AND COALESCE(scheduled_at, NOW()) <= NOW()
               AND COALESCE(next_attempt_at, NOW()) <= NOW()
             ORDER BY COALESCE(scheduled_at, created_at) ASC, id ASC
             LIMIT {$limit}",
            [$this->workspaceId]
        );
        $summary = ['processed' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0, 'recovered' => $recovered];
        foreach ($rows as $row) {
            $jobId = (int) $row['id'];
            $claimed = Database::execute(
                "UPDATE social_media_publish_jobs SET status = 'processing', attempts = attempts + 1
                 WHERE workspace_id = ? AND id = ? AND status = 'queued'",
                [$this->workspaceId, $jobId]
            );
            if ($claimed !== 1) {
                $summary['skipped']++;
                continue;
            }
            $summary['processed']++;
            try {
                $this->publishClaimedJob($jobId);
                $summary['published']++;
            } catch (Throwable $e) {
                $this->recordPublishFailure($jobId, $e);
                $summary['failed']++;
            }
        }
        return $summary;
    }

    public function syncMetrics(int $limit = 50): array
    {
        $this->assertInstalled();
        if (empty($this->getSettings()['metrics_sync_enabled'])) {
            return ['processed' => 0, 'synced' => 0, 'failed' => 0, 'message' => 'Metrics sync is disabled.'];
        }
        $limit = max(1, min(200, $limit));
        $jobs = Database::query(
            "SELECT j.id
             FROM social_media_publish_jobs j
             JOIN social_media_accounts a ON a.id = j.account_id AND a.workspace_id = j.workspace_id
             LEFT JOIN (
                 SELECT publish_job_id, MAX(captured_at) AS last_captured_at
                 FROM social_media_metric_snapshots
                 WHERE workspace_id = ?
                 GROUP BY publish_job_id
             ) latest_metrics ON latest_metrics.publish_job_id = j.id
             WHERE j.workspace_id = ? AND j.status = 'published' AND j.provider_post_id IS NOT NULL
               AND a.status = 'active'
             ORDER BY COALESCE(latest_metrics.last_captured_at, '1970-01-01') ASC, j.published_at DESC
             LIMIT {$limit}",
            [$this->workspaceId, $this->workspaceId]
        );
        $summary = ['processed' => 0, 'synced' => 0, 'failed' => 0];
        foreach ($jobs as $row) {
            $summary['processed']++;
            try {
                $this->syncJobMetrics((int) $row['id']);
                $summary['synced']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->event('metrics_synced', 'failed', 'publish_job', (int) $row['id'], 'Social metrics sync failed.', ['error' => $e->getMessage()]);
            }
        }
        return $summary;
    }

    public function listJobs(array $filters = [], int $limit = 100): array
    {
        $this->assertInstalled();
        $limit = max(1, min(250, $limit));
        $where = ['j.workspace_id = ?'];
        $params = [$this->workspaceId];
        if (!empty($filters['status']) && in_array((string) $filters['status'], self::JOB_STATUSES, true)) {
            $where[] = 'j.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['account_id'])) {
            $where[] = 'j.account_id = ?';
            $params[] = (int) $filters['account_id'];
        }
        return array_map(
            fn(array $row): array => $this->hydrateJob($row),
            Database::query(
                "SELECT j.*, a.account_name, a.account_handle, a.channel, a.provider, a.status AS account_status,
                        c.name AS campaign_name, m.title AS content_title,
                        (SELECT COALESCE(ms.impressions,0) FROM social_media_metric_snapshots ms WHERE ms.publish_job_id = j.id ORDER BY ms.captured_at DESC, ms.id DESC LIMIT 1) AS metric_impressions,
                        (SELECT COALESCE(ms.engagements,0) FROM social_media_metric_snapshots ms WHERE ms.publish_job_id = j.id ORDER BY ms.captured_at DESC, ms.id DESC LIMIT 1) AS metric_engagements,
                        (SELECT MAX(ms.captured_at) FROM social_media_metric_snapshots ms WHERE ms.publish_job_id = j.id) AS metrics_captured_at
                 FROM social_media_publish_jobs j
                 JOIN social_media_accounts a ON a.id = j.account_id AND a.workspace_id = j.workspace_id
                 LEFT JOIN campaigns c ON c.id = j.campaign_id AND c.workspace_id = j.workspace_id
                 LEFT JOIN marketing_content_items m ON m.id = j.content_item_id AND m.workspace_id = j.workspace_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY COALESCE(j.scheduled_at, j.created_at) DESC, j.id DESC
                 LIMIT {$limit}",
                $params
            )
        );
    }

    public function recentEvents(int $limit = 30): array
    {
        $this->assertInstalled();
        $limit = max(1, min(100, $limit));
        return Database::query(
            "SELECT e.*, u.email AS actor_email
             FROM social_media_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE e.workspace_id = ? ORDER BY e.id DESC LIMIT {$limit}",
            [$this->workspaceId]
        );
    }

    public function dashboardSummary(): array
    {
        $this->assertInstalled();
        $counts = Database::queryOne(
            "SELECT
                SUM(status = 'active') AS active_accounts,
                SUM(status IN ('expired','revoked','error')) AS account_issues
             FROM social_media_accounts WHERE workspace_id = ? AND status <> 'disconnected'",
            [$this->workspaceId]
        ) ?: [];
        $jobs = Database::queryOne(
            "SELECT
                COUNT(*) AS total_jobs,
                SUM(status = 'pending_approval') AS pending_approval,
                SUM(status = 'queued') AS queued,
                SUM(status = 'published') AS published,
                SUM(status = 'failed') AS failed
             FROM social_media_publish_jobs WHERE workspace_id = ?",
            [$this->workspaceId]
        ) ?: [];
        $metrics = Database::queryOne(
            "SELECT COALESCE(SUM(ms.impressions),0) AS impressions, COALESCE(SUM(ms.reach),0) AS reach,
                    COALESCE(SUM(ms.engagements),0) AS engagements, COALESCE(SUM(ms.clicks),0) AS clicks
             FROM social_media_metric_snapshots ms
             JOIN (
                 SELECT publish_job_id, MAX(id) AS latest_id
                 FROM social_media_metric_snapshots
                 WHERE workspace_id = ? AND captured_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY publish_job_id
             ) latest ON latest.latest_id = ms.id",
            [$this->workspaceId]
        ) ?: [];
        $tracking = Database::tableExists('marketing_tracking_events')
            ? (Database::queryOne(
                "SELECT COUNT(*) AS tracked_events,
                        COUNT(DISTINCT visitor_session_id) AS visitors,
                        SUM(event_type IN ('form_submit','conversion')) AS conversions
                 FROM marketing_tracking_events
                 WHERE workspace_id = ? AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND (utm_medium = 'organic_social' OR utm_source IN ('facebook','instagram','linkedin','social'))",
                [$this->workspaceId]
            ) ?: [])
            : [];

        return array_merge([
            'active_accounts' => 0, 'account_issues' => 0, 'total_jobs' => 0,
            'pending_approval' => 0, 'queued' => 0, 'published' => 0, 'failed' => 0,
            'impressions' => 0, 'reach' => 0, 'engagements' => 0, 'clicks' => 0,
            'tracked_events' => 0, 'visitors' => 0, 'conversions' => 0,
        ], $counts, $jobs, $metrics, $tracking);
    }

    private function publishClaimedJob(int $jobId): void
    {
        $job = $this->job($jobId);
        $account = $this->runtimeAccount((int) $job['account_id']);
        if ((string) $account['status'] !== 'active') {
            throw new RuntimeException('Destination account is not active.');
        }
        if (!empty($account['token_expires_at']) && strtotime((string) $account['token_expires_at']) <= time()) {
            Database::execute("UPDATE social_media_accounts SET status = 'expired' WHERE workspace_id = ? AND id = ?", [$this->workspaceId, (int) $account['id']]);
            throw new RuntimeException('Destination account token has expired. Reconnect the account.');
        }
        $token = $this->vault->decrypt((string) ($account['access_token_encrypted'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Destination account token is unavailable.');
        }

        $this->event('publish_started', 'info', 'publish_job', $jobId, 'Provider publish started.', ['channel' => $account['channel']]);
        Database::execute(
            'UPDATE social_media_publish_jobs SET request_json = ? WHERE workspace_id = ? AND id = ?',
            [$this->json($this->visiblePublishRequest($job, $account)), $this->workspaceId, $jobId]
        );
        $result = match ((string) $account['channel']) {
            'facebook' => $this->publishFacebook($job, $account, $token),
            'instagram' => $this->publishInstagram($job, $account, $token),
            'linkedin' => $this->publishLinkedIn($job, $account, $token),
            default => throw new RuntimeException('Unsupported social destination.'),
        };
        $providerPostId = trim((string) ($result['provider_post_id'] ?? ''));
        if ($providerPostId === '') {
            throw new RuntimeException('The provider did not return a post identifier.');
        }
        $providerUrl = trim((string) ($result['provider_url'] ?? ''));
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = 'published', provider_post_id = ?, provider_url = ?, response_json = ?,
                 error_class = NULL, error_message = NULL, published_at = NOW(), next_attempt_at = NULL
             WHERE workspace_id = ? AND id = ?",
            [$providerPostId, $providerUrl !== '' ? $providerUrl : null, $this->json($result['evidence'] ?? []), $this->workspaceId, $jobId]
        );
        if (!empty($job['distribution_post_id'])) {
            Database::execute(
                "UPDATE marketing_distribution_posts
                 SET status = 'published', published_url = ?, published_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [$providerUrl !== '' ? $providerUrl : null, $this->workspaceId, (int) $job['distribution_post_id']]
            );
        }
        if (!empty($job['content_item_id'])) {
            Database::execute(
                "UPDATE marketing_content_items SET status = 'published', published_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [$this->workspaceId, (int) $job['content_item_id']]
            );
        }
        $this->event('publish_succeeded', 'success', 'publish_job', $jobId, 'Social post published successfully.', ['provider_post_id' => $providerPostId]);
    }

    private function recordPublishFailure(int $jobId, Throwable $error): void
    {
        $job = $this->job($jobId);
        $attempts = max(1, (int) ($job['attempts'] ?? 1));
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 5));
        $final = $attempts >= $maxAttempts;
        $delayMinutes = min(360, 5 * (2 ** max(0, $attempts - 1)));
        Database::execute(
            "UPDATE social_media_publish_jobs
             SET status = ?, error_class = ?, error_message = ?, response_json = ?,
                 next_attempt_at = " . ($final ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . (int) $delayMinutes . ' MINUTE)') . "
             WHERE workspace_id = ? AND id = ?",
            [
                $final ? 'failed' : 'queued', $this->errorClass($error), mb_substr($error->getMessage(), 0, 4000),
                $this->json(['success' => false, 'message' => $error->getMessage(), 'attempt' => $attempts]),
                $this->workspaceId, $jobId,
            ]
        );
        $this->event('publish_failed', $final ? 'failed' : 'warning', 'publish_job', $jobId,
            $final ? 'Social publishing failed after all retry attempts.' : 'Social publishing failed and was queued for retry.',
            ['attempt' => $attempts, 'max_attempts' => $maxAttempts, 'error' => $error->getMessage()]
        );
    }

    private function recoverStaleProcessingJobs(): int
    {
        $staleJobs = Database::query(
            "SELECT id FROM social_media_publish_jobs
             WHERE workspace_id = ? AND status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY updated_at ASC, id ASC",
            [$this->workspaceId]
        );
        $recovered = 0;
        foreach ($staleJobs as $row) {
            $jobId = (int) $row['id'];
            $updated = Database::execute(
                "UPDATE social_media_publish_jobs
                 SET status = 'failed', error_class = 'delivery_status_unknown',
                     error_message = 'The publishing worker stopped before the provider result was recorded. Check the provider before retrying to avoid a duplicate post.',
                     next_attempt_at = NULL
                 WHERE workspace_id = ? AND id = ? AND status = 'processing'
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
                [$this->workspaceId, $jobId]
            );
            if ($updated !== 1) {
                continue;
            }
            $recovered++;
            $this->event(
                'publish_failed',
                'failed',
                'publish_job',
                $jobId,
                'A stale publishing claim was closed for manual provider verification.',
                ['error_class' => 'delivery_status_unknown', 'stale_after_minutes' => 30]
            );
        }

        return $recovered;
    }

    private function publishFacebook(array $job, array $account, string $token): array
    {
        $media = (array) ($job['media_json'] ?? []);
        $first = (array) ($media[0] ?? []);
        $endpoint = $this->metaGraphUrl((string) $account['external_account_id'] . '/feed');
        $payload = ['message' => (string) $job['caption'], 'access_token' => $token];
        if (!empty($job['link_url'])) {
            $payload['link'] = (string) $job['link_url'];
        }
        if (($first['type'] ?? '') === 'image' && !empty($first['url'])) {
            $endpoint = $this->metaGraphUrl((string) $account['external_account_id'] . '/photos');
            $payload = ['url' => (string) $first['url'], 'caption' => (string) $job['caption'], 'published' => 'true', 'access_token' => $token];
        } elseif (($first['type'] ?? '') === 'video' && !empty($first['url'])) {
            $endpoint = $this->metaGraphUrl((string) $account['external_account_id'] . '/videos');
            $payload = ['file_url' => (string) $first['url'], 'description' => (string) $job['caption'], 'access_token' => $token];
        }
        $response = $this->request('POST', $endpoint, [], $payload, 'form');
        $json = $this->requireSuccess($response, 'Facebook publishing failed');
        $id = (string) ($json['post_id'] ?? $json['id'] ?? '');
        return [
            'provider_post_id' => $id,
            'provider_url' => $id !== '' ? 'https://www.facebook.com/' . rawurlencode($id) : '',
            'evidence' => ['http_status' => $response['status'], 'provider' => 'meta', 'channel' => 'facebook', 'response_id' => $id],
        ];
    }

    private function publishInstagram(array $job, array $account, string $token): array
    {
        $media = (array) ($job['media_json'] ?? []);
        $first = (array) ($media[0] ?? []);
        if (empty($first['url']) || !in_array((string) ($first['type'] ?? ''), ['image', 'video'], true)) {
            throw new RuntimeException('Instagram publishing requires one public image or video URL.');
        }
        $payload = ['caption' => (string) $job['caption'], 'access_token' => $token];
        if ((string) $first['type'] === 'video') {
            $payload['media_type'] = 'REELS';
            $payload['video_url'] = (string) $first['url'];
        } else {
            $payload['image_url'] = (string) $first['url'];
        }
        $created = $this->request('POST', $this->metaGraphUrl((string) $account['external_account_id'] . '/media'), [], $payload, 'form');
        $createdJson = $this->requireSuccess($created, 'Instagram media preparation failed');
        $containerId = (string) ($createdJson['id'] ?? '');
        if ($containerId === '') {
            throw new RuntimeException('Instagram did not return a media container.');
        }
        if ((string) $first['type'] === 'video') {
            $status = $this->request('GET', $this->metaGraphUrl($containerId), [], ['fields' => 'status_code,status', 'access_token' => $token]);
            $statusJson = $this->requireSuccess($status, 'Instagram media status check failed');
            if (!in_array((string) ($statusJson['status_code'] ?? ''), ['FINISHED', 'PUBLISHED'], true)) {
                throw new RuntimeException('Instagram is still processing the video; the worker will retry automatically.');
            }
        }
        $published = $this->request('POST', $this->metaGraphUrl((string) $account['external_account_id'] . '/media_publish'), [], [
            'creation_id' => $containerId,
            'access_token' => $token,
        ], 'form');
        $publishedJson = $this->requireSuccess($published, 'Instagram publishing failed');
        $id = (string) ($publishedJson['id'] ?? '');
        return [
            'provider_post_id' => $id,
            'provider_url' => '',
            'evidence' => ['http_status' => $published['status'], 'provider' => 'meta', 'channel' => 'instagram', 'container_id' => $containerId, 'response_id' => $id],
        ];
    }

    private function publishLinkedIn(array $job, array $account, string $token): array
    {
        $author = 'urn:li:organization:' . (string) $account['external_account_id'];
        $payload = [
            'author' => $author,
            'commentary' => (string) $job['caption'],
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        $media = (array) ($job['media_json'] ?? []);
        $first = (array) ($media[0] ?? []);
        if (($first['type'] ?? '') === 'image' && !empty($first['url'])) {
            $imageUrn = $this->uploadLinkedInImage((string) $first['url'], $author, $token);
            $payload['content'] = [
                'media' => [
                    'id' => $imageUrn,
                    'altText' => $this->clean($first['alt_text'] ?? '', 500),
                ],
            ];
        } elseif (!empty($job['link_url'])) {
            $link = (string) $job['link_url'];
            $captionLimit = max(0, 3000 - mb_strlen($link) - 2);
            $payload['commentary'] = rtrim(mb_substr((string) $job['caption'], 0, $captionLimit)) . "\n\n" . $link;
        }
        $response = $this->request('POST', 'https://api.linkedin.com/rest/posts', $this->linkedInHeaders($token), $payload, 'json');
        $json = $this->requireSuccess($response, 'LinkedIn publishing failed');
        $id = (string) ($response['headers']['x-restli-id'] ?? $json['id'] ?? '');
        return [
            'provider_post_id' => $id,
            'provider_url' => '',
            'evidence' => ['http_status' => $response['status'], 'provider' => 'linkedin', 'channel' => 'linkedin', 'response_id' => $id],
        ];
    }

    private function uploadLinkedInImage(string $url, string $ownerUrn, string $token): string
    {
        $binary = $this->downloadMedia($url, 10 * 1024 * 1024, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        $initialize = $this->request('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', $this->linkedInHeaders($token), [
            'initializeUploadRequest' => ['owner' => $ownerUrn],
        ], 'json');
        $json = $this->requireSuccess($initialize, 'LinkedIn image upload initialization failed');
        $value = (array) ($json['value'] ?? []);
        $uploadUrl = (string) ($value['uploadUrl'] ?? '');
        $imageUrn = (string) ($value['image'] ?? '');
        if ($uploadUrl === '' || $imageUrn === '') {
            throw new RuntimeException('LinkedIn did not return an image upload target.');
        }
        $uploaded = $this->request('PUT', $uploadUrl, ['Content-Type: ' . $binary['content_type'], 'Authorization: Bearer ' . $token], $binary['body'], 'raw');
        if ((int) $uploaded['status'] < 200 || (int) $uploaded['status'] >= 300) {
            throw new RuntimeException('LinkedIn image upload failed with HTTP ' . (int) $uploaded['status'] . '.');
        }
        return $imageUrn;
    }

    private function syncJobMetrics(int $jobId): void
    {
        $job = $this->job($jobId);
        $account = $this->runtimeAccount((int) $job['account_id']);
        $token = $this->vault->decrypt((string) ($account['access_token_encrypted'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Metrics token unavailable.');
        }
        $metrics = ['impressions' => 0, 'reach' => 0, 'engagements' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'video_views' => 0, 'followers' => 0];
        $raw = [];
        if ((string) $account['channel'] === 'instagram') {
            $response = $this->request('GET', $this->metaGraphUrl((string) $job['provider_post_id'] . '/insights'), [], [
                'metric' => 'reach,impressions,total_interactions,likes,comments,shares,views',
                'access_token' => $token,
            ]);
            $raw = $this->requireSuccess($response, 'Instagram metrics sync failed');
            foreach ((array) ($raw['data'] ?? []) as $item) {
                $name = (string) ($item['name'] ?? '');
                $value = (int) (($item['values'][0]['value'] ?? $item['total_value']['value'] ?? 0));
                $map = ['total_interactions' => 'engagements', 'views' => 'video_views'];
                $key = $map[$name] ?? $name;
                if (array_key_exists($key, $metrics)) $metrics[$key] = $value;
            }
        } elseif ((string) $account['channel'] === 'facebook') {
            $response = $this->request('GET', $this->metaGraphUrl((string) $job['provider_post_id']), [], [
                'fields' => 'insights.metric(post_impressions,post_impressions_unique,post_engaged_users,post_clicks),likes.summary(true),comments.summary(true),shares',
                'access_token' => $token,
            ]);
            $raw = $this->requireSuccess($response, 'Facebook metrics sync failed');
            $metrics['likes'] = (int) ($raw['likes']['summary']['total_count'] ?? 0);
            $metrics['comments'] = (int) ($raw['comments']['summary']['total_count'] ?? 0);
            $metrics['shares'] = (int) ($raw['shares']['count'] ?? 0);
            foreach ((array) ($raw['insights']['data'] ?? []) as $item) {
                $name = (string) ($item['name'] ?? '');
                $value = (int) ($item['values'][0]['value'] ?? 0);
                $map = ['post_impressions' => 'impressions', 'post_impressions_unique' => 'reach', 'post_engaged_users' => 'engagements', 'post_clicks' => 'clicks'];
                if (isset($map[$name])) $metrics[$map[$name]] = $value;
            }
        } else {
            $response = $this->request('GET', 'https://api.linkedin.com/rest/socialActions/' . rawurlencode((string) $job['provider_post_id']), $this->linkedInHeaders($token));
            $raw = $this->requireSuccess($response, 'LinkedIn engagement sync failed');
            $metrics['likes'] = (int) ($raw['likesSummary']['totalLikes'] ?? 0);
            $metrics['comments'] = (int) ($raw['commentsSummary']['totalFirstLevelComments'] ?? 0);
            $metrics['engagements'] = $metrics['likes'] + $metrics['comments'];
        }
        Database::execute(
            "INSERT INTO social_media_metric_snapshots
             (workspace_id, account_id, publish_job_id, provider_post_id, impressions, reach, engagements,
              likes, comments, shares, clicks, video_views, followers, raw_json, captured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $this->workspaceId, (int) $account['id'], $jobId, (string) $job['provider_post_id'],
                $metrics['impressions'], $metrics['reach'], $metrics['engagements'], $metrics['likes'],
                $metrics['comments'], $metrics['shares'], $metrics['clicks'], $metrics['video_views'],
                $metrics['followers'], $this->json($raw),
            ]
        );
        Database::execute('UPDATE social_media_accounts SET last_metrics_sync_at = NOW() WHERE workspace_id = ? AND id = ?', [$this->workspaceId, (int) $account['id']]);
        $this->event('metrics_synced', 'success', 'publish_job', $jobId, 'Social metrics synchronized.', $metrics);
    }

    private function discoverMetaAccounts(array $token, int $userId): array
    {
        $userToken = (string) ($token['access_token'] ?? '');
        $longToken = $this->exchangeMetaLongLivedToken($userToken);
        if ($longToken !== '') $userToken = $longToken;
        $response = $this->request('GET', $this->metaGraphUrl('me/accounts'), [], [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
            'access_token' => $userToken,
        ]);
        $json = $this->requireSuccess($response, 'Meta account discovery failed');
        $saved = [];
        foreach ((array) ($json['data'] ?? []) as $page) {
            $pageId = trim((string) ($page['id'] ?? ''));
            $pageToken = trim((string) ($page['access_token'] ?? ''));
            if ($pageId === '' || $pageToken === '') continue;
            $saved[] = $this->upsertAccount([
                'provider' => 'meta', 'channel' => 'facebook', 'external_account_id' => $pageId,
                'account_name' => (string) ($page['name'] ?? 'Facebook Page'), 'access_token' => $pageToken,
                'scopes' => $token['scope'] ?? [], 'token_expires_at' => $token['expires_at'] ?? null,
                'metadata' => ['source' => 'meta_oauth', 'page_id' => $pageId], 'connected_by' => $userId,
            ]);
            $instagram = (array) ($page['instagram_business_account'] ?? []);
            if (!empty($instagram['id'])) {
                $saved[] = $this->upsertAccount([
                    'provider' => 'meta', 'channel' => 'instagram', 'external_account_id' => (string) $instagram['id'],
                    'parent_account_id' => $pageId, 'account_name' => (string) ($instagram['name'] ?? $instagram['username'] ?? 'Instagram Professional'),
                    'account_handle' => (string) ($instagram['username'] ?? ''), 'access_token' => $pageToken,
                    'scopes' => $token['scope'] ?? [], 'token_expires_at' => $token['expires_at'] ?? null,
                    'metadata' => ['source' => 'meta_oauth', 'page_id' => $pageId, 'profile_picture_url' => $instagram['profile_picture_url'] ?? null],
                    'connected_by' => $userId,
                ]);
            }
        }
        return $saved;
    }

    private function discoverLinkedInAccounts(array $token, int $userId): array
    {
        $accessToken = (string) ($token['access_token'] ?? '');
        $authorizationUrl = 'https://api.linkedin.com/rest/organizationAuthorizations'
            . '?bq=authorizationActionsAndImpersonator'
            . '&authorizationActions=List((authorizationAction:(organizationContentAuthorizationAction:(actionType:ORGANIC_SHARE_CREATE))))';
        $response = $this->request('GET', $authorizationUrl, $this->linkedInHeaders($accessToken));
        $elements = [];
        if ((int) ($response['status'] ?? 0) >= 200 && (int) ($response['status'] ?? 0) < 300 && empty($response['error'])) {
            $json = (array) ($response['json'] ?? []);
            foreach ((array) ($json['elements'] ?? []) as $group) {
                foreach ((array) ($group['elements'] ?? [$group]) as $authorization) {
                    $status = $this->json((array) ($authorization['status'] ?? []));
                    if ($status !== '[]' && !str_contains($status, 'Approved')) continue;
                    $elements[] = (array) $authorization;
                }
            }
        } else {
            $fallback = $this->request('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED', $this->linkedInHeaders($accessToken));
            $elements = (array) ($this->requireSuccess($fallback, 'LinkedIn Company Page discovery failed')['elements'] ?? []);
        }
        $saved = [];
        foreach ($elements as $element) {
            $urn = (string) ($element['organization'] ?? '');
            if (!preg_match('/urn:li:organization:(\d+)/', $urn, $match)) continue;
            $organizationId = $match[1];
            $detail = $this->request('GET', 'https://api.linkedin.com/rest/organizations/' . rawurlencode($organizationId), $this->linkedInHeaders($accessToken));
            $detailJson = $this->requireSuccess($detail, 'LinkedIn organization detail lookup failed');
            $saved[] = $this->upsertAccount([
                'provider' => 'linkedin', 'channel' => 'linkedin', 'external_account_id' => $organizationId,
                'account_name' => (string) ($detailJson['localizedName'] ?? 'LinkedIn Company Page'),
                'account_handle' => (string) ($detailJson['vanityName'] ?? ''), 'access_token' => $accessToken,
                'refresh_token' => (string) ($token['refresh_token'] ?? ''), 'scopes' => $token['scope'] ?? [],
                'token_expires_at' => $token['expires_at'] ?? null,
                'metadata' => ['source' => 'linkedin_oauth', 'organization_urn' => $urn], 'connected_by' => $userId,
            ]);
        }
        return $saved;
    }

    private function upsertAccount(array $data): array
    {
        $provider = $this->provider((string) ($data['provider'] ?? ''));
        $channel = $this->channel((string) ($data['channel'] ?? ''));
        $externalId = $this->clean($data['external_account_id'] ?? '', 255);
        if ($externalId === '') throw new RuntimeException('Provider account identifier is missing.');
        $accessToken = trim((string) ($data['access_token'] ?? ''));
        if ($accessToken === '') throw new RuntimeException('Provider access token is missing.');
        $encryptedAccess = $this->vault->encrypt($accessToken);
        $encryptedRefresh = $this->vault->encrypt((string) ($data['refresh_token'] ?? ''));
        $expires = $this->nullableDateTime($data['token_expires_at'] ?? null);
        Database::execute(
            "INSERT INTO social_media_accounts
             (workspace_id, uuid, provider, channel, external_account_id, parent_account_id, account_name,
              account_handle, status, scopes_json, access_token_encrypted, refresh_token_encrypted,
              token_expires_at, last_verified_at, last_error, metadata_json, connected_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, NOW(), NULL, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider = VALUES(provider), parent_account_id = VALUES(parent_account_id), account_name = VALUES(account_name),
                account_handle = VALUES(account_handle), status = 'active', scopes_json = VALUES(scopes_json),
                access_token_encrypted = VALUES(access_token_encrypted), refresh_token_encrypted = VALUES(refresh_token_encrypted),
                token_expires_at = VALUES(token_expires_at), last_verified_at = NOW(), last_error = NULL,
                metadata_json = VALUES(metadata_json), connected_by = VALUES(connected_by)",
            [
                $this->workspaceId, $this->uuid(), $provider, $channel, $externalId,
                $this->clean($data['parent_account_id'] ?? '', 255) ?: null,
                $this->clean($data['account_name'] ?? ucfirst($channel), 255),
                $this->clean($data['account_handle'] ?? '', 255) ?: null,
                $this->json(array_values(array_filter(array_map('strval', (array) ($data['scopes'] ?? []))))),
                $encryptedAccess, $encryptedRefresh, $expires, $this->json((array) ($data['metadata'] ?? [])),
                !empty($data['connected_by']) ? (int) $data['connected_by'] : null,
            ]
        );
        $row = Database::queryOne(
            'SELECT id FROM social_media_accounts WHERE workspace_id = ? AND channel = ? AND external_account_id = ? LIMIT 1',
            [$this->workspaceId, $channel, $externalId]
        );
        $accountId = (int) ($row['id'] ?? 0);
        $this->event('account_connected', 'success', 'account', $accountId, ucfirst($channel) . ' account connected.', ['provider' => $provider], (int) ($data['connected_by'] ?? 0));
        return $this->getAccount($accountId) ?: [];
    }

    private function exchangeOAuthCode(string $provider, string $code, array $config): array
    {
        $response = $this->request('POST', (string) $config['token_url'], [], [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'code' => $code,
        ], 'form');
        $json = $this->requireSuccess($response, ucfirst($provider) . ' token exchange failed');
        if (empty($json['access_token'])) throw new RuntimeException(ucfirst($provider) . ' did not return an access token.');
        $json['scope'] = is_string($json['scope'] ?? null)
            ? preg_split('/[\s,]+/', trim((string) $json['scope'])) ?: []
            : (array) ($json['scope'] ?? []);
        if (!empty($json['expires_in'])) {
            $json['expires_at'] = date('Y-m-d H:i:s', time() + max(0, (int) $json['expires_in']));
        }
        return $json;
    }

    private function exchangeMetaLongLivedToken(string $shortToken): string
    {
        if ($shortToken === '') return '';
        $response = $this->request('GET', $this->metaGraphUrl('oauth/access_token'), [], [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->env('META_APP_ID'),
            'client_secret' => $this->env('META_APP_SECRET'),
            'fb_exchange_token' => $shortToken,
        ]);
        if ((int) $response['status'] < 200 || (int) $response['status'] >= 300) return '';
        return trim((string) ($response['json']['access_token'] ?? ''));
    }

    private function providerConfig(string $provider): array
    {
        $appUrl = rtrim($this->env('APP_URL'), '/');
        if ($appUrl === '') throw new RuntimeException('APP_URL must be configured before connecting social accounts.');
        if ($provider === 'meta') {
            $clientId = $this->env('META_APP_ID');
            $clientSecret = $this->env('META_APP_SECRET');
            return [
                'configured' => $clientId !== '' && $clientSecret !== '',
                'client_id' => $clientId, 'client_secret' => $clientSecret,
                'authorize_url' => 'https://www.facebook.com/' . $this->metaVersion() . '/dialog/oauth',
                'token_url' => $this->metaGraphUrl('oauth/access_token'),
                'redirect_uri' => $appUrl . '/api/social/oauth/callback.php?provider=meta',
                'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish', 'instagram_manage_insights'],
                'missing_message' => 'Meta OAuth is not configured. Set META_APP_ID and META_APP_SECRET.',
            ];
        }
        $clientId = $this->env('LINKEDIN_CLIENT_ID');
        $clientSecret = $this->env('LINKEDIN_CLIENT_SECRET');
        return [
            'configured' => $clientId !== '' && $clientSecret !== '',
            'client_id' => $clientId, 'client_secret' => $clientSecret,
            'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'redirect_uri' => $appUrl . '/api/social/oauth/callback.php?provider=linkedin',
            'scopes' => ['openid', 'profile', 'w_organization_social', 'r_organization_social', 'rw_organization_admin'],
            'missing_message' => 'LinkedIn OAuth is not configured. Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET.',
        ];
    }

    private function request(string $method, string $url, array $headers = [], mixed $body = null, string $bodyType = 'query'): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $headers, $body, $bodyType);
        }

        $method = strtoupper($method);
        if ($method === 'GET' && is_array($body) && $body !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            $body = null;
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Could not initialize provider request.');
        $headerLines = array_values($headers);
        if ($body !== null && $method !== 'GET') {
            if ($bodyType === 'json') {
                $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $headerLines[] = 'Content-Type: application/json';
            } elseif ($bodyType === 'form') {
                $body = http_build_query((array) $body, '', '&', PHP_QUERY_RFC3986);
                $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'CRM-SocialMedia/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ];
        if ($body !== null && $method !== 'GET') $options[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($responseBody === false) $responseBody = '';
        $json = json_decode((string) $responseBody, true);
        return [
            'status' => $status, 'headers' => $responseHeaders,
            'body' => (string) $responseBody, 'json' => is_array($json) ? $json : [],
            'error' => $error, 'content_type' => $contentType,
        ];
    }

    private function requireSuccess(array $response, string $message): array
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300 && empty($response['error'])) {
            return (array) ($response['json'] ?? []);
        }
        $json = (array) ($response['json'] ?? []);
        $providerMessage = (string) ($json['error']['message'] ?? $json['message'] ?? $response['error'] ?? '');
        $providerCode = (string) ($json['error']['code'] ?? $json['status'] ?? $status);
        throw new RuntimeException($message . ($providerMessage !== '' ? ': ' . mb_substr($providerMessage, 0, 500) : '') . ' [provider ' . $providerCode . ']');
    }

    private function downloadMedia(string $url, int $maxBytes, array $allowedTypes): array
    {
        $url = $this->optionalUrl($url);
        if ($url === '') throw new RuntimeException('Media URL is invalid.');
        $addresses = $this->assertPublicDownloadUrl($url);
        if ($this->httpClient !== null) {
            $response = $this->request('GET', $url);
            if ((int) $response['status'] < 200 || (int) $response['status'] >= 300) {
                throw new RuntimeException('Media download failed with HTTP ' . (int) $response['status'] . '.');
            }
            if (strlen((string) $response['body']) > $maxBytes) throw new RuntimeException('Media file is larger than the supported upload limit.');
            $contentType = strtolower(trim(explode(';', (string) ($response['content_type'] ?? ''))[0]));
            if (!in_array($contentType, $allowedTypes, true)) throw new RuntimeException('Media type is not supported for this provider upload.');
            return ['body' => (string) $response['body'], 'content_type' => $contentType];
        }

        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Could not initialize the media download.');
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'CRM-SocialMedia/1.0',
            CURLOPT_HTTPHEADER => ['Accept: ' . implode(', ', $allowedTypes)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $ipv4 = current(array_filter($addresses, static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        if (is_string($ipv4) && $ipv4 !== '') $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ipv4];
        curl_setopt_array($ch, $options);
        $completed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('Media file is larger than the supported upload limit.');
        if ($completed === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Media download failed' . ($status > 0 ? ' with HTTP ' . $status : '') . ($error !== '' ? ': ' . $error : '') . '.');
        }
        if (!in_array($contentType, $allowedTypes, true)) throw new RuntimeException('Media type is not supported for this provider upload.');
        return ['body' => $body, 'content_type' => $contentType];
    }

    private function trackedLinkUrl(string $url, string $channel, array $settings, int $campaignId, string $requestId): string
    {
        if ($url === '') return '';
        $fragment = '';
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition);
            $url = substr($url, 0, $hashPosition);
        }
        $query = [];
        $queryString = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($queryString !== '') parse_str($queryString, $query);
        $base = preg_replace('/\?.*$/', '', $url) ?? $url;
        $defaults = [
            'utm_source' => (string) (($settings['default_utm_source'] ?? '') ?: $channel),
            'utm_medium' => (string) (($settings['default_utm_medium'] ?? '') ?: 'organic_social'),
            'utm_campaign' => $campaignId > 0 ? 'campaign_' . $campaignId : 'social_media',
            'utm_content' => $channel . '_' . substr(hash('sha256', $requestId), 0, 12),
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($query[$key]) || trim((string) $query[$key]) === '') $query[$key] = $value;
        }
        return $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    private function assertPublicDownloadUrl(string $url): array
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('Media must use a publicly reachable URL.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
                if (!empty($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('The media host could not be resolved.');
        }
        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Media URLs cannot resolve to private or reserved network addresses.');
            }
        }
        return array_values(array_unique($addresses));
    }

    private function selectedAccounts(array $accountIds, bool $activeOnly): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$this->workspaceId], $ids);
        $active = $activeOnly ? " AND status = 'active'" : '';
        return array_map(
            fn(array $row): array => $this->hydrateAccount($row),
            Database::query(
                "SELECT id, workspace_id, uuid, provider, channel, external_account_id, parent_account_id,
                        account_name, account_handle, status, scopes_json, token_expires_at, last_verified_at,
                        last_metrics_sync_at, last_error, metadata_json, connected_by, created_at, updated_at
                 FROM social_media_accounts WHERE workspace_id = ? AND id IN ({$placeholders}){$active}",
                $params
            )
        );
    }

    private function runtimeAccount(int $accountId): array
    {
        $account = $this->getAccount($accountId, true);
        if (!$account) throw new RuntimeException('Social account not found in this workspace.');
        return $account;
    }

    private function job(int $jobId): array
    {
        $row = Database::queryOne('SELECT * FROM social_media_publish_jobs WHERE workspace_id = ? AND id = ? LIMIT 1', [$this->workspaceId, $jobId]);
        if (!$row) throw new RuntimeException('Social publish job not found in this workspace.');
        return $this->hydrateJob($row);
    }

    private function existingPublishJobs(array $idempotencyKeys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $idempotencyKeys))));
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        return Database::query(
            "SELECT id FROM social_media_publish_jobs
             WHERE workspace_id = ? AND idempotency_key IN ({$placeholders})
             ORDER BY id ASC",
            array_merge([$this->workspaceId], $keys)
        );
    }

    private function validatePublishingContext(
        int $campaignId,
        int $contentItemId,
        int $distributionPostId = 0,
        int $utmLinkId = 0
    ): array
    {
        $distribution = [];
        if ($distributionPostId > 0) {
            $distribution = Database::queryOne(
                'SELECT id, content_item_id, channel, status FROM marketing_distribution_posts WHERE workspace_id = ? AND id = ? LIMIT 1',
                [$this->workspaceId, $distributionPostId]
            ) ?: [];
            if ($distribution === []) {
                throw new RuntimeException('The prepared distribution handoff was not found in this workspace.');
            }
            $distributionContentId = (int) ($distribution['content_item_id'] ?? 0);
            if ($contentItemId > 0 && $distributionContentId !== $contentItemId) {
                throw new RuntimeException('The prepared distribution handoff does not belong to the selected content item.');
            }
            $contentItemId = $distributionContentId;
        }

        $utmLink = [];
        if ($utmLinkId > 0) {
            $utmLink = Database::queryOne(
                'SELECT id, campaign_id, content_item_id, generated_url FROM marketing_utm_links WHERE workspace_id = ? AND id = ? LIMIT 1',
                [$this->workspaceId, $utmLinkId]
            ) ?: [];
            if ($utmLink === []) {
                throw new RuntimeException('The prepared tracking link was not found in this workspace.');
            }
            $utmContentId = (int) ($utmLink['content_item_id'] ?? 0);
            if ($contentItemId > 0 && $utmContentId > 0 && $utmContentId !== $contentItemId) {
                throw new RuntimeException('The prepared tracking link does not belong to the selected content item.');
            }
            if ($contentItemId <= 0) {
                $contentItemId = $utmContentId;
            }
            $utmCampaignId = (int) ($utmLink['campaign_id'] ?? 0);
            if ($campaignId > 0 && $utmCampaignId > 0 && $utmCampaignId !== $campaignId) {
                throw new RuntimeException('The prepared tracking link does not belong to the selected campaign.');
            }
            if ($campaignId <= 0) {
                $campaignId = $utmCampaignId;
            }
        }

        if ($campaignId > 0) {
            $campaign = Database::queryOne(
                'SELECT id FROM campaigns WHERE workspace_id = ? AND id = ? LIMIT 1',
                [$this->workspaceId, $campaignId]
            );
            if (!$campaign) {
                throw new RuntimeException('The selected campaign was not found in this workspace.');
            }
        }
        if ($contentItemId <= 0) {
            return [$campaignId, 0, $distribution, $utmLink];
        }

        $content = Database::queryOne(
            'SELECT id, campaign_id FROM marketing_content_items WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$this->workspaceId, $contentItemId]
        );
        if (!$content) {
            throw new RuntimeException('The selected content item was not found in this workspace.');
        }
        $contentCampaignId = max(0, (int) ($content['campaign_id'] ?? 0));
        if ($campaignId > 0 && $campaignId !== $contentCampaignId) {
            throw new RuntimeException('The selected content item does not belong to the selected campaign.');
        }
        if ($campaignId <= 0) {
            $campaignId = $contentCampaignId;
        }

        return [$campaignId, $contentItemId, $distribution, $utmLink];
    }

    private function hydrateAccount(array $row, bool $includeToken = false): array
    {
        foreach (['scopes_json' => 'scopes', 'metadata_json' => 'metadata'] as $column => $target) {
            $decoded = json_decode((string) ($row[$column] ?? ''), true);
            $row[$target] = is_array($decoded) ? $decoded : [];
        }
        $row['token_expiring'] = !empty($row['token_expires_at']) && strtotime((string) $row['token_expires_at']) <= time() + (7 * 86400);
        if (!$includeToken) {
            unset($row['access_token_encrypted'], $row['refresh_token_encrypted']);
        }
        return $row;
    }

    private function hydrateJob(array $row): array
    {
        foreach (['media_json', 'request_json', 'response_json'] as $column) {
            $decoded = json_decode((string) ($row[$column] ?? ''), true);
            $row[$column] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    private function normalizeMedia(array $media): array
    {
        $out = [];
        foreach (array_slice($media, 0, 10) as $item) {
            if (!is_array($item)) continue;
            $url = $this->optionalUrl($item['url'] ?? '');
            if ($url === '') continue;
            $type = strtolower(trim((string) ($item['type'] ?? 'image')));
            if (!in_array($type, ['image', 'video'], true)) throw new RuntimeException('Media type must be image or video.');
            $out[] = ['url' => $url, 'type' => $type, 'alt_text' => $this->clean($item['alt_text'] ?? '', 500)];
        }
        return $out;
    }

    private function validateMediaForChannel(array $media, string $channel): void
    {
        if ($channel === 'instagram' && $media === []) {
            throw new RuntimeException('Instagram requires an image or video.');
        }
        if ($channel === 'instagram' && count($media) > 1) {
            throw new RuntimeException('Instagram v1 supports one image or video per scheduled post.');
        }
        if ($channel === 'linkedin' && !empty($media[0]) && (string) ($media[0]['type'] ?? '') !== 'image') {
            throw new RuntimeException('LinkedIn v1 supports text, links, and one image; video publishing is not enabled yet.');
        }
    }

    private function validateCaption(string $caption, string $channel): void
    {
        $limits = ['facebook' => 5000, 'instagram' => 2200, 'linkedin' => 3000];
        $limit = $limits[$channel] ?? 3000;
        if ($caption === '') throw new RuntimeException('A caption is required for ' . ucfirst($channel) . '.');
        if (mb_strlen($caption) > $limit) throw new RuntimeException(ucfirst($channel) . ' caption exceeds the supported ' . $limit . '-character limit.');
    }

    private function deterministicVariant(string $idea, string $channel, array $settings): string
    {
        $idea = trim($idea);
        $cta = trim((string) ($settings['preferred_cta'] ?? ''));
        return match ($channel) {
            'instagram' => rtrim($idea) . ($cta !== '' ? "\n\n" . $cta : '') . "\n\n#business #growth",
            'linkedin' => rtrim($idea) . ($cta !== '' ? "\n\n" . $cta : ''),
            default => rtrim($idea) . ($cta !== '' ? "\n\n" . $cta : ''),
        };
    }

    private function containsProhibitedClaim(string $candidate, string $prohibitedClaims): bool
    {
        $candidate = mb_strtolower($candidate);
        foreach (preg_split('/[\r\n,;]+/', $prohibitedClaims) ?: [] as $claim) {
            $claim = mb_strtolower(trim((string) $claim));
            if (mb_strlen($claim) >= 4 && str_contains($candidate, $claim)) {
                return true;
            }
        }

        return false;
    }

    private function visiblePublishRequest(array $job, array $account): array
    {
        return [
            'job_uuid' => (string) $job['uuid'], 'provider' => (string) $account['provider'],
            'channel' => (string) $account['channel'], 'account_name' => (string) $account['account_name'],
            'caption_length' => mb_strlen((string) $job['caption']), 'link_present' => !empty($job['link_url']),
            'media_count' => count((array) ($job['media_json'] ?? [])), 'scheduled_at' => $job['scheduled_at'] ?? null,
            'idempotency_key' => (string) $job['idempotency_key'],
        ];
    }

    private function event(string $type, string $status, string $subjectType, ?int $subjectId, string $message, array $metadata = [], int $userId = 0): void
    {
        try {
            Database::execute(
                "INSERT INTO social_media_events
                 (workspace_id, event_type, status, subject_type, subject_id, message, metadata_json, actor_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$this->workspaceId, $type, $status, $subjectType, $subjectId, mb_substr($message, 0, 500), $this->json($metadata), $userId > 0 ? $userId : null]
            );
        } catch (Throwable $e) {
            // Audit recording must not turn a successfully persisted action into a failure.
        }
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, self::PROVIDERS, true)) throw new RuntimeException('Unsupported social provider.');
        return $provider;
    }

    private function channel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) throw new RuntimeException('Unsupported social channel.');
        return $channel;
    }

    private function metaVersion(): string
    {
        $version = trim($this->env('META_GRAPH_API_VERSION'));
        return preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v24.0';
    }

    private function metaGraphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . $this->metaVersion() . '/' . ltrim($path, '/');
    }

    private function linkedInHeaders(string $token): array
    {
        $version = trim($this->env('LINKEDIN_API_VERSION'));
        if (!preg_match('/^\d{6}$/', $version)) $version = '202606';
        return ['Authorization: Bearer ' . $token, 'Linkedin-Version: ' . $version, 'X-Restli-Protocol-Version: 2.0.0', 'Accept: application/json'];
    }

    private function normalizeSchedule(string $value, string $timezone): string
    {
        $value = trim($value);
        if ($value === '') return date('Y-m-d H:i:s');
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone($timezone));
            return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            throw new RuntimeException('Choose a valid publish date and time.');
        }
    }

    private function optionalUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') return '';
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('Use a valid http or https URL.');
        }
        return mb_substr($url, 0, 1000);
    }

    private function safeReturnPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (!str_starts_with($path, '/') || str_contains($path, '://') || str_starts_with($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
            return '';
        }
        $base = basename((string) parse_url($path, PHP_URL_PATH));
        if (!in_array($base, ['workspace_skills.php', 'social_media.php'], true)) {
            return '';
        }
        return mb_substr($path, 0, 500);
    }

    private function clean(mixed $value, int $max): string
    {
        return mb_substr(trim(Security::sanitizeInput((string) $value, 'string')), 0, $max);
    }

    private function cleanMultiline(mixed $value, int $max): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));
        $value = strip_tags($value);
        return mb_substr($value, 0, $max);
    }

    private function utmValue(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        $value = trim(preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '', '_');
        return mb_substr($value !== '' ? $value : $fallback, 0, 120);
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private function decodeJsonObject(string $response): array
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $response) ?? $response;
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return trim(is_string($value) ? $value : '');
    }

    private function errorClass(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'expired'), str_contains($message, 'token') => 'authentication',
            str_contains($message, 'permission'), str_contains($message, 'scope') => 'permission',
            str_contains($message, 'rate'), str_contains($message, 'limit') => 'rate_limit',
            str_contains($message, 'media'), str_contains($message, 'image'), str_contains($message, 'video') => 'media',
            str_contains($message, 'processing') => 'provider_processing',
            default => 'provider_error',
        };
    }
}
