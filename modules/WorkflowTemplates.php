<?php
/**
 * Workflow Templates Module
 * Manages workflow templates
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkflowCapabilityRegistryService;
use CRM\Services\WorkspaceScopeService;

class WorkflowTemplates
{
    private WorkspaceScopeService $workspaceScope;
    private WorkflowCapabilityRegistryService $workflowCapabilities;

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?WorkflowCapabilityRegistryService $workflowCapabilities = null
    )
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->workflowCapabilities = $workflowCapabilities ?? new WorkflowCapabilityRegistryService();
    }

    /**
     * Get all public templates
     */
    public function getPublicTemplates(?string $category = null): array
    {
        $sql = "SELECT * FROM workflow_templates WHERE is_public = 1 AND is_active = 1";
        $params = [];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY usage_count DESC, name ASC";

        return $this->filterExecutableTemplates(Database::query($sql, $params), true);
    }
    
    /**
     * Get template by ID
     */
    public function getTemplate(int $id, ?int $userId = null): ?array
    {
        $params = [$id];
        $sql = "SELECT * FROM workflow_templates WHERE id = ? AND is_active = 1";

        if ($userId !== null) {
            $sql .= " AND (is_public = 1 OR created_by = ?)";
            $params[] = $userId;
        } else {
            $sql .= " AND is_public = 1";
        }

        $template = Database::queryOne($sql, $params);

        if (!$template || !$this->isExecutableTemplate($template, $userId ?? 0)) {
            return null;
        }

        return $this->decodeTemplateRecord($template);
    }

    /**
     * Get active personal smart workflow templates for a user.
     */
    public function getUserSmartTemplates(int $userId): array
    {
        $rows = Database::query(
            "SELECT wt.*
             FROM workflow_templates wt
             JOIN smart_template_sets sts ON sts.id = wt.smart_template_set_id
             WHERE wt.created_by = ?
               AND sts.workspace_id = ?
               AND wt.is_public = 0
               AND wt.is_active = 1
               AND wt.is_ai_generated = 1
             ORDER BY wt.created_at DESC, wt.name ASC",
            [$userId, $this->workspaceScope->requireActiveWorkspaceId()]
        );

        foreach ($rows as &$row) {
            $row['trigger_config'] = json_decode($row['trigger_config'] ?? '[]', true) ?? [];
            $row['conditions'] = json_decode($row['conditions'] ?? '[]', true) ?? [];
            $row['actions'] = json_decode($row['actions'] ?? '[]', true) ?? [];
            $row['variables'] = json_decode($row['variables'] ?? '[]', true) ?? [];
        }

        return $rows;
    }
    
    /**
     * Create workflow from template
     */
    public function createFromTemplate(int $templateId, string $workflowName, array $variables = [], ?int $userId = null): int
    {
        $template = $this->getTemplate($templateId, $userId);
        
        if (!$template) {
            throw new \Exception("Template not found or cannot be used");
        }
        
        // Replace template variables
        $trigger = $this->replaceVariables($template['trigger_config'], $variables);
        $conditions = $this->replaceVariables($template['conditions'], $variables);
        $actions = $this->replaceVariables($template['actions'], $variables);

        if (!$this->isTriggerSupported($trigger) || !$this->areActionsSupported($actions, $userId ?? 0)) {
            throw new \Exception("Template is not executable in this workspace");
        }
        
        // Create workflow
        $automationEngine = new AutomationEngine();
        $workflowId = $automationEngine->createWorkflow(
            $workflowName,
            $trigger,
            $conditions,
            $actions
        );
        
        // Increment usage count
        Database::execute(
            "UPDATE workflow_templates SET usage_count = usage_count + 1 WHERE id = ?",
            [$templateId]
        );
        
        return $workflowId;
    }
    
    /**
     * Replace variables in template data
     */
    private function replaceVariables($data, array $variables): array
    {
        if (!is_array($data)) {
            return $data;
        }
        
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->replaceVariables($value, $variables);
            } elseif (is_string($value)) {
                // Replace {variable} placeholders
                foreach ($variables as $varKey => $varValue) {
                    $value = str_replace('{' . $varKey . '}', $varValue, $value);
                }
                $result[$key] = $value;
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Get template categories
     */
    public function getCategories(): array
    {
        $categories = [];
        foreach ($this->getPublicTemplates() as $template) {
            $category = trim((string) ($template['category'] ?? ''));
            if ($category !== '') {
                $categories[$category] = true;
            }
        }

        $categoryNames = array_keys($categories);
        sort($categoryNames, SORT_NATURAL | SORT_FLAG_CASE);
        return $categoryNames;
    }

    /**
     * @param array<int, array<string, mixed>> $templates
     * @return array<int, array<string, mixed>>
     */
    private function filterExecutableTemplates(array $templates, bool $dedupeByName): array
    {
        $filtered = [];
        $seenNames = [];

        foreach ($templates as $template) {
            if (!$this->isExecutableTemplate($template, 0)) {
                continue;
            }

            $name = trim((string) ($template['name'] ?? ''));
            $dedupeKey = strtolower($name);
            if ($dedupeByName && $dedupeKey !== '' && isset($seenNames[$dedupeKey])) {
                continue;
            }

            if ($dedupeByName && $dedupeKey !== '') {
                $seenNames[$dedupeKey] = true;
            }

            $filtered[] = $template;
        }

        return $filtered;
    }

    private function isExecutableTemplate(array $template, int $userId = 0): bool
    {
        if ((int) ($template['is_active'] ?? 0) !== 1) {
            return false;
        }

        $trigger = $this->decodeJsonArray($template['trigger_config'] ?? []);
        if (!$this->isTriggerSupported($trigger)) {
            return false;
        }

        $actions = $this->decodeJsonArray($template['actions'] ?? []);
        return $this->areActionsSupported($actions, $userId);
    }

    private function isTriggerSupported(array $trigger): bool
    {
        $type = strtolower(trim((string) ($trigger['type'] ?? '')));
        if ($type === '') {
            return false;
        }

        return in_array($type, (new AutomationEngine())->getTriggers(), true);
    }

    private function areActionsSupported(array $actions, int $userId = 0): bool
    {
        if ($actions === []) {
            return false;
        }

        $workspaceId = 0;
        try {
            $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            $workspaceId = 0;
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                return false;
            }

            $type = strtolower(trim((string) ($action['type'] ?? '')));
            if ($type === '' || !$this->workflowCapabilities->isActionAvailable($type, $workspaceId, $userId)) {
                return false;
            }
        }

        return true;
    }

    private function decodeTemplateRecord(array $template): array
    {
        $template['trigger_config'] = $this->decodeJsonArray($template['trigger_config'] ?? []);
        $template['conditions'] = $this->decodeJsonArray($template['conditions'] ?? []);
        $template['actions'] = $this->decodeJsonArray($template['actions'] ?? []);
        $template['variables'] = $this->decodeJsonArray($template['variables'] ?? []);

        return $template;
    }

    private function decodeJsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
