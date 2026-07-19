<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\InvoiceSettings;

class InvoiceNumberGenerator
{
    public function generate(string $documentType, ?int $updatedBy = null): string
    {
        $documentType = in_array($documentType, ['quote', 'proforma', 'invoice'], true) ? $documentType : 'invoice';
        $settingsModule = new InvoiceSettings();

        Database::beginTransaction();
        try {
            $settings = $settingsModule->get();
            [$prefixKey, $nextKey] = match ($documentType) {
                'quote' => ['quote_prefix', 'quote_next_number'],
                'proforma' => ['proforma_prefix', 'proforma_next_number'],
                default => ['invoice_prefix', 'invoice_next_number'],
            };

            $prefix = (string) ($settings[$prefixKey] ?? '');
            $next = max(1, (int) ($settings[$nextKey] ?? 1));
            do {
                $candidate = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                $exists = Database::queryOne("SELECT id FROM invoices WHERE invoice_number = ?", [$candidate]);
                $next++;
            } while ($exists);

            $settings[$nextKey] = $next;
            $settingsModule->save($settings, $updatedBy);

            Database::commit();
            return $candidate;
        } catch (\Throwable $e) {
            try {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }
}
