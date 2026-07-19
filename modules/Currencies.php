<?php
/**
 * Currency Management Module
 * 
 * Handles currency configuration and formatting
 */

namespace CRM\Modules;

use CRM\Database;

class Currencies
{
    /**
     * Get all active currencies
     */
    public function getActiveCurrencies(): array
    {
        return Database::query(
            "SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, name ASC"
        );
    }
    
    /**
     * Get all currencies (including inactive)
     */
    public function getAllCurrencies(): array
    {
        return Database::query(
            "SELECT * FROM currencies ORDER BY is_default DESC, name ASC"
        );
    }
    
    /**
     * Get currency by code
     */
    public function getByCode(string $code): ?array
    {
        return Database::queryOne(
            "SELECT * FROM currencies WHERE code = ?",
            [$code]
        );
    }
    
    /**
     * Get default currency
     */
    public function getDefault(): ?array
    {
        return Database::queryOne(
            "SELECT * FROM currencies WHERE is_default = 1 AND is_active = 1 LIMIT 1"
        );
    }
    
    /**
     * Create a new currency
     */
    public function create(array $data): int
    {
        // If this is set as default, unset other defaults
        if (!empty($data['is_default'])) {
            Database::execute("UPDATE currencies SET is_default = 0 WHERE is_default = 1");
        }
        
        Database::execute(
            "INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, thousands_separator, decimal_separator, is_active, is_default) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                strtoupper($data['code']),
                $data['name'],
                $data['symbol'],
                $data['symbol_position'] ?? 'before',
                (int) ($data['decimal_places'] ?? 2),
                $data['thousands_separator'] ?? ',',
                $data['decimal_separator'] ?? '.',
                !empty($data['is_active']) ? 1 : 0,
                !empty($data['is_default']) ? 1 : 0
            ]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Update currency
     */
    public function update(int $id, array $data): bool
    {
        // If this is set as default, unset other defaults
        if (!empty($data['is_default'])) {
            Database::execute("UPDATE currencies SET is_default = 0 WHERE is_default = 1 AND id != ?", [$id]);
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        
        if (isset($data['symbol'])) {
            $updates[] = "symbol = ?";
            $params[] = $data['symbol'];
        }
        
        if (isset($data['symbol_position'])) {
            $updates[] = "symbol_position = ?";
            $params[] = $data['symbol_position'];
        }
        
        if (isset($data['decimal_places'])) {
            $updates[] = "decimal_places = ?";
            $params[] = (int) $data['decimal_places'];
        }
        
        if (isset($data['thousands_separator'])) {
            $updates[] = "thousands_separator = ?";
            $params[] = $data['thousands_separator'];
        }
        
        if (isset($data['decimal_separator'])) {
            $updates[] = "decimal_separator = ?";
            $params[] = $data['decimal_separator'];
        }
        
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = !empty($data['is_active']) ? 1 : 0;
        }
        
        if (isset($data['is_default'])) {
            $updates[] = "is_default = ?";
            $params[] = !empty($data['is_default']) ? 1 : 0;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        Database::execute(
            "UPDATE currencies SET " . implode(", ", $updates) . " WHERE id = ?",
            $params
        );
        
        return true;
    }
    
    /**
     * Delete currency
     */
    public function delete(int $id): bool
    {
        // Don't allow deleting default currency
        $currency = Database::queryOne("SELECT is_default FROM currencies WHERE id = ?", [$id]);
        if ($currency && $currency['is_default']) {
            throw new \Exception("Cannot delete the default currency");
        }
        
        Database::execute("DELETE FROM currencies WHERE id = ?", [$id]);
        return true;
    }
    
    /**
     * Format amount with currency
     */
    public function formatAmount(float $amount, ?string $currencyCode = null): string
    {
        if ($currencyCode === null) {
            $currency = $this->getDefault();
        } else {
            $currency = $this->getByCode($currencyCode);
        }
        
        if (!$currency) {
            // Fallback to USD formatting
            return '$' . number_format($amount, 2);
        }
        
        $formatted = number_format(
            $amount,
            $currency['decimal_places'],
            $currency['decimal_separator'],
            $currency['thousands_separator']
        );
        
        if ($currency['symbol_position'] === 'before') {
            return $currency['symbol'] . $formatted;
        } else {
            return $formatted . ' ' . $currency['symbol'];
        }
    }
    
    /**
     * Get currency for display in forms
     */
    public function getCurrencyOptions(): array
    {
        $currencies = $this->getActiveCurrencies();
        $options = [];
        
        foreach ($currencies as $currency) {
            $options[$currency['code']] = $currency['name'] . ' (' . $currency['symbol'] . ')';
        }
        
        return $options;
    }
}
