<?php

namespace CRM\Services;

use CRM\CacheManager;

class BeginnerWorkSurfaceGuidanceService
{
    public const CACHE_VERSION = 'v1';
    public const CACHE_TTL_SECONDS = 300;

    private CacheManager $cache;

    public function __construct(?CacheManager $cache = null)
    {
        $this->cache = $cache ?: new CacheManager();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function guidanceFor(int $workspaceId, int $userId, array $context = []): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $mode = $this->normalizeMode((string) ($context['mode'] ?? UIExperienceService::MODE_BEGINNER));
        $surface = $this->normalizeKey((string) ($context['surface'] ?? ''));
        $source = $this->normalizeSource((string) ($context['source'] ?? 'direct'));
        $action = $this->normalizeKey((string) ($context['action'] ?? ''));
        $gap = $this->normalizeKey((string) ($context['gap'] ?? ''));
        $currentPage = $this->normalizePage((string) ($context['current_page'] ?? ($surface !== '' ? $surface . '.php' : '')));
        $stateSignature = $this->stateSignature($context);
        $skipCache = !empty($context['skip_cache']);
        $cacheKey = implode(':', [
            'work_surface_guidance',
            self::CACHE_VERSION,
            $workspaceId,
            $userId,
            $mode,
            $surface !== '' ? $surface : '-',
            $source,
            $action !== '' ? $action : '-',
            $gap !== '' ? $gap : '-',
            $stateSignature,
        ]);

        if (!$skipCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $mode === UIExperienceService::MODE_BEGINNER
            ? $this->buildBeginnerPayload($surface, $source, $action, $gap, $currentPage, $context)
            : $this->advancedPayload($surface, $source);

        if (!$skipCache) {
            $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildBeginnerPayload(
        string $surface,
        string $source,
        string $action,
        string $gap,
        string $currentPage,
        array $context
    ): array {
        $payload = match ($surface) {
            'inbox' => $this->inboxGuidance($context),
            'conversation' => $this->conversationGuidance($context),
            'tasks' => $this->tasksGuidance($context),
            'task' => $this->taskDetailGuidance($context),
            'contacts' => $this->contactsGuidance($context, $action),
            'contact_create' => $this->contactCreateGuidance($context),
            'deals' => $this->dealsGuidance($context),
            'deal' => $this->dealDetailGuidance($context),
            'invoices' => $this->invoicesGuidance($context),
            'invoice_create' => $this->invoiceCreateGuidance($context),
            default => $this->blankPayload($surface, $source),
        };

        $payload['mode'] = UIExperienceService::MODE_BEGINNER;
        $payload['surface'] = $surface;
        $payload['source'] = $source;
        $payload['action'] = $action;
        $payload['gap'] = $gap;
        $payload['generated_at'] = gmdate('c');
        $payload['cache_ttl_seconds'] = self::CACHE_TTL_SECONDS;

        return $this->stripInvalidAction($payload, $currentPage);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function inboxGuidance(array $context): array
    {
        $waiting = $this->firstRow((array) ($context['communications'] ?? []), static function (array $row): bool {
            return (string) ($row['direction'] ?? '') === 'inbound' && empty($row['read_at']);
        });

        if ($waiting !== null) {
            return $this->payload(
                'Reply to this customer',
                'A waiting reply is the fastest way to protect revenue today.',
                'Needs your reply',
                $this->action(
                    'reply_to_customer',
                    'Open conversation',
                    'conversation.php?id=' . (int) ($waiting['id'] ?? 0)
                ),
                'AI can help draft after you open the conversation.'
            );
        }

        $anyInbound = $this->firstRow((array) ($context['communications'] ?? []), static function (array $row): bool {
            return (string) ($row['direction'] ?? '') === 'inbound';
        });
        if ($anyInbound !== null) {
            return $this->payload(
                'Review customer messages',
                'No unread customer message is waiting, so scan recent conversations for the next promise.',
                'No waiting replies',
                $this->action(
                    'review_recent_conversation',
                    'Open recent conversation',
                    'conversation.php?id=' . (int) ($anyInbound['id'] ?? 0)
                )
            );
        }

        return $this->payload(
            'Connect a channel so replies arrive here',
            'Inbox guidance becomes useful after email, WhatsApp, or SMS is connected.',
            'No messages yet',
            $this->action(
                'connect_channel',
                'Connect channel',
                    'workspace_skills.php?module=email&setup_tab=outreach_email#setup'
            )
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function conversationGuidance(array $context): array
    {
        $communication = (array) ($context['communication'] ?? []);
        $channel = trim((string) ($communication['channel'] ?? ''));
        $isInbound = (string) ($communication['direction'] ?? '') === 'inbound';

        return $this->payload(
            $isInbound ? 'Reply to this customer' : 'Review this conversation',
            $isInbound
                ? 'Use the reply box below, then send only when you are happy with the wording.'
                : 'Check the thread and decide whether another follow-up is needed.',
            $channel !== '' ? ucfirst($channel) . ' conversation' : 'Conversation ready',
            [],
            $isInbound ? 'Use AI draft buttons only after you choose to write the reply.' : ''
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function tasksGuidance(array $context): array
    {
        $groups = (array) ($context['task_groups'] ?? []);
        $task = $this->firstTaskFromGroups($groups, ['overdue', 'due_soon', 'no_due_date']);
        if ($task !== null) {
            $overdue = !empty($task['due_date']) && strtotime((string) $task['due_date']) < time();
            return $this->payload(
                $overdue ? 'Finish this overdue follow-up' : 'Finish the next follow-up',
                'Clearing one task keeps the customer promise moving.',
                $overdue ? 'Overdue' : 'Needs your action',
                $this->action(
                    'finish_task',
                    'Open task',
                    'task_view.php?id=' . (int) ($task['id'] ?? 0)
                )
            );
        }

        if (!empty($context['can_write_tasks'])) {
            return $this->payload(
                'Create one clear follow-up',
                'A simple dated task is enough to keep the next customer commitment visible.',
                'No open tasks',
                $this->action('create_task', 'Create task', 'task_create.php')
            );
        }

        return $this->payload(
            'Task queue is clear',
            'There is no open task in this view.',
            'Nothing due',
            []
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function taskDetailGuidance(array $context): array
    {
        $task = (array) ($context['task'] ?? []);
        $status = (string) ($task['status'] ?? '');
        $isClosed = in_array($status, ['completed', 'cancelled'], true);

        return $this->payload(
            $isClosed ? 'Review this completed task' : 'Finish this follow-up',
            $isClosed
                ? 'Use the notes and evidence below to understand what happened.'
                : 'Update the task, add a note if needed, then mark it done when the work is complete.',
            $isClosed ? 'Completed' : 'Needs your action',
            []
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function contactsGuidance(array $context, string $actionKey): array
    {
        $total = (int) ($context['total_count'] ?? count((array) ($context['contacts'] ?? [])));
        if ($total <= 0 || $actionKey === 'add_first_customer') {
            return $this->payload(
                'Add your first customer',
                'Start with one real customer so replies, tasks, deals, and invoices have somewhere to connect.',
                'Customer list is empty',
                $this->action('add_first_customer', 'Add customer', 'contacts_create.php?source=work_surface&action=add_first_customer')
            );
        }

        return $this->payload(
            'Keep the customer list useful',
            'Add one customer at a time, or use filters when you need to find a specific relationship.',
            $total . ' customer' . ($total === 1 ? '' : 's') . ' visible',
            $this->action('add_customer', 'Add customer', 'contacts_create.php?source=work_surface&action=add_customer')
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function contactCreateGuidance(array $context): array
    {
        $isLead = !empty($context['is_lead_mode']);
        return $this->payload(
            $isLead ? 'Add one lead' : 'Add one customer',
            $isLead
                ? 'Capture the person or business you want to follow up today.'
                : 'Add the basics first. You can enrich the record later.',
            'Ready to save',
            []
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function dealsGuidance(array $context): array
    {
        $deal = $this->firstOpenDeal($context);
        if ($deal !== null) {
            return $this->payload(
                'Move one deal forward',
                'Open a live deal and choose the next practical step.',
                'Pipeline has active work',
                $this->action(
                    'move_deal_forward',
                    'Open deal',
                    'deal_view.php?id=' . (int) ($deal['id'] ?? 0)
                )
            );
        }

        return $this->payload(
            'Create your first job or deal',
            'A deal gives customer work a value, stage, and next step.',
            'Pipeline is empty',
            $this->action('create_deal', 'Create deal', 'deal_create.php')
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function dealDetailGuidance(array $context): array
    {
        $deal = (array) ($context['deal'] ?? []);
        $dealId = (int) ($deal['id'] ?? 0);
        $stage = (string) ($deal['stage'] ?? '');
        if ($dealId > 0 && !in_array($stage, ['closed_won', 'closed_lost'], true)) {
            return $this->payload(
                'Choose the next deal step',
                'If the customer is ready for pricing, create the quote or invoice from here.',
                'Deal is active',
                $this->action(
                    'prepare_invoice',
                    'Create invoice',
                    'invoice_create.php?deal_id=' . $dealId . '&document_type=invoice&source=work_surface&action=prepare_invoice'
                )
            );
        }

        return $this->payload(
            'Review this deal',
            'Use notes, documents, and invoices below to understand what happened.',
            'Deal is closed',
            []
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function invoicesGuidance(array $context): array
    {
        if (empty($context['invoice_ready'])) {
            return $this->payload(
                'Set up invoices',
                'Payment details are needed before quotes and invoices are ready for daily work.',
                'Needs invoice settings first',
                $this->action(
                    'finish_invoice_settings',
                    'Set up invoices',
                    'workspace_skills.php?module=finance#setup'
                )
            );
        }

        $draft = $this->firstRow((array) ($context['invoices'] ?? []), static function (array $row): bool {
            return (string) ($row['status'] ?? '') === 'draft';
        });
        if ($draft !== null) {
            return $this->payload(
                'Finish a draft invoice',
                'A draft document is already started. Finish it before creating another one.',
                'Draft waiting',
                $this->action(
                    'finish_draft_invoice',
                    'Open draft',
                    'invoice_view.php?id=' . (int) ($draft['id'] ?? 0)
                )
            );
        }

        return $this->payload(
            'Create the next invoice',
            'Use invoices when the customer is ready for a money step.',
            'Ready to create invoices',
            $this->action(
                'create_invoice',
                'New invoice',
                'invoice_create.php?document_type=invoice&source=work_surface&action=create_invoice'
            )
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function invoiceCreateGuidance(array $context): array
    {
        $type = (string) ($context['document_type'] ?? 'invoice');
        $label = $type === 'quote' ? 'quote' : 'invoice';
        return $this->payload(
            'Create this ' . $label,
            'Choose the customer, add line items, confirm dates, then save the document.',
            'Ready to create invoices',
            []
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function advancedPayload(string $surface, string $source): array
    {
        return [
            'mode' => UIExperienceService::MODE_ADVANCED,
            'surface' => $surface,
            'source' => $source,
            'show_guidance' => false,
            'primary_action' => [],
            'generated_at' => gmdate('c'),
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blankPayload(string $surface, string $source): array
    {
        return [
            'mode' => UIExperienceService::MODE_BEGINNER,
            'surface' => $surface,
            'source' => $source,
            'show_guidance' => false,
            'primary_action' => [],
        ];
    }

    /**
     * @param array<string,mixed> $primaryAction
     * @return array<string,mixed>
     */
    private function payload(
        string $goal,
        string $reason,
        string $statusLabel,
        array $primaryAction = [],
        string $secondaryHint = ''
    ): array {
        return [
            'show_guidance' => true,
            'goal' => $goal,
            'reason' => $reason,
            'status_label' => $statusLabel,
            'primary_action' => $primaryAction,
            'secondary_hint' => $secondaryHint,
            'source_type' => 'deterministic',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function action(string $key, string $label, string $href): array
    {
        return [
            'key' => $this->normalizeKey($key),
            'label' => $label,
            'href' => $href,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stripInvalidAction(array $payload, string $currentPage): array
    {
        $action = (array) ($payload['primary_action'] ?? []);
        $href = trim((string) ($action['href'] ?? ''));
        $label = trim((string) ($action['label'] ?? ''));
        if ($href === '' || $label === '' || $this->hrefPointsToCurrentPage($href, $currentPage)) {
            $payload['primary_action'] = [];
            return $payload;
        }

        $payload['primary_action'] = $action;
        return $payload;
    }

    private function hrefPointsToCurrentPage(string $href, string $currentPage): bool
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        return strtolower(basename($path)) === strtolower($currentPage);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function firstRow(array $rows, callable $predicate): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && $predicate($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $groups
     * @param array<int,string> $order
     * @return array<string,mixed>|null
     */
    private function firstTaskFromGroups(array $groups, array $order): ?array
    {
        foreach ($order as $key) {
            $items = (array) ($groups[$key]['items'] ?? []);
            foreach ($items as $task) {
                if (is_array($task)) {
                    return $task;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function firstOpenDeal(array $context): ?array
    {
        foreach ((array) ($context['deals'] ?? []) as $deal) {
            if (!is_array($deal)) {
                continue;
            }
            if (!in_array((string) ($deal['stage'] ?? ''), ['closed_won', 'closed_lost'], true)) {
                return $deal;
            }
        }

        foreach ((array) ($context['deals_by_stage'] ?? []) as $deals) {
            foreach ((array) $deals as $deal) {
                if (is_array($deal) && !in_array((string) ($deal['stage'] ?? ''), ['closed_won', 'closed_lost'], true)) {
                    return $deal;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function stateSignature(array $context): string
    {
        $parts = [];
        foreach (['communications', 'tasks', 'contacts', 'deals', 'invoices'] as $key) {
            $rows = (array) ($context[$key] ?? []);
            $parts[] = $key . ':' . count($rows) . ':' . $this->firstIds($rows);
        }
        $parts[] = 'task_groups:' . $this->groupSignature((array) ($context['task_groups'] ?? []));
        $parts[] = 'deals_by_stage:' . $this->groupSignature((array) ($context['deals_by_stage'] ?? []));
        $parts[] = 'total:' . (int) ($context['total_count'] ?? 0);
        $parts[] = 'invoice:' . (!empty($context['invoice_ready']) ? '1' : '0');
        $parts[] = 'write_tasks:' . (!empty($context['can_write_tasks']) ? '1' : '0');

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function firstIds(array $rows): string
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids[] = (string) ($row['id'] ?? '-');
            if (count($ids) >= 3) {
                break;
            }
        }

        return implode(',', $ids);
    }

    /**
     * @param array<string,mixed> $groups
     */
    private function groupSignature(array $groups): string
    {
        $parts = [];
        foreach ($groups as $key => $group) {
            $items = is_array($group) && array_key_exists('items', $group)
                ? (array) ($group['items'] ?? [])
                : (array) $group;
            $parts[] = (string) $key . ':' . count($items) . ':' . $this->firstIds($items);
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === UIExperienceService::MODE_ADVANCED
            ? UIExperienceService::MODE_ADVANCED
            : UIExperienceService::MODE_BEGINNER;
    }

    private function normalizeSource(string $source): string
    {
        $source = $this->normalizeKey($source);
        return in_array($source, ['dashboard_guidance', 'dashboard_readiness', 'marketplace', 'setup_destination', 'work_surface', 'direct'], true)
            ? $source
            : 'direct';
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\\-]+/', '_', $key) ?: '';
        return trim($key, '_-');
    }

    private function normalizePage(string $page): string
    {
        $page = trim($page);
        if ($page === '') {
            return '';
        }

        return basename(parse_url($page, PHP_URL_PATH) ?: $page);
    }
}
