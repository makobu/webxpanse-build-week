<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceOwnerEquityService
{
    private WorkspaceScopeService $workspaceScope;
    private array $isOwnerUserCache = [];

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function activeOwners(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('workspace_memberships')) {
            return [];
        }

        return Database::query(
            "SELECT
                wm.user_id,
                wm.role_slug,
                wm.is_owner,
                u.email,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.email) AS display_name
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
             ORDER BY wm.is_owner DESC, u.email ASC",
            [$workspaceId]
        );
    }

    public function isOwnerUser(int $workspaceId, int $userId): bool
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('workspace_memberships')) {
            return false;
        }

        $cacheKey = $workspaceId . ':' . $userId;
        if (array_key_exists($cacheKey, $this->isOwnerUserCache)) {
            return $this->isOwnerUserCache[$cacheKey];
        }

        $row = Database::queryOne(
            "SELECT 1
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')
             LIMIT 1",
            [$workspaceId, $userId]
        );

        $this->isOwnerUserCache[$cacheKey] = $row !== null;
        return $this->isOwnerUserCache[$cacheKey];
    }

    public function profiles(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_owner_equity_profiles')) {
            return [];
        }

        $rows = Database::query(
            "SELECT
                p.*,
                u.email,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.email) AS display_name
             FROM finance_owner_equity_profiles p
             JOIN users u ON u.id = p.user_id
             WHERE p.workspace_id = ?
               AND p.is_active = 1
             ORDER BY u.email ASC",
            [$workspaceId]
        );

        return array_map(fn(array $row): array => $this->normalizeProfileRow($row), $rows);
    }

    public function profileForUser(int $workspaceId, int $userId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($userId <= 0 || !Database::tableExists('finance_owner_equity_profiles')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT
                p.*,
                u.email,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.email) AS display_name
             FROM finance_owner_equity_profiles p
             JOIN users u ON u.id = p.user_id
             WHERE p.workspace_id = ?
               AND p.user_id = ?
               AND p.is_active = 1
             LIMIT 1",
            [$workspaceId, $userId]
        );

        return $row ? $this->normalizeProfileRow($row) : null;
    }

    public function saveProfiles(int $workspaceId, array $profiles, int $actorUserId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_owner_equity_profiles')) {
            throw new \RuntimeException('Finance owner equity setup tables are not installed.');
        }

        $owners = $this->activeOwners($workspaceId);
        $ownerIds = array_map(static fn(array $owner): int => (int) ($owner['user_id'] ?? 0), $owners);
        $ownerIdLookup = array_fill_keys($ownerIds, true);
        if ($ownerIds === []) {
            throw new \RuntimeException('Add at least one active workspace owner before Finance setup can be completed.');
        }

        $currency = $this->defaultCurrencyCode();
        $normalized = [];
        foreach ($profiles as $profile) {
            $userId = (int) ($profile['user_id'] ?? 0);
            if ($userId <= 0 || !isset($ownerIdLookup[$userId])) {
                continue;
            }
            $normalized[$userId] = [
                'user_id' => $userId,
                'ownership_percent' => $this->percent($profile['ownership_percent'] ?? 0),
                'opening_owner_capital' => $this->amount($profile['opening_owner_capital'] ?? 0),
                'opening_owner_draws' => $this->amount($profile['opening_owner_draws'] ?? 0),
                'currency' => $this->sanitizeCurrency((string) ($profile['currency'] ?? $currency), $currency),
                'notes' => $this->sanitizeText((string) ($profile['notes'] ?? ''), 1000),
            ];
        }

        foreach ($ownerIds as $ownerId) {
            $normalized[$ownerId] ??= [
                'user_id' => $ownerId,
                'ownership_percent' => count($ownerIds) === 1 ? 100.0 : 0.0,
                'opening_owner_capital' => 0.0,
                'opening_owner_draws' => 0.0,
                'currency' => $currency,
                'notes' => '',
            ];
        }

        $total = round(array_sum(array_map(static fn(array $profile): float => (float) $profile['ownership_percent'], $normalized)), 4);
        if (abs($total - 100.0) > 0.0001) {
            throw new \InvalidArgumentException('Owner ownership percentages must total 100%.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE finance_owner_equity_profiles
                 SET is_active = 0, updated_at = NOW()
                 WHERE workspace_id = ?",
                [$workspaceId]
            );

            foreach ($normalized as $profile) {
                Database::execute(
                    "INSERT INTO finance_owner_equity_profiles (
                        workspace_id, user_id, ownership_percent, opening_owner_capital,
                        opening_owner_draws, currency, notes, is_active, created_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE
                        ownership_percent = VALUES(ownership_percent),
                        opening_owner_capital = VALUES(opening_owner_capital),
                        opening_owner_draws = VALUES(opening_owner_draws),
                        currency = VALUES(currency),
                        notes = VALUES(notes),
                        is_active = 1,
                        created_by = COALESCE(created_by, VALUES(created_by)),
                        updated_at = NOW()",
                    [
                        $workspaceId,
                        (int) $profile['user_id'],
                        (float) $profile['ownership_percent'],
                        (float) $profile['opening_owner_capital'],
                        (float) $profile['opening_owner_draws'],
                        (string) $profile['currency'],
                        $profile['notes'] !== '' ? (string) $profile['notes'] : null,
                        $actorUserId > 0 ? $actorUserId : null,
                    ]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->clearRequestCaches($workspaceId);
        return $this->profiles($workspaceId);
    }

    private function clearRequestCaches(int $workspaceId): void
    {
        foreach (array_keys($this->isOwnerUserCache) as $cacheKey) {
            if (str_starts_with((string) $cacheKey, $workspaceId . ':')) {
                unset($this->isOwnerUserCache[$cacheKey]);
            }
        }
    }

    public function validateOwnerUserId(int $workspaceId, mixed $ownerUserId, string $transactionType): ?int
    {
        $ownerUserId = (int) $ownerUserId;
        if ($ownerUserId <= 0) {
            return null;
        }
        if (!in_array($transactionType, ['founder_capital', 'equity_funding', 'owner_draw'], true)) {
            return null;
        }
        if (!$this->isOwnerUser($workspaceId, $ownerUserId)) {
            throw new \InvalidArgumentException('Owner attribution must use an active workspace owner account.');
        }

        return $ownerUserId;
    }

    public function personalRoi(int $workspaceId, int $userId, array $statements): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!$this->isOwnerUser($workspaceId, $userId)) {
            return null;
        }
        $profile = $this->profileForUser($workspaceId, $userId);
        if ($profile === null) {
            return null;
        }

        $ledger = $this->ownerLedgerTotals($workspaceId, $userId);
        $totalEquity = (float) ($statements['working_balance_sheet']['equity']['total_equity']
            ?? $statements['working_balance_sheet']['owner_equity']
            ?? 0);
        $ownershipPercent = (float) ($profile['ownership_percent'] ?? 0);
        $personalEquityValue = round($totalEquity * ($ownershipPercent / 100), 2);
        $capitalContributed = round((float) ($profile['opening_owner_capital'] ?? 0) + (float) ($ledger['capital_contributed'] ?? 0), 2);
        $draws = round((float) ($profile['opening_owner_draws'] ?? 0) + (float) ($ledger['draws'] ?? 0), 2);
        $roiAmount = round($personalEquityValue + $draws - $capitalContributed, 2);

        return [
            'visible' => true,
            'user_id' => $userId,
            'owner_name' => (string) ($profile['display_name'] ?? $profile['email'] ?? 'Owner'),
            'currency' => (string) ($profile['currency'] ?? $this->defaultCurrencyCode()),
            'ownership_percent' => $ownershipPercent,
            'personal_equity_value' => $personalEquityValue,
            'capital_contributed' => $capitalContributed,
            'draws' => $draws,
            'roi_amount' => $roiAmount,
            'roi_percent' => $capitalContributed > 0 ? round(($roiAmount / $capitalContributed) * 100, 1) : null,
        ];
    }

    public function ownerLedgerTotals(int $workspaceId, int $userId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($userId <= 0 || !Database::tableExists('finance_transactions') || !Database::columnExists('finance_transactions', 'owner_user_id')) {
            return ['capital_contributed' => 0.0, 'draws' => 0.0];
        }

        $rows = Database::query(
            "SELECT transaction_type, COALESCE(SUM(amount), 0) AS total
             FROM finance_transactions
             WHERE workspace_id = ?
               AND owner_user_id = ?
               AND transaction_type IN ('founder_capital','equity_funding','owner_draw')
             GROUP BY transaction_type",
            [$workspaceId, $userId]
        );

        $capital = 0.0;
        $draws = 0.0;
        foreach ($rows as $row) {
            $type = (string) ($row['transaction_type'] ?? '');
            $amount = (float) ($row['total'] ?? 0);
            if (in_array($type, ['founder_capital', 'equity_funding'], true)) {
                $capital += $amount;
            } elseif ($type === 'owner_draw') {
                $draws += $amount;
            }
        }

        return ['capital_contributed' => round($capital, 2), 'draws' => round($draws, 2)];
    }

    private function normalizeProfileRow(array $row): array
    {
        $row['user_id'] = (int) ($row['user_id'] ?? 0);
        $row['ownership_percent'] = round((float) ($row['ownership_percent'] ?? 0), 4);
        $row['opening_owner_capital'] = round((float) ($row['opening_owner_capital'] ?? 0), 2);
        $row['opening_owner_draws'] = round((float) ($row['opening_owner_draws'] ?? 0), 2);
        $row['is_active'] = !empty($row['is_active']);
        return $row;
    }

    private function amount(mixed $value): float
    {
        return round(max(0, min((float) $value, 999999999.99)), 2);
    }

    private function percent(mixed $value): float
    {
        return round(max(0, min((float) $value, 100.0)), 4);
    }

    private function sanitizeCurrency(string $value, string $fallback = 'USD'): string
    {
        $value = strtoupper(trim($value));
        $fallback = strtoupper(trim($fallback)) ?: 'USD';
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $fallback;
    }

    private function sanitizeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }

    private function defaultCurrencyCode(): string
    {
        try {
            if (Database::tableExists('currencies')) {
                $default = (new Currencies())->getDefault();
                $code = strtoupper(trim((string) ($default['code'] ?? '')));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
        }

        return 'USD';
    }
}
