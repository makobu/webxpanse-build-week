<?php

namespace CRM\Services;

use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\OutcomeMetrics;

class PlainLanguageReadinessService
{
    public const CACHE_VERSION = 'v3';
    public const CACHE_TTL_SECONDS = 300;
    private const AI_CONTEXT_READY_SCORE = 85;

    private CacheManager $cache;
    private WorkspaceOnboardingService $onboarding;
    private OutcomeEventService $outcomes;
    private OutcomeMetrics $metrics;

    public function __construct(
        ?CacheManager $cache = null,
        ?WorkspaceOnboardingService $onboarding = null,
        ?OutcomeEventService $outcomes = null,
        ?OutcomeMetrics $metrics = null
    ) {
        $this->cache = $cache ?: new CacheManager();
        $this->onboarding = $onboarding ?: new WorkspaceOnboardingService();
        $this->outcomes = $outcomes ?: new OutcomeEventService();
        $this->metrics = $metrics ?: new OutcomeMetrics();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function summaryFor(int $workspaceId, int $userId, array $context = []): array
    {
        $mode = $this->normalizeMode((string) ($context['mode'] ?? UIExperienceService::MODE_BEGINNER));
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $cacheKey = 'plain_readiness:' . self::CACHE_VERSION . ':' . $workspaceId . ':' . $userId . ':' . $mode
            . ':' . $this->cacheFingerprint($workspaceId, $userId);
        $skipCache = !empty($context['skip_cache']);

        if (!$skipCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $payload = $workspaceId > 0 && $this->onboarding->tableReady()
                ? $this->buildWorkspacePayload($workspaceId, $userId, $mode)
                : $this->fallbackPayload($userId, $mode);
        } catch (\Throwable $e) {
            $payload = $this->fallbackPayload($userId, $mode);
        }

        if (!$skipCache) {
            $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        }

        return $payload;
    }

    private function cacheFingerprint(int $workspaceId, int $userId): string
    {
        if ($workspaceId <= 0) {
            return 'fallback';
        }

        try {
            $signals = [
                'workspace' => Database::tableExists('workspaces')
                    ? (Database::queryOne("SELECT id, uuid, updated_at FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [])
                    : [],
                'onboarding' => Database::tableExists('workspace_onboarding_state')
                    ? (Database::queryOne(
                        "SELECT status, current_step, completed_steps_json, skipped_optional_json,
                                communication_channel, automation_launch_mode, ai_autoresponder_mode,
                                ai_best_practices_enabled, commercial_layer_enabled, deal_automation_enabled,
                                readiness_score, launch_summary_json, updated_at
                         FROM workspace_onboarding_state
                         WHERE workspace_id = ?
                         LIMIT 1",
                        [$workspaceId]
                    ) ?: [])
                    : [],
                'profile' => Database::tableExists('company_profile')
                    ? (Database::queryOne(
                        "SELECT company_name, company_legal_name, company_tax_id, company_description,
                                owner_company_context, company_website, company_email, company_phone,
                                company_address, company_location, company_logo_url, updated_at
                         FROM company_profile
                         WHERE workspace_id = ? AND is_active = TRUE
                         LIMIT 1",
                        [$workspaceId]
                    ) ?: [])
                    : [],
                'products' => Database::tableExists('products')
                    ? Database::query(
                        "SELECT id, name, target_audience, pricing_info, unit_price, is_active, updated_at
                         FROM products
                         WHERE workspace_id = ? AND is_active = TRUE
                         ORDER BY id
                         LIMIT 25",
                        [$workspaceId]
                    )
                    : [],
                'strategy' => Database::tableExists('user_strategy_profiles')
                    ? (Database::queryOne(
                        "SELECT id, workspace_id, ideal_customer_profile, offer_angle, positioning_notes,
                                deal_movement_strategy, draft_tone_preset, draft_voice_notes, updated_at
                         FROM user_strategy_profiles
                         WHERE user_id = ? AND workspace_id IN (?, 0)
                         ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, id DESC
                         LIMIT 1",
                        [$userId, $workspaceId, $workspaceId]
                    ) ?: [])
                    : [],
                'contacts' => Database::tableExists('contacts')
                    ? (Database::queryOne(
                        "SELECT COUNT(*) AS count, COALESCE(MAX(updated_at), MAX(created_at)) AS updated_at
                         FROM contacts
                         WHERE workspace_id = ?",
                        [$workspaceId]
                    ) ?: [])
                    : [],
                'email' => Database::tableExists('email_integrations')
                    ? (Database::queryOne(
                        "SELECT COUNT(*) AS count, COALESCE(MAX(updated_at), MAX(created_at)) AS updated_at
                         FROM email_integrations
                         WHERE workspace_id = ? AND is_active = 1",
                        [$workspaceId]
                    ) ?: [])
                    : [],
                'whatsapp' => Database::tableExists('workspace_whatsapp_integrations')
                    ? (Database::queryOne(
                        "SELECT connection_status, phone_number_id, updated_at
                         FROM workspace_whatsapp_integrations
                         WHERE workspace_id = ?
                         LIMIT 1",
                        [$workspaceId]
                    ) ?: [])
                    : [],
                'invoice' => Database::tableExists('invoice_settings')
                    ? (Database::queryOne(
                        "SELECT enabled, company_legal_name, company_email, company_address, default_currency,
                                default_payment_terms_days, invoice_prefix, bank_name, bank_account_number,
                                bank_instructions, updated_at
                         FROM invoice_settings
                         WHERE id = 1
                         LIMIT 1"
                    ) ?: [])
                    : [],
                'channel_readiness' => $this->channelReadinessSignals($workspaceId),
            ];

            return substr(hash('sha256', (string) json_encode($signals, JSON_UNESCAPED_SLASHES)), 0, 20);
        } catch (\Throwable $e) {
            return 'unsafe-' . ((string) floor(time() / 60));
        }
    }

    /**
     * @return array<string,bool>
     */
    private function channelReadinessSignals(int $workspaceId): array
    {
        try {
            $email = new EmailIntegrationService();
            $system = $email->getMainProviderSummary($workspaceId);
            return [
                'system_mail' => !empty($system['smtp_fallback_configured']),
                'outreach_email' => $email->isStrictRoleOutboundReady('outreach', $workspaceId),
                'nurture_email' => $email->isStrictRoleOutboundReady('nurture', $workspaceId),
                'whatsapp' => (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId) !== null,
            ];
        } catch (\Throwable $e) {
            return [
                'system_mail' => false,
                'outreach_email' => false,
                'nurture_email' => false,
                'whatsapp' => false,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function fallbackPayload(int $userId, string $mode = UIExperienceService::MODE_BEGINNER): array
    {
        $progress = [];
        try {
            $progress = $this->outcomes->getActivationProgress(max(0, $userId));
        } catch (\Throwable $e) {
            $progress = [];
        }

        $milestones = [
            $this->milestone('first_login', 'Signed in', !empty($progress['first_login_at'])),
            $this->milestone('customer_list', 'Add a customer', !empty($progress['first_contact_at'])),
            $this->milestone('channel', 'Connect email or WhatsApp', !empty($progress['connected_channel_at'])),
            $this->milestone('first_inbound', 'First customer message', !empty($progress['first_inbound_at'])),
            $this->milestone('first_followup', 'Finish a follow-up', !empty($progress['first_followup_task_completed_at'])),
            $this->milestone('first_deal', 'Create a job or deal', !empty($progress['first_deal_created_at'])),
        ];

        return $this->payload($mode, $milestones, $this->firstGap($milestones), 'activation_progress_fallback');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWorkspacePayload(int $workspaceId, int $userId, string $mode): array
    {
        $state = $this->onboarding->getState($workspaceId, $userId);
        $readiness = (array) ($state['readiness'] ?? []);
        $profile = (array) ($readiness['profile'] ?? []);
        $products = (array) ($readiness['products'] ?? []);
        $strategy = (array) ($readiness['strategy'] ?? []);
        $row = (array) ($state['row'] ?? []);
        $invoiceSettings = (new InvoiceSettings())->get();

        $profileReady = $this->companyProfileReady($profile);
        $productsReady = $this->productCatalogReady($products);
        $businessReady = $profileReady && $productsReady;
        $businessKey = $profileReady
            ? ($productsReady ? 'profile_products' : 'product_catalog')
            : 'company_profile';
        $customerReady = $this->contactCount($workspaceId) > 0;
        $channelReady = !empty($readiness['channel_ready'])
            || !empty($readiness['connected_email'])
            || !empty($readiness['connected_whatsapp']);
        $invoiceReadiness = $this->invoiceSettingsReadiness($invoiceSettings);
        $moneyReady = !empty($invoiceReadiness['ready']);
        $moneyKey = empty($invoiceReadiness['identity_ready']) ? 'document_identity' : 'money';
        $aiContextReview = (new WorkspaceOnboardingContextGapService())->review(
            $profile,
            $products,
            $strategy,
            $invoiceSettings,
            $row,
            $readiness
        );
        $aiContextReady = $this->aiContextReady($aiContextReview);
        $safeAutomationReady = !empty($readiness['autopilot_ready']);

        $milestones = [
            $this->milestone($businessKey, 'Profile and products saved', $businessReady),
            $this->milestone('customer_list', 'Add a customer', $customerReady),
            $this->milestone('channel', 'Connect email or WhatsApp', $channelReady),
            $this->milestone($moneyKey, 'Ready to create invoices', $moneyReady),
            $this->milestone('ai_context', 'AI understands the business', $aiContextReady),
            $this->milestone('safe_automation', 'Approval rules chosen', $safeAutomationReady),
        ];

        $primaryGap = $this->firstGap($milestones);
        $setupPayload = $this->payload($mode, $milestones, $primaryGap, 'deterministic');
        if ($primaryGap !== null) {
            return $setupPayload;
        }

        try {
            return $this->momentumPayload($mode, $this->metrics->getRevenueMomentumChecklist($userId));
        } catch (\Throwable $e) {
            return $setupPayload;
        }
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function companyProfileReady(array $profile): bool
    {
        $companyName = trim((string) ($profile['company_name'] ?? ''));
        if ($companyName === '' || strcasecmp($companyName, 'Your Company Name') === 0) {
            return false;
        }

        if (!$this->hasUsefulText($profile['company_description'] ?? '')) {
            return false;
        }

        foreach ([
            'company_email',
            'company_phone',
            'company_website',
            'company_address',
            'company_location',
        ] as $field) {
            if ($this->hasUsefulText($profile[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     */
    private function productCatalogReady(array $products): bool
    {
        foreach ($products as $product) {
            $nameReady = trim((string) ($product['name'] ?? '')) !== '';
            $targetReady = $this->hasUsefulText($product['target_audience'] ?? '');
            $pricingReady = $this->hasUsefulText($product['pricing_info'] ?? '')
                || (float) ($product['unit_price'] ?? 0) > 0;

            if ($nameReady && $targetReady && $pricingReady) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function invoiceSettingsReadiness(array $settings): array
    {
        $paymentInstructionsReady = $this->hasUsefulText($settings['bank_instructions'] ?? '')
            || (
                $this->hasUsefulText($settings['bank_name'] ?? '')
                && $this->hasUsefulText($settings['bank_account_number'] ?? '')
            );

        $identityReady = !empty($settings['enabled'])
            && $this->hasUsefulText($settings['company_legal_name'] ?? '')
            && $this->validEmail($settings['company_email'] ?? '')
            && $this->hasUsefulText($settings['company_address'] ?? '');

        $settingsReady = !empty($settings['enabled'])
            && $this->hasUsefulText($settings['default_currency'] ?? '')
            && (int) ($settings['default_payment_terms_days'] ?? 0) > 0
            && $this->hasUsefulText($settings['invoice_prefix'] ?? '')
            && $paymentInstructionsReady;

        return [
            'identity_ready' => $identityReady,
            'settings_ready' => $settingsReady,
            'payment_ready' => $paymentInstructionsReady,
            'ready' => $identityReady && $settingsReady,
        ];
    }

    /**
     * @param array<string,mixed> $review
     */
    private function aiContextReady(array $review): bool
    {
        return (int) ($review['score'] ?? 0) >= self::AI_CONTEXT_READY_SCORE
            && empty($review['requires_answers']);
    }

    private function hasUsefulText(mixed $value): bool
    {
        $text = trim((string) $value);
        return $text !== '' && mb_strlen($text) >= 3 && !in_array(strtolower($text), ['n/a', 'none', 'not sure', 'not sure yet', 'tbd'], true);
    }

    private function validEmail(mixed $value): bool
    {
        $email = trim((string) $value);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param array<int,array<string,mixed>> $milestones
     * @return array<string,mixed>
     */
    private function payload(string $mode, array $milestones, ?array $primaryGap, string $source): array
    {
        $mode = $this->normalizeMode($mode);
        $complete = count(array_filter($milestones, static fn(array $item): bool => !empty($item['complete'])));
        $total = count($milestones);

        return [
            'mode' => $mode,
            'phase' => 'setup',
            'headline_label' => $mode === UIExperienceService::MODE_ADVANCED ? 'Activation Progress' : 'Setup Progress',
            'progress_label' => $complete . ' of ' . $total . ' ready',
            'primary_gap' => $primaryGap ?: [
                'key' => 'daily_work_ready',
                'label' => 'Setup is ready for daily work',
                'reason' => 'Core setup is ready. The dashboard can stay focused on revenue work.',
                'category' => 'setup',
                'status_label' => 'Ready for daily work',
            ],
            'milestones' => array_values(array_slice($milestones, 0, 6)),
            'source' => $source,
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function momentumPayload(string $mode, array $items): array
    {
        $mode = $this->normalizeMode($mode);
        $milestones = [];
        foreach (array_slice($items, 0, 6) as $item) {
            $key = (string) ($item['step_key'] ?? '');
            $complete = !empty($item['complete']);
            $count = (int) ($item['count'] ?? 0);
            $milestones[] = [
                'key' => $key,
                'label' => $this->momentumLabel($key, $complete, $count),
                'complete' => $complete,
                'count' => $count,
            ];
        }

        $complete = count(array_filter($milestones, static fn(array $item): bool => !empty($item['complete'])));
        $total = count($milestones);

        return [
            'mode' => $mode,
            'phase' => 'revenue_momentum',
            'headline_label' => 'Revenue Momentum',
            'progress_label' => $complete . ' of ' . $total . ' moving today',
            'primary_gap' => $this->firstMomentumGap($milestones) ?: [
                'key' => 'daily_loop_moving',
                'label' => 'Daily loop is moving',
                'reason' => 'Your setup is complete and today\'s revenue loop is moving.',
                'href' => 'contacts.php?stage=new',
                'cta_label' => 'Review leads',
                'category' => 'revenue_momentum',
                'status_label' => 'Daily loop is moving',
            ],
            'milestones' => $milestones,
            'source' => 'dashboard_momentum',
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ];
    }

    private function momentumLabel(string $key, bool $complete, int $count): string
    {
        $labels = [
            'followups_clear' => 'Follow-ups clear',
            'due_followups' => 'Finish due follow-ups',
            'no_stale_deals' => 'No quiet deals',
            'stale_deals' => 'Revive quiet deals',
            'pipeline_active' => 'Pipeline active',
            'refill_pipeline' => 'Create a deal',
            'lead_refill' => 'Lead flow today',
            'add_leads' => $count <= 0 ? 'Create your first lead' : 'Add more leads',
            'deal_movement' => 'Deal moved this week',
            'move_deal' => 'Move one deal forward',
            'revenue_action' => 'Revenue action logged',
            'action_gap' => 'Complete one revenue action',
        ];

        return $labels[$key] ?? ($complete ? 'Revenue signal moving' : 'Move revenue forward');
    }

    /**
     * @param array<int,array<string,mixed>> $milestones
     * @return array<string,mixed>|null
     */
    private function firstMomentumGap(array $milestones): ?array
    {
        foreach ($milestones as $milestone) {
            if (empty($milestone['complete'])) {
                return $this->momentumGapForKey(
                    (string) ($milestone['key'] ?? ''),
                    (int) ($milestone['count'] ?? 0)
                );
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function momentumGapForKey(string $key, int $count): array
    {
        $gaps = [
            'due_followups' => [
                'label' => 'Finish due follow-ups',
                'reason' => 'Follow-ups are due now. Clear them first so customer promises do not slip.',
                'href' => 'tasks.php?overdue=1',
                'cta_label' => 'Open follow-ups',
                'status_label' => $count > 0 ? $count . ' follow-up' . ($count === 1 ? '' : 's') . ' due' : 'Follow-ups need attention',
            ],
            'stale_deals' => [
                'label' => 'Revive quiet deals',
                'reason' => 'One or more open deals have gone quiet. Review the pipeline and choose the next move.',
                'href' => 'deals.php',
                'cta_label' => 'Review deals',
                'status_label' => $count > 0 ? $count . ' quiet deal' . ($count === 1 ? '' : 's') : 'Quiet deals need attention',
            ],
            'refill_pipeline' => [
                'label' => 'Create a deal',
                'reason' => 'A live deal gives the revenue loop something concrete to move forward.',
                'href' => 'deal_create.php',
                'cta_label' => 'Create deal',
                'status_label' => 'Pipeline needs one active deal',
            ],
            'add_leads' => [
                'label' => $count <= 0 ? 'Create your first lead' : 'Add more leads',
                'reason' => $count <= 0
                    ? 'Start the next level by creating one lead for today\'s pipeline.'
                    : 'Lead flow is light today. Add more leads to keep the pipeline warm.',
                'href' => 'contacts_create.php?stage=new&source=dashboard_momentum',
                'cta_label' => 'Create lead',
                'status_label' => $count <= 0 ? 'Waiting for first lead today' : 'Lead flow needs a refill',
            ],
            'move_deal' => [
                'label' => 'Move one deal forward',
                'reason' => 'Move one open deal this week so the pipeline keeps advancing.',
                'href' => 'deals.php',
                'cta_label' => 'Open pipeline',
                'status_label' => 'Waiting for deal movement',
            ],
            'action_gap' => [
                'label' => 'Complete one revenue action',
                'reason' => 'A completed follow-up or deal action proves today\'s revenue loop is working.',
                'href' => 'tasks.php',
                'cta_label' => 'Open tasks',
                'status_label' => 'Waiting for one revenue action',
            ],
        ];

        $gap = $gaps[$key] ?? [
            'label' => 'Move revenue forward',
            'reason' => 'Take the next revenue action so setup turns into daily value.',
            'href' => 'tasks.php',
            'cta_label' => 'Open tasks',
            'status_label' => 'Revenue loop needs attention',
        ];
        $gap['key'] = $key !== '' ? $key : 'revenue_momentum_gap';
        $gap['category'] = 'revenue_momentum';

        return $this->withoutEmptyAction($gap);
    }

    /**
     * @param array<int,array<string,mixed>> $milestones
     * @return array<string,mixed>|null
     */
    private function firstGap(array $milestones): ?array
    {
        foreach ($milestones as $milestone) {
            if (empty($milestone['complete'])) {
                return $this->gapForKey((string) ($milestone['key'] ?? ''));
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function gapForKey(string $key): array
    {
        $gaps = [
            'business_basics' => [
                'label' => 'Save business basics',
                'reason' => 'Clarity needs the business name, work, and customer promise before it can guide setup safely.',
                'href' => 'onboarding.php?step=1',
                'cta_label' => 'Save basics',
                'status_label' => 'Needs business basics first',
            ],
            'company_profile' => [
                'label' => 'Complete company profile',
                'reason' => 'Settings need the company name, description, and a usable contact or location detail before daily work is ready.',
                'href' => 'settings.php?tab=company',
                'cta_label' => 'Complete profile',
                'status_label' => 'Needs company profile first',
            ],
            'product_catalog' => [
                'label' => 'Add products and pricing',
                'reason' => 'Settings need at least one product or service with target customers and pricing before Clarity can guide revenue work.',
                'href' => 'settings.php?tab=products',
                'cta_label' => 'Add products',
                'status_label' => 'Needs products and pricing first',
            ],
            'customer_list' => [
                'label' => 'Add a customer',
                'reason' => 'Customer replies, follow-ups, and invoices work better once at least one customer is saved.',
                'href' => 'contacts_create.php',
                'cta_label' => 'Add customer',
                'status_label' => 'Ready after one customer is added',
            ],
            'channel' => [
                'label' => 'Connect email or WhatsApp',
                'reason' => 'Customer replies can be drafted after a channel is connected.',
                'href' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
                'cta_label' => 'Connect channel',
                'status_label' => 'Blocked until a channel is connected',
            ],
            'money' => [
                'label' => 'Set up invoices',
                'reason' => 'Invoice terms, currency, and payment details are needed before invoices are ready for daily work.',
                'href' => 'settings.php?tab=invoicing',
                'cta_label' => 'Set up invoices',
                'status_label' => 'Needs invoice settings first',
            ],
            'document_identity' => [
                'label' => 'Complete document identity',
                'reason' => 'Company Profile needs the legal name, billing email, and address used on invoices and finance reports.',
                'href' => 'settings.php?tab=company',
                'cta_label' => 'Complete profile',
                'status_label' => 'Needs company identity first',
            ],
            'ai_context' => [
                'label' => 'Review business setup',
                'reason' => 'Clarity can guide better once the business setup has been reviewed.',
                'href' => 'onboarding.php?step=5',
                'cta_label' => 'Review setup',
                'status_label' => 'AI needs the business setup first',
            ],
            'safe_automation' => [
                'label' => 'Choose how much approval AI needs',
                'reason' => 'Approval rules tell Clarity when to draft, suggest, or wait for you.',
                'href' => 'onboarding.php?step=4',
                'cta_label' => 'Choose approval level',
                'status_label' => 'Needs your approval rules first',
            ],
            'first_login' => [
                'label' => 'Sign in to start setup',
                'reason' => 'The first setup signal appears after the owner signs in.',
                'status_label' => 'Waiting for first login',
            ],
            'first_inbound' => [
                'label' => 'Process the first customer message',
                'reason' => 'The system learns the communication loop after the first inbound message is handled.',
                'href' => 'inbox.php',
                'cta_label' => 'Open inbox',
                'status_label' => 'Waiting for first customer message',
            ],
            'first_followup' => [
                'label' => 'Finish a follow-up',
                'reason' => 'A completed follow-up proves the daily task loop is working.',
                'href' => 'tasks.php',
                'cta_label' => 'Open tasks',
                'status_label' => 'Waiting for first follow-up',
            ],
            'first_deal' => [
                'label' => 'Create a job or deal',
                'reason' => 'A saved job or deal connects customer work to revenue tracking.',
                'href' => 'deals.php',
                'cta_label' => 'Open deals',
                'status_label' => 'Waiting for first job or deal',
            ],
        ];

        $gap = $gaps[$key] ?? [
            'label' => 'Finish setup',
            'reason' => 'Finish the next setup item so daily work is easier to guide.',
            'status_label' => 'Setup item needs attention',
        ];
        $gap['key'] = $key !== '' ? $key : 'setup_gap';
        $gap['category'] = 'setup';

        return $this->withoutEmptyAction($gap);
    }

    /**
     * @return array<string,mixed>
     */
    private function milestone(string $key, string $label, bool $complete): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
        ];
    }

    private function contactCount(int $workspaceId): int
    {
        if (!Database::tableExists('contacts')) {
            return 0;
        }

        $conditions = [];
        $params = [];
        if ($workspaceId > 0 && Database::columnExists('contacts', 'workspace_id')) {
            $conditions[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        }
        $where = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

        try {
            $row = Database::queryOne('SELECT COUNT(*) AS count FROM contacts' . $where, $params) ?: [];
            return (int) ($row['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $gap
     * @return array<string,mixed>
     */
    private function withoutEmptyAction(array $gap): array
    {
        foreach (['href', 'cta_label'] as $key) {
            if (trim((string) ($gap[$key] ?? '')) === '') {
                unset($gap[$key]);
            }
        }

        return $gap;
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === UIExperienceService::MODE_ADVANCED
            ? UIExperienceService::MODE_ADVANCED
            : UIExperienceService::MODE_BEGINNER;
    }
}
