<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceCashAccountService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function ensureDefaultAccount(int $workspaceId, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_cash_accounts')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM finance_cash_accounts
             WHERE workspace_id = ? AND is_default = 1
             ORDER BY is_active DESC, id ASC
             LIMIT 1",
            [$workspaceId]
        );
        if ($row) {
            return (int) $row['id'];
        }

        Database::execute(
            "INSERT INTO finance_cash_accounts (workspace_id, name, account_type, currency, is_default, is_active, created_by)
             VALUES (?, 'Main Bank', 'bank', ?, 1, 1, ?)
             ON DUPLICATE KEY UPDATE
                is_default = 1,
                is_active = 1,
                updated_at = NOW()",
            [$workspaceId, $this->defaultCurrencyCode(), $userId > 0 ? $userId : null]
        );

        $row = Database::queryOne(
            "SELECT id FROM finance_cash_accounts WHERE workspace_id = ? AND name = 'Main Bank' LIMIT 1",
            [$workspaceId]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function defaultAccountId(int $workspaceId): int
    {
        return $this->ensureDefaultAccount($workspaceId);
    }

    public function resolveCashAccountId(int $workspaceId, mixed $cashAccountId = 0): ?int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_cash_accounts')) {
            return null;
        }

        $cashAccountId = (int) $cashAccountId;
        if ($cashAccountId <= 0) {
            $cashAccountId = $this->ensureDefaultAccount($workspaceId);
        }
        if ($cashAccountId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM finance_cash_accounts
             WHERE workspace_id = ? AND id = ? AND is_active = 1
             LIMIT 1",
            [$workspaceId, $cashAccountId]
        );
        if (!$row) {
            throw new \InvalidArgumentException('Account required.');
        }

        return (int) $row['id'];
    }

    public function listAccounts(int $workspaceId = 0, bool $includeInactive = false): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        if (!Database::tableExists('finance_cash_accounts')) {
            return [];
        }
        $this->ensureDefaultAccount($workspaceId);

        return Database::query(
            "SELECT *
             FROM finance_cash_accounts
             WHERE workspace_id = ?" . ($includeInactive ? "" : " AND is_active = 1") . "
             ORDER BY is_default DESC, is_active DESC, name ASC",
            [$workspaceId]
        );
    }

    public function getAccount(int $workspaceId, int $accountId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($accountId <= 0 || !Database::tableExists('finance_cash_accounts')) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM finance_cash_accounts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $accountId]
        );
    }

    public function saveAccount(int $workspaceId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_cash_accounts')) {
            throw new \RuntimeException('Finance account tables are not installed.');
        }

        $accountId = (int) ($data['id'] ?? 0);
        $name = $this->sanitizeText((string) ($data['name'] ?? ''), 140);
        if ($name === '') {
            throw new \InvalidArgumentException('Name required.');
        }
        $type = $this->normalizeAccountType((string) ($data['account_type'] ?? 'bank'));
        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()));
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;

        Database::beginTransaction();
        try {
            if ($isDefault === 1) {
                Database::execute(
                    "UPDATE finance_cash_accounts SET is_default = 0 WHERE workspace_id = ?",
                    [$workspaceId]
                );
            }

            if ($accountId > 0 && $this->getAccount($workspaceId, $accountId)) {
                Database::execute(
                    "UPDATE finance_cash_accounts
                     SET name = ?, account_type = ?, currency = ?, is_default = ?, is_active = ?
                     WHERE workspace_id = ? AND id = ?",
                    [$name, $type, $currency, $isDefault, $isActive, $workspaceId, $accountId]
                );
            } else {
                Database::execute(
                    "INSERT INTO finance_cash_accounts (
                        workspace_id, name, account_type, currency, is_default, is_active, created_by
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        account_type = VALUES(account_type),
                        currency = VALUES(currency),
                        is_default = VALUES(is_default),
                        is_active = VALUES(is_active),
                        updated_at = NOW()",
                    [$workspaceId, $name, $type, $currency, $isDefault, $isActive, $userId > 0 ? $userId : null]
                );
                $row = Database::queryOne(
                    "SELECT id FROM finance_cash_accounts WHERE workspace_id = ? AND name = ? LIMIT 1",
                    [$workspaceId, $name]
                );
                $accountId = (int) ($row['id'] ?? 0);
            }

            if ($isDefault === 0) {
                $this->ensureDefaultAccount($workspaceId, $userId);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $accountId;
    }

    public function deactivateAccount(int $workspaceId, int $accountId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $account = $this->getAccount($workspaceId, $accountId);
        if (!$account) {
            return;
        }
        if (!empty($account['is_default'])) {
            throw new \InvalidArgumentException('Default account cannot be removed.');
        }

        Database::execute(
            "UPDATE finance_cash_accounts
             SET is_active = 0, updated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $accountId]
        );
    }

    private function normalizeAccountType(string $value): string
    {
        return in_array($value, ['bank', 'cash', 'mobile_money', 'other'], true) ? $value : 'bank';
    }

    private function sanitizeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
        return mb_substr($value, 0, $max);
    }

    private function sanitizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $this->defaultCurrencyCode();
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
