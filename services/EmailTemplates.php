<?php
/**
 * Email Templates Service
 * 
 * Manages email templates with database storage
 */

namespace CRM\Services;

use CRM\Database;

class EmailTemplates
{
    private WorkspaceScopeService $workspaceScope;
    private EmailTemplatePresentationService $presentation;
    private EmailLinkService $links;

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?EmailTemplatePresentationService $presentation = null,
        ?EmailLinkService $links = null
    )
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->presentation = $presentation ?? new EmailTemplatePresentationService();
        $this->links = $links ?? new EmailLinkService();
    }

    /**
     * Render template with variables
     */
    public function render(string $templateSlug, array $variables = []): array
    {
        $template = $this->getBySlug($templateSlug);
        
        if (!$template) {
            throw new \Exception("Template not found: $templateSlug");
        }

        return $this->renderTemplateRecord($template, $variables, $templateSlug);
    }

    /**
     * Render a sendable template for the current workspace user.
     */
    public function renderSendableTemplate(string $templateSlug, int $userId, array $variables = []): array
    {
        $template = $this->getSendableTemplateBySlug($templateSlug, $userId);

        if (!$template) {
            throw new \Exception("Template not found: $templateSlug");
        }

        return $this->renderTemplateRecord($template, $variables, $templateSlug);
    }
    
    /**
     * Replace variables in template string
     */
    private function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $quotedKey = preg_quote((string) $key, '/');
            $template = preg_replace('/\{\{\s*' . $quotedKey . '\s*\}\}/', $escapedValue, $template) ?? $template;
            $template = str_replace('{' . $key . '}', $escapedValue, $template);
        }
        
        // Remove any remaining unreplaced variables
        $template = preg_replace('/\{\{\s*[^{}]+\s*\}\}|\{[^{}]+\}/', '', $template);
        
        return $template;
    }
    
    /**
     * Get template by slug
     */
    public function getBySlug(string $slug): ?array
    {
        return Database::queryOne(
            "SELECT * FROM email_templates
             WHERE slug = ?
               AND is_active = 1
               AND {$this->accessibleTemplateSql()}
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END
             LIMIT 1",
            [$slug, ...$this->accessibleTemplateParams(), $this->workspaceId()]
        );
    }
    
    /**
     * Get template by ID
     */
    public function getById(int $id): ?array
    {
        return Database::queryOne(
            "SELECT * FROM email_templates WHERE id = ? AND {$this->accessibleTemplateSql()}",
            [$id, ...$this->accessibleTemplateParams()]
        );
    }

    /**
     * Get template by ID for the owning user.
     */
    public function getOwnedTemplateById(int $id, int $userId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM email_templates
             WHERE id = ? AND created_by = ? AND is_library = 0 AND workspace_id = ?",
            [$id, $userId, $this->workspaceId()]
        );
    }

    /**
     * Get a template by slug if it is a library template or owned by the user.
     */
    public function getAccessibleTemplateBySlug(string $slug, int $userId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM email_templates
             WHERE slug = ?
               AND workspace_id = ?
               AND (created_by = ? OR category = 'workspace_starter')
               AND is_library = 0
               AND {$this->approvedAiTemplateSql()}
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END
             LIMIT 1",
            [$slug, $this->workspaceId(), $userId, $this->workspaceId()]
        );
    }

    /**
     * Get a template by slug when it is sendable by the current user.
     */
    public function getSendableTemplateBySlug(string $slug, int $userId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM email_templates
             WHERE slug = ?
               AND (created_by = ? OR category = 'workspace_starter')
               AND is_library = 0
               AND is_active = 1
               AND workspace_id = ?
               AND {$this->approvedAiTemplateSql()}
             LIMIT 1",
            [$slug, $userId, $this->workspaceId()]
        );
    }

    /**
     * Get a template by ID when it is sendable by the current user.
     */
    public function getSendableTemplateById(int $id, int $userId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM email_templates
             WHERE id = ?
               AND (created_by = ? OR category = 'workspace_starter')
               AND is_library = 0
               AND is_active = 1
               AND workspace_id = ?
               AND {$this->approvedAiTemplateSql()}
             LIMIT 1",
            [$id, $userId, $this->workspaceId()]
        );
    }
    
    /**
     * List all templates
     */
    public function list(?string $category = null): array
    {
        $params = $this->accessibleTemplateParams();
        if ($category) {
            $params[] = $category;
            return Database::query(
                "SELECT * FROM email_templates WHERE {$this->accessibleTemplateSql()} AND category = ? ORDER BY name ASC",
                $params
            );
        }
        
        return Database::query(
            "SELECT * FROM email_templates WHERE {$this->accessibleTemplateSql()} ORDER BY category, name ASC",
            $params
        );
    }

    /**
     * List non-library templates owned by a user.
     */
    public function getUserTemplates(int $userId, ?string $category = null): array
    {
        $params = [$userId, $this->workspaceId()];
        $sql = "SELECT * FROM email_templates
                WHERE (created_by = ? OR category = 'workspace_starter')
                  AND workspace_id = ?
                  AND is_library = 0
                  AND {$this->approvedAiTemplateSql()}";

        if ($category !== null && $category !== '') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY is_active DESC, name ASC";

        return Database::query($sql, $params);
    }

    /**
     * List templates that are valid for outbound sending.
     */
    public function getSendableTemplatesForUser(int $userId, ?string $category = null): array
    {
        $params = [$userId, $this->workspaceId()];
        $sql = "SELECT * FROM email_templates
                WHERE (created_by = ? OR category = 'workspace_starter')
                  AND workspace_id = ?
                  AND is_library = 0
                  AND is_active = 1
                  AND {$this->approvedAiTemplateSql()}";

        if ($category !== null && $category !== '') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY is_ai_generated DESC, name ASC";

        return Database::query($sql, $params);
    }
    
    /**
     * Create template
     */
    public function create(array $data): int
    {
        $fields = ['name', 'slug', 'subject', 'body_html', 'body_text', 'category', 'variables', 'is_active', 'created_by', 'is_library', 'description', 'tags', 'industry', 'purpose', 'is_featured', 'author', 'version', 'smart_template_set_id', 'is_ai_generated', 'template_key', 'match_metadata_json'];
        $values = [];
        $placeholders = [];
        $isLibrary = !empty($data['is_library']);

        $placeholders[] = 'workspace_id';
        $values[] = $isLibrary ? null : $this->workspaceId();
        
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $placeholders[] = $field;
                if ($field === 'variables' || $field === 'tags' || $field === 'match_metadata_json') {
                    $values[] = json_encode($data[$field]);
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($placeholders)) {
            throw new \Exception("No valid fields provided");
        }
        
        $sql = "INSERT INTO email_templates (" . implode(', ', $placeholders) . ") VALUES (" . implode(', ', array_fill(0, count($placeholders), '?')) . ")";
        
        Database::execute($sql, $values);
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Update template
     */
    public function update(int $id, array $data): bool
    {
        $updates = [];
        $params = [];
        
        $allowedFields = ['name', 'slug', 'subject', 'body_html', 'body_text', 'category', 'variables', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'variables') {
                    $updates[] = "$field = ?";
                    $params[] = json_encode($data[$field]);
                } else {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        Database::execute(
            "UPDATE email_templates SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ? AND is_library = 0",
            array_merge($params, [$this->workspaceId()])
        );
        
        return true;
    }
    
    /**
     * Delete template
     */
    public function delete(int $id): bool
    {
        Database::execute(
            "DELETE FROM email_templates WHERE id = ? AND workspace_id = ? AND is_library = 0",
            [$id, $this->workspaceId()]
        );
        
        return true;
    }
    
    /**
     * Get available variables for a template
     */
    public function getVariables(int $templateId): array
    {
        $template = $this->getById($templateId);
        
        if (!$template) {
            return [];
        }
        
        $variables = json_decode($template['variables'] ?? '[]', true);
        
        // Add common variables
        $commonVars = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'company',
            'contact_id'
        ];
        
        return array_unique(array_merge($commonVars, $variables));
    }
    
    /**
     * Get library templates with filtering
     */
    public function getLibraryTemplates(array $filters = []): array
    {
        if (empty($filters['include_retired_library'])) {
            return [];
        }

        $sql = "SELECT * FROM email_templates WHERE is_library = TRUE AND (workspace_id IS NULL OR workspace_id = ?)";
        $params = [$this->workspaceId()];
        
        // Category filter
        if (!empty($filters['category'])) {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }
        
        // Industry filter
        if (!empty($filters['industry'])) {
            $sql .= " AND industry = ?";
            $params[] = $filters['industry'];
        }
        
        // Purpose filter
        if (!empty($filters['purpose'])) {
            $sql .= " AND purpose = ?";
            $params[] = $filters['purpose'];
        }
        
        // Featured filter
        if (isset($filters['is_featured']) && $filters['is_featured'] === true) {
            $sql .= " AND is_featured = TRUE";
        }
        
        // Active filter
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'] ? 1 : 0;
        }
        
        // Tags filter (multiple tags)
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $tagConditions = [];
            foreach ($filters['tags'] as $tag) {
                $tagConditions[] = "JSON_CONTAINS(tags, ?)";
                $params[] = json_encode($tag);
            }
            if (!empty($tagConditions)) {
                $sql .= " AND (" . implode(' OR ', $tagConditions) . ")";
            }
        }
        
        // Search query
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $sql .= " AND (name LIKE ? OR subject LIKE ? OR description LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Sort
        $sortBy = $filters['sort_by'] ?? 'usage_count';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        $allowedSorts = ['usage_count', 'name', 'created_at', 'is_featured'];
        if (in_array($sortBy, $allowedSorts)) {
            if ($sortBy === 'is_featured') {
                $sql .= " ORDER BY is_featured DESC, usage_count DESC, name ASC";
            } else {
                $sql .= " ORDER BY $sortBy $sortOrder, name ASC";
            }
        } else {
            $sql .= " ORDER BY usage_count DESC, name ASC";
        }
        
        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        $templates = Database::query($sql, $params);
        
        // Decode JSON fields
        foreach ($templates as &$template) {
            $template['variables'] = json_decode($template['variables'] ?? '[]', true);
            $template['tags'] = json_decode($template['tags'] ?? '[]', true);
        }
        
        return $templates;
    }
    
    /**
     * Install library template as user template
     */
    public function installLibraryTemplate(int $templateId, int $userId, array $customizations = []): int
    {
        throw new \Exception("Generic template library installation has been retired. Use AI drafting until learned templates are ready.");

        $libraryTemplate = $this->getById($templateId);
        
        if (!$libraryTemplate) {
            throw new \Exception("Template not found");
        }
        
        if (!$libraryTemplate['is_library']) {
            throw new \Exception("Template is not a library template");
        }
        
        // Generate unique slug
        $baseSlug = $customizations['slug'] ?? $libraryTemplate['slug'];
        $slug = $baseSlug;
        $counter = 1;
        
        while ($this->slugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        
        // Create user copy
        $data = [
            'name' => $customizations['name'] ?? $libraryTemplate['name'],
            'slug' => $slug,
            'subject' => $customizations['subject'] ?? $libraryTemplate['subject'],
            'body_html' => $customizations['body_html'] ?? $libraryTemplate['body_html'],
            'body_text' => $customizations['body_text'] ?? $libraryTemplate['body_text'],
            'category' => $customizations['category'] ?? $libraryTemplate['category'],
            'variables' => json_decode($libraryTemplate['variables'] ?? '[]', true),
            'is_active' => $customizations['is_active'] ?? true,
            'created_by' => $userId,
            'is_library' => false
        ];
        
        $newTemplateId = $this->create($data);
        
        // Increment usage count on library template
        $this->incrementUsageCount($templateId);
        
        return $newTemplateId;
    }
    
    /**
     * Check if slug exists
     */
    private function slugExists(string $slug): bool
    {
        $existing = Database::queryOne(
            "SELECT id FROM email_templates
             WHERE slug = ?
               AND (workspace_id = ? OR (is_library = 1 AND workspace_id IS NULL))
             LIMIT 1",
            [$slug, $this->workspaceId()]
        );
        return $existing !== null;
    }
    
    /**
     * Advanced search templates
     */
    public function searchTemplates(string $query, array $filters = []): array
    {
        $sql = "SELECT * FROM email_templates WHERE {$this->accessibleTemplateSql()}";
        $params = $this->accessibleTemplateParams();
        
        // Full-text search across multiple fields
        if (!empty($query)) {
            $searchTerm = '%' . $query . '%';
            $sql .= " AND (
                name LIKE ? OR 
                subject LIKE ? OR 
                description LIKE ? OR
                body_html LIKE ?
            )";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Library vs user templates
        if (isset($filters['is_library'])) {
            $sql .= " AND is_library = ?";
            $params[] = $filters['is_library'] ? 1 : 0;
        }
        
        // Category filter
        if (!empty($filters['category'])) {
            if (is_array($filters['category'])) {
                $placeholders = implode(',', array_fill(0, count($filters['category']), '?'));
                $sql .= " AND category IN ($placeholders)";
                $params = array_merge($params, $filters['category']);
            } else {
                $sql .= " AND category = ?";
                $params[] = $filters['category'];
            }
        }
        
        // Industry filter
        if (!empty($filters['industry'])) {
            $sql .= " AND industry = ?";
            $params[] = $filters['industry'];
        }
        
        // Purpose filter
        if (!empty($filters['purpose'])) {
            $sql .= " AND purpose = ?";
            $params[] = $filters['purpose'];
        }
        
        // Tags filter
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $tagConditions = [];
            foreach ($filters['tags'] as $tag) {
                $tagConditions[] = "JSON_CONTAINS(tags, ?)";
                $params[] = json_encode($tag);
            }
            if (!empty($tagConditions)) {
                $sql .= " AND (" . implode(' OR ', $tagConditions) . ")";
            }
        }
        
        // Active status
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'] ? 1 : 0;
        }
        
        // Sort
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'ASC');
        $sql .= " ORDER BY $sortBy $sortOrder";
        
        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        $templates = Database::query($sql, $params);
        
        // Decode JSON fields
        foreach ($templates as &$template) {
            $template['variables'] = json_decode($template['variables'] ?? '[]', true);
            $template['tags'] = json_decode($template['tags'] ?? '[]', true);
        }
        
        return $templates;
    }
    
    /**
     * Get template statistics
     */
    public function getTemplateStats(int $templateId): array
    {
        $template = $this->getById($templateId);
        
        if (!$template) {
            return [];
        }
        
        return [
            'template_id' => $templateId,
            'name' => $template['name'],
            'usage_count' => (int)($template['usage_count'] ?? 0),
            'is_library' => (bool)($template['is_library'] ?? false),
            'is_featured' => (bool)($template['is_featured'] ?? false),
            'created_at' => $template['created_at'] ?? null,
            'updated_at' => $template['updated_at'] ?? null
        ];
    }
    
    /**
     * Increment usage count
     */
    public function incrementUsageCount(int $templateId): bool
    {
        Database::execute(
            "UPDATE email_templates SET usage_count = usage_count + 1 WHERE id = ? AND {$this->accessibleTemplateSql()}",
            [$templateId, ...$this->accessibleTemplateParams()]
        );
        
        return true;
    }
    
    /**
     * Get all categories
     */
    public function getCategories(): array
    {
        $categories = Database::query(
            "SELECT DISTINCT category FROM email_templates WHERE {$this->accessibleTemplateSql()} AND category IS NOT NULL ORDER BY category ASC",
            $this->accessibleTemplateParams()
        );
        
        return array_column($categories, 'category');
    }
    
    /**
     * Get all tags
     */
    public function getTags(): array
    {
        $templates = Database::query(
            "SELECT tags FROM email_templates WHERE {$this->accessibleTemplateSql()} AND tags IS NOT NULL AND tags != '[]'",
            $this->accessibleTemplateParams()
        );
        
        $allTags = [];
        foreach ($templates as $template) {
            $tags = json_decode($template['tags'] ?? '[]', true);
            if (is_array($tags)) {
                $allTags = array_merge($allTags, $tags);
            }
        }

        return array_unique($allTags);
    }

    public function renderTemplateRecord(array $template, array $variables = [], string $templateLabel = 'template', bool $requireActive = true): array
    {
        if ($requireActive && empty($template['is_active'])) {
            throw new \Exception("Template is not active: $templateLabel");
        }

        $subject = $this->replaceVariables($template['subject'], $variables);
        $bodyHtml = $this->replaceVariables($template['body_html'], $variables);
        $bodyText = $template['body_text']
            ? $this->replaceVariables($template['body_text'], $variables)
            : strip_tags($bodyHtml);
        $bodyHtml = $this->links->normalizeHtmlLinks($bodyHtml);
        $bodyText = $this->links->normalizePlainTextLinks($bodyText);
        $bodyHtml = $this->presentation->wrapGeneratedTemplate($template, $subject, $bodyHtml, $bodyText);

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText
        ];
    }
    
    /**
     * Get all industries
     */
    public function getIndustries(): array
    {
        $industries = Database::query(
            "SELECT DISTINCT industry FROM email_templates WHERE {$this->accessibleTemplateSql()} AND industry IS NOT NULL ORDER BY industry ASC",
            $this->accessibleTemplateParams()
        );
        
        return array_column($industries, 'industry');
    }
    
    /**
     * Get all purposes
     */
    public function getPurposes(): array
    {
        $purposes = Database::query(
            "SELECT DISTINCT purpose FROM email_templates WHERE {$this->accessibleTemplateSql()} AND purpose IS NOT NULL ORDER BY purpose ASC",
            $this->accessibleTemplateParams()
        );
        
        return array_column($purposes, 'purpose');
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function accessibleTemplateSql(): string
    {
        return '(workspace_id = ? AND COALESCE(is_library, 0) = 0 AND ' . $this->approvedAiTemplateSql() . ')';
    }

    private function approvedAiTemplateSql(): string
    {
        return "(COALESCE(email_templates.is_ai_generated, 0) = 0
                OR email_templates.smart_template_set_id IS NULL
                OR EXISTS (
                    SELECT 1
                    FROM smart_template_sets sts
                    WHERE sts.id = email_templates.smart_template_set_id
                      AND sts.status = 'active'
                ))";
    }

    /**
     * @return array<int, int>
     */
    private function accessibleTemplateParams(): array
    {
        return [$this->workspaceId()];
    }
}
