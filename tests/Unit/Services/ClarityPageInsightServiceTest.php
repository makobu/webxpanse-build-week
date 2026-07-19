<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\ClarityPageInsightService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\WorkspaceLaunchChecklistService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class ClarityPageInsightServiceTest extends DatabaseTestCase
{
    public function testContactsPageWithZeroContactsReturnsUsefulNoLinkGuidance(): void
    {
        $seed = $this->seedWorkspace('clarity-empty-contacts');
        $this->activateSession($seed, 'owner');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'contacts.php'
        );

        $this->assertSame('contacts_empty_guidance', $insight['kind'] ?? null);
        $this->assertSame('Make Contacts Useful First', $insight['title'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertArrayNotHasKey('cta_url', $insight);
        $this->assertStringContainsString('5-10 real', implode(' ', (array) ($insight['bullets'] ?? [])));
    }

    public function testContactsPageReturnsDataQualityAdviceWithoutTaskLink(): void
    {
        $seed = $this->seedWorkspace('clarity-leads');
        $this->activateSession($seed, 'owner');
        $this->insertContact((int) $seed['workspace_id'], 'New', 'Lead', 'new', (int) $seed['user_id'], null, null, 75, '-9 days');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'leads.php'
        );

        $this->assertSame('contacts_data_quality_advice', $insight['kind'] ?? null);
        $this->assertSame('Complete The Lead Details', $insight['title'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertArrayNotHasKey('cta_url', $insight);
        $this->assertNotEmpty($insight['bullets'] ?? []);
        $this->assertStringContainsString('missing', strtolower(implode(' ', (array) ($insight['bullets'] ?? []))));
    }

    public function testContactsPageReturnsLeadMomentumAdvice(): void
    {
        $seed = $this->seedWorkspace('clarity-leads-momentum');
        $this->activateSession($seed, 'owner');
        $this->insertContact((int) $seed['workspace_id'], 'Hot', 'Lead', 'qualified', (int) $seed['user_id'], 'hot@example.test', 'Hot Co', 85, '-9 days', '555-0100');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'contacts.php'
        );

        $this->assertSame('contacts_lead_momentum_advice', $insight['kind'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertStringContainsString('momentum', strtolower((string) ($insight['title'] ?? '')));
    }

    public function testDashboardDoesNotUseUnreadNotificationsAsMainInsight(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard');
        $this->activateSession($seed, 'owner');
        $this->insertContact((int) $seed['workspace_id'], 'Stale', 'Lead', 'new', null, '', '', 0, '-10 days');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $this->assertSame('dashboard_setup_advice', $insight['kind'] ?? null);
        $this->assertStringNotContainsString('unread notification', strtolower(json_encode($insight)));
        $this->assertNotEmpty($insight['bullets'] ?? []);
    }

    public function testDashboardSetupAdviceBeatsOverdueTaskAdvice(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-setup-first');
        $this->activateSession($seed, 'owner');
        $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Call overdue buyer', 'urgent', '-2 days');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $this->assertSame('dashboard_setup_advice', $insight['kind'] ?? null);
        $this->assertStringNotContainsString('Call overdue buyer', (string) ($insight['title'] ?? ''));
        $this->assertStringContainsString('Setup comes first', implode(' ', (array) ($insight['bullets'] ?? [])));
    }

    public function testDashboardOfferPricingSetupOpensProductsSettings(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-offer-pricing');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklistExcept($workspaceId, $userId, ['define_offer_pricing']);

        $insight = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, 'dashboard.php');

        $this->assertSame('dashboard_setup_advice', $insight['kind'] ?? null);
        $this->assertSame('Define offer and pricing', $insight['title'] ?? null);
        $this->assertSame('Open Setup', $insight['cta_label'] ?? null);
        $this->assertStringContainsString('settings.php?tab=products', (string) ($insight['cta_url'] ?? ''));
        $this->assertStringContainsString('source=clarity_chat', (string) ($insight['cta_url'] ?? ''));
        $this->assertStringNotContainsString('startup_journey.php', (string) ($insight['cta_url'] ?? ''));
    }

    public function testDashboardOfferPricingSetupIsSkippedWhenPricedOfferExists(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-priced-offer');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklistExcept($workspaceId, $userId, ['define_offer_pricing']);
        $this->insertPricedProduct($workspaceId);

        $summary = (new WorkspaceLaunchChecklistService())->summary($workspaceId);
        $offerItem = $this->launchChecklistItem($summary, 'define_offer_pricing');
        $insight = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, 'dashboard.php');

        $this->assertTrue((bool) ($offerItem['complete'] ?? false));
        $this->assertTrue((bool) ($offerItem['virtual_complete'] ?? false));
        $this->assertNotSame('Define offer and pricing', $insight['title'] ?? null);
        $this->assertStringNotContainsString('settings.php?tab=products', (string) ($insight['cta_url'] ?? ''));
    }

    public function testDashboardConnectInboxAdviceClearsOnlyAfterInboundEmailIsReady(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-email-ready');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklistExcept($workspaceId, $userId, ['connect_inbox']);

        $email = new EmailIntegrationService();
        $email->storeManualMailIntegrationForRole('outreach', $userId, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => false,
        ], $workspaceId);

        $smtpOnly = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, 'dashboard.php');
        $this->assertSame('dashboard_setup_advice', $smtpOnly['kind'] ?? null);
        $this->assertSame('Connect inbox', $smtpOnly['title'] ?? null);

        $email->storeManualMailIntegrationForRole('outreach', $userId, [
            'from_email' => 'outreach@example.test',
            'smtp_host' => 'smtp.workspace.test',
            'smtp_username' => 'outreach@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.workspace.test',
            'imap_username' => 'outreach@example.test',
            'imap_password' => 'imap-secret',
        ], $workspaceId);

        $ready = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, 'dashboard.php');
        $this->assertNotSame('Connect inbox', $ready['title'] ?? null);
    }

    public function testCriticalCustomerRiskCanBeatSetupAdvice(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-critical');
        $this->activateSession($seed, 'owner');
        $contactId = $this->insertContact((int) $seed['workspace_id'], 'Urgent', 'Buyer', 'qualified', (int) $seed['user_id'], 'urgent@example.test', 'Urgent Co', 90, '-1 days');
        $taskId = $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Reply to urgent buyer', 'urgent', '-1 days', $contactId, ['task_intent' => 'follow_up']);
        $this->insertNotification((int) $seed['workspace_id'], (int) $seed['user_id'], 'task', $taskId, 'Buyer escalation', 'A customer response is waiting.', 'urgent');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $this->assertSame('dashboard_task_advice', $insight['kind'] ?? null);
        $this->assertSame('Reply to urgent buyer', $insight['title'] ?? null);
        $this->assertSame('Open Task', $insight['cta_label'] ?? null);
        $this->assertStringContainsString('task_view.php?id=' . $taskId, (string) ($insight['cta_url'] ?? ''));
    }

    public function testDashboardSelectsOneTimeSensitiveTaskAdvice(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-task');
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklist((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->clearInstalledSkills((int) $seed['workspace_id']);
        $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Lower priority due today', 'low', 'today');
        $taskId = $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Call overdue buyer', 'urgent', '-2 days');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $this->assertSame('dashboard_task_advice', $insight['kind'] ?? null);
        $this->assertSame('Call overdue buyer', $insight['title'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertArrayNotHasKey('cta_url', $insight);
        $this->assertStringNotContainsString('overdue tasks', strtolower(json_encode($insight)));
        $this->assertSame($taskId, (int) ($insight['task_id'] ?? 0));
    }

    public function testDashboardNotificationStrengthensTaskWithoutUnreadCounter(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-notification');
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklist((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->clearInstalledSkills((int) $seed['workspace_id']);
        $contactId = $this->insertContact((int) $seed['workspace_id'], 'Priority', 'Buyer', 'qualified', (int) $seed['user_id'], 'buyer@example.test', 'Buyer Co', 85, '-1 days');
        $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Follow up with Priority Buyer', 'medium', 'today', $contactId);
        $this->insertNotification((int) $seed['workspace_id'], (int) $seed['user_id'], 'contact', $contactId, 'Buyer replied', 'Review the latest customer response.', 'high');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $encoded = strtolower((string) json_encode($insight));
        $this->assertSame('dashboard_task_advice', $insight['kind'] ?? null);
        $this->assertSame('Follow up with Priority Buyer', $insight['title'] ?? null);
        $this->assertStringContainsString('buyer replied', $encoded);
        $this->assertStringContainsString('high-score lead', $encoded);
        $this->assertStringNotContainsString('unread notifications', $encoded);
    }

    public function testDashboardAiSetupTaskIsAdviceNotStarterTaskList(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-setup');
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklist((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->clearInstalledSkills((int) $seed['workspace_id']);
        $this->insertTask(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'Product Pricing Setup',
            'low',
            null,
            null,
            ['source_surface' => 'ai_coach', 'task_intent' => 'pricing']
        );

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $encoded = strtolower((string) json_encode($insight));
        $this->assertSame('dashboard_task_advice', $insight['kind'] ?? null);
        $this->assertSame('Product Pricing Setup', $insight['title'] ?? null);
        $this->assertStringContainsString('setup blocker', $encoded);
        $this->assertStringNotContainsString('starter tasks ready', $encoded);
    }

    public function testDashboardInstalledSkillSetupAdviceBeatsTaskAdvice(): void
    {
        $seed = $this->seedWorkspace('clarity-dashboard-skill-setup');
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklist((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->installSkillForTest((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT);
        $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Lower priority due today', 'low', 'today');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'dashboard.php'
        );

        $this->assertSame('dashboard_skill_setup_advice', $insight['kind'] ?? null);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $insight['skill_key'] ?? null);
        $this->assertStringContainsString('source=clarity_chat', (string) ($insight['cta_url'] ?? ''));
    }

    public function testMarketplaceInsightReturnsOneVisualRecommendationAndRecordsImpression(): void
    {
        $seed = $this->seedWorkspace('clarity-market');
        $this->activateSession($seed, 'owner');
        $this->setCommunicationChannel((int) $seed['workspace_id'], 'whatsapp');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'workspace_skills.php'
        );

        $this->assertSame('marketplace_plugin', $insight['kind'] ?? null);
        $this->assertNotEmpty($insight['thumbnail_url'] ?? '');
        $this->assertNotEmpty($insight['thumbnail_alt'] ?? '');
        $this->assertContains((string) ($insight['cta_label'] ?? ''), ['Finish Setup', 'Install Recommended Module']);
        $this->assertNotEmpty($insight['cta_url'] ?? '');
        $this->assertStringContainsString('source=clarity_chat', (string) ($insight['cta_url'] ?? ''));
        $this->assertStringNotContainsString('Recommended Bundle', (string) json_encode($insight));
        $this->assertStringNotContainsString('View Bundle', (string) json_encode($insight));

        $pluginEvents = (new WorkspaceMarketplaceRecommendationEventService())->getEvents([
            'workspace_id' => (int) $seed['workspace_id'],
            'user_id' => (int) $seed['user_id'],
            'surface' => 'clarity_chat',
            'event_type' => 'impression',
        ]);

        $this->assertGreaterThanOrEqual(1, count($pluginEvents));
    }

    public function testMarketplaceInsightPrioritizesIncompleteInstalledSetup(): void
    {
        $seed = $this->seedWorkspace('clarity-market-incomplete-installed');
        $this->activateSession($seed, 'owner');
        $this->completeLaunchChecklist((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->installSkillForTest((int) $seed['workspace_id'], (int) $seed['user_id'], WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT);

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'workspace_skills.php'
        );

        $this->assertSame('marketplace_plugin', $insight['kind'] ?? null);
        $this->assertSame('Finish Setup', $insight['cta_label'] ?? null);
        $this->assertStringNotContainsString('Install Recommended Module', (string) json_encode($insight));
        $this->assertStringNotContainsString('Recommended Bundle', (string) json_encode($insight));
    }

    public function testGeneralFallbackHasNoDefaultDashboardLink(): void
    {
        $seed = $this->seedWorkspace('clarity-general');
        $this->activateSession($seed, 'owner');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'reports.php'
        );

        $this->assertSame('general', $insight['kind'] ?? null);
        $this->assertSame('Make This Page More Useful', $insight['title'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertArrayNotHasKey('cta_url', $insight);
    }

    public function testDealsPageReturnsPageSpecificGuidance(): void
    {
        $seed = $this->seedWorkspace('clarity-deals');
        $this->activateSession($seed, 'owner');
        $this->insertDeal((int) $seed['workspace_id'], (int) $seed['user_id'], 'Retainer proposal', 'proposal', 0, null, '-8 days');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'deals.php'
        );

        $this->assertSame('deals_data_quality_advice', $insight['kind'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertStringContainsString('pipeline', strtolower((string) ($insight['body'] ?? '')));
    }

    public function testTasksPageReturnsPageSpecificGuidanceWithoutRoutineTaskLink(): void
    {
        $seed = $this->seedWorkspace('clarity-tasks');
        $this->activateSession($seed, 'owner');
        $this->insertTask((int) $seed['workspace_id'], (int) $seed['user_id'], 'Clean up proposal notes', 'medium', '-1 day');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'tasks.php'
        );

        $this->assertSame('tasks_focus_advice', $insight['kind'] ?? null);
        $this->assertArrayNotHasKey('cta_label', $insight);
        $this->assertStringContainsString('overdue', strtolower(json_encode($insight)));
    }

    public function testTasksPagePrioritizesActiveAiStarterTasksInClarity(): void
    {
        $seed = $this->seedWorkspace('clarity-tasks-starter');
        $this->activateSession($seed, 'owner');
        $firstTaskId = $this->insertTask(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'Send more outreach emails this week',
            'high',
            '+1 day',
            null,
            ['source_surface' => 'ai_coach', 'source_recommendation_type' => 'coach']
        );
        $this->insertTask(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'Clean up proposal notes',
            'medium',
            '-1 day'
        );

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'tasks.php'
        );

        $encoded = strtolower((string) json_encode($insight));
        $this->assertSame('tasks_starter_tasks', $insight['kind'] ?? null);
        $this->assertSame('Starter Tasks Ready', $insight['title'] ?? null);
        $this->assertStringContainsString('send more outreach emails this week', $encoded);
        $this->assertSame('Open First Starter Task', $insight['cta_label'] ?? null);
        $this->assertStringContainsString('task_view.php?id=' . $firstTaskId, (string) ($insight['cta_url'] ?? ''));
    }

    public function testSettingsPageReturnsSetupGuidance(): void
    {
        $seed = $this->seedWorkspace('clarity-settings');
        $this->activateSession($seed, 'owner');

        $insight = (new ClarityPageInsightService())->openingInsight(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            'settings.php'
        );

        $this->assertContains($insight['kind'] ?? '', ['settings_setup_advice', 'dashboard_skill_setup_advice']);
        if (($insight['kind'] ?? '') === 'settings_setup_advice') {
            $this->assertArrayNotHasKey('cta_label', $insight);
        }
        $this->assertNotEmpty($insight['bullets'] ?? []);
    }

    private function insertContact(
        int $workspaceId,
        string $firstName,
        string $lastName,
        string $stage,
        ?int $assignedTo,
        ?string $email,
        ?string $company,
        int $leadScore,
        string $createdOffset,
        string $phone = ''
    ): int {
        $daysAgo = abs((int) $createdOffset);
        $timestamp = date('Y-m-d H:i:s', strtotime('-' . $daysAgo . ' days'));
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, company, stage, assigned_to, lead_score, created_at, updated_at)
             VALUES (?, UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $firstName,
                $lastName,
                $email,
                $phone,
                $company,
                $stage,
                $assignedTo,
                $leadScore,
                $timestamp,
                $timestamp,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function insertTask(
        int $workspaceId,
        int $userId,
        string $title,
        string $priority,
        ?string $dueOffset,
        ?int $contactId = null,
        array $metadata = []
    ): int {
        $dueDate = null;
        if ($dueOffset !== null) {
            $dueDate = $dueOffset === 'today'
                ? date('Y-m-d 12:00:00')
                : date('Y-m-d H:i:s', strtotime($dueOffset));
        }

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, description, contact_id, assigned_to, created_by, status, priority, due_date, metadata_json, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())",
            [
                $workspaceId,
                $title,
                $contactId,
                $userId,
                $userId,
                $priority,
                $dueDate,
                $metadata !== [] ? json_encode($metadata) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function insertNotification(
        int $workspaceId,
        int $userId,
        string $entityType,
        int $entityId,
        string $title,
        string $message,
        string $severity
    ): void {
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, severity, is_read, created_at)
             VALUES (?, ?, 'task_context', ?, ?, ?, ?, ?, 0, NOW())",
            [$workspaceId, $userId, $title, $message, $entityType, $entityId, $severity]
        );
    }

    private function insertPricedProduct(int $workspaceId): int
    {
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, pricing_info, target_audience, unit_price, is_active, display_order, created_at, updated_at)
             VALUES (?, 'Launch offer', 'A specific first offer.', 'Services', 'Starts at 500 USD', 'Founder-led teams', 500, 1, 1, NOW(), NOW())",
            [$workspaceId]
        );

        return (int) Database::lastInsertId();
    }

    private function insertDeal(
        int $workspaceId,
        int $userId,
        string $title,
        string $stage,
        int $value,
        ?string $expectedCloseDate,
        string $createdOffset
    ): int {
        $timestamp = date('Y-m-d H:i:s', strtotime($createdOffset));
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, assigned_to, created_by, stage, value, expected_close_date, currency, created_at, updated_at)
             VALUES (?, ?, '', ?, ?, ?, ?, ?, 'USD', ?, ?)",
            [
                $workspaceId,
                $title,
                $userId,
                $userId,
                $stage,
                $value,
                $expectedCloseDate,
                $timestamp,
                $timestamp,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function completeLaunchChecklist(int $workspaceId, int $userId): void
    {
        Database::execute(
            "DELETE FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%']
        );

        foreach ((new WorkspaceLaunchChecklistService())->definitions() as $definition) {
            Database::execute(
                "INSERT INTO tasks (workspace_id, title, description, assigned_to, created_by, status, priority, metadata_json, completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW(), NOW())",
                [
                    $workspaceId,
                    (string) ($definition['title'] ?? 'Setup task'),
                    (string) ($definition['description'] ?? ''),
                    $userId,
                    $userId,
                    (string) ($definition['priority'] ?? 'medium'),
                    json_encode([
                        'source' => WorkspaceLaunchChecklistService::SOURCE,
                        'checklist_key' => (string) ($definition['key'] ?? ''),
                        'url' => (string) ($definition['url'] ?? ''),
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function completeLaunchChecklistExcept(int $workspaceId, int $userId, array $openKeys): void
    {
        Database::execute(
            "DELETE FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%']
        );

        foreach ((new WorkspaceLaunchChecklistService())->definitions() as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if (in_array($key, $openKeys, true)) {
                continue;
            }

            Database::execute(
                "INSERT INTO tasks (workspace_id, title, description, assigned_to, created_by, status, priority, metadata_json, completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW(), NOW())",
                [
                    $workspaceId,
                    (string) ($definition['title'] ?? 'Setup task'),
                    (string) ($definition['description'] ?? ''),
                    $userId,
                    $userId,
                    (string) ($definition['priority'] ?? 'medium'),
                    json_encode([
                        'source' => WorkspaceLaunchChecklistService::SOURCE,
                        'checklist_key' => $key,
                        'url' => (string) ($definition['url'] ?? ''),
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function launchChecklistItem(array $summary, string $key): array
    {
        foreach ((array) ($summary['items'] ?? []) as $item) {
            if ((string) ($item['key'] ?? '') === $key) {
                return (array) $item;
            }
        }

        return [];
    }

    private function clearInstalledSkills(int $workspaceId): void
    {
        if (Database::tableExists('workspace_skill_installs')) {
            Database::execute("DELETE FROM workspace_skill_installs WHERE workspace_id = ?", [$workspaceId]);
        }
    }

    private function installSkillForTest(int $workspaceId, int $userId, string $skillKey): void
    {
        (new WorkspaceSkillCatalogService())->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', config_json = VALUES(config_json), uninstalled_at = NULL, disabled_at = NULL",
            [$workspaceId, $skillKey, $userId, $userId]
        );
    }

    private function setCommunicationChannel(int $workspaceId, string $channel): void
    {
        Database::execute(
            "UPDATE workspace_onboarding_state SET communication_channel = ? WHERE workspace_id = ?",
            [$channel, $workspaceId]
        );
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $email = $slugPrefix . '.' . $suffix . '@example.test';
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Clarity Insight ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Clarity',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $email,
        ];
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed, string $role): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('__remember_restore_attempted', true);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', $role);
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], $role);
    }
}
