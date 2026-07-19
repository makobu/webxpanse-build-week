<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\OutcomeMetrics;
use CRM\Tests\DatabaseTestCase;

class OutcomeMetricsTest extends DatabaseTestCase
{
    private OutcomeMetrics $metrics;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new OutcomeMetrics();
        $this->userId = $this->createUser('outcome.metrics@example.test');
    }

    public function testTodayRevenueFocusUsesDefaultCurrencySymbol(): void
    {
        Database::execute("UPDATE currencies SET is_default = 0");
        Database::execute(
            "INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, is_active, is_default)
             VALUES ('KES', 'Kenyan Shilling', 'KSh', 'before', 2, 1, 1)
             ON DUPLICATE KEY UPDATE
                symbol = VALUES(symbol),
                symbol_position = VALUES(symbol_position),
                decimal_places = VALUES(decimal_places),
                is_active = VALUES(is_active),
                is_default = VALUES(is_default)"
        );
        Database::execute(
            "INSERT INTO deals (workspace_id, title, assigned_to, created_by, stage, value, updated_at, created_at)
             VALUES
                (1, 'First open deal', ?, ?, 'proposal', 1000.25, NOW(), NOW()),
                (1, 'Second open deal', ?, ?, 'negotiation', 234.31, NOW(), NOW())",
            [$this->userId, $this->userId, $this->userId, $this->userId]
        );

        $focus = $this->metrics->getTodayRevenueFocus($this->userId);

        $this->assertContains('Check 2 open deals worth about KSh1,235.', $focus);
        $this->assertStringNotContainsString('~$', implode(' ', $focus));
    }

    public function testRevenueMomentumChecklistUsesHealthyKeysWhenRhythmIsGood(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createContact('Healthy Lead ' . $i);
        }

        Database::execute(
            "INSERT INTO deals (workspace_id, title, assigned_to, created_by, stage, value, updated_at, created_at)
             VALUES (1, 'Healthy deal', ?, ?, 'proposal', 5000, NOW(), NOW())",
            [$this->userId, $this->userId]
        );
        $dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO outcome_events (user_id, deal_id, event_key, event_source, event_at, metadata)
             VALUES (?, ?, 'deal.advanced', 'test', NOW(), '{}')",
            [$this->userId, $dealId]
        );

        $items = $this->metrics->getRevenueMomentumChecklist($this->userId);
        $keys = array_column($items, 'step_key');

        $this->assertSame([
            'followups_clear',
            'no_stale_deals',
            'pipeline_active',
            'lead_refill',
            'deal_movement',
            'revenue_action',
        ], $keys);
        $this->assertCount(6, array_filter($items, static fn(array $item): bool => !empty($item['complete'])));
    }

    public function testRevenueMomentumChecklistUsesActionKeysWhenRevenueRhythmNeedsWork(): void
    {
        $this->createContact('Single Lead');
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, due_date, created_at)
             VALUES (1, 'Call prospect', ?, ?, 'pending', DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())",
            [$this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO deals (workspace_id, title, assigned_to, created_by, stage, value, updated_at, created_at)
             VALUES (1, 'Stale deal', ?, ?, 'proposal', 5000, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY))",
            [$this->userId, $this->userId]
        );

        $items = $this->metrics->getRevenueMomentumChecklist($this->userId);

        $this->assertSame([
            'due_followups',
            'stale_deals',
            'pipeline_active',
            'add_leads',
            'move_deal',
            'action_gap',
        ], array_column($items, 'step_key'));
        $this->assertSame([false, false, true, false, false, false], array_map(
            static fn(array $item): bool => (bool) $item['complete'],
            $items
        ));
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(string $firstName): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at)
             VALUES (1, ?, ?, 'Lead', ?, ?, NOW())",
            [uniqid('contact_', true), $firstName, strtolower(str_replace(' ', '.', $firstName)) . '@example.test', $this->userId]
        );
    }
}
