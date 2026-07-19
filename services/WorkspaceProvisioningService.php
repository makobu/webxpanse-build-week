<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Departments;

class WorkspaceProvisioningService
{
    public function signupWorkspaceOwner(array $data): array
    {
        $workspaceName = trim((string) ($data['workspace_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        if ($workspaceName === '' || $email === '' || $password === '' || $firstName === '') {
            throw new \RuntimeException('Workspace name, first name, email, and password are required.');
        }

        $existingUser = Database::queryOne(
            "SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1",
            [$email]
        );
        if ($existingUser) {
            $membershipCount = (int) (Database::queryOne(
                "SELECT COUNT(*) AS count FROM workspace_memberships WHERE user_id = ?",
                [(int) $existingUser['id']]
            )['count'] ?? 0);
            if ($membershipCount > 0) {
                throw new \RuntimeException('An account already exists for this email. Sign in to manage workspaces or use a different owner email.');
            }
        }

        Database::beginTransaction();
        try {
            if ($existingUser) {
                $userId = (int) $existingUser['id'];
                Database::execute(
                    "UPDATE users
                     SET first_name = ?,
                         last_name = ?,
                         email = ?,
                         password_hash = ?,
                         role = 'owner',
                         updated_at = NOW()
                     WHERE id = ?",
                    [$firstName, $lastName, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $userId]
                );
                Authorization::assignUserRoleBySlug($userId, 'owner');
            } else {
                $userId = Auth::createUser($email, $password, 'owner', $firstName, $lastName);
                if ($userId === null || $userId <= 0) {
                    throw new \RuntimeException('Could not create workspace owner.');
                }
            }

            $workspaceId = $this->provisionWorkspace([
                'workspace_name' => $workspaceName,
                'owner_user_id' => $userId,
            ]);

            Database::commit();

            return [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'checkout' => null,
            ];
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public function provisionWorkspace(array $data): int
    {
        $ownerUserId = (int) ($data['owner_user_id'] ?? 0);
        $operatorUserId = (int) ($data['operator_user_id'] ?? 0);
        $operatorRoleSlug = trim((string) ($data['operator_role_slug'] ?? 'admin')) ?: 'admin';
        if ($ownerUserId <= 0) {
            throw new \RuntimeException('Owner user is required.');
        }

        $workspaceService = new WorkspaceService();
        $membershipService = new WorkspaceMembershipService();
        $billingService = new SaaSBillingService();
        $walletService = new WorkspaceWalletService();

        $workspaceId = $workspaceService->createWorkspace(
            (string) ($data['workspace_name'] ?? ''),
            '',
            $ownerUserId
        );

        if (!empty($data['presentation_workspace'])) {
            Database::execute(
                "UPDATE workspaces
                 SET settings_json = JSON_SET(
                        COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
                        '$.presentation_workspace', TRUE,
                        '$.presentation_status', 'provisioning'
                     )
                 WHERE id = ?",
                [$workspaceId]
            );
        }

        $membershipService->addOrUpdateMembership($workspaceId, $ownerUserId, 'owner', true, $ownerUserId);
        if ($operatorUserId > 0 && $operatorUserId !== $ownerUserId) {
            $membershipService->addOrUpdateMembership(
                $workspaceId,
                $operatorUserId,
                $operatorRoleSlug,
                false,
                $operatorUserId
            );
        }
        (new Departments())->ensureStarterDefaults($workspaceId);
        $organizationFunctions = new OrganizationFunctionService();
        $organizationFunctions->ensureDefaults($workspaceId);
        $organizationFunctions->assignDefaultFunctionsForRole($workspaceId, $ownerUserId, 'owner', true);
        $walletService->ensureWallet($workspaceId, 'KES');
        $billingService->activateCompassFreeSubscription($workspaceId, $ownerUserId);
        (new WorkspaceSkillInstallService())->installRequiredCorePlugins($workspaceId, $ownerUserId);
        (new WorkspaceOnboardingService())->createInProgress($workspaceId);
        (new WorkspaceEmailTemplateSeederService())->seed($workspaceId, $ownerUserId);
        try {
            (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace($workspaceId, $ownerUserId, $ownerUserId);
            (new DefaultWorkspaceOpsEventService())->refreshAll($ownerUserId);
        } catch (\Throwable $e) {
            error_log('Default workspace owner contact sync failed: ' . $e->getMessage());
        }

        return $workspaceId;
    }
}
