# Workflow Implementation Quick Start Guide

## Phase 1: Foundation - Getting Started

This guide provides step-by-step instructions for implementing Phase 1 of the workflow competitive enhancement plan.

---

## Step 1: Condition System Implementation

### 1.1 Create Condition Evaluator Module

**File:** `modules/ConditionEvaluator.php`

```php
<?php
namespace CRM\Modules;

class ConditionEvaluator
{
    /**
     * Evaluate workflow conditions
     */
    public function evaluateConditions(array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true; // No conditions = always true
        }
        
        // Handle single condition
        if (isset($conditions['field'])) {
            return $this->evaluateCondition($conditions, $context);
        }
        
        // Handle condition group
        if (isset($conditions['operator'])) {
            return $this->evaluateConditionGroup($conditions, $context);
        }
        
        return true;
    }
    
    /**
     * Evaluate a single condition
     */
    private function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        
        if (!$field) {
            return true;
        }
        
        // Get field value from context
        $fieldValue = $this->getFieldValue($field, $context);
        
        // Evaluate based on operator
        return $this->compare($fieldValue, $operator, $value);
    }
    
    /**
     * Evaluate a condition group (AND/OR)
     */
    private function evaluateConditionGroup(array $group, array $context): bool
    {
        $operator = strtoupper($group['operator'] ?? 'AND');
        $conditions = $group['conditions'] ?? [];
        
        if (empty($conditions)) {
            return true;
        }
        
        $results = [];
        foreach ($conditions as $condition) {
            $results[] = $this->evaluateConditions($condition, $context);
        }
        
        if ($operator === 'OR') {
            return in_array(true, $results, true);
        }
        
        // Default to AND
        return !in_array(false, $results, true);
    }
    
    /**
     * Get field value from context
     */
    private function getFieldValue(string $field, array $context)
    {
        // Direct field access
        if (isset($context[$field])) {
            return $context[$field];
        }
        
        // Nested field access (e.g., contact.company)
        if (strpos($field, '.') !== false) {
            $parts = explode('.', $field);
            $value = $context;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return null;
                }
            }
            return $value;
        }
        
        // Try contact data
        if (isset($context['contact_id'])) {
            $contact = \CRM\Database::queryOne(
                "SELECT * FROM contacts WHERE id = ?",
                [$context['contact_id']]
            );
            return $contact[$field] ?? null;
        }
        
        return null;
    }
    
    /**
     * Compare values based on operator
     */
    private function compare($fieldValue, string $operator, $compareValue): bool
    {
        switch ($operator) {
            case 'equals':
            case '==':
                return $fieldValue == $compareValue;
                
            case 'not_equals':
            case '!=':
                return $fieldValue != $compareValue;
                
            case 'contains':
                return stripos((string)$fieldValue, (string)$compareValue) !== false;
                
            case 'not_contains':
                return stripos((string)$fieldValue, (string)$compareValue) === false;
                
            case 'starts_with':
                return stripos((string)$fieldValue, (string)$compareValue) === 0;
                
            case 'ends_with':
                return substr_compare((string)$fieldValue, (string)$compareValue, -strlen($compareValue)) === 0;
                
            case 'greater_than':
            case '>':
                return $fieldValue > $compareValue;
                
            case 'less_than':
            case '<':
                return $fieldValue < $compareValue;
                
            case 'greater_than_or_equal':
            case '>=':
                return $fieldValue >= $compareValue;
                
            case 'less_than_or_equal':
            case '<=':
                return $fieldValue <= $compareValue;
                
            case 'in':
                if (!is_array($compareValue)) {
                    $compareValue = explode(',', $compareValue);
                }
                return in_array($fieldValue, $compareValue);
                
            case 'not_in':
                if (!is_array($compareValue)) {
                    $compareValue = explode(',', $compareValue);
                }
                return !in_array($fieldValue, $compareValue);
                
            case 'is_empty':
            case 'empty':
                return empty($fieldValue);
                
            case 'is_not_empty':
            case 'not_empty':
                return !empty($fieldValue);
                
            case 'matches_regex':
                return preg_match($compareValue, (string)$fieldValue) === 1;
                
            default:
                return false;
        }
    }
}
```

### 1.2 Update AutomationEngine to Use Condition Evaluator

**File:** `modules/AutomationEngine.php`

Update the `evaluateConditions` method:

```php
use CRM\Modules\ConditionEvaluator;

private function evaluateConditions(array $conditions, array $eventData): bool
{
    $evaluator = new ConditionEvaluator();
    return $evaluator->evaluateConditions($conditions, $eventData);
}
```

---

## Step 2: Complete Action Implementations

### 2.1 Update executeAction Method

**File:** `modules/AutomationEngine.php`

Complete the `executeAction` method:

```php
private function executeAction(array $action, array $context): void
{
    try {
        switch ($action['type']) {
            case 'send_email':
                $this->executeSendEmail($action, $context);
                break;
                
            case 'send_whatsapp':
                $this->executeSendWhatsApp($action, $context);
                break;
                
            case 'add_tag':
                $this->executeAddTag($action, $context);
                break;
                
            case 'change_stage':
                $this->executeChangeStage($action, $context);
                break;
                
            case 'create_task':
                $this->executeCreateTask($action, $context);
                break;
                
            case 'assign_to_user':
                $this->executeAssignToUser($action, $context);
                break;
                
            case 'wait_for_days':
                $this->executeWaitForDays($action, $context);
                break;
                
            case 'update_contact_field':
                $this->executeUpdateContactField($action, $context);
                break;
                
            case 'create_deal':
                $this->executeCreateDeal($action, $context);
                break;
                
            case 'add_note':
                $this->executeAddNote($action, $context);
                break;
                
            case 'update_lead_score':
                $this->executeUpdateLeadScore($action, $context);
                break;
                
            case 'call_webhook':
                $this->executeCallWebhook($action, $context);
                break;
                
            default:
                error_log("Unknown action type: " . $action['type']);
        }
    } catch (\Exception $e) {
        error_log("Workflow action failed: " . $e->getMessage());
        throw $e;
    }
}

private function executeSendEmail(array $action, array $context): void
{
    $emailService = new \CRM\Services\EmailService();
    $subject = $this->replaceTokens($action['subject'] ?? '', $context);
    $body = $this->replaceTokens($action['body'] ?? '', $context);
    
    $emailService->send(
        $context['contact_id'],
        $context['contact_email'] ?? $context['email'],
        $subject,
        $body,
        $action['template_id'] ?? null
    );
}

private function executeSendWhatsApp(array $action, array $context): void
{
    $whatsappService = new \CRM\Services\WhatsAppService();
    $message = $this->replaceTokens($action['message'] ?? '', $context);
    
    $whatsappService->send(
        $context['contact_id'],
        $context['phone'] ?? '',
        $message
    );
}

private function executeAddTag(array $action, array $context): void
{
    $tagId = $action['tag_id'] ?? null;
    $tagName = $action['tag_name'] ?? null;
    
    if (!$tagId && $tagName) {
        // Find or create tag
        $tag = Database::queryOne(
            "SELECT id FROM tags WHERE name = ?",
            [$tagName]
        );
        if (!$tag) {
            Database::execute(
                "INSERT INTO tags (name) VALUES (?)",
                [$tagName]
            );
            $tagId = Database::lastInsertId();
        } else {
            $tagId = $tag['id'];
        }
    }
    
    if ($tagId) {
        // Check if tag already exists
        $exists = Database::queryOne(
            "SELECT id FROM contact_tags WHERE contact_id = ? AND tag_id = ?",
            [$context['contact_id'], $tagId]
        );
        
        if (!$exists) {
            Database::execute(
                "INSERT INTO contact_tags (contact_id, tag_id) VALUES (?, ?)",
                [$context['contact_id'], $tagId]
            );
        }
    }
}

private function executeChangeStage(array $action, array $context): void
{
    $stage = $action['stage'] ?? null;
    if ($stage) {
        Database::execute(
            "UPDATE contacts SET stage = ? WHERE id = ?",
            [$stage, $context['contact_id']]
        );
    }
}

private function executeCreateTask(array $action, array $context): void
{
    $title = $this->replaceTokens($action['title'] ?? 'Task', $context);
    $description = $this->replaceTokens($action['description'] ?? '', $context);
    $dueDate = $action['due_date'] ?? null;
    $assignedTo = $action['assigned_to'] ?? $context['user_id'] ?? null;
    $priority = $action['priority'] ?? 'normal';
    
    Database::execute(
        "INSERT INTO tasks (contact_id, title, description, due_date, assigned_to, priority, status) 
         VALUES (?, ?, ?, ?, ?, ?, 'pending')",
        [$context['contact_id'], $title, $description, $dueDate, $assignedTo, $priority]
    );
}

private function executeAssignToUser(array $action, array $context): void
{
    $userId = $action['user_id'] ?? null;
    if ($userId) {
        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$userId, $context['contact_id']]
        );
    }
}

private function executeWaitForDays(array $action, array $context): void
{
    $days = (int)($action['days'] ?? 1);
    $executionId = $context['execution_id'] ?? null;
    
    if ($executionId) {
        // Schedule next action for later
        $scheduledFor = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        Database::execute(
            "INSERT INTO scheduled_workflow_actions 
             (workflow_id, execution_id, action_index, scheduled_for, status) 
             VALUES (?, ?, ?, ?, 'pending')",
            [
                $context['workflow_id'],
                $executionId,
                $context['action_index'] + 1,
                $scheduledFor
            ]
        );
    }
}

private function executeUpdateContactField(array $action, array $context): void
{
    $field = $action['field'] ?? null;
    $value = $this->replaceTokens($action['value'] ?? '', $context);
    
    if ($field && in_array($field, ['first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'stage'])) {
        Database::execute(
            "UPDATE contacts SET {$field} = ? WHERE id = ?",
            [$value, $context['contact_id']]
        );
    }
}

private function executeCreateDeal(array $action, array $context): void
{
    $name = $this->replaceTokens($action['name'] ?? 'Deal', $context);
    $amount = $action['amount'] ?? 0;
    $stage = $action['stage'] ?? 'new';
    
    Database::execute(
        "INSERT INTO deals (contact_id, name, amount, stage, created_by) 
         VALUES (?, ?, ?, ?, ?)",
        [
            $context['contact_id'],
            $name,
            $amount,
            $stage,
            $context['user_id'] ?? null
        ]
    );
}

private function executeAddNote(array $action, array $context): void
{
    $note = $this->replaceTokens($action['note'] ?? '', $context);
    
    Database::execute(
        "INSERT INTO notes (contact_id, note, created_by) VALUES (?, ?, ?)",
        [$context['contact_id'], $note, $context['user_id'] ?? null]
    );
}

private function executeUpdateLeadScore(array $action, array $context): void
{
    $score = (int)($action['score'] ?? 0);
    $operation = $action['operation'] ?? 'set'; // set, add, subtract
    
    $contact = Database::queryOne(
        "SELECT lead_score FROM contacts WHERE id = ?",
        [$context['contact_id']]
    );
    
    $currentScore = (int)($contact['lead_score'] ?? 0);
    
    switch ($operation) {
        case 'add':
            $newScore = $currentScore + $score;
            break;
        case 'subtract':
            $newScore = max(0, $currentScore - $score);
            break;
        default:
            $newScore = $score;
    }
    
    Database::execute(
        "UPDATE contacts SET lead_score = ? WHERE id = ?",
        [$newScore, $context['contact_id']]
    );
}

private function executeCallWebhook(array $action, array $context): void
{
    $url = $action['url'] ?? null;
    $method = strtoupper($action['method'] ?? 'POST');
    $headers = $action['headers'] ?? [];
    $body = $action['body'] ?? $context;
    
    if ($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers['Content-Type'] = 'application/json';
        }
        
        if (!empty($headers)) {
            $headerArray = [];
            foreach ($headers as $key => $value) {
                $headerArray[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }
        
        curl_exec($ch);
        curl_close($ch);
    }
}
```

---

## Step 3: Database Migrations

Create migration file: `database/migrations/011_enhance_workflows.sql`

```sql
-- Add new columns to workflows table
ALTER TABLE workflows ADD COLUMN (
    description TEXT,
    category VARCHAR(100),
    version INT DEFAULT 1,
    execution_count INT DEFAULT 0,
    success_count INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    avg_execution_time DECIMAL(10,2),
    last_executed_at DATETIME NULL
);

-- Create scheduled workflow actions table
CREATE TABLE IF NOT EXISTS scheduled_workflow_actions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    execution_id INT NOT NULL,
    action_index INT NOT NULL,
    scheduled_for DATETIME NOT NULL,
    timezone VARCHAR(50),
    status ENUM('pending','executed','failed','cancelled') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    INDEX idx_scheduled (scheduled_for, status),
    INDEX idx_workflow_exec (workflow_id, execution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhance workflow_executions table
ALTER TABLE workflow_executions ADD COLUMN (
    execution_time_ms INT,
    actions_completed INT DEFAULT 0,
    actions_failed INT DEFAULT 0,
    error_details JSON
);
```

---

## Step 4: Testing

Create test file: `tests/Unit/ConditionEvaluatorTest.php`

```php
<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use CRM\Modules\ConditionEvaluator;

class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;
    
    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }
    
    public function testEqualsCondition()
    {
        $condition = [
            'field' => 'stage',
            'operator' => 'equals',
            'value' => 'qualified'
        ];
        
        $context = ['stage' => 'qualified'];
        
        $result = $this->evaluator->evaluateConditions($condition, $context);
        $this->assertTrue($result);
    }
    
    public function testContainsCondition()
    {
        $condition = [
            'field' => 'company',
            'operator' => 'contains',
            'value' => 'Acme'
        ];
        
        $context = ['company' => 'Acme Corp'];
        
        $result = $this->evaluator->evaluateConditions($condition, $context);
        $this->assertTrue($result);
    }
    
    public function testAndConditionGroup()
    {
        $conditions = [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified'],
                ['field' => 'company', 'operator' => 'contains', 'value' => 'Acme']
            ]
        ];
        
        $context = [
            'stage' => 'qualified',
            'company' => 'Acme Corp'
        ];
        
        $result = $this->evaluator->evaluateConditions($conditions, $context);
        $this->assertTrue($result);
    }
}
```

---

## Next Steps

1. **Implement Condition Evaluator** - Start with basic operators
2. **Complete Action Implementations** - Implement all declared actions
3. **Add Tests** - Write unit tests for each component
4. **Update UI** - Add condition builder to workflow creation form
5. **Documentation** - Update user documentation

---

**See:** `docs/workflow-competitive-plan.md` for full implementation plan
