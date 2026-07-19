<?php
/**
 * Condition Evaluator
 * Evaluates workflow conditions
 */

namespace CRM\Modules;

use CRM\Database;

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
        
        // Try contact data if contact_id exists
        if (isset($context['contact_id'])) {
            $contact = Database::queryOne(
                "SELECT * FROM contacts WHERE id = ?",
                [$context['contact_id']]
            );
            if ($contact && isset($contact[$field])) {
                return $contact[$field];
            }
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
