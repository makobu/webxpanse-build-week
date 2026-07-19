<?php

namespace CRM\Services;

use CRM\Modules\Marketing;
use InvalidArgumentException;

class MarketingLaunchPacketCompletionService
{
    private const STEPS = [
        'setup' => [
            'label' => 'Setup',
            'title' => 'Create launch foundation',
            'description' => 'Capture the minimum brand, customer, offer, proof, and CTA context.',
            'action' => 'create_foundation',
            'submit_label' => 'Save foundation',
            'detail_url' => 'marketing_onboarding.php',
        ],
        'audiences' => [
            'label' => 'Audiences',
            'title' => 'Create first audience',
            'description' => 'Define one active segment the campaign can target.',
            'action' => 'create_audience',
            'submit_label' => 'Save audience',
            'detail_url' => 'marketing_segment_edit.php',
        ],
        'campaigns' => [
            'label' => 'Campaigns',
            'title' => 'Create campaign plan',
            'description' => 'Turn the audience, offer, and message into one active brief.',
            'action' => 'create_campaign',
            'submit_label' => 'Save campaign plan',
            'detail_url' => 'marketing_brief_edit.php',
        ],
        'content' => [
            'label' => 'Content',
            'title' => 'Create launch message',
            'description' => 'Prepare one approved manual-ready content item.',
            'action' => 'create_content',
            'submit_label' => 'Save content',
            'detail_url' => 'marketing_content_edit.php',
        ],
        'landing' => [
            'label' => 'Landing Pages',
            'title' => 'Create campaign destination',
            'description' => 'Prepare one approved landing page plan without publishing it.',
            'action' => 'create_landing',
            'submit_label' => 'Save landing page',
            'detail_url' => 'marketing_landing_page_edit.php',
        ],
        'send' => [
            'label' => 'Send / Export',
            'title' => 'Create manual distribution packet',
            'description' => 'Create one draft distribution post that becomes the launch packet.',
            'action' => 'create_distribution',
            'submit_label' => 'Create packet',
            'detail_url' => 'marketing_distribution.php#distribution-tools',
        ],
    ];

    private Marketing $marketing;
    private MarketingLaunchPacketService $packetService;

    public function __construct(Marketing $marketing, ?MarketingLaunchPacketService $packetService = null)
    {
        $this->marketing = $marketing;
        $this->packetService = $packetService ?? new MarketingLaunchPacketService($marketing);
    }

    /**
     * @return array<string,mixed>
     */
    public function build(array $onboardingStatus = [], array $summary = [], string $requestedStep = ''): array
    {
        $packet = $this->packetService->build($onboardingStatus, $summary);
        $missingKeys = array_fill_keys(array_column((array) ($packet['missing'] ?? []), 'key'), true);
        $evidenceKeys = array_fill_keys(array_column((array) ($packet['evidence'] ?? []), 'key'), true);
        $firstMissing = (string) ($packet['first_missing_step'] ?? array_key_first($missingKeys) ?? '');
        $activeStep = $this->activeStep($requestedStep, $missingKeys, $firstMissing);

        return [
            'packet' => $packet,
            'first_missing_step' => $firstMissing !== '' ? $firstMissing : null,
            'active_step' => $activeStep,
            'steps' => $this->steps($missingKeys, $evidenceKeys, $activeStep),
            'form' => $activeStep !== null ? $this->formConfig($activeStep) : null,
            'guardrails' => (array) ($packet['guardrails'] ?? []),
        ];
    }

    /**
     * @return array{created_type:string,id:int,redirect_url:string,packet:array<string,mixed>}
     */
    public function create(string $action, array $input, int $userId): array
    {
        $action = trim($action);
        $id = match ($action) {
            'create_foundation' => $this->createFoundation($input, $userId),
            'create_audience' => $this->createAudience($input, $userId),
            'create_campaign' => $this->createCampaign($input, $userId),
            'create_content' => $this->createContent($input, $userId),
            'create_landing' => $this->createLanding($input, $userId),
            'create_distribution' => $this->createDistribution($input, $userId),
            default => throw new InvalidArgumentException('Unknown launch packet completion action.'),
        };

        $packet = $this->packetService->build();
        $redirect = $action === 'create_distribution'
            ? 'marketing_distribution_bundle.php?id=' . $id
            : 'marketing_launch_packet.php';

        return [
            'created_type' => str_replace('create_', '', $action),
            'id' => $id,
            'redirect_url' => $redirect,
            'packet' => $packet,
        ];
    }

    /**
     * @param array<string,bool> $missingKeys
     */
    private function activeStep(string $requestedStep, array $missingKeys, string $firstMissing): ?string
    {
        $requestedStep = trim($requestedStep);
        if ($requestedStep !== '' && isset(self::STEPS[$requestedStep]) && isset($missingKeys[$requestedStep])) {
            return $requestedStep;
        }
        if ($firstMissing !== '' && isset(self::STEPS[$firstMissing])) {
            return $firstMissing;
        }

        return null;
    }

    /**
     * @param array<string,bool> $missingKeys
     * @param array<string,bool> $evidenceKeys
     * @return array<int,array<string,string>>
     */
    private function steps(array $missingKeys, array $evidenceKeys, ?string $activeStep): array
    {
        $steps = [];
        foreach (self::STEPS as $key => $definition) {
            $status = isset($evidenceKeys[$key]) ? 'ready' : (isset($missingKeys[$key]) ? 'missing' : 'pending');
            if ($activeStep === $key) {
                $status = 'active';
            }
            $steps[] = [
                'key' => $key,
                'label' => $definition['label'],
                'status' => $status,
                'href' => 'marketing_launch_packet.php?step=' . urlencode($key),
                'detail_url' => $definition['detail_url'],
            ];
        }

        return $steps;
    }

    /**
     * @return array<string,mixed>
     */
    private function formConfig(string $step): array
    {
        $definition = self::STEPS[$step];
        return [
            'step' => $step,
            'title' => $definition['title'],
            'description' => $definition['description'],
            'action' => $definition['action'],
            'submit_label' => $definition['submit_label'],
            'detail_url' => $definition['detail_url'],
            'fields' => match ($step) {
                'setup' => $this->foundationFields(),
                'audiences' => $this->audienceFields(),
                'campaigns' => $this->campaignFields(),
                'content' => $this->contentFields(),
                'landing' => $this->landingFields(),
                'send' => $this->distributionFields(),
                default => [],
            },
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function foundationFields(): array
    {
        return [
            $this->field('brand_name', 'Brand name', 'text', true, 'Launch Brand'),
            $this->field('customer_name', 'Primary customer', 'text', true, 'Founder-led growth buyer'),
            $this->field('offer', 'Offer', 'textarea', true, 'Manual launch planning session'),
            $this->field('proof_point', 'Proof point', 'textarea', true, 'Campaign, content, landing, and CRM follow-up stay connected.'),
            $this->field('cta', 'Default CTA', 'text', true, 'Book a planning call'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function audienceFields(): array
    {
        return [
            $this->field('name', 'Audience name', 'text', true, 'Qualified launch audience'),
            $this->field('description', 'Audience note', 'textarea', false, 'People most likely to respond to the first manual launch.'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function campaignFields(): array
    {
        $segment = $this->firstSegment();
        return [
            $this->field('title', 'Campaign name', 'text', true, 'First manual launch campaign'),
            $this->field('objective', 'Goal', 'textarea', true, 'Prepare one campaign that can be manually launched and tracked.'),
            $this->field('key_message', 'Main message', 'textarea', true, 'A clear offer with a practical next step.'),
            $this->field('offer_text', 'Offer', 'textarea', true, 'Manual launch planning session'),
            $this->field('audience', 'Audience', 'text', false, (string) ($segment['name'] ?? 'Qualified launch audience')),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function contentFields(): array
    {
        return [
            $this->field('title', 'Content title', 'text', true, 'Manual launch announcement'),
            $this->field('channel', 'Channel', 'select', true, 'email', $this->channelOptions()),
            $this->field('content_type', 'Format', 'select', true, 'email', $this->contentTypeOptions()),
            $this->field('objective', 'Content goal', 'textarea', true, 'Invite the audience to take the next manual step.'),
            $this->field('draft_body', 'Draft copy', 'textarea', true, "Hi,\n\nWe put together a practical way to plan the next campaign without automatic publishing.\n\nBook a planning call if you want help turning it into a launch packet."),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function landingFields(): array
    {
        return [
            $this->field('title', 'Page title', 'text', true, 'Manual Launch Planning'),
            $this->field('headline', 'Headline', 'text', true, 'Plan a campaign you can launch manually with confidence.'),
            $this->field('body_sections', 'Page sections', 'textarea', true, "Why it matters\nKeep the audience, message, page, and follow-up connected before anything goes live.\n\nWhat happens next\nReview the launch packet, publish manually, then record proof."),
            $this->field('cta_text', 'CTA', 'text', true, 'Book a planning call'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function distributionFields(): array
    {
        return [
            $this->field('content_item_id', 'Source content', 'select', true, (string) ($this->firstContent()['id'] ?? ''), $this->contentOptions()),
            $this->field('channel', 'Manual channel', 'select', true, 'email', $this->channelOptions()),
            $this->field('planned_copy', 'Packet copy', 'textarea', false, ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function field(string $name, string $label, string $type, bool $required, string $value = '', array $options = []): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'value' => $value,
            'options' => $options,
        ];
    }

    private function createFoundation(array $input, int $userId): int
    {
        $metadata = $this->metadata();
        $brandName = $this->text($input, 'brand_name', 'Launch Brand');
        $customer = $this->text($input, 'customer_name', 'Founder-led growth buyer');
        $offer = $this->text($input, 'offer', 'Manual launch planning session');
        $proof = $this->text($input, 'proof_point', 'Campaign, content, landing, and CRM follow-up stay connected.');
        $cta = $this->text($input, 'cta', 'Book a planning call');

        $brandId = $this->marketing->createBrandProfile([
            'name' => $brandName,
            'voice' => 'Clear, practical, founder-friendly.',
            'tone' => 'Helpful and direct',
            'value_props' => $offer,
            'proof_points' => $proof,
            'cta_defaults' => $cta,
            'is_default' => 1,
            'metadata_json' => $metadata,
            'created_by' => $userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => $customer,
            'segment' => $customer,
            'pains' => 'Marketing feels too complex to launch confidently.',
            'goals' => 'Complete one manual launch packet and learn from it.',
            'objections' => 'Does not want automatic publishing or hidden execution.',
            'preferred_channels' => ['email', 'linkedin', 'website'],
            'metadata_json' => $metadata,
            'created_by' => $userId,
        ]);

        foreach ([
            ['offer', 'Launch Offer', $offer],
            ['proof_point', 'Launch Proof', $proof],
            ['content_pillar', 'Manual Launch Discipline', 'Teach the audience how to move from campaign idea to manual launch packet.'],
            ['default_cta', 'Default Launch CTA', $cta],
        ] as [$type, $title, $body]) {
            $this->marketing->createContextItem([
                'item_type' => $type,
                'title' => $title,
                'body' => $body,
                'channel' => 'website',
                'persona_id' => $personaId,
                'status' => 'active',
                'metadata_json' => $metadata,
                'created_by' => $userId,
            ]);
        }

        return $brandId;
    }

    private function createAudience(array $input, int $userId): int
    {
        return $this->marketing->createAudienceSegment([
            'name' => $this->text($input, 'name', 'Qualified launch audience'),
            'description' => $this->text($input, 'description', 'People most likely to respond to the first manual launch.'),
            'status' => 'active',
            'source_scope' => 'contacts',
            'rule_logic' => 'all',
            'rules' => [],
            'metadata_json' => $this->metadata(),
            'created_by' => $userId,
        ]);
    }

    private function createCampaign(array $input, int $userId): int
    {
        $segment = $this->firstSegment();
        return $this->marketing->createCampaignBrief([
            'title' => $this->text($input, 'title', 'First manual launch campaign'),
            'objective' => $this->text($input, 'objective', 'Prepare one campaign that can be manually launched and tracked.'),
            'audience' => $this->text($input, 'audience', (string) ($segment['name'] ?? 'Qualified launch audience')),
            'audience_segment_id' => (int) ($segment['id'] ?? 0) ?: null,
            'offer_text' => $this->text($input, 'offer_text', 'Manual launch planning session'),
            'key_message' => $this->text($input, 'key_message', 'A clear offer with a practical next step.'),
            'channels' => ['email', 'website'],
            'status' => 'active',
            'owner_user_id' => $userId,
            'metadata_json' => $this->metadata(),
            'created_by' => $userId,
        ]);
    }

    private function createContent(array $input, int $userId): int
    {
        $brief = $this->firstBrief();
        $landing = $this->firstLanding();
        return $this->marketing->createContentItem([
            'title' => $this->text($input, 'title', 'Manual launch announcement'),
            'content_type' => $this->enum($input, 'content_type', Marketing::CONTENT_TYPES, 'email'),
            'channel' => $this->enum($input, 'channel', Marketing::CHANNELS, 'email'),
            'status' => 'approved',
            'production_stage' => 'approved',
            'objective' => $this->text($input, 'objective', 'Invite the audience to take the next manual step.'),
            'target_audience' => (string) ($brief['audience'] ?? $brief['audience_segment_name'] ?? 'Qualified launch audience'),
            'campaign_brief_id' => (int) ($brief['id'] ?? 0) ?: null,
            'landing_page_id' => (int) ($landing['id'] ?? 0) ?: null,
            'draft_body' => $this->text($input, 'draft_body', 'Manual launch copy for the first packet.'),
            'approval_checklist' => ['Founder reviewed', 'Manual publish only', 'Proof will be recorded after publishing'],
            'metadata_json' => $this->metadata(),
            'owner_user_id' => $userId,
            'created_by' => $userId,
        ]);
    }

    private function createLanding(array $input, int $userId): int
    {
        $segment = $this->firstSegment();
        return $this->marketing->createLandingPage([
            'title' => $this->text($input, 'title', 'Manual Launch Planning'),
            'headline' => $this->text($input, 'headline', 'Plan a campaign you can launch manually with confidence.'),
            'body_sections' => $this->text($input, 'body_sections', 'Review the launch packet, publish manually, then record proof.'),
            'cta_blocks' => $this->text($input, 'cta_text', 'Book a planning call'),
            'conversion_goal' => 'demo_request',
            'audience_segment_id' => (int) ($segment['id'] ?? 0) ?: null,
            'status' => 'approved',
            'builder_status' => 'ready',
            'metadata_json' => $this->metadata(),
            'created_by' => $userId,
        ]);
    }

    private function createDistribution(array $input, int $userId): int
    {
        $contentId = (int) ($input['content_item_id'] ?? 0);
        if ($contentId <= 0) {
            $contentId = (int) ($this->firstContent()['id'] ?? 0);
        }
        if ($contentId <= 0) {
            throw new InvalidArgumentException('Source content is required before creating a packet.');
        }

        return $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => $this->enum($input, 'channel', Marketing::CHANNELS, 'email'),
            'planned_copy' => $this->text($input, 'planned_copy', ''),
            'publishing_checklist' => ['Review copy', 'Publish manually outside CRM', 'Record published proof'],
            'required_fields' => ['copy', 'destination_url', 'published_url_after_manual_publish'],
            'asset_rules' => ['Use approved media only when available'],
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function firstSegment(): array
    {
        return $this->marketing->listAudienceSegments(['status' => 'active'], 1, 0)[0]
            ?? $this->marketing->listAudienceSegments(['exclude_archived' => true], 1, 0)[0]
            ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstBrief(): array
    {
        return $this->marketing->listCampaignBriefs(['status_open' => true], 1, 0)[0] ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstLanding(): array
    {
        $pages = array_values(array_filter(
            $this->marketing->listLandingPages([], 20, 0),
            static fn(array $page): bool => (string) ($page['status'] ?? '') !== 'archived'
        ));

        return $pages[0] ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstContent(): array
    {
        return $this->marketing->listContentItems(['exclude_status' => 'archived'], 1, 0)[0] ?? [];
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function contentOptions(): array
    {
        return array_values(array_map(
            static fn(array $item): array => ['label' => (string) ($item['title'] ?? 'Content item'), 'value' => (string) ($item['id'] ?? '')],
            $this->marketing->listContentItems(['exclude_status' => 'archived'], 50, 0)
        ));
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function channelOptions(): array
    {
        return array_map(static fn(string $value): array => ['label' => ucwords(str_replace('_', ' ', $value)), 'value' => $value], Marketing::CHANNELS);
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function contentTypeOptions(): array
    {
        return array_map(static fn(string $value): array => ['label' => ucwords(str_replace('_', ' ', $value)), 'value' => $value], Marketing::CONTENT_TYPES);
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(): array
    {
        return [
            'source' => 'marketing_launch_packet_completion',
            'manual_first' => true,
            'external_send' => false,
            'external_publish' => false,
            'created_at' => date('c'),
        ];
    }

    private function text(array $input, string $key, string $fallback): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param string[] $allowed
     */
    private function enum(array $input, string $key, array $allowed, string $fallback): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
