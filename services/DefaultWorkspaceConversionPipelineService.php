<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceConversionPipelineService
{
    private const STAGES = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];

    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
    }

    /**
     * @return array<string,mixed>
     */
    public function syncForOwnerContact(
        int $defaultWorkspaceId,
        int $ownerWorkspaceId,
        int $ownerUserId,
        int $contactId,
        ?int $actorUserId = null
    ): array {
        if ($defaultWorkspaceId <= 0 || $ownerWorkspaceId <= 0 || $ownerUserId <= 0 || $contactId <= 0) {
            throw new \RuntimeException('Default workspace conversion sync requires workspace, owner, and contact IDs.');
        }
        if ($this->defaultWorkspace->isDefaultWorkspace($ownerWorkspaceId)) {
            return ['status' => 'skipped', 'reason' => 'default_workspace_owner'];
        }
        if (!Database::tableExists('default_workspace_owner_contacts')
            || !Database::columnExists('default_workspace_owner_contacts', 'conversion_deal_id')) {
            return ['status' => 'skipped', 'reason' => 'conversion_mapping_missing'];
        }

        $workspace = $this->workspace($ownerWorkspaceId);
        $contact = $this->contact($defaultWorkspaceId, $contactId);
        $billing = $this->billingState($ownerWorkspaceId, $workspace);
        $decision = $this->decideStage($workspace, $billing);
        $existingDeal = $this->findExistingDeal($defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId, $contactId);
        $stage = $this->preserveManualProgress((string) ($existingDeal['stage'] ?? ''), $decision['stage']);
        $customFields = $this->buildCustomFields(
            $existingDeal['custom_fields'] ?? null,
            $ownerWorkspaceId,
            $ownerUserId,
            $workspace,
            $billing,
            $decision['signal']
        );
        $assignedTo = !empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null;
        $createdBy = $actorUserId && $actorUserId > 0 ? $actorUserId : ($assignedTo ?: $ownerUserId);
        $value = (float) ($billing['subscription_amount'] ?? $billing['checkout_amount'] ?? 0);
        $currency = strtoupper(substr((string) ($billing['subscription_currency'] ?? $billing['checkout_currency'] ?? 'KES'), 0, 3)) ?: 'KES';
        $expectedCloseDate = null;
        $title = trim((string) ($workspace['name'] ?? 'Workspace')) . ' conversion';
        $description = 'Default workspace conversion opportunity for workspace #' . $ownerWorkspaceId . '.';

        if ($existingDeal) {
            Database::execute(
                "UPDATE deals
                 SET title = ?,
                     description = ?,
                     contact_id = ?,
                     assigned_to = ?,
                     stage = ?,
                     value = ?,
                     probability = ?,
                     expected_close_date = ?,
                     currency = ?,
                     lead_source = 'other',
                     custom_fields = ?,
                     lock_version = lock_version + 1,
                     updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [
                    $title,
                    $description,
                    $contactId,
                    $assignedTo,
                    $stage,
                    $value,
                    $this->probabilityForStage($stage),
                    $expectedCloseDate,
                    $currency,
                    json_encode($customFields, JSON_UNESCAPED_SLASHES),
                    $defaultWorkspaceId,
                    (int) $existingDeal['id'],
                ]
            );
            $dealId = (int) $existingDeal['id'];
            $status = 'updated';
        } else {
            Database::execute(
                "INSERT INTO deals
                    (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, expected_close_date, currency, lead_source, custom_fields, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'other', ?, NOW(), NOW())",
                [
                    $defaultWorkspaceId,
                    $title,
                    $description,
                    $contactId,
                    $assignedTo,
                    $createdBy,
                    $stage,
                    $value,
                    $this->probabilityForStage($stage),
                    $expectedCloseDate,
                    $currency,
                    json_encode($customFields, JSON_UNESCAPED_SLASHES),
                ]
            );
            $dealId = (int) Database::lastInsertId();
            $status = 'created';
        }

        $this->linkDeal($defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId, $dealId);
        $this->syncContactStage($defaultWorkspaceId, $contactId, $stage);

        return [
            'status' => $status,
            'deal_id' => $dealId,
            'stage' => $stage,
            'conversion_signal' => $decision['signal'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workspace(int $workspaceId): array
    {
        $workspace = Database::queryOne(
            "SELECT id, name, slug, status, plan_status, trial_starts_at, trial_ends_at
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );
        if (!$workspace) {
            throw new \RuntimeException('Owner workspace was not found for conversion pipeline sync.');
        }

        return $workspace;
    }

    /**
     * @return array<string,mixed>
     */
    private function contact(int $defaultWorkspaceId, int $contactId): array
    {
        $contact = Database::queryOne(
            "SELECT id, assigned_to, stage
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$defaultWorkspaceId, $contactId]
        );
        if (!$contact) {
            throw new \RuntimeException('Default workspace owner contact was not found for conversion pipeline sync.');
        }

        return $contact;
    }

    /**
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    private function billingState(int $workspaceId, array $workspace): array
    {
        $subscription = Database::queryOne(
            "SELECT ws.*, bpp.amount AS subscription_amount, bpp.currency AS subscription_currency
             FROM workspace_subscriptions ws
             LEFT JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
             WHERE ws.workspace_id = ?
             ORDER BY FIELD(ws.subscription_status, 'active', 'trialing', 'past_due', 'expired', 'cancelled'), ws.id DESC
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
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
        $failedTransaction = Database::queryOne(
            "SELECT id, amount, currency, created_at
             FROM billing_transactions
             WHERE workspace_id = ?
               AND transaction_type = 'subscription_charge'
               AND transaction_status = 'failed'
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $checkout = Database::tableExists('billing_checkout_sessions') ? Database::queryOne(
            "SELECT id, status, amount, currency, created_at
             FROM billing_checkout_sessions
             WHERE workspace_id = ?
               AND billing_plan_price_id IS NOT NULL
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        ) : null;

        return [
            'subscription_id' => $subscriptionId ?: null,
            'subscription_status' => (string) (
                ($subscription['subscription_status'] ?? '') === 'trialing'
                    ? 'active'
                    : ($subscription['subscription_status'] ?? ($workspace['plan_status'] ?? 'inactive'))
            ),
            'subscription_amount' => isset($subscription['subscription_amount']) ? (float) $subscription['subscription_amount'] : null,
            'subscription_currency' => $subscription['subscription_currency'] ?? null,
            'trial_ends_at' => null,
            'is_current_paying_customer' => $subscriptionId > 0
                && (string) ($subscription['subscription_status'] ?? '') === 'active'
                && !empty($subscription['current_period_end'])
                && strtotime((string) $subscription['current_period_end']) >= time()
                && $paidTransaction !== null,
            'paid_transaction_id' => $paidTransaction['id'] ?? null,
            'failed_transaction_id' => $failedTransaction['id'] ?? null,
            'checkout_status' => (string) ($checkout['status'] ?? ''),
            'checkout_amount' => isset($checkout['amount']) ? (float) $checkout['amount'] : null,
            'checkout_currency' => $checkout['currency'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $billing
     * @return array{stage:string,signal:string}
     */
    private function decideStage(array $workspace, array $billing): array
    {
        $workspaceStatus = strtolower((string) ($workspace['status'] ?? 'active'));
        $planStatus = strtolower((string) ($workspace['plan_status'] ?? 'inactive'));
        $subscriptionStatus = strtolower((string) ($billing['subscription_status'] ?? $planStatus));
        if (!empty($billing['is_current_paying_customer'])) {
            return ['stage' => 'closed_won', 'signal' => 'paid_subscription'];
        }
        if (in_array($workspaceStatus, ['suspended', 'archived', 'deleted', 'inactive'], true)) {
            return ['stage' => 'closed_lost', 'signal' => 'workspace_inactive'];
        }
        if (in_array($subscriptionStatus, ['expired', 'cancelled'], true)) {
            return ['stage' => 'closed_lost', 'signal' => 'subscription_inactive'];
        }
        if ($planStatus === 'past_due'
            || $subscriptionStatus === 'past_due'
            || !empty($billing['failed_transaction_id'])
            || in_array((string) ($billing['checkout_status'] ?? ''), ['failed', 'cancelled'], true)) {
            return ['stage' => 'negotiation', 'signal' => 'payment_failed'];
        }
        if (in_array((string) ($billing['checkout_status'] ?? ''), ['pending', 'processing'], true)) {
            return ['stage' => 'negotiation', 'signal' => 'payment_started'];
        }
        if ($planStatus === 'active' || $subscriptionStatus === 'active') {
            return ['stage' => 'qualification', 'signal' => 'package_active'];
        }

        return ['stage' => 'prospecting', 'signal' => 'signed_in'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findExistingDeal(int $defaultWorkspaceId, int $ownerWorkspaceId, int $ownerUserId, int $contactId): ?array
    {
        $mapped = Database::queryOne(
            "SELECT d.id, d.stage, d.custom_fields
             FROM default_workspace_owner_contacts map
             JOIN deals d ON d.id = map.conversion_deal_id AND d.workspace_id = map.default_workspace_id
             WHERE map.default_workspace_id = ?
               AND map.owner_workspace_id = ?
               AND map.owner_user_id = ?
             LIMIT 1",
            [$defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId]
        );
        if ($mapped) {
            return $mapped;
        }

        $jsonMatched = Database::queryOne(
            "SELECT id, stage, custom_fields
             FROM deals
             WHERE workspace_id = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$.default_workspace_pipeline')) IN ('true', '1')
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$.owner_workspace_id')) AS UNSIGNED) = ?
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$.owner_user_id')) AS UNSIGNED) = ?
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId]
        );
        if ($jsonMatched) {
            return $jsonMatched;
        }

        return Database::queryOne(
            "SELECT id, stage, custom_fields
             FROM deals
             WHERE workspace_id = ?
               AND contact_id = ?
               AND custom_fields LIKE '%default_workspace_pipeline%'
             ORDER BY id ASC
             LIMIT 1",
            [$defaultWorkspaceId, $contactId]
        ) ?: null;
    }

    private function preserveManualProgress(string $existingStage, string $targetStage): string
    {
        if (!in_array($existingStage, self::STAGES, true)) {
            return $targetStage;
        }
        if ($existingStage === 'closed_won' && $targetStage !== 'closed_won') {
            return 'closed_won';
        }
        if (in_array($targetStage, ['closed_won', 'closed_lost'], true)) {
            return $targetStage === 'closed_lost' && $existingStage === 'closed_won' ? 'closed_won' : $targetStage;
        }
        if ($existingStage === 'closed_lost') {
            return 'closed_lost';
        }

        $ranks = ['prospecting' => 0, 'qualification' => 1, 'proposal' => 2, 'negotiation' => 3];
        return ($ranks[$existingStage] ?? -1) > ($ranks[$targetStage] ?? -1) ? $existingStage : $targetStage;
    }

    /**
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $billing
     * @return array<string,mixed>
     */
    private function buildCustomFields(
        mixed $existingJson,
        int $ownerWorkspaceId,
        int $ownerUserId,
        array $workspace,
        array $billing,
        string $conversionSignal
    ): array {
        $customFields = $this->decodeJson($existingJson);
        $customFields['default_workspace_pipeline'] = true;
        $customFields['owner_workspace_id'] = $ownerWorkspaceId;
        $customFields['owner_user_id'] = $ownerUserId;
        $customFields['owner_workspace_plan_status'] = (string) ($workspace['plan_status'] ?? '');
        $customFields['conversion_signal'] = $conversionSignal;
        $customFields['last_pipeline_sync_at'] = gmdate('c');

        return $customFields;
    }

    private function linkDeal(int $defaultWorkspaceId, int $ownerWorkspaceId, int $ownerUserId, int $dealId): void
    {
        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET conversion_deal_id = ?
             WHERE default_workspace_id = ?
               AND owner_workspace_id = ?
               AND owner_user_id = ?",
            [$dealId, $defaultWorkspaceId, $ownerWorkspaceId, $ownerUserId]
        );
    }

    private function syncContactStage(int $defaultWorkspaceId, int $contactId, string $dealStage): void
    {
        $contactStage = match ($dealStage) {
            'closed_won' => 'won',
            'closed_lost' => 'lost',
            default => 'qualified',
        };
        Database::execute(
            "UPDATE contacts
             SET stage = ?, updated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [$contactStage, $defaultWorkspaceId, $contactId]
        );
    }

    private function probabilityForStage(string $stage): int
    {
        return match ($stage) {
            'qualification' => 40,
            'proposal' => 60,
            'negotiation' => 75,
            'closed_won' => 100,
            'closed_lost' => 0,
            default => 20,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
