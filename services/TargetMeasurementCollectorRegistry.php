<?php

namespace CRM\Services;

use CRM\Database;

class TargetMeasurementCollectorRegistry
{
    private TargetPluginIntegrationService $plugins;

    public function __construct(?TargetPluginIntegrationService $plugins = null)
    {
        $this->plugins = $plugins ?? new TargetPluginIntegrationService();
    }

    public function collect(array $target, array $definition, bool $withEvidence = true): array
    {
        $source = strtolower(trim((string) ($target['rollup_source'] ?? $definition['source'] ?? '')));
        $metric = strtolower(trim((string) ($target['rollup_metric'] ?? $definition['metric'] ?? '')));
        $config = $this->config($source, $metric);
        if (!$config) {
            return $this->plugins->computePluginRollup($target, ['source' => $source, 'metric' => $metric] + $definition, $withEvidence)
                ?? $this->emptyResult($source, $metric, ['Unsupported measurement source or metric.']);
        }

        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return $this->emptyResult($source, $metric, ['A workspace is required.']);
        }
        [$where, $params] = $this->baseWhere($target, $config, $workspaceId);
        $missing = [];
        $conflicts = [];
        $currency = strtoupper(trim((string) ($target['currency_code'] ?? '')));

        if (!empty($config['monetary'])) {
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $missing[] = 'Select a three-letter currency for this monetary target.';
                return $this->emptyResult($source, $metric, $missing);
            }
            $currencyRows = Database::query(
                "SELECT DISTINCT UPPER({$config['currency']}) AS currency_code {$config['from']} WHERE " . implode(' AND ', $where),
                $params
            );
            $otherCurrencies = array_values(array_filter(array_map(
                static fn(array $row): string => (string) ($row['currency_code'] ?? ''),
                $currencyRows
            ), static fn(string $value): bool => $value !== '' && $value !== $currency));
            if ($otherCurrencies !== []) {
                $conflicts[] = 'Matching records include other currencies: ' . implode(', ', array_unique($otherCurrencies)) . '.';
            }
            $where[] = "UPPER({$config['currency']}) = ?";
            $params[] = $currency;
        }

        $aggregate = !empty($config['sum']) ? "COALESCE(SUM({$config['sum']}), 0)" : 'COUNT(*)';
        $row = Database::queryOne(
            "SELECT {$aggregate} AS value, COUNT(*) AS matching_count {$config['from']} WHERE " . implode(' AND ', $where),
            $params
        ) ?: [];
        $evidence = [];
        if ($withEvidence) {
            $evidenceRows = Database::query(
                "SELECT {$config['id']} AS entity_id, {$config['label']} AS evidence_label, {$config['status']} AS evidence_status,
                        {$config['date']} AS observed_at, " . (!empty($config['sum']) ? "{$config['sum']}" : '1') . " AS contribution_value" .
                        (!empty($config['currency']) ? ", {$config['currency']} AS evidence_currency" : ", NULL AS evidence_currency") .
                 " {$config['from']} WHERE " . implode(' AND ', $where) . " ORDER BY {$config['date']} DESC, {$config['id']} DESC LIMIT 20",
                $params
            );
            foreach ($evidenceRows as $item) {
                $fingerprint = hash('sha256', implode('|', [
                    $workspaceId,
                    (int) ($target['id'] ?? 0),
                    $source,
                    $metric,
                    (int) ($item['entity_id'] ?? 0),
                    (string) ($item['observed_at'] ?? ''),
                    (string) ($item['contribution_value'] ?? '0'),
                ]));
                $evidence[] = [
                    'collector' => $source . ':' . $metric,
                    'entity_type' => (string) $config['entity_type'],
                    'entity_id' => (int) ($item['entity_id'] ?? 0),
                    'label' => trim((string) ($item['evidence_label'] ?? '')) ?: ucfirst((string) $config['entity_type']) . ' #' . (int) ($item['entity_id'] ?? 0),
                    'status' => (string) ($item['evidence_status'] ?? ''),
                    'observed_at' => (string) ($item['observed_at'] ?? ''),
                    'value' => (float) ($item['contribution_value'] ?? 0),
                    'currency' => strtoupper((string) ($item['evidence_currency'] ?? '')) ?: null,
                    'fingerprint' => $fingerprint,
                    'url' => $this->entityUrl((string) $config['entity_type'], (int) ($item['entity_id'] ?? 0)),
                ];
            }
        }

        return [
            'value' => round((float) ($row['value'] ?? 0), 2),
            'matching_count' => (int) ($row['matching_count'] ?? 0),
            'label' => ucwords(str_replace('_', ' ', $source . ' ' . $metric)),
            'source' => $source,
            'metric' => $metric,
            'currency' => $currency ?: null,
            'calculated_at' => date('Y-m-d H:i:s'),
            'explanation' => $this->explanation($target, $source, $metric, $currency),
            'evidence' => $evidence,
            'missing_configuration' => $missing,
            'conflicts' => $conflicts,
        ];
    }

    private function baseWhere(array $target, array $config, int $workspaceId): array
    {
        $where = ["{$config['workspace']} = ?", "({$config['condition']})"];
        $params = [$workspaceId];
        $window = (string) ($target['rollup_window'] ?? 'target_period');
        if ($window !== 'lifetime') {
            $startDate = !empty($target['start_date']) ? (string) $target['start_date'] : substr((string) ($target['created_at'] ?? date('Y-m-d')), 0, 10);
            $start = $window === 'since_creation'
                ? (string) ($target['created_at'] ?? ($startDate . ' 00:00:00'))
                : $startDate . ' 00:00:00';
            $where[] = "{$config['date']} >= ?";
            $params[] = $start;
            if ($window === 'target_period' && !empty($target['target_date'])) {
                $where[] = "{$config['date']} <= ?";
                $params[] = (string) $target['target_date'] . ' 23:59:59';
            }
        }
        if ((string) ($target['scope'] ?? 'personal') === 'personal' && !empty($config['personal'])) {
            $where[] = '(' . $config['personal'] . ')';
            $owner = (int) ($target['user_id'] ?? 0);
            $placeholderCount = substr_count((string) $config['personal'], '?');
            for ($i = 0; $i < $placeholderCount; $i++) {
                $params[] = $owner;
            }
        }
        return [$where, $params];
    }

    private function config(string $source, string $metric): ?array
    {
        $configs = [
            'deals:closed_won_count' => $this->deal("d.stage = 'closed_won'", false, 'COALESCE(d.actual_close_date, d.updated_at, d.created_at)'),
            'deals:open_count' => $this->deal("d.stage NOT IN ('closed_won','closed_lost')"),
            'deals:proposal_negotiation_count' => $this->deal("d.stage IN ('proposal','negotiation')"),
            'deals:won_value' => $this->deal("d.stage = 'closed_won'", true, 'COALESCE(d.actual_close_date, d.updated_at, d.created_at)'),
            'deals:pipeline_value' => $this->deal("d.stage NOT IN ('closed_won','closed_lost')", true),
            'invoices:paid_total' => $this->invoice("i.status IN ('paid','partially_paid')", true, 'COALESCE(i.paid_at, i.updated_at, i.created_at)'),
            'invoices:sent_total' => $this->invoice("i.status IN ('sent','viewed','accepted','finalized','partially_paid','paid','overdue')", true, 'COALESCE(i.last_sent_at, i.updated_at, i.created_at)'),
            'invoices:paid_count' => $this->invoice("i.status = 'paid'", false, 'COALESCE(i.paid_at, i.updated_at, i.created_at)'),
            'invoices:active_quote_count' => $this->invoice("i.document_type IN ('quote','proforma') AND i.status NOT IN ('cancelled','paid')"),
            'documents:created_count' => $this->invoice("i.document_type IN ('quote','proforma','invoice')", false, 'i.created_at'),
            'documents:proposal_created_count' => $this->invoice("i.document_type IN ('quote','proforma')", false, 'i.created_at'),
            'tasks:completed_count' => $this->task("t.status = 'completed'", 'COALESCE(t.completed_at, t.updated_at, t.created_at)'),
            'tasks:open_count' => $this->task("t.status NOT IN ('completed','cancelled')"),
            'contacts:created_count' => $this->contact('1=1', 'c.created_at'),
            'contacts:qualified_count' => $this->contact("c.stage IN ('qualified','proposal','negotiation','won')", 'c.updated_at'),
            'contacts:won_count' => $this->contact("c.stage = 'won'", 'c.updated_at'),
            'communications:outbound_count' => $this->communication("c.direction = 'outbound'"),
            'communications:inbound_count' => $this->communication("c.direction = 'inbound'"),
            'communications:reply_count' => $this->communication("c.direction = 'inbound' AND EXISTS (SELECT 1 FROM communications prior WHERE prior.workspace_id=c.workspace_id AND prior.thread_key=c.thread_key AND prior.direction='outbound' AND prior.created_at < c.created_at)"),
            'meetings:completed_count' => $this->event("e.event_type IN ('meeting','call') AND e.status = 'completed'"),
            'events:completed_count' => $this->event("e.status = 'completed'"),
        ];
        return $configs[$source . ':' . $metric] ?? null;
    }

    private function deal(string $condition, bool $monetary = false, string $date = 'd.created_at'): array
    {
        return ['from' => 'FROM deals d', 'workspace' => 'd.workspace_id', 'condition' => $condition, 'date' => $date,
            'id' => 'd.id', 'label' => 'd.title', 'status' => 'd.stage', 'personal' => 'd.assigned_to = ? OR d.created_by = ?',
            'sum' => $monetary ? 'd.value' : null, 'monetary' => $monetary, 'currency' => $monetary ? 'd.currency' : null, 'entity_type' => 'deal'];
    }

    private function invoice(string $condition, bool $monetary = false, string $date = 'i.created_at'): array
    {
        return ['from' => 'FROM invoices i', 'workspace' => 'i.workspace_id', 'condition' => $condition, 'date' => $date,
            'id' => 'i.id', 'label' => "CONCAT(i.invoice_number, ' ', i.title)", 'status' => 'i.status', 'personal' => 'i.assigned_to = ? OR i.created_by = ?',
            'sum' => $monetary ? 'i.grand_total' : null, 'monetary' => $monetary, 'currency' => $monetary ? 'i.currency' : null, 'entity_type' => 'invoice'];
    }

    private function task(string $condition, string $date = 't.created_at'): array
    {
        return ['from' => 'FROM tasks t', 'workspace' => 't.workspace_id', 'condition' => $condition, 'date' => $date,
            'id' => 't.id', 'label' => 't.title', 'status' => 't.status', 'personal' => 't.assigned_to = ? OR t.created_by = ?',
            'sum' => null, 'monetary' => false, 'currency' => null, 'entity_type' => 'task'];
    }

    private function contact(string $condition, string $date): array
    {
        return ['from' => 'FROM contacts c', 'workspace' => 'c.workspace_id', 'condition' => $condition, 'date' => $date,
            'id' => 'c.id', 'label' => "TRIM(CONCAT(c.first_name, ' ', COALESCE(c.last_name,'')))", 'status' => 'c.stage', 'personal' => 'c.assigned_to = ? OR c.created_by = ?',
            'sum' => null, 'monetary' => false, 'currency' => null, 'entity_type' => 'contact'];
    }

    private function communication(string $condition): array
    {
        return ['from' => 'FROM communications c INNER JOIN contacts owner_contact ON owner_contact.workspace_id=c.workspace_id AND owner_contact.id=c.contact_id',
            'workspace' => 'c.workspace_id', 'condition' => $condition . ' AND c.deleted_at IS NULL', 'date' => 'c.created_at',
            'id' => 'c.id', 'label' => "COALESCE(NULLIF(c.subject,''), LEFT(c.body,80))", 'status' => 'c.direction',
            'personal' => 'owner_contact.assigned_to = ? OR owner_contact.created_by = ?', 'sum' => null, 'monetary' => false, 'currency' => null, 'entity_type' => 'communication'];
    }

    private function event(string $condition): array
    {
        return ['from' => 'FROM events e', 'workspace' => 'e.workspace_id', 'condition' => $condition, 'date' => 'COALESCE(e.end_time,e.start_time)',
            'id' => 'e.id', 'label' => 'e.title', 'status' => 'e.status', 'personal' => 'e.assigned_to = ? OR e.created_by = ?',
            'sum' => null, 'monetary' => false, 'currency' => null, 'entity_type' => 'event'];
    }

    private function emptyResult(string $source, string $metric, array $missing): array
    {
        return ['value' => 0.0, 'matching_count' => 0, 'label' => 'Measurement unavailable', 'source' => $source, 'metric' => $metric,
            'currency' => null, 'calculated_at' => date('Y-m-d H:i:s'), 'explanation' => '', 'evidence' => [],
            'missing_configuration' => $missing, 'conflicts' => []];
    }

    private function explanation(array $target, string $source, string $metric, string $currency): string
    {
        $window = (string) ($target['rollup_window'] ?? 'target_period');
        $range = $window === 'lifetime' ? 'all workspace history' : (($target['start_date'] ?? $target['created_at'] ?? '') . ' through ' . ($window === 'target_period' ? ($target['target_date'] ?? 'now') : 'now'));
        return ucwords(str_replace('_', ' ', $metric)) . " from {$source}, scoped to {$range}" . ($currency !== '' ? " in {$currency}" : '') . '.';
    }

    private function entityUrl(string $type, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $file = match ($type) {
            'deal' => 'deal_view.php', 'invoice' => 'invoice_view.php', 'task' => 'task_view.php',
            'contact' => 'contact_view.php', 'event' => 'calendar.php', default => '',
        };
        return $file === '' ? '' : publicUrl($file . '?id=' . $id);
    }
}
