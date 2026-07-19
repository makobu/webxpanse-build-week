<?php

namespace CRM\Services;

class DefaultWorkspaceSettingsActionService
{
    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function operationalizeFromSettings(?array $user, string $reason): array
    {
        (new DefaultWorkspaceProtectedActionService())->authorize(
            'default_workspace_operationalize',
            $user,
            null,
            $reason,
            ['source' => 'settings'],
            true
        );

        $result = (new DefaultWorkspaceOperationalizationService())->operationalize((int) ($user['id'] ?? 0));
        (new DefaultWorkspaceProtectedActionService())->auditSuccess(
            'default_workspace_operationalize_completed',
            (int) ($user['id'] ?? 0),
            (int) ($result['workspace_id'] ?? 1),
            $reason,
            [],
            ['operational_score' => (int) ($result['operational_score'] ?? 0)]
        );

        return $result;
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function reconcileOwnerContactsFromSettings(?array $user, string $reason): array
    {
        (new DefaultWorkspaceProtectedActionService())->authorize(
            'default_workspace_owner_contact_reconcile',
            $user,
            null,
            $reason,
            ['source' => 'settings'],
            true
        );

        $result = (new DefaultWorkspaceOwnerContactReconciliationService())->run((int) ($user['id'] ?? 0), $reason);
        (new DefaultWorkspaceProtectedActionService())->auditSuccess(
            'default_workspace_owner_contact_reconcile_completed',
            (int) ($user['id'] ?? 0),
            null,
            $reason,
            [],
            array_intersect_key($result, array_flip(['scanned', 'created', 'updated', 'marked_inactive', 'failed']))
        );

        return $result;
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function recoverFromSettings(?array $user, string $reason): array
    {
        return (new DefaultWorkspaceRecoveryService())->recover($user, $reason);
    }
}
