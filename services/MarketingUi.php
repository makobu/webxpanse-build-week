<?php

namespace CRM\Services;

class MarketingUi
{
    /**
     * @return array<string,array{label:string,href:string,description:string,pages:string[]}>
     */
    public static function sectionDefinitions(): array
    {
        return [
            'home' => [
                'label' => 'Home',
                'href' => 'marketing.php',
                'description' => 'Daily campaign priorities',
                'pages' => ['marketing.php', 'marketing_launch_packet.php'],
            ],
            'setup' => [
                'label' => 'Setup',
                'href' => 'marketing_onboarding.php',
                'description' => 'Know the customer and readiness gaps',
                'pages' => ['marketing_onboarding.php'],
            ],
            'audiences' => [
                'label' => 'Audiences',
                'href' => 'marketing_segments.php',
                'description' => 'Segments and contact previews',
                'pages' => ['marketing_segments.php', 'marketing_segment_edit.php', 'marketing_segment_view.php'],
            ],
            'campaigns' => [
                'label' => 'Campaigns',
                'href' => 'marketing_briefs.php',
                'description' => 'Briefs and campaign intent',
                'pages' => ['marketing_briefs.php', 'marketing_brief_edit.php', 'marketing_brief_view.php'],
            ],
            'content' => [
                'label' => 'Content',
                'href' => 'marketing_content.php',
                'description' => 'Messages and content drafts',
                'pages' => ['marketing_content.php', 'marketing_content_edit.php', 'marketing_content_view.php', 'marketing_campaign_kit.php'],
            ],
            'landing' => [
                'label' => 'Landing Pages',
                'href' => 'marketing_landing_pages.php',
                'description' => 'Conversion pages and previews',
                'pages' => [
                    'marketing_landing_pages.php',
                    'marketing_landing_page_edit.php',
                    'marketing_landing_page_view.php',
                    'marketing_landing_page_preview.php',
                ],
            ],
            'send' => [
                'label' => 'Send / Export',
                'href' => 'marketing_distribution.php',
                'description' => 'Manual send and export work',
                'pages' => ['marketing_distribution.php', 'marketing_distribution_bundle.php'],
            ],
            'results' => [
                'label' => 'Results',
                'href' => 'marketing_performance.php',
                'description' => 'Performance and learning loop',
                'pages' => ['marketing_performance.php', 'marketing_launch_proof.php'],
            ],
            'advanced' => [
                'label' => 'Advanced',
                'href' => 'marketing_system_map.php',
                'description' => 'Operator, admin, and diagnostic tools',
                'pages' => [
                    'marketing_system_map.php',
                    'marketing_action_router.php',
                    'marketing_relationships.php',
                    'marketing_campaign_workspace.php',
                    'marketing_execution.php',
                    'marketing_decisions.php',
                    'marketing_context.php',
                    'marketing_brand.php',
                    'marketing_personas.php',
                    'marketing_seo.php',
                    'marketing_audience_activation.php',
                    'marketing_journeys.php',
                    'marketing_journey_edit.php',
                    'marketing_journey_view.php',
                    'marketing_playbooks.php',
                    'marketing_playbook_edit.php',
                    'marketing_playbook_view.php',
                    'marketing_roadmap.php',
                    'marketing_persona_offer_matrix.php',
                    'marketing_calendar.php',
                    'marketing_reviews.php',
                    'marketing_launch_readiness.php',
                    'marketing_launch_control.php',
                    'marketing_launch_checklists.php',
                    'marketing_guided_workflows.php',
                    'marketing_task_hub.php',
                    'marketing_assistants.php',
                    'marketing_quality.php',
                    'marketing_creative.php',
                    'marketing_assets.php',
                    'marketing_operator_export_packs.php',
                    'marketing_channel_exports.php',
                    'marketing_utm_links.php',
                    'marketing_email_runs.php',
                    'marketing_handoffs.php',
                    'marketing_weekly_report.php',
                    'marketing_monthly_report.php',
                    'marketing_operations.php',
                    'marketing_admin.php',
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,href:string,icon:string}>
     */
    public static function primaryNavigationItems(bool $beginnerLanguage = false): array
    {
        if ($beginnerLanguage) {
            return [
                ['label' => 'Campaign Home', 'href' => 'marketing.php', 'icon' => 'fa-chart-pie'],
                ['label' => 'Growth Setup', 'href' => 'marketing_onboarding.php', 'icon' => 'fa-rocket'],
                ['label' => 'Customer Groups', 'href' => 'marketing_segments.php', 'icon' => 'fa-users-viewfinder'],
                ['label' => 'Promotions', 'href' => 'marketing_briefs.php', 'icon' => 'fa-clipboard-list'],
                ['label' => 'Customer Messages', 'href' => 'marketing_content.php', 'icon' => 'fa-pen-nib'],
                ['label' => 'Send / Share', 'href' => 'marketing_distribution.php', 'icon' => 'fa-share-nodes'],
                ['label' => 'Results', 'href' => 'marketing_performance.php', 'icon' => 'fa-chart-line'],
            ];
        }

        return [
            ['label' => 'Campaign Home', 'href' => 'marketing.php', 'icon' => 'fa-chart-pie'],
            ['label' => 'Setup', 'href' => 'marketing_onboarding.php', 'icon' => 'fa-rocket'],
            ['label' => 'Audiences', 'href' => 'marketing_segments.php', 'icon' => 'fa-users-viewfinder'],
            ['label' => 'Campaigns', 'href' => 'marketing_briefs.php', 'icon' => 'fa-clipboard-list'],
            ['label' => 'Messages & Content', 'href' => 'marketing_content.php', 'icon' => 'fa-pen-nib'],
            ['label' => 'Send / Export', 'href' => 'marketing_distribution.php', 'icon' => 'fa-share-nodes'],
            ['label' => 'Results', 'href' => 'marketing_performance.php', 'icon' => 'fa-chart-line'],
        ];
    }

    /**
     * @return array<string,array{feature:string,label:string,description:string,icon:string,setup_label:string,advanced_label:string,advanced_href:string,items:array<int,array{label:string,href:string,icon:string,requires?:string,class?:string,pages?:string[],tier?:string}>}>
     */
    public static function pluginNavigationGroups(bool $beginnerLanguage = false): array
    {
        return [
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => [
                'feature' => MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
                'label' => 'Campaign Manager',
                'description' => 'Campaign setup, orchestration, analytics, and operating control',
                'icon' => 'fa-chart-line',
                'setup_label' => 'Set up Campaign Manager',
                'advanced_label' => $beginnerLanguage ? 'Advanced Growth Operations' : 'Advanced Marketing Operations',
                'advanced_href' => 'marketing.php#advanced-marketing-operations',
                'items' => [
                    ['label' => 'Campaign Home', 'href' => 'marketing.php', 'icon' => 'fa-chart-pie', 'tier' => 'primary'],
                    ['label' => $beginnerLanguage ? 'Growth Setup' : 'Setup', 'href' => 'marketing_onboarding.php', 'icon' => 'fa-rocket', 'tier' => 'primary'],
                    ['label' => 'Context Hub', 'href' => 'marketing_context.php', 'icon' => 'fa-layer-group'],
                    ['label' => $beginnerLanguage ? 'Customer Groups' : 'Audiences', 'href' => 'marketing_segments.php', 'icon' => 'fa-users-viewfinder', 'pages' => ['marketing_segment_edit.php', 'marketing_segment_view.php'], 'tier' => 'primary'],
                    ['label' => 'Personas', 'href' => 'marketing_personas.php', 'icon' => 'fa-user-tag'],
                    ['label' => 'Audience Activation', 'href' => 'marketing_audience_activation.php', 'icon' => 'fa-bullseye'],
                    ['label' => 'Journeys', 'href' => 'marketing_journeys.php', 'icon' => 'fa-diagram-project', 'pages' => ['marketing_journey_edit.php', 'marketing_journey_view.php']],
                    ['label' => $beginnerLanguage ? 'Promotions' : 'Campaigns', 'href' => 'marketing_briefs.php', 'icon' => 'fa-clipboard-list', 'pages' => ['marketing_brief_edit.php', 'marketing_brief_view.php'], 'tier' => 'primary'],
                    ['label' => 'Campaign Kit', 'href' => 'marketing_campaign_kit.php', 'icon' => 'fa-wand-magic-sparkles', 'tier' => 'primary'],
                    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'icon' => 'fa-diagram-project'],
                    ['label' => 'Campaign Playbooks', 'href' => 'marketing_playbooks.php', 'icon' => 'fa-book-open', 'pages' => ['marketing_playbook_edit.php', 'marketing_playbook_view.php']],
                    ['label' => 'Campaign Roadmap', 'href' => 'marketing_roadmap.php', 'icon' => 'fa-route'],
                    ['label' => 'Persona Offer Matrix', 'href' => 'marketing_persona_offer_matrix.php', 'icon' => 'fa-table-cells'],
                    ['label' => 'Launch', 'href' => 'marketing_launch_readiness.php', 'icon' => 'fa-clipboard-check', 'tier' => 'primary'],
                    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'icon' => 'fa-tower-broadcast'],
                    ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php', 'icon' => 'fa-list-check'],
                    ['label' => 'Guided Workflows', 'href' => 'marketing_guided_workflows.php', 'icon' => 'fa-list-check'],
                    ['label' => 'Task Hub', 'href' => 'marketing_task_hub.php', 'icon' => 'fa-inbox'],
                    ['label' => 'Marketing Assistants', 'href' => 'marketing_assistants.php', 'icon' => 'fa-user-tie'],
                    ['label' => 'Results', 'href' => 'marketing_performance.php', 'icon' => 'fa-chart-line', 'pages' => ['marketing_launch_proof.php'], 'tier' => 'primary'],
                    ['label' => 'Lead Handoffs', 'href' => 'marketing_handoffs.php', 'icon' => 'fa-handshake'],
                    ['label' => 'Weekly Report', 'href' => 'marketing_weekly_report.php', 'icon' => 'fa-chart-simple'],
                    ['label' => 'Monthly Report', 'href' => 'marketing_monthly_report.php', 'icon' => 'fa-chart-column'],
                    ['label' => 'Marketing Operations', 'href' => 'marketing_operations.php', 'icon' => 'fa-arrows-rotate'],
                    ['label' => 'System Map', 'href' => 'marketing_system_map.php', 'icon' => 'fa-map'],
                    ['label' => 'Action Router', 'href' => 'marketing_action_router.php', 'icon' => 'fa-route'],
                    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'icon' => 'fa-share-nodes'],
                    ['label' => 'Execution Center', 'href' => 'marketing_execution.php', 'icon' => 'fa-sliders'],
                    ['label' => 'Decision Center', 'href' => 'marketing_decisions.php', 'icon' => 'fa-scale-balanced'],
                    ['label' => 'Admin Diagnostics', 'href' => 'marketing_admin.php', 'icon' => 'fa-screwdriver-wrench', 'requires' => 'marketing_manage', 'class' => 'manage-only'],
                    ['label' => 'Campaign Automation', 'href' => 'campaigns.php', 'icon' => 'fa-bullhorn', 'requires' => 'campaigns_manage', 'pages' => ['campaign_view.php']],
                    ['label' => 'Attribution Reports', 'href' => 'attribution_reports.php', 'icon' => 'fa-project-diagram'],
                ],
            ],
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => [
                'feature' => MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA,
                'label' => $beginnerLanguage ? 'Posts & Sharing' : 'Social Media',
                'description' => 'Content, calendar, channel packaging, exports, and social tracking',
                'icon' => 'fa-share-nodes',
                'setup_label' => $beginnerLanguage ? 'Set up Posts & Sharing' : 'Set up Social Media',
                'advanced_label' => $beginnerLanguage ? 'More Posting Tools' : 'Advanced Social Tools',
                'advanced_href' => 'social_media.php#advanced-social-tools',
                'items' => [
                    ['label' => $beginnerLanguage ? 'Posts & Sharing Home' : 'Social Media Home', 'href' => 'social_media.php', 'icon' => 'fa-share-nodes', 'tier' => 'primary'],
                    ['label' => 'Create Post', 'href' => 'social_media.php?tab=composer', 'icon' => 'fa-pen', 'tier' => 'primary'],
                    ['label' => 'Publishing Calendar', 'href' => 'social_media.php?tab=calendar', 'icon' => 'fa-calendar-days', 'tier' => 'primary'],
                    ['label' => 'Insights', 'href' => 'social_media.php?tab=insights', 'icon' => 'fa-chart-line', 'tier' => 'primary'],
                    ['label' => $beginnerLanguage ? 'Customer Messages' : 'Messages & Content', 'href' => 'marketing_content.php', 'icon' => 'fa-pen-nib', 'pages' => ['marketing_content_view.php']],
                    ['label' => 'New Content', 'href' => 'marketing_content_edit.php', 'icon' => 'fa-plus', 'requires' => 'marketing_write'],
                    ['label' => 'Marketing Calendar', 'href' => 'marketing_calendar.php', 'icon' => 'fa-calendar-days'],
                    ['label' => 'Reviews', 'href' => 'marketing_reviews.php', 'icon' => 'fa-clipboard-check', 'tier' => 'primary'],
                    ['label' => 'Quality Checks', 'href' => 'marketing_quality.php', 'icon' => 'fa-shield-halved'],
                    ['label' => $beginnerLanguage ? 'Send / Share' : 'Send / Export', 'href' => 'marketing_distribution.php', 'icon' => 'fa-share-nodes', 'pages' => ['marketing_distribution_bundle.php']],
                    ['label' => 'Operator Export Packs', 'href' => 'marketing_operator_export_packs.php', 'icon' => 'fa-boxes-packing'],
                    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'icon' => 'fa-box-open'],
                    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'icon' => 'fa-link'],
                    ['label' => 'Email Runs', 'href' => 'marketing_email_runs.php', 'icon' => 'fa-envelope-open-text'],
                ],
            ],
            MarketingMarketplaceGateService::FEATURE_DESIGN => [
                'feature' => MarketingMarketplaceGateService::FEATURE_DESIGN,
                'label' => $beginnerLanguage ? 'Pages & Forms' : 'Design',
                'description' => 'Landing pages, forms, email signatures, creative assets, brand, and conversion surfaces',
                'icon' => 'fa-pen-ruler',
                'setup_label' => $beginnerLanguage ? 'Set up Pages & Forms' : 'Set up Design',
                'advanced_label' => $beginnerLanguage ? 'More Design Tools' : 'Advanced Design Tools',
                'advanced_href' => 'design.php?tab=assets#advanced-design-tools',
                'items' => [
                    ['label' => $beginnerLanguage ? 'Pages & Forms Home' : 'Design Home', 'href' => 'design.php', 'icon' => 'fa-pen-ruler', 'tier' => 'primary'],
                    ['label' => $beginnerLanguage ? 'Offer Pages' : 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'icon' => 'fa-window-maximize', 'pages' => ['marketing_landing_page_edit.php', 'marketing_landing_page_view.php', 'marketing_landing_page_preview.php'], 'tier' => 'primary'],
                    ['label' => 'Creative Production', 'href' => 'marketing_creative.php', 'icon' => 'fa-pen-ruler'],
                    ['label' => 'Assets', 'href' => 'marketing_assets.php', 'icon' => 'fa-images', 'tier' => 'primary'],
                    ['label' => 'Brand Library', 'href' => 'marketing_brand.php', 'icon' => 'fa-palette'],
                    ['label' => 'SEO Topics', 'href' => 'marketing_seo.php', 'icon' => 'fa-magnifying-glass-chart'],
                    ['label' => 'Forms', 'href' => 'forms.php', 'icon' => 'fa-wpforms', 'pages' => ['form_edit.php', 'form_submissions.php'], 'tier' => 'primary'],
                    ['label' => 'Email Signatures', 'href' => 'email_signatures.php', 'icon' => 'fa-signature', 'pages' => ['email_signature_create.php', 'email_signature_edit.php'], 'tier' => 'primary'],
                ],
            ],
        ];
    }

    public static function pluginFamilyLabel(?string $feature, bool $beginnerLanguage = false): string
    {
        $feature = strtolower(trim((string) $feature));

        return match ($feature) {
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => $beginnerLanguage ? 'Posts & Sharing' : 'Social Media',
            MarketingMarketplaceGateService::FEATURE_DESIGN => $beginnerLanguage ? 'Pages & Forms' : 'Design',
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => 'Campaign Manager',
            default => $beginnerLanguage ? 'Customer Growth' : 'Marketing Overview',
        };
    }

    public static function pluginFamilyForPage(string $filename, bool $beginnerLanguage = false): string
    {
        $feature = (new MarketingMarketplaceGateService())->featureForPage($filename);

        return self::pluginFamilyLabel($feature, $beginnerLanguage);
    }

    /**
     * @return array<int,array{heading:string,items:array<int,array{label:string,href:string,icon:string,requires?:string,class?:string}>}>
     */
    public static function advancedNavigationGroups(): array
    {
        return [
            [
                'heading' => 'System And Control',
                'items' => [
                    ['label' => 'System Map', 'href' => 'marketing_system_map.php', 'icon' => 'fa-map'],
                    ['label' => 'Action Router', 'href' => 'marketing_action_router.php', 'icon' => 'fa-route'],
                    ['label' => 'Relationship Graph', 'href' => 'marketing_relationships.php', 'icon' => 'fa-share-nodes'],
                    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php', 'icon' => 'fa-diagram-project'],
                    ['label' => 'Execution Center', 'href' => 'marketing_execution.php', 'icon' => 'fa-sliders'],
                    ['label' => 'Decision Center', 'href' => 'marketing_decisions.php', 'icon' => 'fa-scale-balanced'],
                ],
            ],
            [
                'heading' => 'Strategy Tools',
                'items' => [
                    ['label' => 'Context Hub', 'href' => 'marketing_context.php', 'icon' => 'fa-layer-group'],
                    ['label' => 'Brand Library', 'href' => 'marketing_brand.php', 'icon' => 'fa-palette'],
                    ['label' => 'Personas', 'href' => 'marketing_personas.php', 'icon' => 'fa-user-tag'],
                    ['label' => 'SEO Topics', 'href' => 'marketing_seo.php', 'icon' => 'fa-magnifying-glass-chart'],
                    ['label' => 'Audience Activation', 'href' => 'marketing_audience_activation.php', 'icon' => 'fa-bullseye'],
                    ['label' => 'Journeys', 'href' => 'marketing_journeys.php', 'icon' => 'fa-diagram-project'],
                    ['label' => 'Campaign Playbooks', 'href' => 'marketing_playbooks.php', 'icon' => 'fa-book-open'],
                    ['label' => 'Campaign Roadmap', 'href' => 'marketing_roadmap.php', 'icon' => 'fa-route'],
                    ['label' => 'Persona Offer Matrix', 'href' => 'marketing_persona_offer_matrix.php', 'icon' => 'fa-table-cells'],
                ],
            ],
            [
                'heading' => 'Production And Review',
                'items' => [
                    ['label' => 'New Content', 'href' => 'marketing_content_edit.php', 'icon' => 'fa-plus', 'requires' => 'marketing_write'],
                    ['label' => 'Marketing Calendar', 'href' => 'marketing_calendar.php', 'icon' => 'fa-calendar-days'],
                    ['label' => 'Reviews', 'href' => 'marketing_reviews.php', 'icon' => 'fa-clipboard-check'],
                    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'icon' => 'fa-clipboard-check'],
                    ['label' => 'Launch Control', 'href' => 'marketing_launch_control.php', 'icon' => 'fa-tower-broadcast'],
                    ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php', 'icon' => 'fa-list-check'],
                    ['label' => 'Guided Workflows', 'href' => 'marketing_guided_workflows.php', 'icon' => 'fa-list-check'],
                    ['label' => 'Task Hub', 'href' => 'marketing_task_hub.php', 'icon' => 'fa-inbox'],
                    ['label' => 'Marketing Assistants', 'href' => 'marketing_assistants.php', 'icon' => 'fa-user-tie'],
                    ['label' => 'Quality Checks', 'href' => 'marketing_quality.php', 'icon' => 'fa-shield-halved'],
                    ['label' => 'Creative Production', 'href' => 'marketing_creative.php', 'icon' => 'fa-pen-ruler'],
                    ['label' => 'Assets', 'href' => 'marketing_assets.php', 'icon' => 'fa-images'],
                ],
            ],
            [
                'heading' => 'Distribution And Measurement',
                'items' => [
                    ['label' => 'Email Runs', 'href' => 'marketing_email_runs.php', 'icon' => 'fa-envelope-open-text'],
                    ['label' => 'Operator Export Packs', 'href' => 'marketing_operator_export_packs.php', 'icon' => 'fa-boxes-packing'],
                    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'icon' => 'fa-box-open'],
                    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php', 'icon' => 'fa-link'],
                    ['label' => 'Lead Handoffs', 'href' => 'marketing_handoffs.php', 'icon' => 'fa-handshake'],
                    ['label' => 'Weekly Report', 'href' => 'marketing_weekly_report.php', 'icon' => 'fa-chart-simple'],
                    ['label' => 'Monthly Report', 'href' => 'marketing_monthly_report.php', 'icon' => 'fa-chart-column'],
                    ['label' => 'Marketing Operations', 'href' => 'marketing_operations.php', 'icon' => 'fa-arrows-rotate'],
                ],
            ],
            [
                'heading' => 'Admin And CRM Tools',
                'items' => [
                    ['label' => 'Admin Diagnostics', 'href' => 'marketing_admin.php', 'icon' => 'fa-screwdriver-wrench', 'requires' => 'marketing_manage', 'class' => 'manage-only'],
                    ['label' => 'Campaign Automation', 'href' => 'campaigns.php', 'icon' => 'fa-bullhorn', 'requires' => 'campaigns_manage'],
                    ['label' => 'Forms', 'href' => 'forms.php', 'icon' => 'fa-wpforms'],
                    ['label' => 'Email Templates', 'href' => 'email_templates.php', 'icon' => 'fa-file-alt'],
                    ['label' => 'Attribution Reports', 'href' => 'attribution_reports.php', 'icon' => 'fa-project-diagram'],
                ],
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function internalPageFilenames(): array
    {
        $pages = [];
        foreach (self::sectionDefinitions() as $section) {
            foreach ($section['pages'] as $page) {
                $pages[] = $page;
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * @return string[]
     */
    public static function navigationPageFilenames(): array
    {
        $pages = self::internalPageFilenames();
        foreach (self::pluginNavigationGroups(false) as $group) {
            foreach ($group['items'] as $item) {
                $hrefPath = (string) (parse_url((string) $item['href'], PHP_URL_PATH) ?: $item['href']);
                $pages[] = basename($hrefPath);
                foreach ((array) ($item['pages'] ?? []) as $page) {
                    $pages[] = basename((string) $page);
                }
            }
        }

        return array_values(array_unique($pages));
    }

    public static function isNavigationPage(string $filename): bool
    {
        return in_array(basename($filename), self::navigationPageFilenames(), true);
    }

    public static function renderDesktopPluginNavigation(
        string $feature,
        bool $canMarketingWrite = false,
        bool $canMarketingManage = false,
        bool $canCampaignsManage = false,
        bool $canNurtureRead = false,
        bool $beginnerLanguage = false,
        array $pluginAccessByFeature = []
    ): string {
        $group = self::visiblePluginNavigationGroup(
            $feature,
            $canMarketingWrite,
            $canMarketingManage,
            $canCampaignsManage,
            $canNurtureRead,
            $beginnerLanguage
        );
        if ($group === null) {
            return '';
        }

        $access = self::navigationAccessForFeature($pluginAccessByFeature, (string) $group['feature']);
        if (empty($access['can_run'])) {
            return self::desktopSetupItem($group, $access);
        }

        $html = '';
        foreach ($group['items'] as $item) {
            if (($item['tier'] ?? '') === 'primary') {
                $html .= self::desktopNavItem($item);
            }
        }

        $html .= '<div class="nav-dropdown-divider"></div>';
        $html .= self::desktopNavItem([
            'label' => (string) $group['advanced_label'],
            'href' => (string) $group['advanced_href'],
            'icon' => 'fa-gears',
            'class' => 'marketing-nav-advanced-entry',
        ]);

        return $html;
    }

    public static function renderMobilePluginNavigation(
        string $feature,
        bool $canMarketingWrite = false,
        bool $canMarketingManage = false,
        bool $canCampaignsManage = false,
        bool $canNurtureRead = false,
        bool $beginnerLanguage = false,
        array $pluginAccessByFeature = []
    ): string {
        $group = self::visiblePluginNavigationGroup(
            $feature,
            $canMarketingWrite,
            $canMarketingManage,
            $canCampaignsManage,
            $canNurtureRead,
            $beginnerLanguage
        );
        if ($group === null) {
            return '';
        }

        $html = '<div class="mobile-nav-section-heading">' . self::escape((string) $group['label']) . '</div>';
        $access = self::navigationAccessForFeature($pluginAccessByFeature, (string) $group['feature']);
        if (empty($access['can_run'])) {
            return $html . self::mobileSetupItem($group, $access);
        }

        foreach ($group['items'] as $item) {
            if (($item['tier'] ?? '') === 'primary') {
                $html .= self::mobileNavItem($item);
            }
        }
        $html .= self::mobileNavItem([
            'label' => (string) $group['advanced_label'],
            'href' => (string) $group['advanced_href'],
            'icon' => 'fa-gears',
        ]);

        return $html;
    }

    public static function renderDesktopNavigation(
        bool $canMarketingWrite = false,
        bool $canMarketingManage = false,
        bool $canCampaignsManage = false,
        bool $canNurtureRead = false,
        bool $beginnerLanguage = false,
        array $pluginAccessByFeature = []
    ): string {
        $html = '';
        foreach (self::visiblePluginNavigationGroups($canMarketingWrite, $canMarketingManage, $canCampaignsManage, $canNurtureRead, $beginnerLanguage) as $group) {
            $feature = (string) $group['feature'];
            $access = self::navigationAccessForFeature($pluginAccessByFeature, $feature);
            $html .= '<div class="marketing-nav-plugin-group">';
            $html .= '<div class="nav-dropdown-heading marketing-nav-plugin-heading">'
                . '<i class="fas ' . self::escape($group['icon']) . '"></i>'
                . '<span>' . self::escape($group['label']) . '</span>'
                . '</div>';

            if (empty($access['can_run'])) {
                $html .= self::desktopSetupItem($group, $access);
                $html .= '</div>';
                continue;
            }

            foreach ($group['items'] as $item) {
                $html .= self::desktopNavItem($item);
            }
            $html .= '</div>';
        }

        return $html;
    }

    public static function renderMobileNavigation(
        bool $canMarketingWrite = false,
        bool $canMarketingManage = false,
        bool $canCampaignsManage = false,
        bool $canNurtureRead = false,
        bool $beginnerLanguage = false,
        array $pluginAccessByFeature = []
    ): string {
        $html = '<div class="mobile-nav-section-heading">Marketing</div>';

        foreach (self::visiblePluginNavigationGroups($canMarketingWrite, $canMarketingManage, $canCampaignsManage, $canNurtureRead, $beginnerLanguage) as $group) {
            $feature = (string) $group['feature'];
            $access = self::navigationAccessForFeature($pluginAccessByFeature, $feature);
            $html .= '<div class="mobile-nav-subheading">' . self::escape($group['label']) . '</div>';

            if (empty($access['can_run'])) {
                $html .= self::mobileSetupItem($group, $access);
                continue;
            }

            foreach ($group['items'] as $item) {
                $html .= self::mobileNavItem($item);
            }
        }

        return $html;
    }

    public static function isInternalPage(string $filename): bool
    {
        return in_array(basename($filename), self::internalPageFilenames(), true);
    }

    /**
     * Legacy CRM tools that are exposed from the Marketing menu but do not use
     * the Marketing module's internal route naming convention.
     *
     * @return string[]
     */
    public static function legacyRefinementPageFilenames(): array
    {
        return [
            'campaigns.php',
            'campaign_view.php',
            'attribution_reports.php',
        ];
    }

    /**
     * @return string[]
     */
    public static function refinementPageFilenames(): array
    {
        return array_values(array_unique(array_merge(
            self::internalPageFilenames(),
            self::legacyRefinementPageFilenames()
        )));
    }

    public static function isRefinementPage(string $filename): bool
    {
        return in_array(basename($filename), self::refinementPageFilenames(), true);
    }

    public static function stylesheetTag(): string
    {
        $path = 'css/marketing-ui.css';
        $url = function_exists('assetUrl') ? assetUrl($path) : 'assets/' . $path;
        $file = dirname(__DIR__) . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : (string) time();

        return '<link rel="stylesheet" href="' . htmlspecialchars($url . '?v=' . $version, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function decorateContent(string $filename, string $content): string
    {
        if (!self::isRefinementPage($filename)) {
            return $content;
        }

        $content = self::addClassToFirst($content, 'page-premium', 'marketing-ui-page');
        $content = self::addClassToFirst($content, 'marketing-ui-page', 'marketing-ui-refined');
        $content = self::addClassEverywhere($content, 'page-header', 'marketing-page-header');
        $content = self::addClassEverywhere($content, 'page-header-actions', 'marketing-page-actions');

        if (!self::isInternalPage($filename)) {
            return $content;
        }

        $content = self::addClassEverywhere($content, 'landing-preview', 'marketing-landing-preview');
        $content = self::injectOperatorChrome($filename, $content);

        return $content;
    }

    public static function pageSection(string $filename): string
    {
        $filename = basename($filename);
        foreach (self::sectionDefinitions() as $key => $section) {
            if (in_array($filename, $section['pages'], true)) {
                return $key;
            }
        }

        return 'home';
    }

    private static function injectOperatorChrome(string $filename, string $content): string
    {
        if (strpos($content, 'marketing-operator-strip') !== false) {
            return $content;
        }

        $chrome = self::operatorChrome($filename);
        if ($chrome === '') {
            return $content;
        }

        $pattern = '/(<div\s+class="[^"]*\bcontainer\b[^"]*"\s*>)/';
        $replacementCount = 0;
        $decorated = preg_replace($pattern, '$1' . "\n" . $chrome, $content, 1, $replacementCount);

        return $decorated !== null && $replacementCount > 0 ? $decorated : $content;
    }

    private static function operatorChrome(string $filename): string
    {
        $feature = (new MarketingMarketplaceGateService())->featureForPage($filename);
        $isDesign = $feature === MarketingMarketplaceGateService::FEATURE_DESIGN;
        $sectionKey = $isDesign ? self::designPageSection($filename) : self::pageSection($filename);
        $sections = $isDesign ? self::designSectionDefinitions() : self::sectionDefinitions();
        $activeSection = $sections[$sectionKey] ?? $sections['home'];
        $nextActions = $isDesign ? self::designNextActionLinks($sectionKey) : self::nextActionLinks($sectionKey);
        $workspaceLabel = $isDesign ? 'Design Workspace' : 'Marketing Workspace / ' . self::pluginFamilyForPage($filename);
        $workspaceAria = $isDesign ? 'Design workspace navigation' : 'Marketing workspace navigation';
        $sectionLinks = [];

        foreach ($sections as $key => $section) {
            $classes = ['marketing-section-link'];
            if ($key === $sectionKey) {
                $classes[] = 'active';
            }
            $sectionLinks[] = '<a class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($section['href'], ENT_QUOTES, 'UTF-8') . '">'
                . '<span>' . htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8') . '</span>'
                . '</a>';
        }

        $primaryAction = $nextActions[0] ?? ['label' => 'Command center', 'href' => 'marketing.php'];
        $actionLinks = [
            '<a class="marketing-next-primary" href="' . htmlspecialchars($primaryAction['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($primaryAction['label'], ENT_QUOTES, 'UTF-8') . '</a>',
        ];
        if (count($nextActions) > 1) {
            $toolLinks = [];
            foreach (array_slice($nextActions, 1) as $action) {
                $toolLinks[] = '<a href="' . htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            $actionLinks[] = '<details class="marketing-more-tools"><summary>More tools</summary><div>' . implode('', $toolLinks) . '</div></details>';
        }

        return '<div class="marketing-operator-strip" aria-label="' . htmlspecialchars($workspaceAria, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="marketing-operator-context">'
            . '<span class="marketing-operator-kicker">' . htmlspecialchars($workspaceLabel, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<strong>' . htmlspecialchars($activeSection['label'], ENT_QUOTES, 'UTF-8') . '</strong>'
            . '<span>' . htmlspecialchars($activeSection['description'], ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>'
            . '<nav class="marketing-section-nav" aria-label="Marketing sections">' . implode('', $sectionLinks) . '</nav>'
            . '<div class="marketing-next-actions"><span>Next</span>' . implode('', $actionLinks) . '</div>'
            . '</div>';
    }

    /**
     * @return array<string,array{label:string,href:string,description:string,pages:string[]}>
     */
    private static function designSectionDefinitions(): array
    {
        return [
            'home' => ['label' => 'Design Home', 'href' => 'design.php', 'description' => 'Pages, forms, signatures and creative readiness', 'pages' => ['design.php']],
            'landing' => ['label' => 'Landing Pages', 'href' => 'marketing_landing_pages.php', 'description' => 'Conversion pages, previews and publishing', 'pages' => ['marketing_landing_pages.php', 'marketing_landing_page_edit.php', 'marketing_landing_page_view.php', 'marketing_landing_page_preview.php']],
            'creative' => ['label' => 'Creative', 'href' => 'marketing_creative.php', 'description' => 'Produce and review campaign creative', 'pages' => ['marketing_creative.php']],
            'assets' => ['label' => 'Assets', 'href' => 'marketing_assets.php', 'description' => 'Media readiness, rights and placement', 'pages' => ['marketing_assets.php']],
            'brand' => ['label' => 'Brand', 'href' => 'marketing_brand.php', 'description' => 'Reusable visual and message direction', 'pages' => ['marketing_brand.php']],
            'seo' => ['label' => 'SEO', 'href' => 'marketing_seo.php', 'description' => 'Search intent for conversion pages', 'pages' => ['marketing_seo.php']],
            'forms' => ['label' => 'Forms', 'href' => 'forms.php', 'description' => 'Lead capture and submissions', 'pages' => ['forms.php', 'form_edit.php', 'form_submissions.php']],
            'signatures' => ['label' => 'Signatures', 'href' => 'email_signatures.php', 'description' => 'Consistent email identity and sign-offs', 'pages' => ['email_signatures.php', 'email_signature_create.php', 'email_signature_edit.php']],
        ];
    }

    private static function designPageSection(string $filename): string
    {
        $filename = basename($filename);
        foreach (self::designSectionDefinitions() as $key => $section) {
            if (in_array($filename, $section['pages'], true)) {
                return $key;
            }
        }

        return 'home';
    }

    /**
     * @return array<int,array{label:string,href:string}>
     */
    private static function designNextActionLinks(string $sectionKey): array
    {
        return match ($sectionKey) {
            'landing' => [
                ['label' => 'Create landing page', 'href' => 'marketing_landing_page_edit.php'],
                ['label' => 'Connect form', 'href' => 'forms.php'],
            ],
            'creative' => [
                ['label' => 'Open assets', 'href' => 'marketing_assets.php'],
                ['label' => 'Review landing pages', 'href' => 'marketing_landing_pages.php'],
            ],
            'assets' => [
                ['label' => 'Creative production', 'href' => 'marketing_creative.php'],
                ['label' => 'Review landing pages', 'href' => 'marketing_landing_pages.php'],
            ],
            'brand' => [
                ['label' => 'Review landing pages', 'href' => 'marketing_landing_pages.php'],
                ['label' => 'Open SEO topics', 'href' => 'marketing_seo.php'],
            ],
            'seo' => [
                ['label' => 'Review landing pages', 'href' => 'marketing_landing_pages.php'],
                ['label' => 'Open brand library', 'href' => 'marketing_brand.php'],
            ],
            'forms' => [
                ['label' => 'Create form', 'href' => 'form_edit.php'],
                ['label' => 'Open landing pages', 'href' => 'marketing_landing_pages.php'],
            ],
            default => [
                ['label' => 'Create landing page', 'href' => 'marketing_landing_page_edit.php'],
                ['label' => 'Create form', 'href' => 'form_edit.php'],
            ],
        };
    }

    /**
     * @return array<int,array{label:string,href:string}>
     */
    private static function nextActionLinks(string $sectionKey): array
    {
        return match ($sectionKey) {
            'home' => [
                ['label' => 'Setup', 'href' => 'marketing_onboarding.php'],
                ['label' => 'Audiences', 'href' => 'marketing_segments.php'],
            ],
            'setup' => [
                ['label' => 'Audiences', 'href' => 'marketing_segments.php'],
                ['label' => 'Campaigns', 'href' => 'marketing_briefs.php'],
            ],
            'audiences' => [
                ['label' => 'Briefs', 'href' => 'marketing_briefs.php'],
                ['label' => 'Content', 'href' => 'marketing_content.php'],
            ],
            'campaigns' => [
                ['label' => 'Content', 'href' => 'marketing_content.php'],
                ['label' => 'Landing pages', 'href' => 'marketing_landing_pages.php'],
            ],
            'content' => [
                ['label' => 'Landing pages', 'href' => 'marketing_landing_pages.php'],
                ['label' => 'Send / Export', 'href' => 'marketing_distribution.php'],
            ],
            'landing' => [
                ['label' => 'Send / Export', 'href' => 'marketing_distribution.php'],
                ['label' => 'Results', 'href' => 'marketing_performance.php'],
            ],
            'send' => [
                ['label' => 'Results', 'href' => 'marketing_performance.php'],
                ['label' => 'Marketing home', 'href' => 'marketing.php'],
            ],
            'results' => [
                ['label' => 'Marketing home', 'href' => 'marketing.php'],
                ['label' => 'Setup', 'href' => 'marketing_onboarding.php'],
            ],
            'advanced' => [
                ['label' => 'Marketing home', 'href' => 'marketing.php'],
                ['label' => 'Setup', 'href' => 'marketing_onboarding.php'],
            ],
            default => [
                ['label' => 'Marketing home', 'href' => 'marketing.php'],
                ['label' => 'Setup', 'href' => 'marketing_onboarding.php'],
            ],
        };
    }

    /**
     * @return array<int,array{feature:string,label:string,description:string,icon:string,setup_label:string,items:array<int,array{label:string,href:string,icon:string,requires?:string,class?:string,pages?:string[]}>}>
     */
    private static function visiblePluginNavigationGroups(
        bool $canMarketingWrite,
        bool $canMarketingManage,
        bool $canCampaignsManage,
        bool $canNurtureRead,
        bool $beginnerLanguage
    ): array {
        $permissions = [
            'marketing_write' => $canMarketingWrite,
            'marketing_manage' => $canMarketingManage,
            'campaigns_manage' => $canCampaignsManage,
            'nurture_read' => $canNurtureRead,
        ];

        $groups = [];
        foreach (self::pluginNavigationGroups($beginnerLanguage) as $group) {
            $items = array_values(array_filter(
                $group['items'],
                static function (array $item) use ($permissions): bool {
                    $required = (string) ($item['requires'] ?? '');

                    return $required === '' || !empty($permissions[$required]);
                }
            ));
            $group['items'] = $items;
            $groups[] = $group;
        }

        return $groups;
    }

    private static function visiblePluginNavigationGroup(
        string $feature,
        bool $canMarketingWrite,
        bool $canMarketingManage,
        bool $canCampaignsManage,
        bool $canNurtureRead,
        bool $beginnerLanguage
    ): ?array {
        foreach (self::visiblePluginNavigationGroups(
            $canMarketingWrite,
            $canMarketingManage,
            $canCampaignsManage,
            $canNurtureRead,
            $beginnerLanguage
        ) as $group) {
            if ((string) ($group['feature'] ?? '') === $feature) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $pluginAccessByFeature
     * @return array<string,mixed>
     */
    private static function navigationAccessForFeature(array $pluginAccessByFeature, string $feature): array
    {
        if (array_key_exists($feature, $pluginAccessByFeature) && is_array($pluginAccessByFeature[$feature])) {
            return $pluginAccessByFeature[$feature];
        }

        return [
            'can_run' => true,
            'setup_url' => self::defaultSetupUrl($feature),
            'setup_label' => 'Set up ' . self::pluginFamilyLabel($feature),
        ];
    }

    private static function defaultSetupUrl(string $feature): string
    {
        $skillKey = match ($feature) {
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            MarketingMarketplaceGateService::FEATURE_DESIGN => WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            default => WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
        };

        return 'workspace_skills.php?module=' . rawurlencode($skillKey) . '#setup';
    }

    /**
     * @param array{label:string,icon:string,setup_label:string} $group
     * @param array<string,mixed> $access
     */
    private static function desktopSetupItem(array $group, array $access): string
    {
        $href = trim((string) ($access['setup_url'] ?? '')) ?: self::defaultSetupUrl((string) ($group['feature'] ?? ''));
        $label = trim((string) ($access['setup_label'] ?? '')) ?: (string) $group['setup_label'];

        return '<a href="' . self::escape($href) . '" class="nav-dropdown-item marketing-nav-setup-cta">'
            . '<i class="fas fa-plug-circle-plus"></i>'
            . self::escape($label)
            . '</a>';
    }

    /**
     * @param array{label:string,icon:string,setup_label:string} $group
     * @param array<string,mixed> $access
     */
    private static function mobileSetupItem(array $group, array $access): string
    {
        $href = trim((string) ($access['setup_url'] ?? '')) ?: self::defaultSetupUrl((string) ($group['feature'] ?? ''));
        $label = trim((string) ($access['setup_label'] ?? '')) ?: (string) $group['setup_label'];

        return '<a href="' . self::escape($href) . '" class="nav-link marketing-nav-setup-cta">' . self::escape($label) . '</a>';
    }

    /**
     * @return array<int,array{heading:string,items:array<int,array{label:string,href:string,icon:string,requires?:string,class?:string}>}>
     */
    private static function visibleAdvancedNavigationGroups(
        bool $canMarketingWrite,
        bool $canMarketingManage,
        bool $canCampaignsManage,
        bool $canNurtureRead
    ): array {
        $permissions = [
            'marketing_write' => $canMarketingWrite,
            'marketing_manage' => $canMarketingManage,
            'campaigns_manage' => $canCampaignsManage,
            'nurture_read' => $canNurtureRead,
        ];

        $groups = [];
        foreach (self::advancedNavigationGroups() as $group) {
            $items = array_values(array_filter(
                $group['items'],
                static function (array $item) use ($permissions): bool {
                    $required = (string) ($item['requires'] ?? '');

                    return $required === '' || !empty($permissions[$required]);
                }
            ));
            if ($items !== []) {
                $group['items'] = $items;
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param array{label:string,href:string,icon:string,class?:string} $item
     */
    private static function desktopNavItem(array $item): string
    {
        $class = trim('nav-dropdown-item ' . (string) ($item['class'] ?? ''));

        return '<a href="' . self::escape($item['href']) . '" class="' . self::escape($class) . '">'
            . '<i class="fas ' . self::escape($item['icon']) . '"></i>'
            . self::escape($item['label'])
            . '</a>';
    }

    /**
     * @param array{label:string,href:string,icon:string,class?:string} $item
     */
    private static function mobileNavItem(array $item): string
    {
        return '<a href="' . self::escape($item['href']) . '" class="nav-link">' . self::escape($item['label']) . '</a>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function addClassToFirst(string $content, string $existingClass, string $newClass): string
    {
        if (strpos($content, $newClass) !== false || strpos($content, $existingClass) === false) {
            return $content;
        }

        $pattern = '/class="([^"]*\b' . preg_quote($existingClass, '/') . '\b[^"]*)"/';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($existingClass, $newClass): string {
                $classes = preg_split('/\s+/', trim((string) $matches[1])) ?: [];
                if (!in_array($existingClass, $classes, true)) {
                    return (string) $matches[0];
                }
                if (!in_array($newClass, $classes, true)) {
                    $classes[] = $newClass;
                }

                return 'class="' . implode(' ', $classes) . '"';
            },
            $content,
            1
        );
    }

    private static function addClassEverywhere(string $content, string $existingClass, string $newClass): string
    {
        if (strpos($content, $existingClass) === false) {
            return $content;
        }

        $pattern = '/class="([^"]*\b' . preg_quote($existingClass, '/') . '\b[^"]*)"/';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($existingClass, $newClass): string {
                $classes = preg_split('/\s+/', trim((string) $matches[1])) ?: [];
                if (!in_array($existingClass, $classes, true)) {
                    return (string) $matches[0];
                }
                if (!in_array($newClass, $classes, true)) {
                    $classes[] = $newClass;
                }

                return 'class="' . implode(' ', $classes) . '"';
            },
            $content
        );
    }
}
