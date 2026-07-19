<?php

namespace CRM\Services;

use CRM\Modules\Marketing;
use InvalidArgumentException;

class MarketingLaunchProofService
{
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
    public function build(int $postId = 0, array $onboardingStatus = [], array $summary = []): array
    {
        $packet = $this->packetService->build($onboardingStatus, $summary);
        $post = $this->selectedPost($postId, $packet);
        $proofStatus = $this->proofStatus($packet, $post);

        return [
            'packet' => $packet,
            'distribution_post' => $post,
            'proof_status' => $proofStatus,
            'form' => $post !== null && $proofStatus !== 'published' ? $this->formConfig($post) : null,
            'first_results' => $this->firstResultsSummary($this->marketing->getPerformanceSummary()),
            'guardrails' => (array) ($packet['guardrails'] ?? []),
            'primary_action_label' => $this->primaryActionLabel($proofStatus),
            'primary_action_url' => $this->primaryActionUrl($proofStatus, $packet, $post),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function recordManualPublishProof(int $postId, array $input, int $userId): array
    {
        unset($userId);
        $post = $this->marketing->getDistributionPost($postId);
        if (!$post) {
            throw new InvalidArgumentException('Manual launch packet was not found.');
        }

        $this->marketing->markDistributionPostPublished(
            $postId,
            trim((string) ($input['published_url'] ?? '')),
            trim((string) ($input['published_at'] ?? '')) ?: null
        );

        $post = $this->marketing->getDistributionPost($postId) ?? [];
        $packet = $this->packetService->build();

        return [
            'distribution_post' => $post,
            'packet' => $packet,
            'proof_status' => 'published',
            'redirect_url' => 'marketing_launch_proof.php?id=' . $postId . '&success=proof',
        ];
    }

    /**
     * @param array<string,mixed> $packet
     * @return array<string,mixed>|null
     */
    private function selectedPost(int $postId, array $packet): ?array
    {
        if ($postId > 0) {
            return $this->marketing->getDistributionPost($postId);
        }

        $packetPostId = (int) ($packet['packet_post_id'] ?? 0);
        return $packetPostId > 0 ? $this->marketing->getDistributionPost($packetPostId) : null;
    }

    /**
     * @param array<string,mixed> $packet
     * @param array<string,mixed>|null $post
     */
    private function proofStatus(array $packet, ?array $post): string
    {
        if ($post === null) {
            return empty($packet['packet_url']) ? 'no_packet' : 'missing_packet';
        }
        if ($this->postHasPublishedProof($post)) {
            return 'published';
        }
        if (!empty($packet['missing'])) {
            return 'packet_incomplete';
        }

        return 'needs_proof';
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function formConfig(array $post): array
    {
        return [
            'action' => 'record_manual_publish_proof',
            'post_id' => (int) ($post['id'] ?? 0),
            'submit_label' => 'Record proof',
            'fields' => [
                [
                    'name' => 'published_url',
                    'label' => 'Published URL',
                    'type' => 'url',
                    'required' => true,
                    'value' => (string) ($post['published_url'] ?? ''),
                ],
                [
                    'name' => 'published_at',
                    'label' => 'Published at',
                    'type' => 'datetime-local',
                    'required' => false,
                    'value' => $this->dateTimeLocal((string) ($post['published_at'] ?? '')),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstResultsSummary(array $summary): array
    {
        $tracking = (array) ($summary['tracking'] ?? []);
        $attribution = (array) ($summary['attribution_pipeline'] ?? []);
        $handoffs = (array) ($summary['lead_handoffs'] ?? []);
        $loop = (array) ($summary['campaign_revenue_loop'] ?? []);
        $roi = $summary['campaign_roi']['roi_percent'] ?? null;

        return [
            'loop_score' => (int) ($loop['score'] ?? 0),
            'conversions' => (int) ($tracking['conversions'] ?? 0),
            'page_views' => (int) ($tracking['page_views'] ?? 0),
            'cta_clicks' => (int) ($tracking['cta_clicks'] ?? 0),
            'touchpoints' => (int) ($attribution['touchpoints'] ?? 0),
            'open_handoffs' => (int) ($handoffs['open'] ?? 0),
            'attributed_revenue' => (float) ($summary['revenue_attribution']['total_revenue'] ?? $attribution['influenced_revenue'] ?? 0),
            'roi_percent' => $roi === null ? null : (float) $roi,
            'results_url' => 'marketing_performance.php',
        ];
    }

    private function primaryActionLabel(string $proofStatus): string
    {
        return match ($proofStatus) {
            'published' => 'View first results',
            'no_packet', 'missing_packet' => 'Complete packet',
            default => 'Record proof',
        };
    }

    /**
     * @param array<string,mixed> $packet
     * @param array<string,mixed>|null $post
     */
    private function primaryActionUrl(string $proofStatus, array $packet, ?array $post): string
    {
        if ($proofStatus === 'published') {
            return 'marketing_performance.php';
        }
        if ($post !== null) {
            return 'marketing_launch_proof.php?id=' . (int) ($post['id'] ?? 0);
        }

        return (string) ($packet['completion_url'] ?? 'marketing_launch_packet.php');
    }

    private function postHasPublishedProof(array $post): bool
    {
        return (string) ($post['status'] ?? '') === 'published'
            || trim((string) ($post['published_url'] ?? '')) !== ''
            || trim((string) ($post['published_at'] ?? '')) !== '';
    }

    private function dateTimeLocal(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return str_replace(' ', 'T', substr($value, 0, 16));
    }
}
