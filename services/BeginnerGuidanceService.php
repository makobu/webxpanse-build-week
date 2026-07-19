<?php

namespace CRM\Services;

use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\OutcomeMetrics;

class BeginnerGuidanceService
{
    public const CACHE_VERSION = 'v2';
    public const CACHE_TTL_SECONDS = 300;

    private CacheManager $cache;
    private OutcomeMetrics $outcomes;

    public function __construct(?CacheManager $cache = null, ?OutcomeMetrics $outcomes = null)
    {
        $this->cache = $cache ?: new CacheManager();
        $this->outcomes = $outcomes ?: new OutcomeMetrics();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function guidanceFor(int $workspaceId, int $userId, array $context = []): array
    {
        $mode = $this->normalizeMode((string) ($context['mode'] ?? UIExperienceService::MODE_BEGINNER));
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $cacheKey = 'beginner_guidance:' . self::CACHE_VERSION . ':' . $workspaceId . ':' . $userId . ':' . $mode;
        $skipCache = !empty($context['skip_cache']);

        if (!$skipCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $this->payloadForMode(
            $this->buildBeginnerPayload($workspaceId, $userId),
            $mode
        );

        if (!$skipCache) {
            $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function fallbackPayload(int $userId, string $mode = UIExperienceService::MODE_BEGINNER, int $workspaceId = 0): array
    {
        $focus = [];
        try {
            $focus = $this->outcomes->getTodayRevenueFocus($userId);
        } catch (\Throwable $e) {
            $focus = [];
        }

        $label = trim((string) ($focus[0] ?? 'Add your first customer'));
        $hints = array_slice(array_values(array_filter(array_map(
            static fn($item): string => trim((string) $item),
            array_slice($focus, 1, 2)
        ))), 0, 2);

        return $this->payloadForMode(
            $this->payload(
                UIExperienceService::MODE_BEGINNER,
                $this->fallbackActionForLabel($label !== '' ? $label : 'Add your first customer', max(0, $workspaceId), max(0, $userId)),
                $this->hintActions($hints),
                [],
                'outcome_metrics'
            ),
            $mode
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBeginnerPayload(int $workspaceId, int $userId): array
    {
        $actions = [];
        $blockedBy = [];

        $waitingInbound = $this->waitingInbound($workspaceId);
        if ($waitingInbound !== null) {
            $actions[] = $this->action(
                'reply_to_customer',
                'Reply to one waiting customer',
                'A waiting reply is the fastest way to protect revenue today.',
                'conversation.php?id=' . (int) ($waitingInbound['id'] ?? 0),
                'Open conversation',
                'communication',
                'Can draft replies',
                true
            );
        }

        $dueTask = $this->dueFollowupTask($workspaceId, $userId);
        if ($dueTask !== null) {
            $actions[] = $this->action(
                'finish_due_followup',
                'Finish today\'s follow-up',
                'A promised follow-up keeps the customer moving.',
                'task_view.php?id=' . (int) ($dueTask['id'] ?? 0),
                'Open task',
                'tasks',
                'Needs your action',
                false
            );
        }

        $quietDeal = $this->quietDeal($workspaceId, $userId);
        if ($quietDeal !== null) {
            $actions[] = $this->action(
                'follow_up_quiet_deal',
                'Follow up a quiet deal',
                'This deal has gone quiet for more than a week.',
                'deal_view.php?id=' . (int) ($quietDeal['id'] ?? 0),
                'Open deal',
                'deals',
                'Needs your action',
                false
            );
        }

        $openDeal = $this->openDeal($workspaceId, $userId);
        $invoiceReady = $this->invoiceReady();
        if ($openDeal !== null && $invoiceReady) {
            $actions[] = $this->action(
                'prepare_invoice',
                'Prepare a quote or invoice',
                'There is active work that may be ready for a money step.',
                'invoice_create.php?document_type=invoice&deal_id=' . (int) ($openDeal['id'] ?? 0),
                'Create invoice',
                'money',
                'Ready to create invoices',
                false
            );
        } elseif ($openDeal !== null) {
            $blockedBy[] = 'invoice_settings';
        }

        $contactCount = $this->countRows('contacts', $workspaceId);
        if ($contactCount === 0) {
            $actions[] = $this->action(
                'add_first_customer',
                'Add your first customer',
                'Start with one real customer so tasks, replies, and invoices have somewhere to connect.',
                'contacts_create.php',
                'Add customer',
                'customers',
                null,
                false
            );
        }

        if (!$this->channelConnected($workspaceId, $userId)) {
            $blockedBy[] = 'channel';
            $actions[] = $this->action(
                'connect_channel',
                'Connect a channel so replies can be drafted',
                'Connect email or WhatsApp before the system can help with customer replies.',
                'settings.php?tab=email',
                'Open channel settings',
                'setup',
                'Blocked until a channel is connected',
                false
            );
        }

        if ($openDeal !== null && !$invoiceReady) {
            $actions[] = $this->action(
                'finish_invoice_settings',
                'Finish invoice settings',
                'Invoice settings are needed before quotes and invoices are ready.',
                'settings.php?tab=invoicing',
                'Open invoice settings',
                'money',
                'Needs invoice settings first',
                false
            );
        }

        if (empty($actions)) {
            $actions[] = $this->action(
                'add_capabilities_when_blocked',
                'Add capabilities when you are ready',
                'Only add modules when a missing capability blocks the next action.',
                'workspace_skills.php',
                'Add capabilities',
                'guided',
                null,
                false
            );
        }

        $primary = array_shift($actions);
        return $this->payload(
            UIExperienceService::MODE_BEGINNER,
            $primary ?: $this->fallbackPayload($userId, UIExperienceService::MODE_BEGINNER, $workspaceId)['primary_action'],
            array_slice($actions, 0, 2),
            array_values(array_unique($blockedBy)),
            'deterministic'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadForMode(array $payload, string $mode): array
    {
        $mode = $this->normalizeMode($mode);
        $payload['mode'] = $mode;

        if ($mode !== UIExperienceService::MODE_ADVANCED) {
            return $payload;
        }

        unset($payload['primary_action']['readiness_label'], $payload['primary_action']['requires_approval']);
        $payload['secondary_hints'] = array_map(static function (array $hint): array {
            unset($hint['readiness_label'], $hint['requires_approval']);
            return $hint;
        }, (array) ($payload['secondary_hints'] ?? []));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackActionForLabel(string $label, int $workspaceId, int $userId): array
    {
        $normalized = strtolower($label);
        $reason = 'This is the fastest available revenue step from today\'s dashboard signals.';

        if (str_contains($normalized, 'quiet') || str_contains($normalized, 'stale')) {
            $deal = $workspaceId > 0 ? $this->quietDeal($workspaceId, $userId) : null;
            return $this->action(
                'follow_up_quiet_deal',
                $label,
                $reason,
                $deal ? 'deal_view.php?id=' . (int) ($deal['id'] ?? 0) : 'deals.php',
                $deal ? 'Open deal' : 'Open deals',
                'deals',
                null,
                false
            );
        }

        if (str_contains($normalized, 'task') || str_contains($normalized, 'follow up') || str_contains($normalized, 'follow-up')) {
            $task = $workspaceId > 0 ? $this->dueFollowupTask($workspaceId, $userId) : null;
            return $this->action(
                'finish_due_followup',
                $label,
                $reason,
                $task ? 'task_view.php?id=' . (int) ($task['id'] ?? 0) : 'tasks.php',
                $task ? 'Open task' : 'Open tasks',
                'tasks',
                null,
                false
            );
        }

        if (str_contains($normalized, 'invoice') || str_contains($normalized, 'quote')) {
            $deal = $workspaceId > 0 ? $this->openDeal($workspaceId, $userId) : null;
            $href = ($deal && $this->invoiceReady())
                ? 'invoice_create.php?document_type=invoice&deal_id=' . (int) ($deal['id'] ?? 0)
                : 'invoices.php';

            return $this->action(
                'prepare_invoice',
                $label,
                $reason,
                $href,
                $deal && $this->invoiceReady() ? 'Create invoice' : 'Open invoices',
                'money',
                null,
                false
            );
        }

        if (str_contains($normalized, 'deal') || str_contains($normalized, 'pipeline') || str_contains($normalized, 'worth')) {
            $deal = $workspaceId > 0 ? $this->openDeal($workspaceId, $userId) : null;
            return $this->action(
                'check_open_deals',
                $label,
                $reason,
                $deal ? 'deal_view.php?id=' . (int) ($deal['id'] ?? 0) : 'deals.php',
                $deal ? 'Open deal' : 'Open deals',
                'deals',
                null,
                false
            );
        }

        if (str_contains($normalized, 'customer') || str_contains($normalized, 'contact') || str_contains($normalized, 'lead')) {
            $importOnly = str_contains($normalized, 'import') && !str_contains($normalized, 'add');
            return $this->action(
                'add_first_customer',
                $label,
                $reason,
                $importOnly ? 'contacts_import.php' : 'contacts_create.php',
                $importOnly ? 'Import contacts' : 'Add customer',
                'customers',
                null,
                false
            );
        }

        return $this->action(
            'outcome_focus',
            $label,
            $reason,
            null,
            null,
            'guided',
            null,
            false
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function waitingInbound(int $workspaceId): ?array
    {
        if (!Database::tableExists('communications')) {
            return null;
        }

        $conditions = ["direction = 'inbound'"];
        $params = [];
        $this->addWorkspaceCondition('communications', $workspaceId, $conditions, $params);
        if (Database::columnExists('communications', 'read_at')) {
            $conditions[] = 'read_at IS NULL';
        }

        return $this->firstRow(
            'SELECT id, contact_id, channel
             FROM communications
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            $params
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dueFollowupTask(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('tasks')) {
            return null;
        }

        $conditions = ["status NOT IN ('completed','cancelled')", 'due_date IS NOT NULL', 'DATE(due_date) <= CURDATE()'];
        $params = [];
        $this->addWorkspaceCondition('tasks', $workspaceId, $conditions, $params);
        $this->addUserCondition('tasks', $userId, $conditions, $params);

        return $this->firstRow(
            'SELECT id, title, due_date
             FROM tasks
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY due_date ASC, id ASC
             LIMIT 1',
            $params
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function quietDeal(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('deals')) {
            return null;
        }

        $conditions = ["stage NOT IN ('closed_won','closed_lost')", 'updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'];
        $params = [];
        $this->addWorkspaceCondition('deals', $workspaceId, $conditions, $params);
        $this->addUserCondition('deals', $userId, $conditions, $params);

        return $this->firstRow(
            'SELECT id, title, value, updated_at
             FROM deals
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY updated_at ASC, id ASC
             LIMIT 1',
            $params
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function openDeal(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('deals')) {
            return null;
        }

        $conditions = ["stage NOT IN ('closed_won','closed_lost')"];
        $params = [];
        $this->addWorkspaceCondition('deals', $workspaceId, $conditions, $params);
        $this->addUserCondition('deals', $userId, $conditions, $params);

        return $this->firstRow(
            'SELECT id, title, value, updated_at
             FROM deals
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            $params
        );
    }

    private function invoiceReady(): bool
    {
        if (!Database::tableExists('invoice_settings')) {
            return false;
        }

        try {
            $settings = (new InvoiceSettings())->get();
            return !empty($settings['enabled']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function channelConnected(int $workspaceId, int $userId): bool
    {
        if (Database::tableExists('communications')) {
            $conditions = [];
            $params = [];
            $this->addWorkspaceCondition('communications', $workspaceId, $conditions, $params);
            $where = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
            if ($this->countSql('SELECT COUNT(*) AS c FROM communications' . $where, $params) > 0) {
                return true;
            }
        }

        if (Database::tableExists('email_integrations')) {
            $conditions = ["is_active = 1", "scope IN ('outreach_email', 'nurture_email')"];
            $params = [];
            $this->addWorkspaceCondition('email_integrations', $workspaceId, $conditions, $params);
            if ($this->countSql('SELECT COUNT(*) AS c FROM email_integrations WHERE ' . implode(' AND ', $conditions), $params) > 0) {
                return true;
            }
        }

        if (Database::tableExists('workspace_whatsapp_integrations')) {
            $conditions = ["connection_status = 'connected'"];
            $params = [];
            $this->addWorkspaceCondition('workspace_whatsapp_integrations', $workspaceId, $conditions, $params);
            if ($this->countSql('SELECT COUNT(*) AS c FROM workspace_whatsapp_integrations WHERE ' . implode(' AND ', $conditions), $params) > 0) {
                return true;
            }
        }

        if (Database::tableExists('activation_progress') && $userId > 0) {
            $row = $this->firstRow(
                'SELECT connected_channel_at
                 FROM activation_progress
                 WHERE user_id = ? AND connected_channel_at IS NOT NULL
                 LIMIT 1',
                [$userId]
            );
            if ($row !== null) {
                return true;
            }
        }

        return false;
    }

    private function countRows(string $table, int $workspaceId): int
    {
        if (!Database::tableExists($table)) {
            return 0;
        }

        $conditions = [];
        $params = [];
        $this->addWorkspaceCondition($table, $workspaceId, $conditions, $params);
        $where = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

        return $this->countSql('SELECT COUNT(*) AS c FROM ' . $table . $where, $params);
    }

    /**
     * @param array<int,string> $conditions
     * @param array<int,mixed> $params
     */
    private function addWorkspaceCondition(string $table, int $workspaceId, array &$conditions, array &$params): void
    {
        if ($workspaceId > 0 && Database::columnExists($table, 'workspace_id')) {
            $conditions[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        }
    }

    /**
     * @param array<int,string> $conditions
     * @param array<int,mixed> $params
     */
    private function addUserCondition(string $table, int $userId, array &$conditions, array &$params): void
    {
        if ($userId <= 0) {
            return;
        }

        $clauses = [];
        if (Database::columnExists($table, 'assigned_to')) {
            $clauses[] = 'assigned_to = ?';
            $params[] = $userId;
        }
        if (Database::columnExists($table, 'created_by')) {
            $clauses[] = 'created_by = ?';
            $params[] = $userId;
        }

        if ($clauses) {
            $conditions[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private function firstRow(string $sql, array $params = []): ?array
    {
        try {
            return Database::queryOne($sql, $params);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<int,mixed> $params
     */
    private function countSql(string $sql, array $params = []): int
    {
        try {
            $row = Database::queryOne($sql, $params) ?: [];
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $secondaryHints
     * @param array<int,string> $blockedBy
     * @return array<string,mixed>
     */
    private function payload(string $mode, array $primaryAction, array $secondaryHints, array $blockedBy, string $source): array
    {
        return [
            'mode' => $mode,
            'primary_action' => $primaryAction,
            'secondary_hints' => array_values(array_slice($secondaryHints, 0, 2)),
            'blocked_by' => array_values($blockedBy),
            'source' => $source,
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function action(
        string $key,
        string $label,
        string $reason,
        ?string $href,
        ?string $ctaLabel,
        string $category,
        ?string $readinessLabel,
        bool $requiresApproval
    ): array {
        $action = [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
            'category' => $category,
        ];

        $href = trim((string) $href);
        $ctaLabel = trim((string) $ctaLabel);
        if ($href !== '') {
            $action['href'] = $href;
        }
        if ($ctaLabel !== '') {
            $action['cta_label'] = $ctaLabel;
        }

        if ($readinessLabel !== null && $readinessLabel !== '') {
            $action['readiness_label'] = $readinessLabel;
        }
        if ($requiresApproval) {
            $action['requires_approval'] = true;
        }

        return $action;
    }

    /**
     * @param array<int,string> $hints
     * @return array<int,array<string,mixed>>
     */
    private function hintActions(array $hints): array
    {
        return array_map(function (string $hint): array {
            return $this->action(
                'hint',
                $hint,
                '',
                null,
                null,
                'guided',
                null,
                false
            );
        }, $hints);
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === UIExperienceService::MODE_ADVANCED
            ? UIExperienceService::MODE_ADVANCED
            : UIExperienceService::MODE_BEGINNER;
    }
}
