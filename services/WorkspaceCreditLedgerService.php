<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceCreditLedgerService
{
    public function isAvailable(): bool
    {
        return Database::tableExists('workspace_credit_lots')
            && Database::tableExists('workspace_credit_lot_ledger');
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function grantCredits(
        int $workspaceId,
        int $walletId,
        int $credits,
        string $sourceType,
        string $sourceId,
        ?int $sourceLedgerId = null,
        ?int $userId = null,
        array $metadata = [],
        ?string $expiresAt = null
    ): array {
        if (!$this->isAvailable() || $workspaceId <= 0 || $walletId <= 0 || $credits <= 0) {
            return [];
        }

        $sourceType = $this->normalizeReference($sourceType, 64, 'credit_grant');
        $sourceId = $this->normalizeReference($sourceId, 120, 'grant_' . bin2hex(random_bytes(6)));
        $existing = $this->lotBySource($workspaceId, $sourceType, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $grantedAt = date('Y-m-d H:i:s');
        $expiresAt = $this->normalizeExpiry($expiresAt, (int) ($metadata['credit_expiry_days'] ?? 180), $grantedAt);

        Database::execute(
            "INSERT INTO workspace_credit_lots
                (workspace_id, wallet_id, user_id, source_type, source_id, source_ledger_id,
                 granted_credits, remaining_credits, reserved_credits, granted_at, expires_at, status, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE
                source_ledger_id = COALESCE(workspace_credit_lots.source_ledger_id, VALUES(source_ledger_id)),
                metadata_json = COALESCE(workspace_credit_lots.metadata_json, VALUES(metadata_json)),
                updated_at = NOW()",
            [
                $workspaceId,
                $walletId,
                $userId && $userId > 0 ? $userId : null,
                $sourceType,
                $sourceId,
                $sourceLedgerId && $sourceLedgerId > 0 ? $sourceLedgerId : null,
                $credits,
                $credits,
                $grantedAt,
                $expiresAt,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        $lot = $this->lotBySource($workspaceId, $sourceType, $sourceId);
        if ($lot === null) {
            return [];
        }

        $ledgerExists = Database::queryOne(
            "SELECT id
             FROM workspace_credit_lot_ledger
             WHERE workspace_id = ?
               AND lot_id = ?
               AND entry_type = 'credit'
               AND reference_type = ?
               AND reference_id = ?
             LIMIT 1",
            [$workspaceId, (int) $lot['id'], $sourceType, $sourceId]
        );
        if (!$ledgerExists) {
            $this->insertLotLedger(
                $workspaceId,
                (int) $lot['id'],
                $sourceLedgerId,
                'credit',
                $sourceType,
                $sourceId,
                $credits,
                $credits,
                0,
                $metadata
            );
        }

        return $this->lotBySource($workspaceId, $sourceType, $sourceId) ?? $lot;
    }

    /**
     * @param array<string,mixed> $walletBefore
     * @param array<string,mixed> $metadata
     * @return list<array<string,mixed>>
     */
    public function reserveCredits(
        int $workspaceId,
        int $walletId,
        int $walletLedgerId,
        int $credits,
        string $referenceType,
        string $referenceId,
        array $metadata = [],
        array $walletBefore = []
    ): array {
        if (!$this->isAvailable() || $credits <= 0) {
            return [];
        }

        $this->ensureLegacyLotIfNeeded($workspaceId, $walletId, $credits, $walletBefore);
        $remaining = $credits;
        $allocations = [];
        foreach ($this->availableLotsForUpdate($workspaceId) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $available = max(0, (int) $lot['remaining_credits'] - (int) $lot['reserved_credits']);
            if ($available <= 0) {
                continue;
            }
            $use = min($remaining, $available);
            $reservedAfter = (int) $lot['reserved_credits'] + $use;
            Database::execute(
                "UPDATE workspace_credit_lots
                 SET reserved_credits = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$reservedAfter, (int) $lot['id']]
            );
            $this->insertLotLedger(
                $workspaceId,
                (int) $lot['id'],
                $walletLedgerId,
                'reserve',
                $referenceType,
                $referenceId,
                -$use,
                (int) $lot['remaining_credits'],
                $reservedAfter,
                $metadata
            );
            $allocations[] = [
                'lot_id' => (int) $lot['id'],
                'reserved_credits' => $use,
                'expires_at' => (string) ($lot['expires_at'] ?? ''),
            ];
            $remaining -= $use;
        }

        if ($remaining > 0) {
            throw new \RuntimeException('Not enough unexpired AI Credits to reserve this request.');
        }

        return $allocations;
    }

    /**
     * @param array<string,mixed> $walletBefore
     * @param array<string,mixed> $metadata
     * @return list<array<string,mixed>>
     */
    public function debitCredits(
        int $workspaceId,
        int $walletId,
        int $walletLedgerId,
        int $credits,
        string $referenceType,
        string $referenceId,
        array $metadata = [],
        array $walletBefore = []
    ): array {
        if (!$this->isAvailable() || $credits <= 0) {
            return [];
        }

        $this->ensureLegacyLotIfNeeded($workspaceId, $walletId, $credits, $walletBefore);
        $remaining = $credits;
        $allocations = [];
        foreach ($this->availableLotsForUpdate($workspaceId) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $available = max(0, (int) $lot['remaining_credits'] - (int) $lot['reserved_credits']);
            if ($available <= 0) {
                continue;
            }
            $use = min($remaining, $available);
            $remainingAfter = max(0, (int) $lot['remaining_credits'] - $use);
            $status = $remainingAfter <= 0 ? 'depleted' : 'active';
            Database::execute(
                "UPDATE workspace_credit_lots
                 SET remaining_credits = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$remainingAfter, $status, (int) $lot['id']]
            );
            $this->insertLotLedger(
                $workspaceId,
                (int) $lot['id'],
                $walletLedgerId,
                'debit',
                $referenceType,
                $referenceId,
                -$use,
                $remainingAfter,
                (int) $lot['reserved_credits'],
                $metadata
            );
            $allocations[] = [
                'lot_id' => (int) $lot['id'],
                'debited_credits' => $use,
                'expires_at' => (string) ($lot['expires_at'] ?? ''),
            ];
            $remaining -= $use;
        }

        if ($remaining > 0) {
            throw new \RuntimeException('Not enough unexpired AI Credits to complete this debit.');
        }

        return $allocations;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return list<array<string,mixed>>
     */
    public function releaseReservation(
        int $workspaceId,
        int $reservationLedgerId,
        ?int $releaseLedgerId,
        string $referenceType,
        string $referenceId,
        array $metadata = []
    ): array {
        if (!$this->isAvailable() || $reservationLedgerId <= 0) {
            return [];
        }

        $allocations = $this->reservationAllocations($workspaceId, $reservationLedgerId);
        $released = [];
        foreach ($allocations as $allocation) {
            $amount = abs((int) ($allocation['credit_delta'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $lot = $this->lotForUpdate((int) $allocation['lot_id']);
            if ($lot === null) {
                continue;
            }
            $reservedAfter = max(0, (int) $lot['reserved_credits'] - $amount);
            Database::execute(
                "UPDATE workspace_credit_lots
                 SET reserved_credits = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$reservedAfter, (int) $lot['id']]
            );
            $this->insertLotLedger(
                $workspaceId,
                (int) $lot['id'],
                $releaseLedgerId,
                'release',
                $referenceType,
                $referenceId,
                $amount,
                (int) $lot['remaining_credits'],
                $reservedAfter,
                $metadata
            );
            $released[] = [
                'lot_id' => (int) $lot['id'],
                'released_credits' => $amount,
            ];
        }

        return $released;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $walletBefore
     * @return array<string,mixed>
     */
    public function settleReservation(
        int $workspaceId,
        int $walletId,
        int $reservationLedgerId,
        int $debitLedgerId,
        ?int $releaseLedgerId,
        int $actualCredits,
        string $referenceType,
        string $referenceId,
        array $metadata = [],
        array $walletBefore = []
    ): array {
        if (!$this->isAvailable() || $actualCredits <= 0 && $reservationLedgerId <= 0) {
            return [];
        }

        $remainingActual = max(0, $actualCredits);
        $debited = [];
        $released = [];

        foreach ($this->reservationAllocations($workspaceId, $reservationLedgerId) as $allocation) {
            $reservedAmount = abs((int) ($allocation['credit_delta'] ?? 0));
            if ($reservedAmount <= 0) {
                continue;
            }
            $lot = $this->lotForUpdate((int) $allocation['lot_id']);
            if ($lot === null) {
                continue;
            }

            $consume = min($remainingActual, $reservedAmount);
            $release = max(0, $reservedAmount - $consume);
            $remainingAfter = max(0, (int) $lot['remaining_credits'] - $consume);
            $reservedAfter = max(0, (int) $lot['reserved_credits'] - $reservedAmount);
            $status = $remainingAfter <= 0 ? 'depleted' : 'active';
            Database::execute(
                "UPDATE workspace_credit_lots
                 SET remaining_credits = ?,
                     reserved_credits = ?,
                     status = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$remainingAfter, $reservedAfter, $status, (int) $lot['id']]
            );

            if ($consume > 0) {
                $this->insertLotLedger(
                    $workspaceId,
                    (int) $lot['id'],
                    $debitLedgerId,
                    'debit',
                    $referenceType,
                    $referenceId,
                    -$consume,
                    $remainingAfter,
                    $reservedAfter,
                    $metadata
                );
                $debited[] = ['lot_id' => (int) $lot['id'], 'debited_credits' => $consume];
            }

            if ($release > 0) {
                $this->insertLotLedger(
                    $workspaceId,
                    (int) $lot['id'],
                    $releaseLedgerId,
                    'release',
                    $referenceType,
                    $referenceId . ':release',
                    $release,
                    $remainingAfter,
                    $reservedAfter,
                    $metadata
                );
                $released[] = ['lot_id' => (int) $lot['id'], 'released_credits' => $release];
            }

            $remainingActual -= $consume;
        }

        if ($remainingActual > 0) {
            $extra = $this->debitCredits(
                $workspaceId,
                $walletId,
                $debitLedgerId,
                $remainingActual,
                $referenceType,
                $referenceId,
                $metadata,
                $walletBefore
            );
            $debited = array_merge($debited, $extra);
        }

        return [
            'debited' => $debited,
            'released' => $released,
        ];
    }

    public function expireUnusedCredits(
        ?int $workspaceId = null,
        ?string $now = null,
        ?int $actorUserId = null,
        ?string $reason = null,
        array $metadata = []
    ): array
    {
        if (!$this->isAvailable()) {
            return ['expired_lots' => 0, 'expired_credits' => 0];
        }
        if (Database::getInstance()->inTransaction()) {
            throw new \RuntimeException('Credit expiry must be run outside an active transaction.');
        }

        $now = $now !== null && trim($now) !== '' ? date('Y-m-d H:i:s', strtotime($now)) : date('Y-m-d H:i:s');
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $operatorMetadata = array_filter([
            'operator_user_id' => $actorUserId && $actorUserId > 0 ? $actorUserId : null,
            'operator_reason' => $reason,
        ], static fn($value): bool => $value !== null && $value !== '');
        $expiryMetadata = array_merge($metadata, $operatorMetadata);
        $params = [$now];
        $workspaceClause = '';
        if ($workspaceId !== null && $workspaceId > 0) {
            $workspaceClause = ' AND workspace_id = ?';
            $params[] = $workspaceId;
        }

        $lots = Database::query(
            "SELECT *
             FROM workspace_credit_lots
             WHERE status = 'active'
               AND remaining_credits > 0
               AND reserved_credits = 0
               AND expires_at <= ?{$workspaceClause}
             ORDER BY expires_at ASC, id ASC
             LIMIT 500",
            $params
        );

        $expiredLots = 0;
        $expiredCredits = 0;
        $affectedWorkspaceIds = [];
        foreach ($lots as $lot) {
            Database::beginTransaction();
            try {
                $lockedLot = $this->lotForUpdate((int) $lot['id']);
                if ($lockedLot === null || (string) ($lockedLot['status'] ?? '') !== 'active') {
                    Database::commit();
                    continue;
                }
                $credits = (int) ($lockedLot['remaining_credits'] ?? 0);
                if ($credits <= 0 || (int) ($lockedLot['reserved_credits'] ?? 0) > 0) {
                    Database::commit();
                    continue;
                }

                $wallet = Database::queryOne(
                    "SELECT *
                     FROM workspace_wallets
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE",
                    [(int) $lockedLot['wallet_id']]
                );
                if (!$wallet) {
                    Database::commit();
                    continue;
                }

                $newBalance = max(0, (int) $wallet['token_balance'] - $credits);
                Database::execute(
                    "UPDATE workspace_wallets
                     SET token_balance = ?,
                         lifetime_debited_tokens = lifetime_debited_tokens + ?,
                         last_activity_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ?",
                    [$newBalance, $credits, (int) $wallet['id']]
                );

                $ledgerId = $this->insertWalletLedger(
                    (int) $lockedLot['workspace_id'],
                    (int) $wallet['id'],
                    null,
                    'debit',
                    'credit_expiry',
                    'lot:' . (int) $lockedLot['id'],
                    -$credits,
                    $newBalance,
                    (int) $wallet['reserved_tokens'],
                    'Expired unused AI Credits',
                    array_merge($expiryMetadata, [
                        'credit_lot_id' => (int) $lockedLot['id'],
                        'expires_at' => (string) $lockedLot['expires_at'],
                    ])
                );

                Database::execute(
                    "UPDATE workspace_credit_lots
                     SET remaining_credits = 0,
                         status = 'expired',
                         updated_at = NOW()
                     WHERE id = ?",
                    [(int) $lockedLot['id']]
                );
                $this->insertLotLedger(
                    (int) $lockedLot['workspace_id'],
                    (int) $lockedLot['id'],
                    $ledgerId,
                    'expire',
                    'credit_expiry',
                    'lot:' . (int) $lockedLot['id'],
                    -$credits,
                    0,
                    0,
                    array_merge($expiryMetadata, ['expires_at' => (string) $lockedLot['expires_at']])
                );

                Database::commit();
                $expiredLots++;
                $expiredCredits += $credits;
                $affectedWorkspaceIds[(int) $lockedLot['workspace_id']] = true;
            } catch (\Throwable $e) {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
                throw $e;
            }
        }

        if ($actorUserId !== null || $reason !== null) {
            (new OperatorAuditService())->log(
                $workspaceId !== null && $workspaceId > 0 ? 'workspace_credits_expired' : 'workspace_credits_expiry_batch_run',
                $actorUserId,
                $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null,
                $reason,
                array_merge($metadata, [
                    'workspace_id' => $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null,
                    'expired_lots' => $expiredLots,
                    'expired_credits' => $expiredCredits,
                    'affected_workspace_ids' => array_keys($affectedWorkspaceIds),
                    'processed_at' => $now,
                ])
            );
        }

        return [
            'expired_lots' => $expiredLots,
            'expired_credits' => $expiredCredits,
        ];
    }

    /**
     * @return array<string,int>
     */
    public function summary(int $workspaceId): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [
                'active_credits' => 0,
                'reserved_credits' => 0,
                'available_credits' => 0,
                'expiring_soon_credits' => 0,
                'expired_credits' => 0,
            ];
        }

        $active = Database::queryOne(
            "SELECT COALESCE(SUM(remaining_credits), 0) AS active_credits,
                    COALESCE(SUM(reserved_credits), 0) AS reserved_credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'active'
               AND expires_at > NOW()",
            [$workspaceId]
        ) ?? [];
        $expiringSoon = Database::queryOne(
            "SELECT COALESCE(SUM(remaining_credits - reserved_credits), 0) AS credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'active'
               AND expires_at > NOW()
               AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)",
            [$workspaceId]
        ) ?? [];
        $expired = Database::queryOne(
            "SELECT COALESCE(SUM(granted_credits), 0) AS credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'expired'",
            [$workspaceId]
        ) ?? [];

        $activeCredits = (int) ($active['active_credits'] ?? 0);
        $reservedCredits = (int) ($active['reserved_credits'] ?? 0);

        return [
            'active_credits' => $activeCredits,
            'reserved_credits' => $reservedCredits,
            'available_credits' => max(0, $activeCredits - $reservedCredits),
            'expiring_soon_credits' => max(0, (int) ($expiringSoon['credits'] ?? 0)),
            'expired_credits' => max(0, (int) ($expired['credits'] ?? 0)),
        ];
    }

    public function coveredCredits(int $workspaceId): int
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COALESCE(SUM(remaining_credits), 0) AS credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status IN ('active', 'expired')",
            [$workspaceId]
        ) ?? [];

        return max(0, (int) ($row['credits'] ?? 0));
    }

    public function activeLots(int $workspaceId, int $limit = 50): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        return array_map([$this, 'hydrateLot'], Database::query(
            "SELECT *
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'active'
               AND remaining_credits > 0
             ORDER BY expires_at ASC, id ASC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    public function expiringLots(int $workspaceId, int $days = 30, int $limit = 50): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        return array_map([$this, 'hydrateLot'], Database::query(
            "SELECT *
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'active'
               AND remaining_credits > 0
               AND expires_at > NOW()
               AND expires_at <= DATE_ADD(NOW(), INTERVAL " . max(1, min(365, $days)) . " DAY)
             ORDER BY expires_at ASC, id ASC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    public function lotLedger(int $workspaceId, int $limit = 50): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
            return $row;
        }, Database::query(
            "SELECT cll.*, cl.source_type, cl.source_id, cl.expires_at
             FROM workspace_credit_lot_ledger cll
             JOIN workspace_credit_lots cl ON cl.id = cll.lot_id
             WHERE cll.workspace_id = ?
             ORDER BY cll.id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lotBySource(int $workspaceId, string $sourceType, string $sourceId): ?array
    {
        $row = Database::queryOne(
            "SELECT *
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = ?
               AND source_id = ?
             LIMIT 1",
            [$workspaceId, $sourceType, $sourceId]
        );

        return $row ? $this->hydrateLot($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lotForUpdate(int $lotId): ?array
    {
        if ($lotId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM workspace_credit_lots
             WHERE id = ?
             LIMIT 1
             FOR UPDATE",
            [$lotId]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function availableLotsForUpdate(int $workspaceId): array
    {
        return Database::query(
            "SELECT *
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND status = 'active'
               AND expires_at > NOW()
               AND remaining_credits > reserved_credits
             ORDER BY expires_at ASC, id ASC
             FOR UPDATE",
            [$workspaceId]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function reservationAllocations(int $workspaceId, int $reservationLedgerId): array
    {
        if ($reservationLedgerId <= 0) {
            return [];
        }

        return Database::query(
            "SELECT *
             FROM workspace_credit_lot_ledger
             WHERE workspace_id = ?
               AND wallet_ledger_id = ?
               AND entry_type = 'reserve'
             ORDER BY id ASC",
            [$workspaceId, $reservationLedgerId]
        );
    }

    /**
     * @param array<string,mixed> $walletBefore
     */
    private function ensureLegacyLotIfNeeded(int $workspaceId, int $walletId, int $neededCredits, array $walletBefore): void
    {
        if ($neededCredits <= 0 || $workspaceId <= 0 || $walletId <= 0) {
            return;
        }

        $active = $this->summary($workspaceId);
        if ((int) ($active['available_credits'] ?? 0) >= $neededCredits) {
            return;
        }

        $walletBalance = max(0, (int) ($walletBefore['token_balance'] ?? 0));
        $walletReserved = max(0, (int) ($walletBefore['reserved_tokens'] ?? 0));
        $walletAvailable = max(0, $walletBalance - $walletReserved);
        $coveredCredits = $this->coveredCredits($workspaceId);
        $legacyCredits = max(0, $walletBalance - $coveredCredits);
        if ($legacyCredits <= 0 || $walletAvailable <= 0) {
            return;
        }

        $this->grantCredits(
            $workspaceId,
            $walletId,
            $legacyCredits,
            'legacy_wallet_balance',
            'wallet:' . $walletId,
            null,
            null,
            ['source' => 'legacy_wallet_balance', 'credit_expiry_days' => 180]
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function insertLotLedger(
        int $workspaceId,
        int $lotId,
        ?int $walletLedgerId,
        string $entryType,
        string $referenceType,
        string $referenceId,
        int $creditDelta,
        int $remainingAfter,
        int $reservedAfter,
        array $metadata = []
    ): int {
        Database::execute(
            "INSERT INTO workspace_credit_lot_ledger
                (workspace_id, lot_id, wallet_ledger_id, entry_type, reference_type, reference_id,
                 credit_delta, remaining_after, reserved_after, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $lotId,
                $walletLedgerId && $walletLedgerId > 0 ? $walletLedgerId : null,
                $entryType,
                $this->normalizeReference($referenceType, 64, 'credit_lot'),
                $this->normalizeReference($referenceId, 120, 'entry_' . bin2hex(random_bytes(6))),
                $creditDelta,
                $remainingAfter,
                $reservedAfter,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function insertWalletLedger(
        int $workspaceId,
        int $walletId,
        ?int $userId,
        string $entryType,
        string $referenceType,
        string $referenceId,
        int $tokenDelta,
        int $balanceAfter,
        int $reservedAfter,
        string $description,
        array $metadata
    ): int {
        Database::execute(
            "INSERT INTO workspace_wallet_ledger
                (workspace_id, wallet_id, user_id, entry_type, reference_type, reference_id,
                 token_delta, balance_after, reserved_after, status, description, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?)
             ON DUPLICATE KEY UPDATE
                updated_at = NOW()",
            [
                $workspaceId,
                $walletId,
                $userId && $userId > 0 ? $userId : null,
                $entryType,
                $referenceType,
                $referenceId,
                $tokenDelta,
                $balanceAfter,
                $reservedAfter,
                $description,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        $row = Database::queryOne(
            "SELECT id
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = ?
               AND reference_id = ?
               AND entry_type = ?
             LIMIT 1",
            [$workspaceId, $referenceType, $referenceId, $entryType]
        );

        return (int) ($row['id'] ?? Database::lastInsertId());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateLot(array $row): array
    {
        $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
        $row['available_credits'] = max(0, (int) ($row['remaining_credits'] ?? 0) - (int) ($row['reserved_credits'] ?? 0));
        return $row;
    }

    private function normalizeReference(string $value, int $maxLength, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = $fallback;
        }

        return substr($value, 0, $maxLength);
    }

    private function normalizeExpiry(?string $expiresAt, int $days, string $from): string
    {
        $timestamp = $expiresAt !== null && trim($expiresAt) !== '' ? strtotime($expiresAt) : false;
        if ($timestamp === false) {
            $timestamp = strtotime('+' . max(1, $days) . ' days', strtotime($from) ?: time());
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private function decodeJson($json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
