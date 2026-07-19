<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceOwnerContactService
{
    private const SOURCE = 'default_workspace_owner_contact';
    private DefaultWorkspaceService $defaultWorkspace;
    private SystemContextRegistryService $contextRegistry;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?SystemContextRegistryService $contextRegistry = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->contextRegistry = $contextRegistry ?: new SystemContextRegistryService();
    }

    /**
     * @return array<string,mixed>
     */
    public function syncOwnerForWorkspace(int $workspaceId, int $ownerUserId, ?int $actorUserId = null): array
    {
        if ($workspaceId <= 0 || $ownerUserId <= 0) {
            throw new \RuntimeException('Workspace and owner user are required.');
        }

        $defaultWorkspace = $this->defaultWorkspace->resolve();
        $defaultWorkspaceId = (int) ($defaultWorkspace['id'] ?? 0);
        if ($workspaceId === $defaultWorkspaceId || $this->defaultWorkspace->isDefaultWorkspace($workspaceId)) {
            return ['status' => 'skipped', 'reason' => 'default_workspace_owner'];
        }

        $workspace = Database::queryOne(
            "SELECT id, name, slug, status, plan_status
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );
        $owner = Database::queryOne(
            "SELECT id, first_name, last_name, email
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$ownerUserId]
        );
        if (!$workspace || !$owner) {
            throw new \RuntimeException('Workspace owner contact sync could not find the workspace or owner.');
        }
        if (!$this->isActiveWorkspace($workspace)) {
            $inactive = $this->markInactiveRelationship($defaultWorkspaceId, $workspace, $ownerUserId, $actorUserId);
            return array_merge([
                'status' => 'skipped',
                'reason' => 'workspace_not_active_package',
                'workspace_id' => $defaultWorkspaceId,
                'owner_workspace_id' => (int) $workspaceId,
            ], $inactive);
        }

        $contact = $this->findExistingContact($defaultWorkspaceId, $ownerUserId, (string) ($owner['email'] ?? ''));
        $metadata = $this->buildMetadata($workspace, $ownerUserId, $actorUserId, $contact['metadata_json'] ?? null);
        $contactPolicy = $this->contextRegistry->ownerContactPolicyForScope((string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $payload = [
            'first_name' => trim((string) ($owner['first_name'] ?? '')) ?: 'Workspace',
            'last_name' => trim((string) ($owner['last_name'] ?? '')) ?: 'Owner',
            'email' => strtolower(trim((string) ($owner['email'] ?? ''))),
            'company' => trim((string) ($workspace['name'] ?? '')),
            'assigned_to' => $this->resolveDefaultAssignee($defaultWorkspaceId, $actorUserId),
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'stage' => (string) ($contactPolicy['stage'] ?? 'qualified'),
            'lead_score' => (int) ($contactPolicy['lead_score'] ?? 75),
        ];
        if ($payload['email'] === '') {
            throw new \RuntimeException('Workspace owner contact sync requires an owner email.');
        }

        if (!empty($contact['id'])) {
            Database::execute(
                "UPDATE contacts
                 SET first_name = ?, last_name = ?, email = ?, company = ?, lead_source = 'other',
                     stage = ?,
                     lead_score = GREATEST(COALESCE(lead_score, 0), ?),
                     assigned_to = ?, metadata_json = ?, updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [
                    $payload['first_name'],
                    $payload['last_name'],
                    $payload['email'],
                    $payload['company'],
                    $payload['stage'],
                    $payload['lead_score'],
                    $payload['assigned_to'],
                    $payload['metadata_json'],
                    $defaultWorkspaceId,
                    (int) $contact['id'],
                ]
            );

            $notificationId = $this->ensureWorkspaceCreatedNotification($defaultWorkspaceId, (int) $workspaceId, $payload['assigned_to'], $workspace, $owner);
            $this->upsertOwnerContactMap($defaultWorkspaceId, (int) $workspaceId, $ownerUserId, (int) $contact['id'], $metadata, $actorUserId);
            $this->markOwnerContactMapSynced($defaultWorkspaceId, (int) $workspaceId, $ownerUserId);
            $conversionDeal = $this->syncConversionDeal($defaultWorkspaceId, (int) $workspaceId, $ownerUserId, (int) $contact['id'], $actorUserId);

            return [
                'status' => 'updated',
                'contact_id' => (int) $contact['id'],
                'workspace_id' => $defaultWorkspaceId,
                'notification_id' => $notificationId,
                'conversion_deal_id' => $conversionDeal['deal_id'] ?? null,
            ];
        }

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, company, lead_source, stage, assigned_to, created_by, lead_score, metadata_json, created_at, updated_at)
             VALUES
                (?, UUID(), ?, ?, ?, ?, 'other', ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $defaultWorkspaceId,
                $payload['first_name'],
                $payload['last_name'],
                $payload['email'],
                $payload['company'],
                $payload['stage'],
                $payload['assigned_to'],
                $actorUserId ?: $payload['assigned_to'],
                $payload['lead_score'],
                $payload['metadata_json'],
            ]
        );

        $contactId = (int) Database::lastInsertId();
        $notificationId = $this->ensureWorkspaceCreatedNotification($defaultWorkspaceId, (int) $workspaceId, $payload['assigned_to'], $workspace, $owner);
        $this->upsertOwnerContactMap($defaultWorkspaceId, (int) $workspaceId, $ownerUserId, $contactId, $metadata, $actorUserId);
        $this->markOwnerContactMapSynced($defaultWorkspaceId, (int) $workspaceId, $ownerUserId);
        $conversionDeal = $this->syncConversionDeal($defaultWorkspaceId, (int) $workspaceId, $ownerUserId, $contactId, $actorUserId);

        return [
            'status' => 'created',
            'contact_id' => $contactId,
            'workspace_id' => $defaultWorkspaceId,
            'notification_id' => $notificationId,
            'conversion_deal_id' => $conversionDeal['deal_id'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function statusForOwner(int $ownerUserId): ?array
    {
        if ($ownerUserId <= 0) {
            return null;
        }

        $defaultWorkspace = $this->defaultWorkspace->resolve();
        if (Database::tableExists('default_workspace_owner_contacts')) {
            $mapped = Database::queryOne(
                "SELECT c.id, c.workspace_id, c.first_name, c.last_name, c.email, c.company, c.stage, c.lead_score, c.metadata_json,
                        map.relationship_status, map.customer_state, map.owner_workspace_id
                 FROM default_workspace_owner_contacts map
                 JOIN contacts c ON c.id = map.contact_id AND c.workspace_id = map.default_workspace_id
                 WHERE map.default_workspace_id = ?
                   AND map.owner_user_id = ?
                 ORDER BY map.id ASC
                 LIMIT 1",
                [(int) ($defaultWorkspace['id'] ?? 0), $ownerUserId]
            );
            if ($mapped) {
                return $mapped;
            }
        }

        $contacts = Database::query(
            "SELECT id, workspace_id, first_name, last_name, email, company, stage, lead_score, metadata_json
             FROM contacts
             WHERE workspace_id = ?
               AND metadata_json IS NOT NULL
               AND metadata_json LIKE ?",
            [(int) ($defaultWorkspace['id'] ?? 0), '%' . self::SOURCE . '%']
        );
        foreach ($contacts as $contact) {
            $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
            if ((int) ($metadata['owner_user_id'] ?? 0) === $ownerUserId) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function isCurrentPayingWorkspace(int $workspaceId): bool
    {
        return !empty($this->getWorkspacePaymentState($workspaceId)['is_current_paying_customer']);
    }

    /**
     * @return array<string,mixed>
     */
    public function getWorkspacePaymentState(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return ['is_current_paying_customer' => false];
        }

        $subscription = Database::queryOne(
            "SELECT id, subscription_status, current_period_start, current_period_end, next_billing_at
             FROM workspace_subscriptions
             WHERE workspace_id = ?
               AND subscription_status = 'active'
               AND current_period_end IS NOT NULL
               AND current_period_end >= NOW()
             ORDER BY current_period_end DESC, id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $subscriptionId = (int) ($subscription['id'] ?? 0);
        $paidTransaction = $subscriptionId > 0 ? Database::queryOne(
            "SELECT id, amount, currency, created_at
             FROM billing_transactions
             WHERE workspace_id = ?
               AND subscription_id = ?
               AND transaction_type = 'subscription_charge'
               AND transaction_status = 'succeeded'
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$workspaceId, $subscriptionId]
        ) : null;

        return [
            'is_current_paying_customer' => $subscriptionId > 0 && $paidTransaction !== null,
            'subscription_id' => $subscriptionId ?: null,
            'subscription_status' => $subscription['subscription_status'] ?? null,
            'current_period_start' => $subscription['current_period_start'] ?? null,
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'next_billing_at' => $subscription['next_billing_at'] ?? null,
            'last_subscription_payment_id' => $paidTransaction['id'] ?? null,
            'last_subscription_payment_amount' => $paidTransaction['amount'] ?? null,
            'last_subscription_payment_currency' => $paidTransaction['currency'] ?? null,
            'last_subscription_payment_at' => $paidTransaction['created_at'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findExistingContact(int $defaultWorkspaceId, int $ownerUserId, string $email): ?array
    {
        if (Database::tableExists('default_workspace_owner_contacts')) {
            $mapped = Database::queryOne(
                "SELECT c.id, c.email, c.stage, c.lead_score, c.metadata_json
                 FROM default_workspace_owner_contacts map
                 JOIN contacts c ON c.id = map.contact_id AND c.workspace_id = map.default_workspace_id
                 WHERE map.default_workspace_id = ?
                   AND map.owner_user_id = ?
                 ORDER BY map.id ASC
                 LIMIT 1",
                [$defaultWorkspaceId, $ownerUserId]
            );
            if ($mapped) {
                return $mapped;
            }
        }

        $contacts = Database::query(
            "SELECT id, email, metadata_json
             FROM contacts
             WHERE workspace_id = ?
               AND metadata_json IS NOT NULL
               AND metadata_json LIKE ?",
            [$defaultWorkspaceId, '%' . self::SOURCE . '%']
        );
        foreach ($contacts as $contact) {
            $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
            if ((int) ($metadata['owner_user_id'] ?? 0) === $ownerUserId) {
                return $contact;
            }
        }

        if (trim($email) === '') {
            return null;
        }

        return Database::queryOne(
            "SELECT id, email, stage, lead_score, metadata_json
             FROM contacts
             WHERE workspace_id = ? AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $email]
        ) ?: null;
    }

    private function resolveDefaultAssignee(int $defaultWorkspaceId, ?int $actorUserId): ?int
    {
        if ($actorUserId !== null && $actorUserId > 0) {
            $membership = Database::queryOne(
                "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
                [$defaultWorkspaceId, $actorUserId]
            );
            if ($membership) {
                return $actorUserId;
            }
        }

        $owner = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ? AND membership_status = 'active'
             ORDER BY is_owner DESC, FIELD(role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), id ASC
             LIMIT 1",
            [$defaultWorkspaceId]
        );

        return !empty($owner['user_id']) ? (int) $owner['user_id'] : null;
    }

    /**
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $owner
     */
    private function ensureWorkspaceCreatedNotification(int $defaultWorkspaceId, int $createdWorkspaceId, ?int $assigneeUserId, array $workspace, array $owner): ?int
    {
        if ($defaultWorkspaceId <= 0 || $createdWorkspaceId <= 0 || $assigneeUserId === null || $assigneeUserId <= 0) {
            return null;
        }

        $existing = Database::queryOne(
            "SELECT id
             FROM notifications
             WHERE workspace_id = ?
               AND type = 'workspace_created'
               AND entity_type = 'workspace'
               AND entity_id = ?
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $createdWorkspaceId]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $workspaceName = trim((string) ($workspace['name'] ?? 'Workspace'));
        $ownerName = trim(implode(' ', array_filter([
            (string) ($owner['first_name'] ?? ''),
            (string) ($owner['last_name'] ?? ''),
        ]))) ?: (string) ($owner['email'] ?? 'workspace owner');
        $message = sprintf(
            '%s created %s. Review onboarding, billing, owner contact details, and any setup risk from Platform Ops.',
            $ownerName,
            $workspaceName
        );

        Database::execute(
            "INSERT INTO notifications
                (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity, ai_insight, ai_action, is_read, created_at)
             VALUES
                (?, ?, 'workspace_created', ?, ?, 'workspace', ?, ?, 'info', ?, ?, 0, NOW())",
            [
                $defaultWorkspaceId,
                $assigneeUserId,
                'New workspace created: ' . $workspaceName,
                $message,
                $createdWorkspaceId,
                'workspace_admin.php?id=' . $createdWorkspaceId,
                'Owner has been added as a Platform Ops contact for helpline follow-up.',
                'Open workspace admin',
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    private function buildMetadata(array $workspace, int $ownerUserId, ?int $actorUserId, mixed $existingMetadata): array
    {
        $metadata = $this->decodeJson($existingMetadata);
        if ($this->isUnqualifiedDemoProspect($metadata)) {
            $metadata['converted_from_demo_prospect'] = true;
            $metadata['demo_prospect_qualified_at'] = $metadata['demo_prospect_qualified_at'] ?? gmdate('c');
            $metadata['demo_prospect_original_source'] = $metadata['demo_prospect_original_source']
                ?? (string) ($metadata['source'] ?? 'default_workspace_demo_visitor');
            $metadata['demo_prospect_original_scope'] = $metadata['demo_prospect_original_scope']
                ?? (string) ($metadata['default_workspace_contact_scope'] ?? 'unqualified_demo_prospect');
            if (!empty($metadata['demo_session_uuid'])) {
                $metadata['demo_prospect_session_uuid'] = $metadata['demo_prospect_session_uuid'] ?? $metadata['demo_session_uuid'];
            }
            if (!empty($metadata['captured_at'])) {
                $metadata['demo_prospect_captured_at'] = $metadata['demo_prospect_captured_at'] ?? $metadata['captured_at'];
            }
        }

        $paymentState = $this->getWorkspacePaymentState((int) ($workspace['id'] ?? 0));
        $isCurrentPayingCustomer = !empty($paymentState['is_current_paying_customer']);
        $policy = $this->contextRegistry->ownerContactPolicy($isCurrentPayingCustomer);
        $metadata['source'] = self::SOURCE;
        $metadata['owner_user_id'] = $ownerUserId;
        $metadata['owner_workspace_id'] = (int) ($workspace['id'] ?? 0);
        $metadata['owner_workspace_name'] = (string) ($workspace['name'] ?? '');
        $metadata['owner_workspace_slug'] = (string) ($workspace['slug'] ?? '');
        $metadata['owner_workspace_status'] = (string) ($workspace['status'] ?? '');
        $metadata['owner_workspace_plan_status'] = (string) ($workspace['plan_status'] ?? '');
        $metadata['relationship'] = 'workspace_owner';
        $metadata['helpline_contact'] = true;
        $metadata['default_workspace_nurture_qualified'] = !empty($policy['default_workspace_nurture_qualified']);
        $metadata['default_workspace_contact_scope'] = (string) ($policy['scope'] ?? 'qualified_workspace_lead');
        $metadata['default_workspace_use'] = (string) ($policy['default_workspace_use'] ?? 'lead_to_customer_conversion');
        $metadata['current_paying_customer'] = !empty($policy['current_paying_customer']);
        $metadata['subscription_payment_state'] = $paymentState;
        $metadata['marketing_conversion_allowed'] = !empty($policy['marketing_conversion_allowed']);
        $metadata['last_synced_at'] = gmdate('c');
        if ($actorUserId !== null && $actorUserId > 0) {
            $metadata['last_synced_by_user_id'] = $actorUserId;
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function isUnqualifiedDemoProspect(array $metadata): bool
    {
        return (string) ($metadata['source'] ?? '') === 'default_workspace_demo_visitor'
            || (string) ($metadata['default_workspace_contact_scope'] ?? '') === 'unqualified_demo_prospect'
            || (string) ($metadata['relationship'] ?? '') === 'demo_visitor';
    }

    /**
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    private function markInactiveRelationship(int $defaultWorkspaceId, array $workspace, int $ownerUserId, ?int $actorUserId): array
    {
        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return [];
        }

        $existing = Database::queryOne(
            "SELECT map.id, map.contact_id, c.id AS contact_row_id, c.metadata_json
             FROM default_workspace_owner_contacts map
             LEFT JOIN contacts c ON c.id = map.contact_id AND c.workspace_id = map.default_workspace_id
             WHERE map.default_workspace_id = ?
               AND map.owner_workspace_id = ?
               AND map.owner_user_id = ?
             LIMIT 1",
            [$defaultWorkspaceId, (int) ($workspace['id'] ?? 0), $ownerUserId]
        );

        if (!$existing) {
            return ['relationship_status' => 'inactive'];
        }

        $metadata = $this->decodeJson($existing['metadata_json'] ?? null);
        $metadata['source'] = self::SOURCE;
        $metadata['owner_user_id'] = $ownerUserId;
        $metadata['owner_workspace_id'] = (int) ($workspace['id'] ?? 0);
        $metadata['owner_workspace_name'] = (string) ($workspace['name'] ?? '');
        $metadata['owner_workspace_slug'] = (string) ($workspace['slug'] ?? '');
        $metadata['owner_workspace_status'] = (string) ($workspace['status'] ?? '');
        $metadata['owner_workspace_plan_status'] = (string) ($workspace['plan_status'] ?? '');
        $policy = $this->contextRegistry->inactiveOwnerContactPolicy();
        $metadata['default_workspace_nurture_qualified'] = !empty($policy['default_workspace_nurture_qualified']);
        $metadata['default_workspace_contact_scope'] = (string) ($policy['scope'] ?? 'inactive_workspace_owner');
        $metadata['default_workspace_use'] = (string) ($policy['default_workspace_use'] ?? 'inactive_owner_reference');
        $metadata['current_paying_customer'] = !empty($policy['current_paying_customer']);
        $metadata['marketing_conversion_allowed'] = !empty($policy['marketing_conversion_allowed']);
        $metadata['last_synced_at'] = gmdate('c');
        if ($actorUserId !== null && $actorUserId > 0) {
            $metadata['last_synced_by_user_id'] = $actorUserId;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET relationship_status = 'inactive',
                 customer_state = 'ineligible',
                 last_synced_at = NOW(),
                 last_synced_by_user_id = ?
             WHERE id = ?",
            [$actorUserId, (int) $existing['id']]
        );
        $this->markOwnerContactMapInactive((int) $existing['id'], $this->inactiveReasonForWorkspace($workspace));

        if (!empty($existing['contact_row_id'])) {
            Database::execute(
                "UPDATE contacts
                 SET metadata_json = ?, updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [json_encode($metadata, JSON_UNESCAPED_SLASHES), $defaultWorkspaceId, (int) $existing['contact_row_id']]
            );
            $conversionDeal = $this->syncConversionDeal(
                $defaultWorkspaceId,
                (int) ($workspace['id'] ?? 0),
                $ownerUserId,
                (int) $existing['contact_row_id'],
                $actorUserId
            );
        } else {
            $conversionDeal = [];
        }

        return [
            'contact_id' => !empty($existing['contact_row_id']) ? (int) $existing['contact_row_id'] : null,
            'conversion_deal_id' => $conversionDeal['deal_id'] ?? null,
            'relationship_status' => 'inactive',
            'customer_state' => 'ineligible',
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function upsertOwnerContactMap(
        int $defaultWorkspaceId,
        int $ownerWorkspaceId,
        int $ownerUserId,
        int $contactId,
        array $metadata,
        ?int $actorUserId
    ): void {
        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return;
        }

        $policy = $this->contextRegistry->ownerContactPolicyForScope((string) ($metadata['default_workspace_contact_scope'] ?? ''));
        $customerState = (string) ($policy['customer_state'] ?? 'qualified_workspace_lead');

        Database::execute(
            "INSERT INTO default_workspace_owner_contacts
                (default_workspace_id, owner_workspace_id, owner_user_id, contact_id, relationship_status, customer_state, last_synced_at, last_synced_by_user_id)
             VALUES
                (?, ?, ?, ?, 'active', ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                default_workspace_id = VALUES(default_workspace_id),
                contact_id = VALUES(contact_id),
                relationship_status = VALUES(relationship_status),
                customer_state = VALUES(customer_state),
                last_synced_at = VALUES(last_synced_at),
                last_synced_by_user_id = VALUES(last_synced_by_user_id)",
            [$defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId, $contactId, $customerState, $actorUserId]
        );
    }

    private function markOwnerContactMapSynced(int $defaultWorkspaceId, int $ownerWorkspaceId, int $ownerUserId): void
    {
        if (!Database::columnExists('default_workspace_owner_contacts', 'sync_status')) {
            return;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET sync_status = 'synced',
                 inactive_reason = NULL,
                 last_sync_error = NULL,
                 last_reconciled_at = NOW()
             WHERE default_workspace_id = ? AND owner_workspace_id = ? AND owner_user_id = ?",
            [$defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId]
        );
    }

    private function markOwnerContactMapInactive(int $mappingId, string $reason): void
    {
        if (!Database::columnExists('default_workspace_owner_contacts', 'sync_status')) {
            return;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET sync_status = 'inactive',
                 inactive_reason = ?,
                 last_sync_error = NULL,
                 last_reconciled_at = NOW()
             WHERE id = ?",
            [substr($reason, 0, 120), $mappingId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function syncConversionDeal(
        int $defaultWorkspaceId,
        int $ownerWorkspaceId,
        int $ownerUserId,
        int $contactId,
        ?int $actorUserId
    ): array {
        return (new DefaultWorkspaceConversionPipelineService($this->defaultWorkspace))->syncForOwnerContact(
            $defaultWorkspaceId,
            $ownerWorkspaceId,
            $ownerUserId,
            $contactId,
            $actorUserId
        );
    }

    /**
     * @param array<string,mixed> $workspace
     */
    private function inactiveReasonForWorkspace(array $workspace): string
    {
        $status = strtolower(trim((string) ($workspace['status'] ?? '')));
        if (in_array($status, ['suspended', 'archived', 'locked', 'inactive', 'deleted'], true)) {
            return 'workspace_' . $status;
        }

        return 'workspace_not_active_package';
    }

    /**
     * @param array<string,mixed> $workspace
     */
    private function isActiveWorkspace(array $workspace): bool
    {
        $status = strtolower(trim((string) ($workspace['status'] ?? '')));
        $planStatus = strtolower(trim((string) ($workspace['plan_status'] ?? '')));

        if (in_array($status, ['suspended', 'archived', 'locked', 'inactive', 'deleted'], true)) {
            return false;
        }

        return in_array($status, ['active', 'current', 'trialing'], true)
            || in_array($planStatus, ['active', 'current', 'trialing'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
