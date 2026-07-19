<?php
/**
 * Reports Management Module
 * 
 * Handles report generation and management
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Authorization;
use CRM\Services\WorkspaceContext;

class Reports
{
    /**
     * Shared report type definitions used for UI and query validation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getReportTypeDefinitions(): array
    {
        return [
            'contacts' => [
                'fields' => [
                    'id' => 'ID',
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'company' => 'Company',
                    'lead_source' => 'Lead Source',
                    'stage' => 'Stage',
                    'lead_score' => 'Composite Lead Score',
                    'created_at' => 'Created At',
                    'updated_at' => 'Updated At',
                ],
                'default_fields' => ['id', 'first_name', 'last_name', 'email', 'company', 'stage', 'lead_score', 'created_at'],
                'order_fields' => [
                    'created_at' => 'Created Date',
                    'updated_at' => 'Updated Date',
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'stage' => 'Stage',
                    'lead_score' => 'Composite Lead Score',
                ],
                'default_order_by' => 'created_at',
                'default_order_dir' => 'DESC',
                'supports_limit' => true,
                'filters' => ['date_from', 'date_to', 'stage', 'lead_source'],
            ],
            'activities' => [
                'fields' => [
                    'a.id' => 'ID',
                    'a.activity_type' => 'Activity Type',
                    'a.description' => 'Description',
                    'a.created_at' => 'Created At',
                    'c.first_name' => 'Contact First Name',
                    'c.last_name' => 'Contact Last Name',
                    'c.email' => 'Contact Email',
                ],
                'default_fields' => ['a.id', 'a.activity_type', 'a.description', 'a.created_at', 'c.first_name', 'c.last_name', 'c.email'],
                'order_fields' => [
                    'a.created_at' => 'Created Date',
                    'a.activity_type' => 'Activity Type',
                ],
                'default_order_by' => 'a.created_at',
                'default_order_dir' => 'DESC',
                'supports_limit' => true,
                'filters' => ['date_from', 'date_to'],
            ],
            'sales' => [
                'fields' => [],
                'default_fields' => [],
                'order_fields' => [],
                'default_order_by' => '',
                'default_order_dir' => 'DESC',
                'supports_limit' => false,
                'filters' => ['date_from', 'date_to'],
            ],
            'emails' => [
                'fields' => [],
                'default_fields' => [],
                'order_fields' => [],
                'default_order_by' => '',
                'default_order_dir' => 'DESC',
                'supports_limit' => false,
                'filters' => ['date_from', 'date_to'],
            ],
            'tasks' => [
                'fields' => [],
                'default_fields' => [],
                'order_fields' => [],
                'default_order_by' => '',
                'default_order_dir' => 'DESC',
                'supports_limit' => false,
                'filters' => ['status', 'priority'],
            ],
            'events' => [
                'fields' => [],
                'default_fields' => [],
                'order_fields' => [],
                'default_order_by' => '',
                'default_order_dir' => 'DESC',
                'supports_limit' => false,
                'filters' => ['date_from', 'event_type'],
            ],
            'custom' => [
                'fields' => [],
                'default_fields' => [],
                'order_fields' => [],
                'default_order_by' => '',
                'default_order_dir' => 'DESC',
                'supports_limit' => false,
                'filters' => [],
            ],
        ];
    }

    /**
     * Create a new report
     */
    public function create(array $data): int
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['query_config'])) {
            throw new \Exception("Report name and query configuration are required");
        }
        
        // Sanitize inputs
        $name = Security::sanitizeInput($data['name'], 'string');
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $reportType = $data['report_type'] ?? 'custom';
        $queryConfig = is_array($data['query_config']) ? json_encode($data['query_config']) : $data['query_config'];
        $chartConfig = !empty($data['chart_config']) ? (is_array($data['chart_config']) ? json_encode($data['chart_config']) : $data['chart_config']) : null;
        $filters = !empty($data['filters']) ? (is_array($data['filters']) ? json_encode($data['filters']) : $data['filters']) : null;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $isPublic = isset($data['is_public']) ? (int) $data['is_public'] : 0;
        $workspaceId = $this->requireWorkspaceId();
        
        // Validate report type
        $allowedTypes = ['contacts', 'activities', 'sales', 'emails', 'tasks', 'events', 'custom'];
        if (!in_array($reportType, $allowedTypes)) {
            $reportType = 'custom';
        }
        
        Database::execute(
            "INSERT INTO reports (workspace_id, name, description, report_type, query_config, chart_config, filters, created_by, is_public) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $name, $description, $reportType, $queryConfig, $chartConfig, $filters, $createdBy, $isPublic]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get report by ID
     */
    public function getById(int $id): ?array
    {
        $report = Database::queryOne(
            "SELECT r.*, u.email as created_by_email
             FROM reports r
             LEFT JOIN users u ON r.created_by = u.id
             WHERE r.workspace_id = ?
               AND r.id = ?",
            [$this->requireWorkspaceId(), $id]
        );
        
        if ($report) {
            $report['query_config'] = json_decode($report['query_config'], true) ?? [];
            $report['chart_config'] = !empty($report['chart_config']) ? json_decode($report['chart_config'], true) : null;
            $report['filters'] = !empty($report['filters']) ? json_decode($report['filters'], true) : null;
        }
        
        return $report;
    }

    public function getViewableById(int $id, int $userId): ?array
    {
        $report = $this->getById($id);
        if (!$report || !$this->canUserView($report, $userId)) {
            return null;
        }

        return $report;
    }

    public function getEditableById(int $id, int $userId): ?array
    {
        $report = $this->getById($id);
        if (!$report || !$this->canUserEdit($report, $userId)) {
            return null;
        }

        return $report;
    }

    public function canUserView(array $report, int $userId): bool
    {
        return (int) ($report['created_by'] ?? 0) === $userId || (int) ($report['is_public'] ?? 0) === 1;
    }

    public function canUserEdit(array $report, int $userId): bool
    {
        return (int) ($report['created_by'] ?? 0) === $userId;
    }
    
    /**
     * Update report
     */
    public function update(int $id, array $data): bool
    {
        $report = $this->getById($id);
        if (!$report) {
            throw new \Exception("Report not found");
        }
        
        $updates = [];
        $params = [];
        
        $allowedFields = ['name', 'description', 'report_type', 'query_config', 'chart_config', 'filters', 'is_public'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'name' || $field === 'description') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                } elseif ($field === 'query_config' || $field === 'chart_config' || $field === 'filters') {
                    $value = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                } elseif ($field === 'is_public') {
                    $value = (int) $data[$field];
                } else {
                    $value = $data[$field];
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        $params[] = $this->requireWorkspaceId();
        Database::execute(
            "UPDATE reports SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
            $params
        );
        
        return true;
    }

    public function updateForUser(int $id, int $userId, array $data): bool
    {
        if (!$this->getEditableById($id, $userId)) {
            throw new \Exception("You do not have permission to edit this report");
        }

        return $this->update($id, $data);
    }
    
    /**
     * Delete report
     */
    public function delete(int $id): bool
    {
        $report = $this->getById($id);
        if (!$report) {
            return false;
        }
        
        Database::execute(
            "DELETE FROM reports WHERE id = ? AND workspace_id = ?",
            [$id, $this->requireWorkspaceId()]
        );
        
        return true;
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        if (!$this->getEditableById($id, $userId)) {
            throw new \Exception("You do not have permission to delete this report");
        }

        return $this->delete($id);
    }
    
    /**
     * Get all reports
     */
    public function getAll(int $userId = null, bool $includePublic = true): array
    {
        $where = [];
        $params = [];
        
        if ($userId) {
            if ($includePublic) {
                $where[] = "(created_by = ? OR is_public = 1)";
                $params[] = $userId;
            } else {
                $where[] = "created_by = ?";
                $params[] = $userId;
            }
        }

        $workspaceId = $this->requireWorkspaceId();
        $where[] = "r.workspace_id = ?";
        $params[] = $workspaceId;
        
        $sql = "SELECT r.*, u.email as created_by_email,
                       COALESCE(re.execution_count, 0) as execution_count
                FROM reports r
                LEFT JOIN users u ON r.created_by = u.id
                LEFT JOIN (
                    SELECT workspace_id, report_id, COUNT(*) as execution_count
                    FROM report_executions
                    WHERE workspace_id = ?
                    GROUP BY workspace_id, report_id
                ) re ON re.workspace_id = r.workspace_id AND re.report_id = r.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        $reports = Database::query($sql, array_merge([$workspaceId], $params));
        
        foreach ($reports as &$report) {
            $report['query_config'] = json_decode($report['query_config'], true) ?? [];
            $report['chart_config'] = !empty($report['chart_config']) ? json_decode($report['chart_config'], true) : null;
            $report['filters'] = !empty($report['filters']) ? json_decode($report['filters'], true) : null;
        }
        
        return $reports;
    }
    
    /**
     * Execute report and return data
     */
    public function execute(int $reportId, array $parameters = []): array
    {
        $report = $this->getById($reportId);
        if (!$report) {
            throw new \Exception("Report not found");
        }
        
        $startTime = microtime(true);
        
        // Build query based on report type and configuration
        $data = $this->buildQuery($report, $parameters);
        
        $executionTime = microtime(true) - $startTime;
        
        // Log execution
        $this->logExecution($reportId, $parameters, count($data), $executionTime);
        
        return $data;
    }

    public function executeForUser(int $reportId, int $userId, array $parameters = []): array
    {
        $report = $this->getViewableById($reportId, $userId);
        if (!$report) {
            throw new \Exception("You do not have permission to view this report");
        }

        $startTime = microtime(true);
        $data = $this->buildQuery($report, $parameters, ['id' => $userId]);
        $executionTime = microtime(true) - $startTime;
        $this->logExecution($reportId, $parameters, count($data), $executionTime);

        return $data;
    }

    public function prepareConfiguration(string $reportType, array $queryConfig = [], array $filters = []): array
    {
        $definitions = $this->getReportTypeDefinitions();
        $definition = $definitions[$reportType] ?? $definitions['custom'];
        $allowedFields = array_keys($definition['fields'] ?? []);
        $allowedOrderFields = array_keys($definition['order_fields'] ?? []);

        $selectedFields = array_values(array_filter(
            array_map('strval', (array) ($queryConfig['fields'] ?? [])),
            static fn (string $field): bool => in_array($field, $allowedFields, true)
        ));

        if ($allowedFields !== [] && $selectedFields === []) {
            $selectedFields = array_values($definition['default_fields'] ?? $allowedFields);
        }

        $defaultOrderBy = (string) ($definition['default_order_by'] ?? '');
        $orderBy = (string) ($queryConfig['order_by'] ?? $defaultOrderBy);
        if ($allowedOrderFields === [] || !in_array($orderBy, $allowedOrderFields, true)) {
            $orderBy = $defaultOrderBy;
        }

        $orderDir = strtoupper((string) ($queryConfig['order_dir'] ?? ($definition['default_order_dir'] ?? 'DESC')));
        if (!in_array($orderDir, ['ASC', 'DESC'], true)) {
            $orderDir = 'DESC';
        }

        $limit = null;
        if (!empty($definition['supports_limit'])) {
            $limitValue = $queryConfig['limit'] ?? null;
            if ($limitValue !== null && $limitValue !== '') {
                $limit = max(1, (int) $limitValue);
            }
        }

        $allowedFilters = array_flip((array) ($definition['filters'] ?? []));
        $normalizedFilters = [];
        foreach ($allowedFilters as $filterKey => $_enabled) {
            $value = $filters[$filterKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $normalizedFilters[$filterKey] = is_string($value)
                ? Security::sanitizeInput($value, 'string')
                : $value;
        }

        return [
            'query_config' => [
                'fields' => $selectedFields,
                'order_by' => $orderBy,
                'order_dir' => $orderDir,
                'limit' => $limit,
            ],
            'filters' => $normalizedFilters,
        ];
    }

    public function getExportCapabilities(): array
    {
        return [
            'csv' => true,
            'pdf' => class_exists('TCPDF'),
            'excel' => class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet'),
        ];
    }

    public function isExportFormatAvailable(string $format): bool
    {
        $capabilities = $this->getExportCapabilities();
        $format = $this->normalizeExportFormat($format);

        return !empty($capabilities[$format]);
    }

    public function normalizeExportFormat(string $format): string
    {
        $normalized = strtolower(trim($format));
        return in_array($normalized, ['csv', 'pdf', 'excel'], true) ? $normalized : 'csv';
    }

    public function getExportUnavailableMessage(string $format): string
    {
        $normalized = strtoupper($this->normalizeExportFormat($format));
        return $normalized . ' export is not available on this system.';
    }

    public function formatDisplayValue(string $column, $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if ($this->isDateLikeValue($column, $trimmed)) {
                return date('M d, Y g:i A', strtotime($trimmed));
            }
            if (is_numeric($trimmed)) {
                return $this->formatNumericValue($column, $trimmed);
            }
            return $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return $this->formatNumericValue($column, $value);
        }

        return (string) $value;
    }
    
    /**
     * Build query based on report configuration
     */
    private function buildQuery(array $report, array $parameters, ?array $user = null): array
    {
        $reportType = $report['report_type'];
        $queryConfig = $report['query_config'];
        $filters = array_merge($report['filters'] ?? [], $parameters);
        
        switch ($reportType) {
            case 'contacts':
                return $this->buildContactsReport($queryConfig, $filters, $user);
            
            case 'activities':
                return $this->buildActivitiesReport($queryConfig, $filters, $user);
            
            case 'sales':
                return $this->buildSalesReport($queryConfig, $filters, $user);
            
            case 'emails':
                return $this->buildEmailsReport($queryConfig, $filters);
            
            case 'tasks':
                return $this->buildTasksReport($queryConfig, $filters, $user);
            
            case 'events':
                return $this->buildEventsReport($queryConfig, $filters);
            
            default:
                return $this->buildCustomReport($queryConfig, $filters);
        }
    }
    
    /**
     * Build contacts report
     */
    private function buildContactsReport(array $config, array $filters, ?array $user = null): array
    {
        $prepared = $this->prepareConfiguration('contacts', $config, $filters);
        $selectFields = $prepared['query_config']['fields'];
        $config = $prepared['query_config'];
        $filters = $prepared['filters'];
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        $this->appendContactOwnerScope($where, $params, '', $user);
        
        // Apply filters
        if (!empty($filters['stage'])) {
            $where[] = "stage = ?";
            $params[] = $filters['stage'];
        }
        
        if (!empty($filters['lead_source'])) {
            $where[] = "lead_source = ?";
            $params[] = $filters['lead_source'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
            $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM contacts";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY " . ($config['order_by'] ?: 'created_at') . " " . ($config['order_dir'] ?: 'DESC');
        
        if (!empty($config['limit'])) {
            $sql .= " LIMIT " . (int) $config['limit'];
        }
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build activities report
     */
    private function buildActivitiesReport(array $config, array $filters, ?array $user = null): array
    {
        $prepared = $this->prepareConfiguration('activities', $config, $filters);
        $selectFields = $prepared['query_config']['fields'];
        $config = $prepared['query_config'];
        $filters = $prepared['filters'];
        $where = ["a.workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        $this->appendActivityContactOwnerScope($where, $params, $user);
        
        if (!empty($filters['activity_type'])) {
            $where[] = "a.activity_type = ?";
            $params[] = $filters['activity_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "a.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "a.created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "SELECT " . implode(', ', $selectFields) . " 
                FROM activities a
                LEFT JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY " . ($config['order_by'] ?: 'a.created_at') . " " . ($config['order_dir'] ?: 'DESC');
        
        if (!empty($config['limit'])) {
            $sql .= " LIMIT " . (int) $config['limit'];
        }
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build sales report
     */
    private function buildSalesReport(array $config, array $filters, ?array $user = null): array
    {
        // Sales report - aggregate by stage
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        $this->appendContactOwnerScope($where, $params, '', $user);
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "SELECT stage, 
                       COUNT(*) as count,
                       AVG(lead_score) as avg_score
                FROM contacts";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " GROUP BY stage ORDER BY count DESC";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build emails report
     */
    private function buildEmailsReport(array $config, array $filters): array
    {
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "SELECT DATE(created_at) as date,
                       COUNT(*) as sent_count,
                       SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened_count,
                       SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_count
                FROM emails";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " GROUP BY DATE(created_at) ORDER BY date DESC";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build tasks report
     */
    private function buildTasksReport(array $config, array $filters, ?array $user = null): array
    {
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        $this->appendTaskOwnerScope($where, $params, '', $user);
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['priority'])) {
            $where[] = "priority = ?";
            $params[] = $filters['priority'];
        }
        
        $sql = "SELECT status, priority, COUNT(*) as count
                FROM tasks";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " GROUP BY status, priority";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build events report
     */
    private function buildEventsReport(array $config, array $filters): array
    {
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        
        if (!empty($filters['event_type'])) {
            $where[] = "event_type = ?";
            $params[] = $filters['event_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "start_time >= ?";
            $params[] = $filters['date_from'];
        }
        
        $sql = "SELECT event_type, status, COUNT(*) as count
                FROM events";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " GROUP BY event_type, status";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Build custom report (for future SQL-based reports)
     */
    private function buildCustomReport(array $config, array $filters): array
    {
        // For now, return empty - can be extended for custom SQL queries
        return [];
    }
    
    /**
     * Log report execution
     */
    private function logExecution(int $reportId, array $parameters, int $resultCount, float $executionTime): void
    {
        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
        $executedBy = $sessionUserId > 0 ? $sessionUserId : null;
        $workspaceId = $this->requireWorkspaceId();
        
        Database::execute(
            "INSERT INTO report_executions (workspace_id, report_id, executed_by, parameters, result_count, execution_time) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $reportId,
                $executedBy,
                json_encode($parameters),
                $resultCount,
                $executionTime
            ]
        );
    }
    
    /**
     * Get report templates
     */
    public function getTemplates(): array
    {
        return [
            [
                'name' => 'Contacts by Stage',
                'report_type' => 'contacts',
                'description' => 'View contacts grouped by sales stage',
                'query_config' => [
                    'fields' => ['id', 'first_name', 'last_name', 'email', 'company', 'stage', 'lead_score'],
                    'order_by' => 'stage',
                    'order_dir' => 'ASC'
                ]
            ],
            [
                'name' => 'Activity Summary',
                'report_type' => 'activities',
                'description' => 'Summary of all activities',
                'query_config' => [
                    'fields' => ['activity_type', 'description', 'created_at'],
                    'order_by' => 'created_at',
                    'order_dir' => 'DESC',
                    'limit' => 100
                ]
            ],
            [
                'name' => 'Sales Pipeline',
                'report_type' => 'sales',
                'description' => 'Sales pipeline overview by stage',
                'query_config' => []
            ],
            [
                'name' => 'Email Performance',
                'report_type' => 'emails',
                'description' => 'Email open and click rates',
                'query_config' => []
            ],
            [
                'name' => 'Task Status',
                'report_type' => 'tasks',
                'description' => 'Task breakdown by status and priority',
                'query_config' => []
            ],
            [
                'name' => 'Event Calendar',
                'report_type' => 'events',
                'description' => 'Events by type and status',
                'query_config' => []
            ]
        ];
    }

    private function formatNumericValue(string $column, $value): string
    {
        $numericValue = (float) $value;
        if ($this->isIntegerLikeColumn($column, $value)) {
            return number_format((int) round($numericValue));
        }

        return number_format($numericValue, 2);
    }

    private function isIntegerLikeColumn(string $column, $value): bool
    {
        $normalizedColumn = strtolower(trim($column));
        $isIntegerValue = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1);
        if (!$isIntegerValue) {
            return false;
        }

        return preg_match('/(^id$|_id$|count$|_count$|score$|lead_score$|total$|number$|day_of_month$)/', $normalizedColumn) === 1;
    }

    private function isDateLikeValue(string $column, string $value): bool
    {
        $normalizedColumn = strtolower(trim($column));
        if (preg_match('/(^date$|_date$|_at$|^executed_at$|^created_at$|^updated_at$|^next_run_at$|^last_run_at$|^start_time$)/', $normalizedColumn) !== 1) {
            return false;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) === 1 && strtotime($value) !== false;
    }

    private function requireWorkspaceId(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required for reports.');
        }

        return $workspaceId;
    }

    private function appendContactOwnerScope(array &$where, array &$params, string $alias, ?array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || Authorization::can('contacts.view_all', $user)) {
            return;
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        $where[] = "({$prefix}assigned_to = ? OR {$prefix}assigned_to IS NULL OR {$prefix}assigned_to = 0)";
        $params[] = $userId;
    }

    private function appendActivityContactOwnerScope(array &$where, array &$params, ?array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || Authorization::can('contacts.view_all', $user)) {
            return;
        }

        $where[] = "(a.user_id = ? OR a.contact_id IS NULL OR c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)";
        $params[] = $userId;
        $params[] = $userId;
    }

    private function appendTaskOwnerScope(array &$where, array &$params, string $alias, ?array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || Authorization::can('tasks.view_all', $user)) {
            return;
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        $where[] = "({$prefix}assigned_to = ? OR {$prefix}created_by = ?)";
        $params[] = $userId;
        $params[] = $userId;
    }
}
