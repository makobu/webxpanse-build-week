<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Currencies;

class WorkspaceFinanceGateService
{
    public const SETUP_SKILL_KEY = WorkspaceSkillCatalogService::PLUGIN_FINANCE;
    public const SETUP_URL = 'workspace_skills.php?module=' . self::SETUP_SKILL_KEY;
    public const RUNTIME_URL = 'finance.php';

    private WorkspaceSkillInstallService $installer;
    private FinanceOwnerEquityService $ownerEquity;
    private FinanceLedgerService $ledger;
    private FinanceOpeningSetupService $openingSetup;
    private array $statusCache = [];

    public function __construct(
        ?WorkspaceSkillInstallService $installer = null,
        ?FinanceOwnerEquityService $ownerEquity = null,
        ?FinanceLedgerService $ledger = null,
        ?FinanceOpeningSetupService $openingSetup = null
    ) {
        $this->installer = $installer ?? new WorkspaceSkillInstallService();
        $this->ownerEquity = $ownerEquity ?? new FinanceOwnerEquityService();
        $this->ledger = $ledger ?? new FinanceLedgerService();
        $this->openingSetup = $openingSetup ?? new FinanceOpeningSetupService(null, null, $this->ownerEquity, $this->ledger);
    }

    public function status(int $workspaceId, ?array $user = null): array
    {
        $user = $user ?? (Auth::user() ?: []);
        $userId = (int) ($user['id'] ?? 0);

        $installed = $workspaceId > 0 && $this->installer->isInstalled($workspaceId, self::SETUP_SKILL_KEY);
        $canManage = Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.manage', $user)
            || Authorization::can('finance.manage', $user);

        $opening = $workspaceId > 0 ? $this->openingSetup->status($workspaceId) : ['ok' => false, 'detail' => 'No active workspace.'];
        $owners = $workspaceId > 0 ? $this->ownerStatus($workspaceId) : ['ok' => false, 'detail' => 'No active workspace.'];
        $ready = $installed && !empty($opening['ok']) && !empty($owners['ok']);
        $checks = [
            [
                'key' => 'installed',
                'label' => 'Marketplace install',
                'ok' => $installed,
                'required' => true,
                'detail' => $installed ? 'Finance is installed.' : 'Install Finance.',
            ],
            [
                'key' => 'opening_balances',
                'label' => 'Initial balance sheet',
                'ok' => !empty($opening['ok']),
                'required' => true,
                'detail' => (string) ($opening['detail'] ?? ''),
            ],
            [
                'key' => 'owner_equity',
                'label' => 'Owners',
                'ok' => !empty($owners['ok']),
                'required' => true,
                'detail' => (string) ($owners['detail'] ?? ''),
            ],
        ];
        $blockers = array_values(array_map(
            static fn(array $check): string => (string) $check['label'],
            array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
        ));

        return [
            'installed' => $installed,
            'enabled' => $installed,
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($installed ? 'setup_required' : 'not_installed'),
            'message' => $ready
                ? 'Finance is ready.'
                : ($installed ? 'Complete Finance setup before opening Finance.' : 'Install Finance before setup.'),
            'owner_message' => $ready
                ? 'Finance is ready.'
                : 'Ask a workspace owner or finance manager to complete Finance setup in Marketplace.',
            'setup_url' => self::SETUP_URL,
            'runtime_url' => self::RUNTIME_URL,
            'can_manage' => $canManage,
            'owner_roi_visible' => $userId > 0 && in_array($userId, (array) ($owners['user_ids'] ?? []), true),
            'checks' => $checks,
            'blockers' => $blockers,
            'opening' => $opening,
            'owners' => $owners,
            'next_action' => $ready ? 'Open Finance.' : 'Complete Finance setup.',
        ];
    }

    public function assertRuntimeReady(int $workspaceId, ?array $user = null, bool $asJson = false): void
    {
        $status = $this->status($workspaceId, $user);
        if (!empty($status['ready'])) {
            return;
        }

        if ($asJson) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Finance setup required',
                'finance_gate' => $status,
                'setup_url' => (string) ($status['setup_url'] ?? self::SETUP_URL),
                'blockers' => (array) ($status['blockers'] ?? []),
            ]);
            exit;
        }

        $setupUrl = (string) ($status['setup_url'] ?? self::SETUP_URL);
        header('Location: ' . $setupUrl);
        exit;
    }

    public function readiness(int $workspaceId, int $userId): array
    {
        $status = $this->status($workspaceId, Auth::user());
        return [
            'enabled' => (bool) ($status['installed'] ?? false),
            'ready' => (bool) ($status['ready'] ?? false),
            'status' => (string) ($status['status'] ?? 'not_installed'),
            'message' => (string) ($status['message'] ?? ''),
            'checks' => (array) ($status['checks'] ?? []),
            'blockers' => (array) ($status['blockers'] ?? []),
            'next_action' => (string) ($status['next_action'] ?? ''),
            'setup_url' => self::SETUP_URL,
            'runtime_url' => self::RUNTIME_URL,
            'owner_roi_visible' => (bool) ($status['owner_roi_visible'] ?? false),
            'owners' => (array) ($status['owners'] ?? []),
            'opening' => (array) ($status['opening'] ?? []),
        ];
    }

    public function saveInitialSetup(int $workspaceId, array $data, int $userId): array
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }
        if (!$this->installer->isInstalled($workspaceId, self::SETUP_SKILL_KEY)) {
            $this->installer->install($workspaceId, self::SETUP_SKILL_KEY, $userId, ['source' => 'finance_marketplace_setup']);
        }

        $tab = strtolower(trim((string) ($data['finance_setup_tab'] ?? '')));
        if ($tab !== '') {
            $this->openingSetup->saveTab($workspaceId, $tab, $data, $userId);
        } else {
            $this->openingSetup->saveLegacySetup($workspaceId, $data, $userId);
        }

        $this->clearStatusCache($workspaceId);
        return $this->status($workspaceId, Auth::user());
    }

    public function setupFormData(int $workspaceId): array
    {
        return $this->openingSetup->setupFormData($workspaceId);
    }

    private function clearStatusCache(int $workspaceId): void
    {
        foreach (array_keys($this->statusCache) as $cacheKey) {
            if (str_starts_with((string) $cacheKey, $workspaceId . ':')) {
                unset($this->statusCache[$cacheKey]);
            }
        }
    }

    private function openingStatus(int $workspaceId): array
    {
        $transaction = $this->latestOpeningBalance($workspaceId);
        if ($transaction === null) {
            return ['ok' => false, 'detail' => 'Opening figures have not been saved.'];
        }

        return ['ok' => true, 'detail' => 'Opening figures were saved on ' . (string) ($transaction['transaction_date'] ?? 'the opening date') . '.'];
    }

    private function ownerStatus(int $workspaceId): array
    {
        $owners = $this->ownerEquity->activeOwners($workspaceId);
        if ($owners === []) {
            return ['ok' => false, 'detail' => 'No active workspace owner accounts were found.', 'owner_count' => 0, 'total_percent' => 0.0];
        }
        $ownerUserIds = array_values(array_map(static fn(array $owner): int => (int) ($owner['user_id'] ?? 0), $owners));
        $profiles = $this->ownerEquity->profiles($workspaceId);
        if (count($profiles) < count($owners)) {
            return ['ok' => false, 'detail' => 'Every active owner needs an equity profile.', 'owner_count' => count($owners), 'profile_count' => count($profiles), 'user_ids' => $ownerUserIds];
        }
        $total = round(array_sum(array_map(static fn(array $profile): float => (float) ($profile['ownership_percent'] ?? 0), $profiles)), 4);
        if (abs($total - 100.0) > 0.0001) {
            return ['ok' => false, 'detail' => 'Owner equity allocation must total 100%.', 'owner_count' => count($owners), 'profile_count' => count($profiles), 'total_percent' => $total, 'user_ids' => $ownerUserIds];
        }

        return ['ok' => true, 'detail' => 'Owner equity is allocated across ' . count($profiles) . ' owner account(s).', 'owner_count' => count($owners), 'profile_count' => count($profiles), 'total_percent' => $total, 'user_ids' => $ownerUserIds];
    }

    private function latestOpeningBalance(int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !Database::tableExists('finance_transactions')) {
            return null;
        }
        $row = Database::queryOne(
            "SELECT id
             FROM finance_transactions
             WHERE workspace_id = ?
               AND transaction_type = 'opening_balance'
             ORDER BY transaction_date DESC, id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $id = (int) ($row['id'] ?? 0);
        return $id > 0 ? $this->ledger->getTransaction($workspaceId, $id) : null;
    }

    private function latestOpeningBalanceId(int $workspaceId): int
    {
        $transaction = $this->latestOpeningBalance($workspaceId);
        return (int) ($transaction['id'] ?? 0);
    }

    private function amount(mixed $value): float
    {
        return round(max(0, min((float) $value, 999999999.99)), 2);
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
    }

    private function sanitizeCurrency(string $value, string $fallback = 'USD'): string
    {
        $value = strtoupper(trim($value));
        $fallback = strtoupper(trim($fallback)) ?: 'USD';
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $fallback;
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
