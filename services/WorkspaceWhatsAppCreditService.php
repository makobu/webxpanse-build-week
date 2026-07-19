<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceWhatsAppCreditService
{
    public function isAvailable(): bool
    {
        return Database::tableExists('workspace_whatsapp_credit_wallets')
            && Database::tableExists('workspace_whatsapp_credit_ledger');
    }

    public function ensureWallet(int $workspaceId, ?int $integrationId = null, string $currency = 'KES'): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $existing = Database::queryOne(
            "SELECT * FROM workspace_whatsapp_credit_wallets WHERE workspace_id = ? LIMIT 1",
            [$workspaceId]
        );
        if ($existing) {
            if ($integrationId !== null && $integrationId > 0 && empty($existing['integration_id'])) {
                Database::execute(
                    "UPDATE workspace_whatsapp_credit_wallets SET integration_id = ?, updated_at = NOW() WHERE id = ?",
                    [$integrationId, (int) $existing['id']]
                );
                $existing['integration_id'] = $integrationId;
            }
            return $this->decorateWallet($existing);
        }

        Database::execute(
            "INSERT INTO workspace_whatsapp_credit_wallets
                (workspace_id, integration_id, currency, billing_status, last_activity_at)
             VALUES (?, ?, ?, 'inactive', NOW())",
            [$workspaceId, $integrationId && $integrationId > 0 ? $integrationId : null, strtoupper(substr($currency, 0, 8)) ?: 'KES']
        );

        return $this->summary($workspaceId);
    }

    public function summary(int $workspaceId): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $wallet = Database::queryOne(
            "SELECT * FROM workspace_whatsapp_credit_wallets WHERE workspace_id = ? LIMIT 1",
            [$workspaceId]
        );
        if (!$wallet) {
            return $this->ensureWallet($workspaceId);
        }

        return $this->decorateWallet($wallet);
    }

    public function grantCredits(
        int $workspaceId,
        float $credits,
        string $referenceType,
        string $referenceId,
        ?int $userId = null,
        array $metadata = [],
        string $description = 'WhatsApp credits added'
    ): array {
        if ($credits <= 0) {
            throw new \RuntimeException('Credit amount must be greater than zero.');
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($credits, $referenceType, $referenceId, $userId, $metadata, $description): array {
            $existing = $this->ledgerByReference((int) $wallet['workspace_id'], $referenceType, $referenceId, 'credit');
            if ($existing) {
                return ['wallet' => $this->summary((int) $wallet['workspace_id']), 'ledger_entry_id' => (int) $existing['id'], 'duplicate' => true];
            }

            $balanceAfter = round((float) $wallet['credit_balance'] + $credits, 4);
            Database::execute(
                "UPDATE workspace_whatsapp_credit_wallets
                 SET credit_balance = ?,
                     lifetime_credited = lifetime_credited + ?,
                     billing_status = CASE WHEN ? > reserved_credits THEN 'active' ELSE billing_status END,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [$balanceAfter, $credits, $balanceAfter, (int) $wallet['id']]
            );

            $ledgerId = $this->insertLedger(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'credit',
                $referenceType,
                $referenceId,
                null,
                $credits,
                $balanceAfter,
                (float) $wallet['reserved_credits'],
                'posted',
                $description,
                $metadata
            );
            $this->syncIntegrationManagedBalance((int) $wallet['workspace_id']);

            return ['wallet' => $this->summary((int) $wallet['workspace_id']), 'ledger_entry_id' => $ledgerId, 'duplicate' => false];
        });
    }

    public function reserveForMessage(int $workspaceId, int $messageId, ?int $userId = null): array
    {
        if (!(new WhatsAppFeatureGate())->managedBillingEnabled($workspaceId)) {
            return ['reserved' => false, 'reason' => 'managed_billing_disabled'];
        }
        if (!$this->messageNeedsManagedBilling($workspaceId, $messageId)) {
            return ['reserved' => false, 'reason' => 'not_platform_managed'];
        }

        $message = Database::queryOne(
            "SELECT id, estimated_cost, template_category, recipient_country, credit_reservation_ledger_id, billing_status
             FROM whatsapp_messages
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $messageId]
        );
        if (!$message) {
            throw new \RuntimeException('WhatsApp message not found for credit reservation.');
        }

        if ((int) ($message['credit_reservation_ledger_id'] ?? 0) > 0 || (string) ($message['billing_status'] ?? '') === 'reserved') {
            return ['reserved' => true, 'duplicate' => true, 'ledger_entry_id' => (int) ($message['credit_reservation_ledger_id'] ?? 0)];
        }

        $cost = (float) ($message['estimated_cost'] ?? 0);
        if ($cost <= 0) {
            $cost = $this->estimateCost(
                $workspaceId,
                (string) ($message['template_category'] ?? 'utility'),
                (string) ($message['recipient_country'] ?? '')
            );
            Database::execute(
                "UPDATE whatsapp_messages SET estimated_cost = ? WHERE workspace_id = ? AND id = ?",
                [$cost, $workspaceId, $messageId]
            );
        }

        if ($cost <= 0) {
            return ['reserved' => false, 'reason' => 'zero_cost'];
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($workspaceId, $messageId, $userId, $cost): array {
            $referenceId = 'whatsapp_message:' . $messageId;
            $existing = $this->ledgerByReference($workspaceId, 'whatsapp_message', $referenceId, 'reserve');
            if ($existing) {
                return ['reserved' => true, 'duplicate' => true, 'ledger_entry_id' => (int) $existing['id']];
            }

            $available = (float) $wallet['credit_balance'] - (float) $wallet['reserved_credits'];
            if ($available + 0.0001 < $cost) {
                $this->markWalletStatus((int) $wallet['id'], 'depleted');
                throw new \RuntimeException('Insufficient WhatsApp Credits. Top up before sending this managed WhatsApp message.');
            }
            $this->assertSpendCapsAllowReservation($workspaceId, $wallet, $cost);

            $reservedAfter = round((float) $wallet['reserved_credits'] + $cost, 4);
            Database::execute(
                "UPDATE workspace_whatsapp_credit_wallets
                 SET reserved_credits = ?,
                     billing_status = CASE WHEN (credit_balance - ?) <= low_balance_threshold THEN 'low_balance' ELSE 'active' END,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [$reservedAfter, $reservedAfter, (int) $wallet['id']]
            );

            $ledgerId = $this->insertLedger(
                $workspaceId,
                (int) $wallet['id'],
                $userId,
                'reserve',
                'whatsapp_message',
                $referenceId,
                null,
                -$cost,
                (float) $wallet['credit_balance'],
                $reservedAfter,
                'pending',
                'Reserved WhatsApp Credits for outbound message',
                ['message_id' => $messageId, 'estimated_cost' => $cost]
            );

            Database::execute(
                "UPDATE whatsapp_messages
                 SET credit_reservation_ledger_id = ?,
                     billing_status = 'reserved'
                 WHERE workspace_id = ? AND id = ?",
                [$ledgerId, $workspaceId, $messageId]
            );
            $this->syncIntegrationManagedBalance($workspaceId);

            return ['reserved' => true, 'ledger_entry_id' => $ledgerId, 'estimated_cost' => $cost];
        });
    }

    public function releaseForMessage(int $workspaceId, int $messageId, string $reason = 'message_not_billable'): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0 || $messageId <= 0) {
            return [];
        }

        $message = Database::queryOne(
            "SELECT id, credit_reservation_ledger_id, billing_status
             FROM whatsapp_messages
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $messageId]
        );
        if (!$message || (int) ($message['credit_reservation_ledger_id'] ?? 0) <= 0) {
            return [];
        }

        $reservation = Database::queryOne(
            "SELECT * FROM workspace_whatsapp_credit_ledger WHERE id = ? AND workspace_id = ? AND entry_type = 'reserve' LIMIT 1",
            [(int) $message['credit_reservation_ledger_id'], $workspaceId]
        );
        if (!$reservation || (string) ($reservation['status'] ?? '') !== 'pending') {
            return [];
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($workspaceId, $messageId, $reservation, $reason): array {
            $amount = abs((float) ($reservation['credit_delta'] ?? 0));
            $reservedAfter = max(0, round((float) $wallet['reserved_credits'] - $amount, 4));
            Database::execute(
                "UPDATE workspace_whatsapp_credit_wallets
                 SET reserved_credits = ?,
                     billing_status = CASE WHEN credit_balance <= reserved_credits THEN 'depleted' WHEN (credit_balance - ?) <= low_balance_threshold THEN 'low_balance' ELSE 'active' END,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [$reservedAfter, $reservedAfter, (int) $wallet['id']]
            );
            Database::execute(
                "UPDATE workspace_whatsapp_credit_ledger SET status = 'released' WHERE id = ?",
                [(int) $reservation['id']]
            );

            $ledgerId = $this->insertLedger(
                $workspaceId,
                (int) $wallet['id'],
                null,
                'release',
                'whatsapp_message',
                'whatsapp_message:' . $messageId,
                null,
                $amount,
                (float) $wallet['credit_balance'],
                $reservedAfter,
                'released',
                'Released reserved WhatsApp Credits',
                ['message_id' => $messageId, 'reason' => $reason, 'reservation_ledger_id' => (int) $reservation['id']]
            );
            Database::execute(
                "UPDATE whatsapp_messages
                 SET billing_status = 'released'
                 WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $messageId]
            );
            $this->syncIntegrationManagedBalance($workspaceId);

            return ['released' => true, 'ledger_entry_id' => $ledgerId, 'released_credits' => $amount];
        });
    }

    public function settleDeliveredWebhook(array $status, int $workspaceId): array
    {
        $providerMessageId = trim((string) ($status['id'] ?? ''));
        if ($workspaceId <= 0 || $providerMessageId === '' || !$this->isAvailable()) {
            return [];
        }
        if (!(new WhatsAppFeatureGate())->managedBillingEnabled($workspaceId)) {
            return ['ignored' => true, 'reason' => 'managed_billing_disabled'];
        }

        $message = Database::queryOne(
            "SELECT *
             FROM whatsapp_messages
             WHERE workspace_id = ?
               AND whatsapp_message_id = ?
             LIMIT 1",
            [$workspaceId, $providerMessageId]
        );
        if (!$message || (string) ($message['connection_mode'] ?? '') !== WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED) {
            return [];
        }

        $providerStatus = strtolower(trim((string) ($status['status'] ?? '')));
        if ($providerStatus !== 'failed' && !in_array($providerStatus, ['delivered', 'read'], true)) {
            return ['ignored' => true, 'status' => $providerStatus];
        }

        $eventType = $providerStatus === 'failed' ? 'failed' : 'delivered';
        $existing = Database::queryOne(
            "SELECT id FROM workspace_whatsapp_billable_events
             WHERE workspace_id = ? AND whatsapp_message_id = ? AND event_type = ?
             LIMIT 1",
            [$workspaceId, $providerMessageId, $eventType]
        );
        if ($existing) {
            return ['duplicate' => true, 'billable_event_id' => (int) $existing['id']];
        }

        if ($eventType === 'failed') {
            $release = $this->releaseForMessage($workspaceId, (int) $message['id'], 'provider_failed');
            $eventId = $this->insertBillableEvent($workspaceId, $message, $status, 'failed', 'failed', 0.0, (int) ($release['ledger_entry_id'] ?? 0));
            return ['released' => $release, 'billable_event_id' => $eventId];
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($workspaceId, $message, $status, $providerMessageId): array {
            $messageId = (int) $message['id'];
            $reservationId = (int) ($message['credit_reservation_ledger_id'] ?? 0);
            $reservation = $reservationId > 0
                ? Database::queryOne("SELECT * FROM workspace_whatsapp_credit_ledger WHERE id = ? AND workspace_id = ? LIMIT 1", [$reservationId, $workspaceId])
                : null;
            $cost = (float) ($message['estimated_cost'] ?? 0);
            if ($cost <= 0) {
                $cost = $this->estimateCost(
                    $workspaceId,
                    (string) ($message['template_category'] ?? 'utility'),
                    (string) ($message['recipient_country'] ?? '')
                );
            }

            $reservedAmount = $reservation ? abs((float) ($reservation['credit_delta'] ?? 0)) : 0.0;
            $reservedAfter = max(0, round((float) $wallet['reserved_credits'] - $reservedAmount, 4));
            $balanceAfter = max(0, round((float) $wallet['credit_balance'] - $cost, 4));
            Database::execute(
                "UPDATE workspace_whatsapp_credit_wallets
                 SET credit_balance = ?,
                     reserved_credits = ?,
                     lifetime_debited = lifetime_debited + ?,
                     billing_status = CASE WHEN ? <= 0 THEN 'depleted' WHEN (? - ?) <= low_balance_threshold THEN 'low_balance' ELSE 'active' END,
                     last_activity_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [$balanceAfter, $reservedAfter, $cost, $balanceAfter, $balanceAfter, $reservedAfter, (int) $wallet['id']]
            );
            if ($reservation) {
                Database::execute("UPDATE workspace_whatsapp_credit_ledger SET status = 'posted' WHERE id = ?", [(int) $reservation['id']]);
            }
            $ledgerId = $this->insertLedger(
                $workspaceId,
                (int) $wallet['id'],
                null,
                'debit',
                'whatsapp_message',
                'whatsapp_message:' . $messageId,
                $providerMessageId,
                -$cost,
                $balanceAfter,
                $reservedAfter,
                'posted',
                'Debited WhatsApp Credits for delivered message',
                ['message_id' => $messageId, 'provider_message_id' => $providerMessageId, 'reservation_ledger_id' => $reservationId]
            );
            $eventId = $this->insertBillableEvent($workspaceId, $message, $status, 'delivered', 'billable', $cost, $ledgerId);
            Database::execute(
                "UPDATE whatsapp_messages
                 SET final_cost = ?,
                     billable_event_id = ?,
                     billing_status = 'billable'
                 WHERE workspace_id = ? AND id = ?",
                [$cost, $eventId, $workspaceId, $messageId]
            );
            $this->syncIntegrationManagedBalance($workspaceId);

            return ['debited' => true, 'debited_credits' => $cost, 'ledger_entry_id' => $ledgerId, 'billable_event_id' => $eventId];
        });
    }

    public function estimateCost(int $workspaceId, string $category = 'utility', string $country = ''): float
    {
        $category = strtolower(trim($category));
        if (!in_array($category, ['marketing', 'utility', 'authentication', 'service'], true)) {
            $category = 'utility';
        }
        $country = strtolower(trim($country));
        $marketCandidates = array_values(array_filter([$country, 'default']));
        foreach ($marketCandidates as $market) {
            $row = Database::queryOne(
                "SELECT platform_unit_price
                 FROM workspace_whatsapp_rate_cards
                 WHERE market_code = ?
                   AND template_category = ?
                   AND is_active = 1
                   AND effective_from <= CURDATE()
                   AND (effective_to IS NULL OR effective_to >= CURDATE())
                 ORDER BY effective_from DESC, id DESC
                 LIMIT 1",
                [$market, $category]
            );
            if ($row) {
                return round((float) ($row['platform_unit_price'] ?? 0), 4);
            }
        }

        return $category === 'service' ? 0.0 : 0.5;
    }

    public function releaseStaleReservations(int $olderThanMinutes = 1440, ?int $workspaceId = null): array
    {
        $where = "entry_type = 'reserve' AND status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        $params = [$olderThanMinutes];
        if ($workspaceId !== null && $workspaceId > 0) {
            $where .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        $rows = Database::query(
            "SELECT workspace_id, reference_id
             FROM workspace_whatsapp_credit_ledger
             WHERE {$where}
             ORDER BY created_at ASC
             LIMIT 200",
            $params
        );

        $released = 0;
        foreach ($rows as $row) {
            if (preg_match('/whatsapp_message:(\d+)/', (string) ($row['reference_id'] ?? ''), $m)) {
                $result = $this->releaseForMessage((int) $row['workspace_id'], (int) $m[1], 'stale_reservation');
                if (!empty($result['released'])) {
                    $released++;
                }
            }
        }

        return ['scanned' => count($rows), 'released' => $released];
    }

    private function messageNeedsManagedBilling(int $workspaceId, int $messageId): bool
    {
        if (!(new WhatsAppFeatureGate())->managedBillingEnabled($workspaceId)) {
            return false;
        }
        $message = Database::queryOne(
            "SELECT connection_mode FROM whatsapp_messages WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $messageId]
        );
        return (string) ($message['connection_mode'] ?? '') === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED;
    }

    private function assertSpendCapsAllowReservation(int $workspaceId, array $wallet, float $cost): void
    {
        $dailyCap = $this->floatOrNull($wallet['daily_spend_cap'] ?? null);
        $monthlyCap = $this->floatOrNull($wallet['monthly_spend_cap'] ?? null);
        if ($dailyCap === null || $monthlyCap === null) {
            $integration = Database::queryOne(
                "SELECT managed_daily_spend_cap, managed_monthly_spend_cap
                 FROM workspace_whatsapp_integrations
                 WHERE workspace_id = ?
                 LIMIT 1",
                [$workspaceId]
            ) ?? [];
            $dailyCap = $dailyCap ?? $this->floatOrNull($integration['managed_daily_spend_cap'] ?? null);
            $monthlyCap = $monthlyCap ?? $this->floatOrNull($integration['managed_monthly_spend_cap'] ?? null);
        }

        if ($dailyCap !== null && $dailyCap > 0) {
            $todaySpend = $this->sumLedgerSpend($workspaceId, date('Y-m-d 00:00:00'));
            if ($todaySpend + $cost > $dailyCap + 0.0001) {
                throw new \RuntimeException('WhatsApp daily spend cap would be exceeded by this send.');
            }
        }

        if ($monthlyCap !== null && $monthlyCap > 0) {
            $monthSpend = $this->sumLedgerSpend($workspaceId, date('Y-m-01 00:00:00'));
            if ($monthSpend + $cost > $monthlyCap + 0.0001) {
                throw new \RuntimeException('WhatsApp monthly spend cap would be exceeded by this send.');
            }
        }
    }

    private function sumLedgerSpend(int $workspaceId, string $since): float
    {
        $row = Database::queryOne(
            "SELECT COALESCE(SUM(ABS(credit_delta)), 0) AS total
             FROM workspace_whatsapp_credit_ledger
             WHERE workspace_id = ?
               AND ((entry_type = 'reserve' AND status = 'pending') OR (entry_type = 'debit' AND status = 'posted'))
               AND created_at >= ?",
            [$workspaceId, $since]
        );

        return (float) ($row['total'] ?? 0);
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }

    private function withLockedWallet(int $workspaceId, callable $callback): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $this->ensureWallet($workspaceId);
        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $wallet = Database::queryOne(
                "SELECT * FROM workspace_whatsapp_credit_wallets WHERE workspace_id = ? LIMIT 1 FOR UPDATE",
                [$workspaceId]
            );
            if (!$wallet) {
                throw new \RuntimeException('WhatsApp credit wallet could not be loaded.');
            }
            $result = $callback($wallet);
            if ($started) {
                Database::commit();
            }
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function ledgerByReference(int $workspaceId, string $referenceType, string $referenceId, string $entryType): ?array
    {
        return Database::queryOne(
            "SELECT * FROM workspace_whatsapp_credit_ledger
             WHERE workspace_id = ?
               AND reference_type = ?
               AND reference_id = ?
               AND entry_type = ?
             LIMIT 1",
            [$workspaceId, substr($referenceType, 0, 64), substr($referenceId, 0, 191), $entryType]
        );
    }

    private function insertLedger(
        int $workspaceId,
        int $walletId,
        ?int $userId,
        string $entryType,
        string $referenceType,
        string $referenceId,
        ?string $externalReference,
        float $creditDelta,
        float $balanceAfter,
        float $reservedAfter,
        string $status,
        string $description,
        array $metadata
    ): int {
        Database::execute(
            "INSERT INTO workspace_whatsapp_credit_ledger
                (workspace_id, wallet_id, user_id, entry_type, reference_type, reference_id, external_reference,
                 credit_delta, balance_after, reserved_after, status, description, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $walletId,
                $userId && $userId > 0 ? $userId : null,
                $entryType,
                substr($referenceType, 0, 64),
                substr($referenceId, 0, 191),
                $externalReference !== null ? substr($externalReference, 0, 191) : null,
                round($creditDelta, 4),
                round($balanceAfter, 4),
                round($reservedAfter, 4),
                $status,
                substr($description, 0, 255),
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function insertBillableEvent(int $workspaceId, array $message, array $providerPayload, string $eventType, string $billableStatus, float $finalCost, int $ledgerId = 0): int
    {
        $occurredAt = date('Y-m-d H:i:s', is_numeric($providerPayload['timestamp'] ?? null) ? (int) $providerPayload['timestamp'] : time());
        Database::execute(
            "INSERT INTO workspace_whatsapp_billable_events
                (workspace_id, wallet_id, ledger_entry_id, whatsapp_message_id, whatsapp_message_row_id, event_type,
                 billable_status, connection_mode, recipient_number, recipient_country, template_name, template_category,
                 estimated_cost, final_cost, currency, provider_payload_json, occurred_at)
             SELECT ?, w.id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, w.currency, ?, ?
             FROM workspace_whatsapp_credit_wallets w
             WHERE w.workspace_id = ?
             LIMIT 1",
            [
                $workspaceId,
                $ledgerId > 0 ? $ledgerId : null,
                (string) ($message['whatsapp_message_id'] ?? ''),
                (int) ($message['id'] ?? 0),
                $eventType,
                $billableStatus,
                (string) ($message['connection_mode'] ?? WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED),
                (string) ($message['to_number'] ?? ''),
                (string) ($message['recipient_country'] ?? ''),
                (string) ($message['template_name'] ?? ''),
                (string) ($message['template_category'] ?? 'utility'),
                (float) ($message['estimated_cost'] ?? 0),
                $finalCost,
                json_encode($providerPayload, JSON_UNESCAPED_SLASHES),
                $occurredAt,
                $workspaceId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function markWalletStatus(int $walletId, string $status): void
    {
        Database::execute(
            "UPDATE workspace_whatsapp_credit_wallets SET billing_status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $walletId]
        );
    }

    private function syncIntegrationManagedBalance(int $workspaceId): void
    {
        if (!Database::tableExists('workspace_whatsapp_integrations')) {
            return;
        }
        Database::execute(
            "UPDATE workspace_whatsapp_integrations wwi
             JOIN workspace_whatsapp_credit_wallets wallet ON wallet.workspace_id = wwi.workspace_id
                 SET wwi.managed_credit_balance = wallet.credit_balance,
                 wwi.managed_credit_reserved = wallet.reserved_credits,
                 wwi.managed_currency = wallet.currency,
                 wwi.managed_low_balance_threshold = wallet.low_balance_threshold,
                 wwi.managed_auto_topup_enabled = wallet.auto_topup_enabled,
                 wwi.managed_auto_topup_threshold = wallet.auto_topup_threshold,
                 wwi.managed_auto_topup_amount = wallet.auto_topup_amount,
                 wwi.managed_billing_status = wallet.billing_status,
                 wwi.updated_at = NOW()
             WHERE wwi.workspace_id = ?",
            [$workspaceId]
        );
    }

    private function decorateWallet(array $wallet): array
    {
        $wallet['credit_balance'] = (float) ($wallet['credit_balance'] ?? 0);
        $wallet['reserved_credits'] = (float) ($wallet['reserved_credits'] ?? 0);
        $wallet['available_credits'] = max(0, round($wallet['credit_balance'] - $wallet['reserved_credits'], 4));
        $wallet['low_balance_threshold'] = (float) ($wallet['low_balance_threshold'] ?? 100);
        $wallet['daily_spend_cap'] = $this->floatOrNull($wallet['daily_spend_cap'] ?? null);
        $wallet['monthly_spend_cap'] = $this->floatOrNull($wallet['monthly_spend_cap'] ?? null);
        $wallet['auto_topup_enabled'] = !empty($wallet['auto_topup_enabled']);
        $wallet['auto_topup_threshold'] = $this->floatOrNull($wallet['auto_topup_threshold'] ?? null);
        $wallet['auto_topup_amount'] = $this->floatOrNull($wallet['auto_topup_amount'] ?? null);
        $wallet['is_low_balance'] = $wallet['available_credits'] <= $wallet['low_balance_threshold'];
        $wallet['is_depleted'] = $wallet['available_credits'] <= 0;
        return $wallet;
    }
}
