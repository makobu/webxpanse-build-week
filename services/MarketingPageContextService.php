<?php

namespace CRM\Services;

class MarketingPageContextService
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function contexts(): array
    {
        $contexts = [
            'marketing.php' => self::context('setup', 'Choose what to do next in marketing', 'Marketing command center', 'Shows the founder path, today actions, and advanced tools without forcing expert navigation.', 'The next step is unclear or the first prerequisite is missing.', 'Open the first stage that is not ready.'),
            'marketing_onboarding.php' => self::context('setup', 'Give marketing the business basics', 'Marketing setup', 'Captures brand, customer, offer, proof, and starter context.', 'Brand, customer, or offer details are incomplete.', 'Complete the missing setup item.'),
            'marketing_context.php' => self::context('setup', 'Record the facts marketing should use', 'Context hub', 'Stores reusable claims, offers, proof, compliance notes, and positioning facts.', 'The system lacks reusable campaign facts.', 'Add one offer, proof point, or audience fact.'),
            'marketing_brand.php' => self::context('setup', 'Describe the brand clearly', 'Brand profile', 'Defines tone, positioning, and brand guidance for campaign work.', 'Brand voice or positioning is not specific enough.', 'Save the strongest brand profile detail.'),
            'marketing_personas.php' => self::context('audiences', 'Describe the customer', 'Persona library', 'Helps Clarity understand who the campaign is trying to persuade.', 'The audience is still too broad.', 'Add or refine one customer persona.'),
            'marketing_seo.php' => self::context('landing', 'Name what customers search for', 'SEO context', 'Keeps search terms and intent available for messaging and landing pages.', 'Search intent has not been translated into plain campaign language.', 'Add one search theme or customer question.'),
            'marketing_segments.php' => self::context('audiences', 'Choose who this campaign is for', 'Audience segment', 'Turns CRM/customer data into an audience the campaign can target.', 'No usable audience segment is selected.', 'Use this audience in a campaign plan.'),
            'marketing_segment_edit.php' => self::context('audiences', 'Define the audience rules', 'Audience segment editor', 'Builds or edits the audience definition for a campaign.', 'The segment rules may be incomplete.', 'Save the segment and return to campaign planning.'),
            'marketing_segment_view.php' => self::context('audiences', 'Check the chosen audience', 'Audience segment detail', 'Reviews the people and rules behind a campaign audience.', 'The segment may not be ready for activation.', 'Confirm the segment can support the campaign.'),
            'marketing_audience_activation.php' => self::context('audiences', 'Make the audience usable', 'Audience activation', 'Checks whether a chosen audience can be used safely in a campaign.', 'Audience coverage or permission evidence may be missing.', 'Resolve the first audience activation blocker.'),
            'marketing_campaign_workspace.php' => self::context('campaigns', 'Shape the campaign plan', 'Campaign workspace', 'Connects campaign, audience, content, destination, and readiness work.', 'One campaign component is not connected yet.', 'Open or create the campaign workspace item that is missing.'),
            'marketing_briefs.php' => self::context('campaigns', 'Pick the campaign brief', 'Campaign briefs', 'Lists the strategic briefs that guide content and launch checks.', 'The campaign does not yet have a clear brief.', 'Create or open the active brief.'),
            'marketing_brief_edit.php' => self::context('campaigns', 'Write the campaign brief', 'Campaign brief editor', 'Captures goal, offer, audience, message, and success criteria.', 'The brief lacks one decision needed by content.', 'Save the next unanswered brief field.'),
            'marketing_brief_view.php' => self::context('campaigns', 'Review the campaign brief', 'Campaign brief detail', 'Shows the strategic source of truth for campaign execution.', 'The brief may not be connected to the next content step.', 'Use the brief to create the campaign message.'),
            'marketing_journeys.php' => self::context('audiences', 'Map the customer journey', 'Journey map', 'Shows how the audience should move from awareness to action.', 'The campaign path may be unclear.', 'Add or refine one journey step.'),
            'marketing_journey_edit.php' => self::context('audiences', 'Edit the customer journey', 'Journey editor', 'Defines the planned customer movement for campaign messaging.', 'The journey has missing or vague steps.', 'Save the next journey step.'),
            'marketing_journey_view.php' => self::context('audiences', 'Review the customer journey', 'Journey detail', 'Checks whether the campaign path makes sense before content work.', 'A journey step may not have a message or action.', 'Connect the next journey step to content.'),
            'marketing_playbooks.php' => self::context('campaigns', 'Choose the campaign playbook', 'Playbooks', 'Stores repeatable campaign patterns and operating guidance.', 'No repeatable campaign pattern has been selected.', 'Open or create the relevant playbook.'),
            'marketing_playbook_edit.php' => self::context('campaigns', 'Build the campaign playbook', 'Playbook editor', 'Defines repeatable campaign steps and checks.', 'The playbook may be missing a required action.', 'Save the next playbook step.'),
            'marketing_playbook_view.php' => self::context('campaigns', 'Use the campaign playbook', 'Playbook detail', 'Turns a repeatable marketing pattern into current campaign work.', 'The playbook is not connected to active work yet.', 'Use the playbook for the current campaign.'),
            'marketing_roadmap.php' => self::context('campaigns', 'Plan what marketing should tackle next', 'Marketing roadmap', 'Shows planned marketing initiatives and sequencing.', 'The next marketing priority is not sequenced.', 'Pick the next roadmap item.'),
            'marketing_persona_offer_matrix.php' => self::context('campaigns', 'Match offers to customers', 'Persona offer matrix', 'Helps align each audience with the offer that fits them.', 'An offer may not match the selected audience.', 'Choose the strongest offer for the audience.'),
            'marketing_content.php' => self::context('content', 'Create campaign messages', 'Content studio', 'Manages draft, review, approval, and scheduling work.', 'The campaign needs a message or content item.', 'Create or continue the most important content item.'),
            'marketing_content_edit.php' => self::context('content', 'Write or edit the message', 'Content editor', 'Creates the actual campaign copy and content.', 'The message may not match the brief or audience yet.', 'Save the next content draft.'),
            'marketing_content_view.php' => self::context('content', 'Review the message', 'Content detail', 'Checks whether the draft is ready for review, scheduling, or launch.', 'The draft may need approval or channel packaging.', 'Move the content toward review or channel prep.'),
            'marketing_calendar.php' => self::context('content', 'Place the work on the calendar', 'Marketing calendar', 'Shows timing for planned and scheduled marketing work.', 'The campaign timing may be unclear.', 'Schedule or adjust the next content item.'),
            'marketing_reviews.php' => self::context('content', 'Review content before use', 'Content reviews', 'Keeps approvals and feedback from blocking launch.', 'A review or approval is waiting.', 'Resolve the oldest pending review.'),
            'marketing_assistants.php' => self::context('content', 'Use AI to fill marketing knowledge gaps', 'Marketing assistants', 'Gives guided help for briefs, copy, quality, and planning.', 'The founder may not know the expert step to take.', 'Ask for one plain-language recommendation.'),
            'marketing_quality.php' => self::context('content', 'Check whether the message is strong enough', 'AI quality checks', 'Reviews copy and campaign materials before launch.', 'Quality risks may not be resolved.', 'Run or review the latest quality check.'),
            'marketing_creative.php' => self::context('content', 'Prepare creative assets', 'Creative production', 'Tracks visual and media work needed for the campaign.', 'A visual or asset request may be missing.', 'Open the next creative request.'),
            'marketing_assets.php' => self::context('landing', 'Gather images and files', 'Media assets', 'Stores files, usage rights, and media needed by landing pages and channel packages.', 'Required media may be missing or unsafe to use.', 'Add or approve the needed asset.'),
            'marketing_distribution.php' => self::context('send', 'Prepare where the campaign will go', 'Distribution workspace', 'Packages content for manual sharing, channels, and tracking.', 'The campaign is not packaged for channels yet.', 'Create the next distribution package.'),
            'marketing_distribution_bundle.php' => self::context('send', 'Review the distribution package', 'Distribution bundle', 'Shows the content, channel, and tracking bundle before use.', 'A channel or tracking detail may be missing.', 'Fix the first missing bundle item.'),
            'marketing_operator_export_packs.php' => self::context('send', 'Export manual campaign packs', 'Operator export packs', 'Prepares safe manual export packages without automatic publishing.', 'The export pack may not be ready.', 'Prepare or download the needed pack.'),
            'marketing_channel_exports.php' => self::context('send', 'Package content by channel', 'Channel exports', 'Turns approved content into channel-specific packages.', 'A channel package is missing or not ready.', 'Create the first channel export.'),
            'marketing_utm_links.php' => self::context('send', 'Make links trackable', 'UTM links', 'Creates tracking links so performance can be understood later.', 'Tracking links may be missing.', 'Create the campaign tracking link.'),
            'marketing_email_runs.php' => self::context('send', 'Prepare an email run', 'Email campaign runs', 'Packages email work for controlled manual execution.', 'Email evidence or readiness may be incomplete.', 'Prepare the email run for review.'),
            'marketing_launch_readiness.php' => self::context('send', 'Check launch readiness', 'Launch readiness review', 'Reviews campaign, audience, content, destination, channels, and tracking.', 'A launch requirement is missing.', 'Resolve the first readiness blocker.'),
            'marketing_launch_control.php' => self::context('send', 'Control the launch decision', 'Launch control', 'Keeps launch work gated until the required evidence is present.', 'Launch control is not ready or is blocked.', 'Review the launch control record.'),
            'marketing_launch_checklists.php' => self::context('send', 'Run the final checklist', 'Launch checklist', 'Makes sure the campaign is ready before anything goes out.', 'The launch checklist is not complete.', 'Complete the first failed checklist item.'),
            'marketing_guided_workflows.php' => self::context('send', 'Follow the guided workflow', 'Guided workflows', 'Turns complex marketing work into step-by-step progress.', 'The workflow may be paused or blocked.', 'Continue the active workflow.'),
            'marketing_task_hub.php' => self::context('send', 'Finish the marketing tasks', 'Marketing task hub', 'Collects work items that keep campaign progress moving.', 'One task is blocking the path.', 'Clear the highest-priority marketing task.'),
            'marketing_execution.php' => self::context('send', 'Prepare controlled execution', 'Execution center', 'Manages manual export, dry-run, and controlled launch work.', 'Execution may be blocked by missing evidence.', 'Clear the first execution blocker.'),
            'marketing_performance.php' => self::context('results', 'Learn what happened', 'Performance dashboard', 'Shows tracking, conversion, handoff, and campaign result signals.', 'There may not be enough tracking or launch evidence yet.', 'Review the strongest performance signal.'),
            'marketing_handoffs.php' => self::context('results', 'Turn interest into follow-up', 'Lead handoffs', 'Connects marketing response to sales follow-up.', 'A lead handoff may be open or stale.', 'Assign or resolve the next handoff.'),
            'marketing_weekly_report.php' => self::context('results', 'Review this week', 'Weekly marketing report', 'Summarizes weekly marketing performance and next learning.', 'The week may not have enough signal yet.', 'Record the main weekly lesson.'),
            'marketing_monthly_report.php' => self::context('results', 'Review the month', 'Monthly marketing report', 'Summarizes monthly marketing results and learning.', 'The month may not have a clear learning yet.', 'Record the main monthly lesson.'),
            'marketing_operations.php' => self::context('results', 'Keep marketing operating', 'Marketing operations', 'Manages recurring rhythm, readiness, and operating follow-through.', 'The operating rhythm may have stale work.', 'Clear the next operating item.'),
            'marketing_decisions.php' => self::context('campaigns', 'Make the campaign decision explicit', 'Decision center', 'Captures strategy, launch, and operating decisions.', 'A decision may be unresolved.', 'Record the next decision.'),
            'marketing_action_router.php' => self::context('campaigns', 'Route the next marketing action', 'Action router', 'Routes suggested work into the correct marketing path.', 'There may be too many possible actions.', 'Accept or dismiss the highest-value action.'),
            'marketing_relationships.php' => self::context('campaigns', 'See how marketing work connects', 'Relationship graph', 'Shows links between strategy, content, launch, and performance objects.', 'A campaign object may be disconnected.', 'Open the disconnected relationship.'),
            'marketing_system_map.php' => self::context('setup', 'See the full marketing system', 'System map', 'Shows the expert map behind the founder path.', 'A system area has low readiness.', 'Open the weakest system area.'),
            'marketing_admin.php' => self::context('setup', 'Check marketing diagnostics', 'Admin diagnostics', 'Shows advanced readiness, release, and safety checks.', 'A diagnostic warning may need attention.', 'Resolve the highest-risk diagnostic item.'),
        ];

        foreach (MarketingUi::internalPageFilenames() as $filename) {
            $contexts[$filename] ??= self::fallbackContext($filename);
        }
        $contexts['marketing.php']['ui_policy'] = [
            'visible_recommendations' => false,
            'recommendation_surface' => 'clarity',
            'visible_next_actions' => 5,
        ];
        $contexts['marketing.php']['clarity_recommendation_context'] = [
            'summary' => 'Marketing recommendations belong in Clarity, while the command center stays visual and procedural.',
            'sources' => [
                'guided recommendations',
                'CRM lifecycle gaps',
                'integration readiness',
                'AI workspace context gaps',
                'manual automation readiness',
            ],
            'founder_instruction' => 'Explain the single most useful marketing recommendation in plain language, then route the founder to the exact prerequisite or next action.',
        ];

        $gate = new MarketingMarketplaceGateService();
        foreach ($contexts as $filename => &$context) {
            $feature = $gate->featureForPage($filename);
            $context['plugin_feature'] = $feature;
            $context['plugin_family'] = MarketingUi::pluginFamilyLabel($feature);
        }
        unset($context);

        return $contexts;
    }

    public static function contextForPage(string $filename): ?array
    {
        $filename = basename(strtolower(parse_url($filename, PHP_URL_PATH) ?: $filename));
        $contexts = self::contexts();

        return $contexts[$filename] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function context(string $stageKey, string $founderJob, string $expertObject, string $purpose, string $blocker, string $nextStep): array
    {
        return [
            'stage_key' => $stageKey,
            'founder_job' => $founderJob,
            'expert_object_name' => $expertObject,
            'plain_english_purpose' => $purpose,
            'current_likely_blocker' => $blocker,
            'one_next_step' => $nextStep,
            'hidden_advanced_tools' => ['System Map', 'Action Router', 'Relationship Graph', 'Execution Center', 'Decision Center', 'Admin Diagnostics'],
            'terminology_translation' => self::terminology($expertObject),
        ];
    }

    private static function fallbackContext(string $filename): array
    {
        $section = MarketingUi::pageSection($filename);
        $stageKey = match ($section) {
            'setup' => 'setup',
            'audiences' => 'audiences',
            'campaigns' => 'campaigns',
            'content' => 'content',
            'landing' => 'landing',
            'send' => 'send',
            'results' => 'results',
            default => 'setup',
        };
        $label = ucwords(str_replace(['marketing_', '_', '.php'], ['', ' ', ''], $filename));

        return self::context($stageKey, 'Use this marketing tool in the guided path', $label, 'Supports the founder marketing workflow while keeping expert detail secondary.', 'The next procedural step may not be obvious yet.', 'Return to the Marketing command center if the next step is unclear.');
    }

    /**
     * @return array<string,string>
     */
    private static function terminology(string $expertObject): array
    {
        return [
            $expertObject => match ($expertObject) {
                'Audience segment' => 'The group of people this campaign is for.',
                'Campaign brief' => 'The simple plan that explains what the campaign should say and do.',
                'UTM links' => 'Trackable links that help you learn what worked.',
                'Launch checklist' => 'A final safety check before the campaign goes out.',
                default => 'The expert marketing object behind this founder step.',
            },
        ];
    }
}
