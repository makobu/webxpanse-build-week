<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceWalletService
{
    public function ensureWallet(int $workspaceId, string $currency = 'KES'): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required.');
        }

        $existing = $this->getWallet($workspaceId);
        if ($existing !== null) {
            return $existing;
        }

        Database::execute(
            "INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
             VALUES (?, ?, 0, 0, 0, 0, NOW())",
            [$workspaceId, trim($currency) !== '' ? trim($currency) : 'KES']
        );

        return $this->getWallet($workspaceId) ?? [];
    }

    public function getWallet(int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !$this->walletTablesAvailable()) {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM workspace_wallets WHERE workspace_id = ? LIMIT 1",
            [$workspaceId]
        );
    }

    public function getSummary(int $workspaceId): array
    {
        $creditLots = new WorkspaceCreditLedgerService();
        $wallet = $this->ensureWallet($workspaceId);
        $walletBalance = max(0, (int) ($wallet['token_balance'] ?? 0));
        $walletAvailable = max(0, $walletBalance - (int) ($wallet['reserved_tokens'] ?? 0));
        $lotSummary = $creditLots->isAvailable() ? $creditLots->summary($workspaceId) : [];
        $lotAvailable = (int) ($lotSummary['available_credits'] ?? 0);
        $coveredCredits = $creditLots->isAvailable() ? $creditLots->coveredCredits($workspaceId) : 0;
        $legacyAvailable = max(0, min($walletAvailable, $walletBalance - $coveredCredits));
        $available = $lotAvailable + $legacyAvailable;

        return [
            'workspace_id' => $workspaceId,
            'wallet_id' => (int) ($wallet['id'] ?? 0),
            'currency' => (string) ($wallet['currency'] ?? 'KES'),
            'token_balance' => (int) ($wallet['token_balance'] ?? 0),
            'credit_balance' => (int) ($wallet['token_balance'] ?? 0),
            'reserved_tokens' => (int) ($wallet['reserved_tokens'] ?? 0),
            'reserved_credits' => (int) ($wallet['reserved_tokens'] ?? 0),
            'available_tokens' => $available,
            'available_credits' => $available,
            'active_credit_balance' => (int) ($lotSummary['active_credits'] ?? 0),
            'expiring_soon_credits' => (int) ($lotSummary['expiring_soon_credits'] ?? 0),
            'expired_credits' => (int) ($lotSummary['expired_credits'] ?? 0),
            'credit_lot_summary' => $lotSummary,
            'is_depleted' => $available <= 0,
        ];
    }

    public function creditTokens(
        int $workspaceId,
        int $tokens,
        string $referenceType,
        string $referenceId,
        ?int $userId = null,
        array $metadata = []
    ): array {
        if ($tokens <= 0) {
            throw new \RuntimeException('Token credit must be greater than zero.');
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($tokens, $referenceType, $referenceId, $userId, $metadata): array {
            $newBalance = (int) $wallet['token_balance'] + $tokens;

            Database::execute(
                "UPDATE workspace_wallets
                 SET token_balance = ?, lifetime_credited_tokens = lifetime_credited_tokens + ?, last_activity_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$newBalance, $tokens, (int) $wallet['id']]
            );

            $ledgerId = $this->insertLedgerEntry(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'credit',
                $referenceType,
                $referenceId,
                $tokens,
                $newBalance,
                (int) $wallet['reserved_tokens'],
                'posted',
                'Wallet credited',
                $metadata
            );
            $lot = (new WorkspaceCreditLedgerService())->grantCredits(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $tokens,
                $referenceType,
                $referenceId,
                $ledgerId,
                $userId,
                $metadata,
                isset($metadata['expires_at']) ? (string) $metadata['expires_at'] : null
            );

            return [
                'wallet_id' => (int) $wallet['id'],
                'ledger_entry_id' => $ledgerId,
                'credit_lot_id' => (int) ($lot['id'] ?? 0),
                'token_balance' => $newBalance,
                'credit_balance' => $newBalance,
                'reserved_tokens' => (int) $wallet['reserved_tokens'],
            ];
        });
    }

    public function debitTokens(
        int $workspaceId,
        int $tokens,
        string $referenceType,
        string $referenceId,
        ?int $userId = null,
        array $metadata = [],
        string $description = 'Wallet debited'
    ): array {
        if ($tokens <= 0) {
            throw new \RuntimeException('Token debit must be greater than zero.');
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($tokens, $referenceType, $referenceId, $userId, $metadata, $description): array {
            $currentBalance = (int) ($wallet['token_balance'] ?? 0);
            $reservedTokens = (int) ($wallet['reserved_tokens'] ?? 0);
            $available = $currentBalance - $reservedTokens;

            if ($available < $tokens) {
                throw new \RuntimeException('Not enough available AI Credits to complete this adjustment.');
            }

            $newBalance = $currentBalance - $tokens;
            Database::execute(
                "UPDATE workspace_wallets
                 SET token_balance = ?, lifetime_debited_tokens = lifetime_debited_tokens + ?, last_activity_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$newBalance, $tokens, (int) $wallet['id']]
            );

            $ledgerId = $this->insertLedgerEntry(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'debit',
                $referenceType,
                $referenceId,
                -$tokens,
                $newBalance,
                $reservedTokens,
                'posted',
                $description,
                $metadata
            );
            $allocations = (new WorkspaceCreditLedgerService())->debitCredits(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $ledgerId,
                $tokens,
                $referenceType,
                $referenceId,
                $metadata,
                $wallet
            );

            return [
                'wallet_id' => (int) $wallet['id'],
                'ledger_entry_id' => $ledgerId,
                'token_balance' => $newBalance,
                'credit_balance' => $newBalance,
                'reserved_tokens' => $reservedTokens,
                'debited_tokens' => $tokens,
                'debited_credits' => $tokens,
                'credit_lot_allocations' => $allocations,
                'available_tokens' => max(0, $newBalance - $reservedTokens),
                'available_credits' => max(0, $newBalance - $reservedTokens),
            ];
        });
    }

    public function reserveTokens(
        int $workspaceId,
        int $tokens,
        string $referenceType,
        string $referenceId,
        ?int $userId = null,
        array $metadata = []
    ): array {
        if ($tokens <= 0) {
            return [
                'ok' => true,
                'reserved_tokens' => 0,
                'workspace_id' => $workspaceId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'ledger_entry_id' => null,
            ];
        }

        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($tokens, $referenceType, $referenceId, $userId, $metadata): array {
            $available = (int) $wallet['token_balance'] - (int) $wallet['reserved_tokens'];
            if ($available < $tokens) {
                return [
                    'ok' => false,
                    'workspace_id' => (int) $wallet['workspace_id'],
                    'wallet_id' => (int) $wallet['id'],
                    'available_tokens' => $available,
                    'requested_tokens' => $tokens,
                ];
            }

            $newReserved = (int) $wallet['reserved_tokens'] + $tokens;
            Database::execute(
                "UPDATE workspace_wallets
                 SET reserved_tokens = ?, last_activity_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$newReserved, (int) $wallet['id']]
            );

            $ledgerId = $this->insertLedgerEntry(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'reserve',
                $referenceType,
                $referenceId,
                -$tokens,
                (int) $wallet['token_balance'],
                $newReserved,
                'pending',
                'Reserved tokens for AI request',
                $metadata
            );
            $allocations = (new WorkspaceCreditLedgerService())->reserveCredits(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $ledgerId,
                $tokens,
                $referenceType,
                $referenceId,
                $metadata,
                $wallet
            );

            return [
                'ok' => true,
                'workspace_id' => (int) $wallet['workspace_id'],
                'wallet_id' => (int) $wallet['id'],
                'ledger_entry_id' => $ledgerId,
                'available_tokens' => (int) $wallet['token_balance'] - $newReserved,
                'available_credits' => (int) $wallet['token_balance'] - $newReserved,
                'reserved_tokens' => $tokens,
                'reserved_credits' => $tokens,
                'credit_lot_allocations' => $allocations,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ];
        });
    }

    public function releaseReservation(
        int $workspaceId,
        string $referenceType,
        string $referenceId,
        ?int $userId = null,
        string $description = 'Released reserved tokens',
        array $metadata = []
    ): void {
        $this->withLockedWallet($workspaceId, function (array $wallet) use ($referenceType, $referenceId, $userId, $description, $metadata): void {
            $reservation = Database::queryOne(
                "SELECT * FROM workspace_wallet_ledger
                 WHERE workspace_id = ? AND wallet_id = ? AND reference_type = ? AND reference_id = ? AND entry_type = 'reserve'
                 LIMIT 1
                 FOR UPDATE",
                [(int) $wallet['workspace_id'], (int) $wallet['id'], $referenceType, $referenceId]
            );

            if (!$reservation || (string) ($reservation['status'] ?? '') === 'voided') {
                return;
            }

            $reserved = abs((int) ($reservation['token_delta'] ?? 0));
            $newReserved = max(0, (int) $wallet['reserved_tokens'] - $reserved);

            Database::execute(
                "UPDATE workspace_wallets
                 SET reserved_tokens = ?, last_activity_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$newReserved, (int) $wallet['id']]
            );

            Database::execute(
                "UPDATE workspace_wallet_ledger
                 SET status = 'voided', updated_at = NOW()
                 WHERE id = ?",
                [(int) $reservation['id']]
            );

            $releaseLedgerId = $this->insertLedgerEntry(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'release',
                $referenceType,
                $referenceId . ':release',
                $reserved,
                (int) $wallet['token_balance'],
                $newReserved,
                'posted',
                $description,
                $metadata
            );
            (new WorkspaceCreditLedgerService())->releaseReservation(
                (int) $wallet['workspace_id'],
                (int) $reservation['id'],
                $releaseLedgerId,
                $referenceType,
                $referenceId . ':release',
                $metadata
            );
        });
    }

    public function settleReservation(
        int $workspaceId,
        string $referenceType,
        string $referenceId,
        int $actualTokens,
        ?int $userId = null,
        array $metadata = []
    ): array {
        return $this->withLockedWallet($workspaceId, function (array $wallet) use ($referenceType, $referenceId, $actualTokens, $userId, $metadata): array {
            $reservation = Database::queryOne(
                "SELECT * FROM workspace_wallet_ledger
                 WHERE workspace_id = ? AND wallet_id = ? AND reference_type = ? AND reference_id = ? AND entry_type = 'reserve'
                 LIMIT 1
                 FOR UPDATE",
                [(int) $wallet['workspace_id'], (int) $wallet['id'], $referenceType, $referenceId]
            );

            $reserved = $reservation ? abs((int) ($reservation['token_delta'] ?? 0)) : 0;
            $actualTokens = max(0, $actualTokens);
            $released = max(0, $reserved - $actualTokens);
            $newReserved = max(0, (int) $wallet['reserved_tokens'] - $reserved);
            $newBalance = max(0, (int) $wallet['token_balance'] - $actualTokens);

            Database::execute(
                "UPDATE workspace_wallets
                 SET token_balance = ?, reserved_tokens = ?, lifetime_debited_tokens = lifetime_debited_tokens + ?, last_activity_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [$newBalance, $newReserved, $actualTokens, (int) $wallet['id']]
            );

            if ($reservation) {
                Database::execute(
                    "UPDATE workspace_wallet_ledger
                     SET status = 'voided', updated_at = NOW()
                     WHERE id = ?",
                    [(int) $reservation['id']]
                );
            }

            $debitLedgerId = $this->insertLedgerEntry(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $userId,
                'debit',
                $referenceType,
                $referenceId,
                -$actualTokens,
                $newBalance,
                $newReserved,
                'posted',
                'Settled AI token usage',
                $metadata
            );

            $releaseLedgerId = null;
            if ($released > 0) {
                $releaseLedgerId = $this->insertLedgerEntry(
                    (int) $wallet['workspace_id'],
                    (int) $wallet['id'],
                    $userId,
                    'release',
                    $referenceType,
                    $referenceId . ':release',
                    $released,
                    $newBalance,
                    $newReserved,
                    'posted',
                    'Released unused reserved tokens',
                    $metadata
                );
            }
            $lotSettlement = (new WorkspaceCreditLedgerService())->settleReservation(
                (int) $wallet['workspace_id'],
                (int) $wallet['id'],
                $reservation ? (int) $reservation['id'] : 0,
                $debitLedgerId,
                $releaseLedgerId,
                $actualTokens,
                $referenceType,
                $referenceId,
                $metadata,
                $wallet
            );

            return [
                'workspace_id' => (int) $wallet['workspace_id'],
                'wallet_id' => (int) $wallet['id'],
                'ledger_entry_id' => $debitLedgerId,
                'token_balance' => $newBalance,
                'credit_balance' => $newBalance,
                'reserved_tokens' => $newReserved,
                'released_tokens' => $released,
                'released_credits' => $released,
                'debited_tokens' => $actualTokens,
                'debited_credits' => $actualTokens,
                'credit_lot_settlement' => $lotSettlement,
            ];
        });
    }

    private function insertLedgerEntry(
        int $workspaceId,
        int $walletId,
        ?int $userId,
        string $entryType,
        string $referenceType,
        string $referenceId,
        int $tokenDelta,
        int $balanceAfter,
        int $reservedAfter,
        string $status,
        string $description,
        array $metadata
    ): int {
        Database::execute(
            "INSERT INTO workspace_wallet_ledger
             (workspace_id, wallet_id, user_id, entry_type, reference_type, reference_id, token_delta, balance_after, reserved_after, status, description, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $walletId,
                $userId,
                $entryType,
                $referenceType,
                $referenceId,
                $tokenDelta,
                $balanceAfter,
                $reservedAfter,
                $status,
                $description,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return mixed
     */
    private function withLockedWallet(int $workspaceId, callable $callback)
    {
        if (!$this->walletTablesAvailable()) {
            throw new \RuntimeException('Workspace wallet tables are not available.');
        }

        $pdo = Database::getInstance();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            Database::beginTransaction();
        }
        try {
            $this->ensureWallet($workspaceId);
            $wallet = Database::queryOne(
                "SELECT * FROM workspace_wallets WHERE workspace_id = ? LIMIT 1 FOR UPDATE",
                [$workspaceId]
            );

            if ($wallet === null) {
                throw new \RuntimeException('Workspace wallet could not be loaded.');
            }

            $result = $callback($wallet);
            if ($startedTransaction) {
                Database::commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function walletTablesAvailable(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_wallets'",
                []
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
