<?php
/**
 * Demo mode integration tests.
 */

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\DemoModeManager;
use CRM\Modules\Webhooks;
use CRM\Services\EmailService;
use CRM\Services\SMSService;
use CRM\Services\WhatsAppService;
use CRM\Tests\TestCase;

class DemoModeIntegrationTest extends TestCase
{
    private int $adminUserId = 0;
    private DemoModeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->coreSchemaReady()) {
            $this->markTestSkipped('Core schema is not ready for demo integration tests. Run migrations first.');
        }
        $this->ensureDemoModeMigrationApplied();
        $this->adminUserId = $this->createAdminUser();
        $this->manager = new DemoModeManager();
        $this->forceDemoModeOffAndCleanup();
    }

    protected function tearDown(): void
    {
        $this->forceDemoModeOffAndCleanup();
        if ($this->adminUserId > 0) {
            Database::execute('DELETE FROM users WHERE id = ?', [$this->adminUserId]);
        }
        parent::tearDown();
    }

    public function testEnableDisableSeedPurgeLifecycle(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'full');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));
        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);

        $state = $this->manager->getState();
        $this->assertTrue((bool) ($state['is_enabled'] ?? false));
        $this->assertSame($runId, (int) ($state['active_run_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($state['seeded_records'] ?? 0));

        $registryCountRow = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
        $this->assertGreaterThan(0, (int) ($registryCountRow['c'] ?? 0));

        $disable = $this->manager->disable($this->adminUserId);
        $this->assertTrue((bool) ($disable['success'] ?? false), (string) ($disable['error'] ?? ''));
        $stateAfterDisable = $this->manager->getState();
        $this->assertFalse((bool) ($stateAfterDisable['is_enabled'] ?? true));

        // OFF behavior requirement: data remains until explicit purge.
        $registryCountAfterDisable = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
        $this->assertGreaterThan(0, (int) ($registryCountAfterDisable['c'] ?? 0));

        $seed = $this->manager->seed($runId, $this->adminUserId, 'full');
        $this->assertTrue((bool) ($seed['success'] ?? false), (string) ($seed['error'] ?? ''));
        $this->assertIsArray($seed['seed_summary']['created'] ?? null);

        $sample = Database::queryOne('SELECT table_name, record_id FROM demo_seed_registry WHERE run_id = ? ORDER BY id DESC LIMIT 1', [$runId]);
        $this->assertNotNull($sample);
        $tableName = (string) ($sample['table_name'] ?? '');
        $recordId = (int) ($sample['record_id'] ?? 0);
        $this->assertNotSame('', $tableName);
        $this->assertGreaterThan(0, $recordId);

        if ($this->tableExists($tableName)) {
            $existsBeforePurge = Database::queryOne('SELECT id FROM `' . $tableName . '` WHERE id = ? LIMIT 1', [$recordId]);
            $this->assertNotNull($existsBeforePurge);
        }

        $purge = $this->manager->purge($runId, $this->adminUserId);
        $this->assertTrue((bool) ($purge['success'] ?? false), (string) ($purge['error'] ?? ''));

        $registryCountAfterPurge = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
        $this->assertSame(0, (int) ($registryCountAfterPurge['c'] ?? 0));

        $runRow = Database::queryOne('SELECT status FROM demo_runs WHERE id = ?', [$runId]);
        $this->assertSame('purged', (string) ($runRow['status'] ?? ''));
    }

    public function testPurgeActiveRunAlsoResetsDemoModeState(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'full');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));
        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);

        $stateBeforePurge = $this->manager->getState();
        $this->assertTrue((bool) ($stateBeforePurge['is_enabled'] ?? false));
        $this->assertSame($runId, (int) ($stateBeforePurge['active_run_id'] ?? 0));

        $purge = $this->manager->purge($runId, $this->adminUserId);
        $this->assertTrue((bool) ($purge['success'] ?? false), (string) ($purge['error'] ?? ''));

        $stateAfterPurge = $this->manager->getState();
        $this->assertFalse((bool) ($stateAfterPurge['is_enabled'] ?? true));
        $this->assertNull($stateAfterPurge['active_run_id'] ?? null);
        $this->assertSame(0, (int) ($stateAfterPurge['seeded_records'] ?? -1));
    }

    public function testSimulationAssertionsForChannelsAndWebhooks(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'full');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));
        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);

        $contactId = $this->createContact($this->adminUserId);

        // SMS simulation
        $_ENV['TWILIO_ACCOUNT_SID'] = 'live_sid_should_not_be_used';
        $_ENV['TWILIO_AUTH_TOKEN'] = 'live_token_should_not_be_used';
        $_ENV['TWILIO_FROM_NUMBER'] = '+15551112222';
        $smsResult = (new SMSService())->sendSMS('+15550001111', 'Demo-mode SMS test');
        $this->assertTrue((bool) ($smsResult['simulated'] ?? false));

        // WhatsApp simulation
        $_ENV['WHATSAPP_PHONE_NUMBER_ID'] = '1234567890';
        $_ENV['WHATSAPP_ACCESS_TOKEN'] = 'EAA_DEMO_TOKEN';
        $waResult = (new WhatsAppService())->sendTextMessage('+15550001111', 'Demo-mode WhatsApp test');
        $this->assertTrue((bool) ($waResult['simulated'] ?? false));
        $this->assertNotEmpty($waResult['messages'][0]['id'] ?? '');

        // Email simulation
        $_SESSION['user_id'] = $this->adminUserId;
        $emailService = new EmailService();
        $emailUuid = $emailService->send(
            $contactId,
            'demo-recipient@example.test',
            'Demo mode subject',
            'Demo mode body'
        );
        $emailRow = Database::queryOne('SELECT id, status, error_message FROM emails WHERE uuid = ?', [$emailUuid]);
        $this->assertNotNull($emailRow);
        $emailService->processEmail((int) $emailRow['id']);
        $emailAfter = Database::queryOne('SELECT status, error_message FROM emails WHERE id = ?', [(int) $emailRow['id']]);
        $this->assertSame('sent', (string) ($emailAfter['status'] ?? ''));
        $emailMarker = (string) ($emailAfter['error_message'] ?? '');
        if ($emailMarker !== '') {
            $this->assertStringContainsString('Simulated', $emailMarker);
        }

        // Webhook simulation
        Database::execute(
            "INSERT INTO webhooks (user_id, name, url, method, secret, events, headers, is_active)
             VALUES (?, ?, ?, 'POST', NULL, ?, NULL, 1)",
            [$this->adminUserId, 'Demo Webhook', 'https://example.test/webhook', json_encode(['contact.created'])]
        );
        $webhookId = (int) Database::lastInsertId();
        $this->assertGreaterThan(0, $webhookId);

        $webhooks = new Webhooks();
        $webhooks->trigger('contact.created', ['contact_id' => $contactId, 'source' => 'test']);

        $log = Database::queryOne(
            'SELECT response_status, response_body, payload FROM webhook_logs WHERE webhook_id = ? ORDER BY id DESC LIMIT 1',
            [$webhookId]
        );
        $this->assertNotNull($log);
        $this->assertSame(202, (int) ($log['response_status'] ?? 0));
        $this->assertStringContainsString('Simulated', (string) ($log['response_body'] ?? ''));
        $this->assertStringContainsString('simulated', strtolower((string) ($log['payload'] ?? '')));

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    public function testInteriorsContractorSeedProfileCreatesQuoteLedDemoData(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'interiors_contractor');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));

        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame(4, (int) ($enable['seed_summary']['created']['contacts'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['deals'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['tasks'] ?? 0));

        $quoteDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Kitchen Remodel Quote' LIMIT 1");
        $this->assertNotNull($quoteDeal);
        $this->assertSame('proposal', (string) ($quoteDeal['stage'] ?? ''));

        $stalledTask = Database::queryOne("SELECT title, priority FROM tasks WHERE priority = 'urgent' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($stalledTask);
        $this->assertSame('urgent', (string) ($stalledTask['priority'] ?? ''));

        $thread = Database::queryOne('SELECT channel, is_resolved FROM conversation_threads ORDER BY id ASC LIMIT 1');
        if ($thread !== null) {
            $this->assertContains((string) ($thread['channel'] ?? ''), ['whatsapp', 'email']);
        }

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    public function testWhatsAppHeavySmbSeedProfileCreatesLeadFollowUpDemoData(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'whatsapp_heavy_smb');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));

        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame(4, (int) ($enable['seed_summary']['created']['contacts'] ?? 0));
        $this->assertSame(2, (int) ($enable['seed_summary']['created']['deals'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['tasks'] ?? 0));

        $proposalDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Pricing Shared - Fast Close Opportunity' LIMIT 1");
        $this->assertNotNull($proposalDeal);
        $this->assertSame('proposal', (string) ($proposalDeal['stage'] ?? ''));

        $negotiationDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Stalled WhatsApp Follow-Up' LIMIT 1");
        $this->assertNotNull($negotiationDeal);
        $this->assertSame('negotiation', (string) ($negotiationDeal['stage'] ?? ''));

        $overdueTask = Database::queryOne("SELECT title, priority FROM tasks WHERE title = 'Reply to fresh WhatsApp inquiry' LIMIT 1");
        $this->assertNotNull($overdueTask);
        $this->assertSame('urgent', (string) ($overdueTask['priority'] ?? ''));

        $thread = Database::queryOne("SELECT channel FROM conversation_threads WHERE channel = 'whatsapp' ORDER BY id ASC LIMIT 1");
        $this->assertNotNull($thread);

        $comm = Database::queryOne("SELECT subject FROM communications WHERE subject = 'New WhatsApp inquiry' LIMIT 1");
        $this->assertNotNull($comm);

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    public function testAgencySeedProfileCreatesProposalAndKickoffDemoData(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'agencies');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));

        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame(4, (int) ($enable['seed_summary']['created']['contacts'] ?? 0));
        $this->assertSame(2, (int) ($enable['seed_summary']['created']['deals'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['tasks'] ?? 0));

        $proposalDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Website Refresh Proposal' LIMIT 1");
        $this->assertNotNull($proposalDeal);
        $this->assertSame('proposal', (string) ($proposalDeal['stage'] ?? ''));

        $kickoffDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Retainer Approval and Kickoff' LIMIT 1");
        $this->assertNotNull($kickoffDeal);
        $this->assertSame('negotiation', (string) ($kickoffDeal['stage'] ?? ''));

        $proposalTask = Database::queryOne("SELECT title, priority FROM tasks WHERE title = 'Follow up on proposal review' LIMIT 1");
        $this->assertNotNull($proposalTask);
        $this->assertSame('urgent', (string) ($proposalTask['priority'] ?? ''));

        $thread = Database::queryOne("SELECT channel FROM conversation_threads WHERE channel = 'email' ORDER BY id ASC LIMIT 1");
        $this->assertNotNull($thread);

        $comm = Database::queryOne("SELECT subject FROM communications WHERE subject = 'Proposal and scope shared' LIMIT 1");
        $this->assertNotNull($comm);

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    public function testSoloFounderLaunchSeedProfileCreatesFounderLaunchDemoData(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'solo_founder_launch');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));

        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame(8, (int) ($enable['seed_summary']['created']['contacts'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['deals'] ?? 0));
        $this->assertSame(8, (int) ($enable['seed_summary']['created']['tasks'] ?? 0));
        $this->assertSame(6, (int) ($enable['seed_summary']['created']['communications'] ?? 0));
        $this->assertSame(2, (int) ($enable['seed_summary']['created']['conversation_threads'] ?? 0));

        $sprintDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'First Paid Sprint - Warm Prospect' LIMIT 1");
        $this->assertNotNull($sprintDeal);
        $this->assertSame('proposal', (string) ($sprintDeal['stage'] ?? ''));

        $launchDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Launch Offer Follow-Up' LIMIT 1");
        $this->assertNotNull($launchDeal);
        $this->assertSame('negotiation', (string) ($launchDeal['stage'] ?? ''));

        foreach ([
            'Finalize first offer and pricing',
            'Send first 20 warm outreach messages',
            'Follow up after launch offer message',
            'Complete first weekly review',
        ] as $title) {
            $task = Database::queryOne('SELECT id FROM tasks WHERE title = ? LIMIT 1', [$title]);
            $this->assertNotNull($task, 'Missing founder launch task: ' . $title);
        }

        $thread = Database::queryOne("SELECT channel FROM conversation_threads WHERE channel IN ('whatsapp', 'email') ORDER BY id ASC LIMIT 1");
        $this->assertNotNull($thread);

        $comm = Database::queryOne("SELECT subject FROM communications WHERE subject = '30-day launch offer shared' LIMIT 1");
        $this->assertNotNull($comm);

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    public function testDistributorWholesalerSeedProfileCreatesReorderDemoData(): void
    {
        $enable = $this->manager->enable($this->adminUserId, 'distributors_wholesalers');
        $this->assertTrue((bool) ($enable['success'] ?? false), (string) ($enable['error'] ?? ''));

        $runId = (int) ($enable['run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame(4, (int) ($enable['seed_summary']['created']['contacts'] ?? 0));
        $this->assertSame(2, (int) ($enable['seed_summary']['created']['deals'] ?? 0));
        $this->assertSame(3, (int) ($enable['seed_summary']['created']['tasks'] ?? 0));

        $proposalDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Bulk Price List Follow-Up' LIMIT 1");
        $this->assertNotNull($proposalDeal);
        $this->assertSame('proposal', (string) ($proposalDeal['stage'] ?? ''));

        $reorderDeal = Database::queryOne("SELECT title, stage FROM deals WHERE title = 'Repeat Buyer Reorder Confirmation' LIMIT 1");
        $this->assertNotNull($reorderDeal);
        $this->assertSame('negotiation', (string) ($reorderDeal['stage'] ?? ''));

        $reorderTask = Database::queryOne("SELECT title, priority FROM tasks WHERE title = 'Follow up after price list was shared' LIMIT 1");
        $this->assertNotNull($reorderTask);
        $this->assertSame('urgent', (string) ($reorderTask['priority'] ?? ''));

        $thread = Database::queryOne("SELECT channel FROM conversation_threads WHERE channel = 'whatsapp' ORDER BY id ASC LIMIT 1");
        $this->assertNotNull($thread);

        $comm = Database::queryOne("SELECT subject FROM communications WHERE subject = 'Bulk stock inquiry' LIMIT 1");
        $this->assertNotNull($comm);

        $this->manager->disable($this->adminUserId);
        $this->manager->purge($runId, $this->adminUserId);
    }

    private function ensureDemoModeMigrationApplied(): void
    {
        if ($this->tableExists('demo_mode_state') && $this->tableExists('demo_runs') && $this->tableExists('demo_seed_registry')) {
            return;
        }

        $file = __DIR__ . '/../../database/migrations/104_create_demo_mode_tables.sql';
        $sql = (string) file_get_contents($file);
        $statements = $this->splitSqlStatements($sql);
        foreach ($statements as $statement) {
            try {
                Database::execute($statement);
            } catch (\Throwable $e) {
                if (stripos($e->getMessage(), 'already exists') === false && stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
            }
        }
    }

    private function splitSqlStatements(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $clean[] = $line;
        }

        $blob = implode("\n", $clean);
        $parts = array_filter(array_map('trim', explode(';', $blob)));
        return array_values($parts);
    }

    private function createAdminUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, ?, 'admin', NOW())",
            [$this->uuid(), 'Demo', 'Admin', 'demo.admin.' . uniqid('', true) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(int $assignedTo): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, phone, lead_source, stage, assigned_to, created_at)
             VALUES (?, ?, ?, ?, ?, 'form', 'new', ?, NOW())",
            [$this->uuid(), 'Sim', 'Contact', 'sim.contact.' . uniqid('', true) . '@example.test', '+15550001111', $assignedTo]
        );
        return (int) Database::lastInsertId();
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function coreSchemaReady(): bool
    {
        return $this->tableExists('users')
            && $this->tableExists('contacts')
            && $this->tableExists('webhooks')
            && $this->tableExists('webhook_logs')
            && $this->tableExists('emails');
    }

    private function forceDemoModeOffAndCleanup(): void
    {
        if (!$this->tableExists('demo_mode_state')) {
            return;
        }

        try {
            Database::execute('UPDATE demo_mode_state SET is_enabled = 0, active_run_id = NULL, simulation_only = 1, updated_by = NULL WHERE id = 1');
        } catch (\Throwable $e) {
            // no-op
        }

        if ($this->tableExists('demo_seed_registry')) {
            Database::execute('DELETE FROM demo_seed_registry');
        }
        if ($this->tableExists('demo_operation_logs')) {
            Database::execute('DELETE FROM demo_operation_logs');
        }
        if ($this->tableExists('demo_runs')) {
            Database::execute('DELETE FROM demo_runs');
        }
        if ($this->tableExists('webhook_logs')) {
            Database::execute('DELETE FROM webhook_logs');
        }
        if ($this->tableExists('webhooks')) {
            Database::execute("DELETE FROM webhooks WHERE name = 'Demo Webhook'");
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
