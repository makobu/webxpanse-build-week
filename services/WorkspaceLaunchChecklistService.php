<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

class WorkspaceLaunchChecklistService
{
    public const SOURCE = 'post_onboarding_launch_checklist';

    public function ensureForWorkspace(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tasksTableReady()) {
            return ['created' => 0, 'existing' => 0, 'items' => []];
        }

        $existing = $this->existingTasksByKey($workspaceId);
        $created = 0;
        $items = [];
        $tasks = new Tasks();

        foreach ($this->definitions() as $definition) {
            $key = (string) $definition['key'];
            $task = $existing[$key] ?? null;
            if (!$task) {
                $taskId = $tasks->create([
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'assigned_to' => $userId,
                    'created_by' => $userId,
                    'actor_user_id' => $userId,
                    'status' => 'pending',
                    'priority' => $definition['priority'] ?? 'medium',
                    'metadata_json' => [
                        'source' => self::SOURCE,
                        'checklist_key' => $key,
                        'url' => $definition['url'],
                    ],
                ]);
                $task = [
                    'id' => $taskId,
                    'status' => 'pending',
                    'metadata_json' => json_encode([
                        'source' => self::SOURCE,
                        'checklist_key' => $key,
                        'url' => $definition['url'],
                    ], JSON_UNESCAPED_SLASHES),
                ];
                $created++;
            }

            $items[] = $this->decorateDefinition($definition, $task);
        }

        return [
            'created' => $created,
            'existing' => count($items) - $created,
            'items' => $items,
        ];
    }

    public function summary(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tasksTableReady()) {
            return ['visible' => false, 'items' => []];
        }

        $existing = $this->existingTasksByKey($workspaceId, true);
        $items = [];
        foreach ($this->definitions() as $definition) {
            $item = $this->decorateDefinition($definition, $existing[(string) $definition['key']] ?? null);
            if ((string) ($definition['key'] ?? '') === 'connect_inbox' && $this->communicationInboxReady($workspaceId)) {
                $item['complete'] = true;
                $item['virtual_complete'] = true;
            }
            if ((string) ($definition['key'] ?? '') === 'define_offer_pricing' && $this->offerPricingReady($workspaceId)) {
                $item['complete'] = true;
                $item['virtual_complete'] = true;
            }
            if ((string) ($definition['key'] ?? '') === 'add_first_customer_list' && $this->customerListReady($workspaceId)) {
                $item['complete'] = true;
                $item['virtual_complete'] = true;
            }
            $items[] = $item;
        }

        return [
            'visible' => true,
            'items' => $items,
            'open_count' => count(array_filter($items, static fn(array $item): bool => empty($item['complete']))),
            'complete_count' => count(array_filter($items, static fn(array $item): bool => !empty($item['complete']))),
        ];
    }

    public function definitions(): array
    {
        return [
            [
                'key' => 'complete_clarity_journey',
                'title' => 'Complete Clarity Journey',
                'description' => 'Finish the founder foundation so customer, problem, offer, pricing, and launch assumptions are visible.',
                'url' => 'startup_journey.php',
                'icon' => 'fa-solid fa-route',
                'priority' => 'urgent',
            ],
            [
                'key' => 'define_offer_pricing',
                'title' => 'Define offer and pricing',
                'description' => 'Save one specific first offer with pricing so outreach, quotes, and AI guidance have a real product to use.',
                'url' => 'settings.php?tab=products',
                'icon' => 'fa-solid fa-tags',
                'priority' => 'high',
            ],
            [
                'key' => 'add_first_customer_list',
                'title' => 'Add first customer list',
                'description' => 'Bring in the first prospects so Clarity can turn the launch idea into real follow-up work.',
                'url' => 'contacts.php',
                'icon' => 'fa-solid fa-address-book',
                'priority' => 'high',
            ],
            [
                'key' => 'send_first_outreach',
                'title' => 'Send first outreach',
                'description' => 'Send or draft the first offer message and create the next follow-up task before the lead goes quiet.',
                'url' => 'tasks.php',
                'icon' => 'fa-solid fa-paper-plane',
                'priority' => 'high',
            ],
            [
                'key' => 'connect_inbox',
                'title' => 'Connect inbox',
                'description' => 'Connect Email or WhatsApp when the first outreach motion is ready to capture real replies.',
                'url' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
                'icon' => 'fa-solid fa-inbox',
                'priority' => 'high',
            ],
            [
                'key' => 'start_weekly_review',
                'title' => 'Start weekly review',
                'description' => 'Use Founder Loop to record what happened, what was learned, and the next weekly commitments.',
                'url' => 'founder_operating_loop.php',
                'icon' => 'fa-solid fa-rotate',
                'priority' => 'medium',
            ],
        ];
    }

    private function existingTasksByKey(int $workspaceId, bool $includeCompleted = false): array
    {
        $statuses = $includeCompleted
            ? "'pending','in_progress','completed','cancelled'"
            : "'pending','in_progress'";
        $rows = Database::query(
            "SELECT id, title, status, metadata_json
             FROM tasks
             WHERE workspace_id = ?
               AND status IN ({$statuses})
               AND metadata_json IS NOT NULL
               AND metadata_json LIKE ?",
            [$workspaceId, '%' . self::SOURCE . '%']
        );

        $byKey = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
            if (!is_array($metadata) || ($metadata['source'] ?? '') !== self::SOURCE) {
                continue;
            }
            $key = (string) ($metadata['checklist_key'] ?? '');
            if ($key === '' || isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = $row;
        }

        return $byKey;
    }

    private function decorateDefinition(array $definition, ?array $task): array
    {
        $status = (string) ($task['status'] ?? 'pending');
        return array_merge($definition, [
            'task_id' => !empty($task['id']) ? (int) $task['id'] : null,
            'task_status' => $status,
            'complete' => $status === 'completed',
        ]);
    }

    private function customerListReady(int $workspaceId): bool
    {
        if ($workspaceId <= 0
            || !Database::tableExists('contacts')
            || !Database::columnExists('contacts', 'workspace_id')) {
            return false;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM contacts
                 WHERE workspace_id = ?",
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function communicationInboxReady(int $workspaceId): bool
    {
        try {
            $channels = (new WorkspaceChannelHealthService())->summarize($workspaceId);
        } catch (\Throwable $e) {
            return false;
        }

        foreach (['main_email', 'outreach_email', 'nurture_email', 'assistant_email', 'whatsapp'] as $channelKey) {
            if ((string) (($channels[$channelKey] ?? [])['status'] ?? '') === 'ready') {
                return true;
            }
        }

        return false;
    }

    private function offerPricingReady(int $workspaceId): bool
    {
        if ($workspaceId <= 0
            || !Database::tableExists('products')
            || !Database::columnExists('products', 'workspace_id')
            || !Database::columnExists('products', 'name')) {
            return false;
        }

        $pricingSignals = [];
        if (Database::columnExists('products', 'pricing_info')) {
            $pricingSignals[] = "TRIM(COALESCE(pricing_info, '')) <> ''";
        }
        if (Database::columnExists('products', 'unit_price')) {
            $pricingSignals[] = "COALESCE(unit_price, 0) > 0";
        }
        if ($pricingSignals === []) {
            return false;
        }

        $activeClause = Database::columnExists('products', 'is_active')
            ? ' AND COALESCE(is_active, 1) = 1'
            : '';

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM products
             WHERE workspace_id = ?
               AND TRIM(COALESCE(name, '')) <> ''
               AND (" . implode(' OR ', $pricingSignals) . ")
               {$activeClause}",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function tasksTableReady(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'"
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
