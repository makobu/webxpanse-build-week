<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceOwnerWelcomeService
{
    private const TEMPLATE_SLUG = 'platform-ops-owner_welcome_setup';
    private const TRIGGER_KEY = 'workspace_created_welcome';
    private const ACTION_URL = 'onboarding.php?signup_success=1';

    private DefaultWorkspaceService $defaultWorkspace;
    private DefaultWorkspaceOwnerContactService $ownerContacts;
    private EmailService $emailService;
    private EmailTemplates $templates;
    private OperatorAuditService $audit;
    private EmailLinkService $links;

    public function __construct(
        ?DefaultWorkspaceService $defaultWorkspace = null,
        ?EmailService $emailService = null,
        ?EmailTemplates $templates = null,
        ?OperatorAuditService $audit = null,
        ?DefaultWorkspaceOwnerContactService $ownerContacts = null,
        ?EmailLinkService $links = null
    ) {
        $this->defaultWorkspace = $defaultWorkspace ?? new DefaultWorkspaceService();
        $this->emailService = $emailService ?? new EmailService();
        $this->templates = $templates ?? new EmailTemplates();
        $this->audit = $audit ?? new OperatorAuditService();
        $this->ownerContacts = $ownerContacts ?? new DefaultWorkspaceOwnerContactService($this->defaultWorkspace);
        $this->links = $links ?? new EmailLinkService();
    }

    /**
     * @return array<string,mixed>
     */
    public function sendForProvisionedWorkspace(int $workspaceId, int $ownerUserId, ?int $actorUserId, string $source): array
    {
        $source = trim($source) !== '' ? trim($source) : 'workspace_provisioning';
        if ($workspaceId <= 0 || $ownerUserId <= 0) {
            return [
                'success' => false,
                'status' => 'failed',
                'error' => 'Workspace and owner user are required.',
            ];
        }

        $existingNudgeId = $this->existingSentNudgeId($workspaceId, $ownerUserId);
        if ($existingNudgeId > 0) {
            return [
                'success' => true,
                'status' => 'skipped',
                'reason' => 'already_sent',
                'nudge_id' => $existingNudgeId,
            ];
        }

        $subject = 'Welcome to ' . brandProductName();
        $bodyText = 'Your workspace is ready for setup.';
        $bodyHtml = '<p>Your workspace is ready for setup.</p>';
        $metadata = [
            'source' => $source,
            'template_slug' => self::TEMPLATE_SLUG,
            'trigger_key' => self::TRIGGER_KEY,
            'attempted_at' => gmdate('c'),
        ];

        try {
            $workspace = $this->workspaceRow($workspaceId);
            $owner = $this->ownerRow($ownerUserId);
            if ($workspace === null || $owner === null) {
                throw new \RuntimeException('Workspace or owner user was not found.');
            }

            $ownerEmail = strtolower(trim((string) ($owner['email'] ?? '')));
            if ($ownerEmail === '') {
                throw new \RuntimeException('Owner email is required for welcome delivery.');
            }

            $defaultWorkspaceId = $this->defaultWorkspace->id();
            $contactId = $this->resolveOwnerContactId($defaultWorkspaceId, $workspaceId, $ownerUserId, $actorUserId);
            if ($contactId <= 0) {
                throw new \RuntimeException('Default workspace owner contact is not available for welcome delivery.');
            }

            $variables = [
                'owner_name' => $this->ownerName($owner),
                'workspace_name' => (string) ($workspace['name'] ?? 'your workspace'),
                'setup_url' => $this->absolutePublicUrl(self::ACTION_URL),
                'support_url' => $this->absolutePublicUrl('dashboard.php'),
            ];
            $metadata['variables'] = [
                'workspace_name' => $variables['workspace_name'],
                'setup_url' => $variables['setup_url'],
                'support_url' => $variables['support_url'],
            ];

            $rendered = $this->renderDefaultWorkspaceTemplate($defaultWorkspaceId, $actorUserId, $variables);
            $subject = (string) ($rendered['subject'] ?? $subject);
            $bodyText = (string) ($rendered['body_text'] ?? $bodyText);
            $bodyHtml = (string) ($rendered['body_html'] ?? $bodyHtml);

            $delivery = $this->sendDefaultWorkspaceEmail(
                $defaultWorkspaceId,
                $contactId,
                $ownerEmail,
                $subject,
                $bodyText,
                $bodyHtml,
                $actorUserId
            );
            $metadata['delivery'] = $delivery;
            $metadata['default_workspace_id'] = $defaultWorkspaceId;
            $metadata['contact_id'] = $contactId;

            if (empty($delivery['success'])) {
                $error = trim((string) ($delivery['error'] ?? 'Workspace welcome email delivery failed.'));
                $nudgeId = $this->recordNudge($workspaceId, $ownerUserId, $actorUserId, 'failed', $subject, $bodyText, $metadata, $error);
                $this->auditDelivery('workspace_owner_welcome_failed', $workspaceId, $ownerUserId, $actorUserId, $source, $nudgeId, $metadata, $error);
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error' => $error,
                    'nudge_id' => $nudgeId,
                    'delivery' => $delivery,
                ];
            }

            $nudgeId = $this->recordNudge($workspaceId, $ownerUserId, $actorUserId, 'sent', $subject, $bodyText, $metadata);
            $this->auditDelivery('workspace_owner_welcome_sent', $workspaceId, $ownerUserId, $actorUserId, $source, $nudgeId, $metadata);

            return [
                'success' => true,
                'status' => 'sent',
                'nudge_id' => $nudgeId,
                'delivery' => $delivery,
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $metadata['error'] = $error;
            $nudgeId = $this->recordNudge($workspaceId, $ownerUserId, $actorUserId, 'failed', $subject, $bodyText, $metadata, $error);
            $this->auditDelivery('workspace_owner_welcome_failed', $workspaceId, $ownerUserId, $actorUserId, $source, $nudgeId, $metadata, $error);
            error_log('Workspace owner welcome delivery failed: ' . $error);

            return [
                'success' => false,
                'status' => 'failed',
                'error' => $error,
                'nudge_id' => $nudgeId,
            ];
        }
    }

    private function existingSentNudgeId(int $workspaceId, int $ownerUserId): int
    {
        if (!$this->nudgeTableReady()) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM workspace_onboarding_nudges
             WHERE workspace_id = ?
               AND owner_user_id = ?
               AND trigger_key = ?
               AND channel = 'email'
               AND status = 'sent'
             ORDER BY id ASC
             LIMIT 1",
            [$workspaceId, $ownerUserId, self::TRIGGER_KEY]
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function workspaceRow(int $workspaceId): ?array
    {
        return Database::queryOne(
            "SELECT id, name, slug, status, plan_status
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ownerRow(int $ownerUserId): ?array
    {
        return Database::queryOne(
            "SELECT id, first_name, last_name, email
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$ownerUserId]
        ) ?: null;
    }

    private function resolveOwnerContactId(int $defaultWorkspaceId, int $workspaceId, int $ownerUserId, ?int $actorUserId): int
    {
        $mappedContactId = $this->mappedOwnerContactId($defaultWorkspaceId, $workspaceId, $ownerUserId);
        if ($mappedContactId > 0) {
            return $mappedContactId;
        }

        $sync = $this->ownerContacts->syncOwnerForWorkspace($workspaceId, $ownerUserId, $actorUserId);
        $contactId = (int) ($sync['contact_id'] ?? 0);
        if ($contactId > 0) {
            return $contactId;
        }

        return $this->mappedOwnerContactId($defaultWorkspaceId, $workspaceId, $ownerUserId);
    }

    private function mappedOwnerContactId(int $defaultWorkspaceId, int $workspaceId, int $ownerUserId): int
    {
        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT contact_id
             FROM default_workspace_owner_contacts
             WHERE default_workspace_id = ?
               AND owner_workspace_id = ?
               AND owner_user_id = ?
               AND relationship_status = 'active'
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $workspaceId, $ownerUserId]
        );

        return (int) ($row['contact_id'] ?? 0);
    }

    /**
     * @param array<string,string> $variables
     * @return array<string,string>
     */
    private function renderDefaultWorkspaceTemplate(int $defaultWorkspaceId, ?int $actorUserId, array $variables): array
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($defaultWorkspaceId, $actorUserId, 'superadmin');
        try {
            return $this->templates->render(self::TEMPLATE_SLUG, $variables);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function sendDefaultWorkspaceEmail(
        int $defaultWorkspaceId,
        int $contactId,
        string $ownerEmail,
        string $subject,
        string $bodyText,
        string $bodyHtml,
        ?int $actorUserId
    ): array {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($defaultWorkspaceId, $actorUserId, 'superadmin');
        try {
            return $this->emailService->sendImmediateDetailed(
                $contactId,
                $ownerEmail,
                $subject,
                $bodyText,
                [
                    'body_html' => $bodyHtml,
                    'workspace_id' => $defaultWorkspaceId,
                    'user_id' => $actorUserId,
                    'priority' => 5,
                    'source_template_slug' => self::TEMPLATE_SLUG,
                    'draft_source' => 'workspace_owner_welcome',
                    'draft_intention' => self::TRIGGER_KEY,
                ]
            );
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function recordNudge(
        int $workspaceId,
        int $ownerUserId,
        ?int $actorUserId,
        string $status,
        string $subject,
        string $body,
        array $metadata,
        ?string $deliveryError = null
    ): int {
        if (!$this->nudgeTableReady()) {
            return 0;
        }

        $status = $status === 'sent' ? 'sent' : 'failed';
        Database::execute(
            "INSERT INTO workspace_onboarding_nudges
                (workspace_id, owner_user_id, created_by_user_id, trigger_key, channel, status, subject, body, action_url, metadata_json, sent_at, delivery_error)
             VALUES
                (?, ?, ?, ?, 'email', ?, ?, ?, ?, ?, IF(? = 'sent', NOW(), NULL), ?)",
            [
                $workspaceId,
                $ownerUserId,
                $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
                self::TRIGGER_KEY,
                $status,
                substr($subject, 0, 255),
                $body !== '' ? $body : 'Workspace welcome email delivery failed.',
                self::ACTION_URL,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $status,
                $deliveryError !== null && trim($deliveryError) !== '' ? substr(trim($deliveryError), 0, 1000) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function auditDelivery(
        string $actionType,
        int $workspaceId,
        int $ownerUserId,
        ?int $actorUserId,
        string $source,
        int $nudgeId,
        array $metadata,
        ?string $error = null
    ): void {
        try {
            $this->audit->log(
                $actionType,
                $actorUserId,
                $workspaceId,
                'Workspace owner welcome email from ' . $source,
                [
                    'nudge_id' => $nudgeId,
                    'source' => $source,
                    'template_slug' => self::TEMPLATE_SLUG,
                    'delivery' => $metadata['delivery'] ?? null,
                    'error' => $error,
                ],
                $ownerUserId
            );
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * @param array<string,mixed> $owner
     */
    private function ownerName(array $owner): string
    {
        $name = trim(implode(' ', array_filter([
            (string) ($owner['first_name'] ?? ''),
            (string) ($owner['last_name'] ?? ''),
        ])));

        return $name !== '' ? $name : 'there';
    }

    private function absolutePublicUrl(string $path): string
    {
        $relative = function_exists('publicUrl') ? publicUrl($path) : '/' . ltrim($path, '/');
        return $this->links->absolutePublicUrl($relative);
    }

    private function nudgeTableReady(): bool
    {
        try {
            return Database::tableExists('workspace_onboarding_nudges');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
