<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\OnboardingProgress;
use CRM\Tests\TestCase;

class OnboardingProgressTest extends TestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'company_profile', 'products', 'contacts', 'tasks', 'deals', 'workflows'] as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped('Required schema is missing for onboarding progress test.');
            }
        }
        if ($this->tableExists('workspace_launch_settings')) {
            Database::execute("UPDATE workspace_launch_settings SET target_niche = 'interiors_contractors', active_package = 'core' WHERE id = 1");
        }

        Database::execute('DELETE FROM company_profile');
        Database::execute('DELETE FROM products');
        Database::execute('DELETE FROM contacts');
        Database::execute('DELETE FROM tasks');
        Database::execute('DELETE FROM deals');
        Database::execute('DELETE FROM workflows');

        Database::execute(
            "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, created_at)
             VALUES (?, 'Launch', 'Owner', ?, ?, 'admin', NOW())",
            [$this->uuid(), 'launch.owner.' . uniqid('', true) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach (['company_profile', 'products', 'contacts', 'tasks', 'deals', 'workflows'] as $table) {
            if ($this->tableExists($table)) {
                Database::execute("DELETE FROM {$table}");
            }
        }
        if ($this->userId > 0 && $this->tableExists('users')) {
            Database::execute('DELETE FROM users WHERE id = ?', [$this->userId]);
        }
        parent::tearDown();
    }

    public function testProgressIncludesCommercialReadinessMilestones(): void
    {
        Database::execute(
            "INSERT INTO company_profile (company_name, company_description, company_industry, is_active)
             VALUES ('Summit Interiors', 'Project-led interior fit-out firm.', 'Interiors / Contracting', 1)"
        );
        Database::execute(
            "INSERT INTO products (name, category, pricing_info, is_active, display_order)
             VALUES ('Site Visit and Measurement', 'Service', 'Charged per visit or bundled into the final project.', 1, 1)"
        );
        for ($i = 1; $i <= 3; $i++) {
            Database::execute(
                "INSERT INTO contacts (uuid, first_name, last_name, email, stage, assigned_to, created_by, created_at)
                 VALUES (?, ?, 'Client', ?, 'proposal', ?, ?, NOW())",
                [$this->uuid(), 'Contact' . $i, 'contact' . $i . '.' . uniqid('', true) . '@example.test', $this->userId, $this->userId]
            );
        }
        $contactId = (int) (Database::queryOne('SELECT id FROM contacts ORDER BY id ASC LIMIT 1')['id'] ?? 0);
        Database::execute(
            "INSERT INTO tasks (title, description, contact_id, assigned_to, created_by, status, priority, due_date)
             VALUES ('Follow up on quote', 'Owner follow-up', ?, ?, ?, 'pending', 'high', DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$contactId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO deals (title, contact_id, assigned_to, created_by, stage, value, probability, currency)
             VALUES ('Kitchen remodel', ?, ?, ?, 'proposal', 12000, 70, 'USD')",
            [$contactId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO workflows (name, trigger_config, actions, is_active, description)
             VALUES ('Quote Follow-up', '{}', '[]', 1, 'Seeded follow-up workflow for onboarding test')"
        );

        $progress = (new OnboardingProgress())->getProgress($this->userId);
        $itemMap = [];
        foreach ($progress['items'] as $item) {
            $itemMap[$item['key']] = $item;
        }

        $this->assertSame('interiors_contractors', $progress['target_niche']);
        $this->assertTrue((bool) ($itemMap['company_profile_complete']['done'] ?? false));
        $this->assertTrue((bool) ($itemMap['service_catalog_ready']['done'] ?? false));
        $this->assertTrue((bool) ($itemMap['contact_import_ready']['done'] ?? false));
        $this->assertTrue((bool) ($itemMap['first_deal_created']['done'] ?? false));
        $this->assertTrue((bool) ($itemMap['task_ownership_ready']['done'] ?? false));
        $this->assertTrue((bool) ($itemMap['follow_up_workflow_ready']['done'] ?? false));
    }

    public function testProgressUsesWhatsAppHeavyHintsWhenWorkspaceNicheChanges(): void
    {
        if (!$this->tableExists('workspace_launch_settings')) {
            $this->markTestSkipped('workspace_launch_settings table is required for niche-aware onboarding progress.');
        }

        Database::execute("UPDATE workspace_launch_settings SET target_niche = 'whatsapp_heavy_smb', active_package = 'growth' WHERE id = 1");

        $progress = (new OnboardingProgress())->getProgress($this->userId);
        $itemMap = [];
        foreach ($progress['items'] as $item) {
            $itemMap[$item['key']] = $item;
        }

        $this->assertSame('whatsapp_heavy_smb', $progress['target_niche']);
        $this->assertStringContainsString('WhatsApp', (string) ($itemMap['channels_ready']['hint'] ?? ''));
        $this->assertStringContainsString('reply owner', strtolower((string) ($itemMap['task_ownership_ready']['hint'] ?? '')));
    }

    public function testProgressUsesAgencyHintsWhenWorkspaceNicheChanges(): void
    {
        if (!$this->tableExists('workspace_launch_settings')) {
            $this->markTestSkipped('workspace_launch_settings table is required for niche-aware onboarding progress.');
        }

        Database::execute("UPDATE workspace_launch_settings SET target_niche = 'agencies', active_package = 'growth' WHERE id = 1");

        $progress = (new OnboardingProgress())->getProgress($this->userId);
        $itemMap = [];
        foreach ($progress['items'] as $item) {
            $itemMap[$item['key']] = $item;
        }

        $this->assertSame('agencies', $progress['target_niche']);
        $this->assertStringContainsString('email', strtolower((string) ($itemMap['channels_ready']['hint'] ?? '')));
        $this->assertStringContainsString('kickoff', strtolower((string) ($itemMap['follow_up_workflow_ready']['hint'] ?? '')));
    }

    public function testProgressUsesDistributorHintsWhenWorkspaceNicheChanges(): void
    {
        if (!$this->tableExists('workspace_launch_settings')) {
            $this->markTestSkipped('workspace_launch_settings table is required for niche-aware onboarding progress.');
        }

        Database::execute("UPDATE workspace_launch_settings SET target_niche = 'distributors_wholesalers', active_package = 'growth' WHERE id = 1");

        $progress = (new OnboardingProgress())->getProgress($this->userId);
        $itemMap = [];
        foreach ($progress['items'] as $item) {
            $itemMap[$item['key']] = $item;
        }

        $this->assertSame('distributors_wholesalers', $progress['target_niche']);
        $this->assertStringContainsString('reorder', strtolower((string) ($itemMap['task_ownership_ready']['hint'] ?? '')));
        $this->assertStringContainsString('pricing', strtolower((string) ($itemMap['follow_up_workflow_ready']['hint'] ?? '')));
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
