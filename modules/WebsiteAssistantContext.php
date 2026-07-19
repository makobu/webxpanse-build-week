<?php
/**
 * Website Assistant Context
 * Builds context for the AI chat bubble about product features, navigation, and current page.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Authorization;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIUserWorkContextService;
use CRM\Services\ClarityPageContextService;
use CRM\Services\MarketingPageContextService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

class WebsiteAssistantContext
{
    /**
     * Build context for website assistant Q&A
     *
     * @param int $userId
     * @param string|null $currentPage e.g. contacts.php
     * @return array
     */
    public function build(
        int $userId,
        ?string $currentPage = null,
        ?array $providedOperatingContext = null,
        ?array $providedUserWorkContext = null,
        ?array $providedClarityPageContext = null
    ): array
    {
        $user = \CRM\Auth::user();
        $role = $user['role'] ?? 'user';
        $resolvedPage = $currentPage ?? basename($_SERVER['PHP_SELF'] ?? '');

        $operatingContext = $providedOperatingContext ?? (new AIOperatingContextService())->buildForSurface($userId, 'clarity_chat', [
            'current_page' => $resolvedPage,
        ]);
        $userWorkContext = $providedUserWorkContext ?? (new AIUserWorkContextService())->buildContext($userId, 'clarity_chat', $operatingContext);
        $features = $this->getFeatures($role);
        $stats = $this->getUserStats($userId);
        $marketingPageContext = MarketingPageContextService::contextForPage($resolvedPage);
        $workspaceId = (int) ($operatingContext['identity']['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $clarityPageContext = $providedClarityPageContext ?? (new ClarityPageContextService())->build(
            $workspaceId,
            $userId,
            $resolvedPage,
            $operatingContext
        );

        return [
            'current_page' => $resolvedPage,
            'user_role' => $role,
            'features' => $features,
            'user_stats' => $stats,
            'clarity_page_context' => $clarityPageContext,
            'marketing_page_context' => $marketingPageContext,
            'operating_context' => $operatingContext,
            'user_work_context' => $userWorkContext,
            'effective_mode' => $operatingContext['qualification_state']['effective_mode'] ?? '1',
            'missing_context_flags' => $operatingContext['missing_context_flags'] ?? [],
            'top_goals' => array_map(static fn(array $goal): string => (string) ($goal['title'] ?? ''), (array) ($operatingContext['goal_state']['active_goals'] ?? [])),
        ];
    }

    /**
     * Get features for documentation page (role-aware)
     */
    public function getFeaturesForDocs(int $userId): array
    {
        $user = \CRM\Auth::user();
        $role = $user['role'] ?? 'user';
        return $this->getFeatures($role);
    }

    /**
     * Get platform features with descriptions (role-aware)
     */
    private function getFeatures(string $role): array
    {
        $currentUser = \CRM\Auth::user();
        $moduleVisible = static function (string $skillKey) use ($currentUser): bool {
            $catalog = new WorkspaceSkillCatalogService();
            if ($catalog->isGloballyDeactivated($skillKey)) {
                return false;
            }
            if (Authorization::isSuperAdmin($currentUser)) {
                return true;
            }
            try {
                $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
                return $workspaceId > 0 && (new WorkspaceSkillInstallService())->canExposeRuntimeModule($workspaceId, $skillKey);
            } catch (\Throwable $e) {
                return false;
            }
        };
        $calendarMeetingsInstalled = $moduleVisible(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS);
        $smsInstalled = $moduleVisible(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL);
        $whatsAppInstalled = $moduleVisible(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);

        $base = [
            ['name' => 'Dashboard', 'path' => publicUrl('dashboard.php'), 'desc' => 'Overview of contacts, deals, tasks, and activity'],
            ['name' => 'Contacts', 'path' => publicUrl('contacts.php'), 'desc' => 'Manage leads and customers, add notes, track stages'],
            ['name' => 'Companies', 'path' => publicUrl('companies.php'), 'desc' => 'Manage company records linked to contacts'],
            ['name' => 'Deals', 'path' => publicUrl('deals.php'), 'desc' => 'Sales pipeline, deal stages, values, and forecasting'],
            ['name' => 'Inbox', 'path' => publicUrl('inbox.php'), 'desc' => 'Unified inbox for email, WhatsApp, and SMS'],
            ['name' => 'Tasks', 'path' => publicUrl('tasks.php'), 'desc' => 'Create and manage tasks, set due dates and priorities'],
            ['name' => 'Activities', 'path' => publicUrl('activities.php'), 'desc' => 'Log calls, meetings, notes, and other activities'],
            ['name' => 'Targets', 'path' => publicUrl('targets.php'), 'desc' => 'Set and track sales targets and goals'],
            ['name' => 'Emails', 'path' => publicUrl('emails.php'), 'desc' => 'Send and track emails'],
            ['name' => 'Marketing Command Center', 'path' => publicUrl('marketing.php'), 'desc' => 'Coordinate marketing content, campaigns, forms, and attribution from one dashboard'],
            ['name' => 'Content Studio', 'path' => publicUrl('marketing_content.php'), 'desc' => 'Plan, draft, schedule, and connect content to campaigns and lead capture assets'],
            ['name' => 'Email Templates', 'path' => publicUrl('email_templates.php'), 'desc' => 'Create reusable email templates'],
            ['name' => 'Bulk Email', 'path' => publicUrl('bulk_email.php'), 'desc' => 'Send emails to multiple contacts'],
            ['name' => 'Campaign Automation', 'path' => publicUrl('campaigns.php'), 'desc' => 'Create, launch, pause, resume, and monitor outbound campaigns'],
            ['name' => 'Analytics', 'path' => publicUrl('analytics.php'), 'desc' => 'Reports and analytics dashboard'],
            ['name' => 'Reports', 'path' => publicUrl('reports.php'), 'desc' => 'Create and run custom reports'],
            ['name' => 'Tags', 'path' => publicUrl('tags.php'), 'desc' => 'Organize contacts with tags'],
            ['name' => 'Settings', 'path' => publicUrl('settings.php'), 'desc' => 'Company settings, integrations, and preferences'],
            ['name' => 'Notifications', 'path' => publicUrl('notifications.php'), 'desc' => 'View and manage notifications'],
        ];

        if ($calendarMeetingsInstalled) {
            $base[] = ['name' => 'Calendar', 'path' => publicUrl('calendar.php'), 'desc' => 'View events and schedule meetings'];
        }
        if ($smsInstalled) {
            $base[] = ['name' => 'Bulk SMS', 'path' => publicUrl('bulk_sms.php'), 'desc' => 'Send SMS to multiple contacts'];
        }
        if ($whatsAppInstalled) {
            $base[] = ['name' => 'Bulk WhatsApp', 'path' => publicUrl('bulk_whatsapp.php'), 'desc' => 'Send WhatsApp messages to multiple contacts'];
        }

        $adminFeatures = [];
        if (Authorization::canAccessUsersPage($currentUser)) {
            $adminFeatures[] = ['name' => 'Users', 'path' => publicUrl('users.php'), 'desc' => 'Manage user accounts and roles'];
        }

        if ($role === 'admin') {
            $adminFeatures = array_merge($adminFeatures, [
            ['name' => 'Custom Fields', 'path' => publicUrl('custom_fields.php'), 'desc' => 'Add custom fields to contacts and deals'],
            ['name' => 'Workflows', 'path' => publicUrl('workflows.php'), 'desc' => 'Automate tasks with workflow triggers'],
            ['name' => 'Webhooks', 'path' => publicUrl('webhooks.php'), 'desc' => 'Configure webhooks for integrations'],
            ['name' => 'API Keys', 'path' => publicUrl('api_keys.php'), 'desc' => 'Manage API keys for external access'],
            ['name' => 'Audit Logs', 'path' => publicUrl('audit_logs.php'), 'desc' => 'View system audit trail'],
            ]);
        }

        if ($adminFeatures !== []) {
            return array_merge($base, $adminFeatures);
        }

        return $base;
    }

    /**
     * Get user-relevant stats for context
     */
    private function getUserStats(int $userId): array
    {
        try {
            $contactCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM contacts")['count'];
            $taskCount = (int) Database::queryOne(
                "SELECT COUNT(*) as count FROM tasks WHERE assigned_to = ? OR created_by = ?",
                [$userId, $userId]
            )['count'];
            $dealCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM deals")['count'];

            return [
                'contact_count' => $contactCount,
                'task_count' => $taskCount,
                'deal_count' => $dealCount,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
