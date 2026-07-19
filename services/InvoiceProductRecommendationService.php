<?php

namespace CRM\Services;

use CRM\Database;

class InvoiceProductRecommendationService
{
    private static array $tableExistsCache = [];
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function recommend(array $context): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $dealId = !empty($context['deal_id']) ? (int) $context['deal_id'] : null;
        $contactId = !empty($context['contact_id']) ? (int) $context['contact_id'] : null;
        $companyId = !empty($context['company_id']) ? (int) $context['company_id'] : null;
        $invoiceId = !empty($context['invoice_id']) ? (int) $context['invoice_id'] : null;

        $products = Database::query(
            "SELECT * FROM products
             WHERE workspace_id = ?
               AND is_active = 1
               AND COALESCE(pricing_info, '') <> 'Internal service'
               AND NOT (
                   seed_metadata_json IS NOT NULL
                   AND JSON_UNQUOTE(JSON_EXTRACT(seed_metadata_json, '$.seed_source')) = 'default_workspace_platform_ops'
               )
             ORDER BY display_order ASC, name ASC",
            [$workspaceId]
        );
        if (!$products) {
            return [];
        }

        $deal = $dealId ? $this->getScopedRow('deals', $dealId, $workspaceId) : null;
        $invoice = $invoiceId ? $this->getScopedRow('invoices', $invoiceId, $workspaceId) : null;
        $company = $companyId ? $this->getScopedRow('companies', $companyId, $workspaceId) : null;
        $contact = $contactId ? $this->getScopedRow('contacts', $contactId, $workspaceId) : null;
        $dealLineItems = ($deal && $dealId && $this->tableExists('deal_line_items'))
            ? Database::query("SELECT * FROM deal_line_items WHERE deal_id = ?", [$dealId])
            : [];

        $dealProductIds = [];
        $dealProductKeywords = [];
        foreach ($dealLineItems as $item) {
            if (!empty($item['product_id'])) {
                $dealProductIds[(int) $item['product_id']] = true;
            }
            $dealProductKeywords = array_merge($dealProductKeywords, $this->tokenize((string) ($item['description'] ?? '')));
        }

        $contextKeywords = array_unique(array_merge(
            $this->tokenize((string) ($deal['title'] ?? '')),
            $this->tokenize((string) ($invoice['title'] ?? '')),
            $this->tokenize((string) ($company['name'] ?? '')),
            $this->tokenize((string) ($contact['company'] ?? '')),
            $this->tokenize((string) ($contact['stage'] ?? '')),
            $dealProductKeywords
        ));

        $recommendations = [];
        foreach ($products as $product) {
            $normalized = $this->normalizeProduct($product);
            $source = 'manual_context';
            $score = 0;

            if (!empty($dealProductIds[(int) $normalized['product_id']])) {
                $score += 100;
                $source = 'deal_line_items';
            }

            $productKeywords = array_unique(array_merge(
                $this->tokenize($normalized['name']),
                $this->tokenize($normalized['category']),
                $this->tokenize($normalized['description'])
            ));
            $overlap = count(array_intersect($productKeywords, $contextKeywords));
            if ($overlap > 0) {
                $score += 70;
                if ($source === 'manual_context') {
                    $source = 'contact_company_match';
                }
            }

            if ($company && $normalized['category'] !== '' && stripos((string) ($company['name'] ?? ''), $normalized['category']) !== false) {
                $score += 50;
                if ($source === 'manual_context') {
                    $source = 'company_profile_match';
                }
            }

            if ((float) $normalized['unit_price'] > 0) {
                $score += 10;
            }

            if ($score <= 0) {
                continue;
            }

            $normalized['confidence'] = min(100, $score);
            $normalized['source'] = $source;
            $normalized['reason'] = $this->explainReason($normalized, $context, $source);
            $recommendations[] = $normalized;
        }

        usort($recommendations, static function (array $a, array $b): int {
            if ($a['confidence'] !== $b['confidence']) {
                return $b['confidence'] <=> $a['confidence'];
            }
            if ($a['display_order'] !== $b['display_order']) {
                return $a['display_order'] <=> $b['display_order'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return array_slice($recommendations, 0, 8);
    }

    public function normalizeProduct(array $product): array
    {
        return [
            'product_id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'unit_price' => isset($product['unit_price']) ? (float) $product['unit_price'] : 0.0,
            'description' => (string) ($product['description'] ?? ''),
            'category' => (string) ($product['category'] ?? ''),
            'pricing_info' => (string) ($product['pricing_info'] ?? ''),
            'display_order' => (int) ($product['display_order'] ?? 0),
            'recommended_quantity' => 1,
            'reason' => '',
            'confidence' => 0,
            'source' => 'manual_context',
        ];
    }

    public function explainReason(array $product, array $context, string $source): string
    {
        return match ($source) {
            'deal_line_items' => 'Previously used on the linked deal estimate.',
            'contact_company_match' => 'Matches the current deal, invoice title, or linked contact/company context.',
            'company_profile_match' => 'Relevant to the linked company context and active catalog.',
            default => ((float) ($product['unit_price'] ?? 0)) > 0
                ? 'Active catalog product with a configured unit price.'
                : 'Active catalog product that may fit this invoice, but pricing needs review.',
        };
    }

    private function tokenize(string $value): array
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return [];
        }
        $parts = preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, static fn(string $part): bool => strlen($part) >= 3));
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, self::$tableExistsCache)) {
            return self::$tableExistsCache[$tableName];
        }

        try {
            $row = Database::queryOne(
                'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$tableName]
            );
            self::$tableExistsCache[$tableName] = ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$tableExistsCache[$tableName] = false;
        }

        return self::$tableExistsCache[$tableName];
    }

    private function getScopedRow(string $table, int $id, int $workspaceId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        return Database::queryOne(
            "SELECT * FROM {$table} WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $id]
        );
    }
}
