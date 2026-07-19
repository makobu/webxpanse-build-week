<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Activities;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Modules\Products;
use CRM\Modules\Tasks;

class WorkspaceSampleWorkflowService
{
    private const RECORD_CONTACT = 'sample_contact';
    private const RECORD_DEAL = 'sample_deal';
    private const RECORD_INVOICE = 'sample_invoice';
    private const RECORD_TASK = 'sample_task';
    private const RECORD_ACTIVITY = 'sample_activity';

    /** @var array<string,string> */
    private const TABLES_BY_KEY = [
        self::RECORD_CONTACT => 'contacts',
        self::RECORD_DEAL => 'deals',
        self::RECORD_INVOICE => 'invoices',
        self::RECORD_TASK => 'tasks',
        self::RECORD_ACTIVITY => 'activities',
    ];

    public function status(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return ['exists' => false, 'records' => [], 'links' => []];
        }

        $rows = Database::query(
            "SELECT *
             FROM workspace_sample_workflow_registry
             WHERE workspace_id = ?
             ORDER BY id ASC",
            [$workspaceId]
        );

        if (!$rows) {
            return ['exists' => false, 'records' => [], 'links' => []];
        }

        $records = [];
        $links = [];
        foreach ($rows as $row) {
            $key = (string) ($row['record_key'] ?? '');
            $table = (string) ($row['table_name'] ?? '');
            $recordId = (int) ($row['record_id'] ?? 0);
            if ($key === '' || $recordId <= 0) {
                continue;
            }

            $records[$key] = [
                'table_name' => $table,
                'record_id' => $recordId,
                'sample_run_id' => (string) ($row['sample_run_id'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
            $url = $this->urlForRecord($key, $recordId);
            if ($url !== null) {
                $links[$key] = $url;
            }
        }

        return [
            'exists' => $records !== [],
            'records' => $records,
            'links' => $links,
        ];
    }

    public function create(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Workspace and user are required.');
        }
        if (!$this->tableReady()) {
            throw new \RuntimeException('Sample workflow registry is not available. Run migrations first.');
        }

        $existing = $this->status($workspaceId);
        if (!empty($existing['exists'])) {
            return array_merge($existing, ['created' => false]);
        }

        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'admin');
        $runId = bin2hex(random_bytes(16));

        try {
            $context = $this->workspaceContext();
            $contact = (new Contacts())->create([
                'first_name' => 'Sample',
                'last_name' => 'Customer',
                'email' => 'sample.customer+' . $workspaceId . '-' . substr($runId, 0, 8) . '@example.test',
                'phone' => '+15550101001',
                'company' => $context['company_name'],
                'lead_source' => 'other',
                'assigned_to' => $userId,
                'created_by' => $userId,
                'job_title' => 'Operations Lead',
                'company_description' => 'Sample workflow record created to demonstrate the CRM flow.',
            ]);
            $contactId = (int) ($contact['id'] ?? 0);
            if ($contactId <= 0) {
                throw new \RuntimeException('Could not create sample contact.');
            }
            $this->register($workspaceId, $runId, 'contacts', $contactId, self::RECORD_CONTACT, $userId);

            $dealId = (new Deals())->create([
                'title' => 'Sample deal: ' . $context['offer_name'],
                'description' => 'Guided sample deal showing how a contact becomes a pipeline opportunity.',
                'contact_id' => $contactId,
                'assigned_to' => $userId,
                'created_by' => $userId,
                'stage' => 'proposal',
                'value' => $context['sample_amount'],
                'probability' => 60,
                'expected_close_date' => date('Y-m-d', strtotime('+14 days')),
                'currency' => $context['currency'],
                'lead_source' => 'sample_workflow',
            ]);
            $this->register($workspaceId, $runId, 'deals', $dealId, self::RECORD_DEAL, $userId);

            $invoiceId = (new Invoices())->create([
                'document_type' => 'invoice',
                'status' => 'draft',
                'deal_id' => $dealId,
                'contact_id' => $contactId,
                'assigned_to' => $userId,
                'created_by' => $userId,
                'currency' => $context['currency'],
                'title' => 'Sample invoice for ' . $context['offer_name'],
                'intro_text' => 'Sample invoice created from the guided workflow. Review and edit freely.',
                'notes' => 'Sample only. No message has been sent and no payment is due.',
                'terms' => 'Sample payment terms for onboarding practice.',
                'line_items' => [
                    [
                        'description' => $context['offer_name'],
                        'quantity' => 1,
                        'unit_price' => $context['sample_amount'],
                    ],
                ],
            ], 'user', $userId);
            $this->register($workspaceId, $runId, 'invoices', $invoiceId, self::RECORD_INVOICE, $userId);

            $taskId = (new Tasks())->create([
                'title' => 'Follow up with Sample Customer',
                'description' => 'Sample task from the guided workflow. Use it to try task assignment and completion.',
                'contact_id' => $contactId,
                'assigned_to' => $userId,
                'created_by' => $userId,
                'actor_user_id' => $userId,
                'status' => 'pending',
                'priority' => 'medium',
                'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'metadata_json' => [
                    'source' => 'guided_sample_workflow',
                    'sample_run_id' => $runId,
                ],
            ]);
            $this->register($workspaceId, $runId, 'tasks', $taskId, self::RECORD_TASK, $userId);

            $activityId = (new Activities())->log(
                $contactId,
                'email',
                'Sample inbound message: Hi, I am interested in ' . $context['offer_name'] . '. Could you send details and pricing?',
                [
                    'source' => 'guided_sample_workflow',
                    'sample_run_id' => $runId,
                    'external_send' => false,
                    'selected_channel' => $context['selected_channel'],
                ],
                $userId
            );
            $this->register($workspaceId, $runId, 'activities', $activityId, self::RECORD_ACTIVITY, $userId);

            $status = $this->status($workspaceId);
            return array_merge($status, [
                'created' => true,
                'sample_run_id' => $runId,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->remove($workspaceId, $userId);
            } catch (\Throwable $cleanupError) {
                error_log('Workspace sample workflow cleanup after failed create failed: ' . $cleanupError->getMessage());
            }
            throw $e;
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    public function remove(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return ['deleted' => 0, 'skipped' => 0];
        }

        $rows = Database::query(
            "SELECT *
             FROM workspace_sample_workflow_registry
             WHERE workspace_id = ?
             ORDER BY FIELD(record_key, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                self::RECORD_ACTIVITY,
                self::RECORD_TASK,
                self::RECORD_INVOICE,
                self::RECORD_DEAL,
                self::RECORD_CONTACT,
            ]
        );

        $deleted = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $table = (string) ($row['table_name'] ?? '');
            $recordId = (int) ($row['record_id'] ?? 0);
            if ($recordId <= 0 || !in_array($table, self::TABLES_BY_KEY, true) || !$this->tableExists($table)) {
                $skipped++;
                continue;
            }

            $affected = Database::execute(
                "DELETE FROM {$table} WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $recordId]
            );
            $affected > 0 ? $deleted++ : $skipped++;
        }

        Database::execute(
            "DELETE FROM workspace_sample_workflow_registry WHERE workspace_id = ?",
            [$workspaceId]
        );

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'removed_by_user_id' => $userId,
        ];
    }

    private function workspaceContext(): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $settings = (new InvoiceSettings())->get();
        $row = Database::queryOne(
            "SELECT communication_channel
             FROM workspace_onboarding_state
             WHERE workspace_id = ?
             LIMIT 1",
            [(int) (WorkspaceContext::currentWorkspaceId() ?? 0)]
        ) ?: [];

        $companyName = trim((string) ($profile['company_name'] ?? 'Your company')) ?: 'Your company';
        $offerName = trim((string) ($products[0]['name'] ?? 'Core offer')) ?: 'Core offer';
        $currency = strtoupper(trim((string) ($settings['default_currency'] ?? 'USD'))) ?: 'USD';

        return [
            'company_name' => $companyName,
            'offer_name' => $offerName,
            'currency' => $currency,
            'sample_amount' => 1200.00,
            'selected_channel' => (string) ($row['communication_channel'] ?? 'email'),
        ];
    }

    private function register(int $workspaceId, string $runId, string $tableName, int $recordId, string $recordKey, int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_sample_workflow_registry
                (workspace_id, sample_run_id, table_name, record_id, record_key, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                sample_run_id = VALUES(sample_run_id),
                table_name = VALUES(table_name),
                record_id = VALUES(record_id),
                created_by_user_id = VALUES(created_by_user_id),
                updated_at = NOW()",
            [$workspaceId, $runId, $tableName, $recordId, $recordKey, $userId]
        );
    }

    private function urlForRecord(string $recordKey, int $recordId): ?string
    {
        return match ($recordKey) {
            self::RECORD_CONTACT => 'contact_view.php?id=' . $recordId,
            self::RECORD_DEAL => 'deal_view.php?id=' . $recordId,
            self::RECORD_INVOICE => 'invoice_view.php?id=' . $recordId,
            self::RECORD_TASK => 'task_view.php?id=' . $recordId,
            default => null,
        };
    }

    private function tableReady(): bool
    {
        return $this->tableExists('workspace_sample_workflow_registry');
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
