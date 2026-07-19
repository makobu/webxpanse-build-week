<?php

namespace CRM\Services;

class MarketingPageGuideUi
{
    /**
     * @return array<string,array{key:string,label:string,title:string}>
     */
    public static function pageDefinitions(): array
    {
        return [
            'marketing.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_COMMAND_CENTER, 'Marketing Command Center page guide', 'How to use Marketing Command Center'),
            'marketing_onboarding.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ONBOARDING, 'Marketing Setup page guide', 'How to use Marketing Setup'),
            'marketing_system_map.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_SYSTEM_MAP, 'Marketing System Map page guide', 'How to use Marketing System Map'),
            'marketing_action_router.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ACTION_ROUTER, 'Marketing Action Router page guide', 'How to use Marketing Action Router'),
            'marketing_relationships.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_RELATIONSHIPS, 'Marketing Relationship Graph page guide', 'How to use Marketing Relationship Graph'),
            'marketing_content.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CONTENT, 'Content Studio page guide', 'How to use Content Studio'),
            'marketing_context.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CONTEXT, 'Marketing Context Hub page guide', 'How to use Marketing Context Hub'),
            'marketing_admin.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ADMIN, 'Marketing Admin Diagnostics page guide', 'How to use Marketing Admin Diagnostics'),
            'marketing_content_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CONTENT_EDIT, 'Marketing Content Editor page guide', 'How to use Marketing Content Editor'),
            'marketing_content_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CONTENT_VIEW, 'Marketing Content Detail page guide', 'How to use Marketing Content Detail'),
            'marketing_briefs.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_BRIEFS, 'Campaign Briefs page guide', 'How to use Campaign Briefs'),
            'marketing_brief_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_BRIEF_EDIT, 'Campaign Brief Editor page guide', 'How to use Campaign Brief Editor'),
            'marketing_brief_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_BRIEF_VIEW, 'Campaign Brief Detail page guide', 'How to use Campaign Brief Detail'),
            'marketing_playbooks.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PLAYBOOKS, 'Campaign Playbooks page guide', 'How to use Campaign Playbooks'),
            'marketing_playbook_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PLAYBOOK_EDIT, 'Campaign Playbook Editor page guide', 'How to use Campaign Playbook Editor'),
            'marketing_playbook_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PLAYBOOK_VIEW, 'Campaign Playbook Detail page guide', 'How to use Campaign Playbook Detail'),
            'marketing_segments.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_SEGMENTS, 'Audience Segments page guide', 'How to use Audience Segments'),
            'marketing_segment_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_SEGMENT_EDIT, 'Audience Segment Editor page guide', 'How to use Audience Segment Editor'),
            'marketing_segment_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_SEGMENT_VIEW, 'Audience Segment Detail page guide', 'How to use Audience Segment Detail'),
            'marketing_audience_activation.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_AUDIENCE_ACTIVATION, 'Audience Activation page guide', 'How to use Audience Activation'),
            'marketing_journeys.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_JOURNEYS, 'Marketing Journeys page guide', 'How to use Marketing Journeys'),
            'marketing_journey_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_JOURNEY_EDIT, 'Marketing Journey Editor page guide', 'How to use Marketing Journey Editor'),
            'marketing_journey_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_JOURNEY_VIEW, 'Marketing Journey Detail page guide', 'How to use Marketing Journey Detail'),
            'marketing_launch_readiness.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LAUNCH_READINESS, 'Marketing Launch Readiness page guide', 'How to use Marketing Launch Readiness'),
            'marketing_launch_control.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LAUNCH_CONTROL, 'Marketing Launch Control Room page guide', 'How to use Marketing Launch Control Room'),
            'marketing_launch_checklists.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LAUNCH_CHECKLISTS, 'Campaign Launch Checklists page guide', 'How to use Campaign Launch Checklists'),
            'marketing_guided_workflows.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_GUIDED_WORKFLOWS, 'Marketing Guided Workflows page guide', 'How to use Marketing Guided Workflows'),
            'marketing_task_hub.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_TASK_HUB, 'Marketing Task Hub page guide', 'How to use Marketing Task Hub'),
            'marketing_campaign_workspace.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CAMPAIGN_WORKSPACE, 'Marketing Campaign Workspace page guide', 'How to use Marketing Campaign Workspace'),
            'marketing_calendar.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CALENDAR, 'Marketing Calendar page guide', 'How to use Marketing Calendar'),
            'marketing_reviews.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_REVIEWS, 'Marketing Approval Workbench page guide', 'How to use Marketing Approval Workbench'),
            'marketing_roadmap.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ROADMAP, 'Campaign Roadmap page guide', 'How to use Campaign Roadmap'),
            'marketing_persona_offer_matrix.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PERSONA_OFFER_MATRIX, 'Persona Offer Matrix page guide', 'How to use Persona Offer Matrix'),
            'marketing_brand.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_BRAND, 'Brand Library page guide', 'How to use Brand Library'),
            'marketing_personas.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PERSONAS, 'Personas page guide', 'How to use Personas'),
            'marketing_seo.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_SEO, 'SEO Topics page guide', 'How to use SEO Topics'),
            'marketing_landing_pages.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LANDING_PAGES, 'Landing Page Plans page guide', 'How to use Landing Page Plans'),
            'marketing_landing_page_edit.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LANDING_PAGE_EDIT, 'Landing Page Editor page guide', 'How to use Landing Page Editor'),
            'marketing_landing_page_view.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_LANDING_PAGE_VIEW, 'Landing Page Detail page guide', 'How to use Landing Page Detail'),
            'marketing_assets.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ASSETS, 'Marketing Assets page guide', 'How to use Marketing Assets'),
            'marketing_distribution.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_DISTRIBUTION, 'Distribution Queue page guide', 'How to use Distribution Queue'),
            'marketing_distribution_bundle.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_DISTRIBUTION_BUNDLE, 'Export Bundle page guide', 'How to use Export Bundles'),
            'marketing_operator_export_packs.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_OPERATOR_EXPORT_PACKS, 'Operator Export Packs page guide', 'How to use Operator Export Packs'),
            'marketing_channel_exports.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CHANNEL_EXPORTS, 'Channel Export Bundles page guide', 'How to use Channel Export Bundles'),
            'marketing_utm_links.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_UTM_LINKS, 'UTM Links page guide', 'How to use UTM Links'),
            'marketing_email_runs.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_EMAIL_RUNS, 'Marketing Email Runs page guide', 'How to use Marketing Email Runs'),
            'marketing_performance.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_PERFORMANCE, 'Marketing Performance page guide', 'How to use Marketing Performance'),
            'marketing_handoffs.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_HANDOFFS, 'Lead Handoffs page guide', 'How to use Lead Handoffs'),
            'marketing_weekly_report.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_WEEKLY_REPORT, 'Weekly Marketing Report page guide', 'How to use Weekly Marketing Reports'),
            'marketing_monthly_report.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_MONTHLY_REPORT, 'Monthly Marketing Report page guide', 'How to use Monthly Marketing Reports'),
            'marketing_assistants.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_ASSISTANTS, 'Marketing Assistants page guide', 'How to use Marketing Assistants'),
            'marketing_quality.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_QUALITY, 'Marketing Quality Checks page guide', 'How to use Marketing Quality Checks'),
            'marketing_creative.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_CREATIVE, 'Creative Production page guide', 'How to use Creative Production'),
            'marketing_operations.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_OPERATIONS, 'Marketing Operations page guide', 'How to use Marketing Operations'),
            'marketing_execution.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_EXECUTION, 'Marketing Execution Control Center page guide', 'How to use Marketing Execution Control Center'),
            'marketing_decisions.php' => self::definition(MarketplacePageExplainerService::PAGE_MARKETING_DECISIONS, 'Marketing Decision Center page guide', 'How to use Marketing Decision Center'),
        ];
    }

    /**
     * @return array{key:string,label:string,title:string}|null
     */
    public static function pageDefinition(string $filename): ?array
    {
        $definitions = self::pageDefinitions();

        return $definitions[basename($filename)] ?? null;
    }

    public static function activeVideoUrl(string $filename): string
    {
        $definition = self::pageDefinition($filename);
        if ($definition === null) {
            return '';
        }

        return PageGuideVideoUi::activeVideoUrl($definition['key']);
    }

    public static function button(string $filename, ?string $videoUrl = null): string
    {
        $definition = self::pageDefinition($filename);
        if ($definition === null) {
            return '';
        }

        $videoUrl = $videoUrl ?? self::activeVideoUrl($filename);
        if (trim($videoUrl) === '') {
            return '';
        }

        return PageGuideVideoUi::button($definition['key'], $definition['label'], 'btn-premium-secondary');
    }

    public static function modal(string $filename, ?string $videoUrl = null): string
    {
        $definition = self::pageDefinition($filename);
        if ($definition === null) {
            return '';
        }

        $videoUrl = $videoUrl ?? self::activeVideoUrl($filename);
        if (trim($videoUrl) === '') {
            return '';
        }

        return PageGuideVideoUi::modal($definition['key'], $definition['title'], $videoUrl);
    }

    public static function decorateContent(string $filename, string $content): string
    {
        $videoUrl = self::activeVideoUrl($filename);
        if (trim($videoUrl) === '') {
            return $content;
        }

        $button = self::button($filename, $videoUrl);
        if ($button === '' || strpos($content, 'page-header-actions') === false) {
            return $content;
        }

        $replacementCount = 0;
        $decoratedContent = preg_replace(
            '/(<div\s+class="page-header-actions"\s*>)/',
            '$1' . "\n                " . $button,
            $content,
            1,
            $replacementCount
        );

        if ($decoratedContent === null || $replacementCount < 1) {
            return $content;
        }

        return PageGuideVideoUi::assets() . "\n" . $decoratedContent . "\n" . self::modal($filename, $videoUrl);
    }

    /**
     * @return array{key:string,label:string,title:string}
     */
    private static function definition(string $key, string $label, string $title): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'title' => $title,
        ];
    }
}
