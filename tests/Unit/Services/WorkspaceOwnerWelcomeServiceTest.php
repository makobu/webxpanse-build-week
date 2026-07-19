<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailService;
use CRM\Services\EmailTemplates;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOwnerWelcomeService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceOwnerWelcomeServiceTest extends DatabaseTestCase
{
    public function testWelcomeEmailSendsAndRecordsOnboardingNudge(): void
    {
        $seed = $this->provisionWorkspace('welcome.owner@customer.com');
        $email = new FakeWorkspaceOwnerWelcomeEmailService();
        $templates = new FakeWorkspaceOwnerWelcomeTemplates();

        $result = (new WorkspaceOwnerWelcomeService(null, $email, $templates))->sendForProvisionedWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            (int) $seed['user_id'],
            'public_signup'
        );

        $this->assertSame('sent', (string) ($result['status'] ?? ''));
        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame(1, $templates->workspaceIdDuringRender);
        $this->assertCount(1, $email->sends);
        $this->assertSame('welcome.owner@customer.com', $email->sends[0]['to']);
        $this->assertSame(1, (int) ($email->sends[0]['options']['workspace_id'] ?? 0));
        $this->assertSame('platform-ops-owner_welcome_setup', (string) ($email->sends[0]['options']['source_template_slug'] ?? ''));

        $nudge = $this->latestWelcomeNudge((int) $seed['workspace_id'], (int) $seed['user_id']);
        $metadata = json_decode((string) ($nudge['metadata_json'] ?? '{}'), true);

        $this->assertSame('sent', (string) ($nudge['status'] ?? ''));
        $this->assertSame('email', (string) ($nudge['channel'] ?? ''));
        $this->assertSame('workspace_created_welcome', (string) ($nudge['trigger_key'] ?? ''));
        $this->assertSame('onboarding.php?signup_success=1', (string) ($nudge['action_url'] ?? ''));
        $this->assertSame('public_signup', (string) ($metadata['source'] ?? ''));
        $this->assertSame('fake-email-1', (string) ($metadata['delivery']['email_uuid'] ?? ''));
        $this->assertNotEmpty($nudge['sent_at'] ?? null);

        $audit = Database::queryOne(
            "SELECT action_type
             FROM operator_audit_log
             WHERE target_workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        $this->assertSame('workspace_owner_welcome_sent', (string) ($audit['action_type'] ?? ''));
    }

    public function testWelcomeEmailSkipsWhenSentNudgeAlreadyExists(): void
    {
        $seed = $this->provisionWorkspace('welcome.duplicate@customer.com');
        $email = new FakeWorkspaceOwnerWelcomeEmailService();
        $service = new WorkspaceOwnerWelcomeService(null, $email, new FakeWorkspaceOwnerWelcomeTemplates());

        $first = $service->sendForProvisionedWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $seed['user_id'], 'public_signup');
        $second = $service->sendForProvisionedWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], (int) $seed['user_id'], 'public_signup');

        $this->assertSame('sent', (string) ($first['status'] ?? ''));
        $this->assertSame('skipped', (string) ($second['status'] ?? ''));
        $this->assertSame('already_sent', (string) ($second['reason'] ?? ''));
        $this->assertCount(1, $email->sends);

        $sentCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_onboarding_nudges
             WHERE workspace_id = ?
               AND owner_user_id = ?
               AND trigger_key = 'workspace_created_welcome'
               AND channel = 'email'
               AND status = 'sent'",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        )['c'] ?? 0);
        $this->assertSame(1, $sentCount);
    }

    public function testDeliveryFailureRecordsFailedNudgeWithoutBreakingProvisionedWorkspace(): void
    {
        $seed = $this->provisionWorkspace('welcome.failure@customer.com');
        $email = new FakeWorkspaceOwnerWelcomeEmailService(['success' => false, 'error' => 'SMTP unavailable']);

        $result = (new WorkspaceOwnerWelcomeService(null, $email, new FakeWorkspaceOwnerWelcomeTemplates()))->sendForProvisionedWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            (int) $seed['user_id'],
            'platform_admin_provisioning'
        );

        $this->assertSame('failed', (string) ($result['status'] ?? ''));
        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertSame('SMTP unavailable', (string) ($result['error'] ?? ''));
        $this->assertNotNull(Database::queryOne("SELECT id FROM workspaces WHERE id = ?", [(int) $seed['workspace_id']]));
        $this->assertNotNull(Database::queryOne("SELECT id FROM users WHERE id = ?", [(int) $seed['user_id']]));

        $nudge = $this->latestWelcomeNudge((int) $seed['workspace_id'], (int) $seed['user_id']);
        $metadata = json_decode((string) ($nudge['metadata_json'] ?? '{}'), true);

        $this->assertSame('failed', (string) ($nudge['status'] ?? ''));
        $this->assertSame('SMTP unavailable', (string) ($nudge['delivery_error'] ?? ''));
        $this->assertSame('platform_admin_provisioning', (string) ($metadata['source'] ?? ''));
        $this->assertSame('fake-email-1', (string) ($metadata['delivery']['email_uuid'] ?? ''));
    }

    public function testTemplateFailureRecordsFailedNudgeWithoutSendingEmail(): void
    {
        $seed = $this->provisionWorkspace('welcome.template-failure@customer.com');
        $email = new FakeWorkspaceOwnerWelcomeEmailService();

        $result = (new WorkspaceOwnerWelcomeService(null, $email, new FailingWorkspaceOwnerWelcomeTemplates()))->sendForProvisionedWorkspace(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            (int) $seed['user_id'],
            'public_signup'
        );

        $this->assertSame('failed', (string) ($result['status'] ?? ''));
        $this->assertStringContainsString('Template unavailable', (string) ($result['error'] ?? ''));
        $this->assertCount(0, $email->sends);

        $nudge = $this->latestWelcomeNudge((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertSame('failed', (string) ($nudge['status'] ?? ''));
        $this->assertStringContainsString('Template unavailable', (string) ($nudge['delivery_error'] ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Welcome ' . bin2hex(random_bytes(3)),
            'first_name' => 'Welcome',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function latestWelcomeNudge(int $workspaceId, int $ownerUserId): array
    {
        return Database::queryOne(
            "SELECT *
             FROM workspace_onboarding_nudges
             WHERE workspace_id = ?
               AND owner_user_id = ?
               AND trigger_key = 'workspace_created_welcome'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $ownerUserId]
        ) ?: [];
    }
}

class FakeWorkspaceOwnerWelcomeEmailService extends EmailService
{
    /** @var array<int,array<string,mixed>> */
    public array $sends = [];

    /** @var array<string,mixed>|null */
    private ?array $failure;

    /**
     * @param array<string,mixed>|null $failure
     */
    public function __construct(?array $failure = null)
    {
        $this->failure = $failure;
    }

    public function sendImmediateDetailed(int $contactId, string $to, string $subject, string $body, array $options = []): array
    {
        $uuid = 'fake-email-' . (count($this->sends) + 1);
        $this->sends[] = [
            'contact_id' => $contactId,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'options' => $options,
            'uuid' => $uuid,
        ];

        if ($this->failure !== null) {
            return array_replace([
                'success' => false,
                'status' => 'failed',
                'error' => 'SMTP unavailable',
                'email_uuid' => $uuid,
                'provider_key' => 'fake',
                'smtp_method' => 'fake',
            ], $this->failure);
        }

        return [
            'success' => true,
            'status' => 'sent',
            'email_uuid' => $uuid,
            'provider_key' => 'fake',
            'smtp_method' => 'fake',
            'communication_id' => count($this->sends),
        ];
    }
}

class FakeWorkspaceOwnerWelcomeTemplates extends EmailTemplates
{
    public int $workspaceIdDuringRender = 0;

    public function __construct()
    {
    }

    public function render(string $templateSlug, array $variables = []): array
    {
        $this->workspaceIdDuringRender = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

        return [
            'subject' => 'Welcome to Clarity, ' . (string) ($variables['owner_name'] ?? 'there'),
            'body_text' => 'Start setup: ' . (string) ($variables['setup_url'] ?? ''),
            'body_html' => '<p>Start setup: ' . htmlspecialchars((string) ($variables['setup_url'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>',
        ];
    }
}

class FailingWorkspaceOwnerWelcomeTemplates extends EmailTemplates
{
    public function __construct()
    {
    }

    public function render(string $templateSlug, array $variables = []): array
    {
        throw new \RuntimeException('Template unavailable');
    }
}
