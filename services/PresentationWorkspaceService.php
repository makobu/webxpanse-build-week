<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class PresentationWorkspaceService
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function provision(array $input, int $temporaryOwnerUserId, int $presenterUserId, string $expiresAt): array
    {
        if ($temporaryOwnerUserId <= 0 || $presenterUserId <= 0) {
            throw new \InvalidArgumentException('Presenter and temporary owner are required.');
        }

        $workspaceName = $this->workspaceName($input);
        $workspaceId = (new WorkspaceProvisioningService())->provisionWorkspace([
            'workspace_name' => $workspaceName,
            'owner_user_id' => $temporaryOwnerUserId,
            'operator_user_id' => $presenterUserId,
            'operator_role_slug' => 'presentation_owner',
            'presentation_workspace' => true,
        ]);

        $this->applyPresentationFlags(
            $workspaceId,
            (string) ($input['audience_key'] ?? ''),
            (string) ($input['seed_pack_key'] ?? ''),
            $expiresAt,
            (string) ($input['pitch_title'] ?? ''),
            $presenterUserId
        );

        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $temporaryOwnerUserId, 'presentation_owner', true, $presenterUserId);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $presenterUserId, 'presentation_owner', false, $presenterUserId);

        return $this->workspacePayload($workspaceId);
    }

    public function applyPresentationFlags(
        int $workspaceId,
        string $audienceKey,
        string $seedPackKey,
        string $expiresAt,
        string $pitchTitle,
        int $actorUserId
    ): void {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }

        Database::execute(
            "UPDATE workspaces
             SET status = 'active',
                 plan_status = 'inactive',
                 settings_json = JSON_SET(
                    COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
                    '$.presentation_workspace', TRUE,
                    '$.presentation_status', 'active',
                    '$.presentation_audience_key', ?,
                    '$.presentation_seed_pack_key', ?,
                    '$.presentation_expires_at', ?,
                    '$.presentation_pitch_title', ?,
                    '$.presentation_created_by', ?,
                    '$.billing_disabled', TRUE,
                    '$.invites_disabled', TRUE,
                    '$.exports_disabled', TRUE,
                    '$.integration_changes_disabled', TRUE,
                    '$.tenant_delete_disabled', TRUE,
                    '$.permanent_user_management_disabled', TRUE
                 ),
                 updated_at = NOW()
             WHERE id = ?",
            [
                $audienceKey,
                $seedPackKey,
                $expiresAt,
                $pitchTitle,
                $actorUserId,
                $workspaceId,
            ]
        );
    }

    public function archive(int $workspaceId, int $actorUserId): void
    {
        if ($workspaceId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE workspaces
             SET status = 'archived',
                 settings_json = JSON_SET(
                    COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
                    '$.presentation_status', 'archived',
                    '$.presentation_archived_at', NOW(),
                    '$.presentation_archived_by', ?
                 ),
                 updated_at = NOW()
             WHERE id = ?",
            [$actorUserId, $workspaceId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function workspacePayload(int $workspaceId): array
    {
        $workspace = Database::queryOne("SELECT * FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [];
        if ($workspace === []) {
            return [];
        }

        $settings = json_decode((string) ($workspace['settings_json'] ?? ''), true);
        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'id' => (int) ($workspace['id'] ?? 0),
            'uuid' => (string) ($workspace['uuid'] ?? ''),
            'name' => (string) ($workspace['name'] ?? ''),
            'slug' => (string) ($workspace['slug'] ?? ''),
            'status' => (string) ($workspace['status'] ?? ''),
            'plan_status' => (string) ($workspace['plan_status'] ?? ''),
            'presentation_status' => (string) ($settings['presentation_status'] ?? ''),
            'presentation_seed_pack_key' => (string) ($settings['presentation_seed_pack_key'] ?? ''),
            'presentation_audience_key' => (string) ($settings['presentation_audience_key'] ?? ''),
            'presentation_expires_at' => (string) ($settings['presentation_expires_at'] ?? ''),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function workspaceName(array $input): string
    {
        $company = trim((string) ($input['company'] ?? $input['prospect_company'] ?? ''));
        $title = trim((string) ($input['pitch_title'] ?? ''));
        if ($title !== '') {
            return mb_substr($title, 0, 120);
        }
        if ($company !== '') {
            return mb_substr($company . ' Presentation Workspace', 0, 120);
        }

        return 'Presentation Workspace ' . date('Ymd His');
    }
}
