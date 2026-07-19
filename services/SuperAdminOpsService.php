<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Session;

class SuperAdminOpsService
{
    private const LOW_TOKEN_THRESHOLD = 1000;

    public function handleMessage(string $message, array $user): array
    {
        $this->assertSuperAdmin($user);

        $context = $this->buildContext();
        $intent = $this->detectIntent($message);
        $taskResult = null;

        if ($intent === 'create_tasks') {
            $taskResult = $this->createPlatformFollowUpTasks((int) ($user['id'] ?? 0), $context);
            $answer = $this->formatTaskCreationAnswer($taskResult);
        } elseif ($intent === 'billing_guide') {
            $answer = $this->formatBillingGuide($context);
        } elseif ($intent === 'risky_workspaces') {
            $answer = $this->formatRiskyWorkspaces($context);
        } elseif ($intent === 'operator_actions') {
            $answer = $this->formatRecentOperatorActions($context);
        } else {
            $answer = $this->formatAttentionSummary($context);
        }

        return [
            'answer' => $answer,
            'metadata' => [
                'mode_variant' => 'superadmin_ops',
                'superadmin_ops' => true,
                'task_result' => $taskResult,
            ],
            'diagnostics' => [
                'surface' => 'superadmin_ops',
                'intent' => $intent,
                'workspace_count' => (int) ($context['summary']['total_workspaces'] ?? 0),
                'risk_count' => count($context['risk_workspaces']),
            ],
        ];
    }

    public function buildContext(): array
    {
        $workspaces = $this->listWorkspaceSignals();
        $recentAudit = $this->listRecentOperatorActions();
        $settings = (new PlatformSecuritySettingsService())->getSettings();

        $summary = [
            'total_workspaces' => count($workspaces),
            'active_workspaces' => 0,
            'suspended_workspaces' => 0,
            'archived_workspaces' => 0,
            'low_token_workspaces' => 0,
            'failed_billing_event_workspaces' => 0,
            'billing_risk_workspaces' => 0,
            'require_superadmin_workspace_2fa' => !empty($settings['require_superadmin_workspace_2fa']),
        ];

        $riskWorkspaces = [];
        foreach ($workspaces as $workspace) {
            $status = strtolower((string) ($workspace['status'] ?? ''));
            if ($status === 'active') {
                $summary['active_workspaces']++;
            } elseif ($status === 'suspended') {
                $summary['suspended_workspaces']++;
            } elseif ($status === 'archived') {
                $summary['archived_workspaces']++;
            }

            $reasons = $this->riskReasons($workspace);
            if (in_array('Low AI Credit balance', $reasons, true)) {
                $summary['low_token_workspaces']++;
            }
            if (in_array('Failed billing provider event', $reasons, true)) {
                $summary['failed_billing_event_workspaces']++;
            }
            if (in_array('Billing or subscription needs review', $reasons, true)) {
                $summary['billing_risk_workspaces']++;
            }

            if ($reasons !== []) {
                $workspace['risk_reasons'] = $reasons;
                $riskWorkspaces[] = $workspace;
            }
        }

        return [
            'summary' => $summary,
            'workspaces' => $workspaces,
            'risk_workspaces' => $riskWorkspaces,
            'recent_audit' => $recentAudit,
            'platform_security_settings' => $settings,
        ];
    }

    public function createPlatformFollowUpTasks(int $actorUserId, ?array $context = null): array
    {
        if ($actorUserId <= 0) {
            throw new \RuntimeException('A valid Super Admin user is required.');
        }

        $context = $context ?? $this->buildContext();
        $taskWorkspaceId = $this->resolvePlatformTaskWorkspaceId();
        $existingKeys = $this->loadOpenPlatformTaskKeys($taskWorkspaceId, $actorUserId);
        $candidates = $this->buildTaskCandidates($context);
        $created = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['key'] ?? '');
            if ($key === '' || isset($existingKeys[$key])) {
                $skipped[] = $candidate;
                continue;
            }

            Database::execute(
                "INSERT INTO tasks
                    (workspace_id, title, description, assigned_to, created_by, status, priority, due_date, metadata_json, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW())",
                [
                    $taskWorkspaceId,
                    (string) $candidate['title'],
                    (string) $candidate['description'],
                    $actorUserId,
                    $actorUserId,
                    (string) ($candidate['priority'] ?? 'medium'),
                    (string) ($candidate['due_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day 17:00'))),
                    json_encode([
                        'source_surface' => 'superadmin_ops',
                        'source_recommendation_type' => (string) ($candidate['category'] ?? 'platform_ops'),
                        'platform_task_key' => $key,
                        'workspace_id_reference' => $candidate['workspace_id'] ?? null,
                        'risk_reasons' => $candidate['risk_reasons'] ?? [],
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );

            $candidate['task_id'] = (int) Database::lastInsertId();
            $created[] = $candidate;
            $existingKeys[$key] = true;
        }

        return [
            'workspace_id' => $taskWorkspaceId,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    private function assertSuperAdmin(array $user): void
    {
        if (!Authorization::isSuperAdmin($user)) {
            throw new \RuntimeException('Super Admin access is required for Clarity Ops.');
        }
    }

    private function detectIntent(string $message): string
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'create') && (str_contains($normalized, 'task') || str_contains($normalized, 'follow-up') || str_contains($normalized, 'follow up'))) {
            return 'create_tasks';
        }
        if (str_contains($normalized, 'billing guide') || (str_contains($normalized, 'billing') && str_contains($normalized, 'guide'))) {
            return 'billing_guide';
        }
        if (str_contains($normalized, 'risky') || str_contains($normalized, 'risk')) {
            return 'risky_workspaces';
        }
        if (str_contains($normalized, 'operator') || str_contains($normalized, 'audit') || str_contains($normalized, 'recent action')) {
            return 'operator_actions';
        }

        return 'attention_today';
    }

    private function listWorkspaceSignals(): array
    {
        return Database::query(
            "SELECT
                w.id,
                w.name,
                w.slug,
                w.status,
                w.plan_status,
                w.created_at,
                COALESCE(owner_user.email, fallback_owner.email) AS owner_email,
                COALESCE(member_counts.active_member_count, 0) AS active_member_count,
                COALESCE(wallet.token_balance, 0) AS token_balance,
                GREATEST(COALESCE(wallet.token_balance, 0) - COALESCE(wallet.reserved_tokens, 0), 0) AS available_tokens,
                latest_sub.subscription_status,
                latest_action.action_type AS last_operator_action,
                latest_action.created_at AS last_operator_action_at,
                COALESCE(provider_failures.failed_count, 0) AS failed_billing_events,
                provider_failures.latest_failed_at AS latest_failed_billing_event_at
             FROM workspaces w
             LEFT JOIN workspace_wallets wallet ON wallet.workspace_id = w.id
             LEFT JOIN workspace_subscriptions latest_sub
                ON latest_sub.id = (
                    SELECT ws.id
                    FROM workspace_subscriptions ws
                    WHERE ws.workspace_id = w.id
                    ORDER BY ws.id DESC
                    LIMIT 1
                )
             LEFT JOIN workspace_memberships owner_membership
                ON owner_membership.id = (
                    SELECT wm.id
                    FROM workspace_memberships wm
                    WHERE wm.workspace_id = w.id
                      AND wm.membership_status = 'active'
                      AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
                    ORDER BY wm.is_owner DESC, wm.id ASC
                    LIMIT 1
                )
             LEFT JOIN users owner_user ON owner_user.id = owner_membership.user_id
             LEFT JOIN workspace_memberships fallback_membership
                ON fallback_membership.id = (
                    SELECT wm.id
                    FROM workspace_memberships wm
                    WHERE wm.workspace_id = w.id
                      AND wm.membership_status = 'active'
                    ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), wm.id ASC
                    LIMIT 1
                )
             LEFT JOIN users fallback_owner ON fallback_owner.id = fallback_membership.user_id
             LEFT JOIN (
                SELECT workspace_id, COUNT(*) AS active_member_count
                FROM workspace_memberships
                WHERE membership_status = 'active'
                GROUP BY workspace_id
             ) member_counts ON member_counts.workspace_id = w.id
             LEFT JOIN operator_audit_log latest_action
                ON latest_action.id = (
                    SELECT oal.id
                    FROM operator_audit_log oal
                    WHERE oal.target_workspace_id = w.id
                    ORDER BY oal.id DESC
                    LIMIT 1
                )
             LEFT JOIN (
                SELECT workspace_id, COUNT(*) AS failed_count, MAX(created_at) AS latest_failed_at
                FROM billing_provider_events
                WHERE processing_status = 'failed'
                GROUP BY workspace_id
             ) provider_failures ON provider_failures.workspace_id = w.id
             ORDER BY w.created_at DESC, w.id DESC
             LIMIT 250"
        );
    }

    private function listRecentOperatorActions(): array
    {
        return Database::query(
            "SELECT
                oal.id,
                oal.action_type,
                oal.reason,
                oal.created_at,
                actor.email AS actor_email,
                w.name AS workspace_name,
                w.slug AS workspace_slug
             FROM operator_audit_log oal
             LEFT JOIN users actor ON actor.id = oal.actor_user_id
             LEFT JOIN workspaces w ON w.id = oal.target_workspace_id
             ORDER BY oal.id DESC
             LIMIT 10"
        );
    }

    private function riskReasons(array $workspace): array
    {
        $reasons = [];
        $status = strtolower((string) ($workspace['status'] ?? ''));
        $subscriptionStatus = strtolower((string) (($workspace['subscription_status'] ?? '') ?: ($workspace['plan_status'] ?? '')));
        $availableTokens = (int) ($workspace['available_tokens'] ?? 0);
        $failedBillingEvents = (int) ($workspace['failed_billing_events'] ?? 0);

        if (in_array($status, ['suspended', 'archived'], true)) {
            $reasons[] = 'Suspended or archived workspace';
        }
        if (in_array($subscriptionStatus, ['past_due', 'failed', 'expired', 'cancelled', 'inactive'], true)) {
            $reasons[] = 'Billing or subscription needs review';
        }
        if ($availableTokens <= self::LOW_TOKEN_THRESHOLD) {
            $reasons[] = 'Low AI Credit balance';
        }
        if ($failedBillingEvents > 0) {
            $reasons[] = 'Failed billing provider event';
        }

        return $reasons;
    }

    private function buildTaskCandidates(array $context): array
    {
        $candidates = [];
        $summary = $context['summary'] ?? [];

        if (empty($summary['require_superadmin_workspace_2fa'])) {
            $candidates[] = [
                'key' => 'security-setting-review-superadmin-workspace-2fa',
                'title' => 'Review Super Admin workspace 2FA setting',
                'description' => 'Confirm whether Super Admin workspace login and delete actions should require authenticator-app verification.',
                'category' => 'security_setting_review',
                'priority' => 'high',
            ];
        }

        foreach (($context['risk_workspaces'] ?? []) as $workspace) {
            $workspaceId = (int) ($workspace['id'] ?? 0);
            $name = (string) (($workspace['name'] ?? '') ?: ('Workspace #' . $workspaceId));
            $slug = (string) ($workspace['slug'] ?? '');
            $reasons = (array) ($workspace['risk_reasons'] ?? []);

            foreach ($reasons as $reason) {
                $category = $this->categoryForRiskReason($reason);
                $candidates[] = [
                    'key' => $category . '-' . $workspaceId,
                    'title' => $this->titleForRiskReason($reason, $name),
                    'description' => trim("Review {$name}" . ($slug !== '' ? " ({$slug})" : '') . " for: {$reason}. Check owner contact, subscription status, AI Credit balance, and latest operator action before changing tenant state."),
                    'category' => $category,
                    'priority' => in_array($reason, ['Failed billing provider event', 'Billing or subscription needs review'], true) ? 'high' : 'medium',
                    'workspace_id' => $workspaceId,
                    'risk_reasons' => $reasons,
                ];
            }
        }

        if ($this->hasRecentDeletionAudit($context)) {
            $candidates[] = [
                'key' => 'workspace-deletion-audit-review',
                'title' => 'Review recent workspace deletion audit',
                'description' => 'Review the latest workspace deletion operator audit records and confirm deleted tenant data matched the intended request.',
                'category' => 'workspace_audit_review',
                'priority' => 'medium',
            ];
        }

        return array_slice($candidates, 0, 25);
    }

    private function categoryForRiskReason(string $reason): string
    {
        return match ($reason) {
            'Failed billing provider event' => 'failed_provider_event_review',
            'Billing or subscription needs review' => 'billing_follow_up',
            'Low AI Credit balance' => 'low_token_balance_review',
            'Suspended or archived workspace' => 'suspended_workspace_check',
            default => 'platform_ops_review',
        };
    }

    private function titleForRiskReason(string $reason, string $workspaceName): string
    {
        return match ($reason) {
            'Failed billing provider event' => 'Review failed billing event for ' . $workspaceName,
            'Billing or subscription needs review' => 'Follow up on billing status for ' . $workspaceName,
            'Low AI Credit balance' => 'Check AI Credit balance for ' . $workspaceName,
            'Suspended or archived workspace' => 'Review suspended or archived workspace ' . $workspaceName,
            default => 'Review platform ops signal for ' . $workspaceName,
        };
    }

    private function hasRecentDeletionAudit(array $context): bool
    {
        foreach (($context['recent_audit'] ?? []) as $audit) {
            if (str_contains((string) ($audit['action_type'] ?? ''), 'delete')) {
                return true;
            }
        }

        return false;
    }

    private function resolvePlatformTaskWorkspaceId(): int
    {
        $sessionWorkspaceId = (int) (Session::get('active_workspace_id') ?? 0);
        if ($sessionWorkspaceId > 0 && Database::queryOne('SELECT id FROM workspaces WHERE id = ? LIMIT 1', [$sessionWorkspaceId])) {
            return $sessionWorkspaceId;
        }

        try {
            $workspaceId = (new DefaultWorkspaceService())->id();
        } catch (\Throwable $e) {
            $row = Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
            $workspaceId = (int) ($row['id'] ?? 0);
        }
        if ($workspaceId <= 0) {
            throw new \RuntimeException('No workspace is available for Super Admin tasks.');
        }

        return $workspaceId;
    }

    /**
     * @return array<string,bool>
     */
    private function loadOpenPlatformTaskKeys(int $workspaceId, int $actorUserId): array
    {
        $rows = Database::query(
            "SELECT metadata_json
             FROM tasks
             WHERE workspace_id = ?
               AND assigned_to = ?
               AND status IN ('pending', 'in_progress')
               AND metadata_json IS NOT NULL",
            [$workspaceId, $actorUserId]
        );

        $keys = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
            if (!is_array($metadata) || ($metadata['source_surface'] ?? '') !== 'superadmin_ops') {
                continue;
            }
            $key = (string) ($metadata['platform_task_key'] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function formatAttentionSummary(array $context): string
    {
        $summary = $context['summary'];
        $lines = [
            'Super Admin Ops is looking across the platform, not just the active workspace.',
            'Today: ' . (int) $summary['total_workspaces'] . ' workspaces, ' . (int) $summary['active_workspaces'] . ' active, ' . count($context['risk_workspaces']) . ' with operational signals.',
            'Watch first: ' . (int) $summary['failed_billing_event_workspaces'] . ' failed billing-event workspace(s), ' . (int) $summary['billing_risk_workspaces'] . ' package-status risk workspace(s), and ' . (int) $summary['low_token_workspaces'] . ' low-token workspace(s).',
            !empty($summary['require_superadmin_workspace_2fa'])
                ? 'Security: Super Admin workspace 2FA is enabled for workspace login/delete checkpoints.'
                : 'Security: Super Admin workspace 2FA is off. Consider enabling it before destructive workspace operations become routine.',
        ];

        if ($context['risk_workspaces'] !== []) {
            $lines[] = 'Next step: run “Show risky workspaces” for the short list, or “Create platform follow-up tasks” to put the reviews into Tasks.';
        }

        return implode("\n", $lines);
    }

    private function formatBillingGuide(array $context): string
    {
        $summary = $context['summary'];

        return implode("\n", [
            'Workspace billing guide:',
            '1. Start with failed provider events and past-due/failed subscriptions; these are the highest-risk tenant continuity signals.',
            '2. Review package status and decide whether to restore, change package, or contact the owner.',
            '3. Check low AI Credit balances before support tickets arrive; low available credits can look like product failure to a tenant.',
            '4. Use Open Workspace Ops for a workspace-specific billing portal, wallet adjustments, package changes, and operator audit history.',
            'Current platform signals: ' . (int) $summary['billing_risk_workspaces'] . ' billing-status risk workspace(s), ' . (int) $summary['failed_billing_event_workspaces'] . ' failed provider-event workspace(s), ' . (int) $summary['low_token_workspaces'] . ' low-token workspace(s).',
        ]);
    }

    private function formatRiskyWorkspaces(array $context): string
    {
        if ($context['risk_workspaces'] === []) {
            return 'No risky workspaces are showing in the current platform scan. Keep an eye on failed billing events, low tokens, and package status as new activity comes in.';
        }

        $lines = ['Risky workspaces:'];
        foreach (array_slice($context['risk_workspaces'], 0, 8) as $workspace) {
            $lines[] = '- ' . (string) ($workspace['name'] ?? 'Workspace') . ' (' . (string) ($workspace['slug'] ?? 'n/a') . '): ' . implode(', ', (array) ($workspace['risk_reasons'] ?? []));
        }
        if (count($context['risk_workspaces']) > 8) {
            $lines[] = 'Plus ' . (count($context['risk_workspaces']) - 8) . ' more workspace(s). Use the directory search and Open Workspace Ops to drill in.';
        }

        return implode("\n", $lines);
    }

    private function formatRecentOperatorActions(array $context): string
    {
        if ($context['recent_audit'] === []) {
            return 'No recent operator actions are recorded yet.';
        }

        $lines = ['Recent operator actions:'];
        foreach (array_slice($context['recent_audit'], 0, 8) as $audit) {
            $workspace = (string) (($audit['workspace_name'] ?? '') ?: ($audit['workspace_slug'] ?? 'platform'));
            $actor = (string) (($audit['actor_email'] ?? '') ?: 'unknown operator');
            $lines[] = '- ' . (string) ($audit['action_type'] ?? 'action') . ' on ' . $workspace . ' by ' . $actor . ' at ' . (string) ($audit['created_at'] ?? 'n/a');
        }

        return implode("\n", $lines);
    }

    private function formatTaskCreationAnswer(array $taskResult): string
    {
        $lines = [
            'Platform follow-up tasks created: ' . (int) $taskResult['created_count'] . '.',
            'Already covered by open tasks: ' . (int) $taskResult['skipped_count'] . '.',
        ];

        foreach (array_slice($taskResult['created'], 0, 6) as $task) {
            $lines[] = '- ' . (string) ($task['title'] ?? 'Platform ops task');
        }
        if ((int) $taskResult['created_count'] === 0) {
            $lines[] = 'No new tasks were needed; the open Super Admin ops tasks already cover the current signals.';
        }

        return implode("\n", $lines);
    }
}
