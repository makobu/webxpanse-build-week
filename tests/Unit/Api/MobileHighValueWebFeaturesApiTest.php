<?php

namespace CRM\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/mobile/_feature_helpers.php';

class MobileHighValueWebFeaturesApiTest extends TestCase
{
    public function testCapabilityContractSuppressesSetupUrlForNonManagers(): void
    {
        $runtime = [
            'available' => false,
            'can_manage_setup' => false,
            'reason_code' => 'setup_required',
            'message' => 'Ask an owner to finish setup.',
            'setup_url' => 'settings.php',
        ];

        $payload = \mobileOperationCapability(true, false, $runtime);

        $this->assertTrue($payload['can_view']);
        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['can_manage_setup']);
        $this->assertNull($payload['setup_url']);
    }

    public function testOperationsManifestUsesPermissionAndRuntimeStatusHelpers(): void
    {
        $source = $this->readApiSource('operations.php');
        $helpers = $this->readApiSource('_feature_helpers.php');

        $this->assertStringContainsString("'api_version' => 4", $source);
        $this->assertStringContainsString('mobileOperationCapabilities', $source);
        $this->assertStringContainsString("'organization' =>", $helpers);
        $this->assertStringContainsString("'analytics' =>", $helpers);
        $this->assertStringContainsString("'calendar_shares' =>", $helpers);
        $this->assertStringContainsString("'voice' =>", $helpers);
        $this->assertStringContainsString("'decision_center' =>", $helpers);
        $this->assertStringContainsString("'can_view'", $helpers);
        $this->assertStringContainsString("'can_manage_setup'", $helpers);
        $this->assertStringContainsString('function mobileOrganizationRuntimeStatus', $helpers);
        $this->assertStringContainsString('function mobileAnalyticsRuntimeStatus', $helpers);
        $this->assertStringContainsString('function mobileCalendarMeetingsRuntimeStatus', $helpers);
        $this->assertStringContainsString('function mobileCommunicationRuntimeStatus', $helpers);
        $this->assertStringContainsString('function mobileVoiceRuntimeStatus', $helpers);
    }

    public function testMobileAnalyticsReusesWebOperatingAnalyticsWithServerOwnedGates(): void
    {
        $source = $this->readApiSource('analytics.php');

        $this->assertStringContainsString('WorkspaceBusinessIntelligenceGateService', $source);
        $this->assertStringContainsString('OperatingAnalyticsService', $source);
        $this->assertStringContainsString('getOperatingSnapshot', $source);
        $this->assertStringContainsString('getChannelAnalytics', $source);
        $this->assertStringContainsString('getAIAutomationAnalytics', $source);
        $this->assertStringContainsString('getFounderJourneyAnalytics', $source);
        $this->assertStringContainsString('getTargetTaskAnalytics', $source);
        $this->assertStringContainsString('getMarketplaceSkillAnalytics', $source);
        $this->assertStringContainsString('getOperationsHealthAnalytics', $source);
        $this->assertStringContainsString("'analytics_section_invalid'", $source);
        $this->assertStringContainsString("'permission_denied'", $source);
    }

    public function testOrganizationIntelligenceEndpointUsesHrAnalyticsGatesAndServices(): void
    {
        $source = $this->readApiSource('organization_intelligence.php');

        $this->assertStringContainsString('hr.analytics.view', $source);
        $this->assertStringContainsString('hr.analytics.manage', $source);
        $this->assertStringContainsString('mobileRequireOrganizationRuntime', $source);
        $this->assertStringContainsString('buildDashboard', $source);
        $this->assertStringContainsString('createCoachingTask', $source);
        $this->assertStringContainsString('createActionPlanTasks', $source);
    }

    public function testCalendarMeetingEndpointsStayBehindRuntimeGate(): void
    {
        $bookings = $this->readApiSource('meeting_bookings.php');
        $shares = $this->readApiSource('calendar_shares.php');

        $this->assertStringContainsString('mobileRequireCalendarMeetingsRuntime', $bookings);
        $this->assertStringContainsString('approveBooking', $bookings);
        $this->assertStringContainsString('updateStatus', $bookings);
        $this->assertStringContainsString('meeting_bookings.manage', $bookings);
        $this->assertStringContainsString('mobileBookingAllowedActions', $bookings);
        $this->assertStringContainsString('booking_action_not_allowed', $bookings);

        $this->assertStringContainsString('mobileRequireCalendarMeetingsRuntime', $shares);
        $this->assertStringContainsString('createOrUpdateShare', $shares);
        $this->assertStringContainsString('sendShareEmail', $shares);
        $this->assertStringContainsString('copy_logged', $shares);
    }

    public function testExecutionEndpointsExposeMobileFirstActionsOnly(): void
    {
        $targets = $this->readApiSource('targets.php');
        $activities = $this->readApiSource('activities.php');
        $forms = $this->readApiSource('forms/submissions.php');

        $this->assertStringContainsString('->getAll', $targets);
        $this->assertStringContainsString('updateProgress', $targets);
        $this->assertStringContainsString('complete', $targets);
        $this->assertStringContainsString("['target_id' => (int) \$target['id']]", $targets);
        $this->assertStringContainsString("'related_tasks'", $targets);
        $this->assertStringContainsString('->getRecent', $activities);
        $this->assertStringContainsString('$canViewAllContacts', $activities);
        $this->assertStringContainsString('->log', $activities);
        $this->assertStringContainsString('create_follow_up_task', $forms);
        $this->assertStringContainsString('apply_ai_tags', $forms);
        $this->assertStringContainsString('AI tag application is not available', $forms);
        $this->assertStringContainsString("['tasks.write']", $forms);
        $this->assertStringContainsString('mobileRequireCommunicationRuntime', $forms);
    }

    public function testCommunicationEndpointsAreReviewAndTemplateFocused(): void
    {
        $templates = $this->readApiSource('communications/templates.php');
        $preview = $this->readApiSource('communications/template_preview.php');
        $whatsapp = $this->readApiSource('communications/whatsapp.php');
        $campaignReview = $this->readApiSource('communications/campaign_review.php');

        $this->assertStringContainsString('mobileRequireCommunicationRuntime', $templates);
        $this->assertStringContainsString('getSendableTemplatesForUser', $templates);
        $this->assertStringContainsString('getUserSignaturesForUser', $templates);
        $this->assertStringContainsString('WhatsAppTemplateService', $templates);
        $this->assertStringContainsString('mobileRequireCommunicationRuntime', $preview);
        $this->assertStringContainsString('renderTemplate', $preview);
        $this->assertStringContainsString('mobileRequireCommunicationChannelRuntime', $whatsapp);
        $this->assertStringContainsString('mobileWhatsAppMessageSummary', $whatsapp);
        $this->assertStringContainsString('contacts.view_all', $whatsapp);
        $this->assertStringContainsString('mobileCampaignReviewSummary', $campaignReview);
        $this->assertStringContainsString('contacts.view_all', $campaignReview);
    }

    public function testMobileWhatsAppUsesChannelPluginAndKeepsAssistantOptional(): void
    {
        $compose = $this->readApiSource('compose.php');
        $reply = $this->readApiSource('conversations/reply.php');
        $drafts = $this->readServiceSource('MobileConversationDraftService.php');
        $status = $this->readApiSource('communications/status.php');

        $this->assertStringContainsString("isChannelRuntimeReady(\$workspaceId, \$channel", $compose);
        $this->assertStringNotContainsString('PLUGIN_WHATSAPP_ASSISTANT', $compose);
        $this->assertStringContainsString("isChannelRuntimeReady(\$workspaceId, \$channel", $reply);
        $this->assertStringContainsString("isChannelRuntimeReady(\$workspaceId, \$channel", $drafts);
        $this->assertStringContainsString('PLUGIN_WHATSAPP_ASSISTANT', $status);
        $this->assertStringContainsString("'assistant' =>", $status);
        $this->assertStringContainsString('Optional. Install only for internal team commands and digests.', $status);
    }

    public function testQuickCaptureEndpointsExposeAuthoritativeResultRoutes(): void
    {
        $tasks = $this->readApiSource('tasks/create.php');
        $contacts = $this->readApiSource('contacts.php');
        $deals = $this->readApiSource('deals.php');
        $events = $this->readApiSource('events.php');
        $activities = $this->readApiSource('activities.php');

        $this->assertStringContainsString("'mobile_quick_capture'", $tasks);
        $this->assertStringContainsString('$allowedSourceSurfaces', $tasks);
        $this->assertStringContainsString("'result_route' => '/tasks/'", $tasks);
        $this->assertStringContainsString("'result_route' => '/contacts/'", $contacts);
        $this->assertStringContainsString("'result_route' => '/deals/'", $deals);
        $this->assertStringContainsString("'result_route' => '/calendar/'", $events);
        $this->assertStringContainsString("'result_route' => '/contacts/'", $activities);
    }

    private function readApiSource(string $relativePath): string
    {
        $path = __DIR__ . '/../../../api/mobile/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function readServiceSource(string $relativePath): string
    {
        $path = __DIR__ . '/../../../services/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
