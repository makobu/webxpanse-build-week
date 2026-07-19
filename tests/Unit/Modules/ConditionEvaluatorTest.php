<?php
/**
 * Condition Evaluator Unit Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\ConditionEvaluator;

class ConditionEvaluatorTest extends DatabaseTestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator();
    }

    public function testEmptyConditionsReturnTrue(): void
    {
        $this->assertTrue($this->evaluator->evaluateConditions([], ['contact_id' => 1]));
    }

    public function testSingleConditionEquals(): void
    {
        $conditions = ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified'];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['stage' => 'qualified']));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['stage' => 'new']));
    }

    public function testSingleConditionNotEquals(): void
    {
        $conditions = ['field' => 'stage', 'operator' => 'not_equals', 'value' => 'lost'];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['stage' => 'won']));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['stage' => 'lost']));
    }

    public function testSingleConditionContains(): void
    {
        $conditions = ['field' => 'email', 'operator' => 'contains', 'value' => '@example'];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['email' => 'test@example.com']));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['email' => 'test@other.com']));
    }

    public function testSingleConditionGreaterThan(): void
    {
        $conditions = ['field' => 'lead_score', 'operator' => 'greater_than', 'value' => '50'];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['lead_score' => 75]));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['lead_score' => 25]));
    }

    public function testConditionGroupAnd(): void
    {
        $conditions = [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified'],
                ['field' => 'lead_score', 'operator' => 'greater_than', 'value' => '50']
            ]
        ];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['stage' => 'qualified', 'lead_score' => 75]));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['stage' => 'qualified', 'lead_score' => 25]));
    }

    public function testConditionGroupOr(): void
    {
        $conditions = [
            'operator' => 'OR',
            'conditions' => [
                ['field' => 'stage', 'operator' => 'equals', 'value' => 'won'],
                ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified']
            ]
        ];
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['stage' => 'won']));
        $this->assertTrue($this->evaluator->evaluateConditions($conditions, ['stage' => 'qualified']));
        $this->assertFalse($this->evaluator->evaluateConditions($conditions, ['stage' => 'new']));
    }
}
