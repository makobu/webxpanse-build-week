<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class AssistantActionAuthorizationService
{
    /**
     * @return array{allowed:bool,permission:?string,reason:string}
     */
    public function authorize(string $intent, int $userId, ?int $workspaceId = null): array
    {
        $workspaceId = $workspaceId ?: (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0 || $userId <= 0) {
            return $this->denied(null, 'missing_workspace_identity');
        }

        $actor = Database::queryOne(
            "SELECT u.id, u.role, wm.role_slug, wm.is_owner
             FROM users u
             JOIN workspace_memberships wm ON wm.user_id = u.id
             WHERE u.id = ?
               AND wm.workspace_id = ?
               AND wm.membership_status = 'active'
             LIMIT 1",
            [$userId, $workspaceId]
        );
        if (!$actor) {
            return $this->denied(null, 'inactive_workspace_member');
        }

        $workspaceRole = strtolower(trim((string) ($actor['role_slug'] ?? '')));
        if (!empty($actor['is_owner']) || in_array($workspaceRole, ['superadmin', 'owner', 'admin'], true) || Authorization::isSuperAdmin($actor)) {
            return ['allowed' => true, 'permission' => null, 'reason' => 'privileged_workspace_role'];
        }

        $permission = $this->permissionForIntent($intent);
        if ($permission === null) {
            return $this->denied(null, 'privileged_action');
        }

        if (Authorization::can($permission, $actor)) {
            return ['allowed' => true, 'permission' => $permission, 'reason' => 'permission_granted'];
        }

        return $this->denied($permission, 'permission_missing');
    }

    private function permissionForIntent(string $intent): ?string
    {
        return match (strtolower(trim($intent))) {
            'create_task' => 'tasks.write',
            'list_tasks' => 'tasks.read',
            'question', 'get_status', 'get_pipeline', 'run_report',
            'show_last_assistant_action', 'summarize_thread_state' => 'reports.nl_generate',
            'list_invoices', 'explain_quote_changes', 'draft_customer_reply' => 'invoices.view',
            'create_invoice', 'convert_quote_to_invoice' => 'invoices.create',
            'update_invoice', 'revise_quote_with_context' => 'invoices.edit',
            'send_invoice', 'send_customer_reply' => 'invoices.send',
            'finalize_invoice' => 'invoices.finalize',
            'mark_invoice_paid' => 'invoices.mark_paid',
            'approve_commercial_action', 'reject_commercial_action',
            'list_pending_commercial_approvals', 'summarize_commercial_automation_state' => 'commercial_automation.approvals',
            'enrich_contact', 'verify_contact_email', 'verify_email_value' => 'settings.enrichment',
            // Contact create/update/delete, notes, calendar mutations, and unknown
            // intents stay privileged until those domains expose write permissions.
            default => null,
        };
    }

    /**
     * @return array{allowed:false,permission:?string,reason:string}
     */
    private function denied(?string $permission, string $reason): array
    {
        return ['allowed' => false, 'permission' => $permission, 'reason' => $reason];
    }
}
