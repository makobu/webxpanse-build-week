<?php
/**
 * Deals Management Module
 * 
 * Handles sales opportunities and deals
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Concurrency;
use CRM\Security;
use CRM\Modules\Notifications;
use CRM\Services\AttributionService;
use CRM\Services\TouchpointIngestionService;
use CRM\Services\OutcomeEventService;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\WorkspaceScopeService;

class Deals
{
    private const ALLOWED_STAGES = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
    private const ALLOWED_LEAD_SOURCES = ['form', 'whatsapp', 'ad', 'referral', 'social', 'cold_call', 'import', 'web_assessment', 'other'];

    private AttributionService $attributionService;
    private TouchpointIngestionService $touchpointIngestion;
    private static array $tableExistsCache = [];
    /** @var array<int,int> */
    private static array $activeUpdateDepth = [];
    private static bool $dispatchingUpdateSideEffects = false;
    private WorkspaceScopeService $workspaceScope;
    private ContactAssignmentAccessService $assignmentAccess;

    public function __construct()
    {
        $this->attributionService = new AttributionService();
        $this->touchpointIngestion = new TouchpointIngestionService();
        $this->workspaceScope = new WorkspaceScopeService();
        $this->assignmentAccess = new ContactAssignmentAccessService($this->workspaceScope);
    }

    /**
     * Create a new deal
     */
    public function create(array $data): int
    {
        $workspaceId = $this->workspaceId();
        // Validate required fields
        if (empty($data['title'])) {
            throw new \InvalidArgumentException("Deal title is required");
        }
        
        // Sanitize inputs
        $title = Security::sanitizeInput($data['title'], 'string');
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $companyId = !empty($data['company_id']) ? (int) $data['company_id'] : null;
        $assignedTo = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $stage = $this->normalizeStage($data['stage'] ?? 'prospecting');
        $value = $this->normalizeNonNegativeAmount($data['value'] ?? 0, 'Deal value');
        $probability = $this->normalizeProbability($data['probability'] ?? 0);
        $expectedCloseDate = $this->normalizeDate($data['expected_close_date'] ?? null, 'Expected close date');
        $currency = Security::sanitizeInput($data['currency'] ?? 'USD', 'string');
        $leadSource = $this->normalizeLeadSource($data['lead_source'] ?? null);

        if ($title === '') {
            throw new \InvalidArgumentException("Deal title is required");
        }
        
        // Auto-set actual_close_date if deal is created as closed
        if (in_array($stage, ['closed_won', 'closed_lost'])) {
            if (empty($data['actual_close_date'])) {
                $actualCloseDate = date('Y-m-d');
            } else {
                $actualCloseDate = $this->normalizeDate($data['actual_close_date'], 'Actual close date');
            }
        } else {
            $actualCloseDate = $this->normalizeDate($data['actual_close_date'] ?? null, 'Actual close date');
        }

        if ($contactId !== null) {
            $this->workspaceScope->assertSameWorkspace('contacts', $contactId, $workspaceId);
        }
        if ($companyId !== null) {
            $this->workspaceScope->assertSameWorkspace('companies', $companyId, $workspaceId);
        }
        $this->assignmentAccess->assertAssignableUser($assignedTo, $workspaceId);
        
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, company_id, assigned_to, created_by, stage, value, probability, expected_close_date, actual_close_date, currency, lead_source) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $title, $description, $contactId, $companyId, $assignedTo, $createdBy, $stage, $value, $probability, $expectedCloseDate, $actualCloseDate, $currency, $leadSource]
        );
        
        $dealId = (int) Database::lastInsertId();
        
        // Log activity
        if ($contactId) {
            $activities = new Activities();
            $activities->log($contactId, 'deal_created', "Deal created: $title");
        }
        
        // Publish event for workflows
        \CRM\EventBus::publish('deal.created', [
            'deal_id' => $dealId,
            'contact_id' => $contactId,
            'deal' => [
                'id' => $dealId,
                'title' => $title,
                'stage' => $stage,
                'value' => $value,
                'contact_id' => $contactId
            ]
        ]);
        try {
            $outcomes = new OutcomeEventService();
            $outcomes->track('deal.created', [
                'user_id' => $createdBy,
                'contact_id' => $contactId,
                'deal_id' => $dealId,
                'event_source' => 'deals.create',
                'metadata' => [
                    'stage' => $stage,
                    'value' => $value,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('Deals::create outcome tracking failed: ' . $e->getMessage());
        }

        if ($contactId) {
            $this->touchpointIngestion->ingestTouchpoint([
                'contact_id' => $contactId,
                'campaign_id' => !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
                'source_table' => 'deals',
                'source_id' => $dealId,
                'channel' => 'deal',
                'touch_type' => 'deal_created',
                'value_amount' => $value,
                'metadata' => ['stage' => $stage]
            ]);
        }
        
        // Create notification for assigned user
        if ($assignedTo) {
            $notifications = new \CRM\Modules\Notifications();
            $notifications->create(
                $assignedTo,
                'deal_created',
                'New Deal Assigned',
                "You have been assigned to deal: $title",
                [
                    'entity_type' => 'deal',
                    'entity_id' => $dealId,
                    'link' => publicUrl("deal_view.php?id=$dealId")
                ]
            );
        }

        if ($contactId) {
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
            } catch (\Throwable $e) {
                error_log('Deals::create intelligence refresh failed: ' . $e->getMessage());
            }
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('deals', $dealId, [
                'contact_id' => $contactId,
                'company_id' => $companyId,
                'assigned_to' => $assignedTo,
            ]);
        } catch (\Throwable $e) {
            error_log('Deals::create target intelligence refresh failed: ' . $e->getMessage());
        }
        
        return $dealId;
    }
    
    /**
     * Get deal by ID
     */
    public function getById(int $id): ?array
    {
        $deal = $this->getDealRowById($id);
        
        if ($deal) {
            $deal['line_items'] = $this->getLineItems($id);
        }
        return $deal;
    }

    /**
     * Get a deal row without hydrating dependent collections.
     */
    private function getDealRowById(int $id): ?array
    {
        $workspace = $this->workspaceClause('d.');

        return Database::queryOne(
            "SELECT d.*, 
                    c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                    u1.email as assigned_to_email,
                    u2.email as created_by_email
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             LEFT JOIN users u1 ON d.assigned_to = u1.id
             LEFT JOIN users u2 ON d.created_by = u2.id
             WHERE {$workspace['sql']}
               AND d.id = ?",
            array_merge($workspace['params'], [$id])
        );
    }

    /**
     * Get line items for a deal
     */
    public function getLineItems(int $dealId): array
    {
        if (!$this->dealLineItemsTableExists()) {
            return [];
        }
        if (!$this->dealExistsInWorkspace($dealId)) {
            return [];
        }

        return Database::query(
            "SELECT li.*, p.name as product_name FROM deal_line_items li
             LEFT JOIN products p ON li.product_id = p.id AND p.workspace_id = ?
             WHERE li.deal_id = ? ORDER BY li.sort_order, li.id",
            [$this->workspaceId(), $dealId]
        );
    }

    /**
     * Add line item to deal
     */
    public function addLineItem(int $dealId, array $data): int
    {
        $this->requireDealLineItemsTable();
        if (!$this->getById($dealId)) {
            throw new \RuntimeException('Deal not found in the active workspace.');
        }

        $productId = !empty($data['product_id']) ? (int) $data['product_id'] : null;
        if ($productId !== null) {
            $this->workspaceScope->assertSameWorkspace('products', $productId, $this->workspaceId());
        }
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $quantity = !empty($data['quantity']) ? (float) $data['quantity'] : 1;
        $unitPrice = !empty($data['unit_price']) ? (float) $data['unit_price'] : 0;
        $discountPercent = !empty($data['discount_percent']) ? (float) $data['discount_percent'] : 0;
        $this->assertLineItemNumbers($quantity, $unitPrice, $discountPercent);

        $subtotal = $quantity * $unitPrice;
        $total = $subtotal * (1 - $discountPercent / 100);

        $maxOrder = Database::queryOne("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM deal_line_items WHERE deal_id = ?", [$dealId]);
        $sortOrder = (int) ($maxOrder['next_order'] ?? 1);

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO deal_line_items (deal_id, product_id, description, quantity, unit_price, discount_percent, total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$dealId, $productId, $description ?: null, $quantity, $unitPrice, $discountPercent, $total, $sortOrder]
            );
            $lineItemId = (int) Database::lastInsertId();
            $this->recalculateDealValue($dealId);
            Database::commit();
            return $lineItemId;
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update line item
     */
    public function updateLineItem(int $lineItemId, array $data): bool
    {
        $this->requireDealLineItemsTable();

        $item = Database::queryOne("SELECT * FROM deal_line_items WHERE id = ?", [$lineItemId]);
        if (!$item) return false;
        if (!$this->getById((int) $item['deal_id'])) {
            return false;
        }

        $quantity = isset($data['quantity']) ? (float) $data['quantity'] : $item['quantity'];
        $unitPrice = isset($data['unit_price']) ? (float) $data['unit_price'] : $item['unit_price'];
        $discountPercent = isset($data['discount_percent']) ? (float) $data['discount_percent'] : $item['discount_percent'];
        $this->assertLineItemNumbers((float) $quantity, (float) $unitPrice, (float) $discountPercent);
        $productId = !empty($data['product_id']) ? (int) $data['product_id'] : null;
        if ($productId !== null) {
            $this->workspaceScope->assertSameWorkspace('products', $productId, $this->workspaceId());
        }

        $subtotal = $quantity * $unitPrice;
        $total = $subtotal * (1 - $discountPercent / 100);

        Database::execute(
            "UPDATE deal_line_items SET product_id = ?, description = ?, quantity = ?, unit_price = ?, discount_percent = ?, total = ? WHERE id = ?",
            [
                $productId,
                Security::sanitizeInput($data['description'] ?? $item['description'], 'string') ?: null,
                $quantity,
                $unitPrice,
                $discountPercent,
                $total,
                $lineItemId
            ]
        );

        $this->recalculateDealValue($item['deal_id']);
        $updatedDeal = $this->getDealRowById((int) $item['deal_id']);
        if (!empty($updatedDeal['contact_id'])) {
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $updatedDeal['contact_id']);
            } catch (\Throwable $e) {
                error_log('Deals::update intelligence refresh failed: ' . $e->getMessage());
            }
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('deals', (int) $item['deal_id'], [
                'contact_id' => (int) ($updatedDeal['contact_id'] ?? 0),
                'company_id' => (int) ($updatedDeal['company_id'] ?? 0),
                'assigned_to' => (int) ($updatedDeal['assigned_to'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            error_log('Deals::update target intelligence refresh failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Remove line item
     */
    public function removeLineItem(int $lineItemId): bool
    {
        $this->requireDealLineItemsTable();

        $item = Database::queryOne("SELECT deal_id FROM deal_line_items WHERE id = ?", [$lineItemId]);
        if (!$item) return false;
        if (!$this->getById((int) $item['deal_id'])) {
            return false;
        }

        Database::execute("DELETE FROM deal_line_items WHERE id = ?", [$lineItemId]);
        $this->recalculateDealValue($item['deal_id']);
        return true;
    }

    /**
     * Recalculate deal value from line items
     */
    public function recalculateDealValue(int $dealId): void
    {
        if (!$this->dealLineItemsTableExists()) {
            return;
        }
        if (!$this->getById($dealId)) {
            return;
        }

        $sum = Database::queryOne("SELECT COALESCE(SUM(total), 0) as total FROM deal_line_items WHERE deal_id = ?", [$dealId]);
        $total = (float) ($sum['total'] ?? 0);
        Database::execute("UPDATE deals SET value = ? WHERE workspace_id = ? AND id = ?", [$total, $this->workspaceId(), $dealId]);
    }

    private function requireDealLineItemsTable(): void
    {
        if (!$this->dealLineItemsTableExists()) {
            throw new \RuntimeException('Deal line items are not available yet. Run migration 067_create_deal_line_items.sql to enable quote line items.');
        }
    }

    private function assertLineItemNumbers(float $quantity, float $unitPrice, float $discountPercent): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Line item quantity must be greater than zero');
        }
        if ($unitPrice < 0) {
            throw new \InvalidArgumentException('Line item unit price cannot be negative');
        }
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new \InvalidArgumentException('Line item discount must be between 0 and 100 percent');
        }
    }

    private function dealLineItemsTableExists(): bool
    {
        if (array_key_exists('deal_line_items', self::$tableExistsCache)) {
            return self::$tableExistsCache['deal_line_items'];
        }

        try {
            $row = Database::queryOne(
                'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                ['deal_line_items']
            );
            self::$tableExistsCache['deal_line_items'] = ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$tableExistsCache['deal_line_items'] = false;
        }

        return self::$tableExistsCache['deal_line_items'];
    }

    private function dealExistsInWorkspace(int $dealId): bool
    {
        if ($dealId <= 0) {
            return false;
        }

        return $this->getDealRowById($dealId) !== null;
    }
    
    /**
     * Update deal
     */
    public function update(int $id, array $data): bool
    {
        $deal = $this->getById($id);
        if (!$deal) {
            throw new \RuntimeException("Deal not found");
        }

        $isReentrantUpdate = isset(self::$activeUpdateDepth[$id]) && self::$activeUpdateDepth[$id] > 0;
        self::$activeUpdateDepth[$id] = (int) (self::$activeUpdateDepth[$id] ?? 0) + 1;

        try {
        
        $updates = [];
        $params = [];
        $submittedFields = [];
        
        $allowedFields = ['title', 'description', 'contact_id', 'company_id', 'assigned_to', 'stage', 'value', 'probability', 'expected_close_date', 'actual_close_date', 'currency', 'lead_source', 'quote_number', 'quote_valid_until'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'title' || $field === 'description' || $field === 'currency' || $field === 'quote_number') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if ($field === 'title' && $value === '') {
                        throw new \InvalidArgumentException("Deal title is required");
                    }
                } elseif ($field === 'expected_close_date' || $field === 'actual_close_date' || $field === 'quote_valid_until') {
                    $label = match ($field) {
                        'actual_close_date' => 'Actual close date',
                        'quote_valid_until' => 'Quote valid until',
                        default => 'Expected close date',
                    };
                    $value = $this->normalizeDate($data[$field], $label);
                } elseif ($field === 'contact_id' || $field === 'company_id' || $field === 'assigned_to') {
                    $value = !empty($data[$field]) ? (int) $data[$field] : null;
                } elseif ($field === 'value') {
                    $value = $this->normalizeNonNegativeAmount($data[$field], 'Deal value');
                } elseif ($field === 'probability') {
                    $value = $this->normalizeProbability($data[$field]);
                } elseif ($field === 'stage') {
                    $value = $this->normalizeStage($data[$field]);
                } elseif ($field === 'lead_source') {
                    $value = $this->normalizeLeadSource($data[$field]);
                } else {
                    $value = $data[$field];
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
                $submittedFields[$field] = $value;
            }
        }

        if (array_key_exists('contact_id', $data) && !empty($data['contact_id'])) {
            $this->workspaceScope->assertSameWorkspace('contacts', (int) $data['contact_id'], $this->workspaceId());
        }
        if (array_key_exists('company_id', $data) && !empty($data['company_id'])) {
            $this->workspaceScope->assertSameWorkspace('companies', (int) $data['company_id'], $this->workspaceId());
        }
        if (array_key_exists('assigned_to', $data)) {
            $this->assignmentAccess->assertAssignableUser(
                !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
                $this->workspaceId()
            );
        }
        
        // Auto-set actual_close_date when deal is closed
        if (isset($data['stage']) && in_array($data['stage'], ['closed_won', 'closed_lost'])) {
            // Only auto-set if actual_close_date is not explicitly provided
            if (!isset($data['actual_close_date'])) {
                // Only set if currently NULL (preserve existing dates)
                if (empty($deal['actual_close_date'])) {
                    $data['actual_close_date'] = date('Y-m-d');
                    $updates[] = "actual_close_date = ?";
                    $params[] = $data['actual_close_date'];
                    $submittedFields['actual_close_date'] = $data['actual_close_date'];
                }
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        Concurrency::executeWorkspaceUpdate(
            'deals',
            $this->workspaceId(),
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn() => $this->getById($id),
            $submittedFields,
            'deal'
        );

        if ($isReentrantUpdate || self::$dispatchingUpdateSideEffects) {
            return true;
        }

        self::$dispatchingUpdateSideEffects = true;
        try {
        // Publish events for workflows
        $updatedDeal = $this->getById($id);
        if ($updatedDeal) {
            $eventData = [
                'deal_id' => $id,
                'contact_id' => $updatedDeal['contact_id'],
                'deal' => $updatedDeal
            ];
            
            // Check if stage changed
            if (isset($data['stage']) && $data['stage'] !== $deal['stage']) {
                $eventData['from_stage'] = $deal['stage'];
                $eventData['to_stage'] = $data['stage'];
                \CRM\EventBus::publish('deal.stage_changed', $eventData);
                // Keep manual stage changes request-safe. Downstream automation and
                // evidence scans should run off the emitted stage-change event rather
                // than recursively expanding this request in-place.
                // Keep manual deal updates lightweight and replay-safe. Automation,
                // outcome tracking, and AI demonstration capture should happen off the
                // emitted events rather than expanding this request inline.
                
                // Check if won or lost
                if ($data['stage'] === 'closed_won') {
                    \CRM\EventBus::publish('deal.won', $eventData);
                    $this->touchpointIngestion->ingestTouchpoint([
                        'contact_id' => (int) ($updatedDeal['contact_id'] ?? 0),
                        'campaign_id' => !empty($updatedDeal['campaign_id']) ? (int) $updatedDeal['campaign_id'] : null,
                        'source_table' => 'deals',
                        'source_id' => $id,
                        'channel' => 'deal',
                        'touch_type' => 'deal_closed_won',
                        'value_amount' => (float) ($updatedDeal['value'] ?? 0),
                        'metadata' => ['from_stage' => $deal['stage'] ?? null]
                    ]);
                    $this->attributionService->recomputeForDeal($id);
                } elseif ($data['stage'] === 'closed_lost') {
                    \CRM\EventBus::publish('deal.lost', $eventData);
                    $this->touchpointIngestion->ingestTouchpoint([
                        'contact_id' => (int) ($updatedDeal['contact_id'] ?? 0),
                        'campaign_id' => !empty($updatedDeal['campaign_id']) ? (int) $updatedDeal['campaign_id'] : null,
                        'source_table' => 'deals',
                        'source_id' => $id,
                        'channel' => 'deal',
                        'touch_type' => 'deal_closed_lost',
                        'value_amount' => (float) ($updatedDeal['value'] ?? 0),
                        'metadata' => ['from_stage' => $deal['stage'] ?? null]
                    ]);
                }
            }
            
            // Check if amount changed
            if (isset($data['value']) && abs(($data['value'] ?? 0) - ($deal['value'] ?? 0)) > 0.01) {
                \CRM\EventBus::publish('deal.amount_changed', $eventData);
            }
            
            \CRM\EventBus::publish('deal.updated', $eventData);
        }
        
        // Log activity if stage changed
        if (isset($data['stage']) && $data['stage'] !== $deal['stage'] && $deal['contact_id']) {
            $activities = new Activities();
            $activities->log($deal['contact_id'], 'deal_stage_changed', "Deal stage changed from {$deal['stage']} to {$data['stage']}");
            
            // Create notification for assigned user
            if ($deal['assigned_to']) {
                $notifications = new \CRM\Modules\Notifications();
                $notifications->create(
                    $deal['assigned_to'],
                    'deal_stage_changed',
                    'Deal Stage Changed',
                    "Deal '{$deal['title']}' moved to stage: " . str_replace('_', ' ', $data['stage']),
                    [
                        'entity_type' => 'deal',
                        'entity_id' => $id,
                        'link' => publicUrl("deal_view.php?id=$id")
                    ]
                );
            }
        }
        
        // Create notification if deal won
        if (isset($data['stage']) && $data['stage'] === 'closed_won' && $deal['stage'] !== 'closed_won' && $deal['assigned_to']) {
            $notifications = new \CRM\Modules\Notifications();
            $notifications->create(
                $deal['assigned_to'],
                'deal_won',
                'Deal Won! 🎉',
                "Congratulations! Deal '{$deal['title']}' has been won.",
                [
                    'entity_type' => 'deal',
                    'entity_id' => $id,
                    'link' => publicUrl("deal_view.php?id=$id")
                ]
            );
        }
        
        return true;
        } finally {
            self::$dispatchingUpdateSideEffects = false;
        }
        } finally {
            if (isset(self::$activeUpdateDepth[$id])) {
                self::$activeUpdateDepth[$id]--;
                if (self::$activeUpdateDepth[$id] <= 0) {
                    unset(self::$activeUpdateDepth[$id]);
                }
            }
        }
    }
    
    /**
     * Delete deal
     */
    public function delete(int $id): bool
    {
        $deal = $this->getById($id);
        if (!$deal) {
            return false;
        }
        
        Database::execute("DELETE FROM deals WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        
        return true;
    }
    
    /**
     * Get all deals
     */
    public function getAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $parts = $this->buildFilterParts($filters);
        
        $sql = "SELECT d.*, 
                       c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                       u1.email as assigned_to_email,
                       u2.email as created_by_email
                FROM deals d
                LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
                LEFT JOIN users u1 ON d.assigned_to = u1.id
                LEFT JOIN users u2 ON d.created_by = u2.id";
        
        if ($parts['where'] !== []) {
            $sql .= " WHERE " . implode(" AND ", $parts['where']);
        }
        
        $sql .= " ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
        $params = $parts['params'];
        $params[] = $limit;
        $params[] = $offset;
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get deals by stage
     */
    public function getByStage(string $stage, array $filters = []): array
    {
        $filters['stage'] = $stage;
        $parts = $this->buildFilterParts($filters);

        $sql = "SELECT d.*, 
                    c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                    u1.email as assigned_to_email
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             LEFT JOIN users u1 ON d.assigned_to = u1.id";
        if ($parts['where'] !== []) {
            $sql .= " WHERE " . implode(" AND ", $parts['where']);
        }
        $sql .= " ORDER BY d.expected_close_date ASC, d.created_at DESC";

        return Database::query(
            $sql,
            $parts['params']
        );
    }
    
    /**
     * Get pipeline statistics
     */
    public function getPipelineStats(array $filters = []): array
    {
        $parts = $this->buildFilterParts($filters);
        $rows = Database::query(
            "SELECT d.stage, 
                    COUNT(*) as count,
                    SUM(d.value) as total_value,
                    AVG(d.probability) as avg_probability
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             " . ($parts['where'] !== [] ? "WHERE " . implode(" AND ", $parts['where']) : "") . "
             GROUP BY d.stage",
            $parts['params']
        );

        $stats = [];
        $wonDeals = ['count' => 0, 'total_value' => 0];
        $lostDeals = ['count' => 0];
        $totalPipelineValue = 0.0;

        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?? '');
            if ($stage === 'closed_won') {
                $wonDeals = $row;
                continue;
            }
            if ($stage === 'closed_lost') {
                $lostDeals = $row;
                continue;
            }

            $stats[] = $row;
            $totalPipelineValue += (float) ($row['total_value'] ?? 0);
        }
        
        return [
            'by_stage' => $stats,
            'won' => [
                'count' => (int) ($wonDeals['count'] ?? 0),
                'value' => (float) ($wonDeals['total_value'] ?? 0)
            ],
            'lost' => [
                'count' => (int) ($lostDeals['count'] ?? 0)
            ],
            'total_pipeline_value' => $totalPipelineValue
        ];
    }
    
    /**
     * Get deals count
     */
    public function getCount(array $filters = []): int
    {
        $parts = $this->buildFilterParts($filters);
        $sql = "SELECT COUNT(*) as count FROM deals d LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id";
        if ($parts['where'] !== []) {
            $sql .= " WHERE " . implode(" AND ", $parts['where']);
        }
        
        $result = Database::queryOne($sql, $parts['params']);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * @return array{where:array<int, string>, params:array<int, mixed>}
     */
    private function buildFilterParts(array $filters): array
    {
        $workspace = $this->workspaceClause('d.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];

        if (!empty($filters['stage'])) {
            $where[] = "d.stage = ?";
            $params[] = $filters['stage'];
        }

        if (!empty($filters['exclude_stages']) && is_array($filters['exclude_stages'])) {
            $excludeStages = array_values(array_filter(array_map(static fn ($stage) => Security::sanitizeInput((string) $stage, 'string'), $filters['exclude_stages'])));
            if ($excludeStages !== []) {
                $placeholders = implode(',', array_fill(0, count($excludeStages), '?'));
                $where[] = "d.stage NOT IN ($placeholders)";
                $params = array_merge($params, $excludeStages);
            }
        }

        if (array_key_exists('assigned_to', $filters) && $filters['assigned_to'] !== null && $filters['assigned_to'] !== '') {
            $where[] = "d.assigned_to = ?";
            $params[] = (int) $filters['assigned_to'];
        }

        if (!empty($filters['contact_id'])) {
            $where[] = "d.contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
            $where[] = "(d.title LIKE ? OR d.description LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }
    
    /**
     * Get user deals
     */
    public function getUserDeals(int $userId, array $filters = []): array
    {
        $filters['assigned_to'] = $userId;
        return $this->getAll(100, 0, $filters);
    }
    
    /**
     * Get contact deals
     */
    public function getContactDeals(int $contactId): array
    {
        return Database::query(
            "SELECT d.*, 
                    u1.email as assigned_to_email,
                    u2.email as created_by_email
             FROM deals d
             LEFT JOIN users u1 ON d.assigned_to = u1.id
             LEFT JOIN users u2 ON d.created_by = u2.id
             WHERE d.workspace_id = ?
               AND d.contact_id = ?
             ORDER BY d.created_at DESC",
            [$this->workspaceId(), $contactId]
        );
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    private function workspaceClause(string $alias = '', string $column = 'workspace_id'): array
    {
        return $this->workspaceScope->workspaceClause($alias, $column);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function normalizeStage($value): string
    {
        $stage = Security::sanitizeInput((string) $value, 'string');
        if (!in_array($stage, self::ALLOWED_STAGES, true)) {
            throw new \InvalidArgumentException('Invalid deal stage');
        }

        return $stage;
    }

    private function normalizeLeadSource($value): ?string
    {
        $source = Security::sanitizeInput((string) ($value ?? ''), 'string');
        if ($source === '') {
            return null;
        }
        if (!in_array($source, self::ALLOWED_LEAD_SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid deal lead source');
        }

        return $source;
    }

    private function normalizeNonNegativeAmount($value, string $label): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($label . ' must be a number');
        }

        $amount = (float) $value;
        if ($amount < 0) {
            throw new \InvalidArgumentException($label . ' cannot be negative');
        }

        return $amount;
    }

    private function normalizeProbability($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Deal probability must be a number');
        }

        $probability = (int) $value;
        if ($probability < 0 || $probability > 100) {
            throw new \InvalidArgumentException('Deal probability must be between 0 and 100');
        }

        return $probability;
    }

    private function normalizeDate($value, string $label): ?string
    {
        $date = trim((string) ($value ?? ''));
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException($label . ' must be a valid date');
        }

        return $date;
    }
}
