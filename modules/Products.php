<?php
/**
 * Products Module
 * 
 * Manages products and services
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Concurrency;
use CRM\Services\WorkspaceScopeService;

class Products
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    private function normalizeFeatures($features): ?string
    {
        if (!is_array($features)) {
            return null;
        }

        $normalized = [];
        foreach ($features as $feature) {
            $feature = trim((string) $feature);
            if ($feature !== '') {
                $normalized[] = $feature;
            }
        }

        return $normalized !== [] ? json_encode(array_values($normalized)) : null;
    }

    private function normalizeOptionalUnitPrice($value): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('Unit price must be a valid number.');
        }

        $price = (float) $raw;
        if (!is_finite($price) || $price < 0) {
            throw new \InvalidArgumentException('Unit price cannot be negative.');
        }

        return $price;
    }

    private function normalizeDisplayOrder($value): int
    {
        $raw = trim((string) ($value ?? '0'));
        $order = filter_var($raw, FILTER_VALIDATE_INT);
        if ($order === false || $order < 0) {
            throw new \InvalidArgumentException('Display order must be a non-negative whole number.');
        }

        return $order;
    }

    private function normalizeOptionalMediaUrl($value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }

        if (str_contains($url, "\r") || str_contains($url, "\n") || str_contains($url, "\0") || str_starts_with($url, '//')) {
            throw new \InvalidArgumentException('Product media URL is invalid.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null && !in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Product media URL must use HTTP or HTTPS.');
        }

        return $url;
    }

    /**
     * List all active products
     */
    public function list(array $filters = []): array
    {
        $workspace = $this->workspaceScope->workspaceClause();
        $where = [$workspace['sql'], "is_active = TRUE"];
        $params = $workspace['params'];
        
        if (!empty($filters['category'])) {
            $where[] = "category = ?";
            $params[] = $filters['category'];
        }
        
        return Database::query(
            "SELECT * FROM products 
             WHERE " . implode(' AND ', $where) . "
             ORDER BY display_order ASC, name ASC",
            $params
        );
    }
    
    /**
     * Get product by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceScope->workspaceClause();
        return Database::queryOne(
            "SELECT * FROM products WHERE {$workspace['sql']} AND id = ? AND is_active = TRUE",
            array_merge($workspace['params'], [$id])
        );
    }
    
    /**
     * Create new product
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Product name is required.');
        }

        $features = $this->normalizeFeatures($data['features'] ?? null);
        $unitPrice = $this->normalizeOptionalUnitPrice($data['unit_price'] ?? null);
        $productImageUrl = $this->normalizeOptionalMediaUrl($data['product_image_url'] ?? null);
        $productDemoVideoUrl = $this->normalizeOptionalMediaUrl($data['product_demo_video_url'] ?? null);
        $displayOrder = $this->normalizeDisplayOrder($data['display_order'] ?? 0);
        
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, features, pricing_info, target_audience, use_cases, benefits, unit_price, product_image_url, product_demo_video_url, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)",
            [
                $this->workspaceScope->requireActiveWorkspaceId(),
                $name,
                trim((string) ($data['description'] ?? '')) ?: null,
                trim((string) ($data['category'] ?? '')) ?: null,
                $features,
                trim((string) ($data['pricing_info'] ?? '')) ?: null,
                trim((string) ($data['target_audience'] ?? '')) ?: null,
                trim((string) ($data['use_cases'] ?? '')) ?: null,
                trim((string) ($data['benefits'] ?? '')) ?: null,
                $unitPrice,
                $productImageUrl,
                $productDemoVideoUrl,
                $displayOrder
            ]
        );

        $productId = (int) Database::lastInsertId();
        if ($productId <= 0) {
            throw new \RuntimeException('Product could not be created.');
        }

        return $productId;
    }
    
    /**
     * Update existing product
     */
    public function update(int $id, array $data): bool
    {
        $product = $this->getById($id);
        if (!$product) {
            return false;
        }
        
        $name = trim((string) ($data['name'] ?? $product['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Product name is required.');
        }

        $features = array_key_exists('features', $data)
            ? $this->normalizeFeatures($data['features'])
            : $product['features'];
        $unitPrice = array_key_exists('unit_price', $data)
            ? $this->normalizeOptionalUnitPrice($data['unit_price'])
            : $product['unit_price'];
        $productImageUrl = array_key_exists('product_image_url', $data)
            ? $this->normalizeOptionalMediaUrl($data['product_image_url'])
            : ($product['product_image_url'] ?? null);
        $productDemoVideoUrl = array_key_exists('product_demo_video_url', $data)
            ? $this->normalizeOptionalMediaUrl($data['product_demo_video_url'])
            : ($product['product_demo_video_url'] ?? null);
        $displayOrder = array_key_exists('display_order', $data)
            ? $this->normalizeDisplayOrder($data['display_order'])
            : (int) $product['display_order'];
        
        $submittedFields = [
            'name' => $name,
            'description' => array_key_exists('description', $data) ? (trim((string) $data['description']) ?: null) : $product['description'],
            'category' => array_key_exists('category', $data) ? (trim((string) $data['category']) ?: null) : $product['category'],
            'features' => $features,
            'pricing_info' => array_key_exists('pricing_info', $data) ? (trim((string) $data['pricing_info']) ?: null) : $product['pricing_info'],
            'target_audience' => array_key_exists('target_audience', $data) ? (trim((string) $data['target_audience']) ?: null) : $product['target_audience'],
            'use_cases' => array_key_exists('use_cases', $data) ? (trim((string) $data['use_cases']) ?: null) : $product['use_cases'],
            'benefits' => array_key_exists('benefits', $data) ? (trim((string) $data['benefits']) ?: null) : $product['benefits'],
            'unit_price' => $unitPrice,
            'product_image_url' => $productImageUrl,
            'product_demo_video_url' => $productDemoVideoUrl,
            'display_order' => $displayOrder,
        ];
        Concurrency::executeWorkspaceUpdate(
            'products',
            $this->workspaceScope->requireActiveWorkspaceId(),
            $id,
            [
                'name = ?', 'description = ?', 'category = ?', 'features = ?',
                'pricing_info = ?', 'target_audience = ?', 'use_cases = ?',
                'benefits = ?', 'unit_price = ?', 'product_image_url = ?',
                'product_demo_video_url = ?', 'display_order = ?',
            ],
            array_values($submittedFields),
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->getById($id),
            $submittedFields,
            'product'
        );
        
        return true;
    }
    
    /**
     * Delete product (soft delete)
     */
    public function delete(int $id): bool
    {
        $existing = Database::queryOne(
            "SELECT id, is_active FROM products WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$this->workspaceScope->requireActiveWorkspaceId(), $id]
        );

        if (!$existing) {
            return false;
        }

        if ((int) ($existing['is_active'] ?? 0) !== 1) {
            return false;
        }

        $affected = Database::execute(
            "UPDATE products SET is_active = FALSE WHERE workspace_id = ? AND id = ? AND is_active = TRUE",
            [$this->workspaceScope->requireActiveWorkspaceId(), $id]
        );

        return $affected > 0;
    }
    
    /**
     * Get distinct product categories
     */
    public function getCategories(): array
    {
        $results = Database::query(
            "SELECT DISTINCT category FROM products WHERE workspace_id = ? AND is_active = TRUE AND category IS NOT NULL AND category != '' ORDER BY category",
            [$this->workspaceScope->requireActiveWorkspaceId()]
        );
        
        return array_column($results, 'category');
    }
}
