<?php

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class CommercialAutomationConfig
{
    private const DEFAULTS = [
        'enabled' => false,
        'mode' => 'auto_safe',
        'stage_entry_enabled' => true,
        'negotiation_revisions_enabled' => true,
        'auto_send_enabled' => true,
        'auto_convert_on_won_enabled' => false,
        'auto_mark_overdue_enabled' => true,
        'followup_reminders_enabled' => true,
        'approval_mode' => 'threshold_only',
        'send_delay_minutes' => 0,
        'max_auto_discount_percent' => 20.0,
        'max_auto_total_change_percent' => 25.0,
        'max_revision_count_before_approval' => 2,
        'require_recipient_for_send' => true,
        'require_nonzero_total_for_send' => true,
        'require_billing_identity_for_final_invoice' => true,
        'auto_convert_requires_status' => 'accepted',
        'negotiation_stale_hours' => 48,
        'proposal_followup_hours' => 24,
        'delivery_retry_limit' => 2,
        'delivery_retry_backoff_minutes' => 30,
        'resend_cooldown_minutes' => 180,
        'task_owner_mode' => 'deal_owner',
        'default_document_by_stage' => [
            'proposal' => 'quote',
            'negotiation' => 'quote',
            'closed_won' => 'invoice',
        ],
        'send_channels' => [
            'email' => true,
            'whatsapp' => true,
        ],
        'action_confidence_thresholds' => [
            'create_draft' => 0.82,
            'revise_document' => 0.86,
            'send_document' => 0.88,
            'resend_document' => 0.90,
            'convert_to_invoice' => 0.90,
            'finalize_invoice' => 0.93,
            'mark_paid' => 0.96,
        ],
        'schema_version' => 2,
    ];

    public function get(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = null;
        if ($workspaceId > 0 && Database::tableExists('workspace_commercial_automation_config')) {
            $row = Database::queryOne(
                "SELECT * FROM workspace_commercial_automation_config WHERE workspace_id = ? LIMIT 1",
                [$workspaceId]
            );
        }
        if (!$row) {
            $row = Database::queryOne("SELECT * FROM commercial_automation_config WHERE id = 1");
        }
        if (!$row) {
            return self::DEFAULTS;
        }

        $config = self::DEFAULTS;
        foreach ($row as $key => $value) {
            if (array_key_exists($key, $config)) {
                $config[$key] = $value;
            }
        }

        foreach ([
            'enabled', 'stage_entry_enabled', 'negotiation_revisions_enabled',
            'auto_send_enabled', 'auto_convert_on_won_enabled', 'auto_mark_overdue_enabled',
            'followup_reminders_enabled', 'require_recipient_for_send',
            'require_nonzero_total_for_send', 'require_billing_identity_for_final_invoice',
        ] as $boolKey) {
            $config[$boolKey] = !empty($config[$boolKey]);
        }

        foreach ([
            'send_delay_minutes', 'max_revision_count_before_approval', 'negotiation_stale_hours',
            'proposal_followup_hours', 'delivery_retry_limit', 'delivery_retry_backoff_minutes',
            'resend_cooldown_minutes',
        ] as $intKey) {
            $config[$intKey] = (int) ($config[$intKey] ?? 0);
        }

        foreach ([
            'max_auto_discount_percent', 'max_auto_total_change_percent',
        ] as $floatKey) {
            $config[$floatKey] = (float) ($config[$floatKey] ?? 0);
        }

        $json = $row['config_json'] ?? null;
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                if (!empty($decoded['default_document_by_stage']) && is_array($decoded['default_document_by_stage'])) {
                    $config['default_document_by_stage'] = array_merge(
                        self::DEFAULTS['default_document_by_stage'],
                        $decoded['default_document_by_stage']
                    );
                }
                if (!empty($decoded['send_channels']) && is_array($decoded['send_channels'])) {
                    $channels = array_merge(self::DEFAULTS['send_channels'], $decoded['send_channels']);
                    $config['send_channels'] = [
                        'email' => !empty($channels['email']),
                        'whatsapp' => !empty($channels['whatsapp']),
                    ];
                }
                if (!empty($decoded['action_confidence_thresholds']) && is_array($decoded['action_confidence_thresholds'])) {
                    $config['action_confidence_thresholds'] = $this->normalizeThresholdMap($decoded['action_confidence_thresholds']);
                }
                if (array_key_exists('resend_cooldown_minutes', $decoded)) {
                    $config['resend_cooldown_minutes'] = max(0, (int) $decoded['resend_cooldown_minutes']);
                }
                $config['schema_version'] = (int) ($decoded['schema_version'] ?? 1);
            }
        }

        $config['mode'] = in_array((string) ($config['mode'] ?? 'auto_safe'), ['suggest_only', 'auto_safe', 'full_auto'], true)
            ? (string) $config['mode']
            : 'auto_safe';
        $config['approval_mode'] = 'threshold_only';
        $config['auto_convert_requires_status'] = in_array((string) ($config['auto_convert_requires_status'] ?? 'accepted'), ['accepted', 'sent', 'any_non_draft'], true)
            ? (string) $config['auto_convert_requires_status']
            : 'accepted';
        $config['task_owner_mode'] = in_array((string) ($config['task_owner_mode'] ?? 'deal_owner'), ['deal_owner', 'contact_owner', 'round_robin'], true)
            ? (string) $config['task_owner_mode']
            : 'deal_owner';

        return $config;
    }

    public function save(array $data, ?int $workspaceId = null): void
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $current = $this->get($workspaceId > 0 ? $workspaceId : null);
        $merged = array_merge($current, $data);

        foreach ([
            'enabled', 'stage_entry_enabled', 'negotiation_revisions_enabled',
            'auto_send_enabled', 'auto_convert_on_won_enabled', 'auto_mark_overdue_enabled',
            'followup_reminders_enabled', 'require_recipient_for_send',
            'require_nonzero_total_for_send', 'require_billing_identity_for_final_invoice',
        ] as $boolKey) {
            $merged[$boolKey] = !empty($merged[$boolKey]);
        }

        $merged['mode'] = in_array((string) ($merged['mode'] ?? 'auto_safe'), ['suggest_only', 'auto_safe', 'full_auto'], true)
            ? (string) $merged['mode']
            : 'auto_safe';
        $merged['approval_mode'] = 'threshold_only';
        $merged['auto_convert_requires_status'] = in_array((string) ($merged['auto_convert_requires_status'] ?? 'accepted'), ['accepted', 'sent', 'any_non_draft'], true)
            ? (string) $merged['auto_convert_requires_status']
            : 'accepted';
        $merged['task_owner_mode'] = in_array((string) ($merged['task_owner_mode'] ?? 'deal_owner'), ['deal_owner', 'contact_owner', 'round_robin'], true)
            ? (string) $merged['task_owner_mode']
            : 'deal_owner';

        foreach ([
            'send_delay_minutes', 'max_revision_count_before_approval', 'negotiation_stale_hours',
            'proposal_followup_hours', 'delivery_retry_limit', 'delivery_retry_backoff_minutes',
            'resend_cooldown_minutes',
        ] as $intKey) {
            $merged[$intKey] = max(0, (int) ($merged[$intKey] ?? 0));
        }

        foreach ([
            'max_auto_discount_percent', 'max_auto_total_change_percent',
        ] as $floatKey) {
            $merged[$floatKey] = max(0.0, (float) ($merged[$floatKey] ?? 0));
        }

        $merged['action_confidence_thresholds'] = $this->normalizeThresholdMap(
            is_array($merged['action_confidence_thresholds'] ?? null)
                ? $merged['action_confidence_thresholds']
                : []
        );

        $json = json_encode([
            'default_document_by_stage' => array_merge(self::DEFAULTS['default_document_by_stage'], (array) ($merged['default_document_by_stage'] ?? [])),
            'send_channels' => [
                'email' => !empty(($merged['send_channels'] ?? [])['email']),
                'whatsapp' => !empty(($merged['send_channels'] ?? [])['whatsapp']),
            ],
            'action_confidence_thresholds' => $merged['action_confidence_thresholds'],
            'resend_cooldown_minutes' => $merged['resend_cooldown_minutes'],
            'schema_version' => 2,
        ]);

        $params = [
            $merged['enabled'] ? 1 : 0,
            $merged['mode'],
            $merged['stage_entry_enabled'] ? 1 : 0,
            $merged['negotiation_revisions_enabled'] ? 1 : 0,
            $merged['auto_send_enabled'] ? 1 : 0,
            $merged['auto_convert_on_won_enabled'] ? 1 : 0,
            $merged['auto_mark_overdue_enabled'] ? 1 : 0,
            $merged['followup_reminders_enabled'] ? 1 : 0,
            $merged['approval_mode'],
            $merged['send_delay_minutes'],
            $merged['max_auto_discount_percent'],
            $merged['max_auto_total_change_percent'],
            $merged['max_revision_count_before_approval'],
            $merged['require_recipient_for_send'] ? 1 : 0,
            $merged['require_nonzero_total_for_send'] ? 1 : 0,
            $merged['require_billing_identity_for_final_invoice'] ? 1 : 0,
            $merged['auto_convert_requires_status'],
            $merged['negotiation_stale_hours'],
            $merged['proposal_followup_hours'],
            $merged['delivery_retry_limit'],
            $merged['delivery_retry_backoff_minutes'],
            $merged['task_owner_mode'],
            $json,
        ];

        if ($workspaceId > 0 && Database::tableExists('workspace_commercial_automation_config')) {
            Database::execute(
                "INSERT INTO workspace_commercial_automation_config (
                    workspace_id, enabled, mode, stage_entry_enabled, negotiation_revisions_enabled, auto_send_enabled,
                    auto_convert_on_won_enabled, auto_mark_overdue_enabled, followup_reminders_enabled,
                    approval_mode, send_delay_minutes, max_auto_discount_percent, max_auto_total_change_percent,
                    max_revision_count_before_approval, require_recipient_for_send, require_nonzero_total_for_send,
                    require_billing_identity_for_final_invoice, auto_convert_requires_status, negotiation_stale_hours,
                    proposal_followup_hours, delivery_retry_limit, delivery_retry_backoff_minutes, task_owner_mode, config_json
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    mode = VALUES(mode),
                    stage_entry_enabled = VALUES(stage_entry_enabled),
                    negotiation_revisions_enabled = VALUES(negotiation_revisions_enabled),
                    auto_send_enabled = VALUES(auto_send_enabled),
                    auto_convert_on_won_enabled = VALUES(auto_convert_on_won_enabled),
                    auto_mark_overdue_enabled = VALUES(auto_mark_overdue_enabled),
                    followup_reminders_enabled = VALUES(followup_reminders_enabled),
                    approval_mode = VALUES(approval_mode),
                    send_delay_minutes = VALUES(send_delay_minutes),
                    max_auto_discount_percent = VALUES(max_auto_discount_percent),
                    max_auto_total_change_percent = VALUES(max_auto_total_change_percent),
                    max_revision_count_before_approval = VALUES(max_revision_count_before_approval),
                    require_recipient_for_send = VALUES(require_recipient_for_send),
                    require_nonzero_total_for_send = VALUES(require_nonzero_total_for_send),
                    require_billing_identity_for_final_invoice = VALUES(require_billing_identity_for_final_invoice),
                    auto_convert_requires_status = VALUES(auto_convert_requires_status),
                    negotiation_stale_hours = VALUES(negotiation_stale_hours),
                    proposal_followup_hours = VALUES(proposal_followup_hours),
                    delivery_retry_limit = VALUES(delivery_retry_limit),
                    delivery_retry_backoff_minutes = VALUES(delivery_retry_backoff_minutes),
                    task_owner_mode = VALUES(task_owner_mode),
                    config_json = VALUES(config_json),
                    updated_at = CURRENT_TIMESTAMP",
                array_merge([$workspaceId], $params)
            );
            return;
        }

        $exists = Database::queryOne("SELECT id FROM commercial_automation_config WHERE id = 1");
        if ($exists) {
            $params[] = 1;
            Database::execute(
                "UPDATE commercial_automation_config SET
                    enabled = ?, mode = ?, stage_entry_enabled = ?, negotiation_revisions_enabled = ?, auto_send_enabled = ?,
                    auto_convert_on_won_enabled = ?, auto_mark_overdue_enabled = ?, followup_reminders_enabled = ?,
                    approval_mode = ?, send_delay_minutes = ?, max_auto_discount_percent = ?, max_auto_total_change_percent = ?,
                    max_revision_count_before_approval = ?, require_recipient_for_send = ?, require_nonzero_total_for_send = ?,
                    require_billing_identity_for_final_invoice = ?, auto_convert_requires_status = ?, negotiation_stale_hours = ?,
                    proposal_followup_hours = ?, delivery_retry_limit = ?, delivery_retry_backoff_minutes = ?, task_owner_mode = ?,
                    config_json = ?
                 WHERE id = ?",
                $params
            );
            return;
        }

        array_unshift($params, 1);
        Database::execute(
            "INSERT INTO commercial_automation_config (
                id, enabled, mode, stage_entry_enabled, negotiation_revisions_enabled, auto_send_enabled,
                auto_convert_on_won_enabled, auto_mark_overdue_enabled, followup_reminders_enabled,
                approval_mode, send_delay_minutes, max_auto_discount_percent, max_auto_total_change_percent,
                max_revision_count_before_approval, require_recipient_for_send, require_nonzero_total_for_send,
                require_billing_identity_for_final_invoice, auto_convert_requires_status, negotiation_stale_hours,
                proposal_followup_hours, delivery_retry_limit, delivery_retry_backoff_minutes, task_owner_mode, config_json
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )",
            $params
        );
    }

    public function isEnabled(): bool
    {
        return !empty($this->get()['enabled']);
    }

    private function normalizeThresholdMap(array $thresholds): array
    {
        $normalized = self::DEFAULTS['action_confidence_thresholds'];
        foreach ($thresholds as $action => $value) {
            if (!array_key_exists($action, $normalized)) {
                continue;
            }
            $normalized[$action] = max(0.0, min(1.0, (float) $value));
        }
        return $normalized;
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
