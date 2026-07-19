<?php

namespace CRM\Services;

use CRM\Modules\Marketing;

class MarketingLaunchPacketService
{
    private const SETUP_READY_SCORE = 35;

    private Marketing $marketing;

    public function __construct(Marketing $marketing)
    {
        $this->marketing = $marketing;
    }

    /**
     * @return array{
     *     status:string,
     *     score:int,
     *     primary_action_label:string,
     *     primary_action_url:string,
     *     packet_url:?string,
     *     packet_post_id:?int,
     *     proof_url:?string,
     *     results_url:string,
     *     needs_manual_proof:bool,
     *     completion_url:?string,
     *     first_missing_step:?string,
     *     missing:array<int,array<string,string>>,
     *     evidence:array<int,array<string,string>>,
     *     guardrails:array<string,bool>
     * }
     */
    public function build(array $onboardingStatus = [], array $summary = []): array
    {
        if ($onboardingStatus === []) {
            $onboardingStatus = $this->marketing->getMarketingOnboardingStatus();
        }

        $counts = (array) ($summary['counts'] ?? []);
        $setupScore = $this->bounded((int) ($onboardingStatus['readiness_score'] ?? $onboardingStatus['score'] ?? 0));
        $segments = $this->marketing->listAudienceSegments(['exclude_archived' => true], 50, 0);
        $briefs = $this->marketing->listCampaignBriefs(['status_open' => true], 50, 0);
        $contentItems = $this->marketing->listContentItems(['exclude_status' => 'archived'], 50, 0);
        $landingPages = array_values(array_filter(
            $this->marketing->listLandingPages([], 50, 0),
            static fn(array $page): bool => (string) ($page['status'] ?? '') !== 'archived'
        ));
        $distributionPosts = array_values(array_filter(
            $this->marketing->listDistributionPosts([], 50, 0),
            static fn(array $post): bool => in_array((string) ($post['status'] ?? ''), ['draft', 'scheduled', 'exported', 'published'], true)
        ));
        $packetPost = $this->firstPacketPost($distributionPosts);
        $packetPostId = $packetPost ? (int) ($packetPost['id'] ?? 0) : null;
        $packetUrl = $packetPostId !== null ? 'marketing_distribution_bundle.php?id=' . $packetPostId : null;
        $proofUrl = $packetPostId !== null ? 'marketing_launch_proof.php?id=' . $packetPostId : null;
        $resultsUrl = 'marketing_performance.php';
        $publishedEvidence = $this->hasPublishedEvidence($distributionPosts, $counts);
        $needsManualProof = $packetPost !== null && !$this->postHasPublishedProof($packetPost);

        $steps = [
            'setup' => [
                'label' => 'Setup',
                'href' => 'marketing_onboarding.php',
                'ready' => $setupScore >= self::SETUP_READY_SCORE,
                'missing' => 'Add brand, customer, offer, or proof context.',
                'evidence' => 'Marketing setup is ' . $setupScore . '% ready.',
            ],
            'audiences' => [
                'label' => 'Audiences',
                'href' => 'marketing_segments.php',
                'ready' => $segments !== [],
                'missing' => 'Create or activate one audience segment.',
                'evidence' => count($segments) . ' audience segment' . (count($segments) === 1 ? '' : 's') . ' ready.',
            ],
            'campaigns' => [
                'label' => 'Campaigns',
                'href' => 'marketing_briefs.php',
                'ready' => $briefs !== [],
                'missing' => 'Create one draft or active campaign brief.',
                'evidence' => count($briefs) . ' campaign brief' . (count($briefs) === 1 ? '' : 's') . ' ready.',
            ],
            'content' => [
                'label' => 'Content',
                'href' => 'marketing_content.php',
                'ready' => $contentItems !== [],
                'missing' => 'Prepare one message or content item.',
                'evidence' => $this->contentEvidence($contentItems),
            ],
            'landing' => [
                'label' => 'Landing Pages',
                'href' => 'marketing_landing_pages.php',
                'ready' => $landingPages !== [],
                'missing' => 'Create one campaign destination or landing page.',
                'evidence' => count($landingPages) . ' landing page' . (count($landingPages) === 1 ? '' : 's') . ' ready.',
            ],
            'send' => [
                'label' => 'Send / Export',
                'href' => 'marketing_distribution.php',
                'ready' => $packetPost !== null,
                'missing' => 'Create one manual distribution post.',
                'evidence' => $packetPost
                    ? 'Manual launch packet is available for ' . (string) ($packetPost['content_title'] ?? 'the selected content') . '.'
                    : '',
            ],
        ];

        $missing = [];
        $evidence = [];
        foreach ($steps as $key => $step) {
            if (!empty($step['ready'])) {
                $evidence[] = [
                    'key' => (string) $key,
                    'label' => (string) $step['label'],
                    'href' => (string) $step['href'],
                    'detail' => (string) $step['evidence'],
                ];
                continue;
            }

            $missing[] = [
                'key' => (string) $key,
                'label' => (string) $step['label'],
                'href' => (string) $step['href'],
                'detail' => (string) $step['missing'],
            ];
        }

        if ($publishedEvidence) {
            $evidence[] = [
                'key' => 'results',
                'label' => 'Results',
                'href' => 'marketing_performance.php',
                'detail' => 'Published, tracking, handoff, or attribution evidence is present.',
            ];
        }

        $readyCount = count($evidence) - ($publishedEvidence ? 1 : 0);
        $score = (int) round(($readyCount / max(1, count($steps))) * 100);
        $status = $this->status($missing, $evidence, $publishedEvidence);
        $completionUrl = $missing !== []
            ? 'marketing_launch_packet.php?step=' . urlencode((string) ($missing[0]['key'] ?? 'setup'))
            : null;
        $primaryAction = $this->primaryAction($missing, $packetUrl, $status, $completionUrl, $resultsUrl);

        return [
            'status' => $status,
            'score' => $score,
            'primary_action_label' => $primaryAction['label'],
            'primary_action_url' => $primaryAction['href'],
            'packet_url' => $packetUrl,
            'packet_post_id' => $packetPostId,
            'proof_url' => $proofUrl,
            'results_url' => $resultsUrl,
            'needs_manual_proof' => $needsManualProof,
            'completion_url' => $completionUrl,
            'first_missing_step' => $missing[0]['key'] ?? null,
            'missing' => $missing,
            'evidence' => $evidence,
            'guardrails' => [
                'manual_first' => true,
                'external_send' => false,
                'external_publish' => false,
                'external_api_execution' => false,
                'derived_only' => true,
                'migration_required' => false,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $posts
     */
    private function firstPacketPost(array $posts): ?array
    {
        $priority = ['published' => 0, 'exported' => 1, 'scheduled' => 2, 'draft' => 3];
        usort($posts, static function (array $left, array $right) use ($priority): int {
            $leftStatus = (string) ($left['status'] ?? 'draft');
            $rightStatus = (string) ($right['status'] ?? 'draft');
            $leftRank = $priority[$leftStatus] ?? 9;
            $rightRank = $priority[$rightStatus] ?? 9;
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return $posts[0] ?? null;
    }

    /**
     * @param array<int,array<string,mixed>> $posts
     * @param array<string,mixed> $counts
     */
    private function hasPublishedEvidence(array $posts, array $counts): bool
    {
        foreach ($posts as $post) {
            if ($this->postHasPublishedProof($post)) {
                return true;
            }
        }

        foreach (['landing_page_views', 'conversion_events', 'lead_handoffs_open', 'lead_handoffs_converted', 'attribution_touchpoints'] as $key) {
            if ((int) ($counts[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function postHasPublishedProof(array $post): bool
    {
        return (string) ($post['status'] ?? '') === 'published'
            || trim((string) ($post['published_url'] ?? '')) !== ''
            || trim((string) ($post['published_at'] ?? '')) !== '';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function contentEvidence(array $items): string
    {
        $preferred = count(array_filter($items, static function (array $item): bool {
            return in_array((string) ($item['status'] ?? ''), ['approved', 'scheduled', 'published'], true)
                || in_array((string) ($item['production_stage'] ?? ''), ['approved', 'scheduled', 'published'], true);
        }));

        $count = count($items);
        $suffix = $count === 1 ? '' : 's';
        if ($preferred > 0) {
            return $count . ' content item' . $suffix . ', ' . $preferred . ' approved/scheduled/published.';
        }

        return $count . ' content item' . $suffix . ' ready for review.';
    }

    /**
     * @param array<int,array<string,string>> $missing
     * @param array<int,array<string,string>> $evidence
     */
    private function status(array $missing, array $evidence, bool $publishedEvidence): string
    {
        if ($missing === [] && $publishedEvidence) {
            return 'published_evidence';
        }
        if ($missing === []) {
            return 'ready';
        }
        if ($evidence === []) {
            return 'not_started';
        }

        return 'building';
    }

    /**
     * @param array<int,array<string,string>> $missing
     * @return array{label:string,href:string}
     */
    private function primaryAction(array $missing, ?string $packetUrl, string $status, ?string $completionUrl, string $resultsUrl): array
    {
        if ($status === 'published_evidence') {
            return ['label' => 'View first results', 'href' => $resultsUrl];
        }
        if ($packetUrl !== null && in_array($status, ['ready', 'published_evidence'], true)) {
            return ['label' => 'Open launch packet', 'href' => $packetUrl];
        }
        if ($missing !== []) {
            $first = $missing[0];
            return [
                'label' => 'Complete ' . (string) ($first['label'] ?? 'next step'),
                'href' => $completionUrl ?? (string) ($first['href'] ?? 'marketing_onboarding.php'),
            ];
        }
        if ($packetUrl !== null) {
            return ['label' => 'Open launch packet', 'href' => $packetUrl];
        }

        return ['label' => 'Open Send / Export', 'href' => 'marketing_distribution.php'];
    }

    private function bounded(int $score): int
    {
        return max(0, min(100, $score));
    }
}
