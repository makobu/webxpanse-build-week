<?php

namespace CRM\Services;

class MarketingStageContextService
{
    /**
     * @return array{summary:array<string,mixed>,stages:array<int,array<string,mixed>>,today_actions:array<int,array<string,mixed>>,advanced_tools:array<int,array<string,string>>,advanced_tool_groups:array<int,array{label:string,feature:string,items:array<int,array<string,string>>}>}
     */
    public function build(array $summary, array $onboardingStatus = [], array $nextBestActions = []): array
    {
        $counts = (array) ($summary['counts'] ?? []);
        $workflowReadiness = (array) ($summary['workflow_readiness'] ?? []);
        $lanes = (array) ($workflowReadiness['lanes'] ?? []);

        $stageScores = $this->scores($counts, $onboardingStatus, $lanes);
        $stages = [
            $this->stage('setup', 'Setup', 'Marketing Setup', 'Give marketing the brand, customer, and offer basics it needs.', 'marketing_onboarding.php', $stageScores['setup'], '', [
                'Brand profile', 'Customer context', 'Offer or proof point',
            ], [
                ['label' => 'Marketing Setup', 'href' => 'marketing_onboarding.php'],
                ['label' => 'Context Hub', 'href' => 'marketing_context.php'],
                ['label' => 'Brand Library', 'href' => 'marketing_brand.php'],
            ], 'fa-user-check'),
            $this->stage('audiences', 'Audiences', 'Audience Builder', 'Choose who the campaign is for before writing the plan.', 'marketing_segments.php', $stageScores['audiences'], 'Finish setup before choosing a campaign audience.', [
                'Customer context', 'Audience segment', 'Contact preview or snapshot',
            ], [
                ['label' => 'Segments', 'href' => 'marketing_segments.php'],
                ['label' => 'Audience Activation', 'href' => 'marketing_audience_activation.php'],
                ['label' => 'Personas', 'href' => 'marketing_personas.php'],
            ], 'fa-users-viewfinder'),
            $this->stage('campaigns', 'Campaigns', 'Campaign Briefs', 'Turn the audience, offer, and goal into one simple campaign plan.', 'marketing_briefs.php', $stageScores['campaigns'], 'Choose or create an audience first.', [
                'Audience segment', 'Campaign brief', 'Offer or success goal',
            ], [
                ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php'],
                ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php'],
                ['label' => 'Playbooks', 'href' => 'marketing_playbooks.php'],
            ], 'fa-clipboard-list'),
            $this->stage('content', 'Content', 'Messages & Content', 'Create the message or content item the campaign will use.', 'marketing_content.php', $stageScores['content'], 'Create a campaign plan or brief first.', [
                'Campaign brief', 'Draft content item', 'Review or approval signal',
            ], [
                ['label' => 'Content Studio', 'href' => 'marketing_content.php'],
                ['label' => 'New Content', 'href' => 'marketing_content_edit.php'],
                ['label' => 'Reviews', 'href' => 'marketing_reviews.php'],
            ], 'fa-pen-nib'),
            $this->stage('landing', 'Landing Pages', 'Campaign Destination', 'Prepare the page or destination where the campaign will send people.', 'marketing_landing_pages.php', $stageScores['landing'], 'Create campaign content before preparing a destination.', [
                'Campaign content', 'Landing page or destination', 'Preview or approval signal',
            ], [
                ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php'],
                ['label' => 'Assets', 'href' => 'marketing_assets.php'],
                ['label' => 'SEO Topics', 'href' => 'marketing_seo.php'],
            ], 'fa-window-maximize'),
            $this->stage('send', 'Send / Export', 'Manual Launch Package', 'Package the campaign for manual channels without sending or publishing automatically.', 'marketing_distribution.php', $stageScores['send'], 'Prepare content and a campaign destination first.', [
                'Approved or scheduled content', 'Distribution package', 'Manual publish proof or tracking',
            ], [
                ['label' => 'Distribution', 'href' => 'marketing_distribution.php'],
                ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php'],
                ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php'],
            ], 'fa-share-nodes'),
            $this->stage('results', 'Results', 'Measurement Loop', 'Use tracking, handoffs, and performance signals to improve the next campaign.', 'marketing_performance.php', $stageScores['results'], 'Send/export or tracking evidence is needed before learning is useful.', [
                'Published or exported evidence', 'Tracking signal', 'Handoff or performance signal',
            ], [
                ['label' => 'Performance', 'href' => 'marketing_performance.php'],
                ['label' => 'Lead Handoffs', 'href' => 'marketing_handoffs.php'],
                ['label' => 'Weekly Report', 'href' => 'marketing_weekly_report.php'],
            ], 'fa-chart-line'),
        ];
        foreach ($stages as $index => &$stage) {
            $stage['graph_position'] = $index + 1;
            $stage['visual_state'] = $this->visualState((string) ($stage['status'] ?? 'Locked'));
        }
        unset($stage);

        $todayActions = array_slice($this->proceduralActions((array) ($nextBestActions['actions'] ?? [])), 0, 5);
        if ($todayActions === []) {
            $todayActions[] = [
                'label' => 'Complete Marketing Setup',
                'href' => 'marketing_onboarding.php',
                'reason' => 'Start with customer, brand, and offer basics so the rest of the path can unlock.',
                'priority' => 'high',
            ];
        }

        $currentStage = $this->currentStage($stages);
        $readyCount = count(array_filter($stages, static fn(array $stage): bool => in_array($stage['status'], ['Ready', 'In use'], true)));
        $blockedCount = count(array_filter($stages, static fn(array $stage): bool => $stage['status'] === 'Locked'));

        $advancedToolGroups = $this->advancedToolGroups();
        $advancedTools = [];
        foreach ($advancedToolGroups as $group) {
            foreach ($group['items'] as $item) {
                $advancedTools[] = $item;
            }
        }

        return [
            'summary' => [
                'current_step' => (string) ($currentStage['founder_label'] ?? 'Setup'),
                'ready_stages' => $readyCount,
                'blocked_stages' => $blockedCount,
                'next_action' => (string) ($todayActions[0]['label'] ?? 'Complete Marketing Setup'),
                'next_action_url' => (string) ($todayActions[0]['href'] ?? 'marketing_onboarding.php'),
                'tooltips' => [
                    'current_step' => 'The first stage that still needs founder attention.',
                    'ready_stages' => 'Stages with enough evidence to open or continue.',
                    'blocked_stages' => 'Stages waiting on a prerequisite. They remain visible so the path is learnable.',
                    'next_action' => 'The next procedural action from the current Marketing queue.',
                ],
            ],
            'stages' => $stages,
            'today_actions' => $todayActions,
            'advanced_tools' => $advancedTools,
            'advanced_tool_groups' => $advancedToolGroups,
        ];
    }

    /**
     * @return array<int,array{label:string,feature:string,items:array<int,array<string,string>>}>
     */
    private function advancedToolGroups(): array
    {
        return [
            [
                'label' => 'Campaign Manager',
                'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
                'items' => [
                    ['label' => 'Marketing Assistants', 'href' => 'marketing_assistants.php', 'description' => 'Open the draft-only AI marketing workspace and its guided specialist commands.'],
                    ['label' => 'System Map', 'href' => 'marketing_system_map.php', 'description' => 'See the full marketing operating system.'],
                    ['label' => 'Action Router', 'href' => 'marketing_action_router.php', 'description' => 'Review routed marketing work and blockers.'],
                    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'description' => 'Inspect links between campaigns, content, and revenue signals.'],
                    ['label' => 'Execution Center', 'href' => 'marketing_execution.php', 'description' => 'Manage controlled manual execution work.'],
                    ['label' => 'Decision Center', 'href' => 'marketing_decisions.php', 'description' => 'Review strategic decisions and approvals.'],
                    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'description' => 'Control the launch decision with readiness evidence.'],
                    ['label' => 'Performance', 'href' => 'marketing_performance.php', 'description' => 'Review campaign performance and learning signals.'],
                ],
            ],
            [
                'label' => 'Social Media',
                'feature' => MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA,
                'items' => [
                    ['label' => 'Content Studio', 'href' => 'marketing_content.php', 'description' => 'Plan and review campaign messages and channel content.'],
                    ['label' => 'Marketing Calendar', 'href' => 'marketing_calendar.php', 'description' => 'See timing for planned and scheduled content.'],
                    ['label' => 'Reviews', 'href' => 'marketing_reviews.php', 'description' => 'Resolve content approvals and feedback before launch.'],
                    ['label' => 'Distribution', 'href' => 'marketing_distribution.php', 'description' => 'Package campaign content for manual channel sharing.'],
                    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'description' => 'Create channel-specific content packages.'],
                    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'description' => 'Prepare trackable links for channel performance.'],
                    ['label' => 'Email Runs', 'href' => 'marketing_email_runs.php', 'description' => 'Prepare controlled manual email campaign runs.'],
                ],
            ],
            [
                'label' => 'Design',
                'feature' => MarketingMarketplaceGateService::FEATURE_DESIGN,
                'items' => [
                    ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'description' => 'Prepare campaign destinations and previews.'],
                    ['label' => 'Creative Production', 'href' => 'marketing_creative.php', 'description' => 'Track visual work needed by campaigns.'],
                    ['label' => 'Assets', 'href' => 'marketing_assets.php', 'description' => 'Review media, files, and usage readiness.'],
                    ['label' => 'Brand Library', 'href' => 'marketing_brand.php', 'description' => 'Keep brand voice and positioning guidance ready.'],
                    ['label' => 'SEO Topics', 'href' => 'marketing_seo.php', 'description' => 'Capture search intent for pages and campaign copy.'],
                    ['label' => 'Forms', 'href' => 'forms.php', 'description' => 'Open campaign form and lead-capture surfaces.'],
                ],
            ],
        ];
    }

    /**
     * @param array<string,int|float|string> $counts
     * @param array<string,mixed> $onboardingStatus
     * @param array<int,array<string,mixed>> $lanes
     * @return array<string,array{score:int,status:string,locked_reason:string}>
     */
    private function scores(array $counts, array $onboardingStatus, array $lanes): array
    {
        $setupScore = $this->bounded((int) ($onboardingStatus['readiness_score'] ?? $onboardingStatus['score'] ?? 0));
        $laneScore = static function (string $key) use ($lanes): int {
            foreach ($lanes as $lane) {
                if ((string) ($lane['key'] ?? '') === $key) {
                    return max(0, min(100, (int) ($lane['score'] ?? 0)));
                }
            }
            return 0;
        };

        $audienceEvidence = $this->sum($counts, ['audience_segments', 'segments', 'audience_activations_ready', 'audience_activations_active']);
        $campaignEvidence = $this->sum($counts, ['active_campaigns', 'briefs_in_progress', 'campaign_briefs', 'campaign_workspaces_ready']);
        $contentEvidence = $this->sum($counts, ['drafts', 'in_review', 'scheduled', 'content_items', 'channel_exports_ready']);
        $landingEvidence = $this->sum($counts, ['landing_pages', 'landing_page_views', 'conversion_goals_active']);
        $channelEvidence = $this->sum($counts, ['distribution_queue', 'exported_posts', 'channel_exports_ready', 'email_runs_ready', 'utm_links']);
        $learningEvidence = $this->sum($counts, ['conversion_events', 'lead_handoffs_open', 'lead_handoffs_converted', 'attribution_touchpoints', 'landing_page_views']);

        return [
            'setup' => $this->state(max($setupScore, $laneScore('setup_context')), $setupScore > 0, true),
            'audiences' => $this->state($audienceEvidence > 0 ? min(100, 45 + ($audienceEvidence * 15)) : $laneScore('audience_journey'), $audienceEvidence > 0, $setupScore >= 35),
            'campaigns' => $this->state(max($laneScore('campaign_strategy'), $campaignEvidence > 0 ? min(100, 45 + ($campaignEvidence * 15)) : 0), $campaignEvidence > 0, $setupScore >= 35 && ($audienceEvidence > 0 || $laneScore('audience_journey') >= 50)),
            'content' => $this->state($contentEvidence > 0 ? min(100, 45 + ($contentEvidence * 12)) : $laneScore('content_review'), $contentEvidence > 0, $campaignEvidence > 0),
            'landing' => $this->state($landingEvidence > 0 ? min(100, 45 + ($landingEvidence * 12)) : $laneScore('landing_conversion'), $landingEvidence > 0, $contentEvidence > 0),
            'send' => $this->state($channelEvidence > 0 ? min(100, 40 + ($channelEvidence * 12)) : $laneScore('distribution_execution'), $channelEvidence > 0, $contentEvidence > 0 && ($landingEvidence > 0 || $laneScore('landing_conversion') >= 50)),
            'results' => $this->state($learningEvidence > 0 ? min(100, 45 + ($learningEvidence * 10)) : $laneScore('measurement_handoff'), $learningEvidence > 0, $channelEvidence > 0),
        ];
    }

    /**
     * @param array<int,array{label:string,href:string}> $advancedLinks
     * @param array<int,string> $requiredEvidence
     * @return array<string,mixed>
     */
    private function stage(
        string $key,
        string $founderLabel,
        string $expertLabel,
        string $description,
        string $primaryActionUrl,
        array $state,
        string $defaultLockedReason,
        array $requiredEvidence,
        array $advancedLinks,
        string $icon
    ): array {
        $status = (string) ($state['status'] ?? 'Locked');
        return [
            'key' => $key,
            'founder_label' => $founderLabel,
            'expert_label' => $expertLabel,
            'description' => $description,
            'status' => $status,
            'score' => (int) ($state['score'] ?? 0),
            'primary_action_url' => $status === 'Locked' ? $this->prerequisiteUrl($key) : $primaryActionUrl,
            'primary_action_label' => $status === 'Locked' ? 'Fix prerequisite' : $this->primaryActionLabel($status),
            'locked_reason' => $status === 'Locked' ? ((string) ($state['locked_reason'] ?? '') ?: $defaultLockedReason) : '',
            'required_evidence' => $requiredEvidence,
            'advanced_page_links' => $advancedLinks,
            'icon' => $icon,
            'tooltip' => $this->tooltip($expertLabel, $description, $status, (string) ($state['locked_reason'] ?? '') ?: $defaultLockedReason, $requiredEvidence),
        ];
    }

    /**
     * @param array<int,string> $requiredEvidence
     */
    private function tooltip(string $expertLabel, string $description, string $status, string $lockedReason, array $requiredEvidence): string
    {
        $parts = [
            $description,
            'Expert view: ' . $expertLabel . '.',
        ];
        if ($status === 'Locked' && trim($lockedReason) !== '') {
            $parts[] = 'Prerequisite: ' . $lockedReason;
        }
        if ($requiredEvidence !== []) {
            $parts[] = 'Evidence: ' . implode(', ', $requiredEvidence) . '.';
        }

        return implode(' ', $parts);
    }

    private function visualState(string $status): string
    {
        return match ($status) {
            'In use' => 'active',
            'Ready' => 'ready',
            'Setup needed' => 'attention',
            default => 'locked',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>
     */
    private function proceduralActions(array $actions): array
    {
        return array_values(array_filter($actions, function (array $action): bool {
            $source = strtolower((string) ($action['source'] ?? ''));
            $href = (string) ($action['href'] ?? '');

            return !str_contains($source, 'recommendation') && $this->isPrimaryPathHref($href);
        }));
    }

    private function isPrimaryPathHref(string $href): bool
    {
        $page = basename((string) (parse_url($href, PHP_URL_PATH) ?: $href));
        return in_array($page, [
            'marketing.php',
            'marketing_onboarding.php',
            'marketing_segments.php',
            'marketing_segment_edit.php',
            'marketing_segment_view.php',
            'marketing_briefs.php',
            'marketing_brief_edit.php',
            'marketing_brief_view.php',
            'marketing_content.php',
            'marketing_content_edit.php',
            'marketing_content_view.php',
            'marketing_landing_pages.php',
            'marketing_landing_page_edit.php',
            'marketing_landing_page_view.php',
            'marketing_landing_page_preview.php',
            'marketing_distribution.php',
            'marketing_distribution_bundle.php',
            'marketing_performance.php',
        ], true);
    }

    private function state(int $score, bool $inUse, bool $unlocked): array
    {
        $score = $this->bounded($score);
        if (!$unlocked) {
            return ['score' => $score, 'status' => 'Locked', 'locked_reason' => 'Complete the previous stage first.'];
        }
        if ($inUse) {
            return ['score' => max($score, 65), 'status' => 'In use', 'locked_reason' => ''];
        }
        if ($score >= 60) {
            return ['score' => $score, 'status' => 'Ready', 'locked_reason' => ''];
        }
        return ['score' => $score, 'status' => 'Setup needed', 'locked_reason' => ''];
    }

    private function prerequisiteUrl(string $key): string
    {
        return match ($key) {
            'audiences' => 'marketing_onboarding.php',
            'campaigns' => 'marketing_segments.php',
            'content' => 'marketing_briefs.php',
            'landing' => 'marketing_content.php',
            'send' => 'marketing_landing_pages.php',
            'results' => 'marketing_distribution.php',
            default => 'marketing_onboarding.php',
        };
    }

    private function primaryActionLabel(string $status): string
    {
        return match ($status) {
            'In use' => 'Continue',
            'Ready' => 'Open',
            default => 'Start',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $stages
     */
    private function currentStage(array $stages): array
    {
        foreach ($stages as $stage) {
            if (!in_array((string) ($stage['status'] ?? ''), ['Ready', 'In use'], true)) {
                return $stage;
            }
        }

        return $stages[count($stages) - 1] ?? [];
    }

    /**
     * @param array<string,int|float|string> $counts
     * @param string[] $keys
     */
    private function sum(array $counts, array $keys): int
    {
        $total = 0;
        foreach ($keys as $key) {
            $total += (int) ($counts[$key] ?? 0);
        }

        return $total;
    }

    private function bounded(int $score): int
    {
        return max(0, min(100, $score));
    }
}
