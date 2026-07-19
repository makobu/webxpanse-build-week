<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Nurture;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class NurtureTest extends DatabaseTestCase
{
    use EndpointHarness;

    private Nurture $nurture;
    private int $workspaceId;
    private int $membershipId;
    private string $workspaceUuid;
    private int $userId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nurture = new Nurture();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'sales', NOW())",
            [uniqid('nurture-user-', true), 'nurture-sales@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        Authorization::assignUserRole($this->userId, (int) ($salesRole['id'] ?? 0), $this->userId);
        Session::set('user_id', $this->userId);

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), 'Nurture Tenant Workspace', 'nurture-tenant-workspace', 'active', 'trialing', ?)",
            [$this->userId]
        );
        $this->workspaceId = (int) Database::lastInsertId();
        $workspace = Database::queryOne("SELECT uuid FROM workspaces WHERE id = ?", [$this->workspaceId]);
        $this->workspaceUuid = (string) ($workspace['uuid'] ?? '');
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );
        $this->membershipId = (int) Database::lastInsertId();
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        Database::execute(
            "INSERT INTO contacts
             (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_by, lead_score, engagement_score, created_at)
             VALUES (?, ?, 'Nora', 'Nurture', 'nora@example.com', 'new', ?, ?, 20, 15, NOW())",
            [$this->workspaceId, uniqid('nurture-contact-', true), $this->userId, $this->userId]
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    public function testQualifiedProspectDoesNotBecomeClientCareLane(): void
    {
        $lane = $this->nurture->classifyLifecycleLane(
            ['stage' => 'qualified', 'lead_score' => 72, 'engagement_score' => 45],
            ['last_touch_at' => date('Y-m-d H:i:s', strtotime('-5 days')), 'active_deals' => 0, 'won_deals' => 0]
        );

        $this->assertSame('inactive', $lane);
    }

    public function testClassifiesWonCustomerAsAtRiskWhenQuietTooLong(): void
    {
        $lane = $this->nurture->classifyLifecycleLane(
            ['stage' => 'won', 'lead_score' => 30, 'engagement_score' => 20],
            ['last_touch_at' => date('Y-m-d H:i:s', strtotime('-75 days')), 'active_deals' => 0, 'won_deals' => 1]
        );

        $this->assertSame('at_risk', $lane);
    }

    public function testStaleDetectionUsesCadenceWindow(): void
    {
        $this->assertFalse($this->nurture->isStale(date('Y-m-d H:i:s', strtotime('-12 days')), 'weekly'));
        $this->assertTrue($this->nurture->isStale(date('Y-m-d H:i:s', strtotime('-20 days')), 'weekly'));
        $this->assertFalse($this->nurture->isStale(null, 'manual'));
    }

    public function testCreateProfilePersistsRelationshipSignals(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Initial implementation package', 1200);

        Database::execute(
            "INSERT INTO activities (id, workspace_id, contact_id, user_id, activity_type, description, created_at)
             VALUES (?, ?, ?, ?, 'email', 'Helpful resource sent', ?)",
            [100001, $this->workspaceId, $this->contactId, $this->userId, date('Y-m-d H:i:s', strtotime('-3 days'))]
        );

        $profile = $this->nurture->getOrCreateProfile($this->contactId);

        $this->assertSame('customer_success', $profile['lifecycle_lane']);
        $this->assertSame('deal_closed_won', $profile['entry_source']);
        $this->assertSame('hot', $profile['temperature']);
        $this->assertSame('weekly', $profile['cadence']);
        $this->assertSame($this->contactId, (int) $profile['contact_id']);
        $this->assertNotEmpty($profile['suggested_touch']['guardrail']);
        $this->assertSame('Closed-won deal', $profile['purchase_summary']['label'] ?? null);
    }

    public function testNonDefaultWorkspaceProspectStaysOutOfNurture(): void
    {
        $profiles = $this->nurture->listProfiles();
        $profileIds = array_map(static fn(array $profile): int => (int) ($profile['contact_id'] ?? 0), $profiles);

        $this->assertNotContains($this->contactId, $profileIds);
    }

    public function testClosedWonContactAppearsWithDealEvidence(): void
    {
        $dealId = $this->createClosedWonDeal($this->contactId, 'Retainer kickoff', 2500);

        $profiles = $this->nurture->listProfiles();
        $profile = $this->findProfile($profiles, $this->contactId);

        $this->assertNotNull($profile);
        $this->assertSame('deal_closed_won', (string) ($profile['entry_source'] ?? ''));
        $this->assertSame($dealId, (int) ($profile['entry_reference_id'] ?? 0));
        $this->assertSame('Closed-won deal', (string) ($profile['purchase_summary']['label'] ?? ''));
    }

    public function testPaidInvoiceContactAppearsWithInvoiceEvidence(): void
    {
        $invoiceId = $this->createPaidInvoice($this->contactId, 'Success onboarding invoice', 750);

        $profiles = $this->nurture->listProfiles();
        $profile = $this->findProfile($profiles, $this->contactId);

        $this->assertNotNull($profile);
        $this->assertSame('paid_invoice', (string) ($profile['entry_source'] ?? ''));
        $this->assertSame($invoiceId, (int) ($profile['entry_reference_id'] ?? 0));
        $this->assertSame('Paid invoice', (string) ($profile['purchase_summary']['label'] ?? ''));
    }

    public function testTransitionReadinessRequiresPurchaseEvidenceBeforeCustomerNurture(): void
    {
        $readiness = $this->nurture->getTransitionReadinessForContact($this->contactId);

        $this->assertSame('not_ready', (string) ($readiness['status'] ?? ''));
        $this->assertSame('Needs purchase evidence', (string) ($readiness['label'] ?? ''));
        $this->assertStringContainsString('Close the deal as won', (string) ($readiness['next_step'] ?? ''));
    }

    public function testConvertedHandoffCanOpenCustomerNurtureAfterPurchaseEvidence(): void
    {
        $handoffId = $this->createMarketingHandoff($this->contactId, 'converted');
        $this->createClosedWonDeal($this->contactId, 'Converted handoff package', 1800);

        $readiness = $this->nurture->getTransitionReadinessForContact($this->contactId);
        $this->assertSame('ready', (string) ($readiness['status'] ?? ''));
        $this->assertSame('Ready for Customer Care', (string) ($readiness['label'] ?? ''));

        $active = $this->nurture->getTransitionReadinessForContact($this->contactId, true);
        $this->assertSame('active', (string) ($active['status'] ?? ''));
        $this->assertSame($this->contactId, (int) ($active['profile']['contact_id'] ?? 0));

        $lineage = $this->nurture->getMarketingHandoffLineage($this->contactId);
        $this->assertNotEmpty($lineage);
        $this->assertSame($handoffId, (int) ($lineage[0]['id'] ?? 0));
        $this->assertSame('converted', (string) ($lineage[0]['lineage_outcome'] ?? ''));
    }

    public function testExitedProfileReactivatesWhenPurchaseEvidenceArrivesLater(): void
    {
        $dealId = $this->createClosedWonDeal($this->contactId, 'Reactivated customer package', 1400);
        $this->nurture->getOrCreateProfile($this->contactId);

        Database::execute("UPDATE deals SET stage = 'closed_lost' WHERE id = ?", [$dealId]);
        $this->nurture->listProfiles();
        $exited = Database::queryOne(
            "SELECT nurture_status FROM nurture_profiles WHERE workspace_id = ? AND contact_id = ?",
            [$this->workspaceId, $this->contactId]
        );
        $this->assertSame('exited', (string) ($exited['nurture_status'] ?? ''));

        Database::execute("UPDATE deals SET stage = 'closed_won' WHERE id = ?", [$dealId]);
        $this->nurture = new Nurture();
        $profiles = $this->nurture->listProfiles();
        $profile = $this->findProfile($profiles, $this->contactId);

        $this->assertNotNull($profile);
        $this->assertNotSame('exited', (string) ($profile['nurture_status'] ?? ''));
    }

    public function testNurturePageUsesPostPurchaseLanguage(): void
    {
        $response = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Customer Care', $body);
        $this->assertStringContainsString('No customer check-ins due today.', $body);
        $this->assertStringContainsString('data-nurture-care-queue', $body);
        $this->assertStringContainsString('data-care-queue', $body);
        $this->assertStringContainsString('data-care-automation-status', $body);
        $this->assertStringContainsString('Automation', $body);
        $this->assertStringContainsString('Why automation is limited', $body);
        $this->assertStringContainsString('data-nurture-filter-toggle', $body);
        $this->assertStringContainsString('filters-card nurture-filter-panel', $body);
        $this->assertStringContainsString('<summary>', $body);
        $this->assertStringContainsString('stage-stats nurture-tabs', $body);
        $this->assertStringContainsString('table-card nurture-queue-shell', $body);
        $this->assertStringContainsString('data-care-plan-drawer', $body);
        $this->assertStringContainsString('Today', $body);
        $this->assertStringContainsString('Needs attention', $body);
        $this->assertStringContainsString('Quiet', $body);
        $this->assertStringContainsString('All', $body);
        $this->assertStringContainsString('nurture-empty-state', $body);
        $this->assertStringContainsString('Customer Care fills after an invoice payment, closed-won purchase, or paid workspace account.', $body);
        $this->assertStringContainsString('Follow-up Plans', $body);
        $this->assertStringContainsString('Use a plan when several customers need the same check-in rhythm.', $body);
        $this->assertStringContainsString('First 30 Days', $body);
        $this->assertStringContainsString('Quiet Customer', $body);
        $this->assertStringContainsString('Customer status', $body);
        $this->assertStringContainsString('Signal', $body);
        $this->assertStringContainsString('Review Deals', $body);
        $this->assertStringNotContainsString('Review Invoices', $body);
        $this->assertStringNotContainsString('Review Contacts', $body);
        $this->assertStringNotContainsString('Customer Nurture', $body);
        $this->assertStringNotContainsString('Current customer care', $body);
        $this->assertStringNotContainsString('Care Programs', $body);
        $this->assertStringNotContainsString('Care Readiness', $body);
        $this->assertStringNotContainsString('Momentum', $body);
        $this->assertStringNotContainsString('Cadence', $body);
        $this->assertStringNotContainsString('Reactivate', $body);
        $this->assertStringNotContainsString('nurture-insights', $body);
        $this->assertStringNotContainsString('nurture-programs-help', $body);
        $this->assertStringNotContainsString('How care programs help', $body);
        $this->assertStringNotContainsString('nurture-cockpit', $body);
        $this->assertStringNotContainsString('nurture-summary-grid', $body);
        $this->assertStringNotContainsString('nurture-today', $body);
        $this->assertStringNotContainsString('autonomy_mode', $body);
        $this->assertStringNotContainsString('promotion_status', $body);
        $this->assertStringNotContainsString('domain_key', $body);
        $this->assertStringNotContainsString('Due Now', $body);
        $this->assertStringNotContainsString('Healthy</a>', $body);
        $this->assertStringNotContainsString('<select name="action" data-nurture-action>', $body);
        $this->assertStringNotContainsString('<form method="POST" class="nurture-queue-form" data-nurture-bulk-form', $body);
        $this->assertStringNotContainsString('data-nurture-check type="checkbox"', $body);
        $this->assertStringNotContainsString('placeholder="Program name"', $body);
        $this->assertStringNotContainsString('Client Care Programs</h3>', $body);
        $this->assertStringNotContainsString('Sales Ready', $body);
        $this->assertStringNotContainsString('Mark sales ready', $body);
        $this->assertStringNotContainsString('Prospect nurture', $body);
        $this->assertStringNotContainsString('Create expansion opportunity', $body);
        $this->assertStringNotContainsString('proposal', strtolower($body));

        $queuePosition = strpos($body, 'data-care-queue');
        $filterPosition = strpos($body, '<details class="filters-card nurture-filter-panel" data-nurture-filter-toggle>');
        $drawerPosition = strpos($body, 'data-care-plan-drawer');
        $this->assertIsInt($queuePosition);
        $this->assertIsInt($filterPosition);
        $this->assertIsInt($drawerPosition);
        $this->assertLessThan($queuePosition, $filterPosition);
        $this->assertLessThan($drawerPosition, $queuePosition);
    }

    public function testNurturePageInitialLoadUsesSingleVisibleProfileQuery(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/nurture.php');

        $this->assertStringNotContainsString('$overviewProfiles = $nurture->listProfiles([], 250, 0);', $source);
        $this->assertStringContainsString('$profileTotal = (int) ($counts[\'all\'] ?? 0);', $source);
        $this->assertStringContainsString('$profileRows = [];', $source);
        $this->assertStringContainsString('foreach ($profileRows as $row)', $source);
        $this->assertStringContainsString('JOIN workspace_memberships wm ON wm.user_id = u.id', $source);
    }

    public function testNurturePageWithCustomerExposesFunctionalBulkControls(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Care-ready package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'nurture_status' => 'needs_touch',
            'next_touch_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'next_touch_reason' => 'Customer check-in is due now.',
        ]);

        $response = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('data-nurture-bulk-form', $body);
        $this->assertStringContainsString('data-care-automation-status', $body);
        $this->assertStringContainsString('data-nurture-care-row', $body);
        $this->assertStringContainsString('data-nurture-selected-action-bar', $body);
        $this->assertStringContainsString('data-nurture-selection-hint', $body);
        $this->assertStringContainsString('data-nurture-selection-count', $body);
        $this->assertStringContainsString('data-nurture-apply', $body);
        $this->assertStringNotContainsString('<option value="enroll">Add to follow-up plan</option>', $body);
        $this->assertStringNotContainsString('data-nurture-program-control hidden', $body);
        $this->assertStringContainsString('name="quick_create_task"', $body);
        $this->assertStringContainsString('name="quick_schedule_checkin"', $body);
        $this->assertStringContainsString('name="quick_check_in"', $body);
        $this->assertStringContainsString('data-care-check-in', $body);
        $this->assertStringContainsString('Why</th>', $body);
        $this->assertStringContainsString('Suggested next step', $body);
        $this->assertStringContainsString('No plan', $body);
        $this->assertStringContainsString('Brief', $body);
        $this->assertStringContainsString('Check-in due', $body);
        $this->assertStringContainsString('>Check in</button>', $body);
        $this->assertStringContainsString('>Create task</button>', $body);
        $this->assertStringContainsString('>Schedule</button>', $body);
        $this->assertStringNotContainsString('Next Best Care', $body);
        $this->assertStringNotContainsString('Open care profile', $body);
        $this->assertStringNotContainsString('<div class="nurture-empty-state">', $body);

        $queuePosition = strpos($body, 'data-care-queue');
        $filterPosition = strpos($body, '<details class="filters-card nurture-filter-panel" data-nurture-filter-toggle>');
        $this->assertIsInt($queuePosition);
        $this->assertIsInt($filterPosition);
        $this->assertLessThan($queuePosition, $filterPosition);
    }

    public function testNurturePageShowsProgramEnrollmentAndProgramFilter(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Program customer package', 1250);
        $programId = $this->createCareProgram('Lifecycle Care');
        $this->nurture->enrollContacts($programId, [$this->contactId]);

        $response = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'GET',
            'query' => [
                'tab' => 'all',
                'program_id' => (string) $programId,
            ],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Lifecycle Care', $body);
        $this->assertStringContainsString('Program start', $body);
        $this->assertStringContainsString('value="' . $programId . '" selected', $body);
        $this->assertStringContainsString('data-nurture-care-row', $body);
        $this->assertStringNotContainsString('No customers match this view', $body);
    }

    public function testReactivationTabIncludesPausedPostPurchaseCustomerAndCountsMatch(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Paused customer package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'nurture_status' => 'paused',
            'next_touch_reason' => 'Paused until the customer is ready to restart care.',
        ]);

        $profiles = $this->nurture->listProfiles(['tab' => 'dormant']);
        $profile = $this->findProfile($profiles, $this->contactId);
        $counts = $this->nurture->getCounts();

        $this->assertNotNull($profile);
        $this->assertSame('paused', (string) ($profile['nurture_status'] ?? ''));
        $this->assertArrayHasKey('all', $counts);
        $this->assertSame(count($profiles), (int) ($counts['dormant'] ?? -1));
        $this->assertGreaterThanOrEqual(count($profiles), (int) ($counts['all'] ?? 0));
    }

    public function testDueBeforeFilterIncludesWholeSelectedDay(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Full-day due package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'nurture_status' => 'active',
            'next_touch_at' => '2030-01-15 17:45:00',
            'next_touch_reason' => 'Future care check-in',
        ]);

        $profiles = $this->nurture->listProfiles(['due_before' => '2030-01-15']);

        $this->assertNotNull($this->findProfile($profiles, $this->contactId));
    }

    public function testCareBriefReturnsPlainLanguageReasonAndNextStep(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Plain-language care package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'lifecycle_lane' => 'at_risk',
            'nurture_status' => 'active',
            'next_touch_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
            'next_touch_reason' => 'Confirm whether the customer is still getting value.',
        ]);
        Database::execute(
            "UPDATE nurture_profiles SET health_score = 35 WHERE workspace_id = ? AND contact_id = ?",
            [$this->workspaceId, $this->contactId]
        );

        $profile = $this->nurture->getProfileForContact($this->contactId);
        $this->assertNotNull($profile);

        $brief = $this->nurture->careBrief($profile);

        $this->assertSame('Needs attention', $brief['why_now']);
        $this->assertSame('Nora Nurture may need attention.', $brief['status_sentence']);
        $this->assertSame('Confirm whether the customer is still getting value.', $brief['suggested_next_step']);
        $this->assertSame('Quiet Customer', $brief['suggested_plan']);
        $this->assertSame('Check in with Nora Nurture', $brief['task_title']);
    }

    public function testRecordCheckInCreatesCompletedTouchpointAndSchedulesNextCheckIn(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Check-in package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'cadence' => 'weekly',
            'nurture_status' => 'needs_touch',
            'next_touch_at' => '2029-12-31 09:00:00',
            'next_touch_reason' => 'Check whether the customer is getting value.',
        ]);

        $updated = $this->nurture->recordCheckIn($this->contactId, [
            'checked_at' => '2030-01-01 09:00:00',
            'subject' => 'Customer check-in completed',
            'notes' => 'Confirmed progress and next blocker.',
            'created_by' => $this->userId,
        ]);

        $touchpoint = Database::queryOne(
            "SELECT * FROM nurture_touchpoints WHERE workspace_id = ? AND contact_id = ? ORDER BY id DESC LIMIT 1",
            [$this->workspaceId, $this->contactId]
        );

        $this->assertNotNull($touchpoint);
        $this->assertSame('check_in', (string) ($touchpoint['touch_type'] ?? ''));
        $this->assertSame('completed', (string) ($touchpoint['status'] ?? ''));
        $this->assertSame('2030-01-01 09:00:00', (string) ($touchpoint['completed_at'] ?? ''));
        $this->assertSame('active', (string) ($updated['nurture_status'] ?? ''));
        $this->assertSame('2030-01-01 09:00:00', (string) ($updated['last_touch_at'] ?? ''));
        $this->assertSame('2030-01-08 09:00:00', (string) ($updated['next_touch_at'] ?? ''));
    }

    public function testUnknownBulkActionReturnsSafeError(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Bulk action package', 1250);

        $response = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'not_a_real_action',
                'contact_ids' => [$this->contactId],
            ],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Choose a valid customer care action.', $body);
    }

    public function testEnrollmentRequiresProgramAndManagePermission(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Enrollment package', 1250);
        $programId = $this->createCareProgram('Lifecycle Care');

        $blocked = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'enroll',
                'program_id' => $programId,
                'contact_ids' => [$this->contactId],
            ],
        ]);
        $blockedBody = (string) ($blocked['body'] ?? '');
        $this->assertSame(200, (int) ($blocked['status'] ?? 0), (string) ($blocked['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan changes require manage access.', $blockedBody);

        $this->grantNurtureManage();
        $missingProgram = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'enroll',
                'program_id' => '',
                'contact_ids' => [$this->contactId],
            ],
        ]);
        $missingProgramBody = (string) ($missingProgram['body'] ?? '');

        $this->assertSame(200, (int) ($missingProgram['status'] ?? 0), (string) ($missingProgram['stderr'] ?? ''));
        $this->assertStringContainsString('Choose a follow-up plan.', $missingProgramBody);
    }

    public function testInlineFollowUpPlanCreationRequiresManageAndEnrollsSelectedCustomers(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Inline plan package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);

        $blocked = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'create_inline_plan',
                'plan_preset' => 'Quiet Customer',
                'plan_name' => '',
                'plan_cadence' => 'monthly',
                'plan_first_touch_delay_days' => '3',
                'plan_preferred_channel' => 'whatsapp',
                'plan_default_touch_type' => 'risk_recovery',
                'plan_description' => 'Win back quiet customers with a useful check-in.',
                'plan_touch_guidance' => 'Ask what would make the relationship useful again.',
                'contact_ids' => [$this->contactId],
            ],
        ]);
        $this->assertSame(200, (int) ($blocked['status'] ?? 0), (string) ($blocked['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan creation requires manage access.', (string) ($blocked['body'] ?? ''));

        $this->grantNurtureManage();

        $created = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'create_inline_plan',
                'plan_preset' => 'Quiet Customer',
                'plan_name' => '',
                'plan_cadence' => 'monthly',
                'plan_first_touch_delay_days' => '3',
                'plan_preferred_channel' => 'whatsapp',
                'plan_default_touch_type' => 'risk_recovery',
                'plan_description' => 'Win back quiet customers with a useful check-in.',
                'plan_touch_guidance' => 'Ask what would make the relationship useful again.',
                'contact_ids' => [$this->contactId],
            ],
        ]);
        $body = (string) ($created['body'] ?? '');
        $this->assertSame(200, (int) ($created['status'] ?? 0), (string) ($created['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan created and selected customers added.', $body);

        $program = Database::queryOne(
            "SELECT * FROM nurture_programs WHERE workspace_id = ? AND name = 'Quiet Customer' ORDER BY id DESC LIMIT 1",
            [$this->workspaceId]
        );
        $this->assertNotNull($program);
        $this->assertSame('monthly', (string) ($program['cadence'] ?? ''));
        $this->assertSame('customer_success', (string) ($program['program_type'] ?? ''));
        $this->assertSame(3, (int) ($program['first_touch_delay_days'] ?? -1));
        $this->assertSame('whatsapp', (string) ($program['preferred_channel'] ?? ''));
        $this->assertSame('risk_recovery', (string) ($program['default_touch_type'] ?? ''));
        $this->assertSame('Ask what would make the relationship useful again.', (string) ($program['touch_guidance'] ?? ''));

        $enrollment = Database::queryOne(
            "SELECT * FROM nurture_enrollments WHERE workspace_id = ? AND contact_id = ? AND program_id = ?",
            [$this->workspaceId, $this->contactId, (int) $program['id']]
        );
        $this->assertNotNull($enrollment);
        $this->assertSame('active', (string) ($enrollment['status'] ?? ''));
        $this->assertSame(date('Y-m-d', strtotime('+3 days')), date('Y-m-d', strtotime((string) $enrollment['next_touch_at'])));
    }

    public function testNurtureProgramsPageHidesCreateFormUntilNewButtonClicked(): void
    {
        $this->grantNurtureManage();

        $response = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Advanced Follow-up Plans', $body);
        $this->assertStringContainsString('New Follow-up Plan', $body);
        $this->assertStringNotContainsString('placeholder="Program name"', $body);
        $this->assertStringContainsString('Back to Customer Care', $body);

        $newResponse = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['new' => '1'],
        ]);
        $newBody = (string) ($newResponse['body'] ?? '');

        $this->assertSame(200, (int) ($newResponse['status'] ?? 0), (string) ($newResponse['stderr'] ?? ''));
        $this->assertStringContainsString('placeholder="Plan name"', $newBody);
        $this->assertStringContainsString('Create Follow-up Plan', $newBody);
    }

    public function testNurtureProgramsPageCreatesProgram(): void
    {
        $this->grantNurtureManage();

        $response = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'create_program',
                'name' => 'Executive Renewal Care',
                'program_type' => 'customer_success',
                'cadence' => 'monthly',
                'first_touch_delay_days' => '14',
                'preferred_channel' => 'email',
                'default_touch_type' => 'renewal',
                'touch_guidance' => 'Confirm renewal value and open blockers.',
                'linked_campaign_id' => '',
                'description' => 'Renewal readiness for active customers',
            ],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan created.', $body);
        $this->assertStringContainsString('Executive Renewal Care', $body);

        $program = Database::queryOne(
            "SELECT * FROM nurture_programs WHERE workspace_id = ? AND name = 'Executive Renewal Care'",
            [$this->workspaceId]
        );
        $this->assertNotNull($program);
        $this->assertSame(14, (int) ($program['first_touch_delay_days'] ?? -1));
        $this->assertSame('email', (string) ($program['preferred_channel'] ?? ''));
        $this->assertSame('renewal', (string) ($program['default_touch_type'] ?? ''));
        $this->assertSame('Confirm renewal value and open blockers.', (string) ($program['touch_guidance'] ?? ''));
    }

    public function testNurtureProgramsPageLetsManagersUpdateAndDeleteUnusedPlans(): void
    {
        $this->grantNurtureManage();
        $programId = $this->createCareProgram('Executive Renewal Care');
        $campaignId = $this->createCampaign('Renewal Campaign');

        $editResponse = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['edit' => (string) $programId],
        ]);
        $editBody = (string) ($editResponse['body'] ?? '');

        $this->assertSame(200, (int) ($editResponse['status'] ?? 0), (string) ($editResponse['stderr'] ?? ''));
        $this->assertStringContainsString('data-follow-up-plan-row', $editBody);
        $this->assertStringContainsString('data-follow-up-plan-edit', $editBody);
        $this->assertStringContainsString('data-follow-up-plan-delete', $editBody);
        $this->assertStringContainsString('data-follow-up-plan-edit-panel', $editBody);

        $updateResponse = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'update_program',
                'program_id' => $programId,
                'name' => 'Executive Renewal Follow-up',
                'program_type' => 'expansion',
                'cadence' => 'quarterly',
                'first_touch_delay_days' => '30',
                'preferred_channel' => 'phone',
                'default_touch_type' => 'expansion',
                'touch_guidance' => 'Ask where more value would help this quarter.',
                'status' => 'paused',
                'linked_campaign_id' => $campaignId,
                'description' => 'Renewal readiness and expansion check-ins',
            ],
        ]);
        $updateBody = (string) ($updateResponse['body'] ?? '');

        $this->assertSame(200, (int) ($updateResponse['status'] ?? 0), (string) ($updateResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan updated.', $updateBody);

        $updated = Database::queryOne(
            "SELECT * FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $programId]
        );
        $this->assertNotNull($updated);
        $this->assertSame('Executive Renewal Follow-up', (string) ($updated['name'] ?? ''));
        $this->assertSame('Renewal readiness and expansion check-ins', (string) ($updated['description'] ?? ''));
        $this->assertSame('expansion', (string) ($updated['program_type'] ?? ''));
        $this->assertSame('quarterly', (string) ($updated['cadence'] ?? ''));
        $this->assertSame('paused', (string) ($updated['status'] ?? ''));
        $this->assertSame(30, (int) ($updated['first_touch_delay_days'] ?? -1));
        $this->assertSame('phone', (string) ($updated['preferred_channel'] ?? ''));
        $this->assertSame('expansion', (string) ($updated['default_touch_type'] ?? ''));
        $this->assertSame('Ask where more value would help this quarter.', (string) ($updated['touch_guidance'] ?? ''));
        $this->assertSame($campaignId, (int) ($updated['linked_campaign_id'] ?? 0));

        $deleteResponse = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'delete_program',
                'program_id' => $programId,
            ],
        ]);
        $deleteBody = (string) ($deleteResponse['body'] ?? '');

        $this->assertSame(200, (int) ($deleteResponse['status'] ?? 0), (string) ($deleteResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan deleted.', $deleteBody);
        $this->assertNull(Database::queryOne(
            "SELECT id FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $programId]
        ));
    }

    public function testNurtureProgramsPageArchivesUsedPlansAndHidesArchivedByDefault(): void
    {
        $this->grantNurtureManage();
        $this->createClosedWonDeal($this->contactId, 'Used follow-up plan package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $programId = $this->createCareProgram('Used Renewal Plan');
        $this->nurture->enrollContacts($programId, [$this->contactId]);

        $availableBefore = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $availableBeforeBody = (string) ($availableBefore['body'] ?? '');
        $this->assertStringContainsString('Used Renewal Plan', $availableBeforeBody);
        $this->assertStringContainsString('data-follow-up-plan-archive', $availableBeforeBody);
        $this->assertStringContainsString('History preserved', $availableBeforeBody);

        $archiveResponse = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'delete_program',
                'program_id' => $programId,
            ],
        ]);
        $archiveBody = (string) ($archiveResponse['body'] ?? '');

        $this->assertSame(200, (int) ($archiveResponse['status'] ?? 0), (string) ($archiveResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Follow-up plan archived because it has customer history.', $archiveBody);

        $program = Database::queryOne(
            "SELECT status FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $programId]
        );
        $this->assertSame('archived', (string) ($program['status'] ?? ''));

        $enrollment = Database::queryOne(
            "SELECT status, completed_at, exit_reason FROM nurture_enrollments WHERE workspace_id = ? AND program_id = ? AND contact_id = ?",
            [$this->workspaceId, $programId, $this->contactId]
        );
        $this->assertNotNull($enrollment);
        $this->assertSame('exited', (string) ($enrollment['status'] ?? ''));
        $this->assertNotEmpty($enrollment['completed_at'] ?? '');
        $this->assertSame('Follow-up plan archived', (string) ($enrollment['exit_reason'] ?? ''));

        $availableAfter = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $availableAfterBody = (string) ($availableAfter['body'] ?? '');
        $this->assertStringContainsString('Available Plans', $availableAfterBody);
        $this->assertStringNotContainsString('Used Renewal Plan', $availableAfterBody);

        $archived = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['status' => 'archived'],
        ]);
        $archivedBody = (string) ($archived['body'] ?? '');
        $this->assertStringContainsString('Archived Plans', $archivedBody);
        $this->assertStringContainsString('Used Renewal Plan', $archivedBody);
    }

    public function testNurtureProgramsPageHidesMutationControlsForNonManagersAndBlocksPost(): void
    {
        $programId = $this->createCareProgram('Viewer Follow-up Plan');

        $response = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Viewer Follow-up Plan', $body);
        $this->assertStringNotContainsString('data-follow-up-plan-edit', $body);
        $this->assertStringNotContainsString('data-follow-up-plan-delete', $body);
        $this->assertStringNotContainsString('data-follow-up-plan-archive', $body);
        $this->assertStringNotContainsString('New Follow-up Plan', $body);

        $blocked = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'update_program',
                'program_id' => $programId,
                'name' => 'Blocked Rename',
                'program_type' => 'expansion',
                'cadence' => 'weekly',
                'status' => 'active',
                'linked_campaign_id' => '',
                'description' => 'Should not save',
            ],
        ]);
        $blockedBody = (string) ($blocked['body'] ?? '');

        $this->assertSame(200, (int) ($blocked['status'] ?? 0), (string) ($blocked['stderr'] ?? ''));
        $this->assertStringContainsString('You do not have permission to manage follow-up plans.', $blockedBody);

        $program = Database::queryOne(
            "SELECT name, cadence FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $programId]
        );
        $this->assertSame('Viewer Follow-up Plan', (string) ($program['name'] ?? ''));
        $this->assertSame('monthly', (string) ($program['cadence'] ?? ''));
    }

    public function testNurtureProgramsPageUnknownActionReturnsSafeError(): void
    {
        $this->grantNurtureManage();

        $response = $this->runWebEndpoint('public/nurture_programs.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-nurture',
                'action' => 'not_a_plan_action',
            ],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Choose a valid follow-up plan action.', $body);
    }

    public function testCustomerCareEnrollmentPickerExcludesArchivedPlans(): void
    {
        $activeProgramId = $this->createCareProgram('Active Follow-up Plan');
        $archivedProgramId = $this->createCareProgram('Archived Follow-up Plan', 'archived');
        $this->createClosedWonDeal($this->contactId, 'Care-ready archived picker package', 1250);
        $this->nurture->getOrCreateProfile($this->contactId);
        $this->nurture->updateProfile($this->contactId, [
            'nurture_status' => 'needs_touch',
            'next_touch_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'next_touch_reason' => 'Customer check-in is due now.',
        ]);

        $response = $this->runWebEndpoint('public/nurture.php', $this->webSession(), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('<option value="' . $activeProgramId . '">Active Follow-up Plan', $body);
        $this->assertStringContainsString('Active Follow-up Plan', $body);
        $this->assertStringNotContainsString('<option value="' . $archivedProgramId . '">Archived Follow-up Plan', $body);
        $this->assertStringNotContainsString('Archived Follow-up Plan', $body);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Follow-up plan is not available for new enrollments.');
        $this->nurture->enrollContacts($archivedProgramId, [$this->contactId]);
    }

    public function testNurtureDetailShowsPurchaseContextAndCareActions(): void
    {
        $this->createPaidInvoice($this->contactId, 'Customer success package', 1100);

        $response = $this->runWebEndpoint('public/nurture_view.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['contact_id' => $this->contactId],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Customer Care', $body);
        $this->assertStringContainsString('Why this customer is here', $body);
        $this->assertStringContainsString('Suggested next step', $body);
        $this->assertStringContainsString('Purchase', $body);
        $this->assertStringContainsString('Follow-up plan', $body);
        $this->assertStringContainsString('History', $body);
        $this->assertStringContainsString('Paid invoice', $body);
        $this->assertStringContainsString('Where this came from', $body);
        $this->assertStringContainsString('data-care-settings-panel', $body);
        $this->assertStringContainsString('>Check in</button>', $body);
        $this->assertStringContainsString('>Create task</button>', $body);
        $this->assertStringContainsString('>Schedule</button>', $body);
        $this->assertStringContainsString('>Save</button>', $body);
        $this->assertStringNotContainsString('Care Readiness', $body);
        $this->assertStringNotContainsString('Outreach Handoff Lineage', $body);
        $this->assertStringNotContainsString('Next Best Care Action', $body);
        $this->assertStringNotContainsString('Customer Relationship Summary', $body);
        $this->assertStringNotContainsString('Â', $body);
        $this->assertStringNotContainsString('Â', $body);
        $this->assertStringNotContainsString('Suggested Touch', $body);
    }

    public function testDefaultWorkspaceOwnerQualifiedLeadStaysOutOfNurture(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1, $this->userId, 'owner');
        $metadata = [
            'source' => 'default_workspace_owner_contact',
            'relationship' => 'workspace_owner',
            'owner_workspace_id' => 42,
            'owner_workspace_plan_status' => 'trialing',
            'default_workspace_nurture_qualified' => false,
            'default_workspace_contact_scope' => 'qualified_workspace_lead',
            'default_workspace_use' => 'lead_to_customer_conversion',
            'current_paying_customer' => false,
            'marketing_conversion_allowed' => true,
        ];
        Database::execute(
            "INSERT INTO contacts
             (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_by, lead_score, engagement_score, metadata_json, created_at)
             VALUES (1, ?, 'Tara', 'Trial', 'tara.trial@example.com', 'qualified', ?, ?, 75, 0, ?, NOW())",
            [uniqid('nurture-trial-owner-', true), $this->userId, $this->userId, json_encode($metadata)]
        );
        $contactId = (int) Database::lastInsertId();

        $profiles = $this->nurture->listProfiles();
        $profileIds = array_map(static fn(array $profile): int => (int) ($profile['contact_id'] ?? 0), $profiles);

        $this->assertNotContains($contactId, $profileIds);
    }

    public function testDefaultWorkspacePaidOwnerContactAppearsInCustomerNurture(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1, $this->userId, 'owner');
        $metadata = [
            'source' => 'default_workspace_owner_contact',
            'relationship' => 'workspace_owner',
            'owner_workspace_id' => 43,
            'owner_workspace_plan_status' => 'active',
            'default_workspace_nurture_qualified' => true,
            'default_workspace_contact_scope' => 'current_paying_customer',
            'default_workspace_use' => 'customer_success_nurture',
            'current_paying_customer' => true,
            'marketing_conversion_allowed' => false,
        ];
        Database::execute(
            "INSERT INTO contacts
             (workspace_id, uuid, first_name, last_name, email, stage, assigned_to, created_by, lead_score, engagement_score, metadata_json, created_at)
             VALUES (1, ?, 'Pat', 'Paid', 'pat.paid@example.com', 'won', ?, ?, 90, 0, ?, NOW())",
            [uniqid('nurture-paid-owner-', true), $this->userId, $this->userId, json_encode($metadata)]
        );
        $contactId = (int) Database::lastInsertId();

        $profiles = $this->nurture->listProfiles();
        $profile = null;
        foreach ($profiles as $candidate) {
            if ((int) ($candidate['contact_id'] ?? 0) === $contactId) {
                $profile = $candidate;
                break;
            }
        }

        $this->assertNotNull($profile);
        $this->assertSame('customer_success', (string) ($profile['lifecycle_lane'] ?? ''));
        $this->assertContains((string) ($profile['nurture_status'] ?? ''), ['active', 'needs_touch']);
    }

    public function testFollowUpPlanPreferencesDriveEnrollmentTasksAndCheckIns(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Preference-driven care package', 1400);
        $programId = $this->nurture->createProgram([
            'name' => 'Preference Care',
            'description' => 'Preference test plan',
            'program_type' => 'customer_success',
            'cadence' => 'monthly',
            'first_touch_delay_days' => 7,
            'preferred_channel' => 'whatsapp',
            'default_touch_type' => 'risk_recovery',
            'touch_guidance' => 'Open with a low-pressure value check.',
            'status' => 'active',
            'created_by' => $this->userId,
        ]);

        $this->nurture->enrollContacts($programId, [$this->contactId]);

        $enrollment = Database::queryOne(
            "SELECT * FROM nurture_enrollments WHERE workspace_id = ? AND program_id = ? AND contact_id = ?",
            [$this->workspaceId, $programId, $this->contactId]
        );
        $this->assertNotNull($enrollment);
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime((string) $enrollment['next_touch_at'])));

        $taskId = $this->nurture->createFollowUpTask($this->contactId, [
            'created_by' => $this->userId,
            'actor_user_id' => $this->userId,
        ]);
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);
        $plannedTouchpoint = Database::queryOne("SELECT * FROM nurture_touchpoints WHERE task_id = ?", [$taskId]);

        $this->assertNotNull($task);
        $this->assertStringContainsString('Open with a low-pressure value check.', (string) ($task['description'] ?? ''));
        $taskMetadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('whatsapp', (string) ($taskMetadata['preferred_channel'] ?? ''));
        $this->assertSame('risk_recovery', (string) ($taskMetadata['default_touch_type'] ?? ''));
        $this->assertNotNull($plannedTouchpoint);
        $this->assertSame('whatsapp', (string) ($plannedTouchpoint['channel'] ?? ''));
        $this->assertSame('risk_recovery', (string) ($plannedTouchpoint['touch_type'] ?? ''));
        $this->assertStringContainsString('Open with a low-pressure value check.', (string) ($plannedTouchpoint['notes'] ?? ''));

        $this->nurture->recordCheckIn($this->contactId, ['created_by' => $this->userId]);
        $completedTouchpoint = Database::queryOne(
            "SELECT * FROM nurture_touchpoints
             WHERE workspace_id = ? AND contact_id = ? AND status = 'completed'
             ORDER BY id DESC LIMIT 1",
            [$this->workspaceId, $this->contactId]
        );

        $this->assertNotNull($completedTouchpoint);
        $this->assertSame('whatsapp', (string) ($completedTouchpoint['channel'] ?? ''));
        $this->assertSame('risk_recovery', (string) ($completedTouchpoint['touch_type'] ?? ''));
        $this->assertStringContainsString('Open with a low-pressure value check.', (string) ($completedTouchpoint['notes'] ?? ''));
    }

    public function testCreateFollowUpTaskLinksTaskAndTouchpoint(): void
    {
        $this->createClosedWonDeal($this->contactId, 'Support package', 900);

        $taskId = $this->nurture->createFollowUpTask($this->contactId, [
            'created_by' => $this->userId,
            'actor_user_id' => $this->userId,
            'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);

        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);
        $touchpoint = Database::queryOne("SELECT * FROM nurture_touchpoints WHERE task_id = ?", [$taskId]);

        $this->assertNotNull($task);
        $this->assertSame($this->contactId, (int) $task['contact_id']);
        $this->assertSame('nurture', json_decode((string) $task['metadata_json'], true)['source_surface'] ?? null);
        $this->assertNotNull($touchpoint);
        $this->assertSame('manual_task', $touchpoint['touch_type']);
    }

    /**
     * @param array<int,array<string,mixed>> $profiles
     */
    private function findProfile(array $profiles, int $contactId): ?array
    {
        foreach ($profiles as $profile) {
            if ((int) ($profile['contact_id'] ?? 0) === $contactId) {
                return $profile;
            }
        }

        return null;
    }

    private function createClosedWonDeal(int $contactId, string $title, float $value): int
    {
        Database::execute(
            "INSERT INTO deals
                (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, actual_close_date, currency, created_at)
             VALUES
                (?, ?, 'Customer purchase evidence', ?, ?, ?, 'closed_won', ?, 100, CURDATE(), 'USD', NOW())",
            [$this->workspaceId, $title, $contactId, $this->userId, $this->userId, $value]
        );

        return (int) Database::lastInsertId();
    }

    private function createPaidInvoice(int $contactId, string $title, float $amount): int
    {
        Database::execute(
            "INSERT INTO invoices
                (workspace_id, document_type, status, invoice_number, contact_id, assigned_to, created_by, currency,
                 issue_date, due_date, payment_terms_days, subtotal, grand_total, amount_paid, balance_due, title, paid_at, created_at)
             VALUES
                (?, 'invoice', 'paid', ?, ?, ?, ?, 'USD', CURDATE(), CURDATE(), 0, ?, ?, ?, 0, ?, NOW(), NOW())",
            [
                $this->workspaceId,
                'INV-NURTURE-' . strtolower(bin2hex(random_bytes(4))),
                $contactId,
                $this->userId,
                $this->userId,
                $amount,
                $amount,
                $amount,
                $title,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function createCareProgram(string $name, string $status = 'active'): int
    {
        Database::execute(
            "INSERT INTO nurture_programs
                (workspace_id, name, description, program_type, status, cadence, created_by)
             VALUES
                (?, ?, 'Customer care test program', 'customer_success', ?, 'monthly', ?)",
            [$this->workspaceId, $name, $status, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createCampaign(string $name): int
    {
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, status, created_by)
             VALUES (?, UUID(), ?, 'active', ?)",
            [$this->workspaceId, $name, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createMarketingHandoff(int $contactId, string $status = 'qualified'): int
    {
        Database::execute(
            "INSERT INTO marketing_lead_handoffs
                (workspace_id, uuid, contact_id, status, priority, assigned_to, source, handoff_note,
                 sla_due_at, converted_at, sales_outcome, feedback_reason, feedback_by, feedback_at, metadata_json, created_by)
             VALUES
                (?, UUID(), ?, ?, 'high', ?, 'landing_page', 'Demo request converted into sales follow-up',
                 DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), ?, 'Converted from outreach', ?, NOW(), ?, ?)",
            [
                $this->workspaceId,
                $contactId,
                $status,
                $this->userId,
                in_array($status, ['accepted', 'contacted', 'qualified', 'converted', 'lost'], true) ? $status : null,
                $this->userId,
                json_encode(['source' => 'nurture_test']),
                $this->userId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'nurture-user',
            'user_email' => 'nurture-sales@example.com',
            'user_role' => 'sales',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => 'nurture-tenant-workspace',
            'active_workspace_name' => 'Nurture Tenant Workspace',
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => $this->membershipId,
            'csrf_token' => 'csrf-nurture',
            '__remember_restore_attempted' => true,
        ];
    }

    private function grantNurtureManage(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
    }
}
