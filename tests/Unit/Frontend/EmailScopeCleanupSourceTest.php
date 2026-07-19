<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class EmailScopeCleanupSourceTest extends TestCase
{
    public function testLegacyEmailsPageScopesQueriesAndFallbackSendsToActiveWorkspace(): void
    {
        $emailsPage = (string) file_get_contents(__DIR__ . '/../../../public/emails.php');

        $this->assertStringContainsString('$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();', $emailsPage);
        $this->assertStringContainsString("\$emailVisibility = \$demoScope->visibilityClause('e', \$workspaceId);", $emailsPage);
        $this->assertStringContainsString('$emailScopeWhere = [\'e.workspace_id = ?\'];', $emailsPage);
        $this->assertStringContainsString('LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id', $emailsPage);
        $this->assertStringContainsString('SELECT e.status, COUNT(*) as count', $emailsPage);
        $this->assertStringContainsString('FROM contacts', $emailsPage);
        $this->assertStringContainsString('WHERE workspace_id = ?', $emailsPage);
        $this->assertStringContainsString('processEmail($emailId, $lastError, [], $workspaceId)', $emailsPage);
        $this->assertStringContainsString('processEmailByUuid($emailUuid, $lastError, [], $workspaceId)', $emailsPage);
        $this->assertStringNotContainsString('SELECT status, COUNT(*) as count FROM emails GROUP BY status', $emailsPage);
        $this->assertStringNotContainsString('LEFT JOIN contacts c ON e.contact_id = c.id' . "\n     \$whereClause", $emailsPage);
    }

    public function testMarketplaceRendersExplicitEmailDisconnectActions(): void
    {
        $workspaceSkills = (string) file_get_contents(__DIR__ . '/../../../public/workspace_skills.php');
        $marketplaceSetup = (string) file_get_contents(__DIR__ . '/../../../views/partials/marketplace_plugin_setup.php');

        $this->assertStringContainsString("disconnect_communication_email", $workspaceSkills);
        $this->assertStringContainsString("clear_email_assistant_mail", $workspaceSkills);
        $this->assertStringContainsString('Disconnect and clear <?php echo htmlspecialchars($label); ?>', $marketplaceSetup);
        $this->assertStringContainsString('Clear assistant mail settings', $marketplaceSetup);
    }

    public function testMarketplaceDistinguishesSendingReadyFromFullyReady(): void
    {
        $workspaceSkills = (string) file_get_contents(__DIR__ . '/../../../public/workspace_skills.php');
        $marketplaceSetup = (string) file_get_contents(__DIR__ . '/../../../views/partials/marketplace_plugin_setup.php');
        $catalogStatus = (string) file_get_contents(__DIR__ . '/../../../api/workspace/marketplace_catalog_status.php');
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/marketplace.css');

        $this->assertStringContainsString("'sending_ready'", $workspaceSkills);
        $this->assertStringContainsString('Sending ready', $workspaceSkills);
        $this->assertStringContainsString('Inbox setup optional', $workspaceSkills);
        $this->assertStringContainsString("'sending_ready'", $catalogStatus);
        $this->assertStringContainsString('is-warning', $catalogStatus);
        $this->assertStringContainsString("status === 'warning'", $marketplaceSetup);
        $this->assertStringContainsString('.marketplace-status.is-warning', $css);
        $this->assertStringContainsString('.marketplace-status-dot.is-warning', $css);
    }

    public function testIncomingFetchUsesWorkspaceEmailProfiles(): void
    {
        $fetcher = (string) file_get_contents(__DIR__ . '/../../../services/EmailFetcher.php');
        $api = (string) file_get_contents(__DIR__ . '/../../../api/fetch_emails.php');
        $worker = (string) file_get_contents(__DIR__ . '/../../../cli/fetch_incoming_emails.php');

        $this->assertStringContainsString("getManualImapConfigForRole", $fetcher);
        $this->assertStringContainsString("new EmailFetcher(\$profile)", $api);
        $this->assertStringContainsString("listActiveRoleIntegrations(['outreach', 'nurture'])", $worker);
        $this->assertStringNotContainsString("listActiveMainIntegrations()", $worker);
    }

    public function testSettingsDoesNotSavePlatformAssistantMailboxDefaults(): void
    {
        $settings = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertStringContainsString('Assistant Mailbox Setup', $settings);
        $this->assertStringContainsString('workspace_skills.php?module=email_assistant&amp;setup_tab=identity#setup', $settings);
        $this->assertStringContainsString('Legacy assistant command address', $settings);
        $this->assertStringNotContainsString('copy_main_to_assistant', $settings);
        $this->assertStringNotContainsString('Copy from Main Email settings', $settings);
        $this->assertStringNotContainsString('platformEmailDefaults->save(EmailIntegrationService::SCOPE_ASSISTANT_EMAIL', $settings);
    }

    public function testRoutingSourcesUseExplicitMailProfiles(): void
    {
        $inviteMailer = (string) file_get_contents(__DIR__ . '/../../../services/WorkspaceInviteMailer.php');
        $bulkMessaging = (string) file_get_contents(__DIR__ . '/../../../services/BulkMessagingService.php');
        $conversationReplies = (string) file_get_contents(__DIR__ . '/../../../services/ConversationEmailReplyService.php');
        $assistantExecution = (string) file_get_contents(__DIR__ . '/../../../services/EmailAssistantExecutionService.php');
        $automationEngine = (string) file_get_contents(__DIR__ . '/../../../modules/AutomationEngine.php');
        $legacyEmailView = (string) file_get_contents(__DIR__ . '/../../../public/email_view.php');
        $draftReview = (string) file_get_contents(__DIR__ . '/../../../public/draft_review.php');
        $emailScheduler = (string) file_get_contents(__DIR__ . '/../../../modules/EmailScheduler.php');
        $marketing = (string) file_get_contents(__DIR__ . '/../../../modules/Marketing.php');
        $templateTestApi = (string) file_get_contents(__DIR__ . '/../../../api/email_template_test.php');
        $aiAutoResponder = (string) file_get_contents(__DIR__ . '/../../../services/AIAutoResponderDispatcher.php');
        $smtpClient = (string) file_get_contents(__DIR__ . '/../../../services/SMTPClient.php');

        $this->assertStringContainsString('return new SMTPClient();', $inviteMailer);
        $this->assertStringContainsString("'sender_profile' => 'outreach'", $bulkMessaging);
        $this->assertStringContainsString("\$sendOptions['sender_profile'] = 'nurture';", $bulkMessaging);
        $this->assertStringContainsString("'sender_profile' => 'outreach'", $conversationReplies);
        $this->assertStringContainsString("'sender_profile' => 'assistant'", $assistantExecution);
        $this->assertStringContainsString("\$action['sender_profile'] ?? \$action['smtp_profile'] ?? 'outreach'", $automationEngine);
        $this->assertStringContainsString("'sender_profile' => \$senderProfile", $automationEngine);
        $this->assertStringContainsString("'sender_profile' => in_array((string) (\$email['sender_profile'] ?? '')", $legacyEmailView);
        $this->assertStringContainsString("'sender_profile' => 'outreach'", $draftReview);
        $this->assertStringContainsString("\$options['sender_profile'] = \$options['sender_profile'] ?? \$options['smtp_profile'] ?? 'outreach';", $emailScheduler);
        $this->assertStringContainsString("'sender_profile' => 'outreach'", $marketing);
        $this->assertStringContainsString("new SMTPClient('outreach')", $templateTestApi);
        $this->assertStringContainsString("'sender_profile' => \$senderProfile", $aiAutoResponder);
        $this->assertStringContainsString("default => null", $smtpClient);
        $this->assertStringContainsString("'assistant' => \$this->emailIntegrationService->getAuthorizedAssistantIntegration()", $smtpClient);
    }
}
