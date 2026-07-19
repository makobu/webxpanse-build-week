<?php

namespace CRM\Services;

use CRM\Database;

class BillingPaymentMarketService
{
    public const SCOPE_COUNTRY = 'country';
    public const SCOPE_REGION = 'region';

    private const CURRENCY_MARKETS = [
        'KES' => ['country_code' => 'KE', 'region_code' => 'EAST_AFRICA', 'label' => 'Kenya'],
        'UGX' => ['country_code' => 'UG', 'region_code' => 'EAST_AFRICA', 'label' => 'Uganda'],
        'TZS' => ['country_code' => 'TZ', 'region_code' => 'EAST_AFRICA', 'label' => 'Tanzania'],
        'RWF' => ['country_code' => 'RW', 'region_code' => 'EAST_AFRICA', 'label' => 'Rwanda'],
        'NGN' => ['country_code' => 'NG', 'region_code' => 'WEST_AFRICA', 'label' => 'Nigeria'],
        'GHS' => ['country_code' => 'GH', 'region_code' => 'WEST_AFRICA', 'label' => 'Ghana'],
        'ZAR' => ['country_code' => 'ZA', 'region_code' => 'SOUTHERN_AFRICA', 'label' => 'South Africa'],
    ];

    /**
     * @param array<string,bool> $globalAvailability
     * @return array<string,mixed>
     */
    public function effectivePolicy(?int $workspaceId, string $currency, array $globalAvailability): array
    {
        $availability = $this->normalizeAvailability($globalAvailability);
        $market = $this->resolveMarket($workspaceId, $currency);
        $rule = $this->findApplicableRule(
            (string) ($market['country_code'] ?? ''),
            (string) ($market['region_code'] ?? '')
        );
        $reasons = [];

        if ($rule !== null) {
            $marketLabel = trim((string) ($rule['scope_name'] ?? ''));
            if ($marketLabel === '') {
                $marketLabel = (string) ($rule['scope_code'] ?? 'this market');
            }
            foreach ($this->modeColumns() as $mode => $column) {
                if (empty($rule[$column])) {
                    $availability[$mode] = false;
                    $reasons[$mode] = $this->modeLabel($mode) . ' is disabled for ' . $marketLabel . ' by regional payment policy.';
                }
            }
        }

        return [
            'availability' => $availability,
            'reasons' => $reasons,
            'market' => $market,
            'applied_rule' => $rule,
        ];
    }

    /** @return array<string,mixed> */
    public function resolveMarket(?int $workspaceId, string $currency): array
    {
        if (($workspaceId ?? 0) > 0 && Database::tableExists('workspace_billing_market_assignments')) {
            $assignment = Database::queryOne(
                "SELECT country_code, region_code
                 FROM workspace_billing_market_assignments
                 WHERE workspace_id = ?",
                [$workspaceId]
            );
            if ($assignment) {
                $countryCode = $this->normalizeCountryCode((string) ($assignment['country_code'] ?? ''), true);
                $regionCode = $this->normalizeRegionCode((string) ($assignment['region_code'] ?? ''), true);
                if ($countryCode !== '' || $regionCode !== '') {
                    return [
                        'country_code' => $countryCode,
                        'region_code' => $regionCode,
                        'label' => $countryCode !== '' ? $countryCode : str_replace('_', ' ', $regionCode),
                        'source' => 'workspace_assignment',
                    ];
                }
            }
        }

        $currency = strtoupper(trim($currency));
        if (isset(self::CURRENCY_MARKETS[$currency])) {
            return self::CURRENCY_MARKETS[$currency] + ['source' => 'currency_inference'];
        }

        return [
            'country_code' => '',
            'region_code' => '',
            'label' => $currency !== '' ? $currency . ' market' : 'Unassigned market',
            'source' => 'unassigned',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listRules(): array
    {
        if (!Database::tableExists('billing_payment_market_rules')) {
            return [];
        }

        return Database::query(
            "SELECT rules.*, users.email AS updated_by_email
             FROM billing_payment_market_rules rules
             LEFT JOIN users ON users.id = rules.updated_by
             ORDER BY FIELD(rules.scope_type, 'country', 'region'), rules.scope_name, rules.scope_code"
        );
    }

    /** @return list<array<string,mixed>> */
    public function listAssignments(): array
    {
        if (!Database::tableExists('workspace_billing_market_assignments')) {
            return [];
        }

        return Database::query(
            "SELECT assignments.*, workspaces.name AS workspace_name, workspaces.slug AS workspace_slug,
                    users.email AS updated_by_email
             FROM workspace_billing_market_assignments assignments
             JOIN workspaces ON workspaces.id = assignments.workspace_id
             LEFT JOIN users ON users.id = assignments.updated_by
             ORDER BY workspaces.name, workspaces.id"
        );
    }

    /** @return list<array<string,mixed>> */
    public function listWorkspaces(): array
    {
        return Database::query(
            "SELECT id, name, slug, status
             FROM workspaces
             WHERE status <> 'archived'
             ORDER BY name, id"
        );
    }

    /** @return array<string,mixed> */
    public function saveRule(array $input, ?int $updatedBy = null): array
    {
        if (!Database::tableExists('billing_payment_market_rules')) {
            throw new \RuntimeException('Regional payment controls are not installed. Run database migrations first.');
        }

        $scopeType = strtolower(trim((string) ($input['scope_type'] ?? '')));
        if (!in_array($scopeType, [self::SCOPE_COUNTRY, self::SCOPE_REGION], true)) {
            throw new \RuntimeException('Choose either country or region scope.');
        }
        $scopeCode = $scopeType === self::SCOPE_COUNTRY
            ? $this->normalizeCountryCode((string) ($input['scope_code'] ?? ''))
            : $this->normalizeRegionCode((string) ($input['scope_code'] ?? ''));
        $scopeName = trim((string) ($input['scope_name'] ?? ''));
        if ($scopeName === '') {
            throw new \RuntimeException('Country or region name is required.');
        }

        Database::execute(
            "INSERT INTO billing_payment_market_rules
             (scope_type, scope_code, scope_name, payment_card_enabled, payment_mpesa_enabled,
              payment_bank_transfer_enabled, is_active, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                scope_name = VALUES(scope_name),
                payment_card_enabled = VALUES(payment_card_enabled),
                payment_mpesa_enabled = VALUES(payment_mpesa_enabled),
                payment_bank_transfer_enabled = VALUES(payment_bank_transfer_enabled),
                is_active = VALUES(is_active),
                updated_by = VALUES(updated_by),
                updated_at = NOW()",
            [
                $scopeType,
                $scopeCode,
                substr($scopeName, 0, 120),
                !empty($input['payment_card_enabled']) ? 1 : 0,
                !empty($input['payment_mpesa_enabled']) ? 1 : 0,
                !empty($input['payment_bank_transfer_enabled']) ? 1 : 0,
                !empty($input['is_active']) ? 1 : 0,
                $updatedBy ?: null,
            ]
        );

        return (array) Database::queryOne(
            "SELECT * FROM billing_payment_market_rules WHERE scope_type = ? AND scope_code = ?",
            [$scopeType, $scopeCode]
        );
    }

    /** @return array<string,mixed> */
    public function saveWorkspaceAssignment(int $workspaceId, string $countryCode, string $regionCode, ?int $updatedBy = null): array
    {
        if (!Database::tableExists('workspace_billing_market_assignments')) {
            throw new \RuntimeException('Workspace market assignments are not installed. Run database migrations first.');
        }
        if ($workspaceId <= 0 || !Database::queryOne('SELECT id FROM workspaces WHERE id = ?', [$workspaceId])) {
            throw new \RuntimeException('Choose a valid workspace.');
        }

        $countryCode = $this->normalizeCountryCode($countryCode, true);
        $regionCode = $this->normalizeRegionCode($regionCode, true);
        if ($countryCode === '' && $regionCode === '') {
            throw new \RuntimeException('Assign a country, a region, or both.');
        }

        Database::execute(
            "INSERT INTO workspace_billing_market_assignments
             (workspace_id, country_code, region_code, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                country_code = VALUES(country_code),
                region_code = VALUES(region_code),
                updated_by = VALUES(updated_by),
                updated_at = NOW()",
            [$workspaceId, $countryCode !== '' ? $countryCode : null, $regionCode !== '' ? $regionCode : null, $updatedBy ?: null]
        );

        return (array) Database::queryOne(
            'SELECT * FROM workspace_billing_market_assignments WHERE workspace_id = ?',
            [$workspaceId]
        );
    }

    public function clearWorkspaceAssignment(int $workspaceId): bool
    {
        if (!Database::tableExists('workspace_billing_market_assignments')) {
            return false;
        }
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Choose a valid workspace assignment to clear.');
        }

        return Database::execute(
            'DELETE FROM workspace_billing_market_assignments WHERE workspace_id = ?',
            [$workspaceId]
        ) > 0;
    }

    /** @return array<string,mixed>|null */
    private function findApplicableRule(string $countryCode, string $regionCode): ?array
    {
        if (!Database::tableExists('billing_payment_market_rules')) {
            return null;
        }
        if ($countryCode !== '') {
            $rule = Database::queryOne(
                "SELECT * FROM billing_payment_market_rules
                 WHERE scope_type = 'country' AND scope_code = ? AND is_active = 1",
                [$countryCode]
            );
            if ($rule) {
                return $rule;
            }
        }
        if ($regionCode !== '') {
            return Database::queryOne(
                "SELECT * FROM billing_payment_market_rules
                 WHERE scope_type = 'region' AND scope_code = ? AND is_active = 1",
                [$regionCode]
            );
        }

        return null;
    }

    /** @return array<string,string> */
    private function modeColumns(): array
    {
        return [
            WorkspaceBillingPaymentModeService::MODE_CARD => 'payment_card_enabled',
            WorkspaceBillingPaymentModeService::MODE_MPESA => 'payment_mpesa_enabled',
            WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER => 'payment_bank_transfer_enabled',
        ];
    }

    /** @param array<string,mixed> $availability @return array<string,bool> */
    private function normalizeAvailability(array $availability): array
    {
        $normalized = [];
        foreach ($this->modeColumns() as $mode => $column) {
            $normalized[$mode] = !array_key_exists($mode, $availability) || !empty($availability[$mode]);
        }
        return $normalized;
    }

    private function normalizeCountryCode(string $code, bool $allowEmpty = false): string
    {
        $code = strtoupper(trim($code));
        if ($allowEmpty && $code === '') {
            return '';
        }
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new \RuntimeException('Country codes must use two ISO letters, for example KE or NG.');
        }
        return $code;
    }

    private function normalizeRegionCode(string $code, bool $allowEmpty = false): string
    {
        $code = strtoupper(trim(preg_replace('/[\s-]+/', '_', $code) ?? ''));
        if ($allowEmpty && $code === '') {
            return '';
        }
        if (preg_match('/^[A-Z0-9_]{2,32}$/', $code) !== 1) {
            throw new \RuntimeException('Region codes must use letters, numbers, or underscores.');
        }
        return $code;
    }

    private function modeLabel(string $mode): string
    {
        return $mode === WorkspaceBillingPaymentModeService::MODE_MPESA
            ? 'M-Pesa'
            : ($mode === WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER ? 'Bank transfer' : 'Card payments');
    }
}
