<?php

namespace CRM\Services;

use CRM\Database;

class MarketplacePageExplainerService
{
    public const PAGE_ENTRANCE = 'entrance';
    public const PAGE_DASHBOARD = 'dashboard';
    public const PAGE_FOUNDER_OPERATING_LOOP = 'founder_operating_loop';
    public const PAGE_STARTUP_JOURNEY = 'startup_journey';
    public const PAGE_FINANCE = 'finance';
    public const PAGE_TASKS = 'tasks';
    public const PAGE_INBOX = 'inbox';
    public const PAGE_CONTACTS = 'contacts';
    public const PAGE_MARKETPLACE = 'marketplace';
    public const PAGE_NURTURE = 'nurture';
    public const PAGE_DEALS = 'deals';
    public const PAGE_INVOICES = 'invoices';
    public const PAGE_COMPANIES = 'companies';
    public const PAGE_SIGNATURES = 'signatures';
    public const PAGE_FORMS = 'forms';
    public const PAGE_EMAIL_TEMPLATES = 'email_templates';
    public const PAGE_TAGS = 'tags';
    public const PAGE_ANALYTICS = 'analytics';
    public const PAGE_TARGETS = 'targets';
    public const PAGE_WORKFLOWS = 'workflows';
    public const PAGE_CALENDAR = 'calendar';
    public const PAGE_CAMPAIGNS = 'campaigns';
    public const PAGE_REPORTS = 'reports';
    public const PAGE_DOCS = 'docs';
    public const PAGE_USERS = 'users';
    public const PAGE_ROLES = 'roles';
    public const PAGE_WORKSPACE_TEAM = 'workspace_team';
    public const PAGE_AI_CONTROL_CENTER = 'ai_control_center';
    public const PAGE_HR_ANALYTICS = 'hr_analytics';
    public const PAGE_LEAD_SCORING = 'lead_scoring';
    public const PAGE_ENRICHMENT_DASHBOARD = 'enrichment_dashboard';
    public const PAGE_PREDICTIVE_ANALYTICS = 'predictive_analytics';
    public const PAGE_WORKFLOW_ANALYTICS = 'workflow_analytics';
    public const PAGE_ATTRIBUTION_REPORTS = 'attribution_reports';
    public const PAGE_DRAFT_REVIEWS = 'draft_reviews';
    public const PAGE_EMAIL_ASSISTANT_RUNS = 'email_assistant_runs';
    public const PAGE_COMMERCIAL_APPROVALS = 'commercial_approvals';
    public const PAGE_MARKETING_COMMAND_CENTER = 'marketing_command_center';
    public const PAGE_MARKETING_ONBOARDING = 'marketing_onboarding';
    public const PAGE_MARKETING_SYSTEM_MAP = 'marketing_system_map';
    public const PAGE_MARKETING_ACTION_ROUTER = 'marketing_action_router';
    public const PAGE_MARKETING_RELATIONSHIPS = 'marketing_relationships';
    public const PAGE_MARKETING_CONTENT = 'marketing_content';
    public const PAGE_MARKETING_CONTEXT = 'marketing_context';
    public const PAGE_MARKETING_ADMIN = 'marketing_admin';
    public const PAGE_MARKETING_CONTENT_EDIT = 'marketing_content_edit';
    public const PAGE_MARKETING_CONTENT_VIEW = 'marketing_content_view';
    public const PAGE_MARKETING_BRIEFS = 'marketing_briefs';
    public const PAGE_MARKETING_BRIEF_EDIT = 'marketing_brief_edit';
    public const PAGE_MARKETING_BRIEF_VIEW = 'marketing_brief_view';
    public const PAGE_MARKETING_PLAYBOOKS = 'marketing_playbooks';
    public const PAGE_MARKETING_PLAYBOOK_EDIT = 'marketing_playbook_edit';
    public const PAGE_MARKETING_PLAYBOOK_VIEW = 'marketing_playbook_view';
    public const PAGE_MARKETING_SEGMENTS = 'marketing_segments';
    public const PAGE_MARKETING_SEGMENT_EDIT = 'marketing_segment_edit';
    public const PAGE_MARKETING_SEGMENT_VIEW = 'marketing_segment_view';
    public const PAGE_MARKETING_AUDIENCE_ACTIVATION = 'marketing_audience_activation';
    public const PAGE_MARKETING_JOURNEYS = 'marketing_journeys';
    public const PAGE_MARKETING_JOURNEY_EDIT = 'marketing_journey_edit';
    public const PAGE_MARKETING_JOURNEY_VIEW = 'marketing_journey_view';
    public const PAGE_MARKETING_LAUNCH_READINESS = 'marketing_launch_readiness';
    public const PAGE_MARKETING_LAUNCH_CONTROL = 'marketing_launch_control';
    public const PAGE_MARKETING_LAUNCH_CHECKLISTS = 'marketing_launch_checklists';
    public const PAGE_MARKETING_GUIDED_WORKFLOWS = 'marketing_guided_workflows';
    public const PAGE_MARKETING_TASK_HUB = 'marketing_task_hub';
    public const PAGE_MARKETING_CAMPAIGN_WORKSPACE = 'marketing_campaign_workspace';
    public const PAGE_MARKETING_CALENDAR = 'marketing_calendar';
    public const PAGE_MARKETING_REVIEWS = 'marketing_reviews';
    public const PAGE_MARKETING_ROADMAP = 'marketing_roadmap';
    public const PAGE_MARKETING_PERSONA_OFFER_MATRIX = 'marketing_persona_offer_matrix';
    public const PAGE_MARKETING_BRAND = 'marketing_brand';
    public const PAGE_MARKETING_PERSONAS = 'marketing_personas';
    public const PAGE_MARKETING_SEO = 'marketing_seo';
    public const PAGE_MARKETING_LANDING_PAGES = 'marketing_landing_pages';
    public const PAGE_MARKETING_LANDING_PAGE_EDIT = 'marketing_landing_page_edit';
    public const PAGE_MARKETING_LANDING_PAGE_VIEW = 'marketing_landing_page_view';
    public const PAGE_MARKETING_ASSETS = 'marketing_assets';
    public const PAGE_MARKETING_DISTRIBUTION = 'marketing_distribution';
    public const PAGE_MARKETING_DISTRIBUTION_BUNDLE = 'marketing_distribution_bundle';
    public const PAGE_MARKETING_OPERATOR_EXPORT_PACKS = 'marketing_operator_export_packs';
    public const PAGE_MARKETING_CHANNEL_EXPORTS = 'marketing_channel_exports';
    public const PAGE_MARKETING_UTM_LINKS = 'marketing_utm_links';
    public const PAGE_MARKETING_EMAIL_RUNS = 'marketing_email_runs';
    public const PAGE_MARKETING_PERFORMANCE = 'marketing_performance';
    public const PAGE_MARKETING_HANDOFFS = 'marketing_handoffs';
    public const PAGE_MARKETING_WEEKLY_REPORT = 'marketing_weekly_report';
    public const PAGE_MARKETING_MONTHLY_REPORT = 'marketing_monthly_report';
    public const PAGE_MARKETING_ASSISTANTS = 'marketing_assistants';
    public const PAGE_MARKETING_QUALITY = 'marketing_quality';
    public const PAGE_MARKETING_CREATIVE = 'marketing_creative';
    public const PAGE_MARKETING_OPERATIONS = 'marketing_operations';
    public const PAGE_MARKETING_EXECUTION = 'marketing_execution';
    public const PAGE_MARKETING_DECISIONS = 'marketing_decisions';

    public static function settingsPageDefinitions(): array
    {
        return [
            self::PAGE_ENTRANCE => [
                'label' => 'Entrance landing demo',
                'description' => 'Choose the landscape product demo shown on the public entrance landing page.',
                'button_context' => 'public entrance demo player',
            ],
            self::PAGE_DASHBOARD => [
                'label' => 'Dashboard page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button on the dashboard hero.',
                'button_context' => 'dashboard Watch guide button',
            ],
            self::PAGE_TASKS => [
                'label' => 'Tasks page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside the Tasks filters.',
                'button_context' => 'Tasks Watch guide button',
            ],
            self::PAGE_INBOX => [
                'label' => 'Inbox page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside the Inbox filters.',
                'button_context' => 'Inbox Watch guide button',
            ],
            self::PAGE_CONTACTS => [
                'label' => 'Contacts page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside the Contacts stage filters.',
                'button_context' => 'Contacts Watch guide button',
            ],
            self::PAGE_MARKETPLACE => [
                'label' => 'Marketplace page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Marketplace filters.',
                'button_context' => 'Marketplace Watch guide button',
            ],
            self::PAGE_NURTURE => [
                'label' => 'Customer Nurture page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Customer Nurture filters.',
                'button_context' => 'Customer Nurture Watch guide button',
            ],
            self::PAGE_DEALS => [
                'label' => 'Deals page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Deals filters.',
                'button_context' => 'Deals Watch guide button',
            ],
            self::PAGE_INVOICES => [
                'label' => 'Invoices & Quotes page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Invoices & Quotes filters.',
                'button_context' => 'Invoices & Quotes Watch guide button',
            ],
            self::PAGE_COMPANIES => [
                'label' => 'Companies page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Companies filters.',
                'button_context' => 'Companies Watch guide button',
            ],
            self::PAGE_SIGNATURES => [
                'label' => 'Email Signatures page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Email Signatures actions.',
                'button_context' => 'Email Signatures Watch guide button',
            ],
            self::PAGE_FORMS => [
                'label' => 'Forms page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Forms actions.',
                'button_context' => 'Forms Watch guide button',
            ],
            self::PAGE_EMAIL_TEMPLATES => [
                'label' => 'Email Templates page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Email Templates filters.',
                'button_context' => 'Email Templates Watch guide button',
            ],
            self::PAGE_TAGS => [
                'label' => 'Tags page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Tags actions.',
                'button_context' => 'Tags Watch guide button',
            ],
            self::PAGE_ANALYTICS => [
                'label' => 'Analytics page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Analytics filters.',
                'button_context' => 'Analytics Watch guide button',
            ],
            self::PAGE_TARGETS => [
                'label' => 'Targets page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Targets actions.',
                'button_context' => 'Targets Watch guide button',
            ],
            self::PAGE_WORKFLOWS => [
                'label' => 'Workflows page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Workflows actions.',
                'button_context' => 'Workflows Watch guide button',
            ],
            self::PAGE_CALENDAR => [
                'label' => 'Calendar page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Calendar actions.',
                'button_context' => 'Calendar Watch guide button',
            ],
            self::PAGE_CAMPAIGNS => [
                'label' => 'Campaign Automation page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Campaign Automation actions.',
                'button_context' => 'Campaign Automation Watch guide button',
            ],
            self::PAGE_REPORTS => [
                'label' => 'Reports page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Reports actions.',
                'button_context' => 'Reports Watch guide button',
            ],
            self::PAGE_DOCS => [
                'label' => 'Feature Documentation page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Documentation actions.',
                'button_context' => 'Documentation Watch guide button',
            ],
            self::PAGE_USERS => [
                'label' => 'Users page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Users actions.',
                'button_context' => 'Users Watch guide button',
            ],
            self::PAGE_ROLES => [
                'label' => 'Access Profiles page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Access Profiles actions.',
                'button_context' => 'Access Profiles Watch guide button',
            ],
            self::PAGE_WORKSPACE_TEAM => [
                'label' => 'Workspace Team onboarding guide',
                'description' => 'Upload the short onboarding explainer that appears in the Workspace Team settings tab.',
                'button_context' => 'Workspace Team Watch guide button',
            ],
            self::PAGE_AI_CONTROL_CENTER => [
                'label' => 'AI Control Center page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside AI Control Center actions.',
                'button_context' => 'AI Control Center Watch guide button',
            ],
            self::PAGE_HR_ANALYTICS => [
                'label' => 'Organization Intelligence page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Organization Intelligence actions.',
                'button_context' => 'Organization Intelligence Watch guide button',
            ],
            self::PAGE_LEAD_SCORING => [
                'label' => 'Lead Scoring page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Lead Scoring actions.',
                'button_context' => 'Lead Scoring Watch guide button',
            ],
            self::PAGE_ENRICHMENT_DASHBOARD => [
                'label' => 'Enrichment Dashboard page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Enrichment Dashboard actions.',
                'button_context' => 'Enrichment Dashboard Watch guide button',
            ],
            self::PAGE_PREDICTIVE_ANALYTICS => [
                'label' => 'Predictive Analytics page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Predictive Analytics actions.',
                'button_context' => 'Predictive Analytics Watch guide button',
            ],
            self::PAGE_WORKFLOW_ANALYTICS => [
                'label' => 'Workflow Analytics page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Workflow Analytics filters.',
                'button_context' => 'Workflow Analytics Watch guide button',
            ],
            self::PAGE_ATTRIBUTION_REPORTS => [
                'label' => 'Attribution Reports page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Attribution Reports filters.',
                'button_context' => 'Attribution Reports Watch guide button',
            ],
            self::PAGE_DRAFT_REVIEWS => [
                'label' => 'Draft Reviews page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Draft Reviews actions.',
                'button_context' => 'Draft Reviews Watch guide button',
            ],
            self::PAGE_EMAIL_ASSISTANT_RUNS => [
                'label' => 'Email Assistant Runs page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Email Assistant Runs actions.',
                'button_context' => 'Email Assistant Runs Watch guide button',
            ],
            self::PAGE_COMMERCIAL_APPROVALS => [
                'label' => 'Commercial Approvals page guide',
                'description' => 'Upload the short explainer that appears as a Watch guide button beside Commercial Approvals actions.',
                'button_context' => 'Commercial Approvals Watch guide button',
            ],
        ] + self::marketingSettingsPageDefinitions();
    }

    public static function marketingSettingsPageDefinitions(): array
    {
        return [
            self::PAGE_MARKETING_COMMAND_CENTER => self::pageVideoDefinition('Marketing Command Center page guide', 'Marketing Command Center'),
            self::PAGE_MARKETING_ONBOARDING => self::pageVideoDefinition('Marketing Setup page guide', 'Marketing Setup'),
            self::PAGE_MARKETING_SYSTEM_MAP => self::pageVideoDefinition('Marketing System Map page guide', 'Marketing System Map'),
            self::PAGE_MARKETING_ACTION_ROUTER => self::pageVideoDefinition('Marketing Action Router page guide', 'Marketing Action Router'),
            self::PAGE_MARKETING_RELATIONSHIPS => self::pageVideoDefinition('Marketing Relationship Graph page guide', 'Marketing Relationship Graph'),
            self::PAGE_MARKETING_CONTENT => self::pageVideoDefinition('Content Studio page guide', 'Content Studio'),
            self::PAGE_MARKETING_CONTEXT => self::pageVideoDefinition('Marketing Context Hub page guide', 'Marketing Context Hub'),
            self::PAGE_MARKETING_ADMIN => self::pageVideoDefinition('Marketing Admin Diagnostics page guide', 'Marketing Admin Diagnostics'),
            self::PAGE_MARKETING_CONTENT_EDIT => self::pageVideoDefinition('Marketing Content Editor page guide', 'Marketing Content Editor'),
            self::PAGE_MARKETING_CONTENT_VIEW => self::pageVideoDefinition('Marketing Content Detail page guide', 'Marketing Content Detail'),
            self::PAGE_MARKETING_BRIEFS => self::pageVideoDefinition('Campaign Briefs page guide', 'Campaign Briefs'),
            self::PAGE_MARKETING_BRIEF_EDIT => self::pageVideoDefinition('Campaign Brief Editor page guide', 'Campaign Brief Editor'),
            self::PAGE_MARKETING_BRIEF_VIEW => self::pageVideoDefinition('Campaign Brief Detail page guide', 'Campaign Brief Detail'),
            self::PAGE_MARKETING_PLAYBOOKS => self::pageVideoDefinition('Campaign Playbooks page guide', 'Campaign Playbooks'),
            self::PAGE_MARKETING_PLAYBOOK_EDIT => self::pageVideoDefinition('Campaign Playbook Editor page guide', 'Campaign Playbook Editor'),
            self::PAGE_MARKETING_PLAYBOOK_VIEW => self::pageVideoDefinition('Campaign Playbook Detail page guide', 'Campaign Playbook Detail'),
            self::PAGE_MARKETING_SEGMENTS => self::pageVideoDefinition('Audience Segments page guide', 'Audience Segments'),
            self::PAGE_MARKETING_SEGMENT_EDIT => self::pageVideoDefinition('Audience Segment Editor page guide', 'Audience Segment Editor'),
            self::PAGE_MARKETING_SEGMENT_VIEW => self::pageVideoDefinition('Audience Segment Detail page guide', 'Audience Segment Detail'),
            self::PAGE_MARKETING_AUDIENCE_ACTIVATION => self::pageVideoDefinition('Audience Activation page guide', 'Audience Activation'),
            self::PAGE_MARKETING_JOURNEYS => self::pageVideoDefinition('Marketing Journeys page guide', 'Marketing Journeys'),
            self::PAGE_MARKETING_JOURNEY_EDIT => self::pageVideoDefinition('Marketing Journey Editor page guide', 'Marketing Journey Editor'),
            self::PAGE_MARKETING_JOURNEY_VIEW => self::pageVideoDefinition('Marketing Journey Detail page guide', 'Marketing Journey Detail'),
            self::PAGE_MARKETING_LAUNCH_READINESS => self::pageVideoDefinition('Marketing Launch Readiness page guide', 'Marketing Launch Readiness'),
            self::PAGE_MARKETING_LAUNCH_CONTROL => self::pageVideoDefinition('Marketing Launch Control Room page guide', 'Marketing Launch Control Room'),
            self::PAGE_MARKETING_LAUNCH_CHECKLISTS => self::pageVideoDefinition('Campaign Launch Checklists page guide', 'Campaign Launch Checklists'),
            self::PAGE_MARKETING_GUIDED_WORKFLOWS => self::pageVideoDefinition('Marketing Guided Workflows page guide', 'Marketing Guided Workflows'),
            self::PAGE_MARKETING_TASK_HUB => self::pageVideoDefinition('Marketing Task Hub page guide', 'Marketing Task Hub'),
            self::PAGE_MARKETING_CAMPAIGN_WORKSPACE => self::pageVideoDefinition('Marketing Campaign Workspace page guide', 'Marketing Campaign Workspace'),
            self::PAGE_MARKETING_CALENDAR => self::pageVideoDefinition('Marketing Calendar page guide', 'Marketing Calendar'),
            self::PAGE_MARKETING_REVIEWS => self::pageVideoDefinition('Marketing Approval Workbench page guide', 'Marketing Approval Workbench'),
            self::PAGE_MARKETING_ROADMAP => self::pageVideoDefinition('Campaign Roadmap page guide', 'Campaign Roadmap'),
            self::PAGE_MARKETING_PERSONA_OFFER_MATRIX => self::pageVideoDefinition('Persona Offer Matrix page guide', 'Persona Offer Matrix'),
            self::PAGE_MARKETING_BRAND => self::pageVideoDefinition('Brand Library page guide', 'Brand Library'),
            self::PAGE_MARKETING_PERSONAS => self::pageVideoDefinition('Personas page guide', 'Personas'),
            self::PAGE_MARKETING_SEO => self::pageVideoDefinition('SEO Topics page guide', 'SEO Topics'),
            self::PAGE_MARKETING_LANDING_PAGES => self::pageVideoDefinition('Landing Page Plans page guide', 'Landing Page Plans'),
            self::PAGE_MARKETING_LANDING_PAGE_EDIT => self::pageVideoDefinition('Landing Page Editor page guide', 'Landing Page Editor'),
            self::PAGE_MARKETING_LANDING_PAGE_VIEW => self::pageVideoDefinition('Landing Page Detail page guide', 'Landing Page Detail'),
            self::PAGE_MARKETING_ASSETS => self::pageVideoDefinition('Marketing Assets page guide', 'Marketing Assets'),
            self::PAGE_MARKETING_DISTRIBUTION => self::pageVideoDefinition('Distribution Queue page guide', 'Distribution Queue'),
            self::PAGE_MARKETING_DISTRIBUTION_BUNDLE => self::pageVideoDefinition('Export Bundle page guide', 'Export Bundle'),
            self::PAGE_MARKETING_OPERATOR_EXPORT_PACKS => self::pageVideoDefinition('Operator Export Packs page guide', 'Operator Export Packs'),
            self::PAGE_MARKETING_CHANNEL_EXPORTS => self::pageVideoDefinition('Channel Export Bundles page guide', 'Channel Export Bundles'),
            self::PAGE_MARKETING_UTM_LINKS => self::pageVideoDefinition('UTM Links page guide', 'UTM Links'),
            self::PAGE_MARKETING_EMAIL_RUNS => self::pageVideoDefinition('Marketing Email Runs page guide', 'Marketing Email Runs'),
            self::PAGE_MARKETING_PERFORMANCE => self::pageVideoDefinition('Marketing Performance page guide', 'Marketing Performance'),
            self::PAGE_MARKETING_HANDOFFS => self::pageVideoDefinition('Lead Handoffs page guide', 'Lead Handoffs'),
            self::PAGE_MARKETING_WEEKLY_REPORT => self::pageVideoDefinition('Weekly Marketing Report page guide', 'Weekly Marketing Report'),
            self::PAGE_MARKETING_MONTHLY_REPORT => self::pageVideoDefinition('Monthly Marketing Report page guide', 'Monthly Marketing Report'),
            self::PAGE_MARKETING_ASSISTANTS => self::pageVideoDefinition('Marketing Assistants page guide', 'Marketing Assistants'),
            self::PAGE_MARKETING_QUALITY => self::pageVideoDefinition('Marketing Quality Checks page guide', 'Marketing Quality Checks'),
            self::PAGE_MARKETING_CREATIVE => self::pageVideoDefinition('Creative Production page guide', 'Creative Production'),
            self::PAGE_MARKETING_OPERATIONS => self::pageVideoDefinition('Marketing Operations page guide', 'Marketing Operations'),
            self::PAGE_MARKETING_EXECUTION => self::pageVideoDefinition('Marketing Execution Control Center page guide', 'Marketing Execution Control Center'),
            self::PAGE_MARKETING_DECISIONS => self::pageVideoDefinition('Marketing Decision Center page guide', 'Marketing Decision Center'),
        ];
    }

    private static function pageVideoDefinition(string $label, string $pageName): array
    {
        return [
            'label' => $label,
            'description' => 'Upload the short explainer that appears as a Watch guide button beside ' . $pageName . ' actions.',
            'button_context' => $pageName . ' Watch guide button',
        ];
    }

    public function get(string $pageKey): ?array
    {
        $pageKey = $this->normalizePageKey($pageKey);
        if ($pageKey === '' || !$this->tableReady()) {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM marketplace_page_explainers WHERE page_key = ? LIMIT 1",
            [$pageKey]
        ) ?: null;
    }

    public function getActive(string $pageKey): ?array
    {
        $pageKey = $this->normalizePageKey($pageKey);
        if ($pageKey === '' || !$this->tableReady()) {
            return null;
        }

        if (Database::columnExists('marketplace_page_explainers', 'video_asset_id') && Database::tableExists('marketplace_video_assets')) {
            $row = Database::queryOne(
                "SELECT e.*,
                        COALESCE(NULLIF(a.stored_path, ''), e.video_url) AS video_url,
                        a.id AS video_asset_id,
                        a.title AS video_asset_title,
                        a.original_filename AS video_asset_original_filename,
                        a.mime_type AS video_asset_mime_type,
                        a.created_at AS video_asset_created_at,
                        a.updated_at AS video_asset_updated_at
                 FROM marketplace_page_explainers e
                 LEFT JOIN marketplace_video_assets a ON a.id = e.video_asset_id
                 WHERE e.page_key = ?
                   AND e.is_active = 1
                   AND COALESCE(NULLIF(a.stored_path, ''), NULLIF(e.video_url, '')) IS NOT NULL
                 LIMIT 1",
                [$pageKey]
            );

            return $row ?: null;
        }

        $row = Database::queryOne(
            "SELECT * FROM marketplace_page_explainers
             WHERE page_key = ? AND is_active = 1 AND video_url IS NOT NULL AND video_url <> ''
             LIMIT 1",
            [$pageKey]
        );

        return $row ?: null;
    }

    public function save(string $pageKey, string $label, string $videoUrl, bool $isActive, int $userId): array
    {
        $pageKey = $this->normalizePageKey($pageKey);
        if ($pageKey === '') {
            throw new \InvalidArgumentException('Page key is required.');
        }
        if (!$this->tableReady()) {
            throw new \RuntimeException('Marketplace page explainer table is not installed.');
        }

        $label = $this->sanitizeString($label, 160);
        if ($label === '') {
            $label = ucwords(str_replace('_', ' ', $pageKey));
        }
        $videoUrl = $this->sanitizeString($videoUrl, 500);

        if (Database::columnExists('marketplace_page_explainers', 'video_asset_id')) {
            Database::execute(
                "INSERT INTO marketplace_page_explainers (
                    page_key, label, video_url, video_asset_id, is_active, updated_by_user_id
                 ) VALUES (?, ?, ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    video_url = VALUES(video_url),
                    video_asset_id = NULL,
                    is_active = VALUES(is_active),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = NOW()",
                [
                    $pageKey,
                    $label,
                    $videoUrl !== '' ? $videoUrl : null,
                    $isActive ? 1 : 0,
                    $userId > 0 ? $userId : null,
                ]
            );

            return $this->get($pageKey) ?? [
                'page_key' => $pageKey,
                'label' => $label,
                'video_url' => $videoUrl,
                'video_asset_id' => null,
                'is_active' => $isActive ? 1 : 0,
            ];
        }

        Database::execute(
            "INSERT INTO marketplace_page_explainers (
                page_key, label, video_url, is_active, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                video_url = VALUES(video_url),
                is_active = VALUES(is_active),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $pageKey,
                $label,
                $videoUrl !== '' ? $videoUrl : null,
                $isActive ? 1 : 0,
                $userId > 0 ? $userId : null,
            ]
        );

        return $this->get($pageKey) ?? [
            'page_key' => $pageKey,
            'label' => $label,
            'video_url' => $videoUrl,
            'is_active' => $isActive ? 1 : 0,
        ];
    }

    public function tableReady(): bool
    {
        return Database::tableExists('marketplace_page_explainers');
    }

    private function normalizePageKey(string $pageKey): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($pageKey)) ?? '', '_');
    }

    private function sanitizeString(string $value, int $maxLength): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}
