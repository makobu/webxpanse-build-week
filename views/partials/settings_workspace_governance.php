<?php
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

$workspaceGovernanceData = is_array($workspaceGovernanceData ?? null) ? $workspaceGovernanceData : [];
$workspaceGovernanceWorkspace = (array) ($workspaceGovernanceData['workspace'] ?? []);
$workspaceGovernanceMembers = (array) ($workspaceGovernanceData['members'] ?? []);
$workspaceGovernanceInvites = (array) ($workspaceGovernanceData['pending_invites'] ?? []);
$workspaceGovernanceSlugs = (array) ($workspaceGovernanceData['slugs'] ?? []);
$workspaceGovernanceHistory = (array) ($workspaceGovernanceData['history'] ?? []);
$workspaceGovernanceHistoryFilter = (string) ($workspaceGovernanceData['history_filter'] ?? 'all');
$workspaceGovernanceHistoryFilters = (array) ($workspaceGovernanceData['history_filters'] ?? ['all']);
$workspaceGovernanceCapabilities = (array) ($workspaceGovernanceData['capabilities'] ?? []);
$workspaceGovernanceActorMembership = (array) ($workspaceGovernanceData['actor_membership'] ?? []);
$workspaceGovernanceInviteUrl = (string) ($workspaceGovernanceActionResult['invite_url'] ?? '');
$workspaceGovernanceDelivery = (array) ($workspaceGovernanceActionResult['delivery'] ?? []);
$workspaceGovernanceAllowedRoles = ['viewer' => 'Viewer', 'expert' => 'Expert', 'accountant' => 'Accountant', 'admin' => 'Admin', 'owner' => 'Owner'];
$workspaceGovernanceAllowedDemotionRoles = ['admin' => 'Admin', 'accountant' => 'Accountant', 'expert' => 'Expert', 'viewer' => 'Viewer'];
$workspaceGovernanceCanManageOwners = !empty($workspaceGovernanceCapabilities['can_manage_owners']);
$workspaceGovernanceCanTransferOwnership = !empty($workspaceGovernanceCapabilities['can_transfer_ownership']);
$workspaceGovernanceFunctions = (array) ($workspaceGovernanceData['assignable_functions'] ?? []);
$workspaceGovernanceFunctionDefaults = (array) ($workspaceGovernanceData['function_defaults_by_role'] ?? []);
$workspaceGovernanceMemberWorkOwnership = (array) ($workspaceGovernanceData['member_function_assignments'] ?? []);
$workspaceGovernanceOwnershipCoverage = (array) ($workspaceGovernanceData['work_ownership_coverage'] ?? []);
$workspaceGovernanceCoverageRows = (array) ($workspaceGovernanceOwnershipCoverage['functions'] ?? []);
$workspaceGovernanceDefaultInviteRole = 'viewer';
$workspaceGovernanceDefaultInviteFunctionIds = (array) ($workspaceGovernanceFunctionDefaults[$workspaceGovernanceDefaultInviteRole]['function_ids'] ?? []);
$workspaceGovernanceDefaultInvitePrimaryFunctionId = (int) ($workspaceGovernanceFunctionDefaults[$workspaceGovernanceDefaultInviteRole]['primary_function_id'] ?? ($workspaceGovernanceDefaultInviteFunctionIds[0] ?? 0));
$workspaceGovernanceFunctionDefaultsJson = htmlspecialchars(json_encode($workspaceGovernanceFunctionDefaults, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$workspaceGovernanceReadiness = is_array($workspaceGovernanceReadiness ?? null) ? $workspaceGovernanceReadiness : ['ready' => true, 'issues' => []];
$workspaceGovernanceReadinessError = (string) ($workspaceGovernanceReadinessError ?? '');
$workspaceSecuritySettings = is_array($workspaceSecuritySettings ?? null) ? $workspaceSecuritySettings : ['require_member_2fa' => false];
$workspaceSecurity2FASummary = is_array($workspaceSecurity2FASummary ?? null) ? $workspaceSecurity2FASummary : ['total' => 0, 'protected' => 0, 'missing' => 0, 'missing_members' => []];
$workspaceSecurityMissingMembers = array_slice((array) ($workspaceSecurity2FASummary['missing_members'] ?? []), 0, 5);
$automationReadinessRefreshOptions = is_array($automationReadinessRefreshOptions ?? null) && $automationReadinessRefreshOptions !== []
    ? $automationReadinessRefreshOptions
    : [
        'manual_only' => ['label' => 'Manual only', 'seconds' => null],
        'hourly' => ['label' => 'Every hour', 'seconds' => 3600],
        'six_hours' => ['label' => 'Every 6 hours', 'seconds' => 21600],
        'daily' => ['label' => 'Daily', 'seconds' => 86400],
    ];
$automationReadinessRefreshPolicy = is_array($automationReadinessRefreshPolicy ?? null)
    ? $automationReadinessRefreshPolicy
    : ['cadence' => 'six_hours', 'label' => 'Every 6 hours', 'seconds' => 21600, 'automatic_enabled' => true];
$automationReadinessRefreshCadence = (string) ($automationReadinessRefreshPolicy['cadence'] ?? $automationReadinessRefreshPolicy['key'] ?? 'six_hours');
$automationReadinessRefreshLabel = (string) ($automationReadinessRefreshPolicy['label'] ?? 'Every 6 hours');
$workspaceGovernanceGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM);
$workspaceGovernanceActiveMembers = array_values(array_filter($workspaceGovernanceMembers, static function (array $membership): bool {
    return (string) ($membership['membership_status'] ?? '') === 'active';
}));
$workspaceGovernanceDepartmentFilterOptions = [];
$workspaceGovernanceAccessFilterOptions = [];
foreach ($workspaceGovernanceActiveMembers as $membership) {
    $departmentId = (int) ($membership['department_id'] ?? 0);
    $departmentKey = $departmentId > 0 ? 'department-' . $departmentId : 'unassigned';
    $workspaceGovernanceDepartmentFilterOptions[$departmentKey] = $departmentId > 0
        ? (string) ($membership['department_name'] ?? 'Department')
        : 'Unassigned';

    $accessSlug = trim((string) ($membership['access_role_slug'] ?? ''));
    $accessName = trim((string) ($membership['access_role_name'] ?? ''));
    if ($accessSlug === '') {
        $accessSlug = (string) ($membership['role_slug'] ?? 'viewer');
    }
    if ($accessName === '') {
        $accessName = ucwords(str_replace('_', ' ', $accessSlug));
    }
    $workspaceGovernanceAccessFilterOptions[$accessSlug] = $accessName;
}
ksort($workspaceGovernanceDepartmentFilterOptions);
asort($workspaceGovernanceAccessFilterOptions);
$workspaceGovernanceTransferTargets = array_values(array_filter($workspaceGovernanceActiveMembers, static function (array $membership) use ($workspaceGovernanceActorMembership): bool {
    return (int) ($membership['id'] ?? 0) !== (int) ($workspaceGovernanceActorMembership['id'] ?? 0);
}));
$workspaceGovernanceEventLabels = [
    'invite_created' => 'Invite created',
    'invite_resent' => 'Invite resent',
    'invite_revoked' => 'Invite revoked',
    'invite_accepted' => 'Invite accepted',
    'invite_delivery_sent' => 'Invite delivered',
    'invite_delivery_failed' => 'Invite delivery failed',
    'member_role_changed' => 'Member role changed',
    'member_restored' => 'Member restored',
    'member_removed' => 'Member removed',
    'member_suspended' => 'Member suspended',
    'ownership_transferred' => 'Ownership transferred',
    'primary_slug_changed' => 'Primary slug changed',
    'workspace_security_updated' => 'Workspace security updated',
    'automation_readiness_refresh_updated' => 'Automation readiness refresh updated',
    'member_work_ownership_assigned' => 'Work Ownership assigned',
];

$workspaceGovernanceCoverageTokens = static function (array $coverage): array {
    $departments = [];
    $accessProfiles = [];
    $statuses = [];
    $members = array_merge(
        (array) ($coverage['primary_members'] ?? []),
        (array) ($coverage['supporting_members'] ?? [])
    );

    foreach ($members as $member) {
        $departmentId = (int) ($member['department_id'] ?? 0);
        $departments[] = $departmentId > 0 ? 'department-' . $departmentId : 'unassigned';
        $accessSlug = trim((string) ($member['access_role_slug'] ?? ''));
        $accessProfiles[] = $accessSlug !== '' ? $accessSlug : (string) ($member['role_slug'] ?? 'viewer');
    }

    foreach (array_merge((array) ($coverage['pending_primary_invites'] ?? []), (array) ($coverage['pending_supporting_invites'] ?? [])) as $invite) {
        $statuses[] = 'invited';
        $accessProfiles[] = (string) ($invite['role_slug'] ?? 'viewer');
    }
    if (!empty($coverage['missing_primary'])) {
        $statuses[] = 'missing';
    }
    if ((int) ($coverage['active_assignment_count'] ?? 0) > 0) {
        $statuses[] = 'active';
    }

    return [
        'departments' => implode(' ', array_values(array_unique(array_filter($departments)))),
        'access_profiles' => implode(' ', array_values(array_unique(array_filter($accessProfiles)))),
        'statuses' => implode(' ', array_values(array_unique(array_filter($statuses)))),
    ];
};
?>

<div class="settings-card-stack settings-governance" style="max-width:1120px;">
    <?php if ($workspaceGovernanceGuideVideoUrl !== ''): ?>
        <?php echo PageGuideVideoUi::assets(); ?>
    <?php endif; ?>
    <section id="workspace-team" class="content-card" style="display:grid;gap:1rem;">
        <div class="workspace-governance-hero">
            <div class="workspace-governance-hero__copy">
                <h2 style="margin:0 0 .4rem 0;">Workspace Team</h2>
                <p style="margin:0;color:#64748b;">Manage members, work ownership, invites, slug history, and ownership handoff for the active workspace without leaving settings.</p>
            </div>
            <div class="workspace-governance-overview">
                <div class="workspace-governance-summary-card">
                    <span>Active workspace</span>
                    <strong><?php echo htmlspecialchars((string) ($workspaceGovernanceWorkspace['name'] ?? 'Workspace')); ?></strong>
                    <small><?php echo htmlspecialchars((string) ($workspaceGovernanceWorkspace['slug'] ?? '')); ?> &middot; Your role <?php echo htmlspecialchars((string) ($workspaceGovernanceActorMembership['role_slug'] ?? 'viewer')); ?></small>
                </div>
                <?php if ($workspaceGovernanceGuideVideoUrl !== ''): ?>
                    <div class="workspace-governance-action-card">
                        <span>Onboarding</span>
                        <p>Clarify how team invites, roles, ownership, and handoff should work during workspace onboarding.</p>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM, 'Workspace Team onboarding guide', 'btn-premium-secondary'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($workspaceGovernanceCanTransferOwnership): ?>
                    <div class="workspace-governance-action-card">
                        <span>Ownership</span>
                        <p>Hand off the workspace owner role to another active member.</p>
                        <button type="button" class="btn-premium-secondary governance-transfer-trigger" data-open-ownership-transfer>
                            Transfer Ownership
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($workspaceGovernanceGuideVideoUrl !== ''): ?>
            <?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM, 'Workspace Team onboarding guide', $workspaceGovernanceGuideVideoUrl); ?>
        <?php endif; ?>

        <?php if (empty($workspaceGovernanceReadiness['ready'])): ?>
            <div style="padding:1rem 1.1rem;border-radius:12px;background:#fff7ed;color:#9a3412;border:1px solid rgba(249,115,22,.22);display:grid;gap:.5rem;">
                <div><strong>Workspace team readiness warning:</strong> <?php echo htmlspecialchars($workspaceGovernanceReadinessError !== '' ? $workspaceGovernanceReadinessError : (string) ($workspaceGovernanceReadiness['customer_message'] ?? 'Workspace team settings are temporarily unavailable until the latest governance migrations are applied.')); ?></div>
                <?php if (!empty($canPlatformBillingAdmin) && !empty($workspaceGovernanceReadiness['issues']) && is_array($workspaceGovernanceReadiness['issues'])): ?>
                    <div style="font-size:.92rem;">Detected issues:
                        <?php echo htmlspecialchars(implode('; ', array_map(static function (array $issue): string {
                            return (string) ($issue['message'] ?? 'Unknown readiness issue.');
                        }, $workspaceGovernanceReadiness['issues']))); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($workspaceGovernanceReadiness['ready'])): ?>
        <form method="POST" class="workspace-security-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
            <input type="hidden" name="tab" value="workspace_governance">
            <input type="hidden" name="workspace_governance_action" value="update_workspace_security">
            <div class="workspace-security-card__header">
                <div>
                    <span class="workspace-security-card__eyebrow">Security</span>
                    <h3>Workspace 2FA</h3>
                    <p>Require every active member to set up account two-factor authentication before entering this workspace.</p>
                </div>
                <div class="workspace-security-card__metric">
                    <strong><?php echo (int) ($workspaceSecurity2FASummary['protected'] ?? 0); ?> / <?php echo (int) ($workspaceSecurity2FASummary['total'] ?? 0); ?></strong>
                    <span>members protected</span>
                </div>
            </div>
            <label class="workspace-security-card__toggle">
                <input type="checkbox" name="require_member_2fa" value="1" <?php echo !empty($workspaceSecuritySettings['require_member_2fa']) ? 'checked' : ''; ?> style="margin-top:.2rem;">
                <span>Require 2FA for this workspace</span>
            </label>
            <?php if (!empty($workspaceSecurity2FASummary['missing'])): ?>
                <div class="workspace-security-card__warning">
                    <strong><?php echo (int) ($workspaceSecurity2FASummary['missing'] ?? 0); ?> active member<?php echo (int) ($workspaceSecurity2FASummary['missing'] ?? 0) === 1 ? '' : 's'; ?> still need 2FA:</strong>
                    <?php echo htmlspecialchars(implode(', ', array_map(static function (array $member): string {
                        return (string) ($member['email'] ?? 'member');
                    }, $workspaceSecurityMissingMembers))); ?><?php echo (int) ($workspaceSecurity2FASummary['missing'] ?? 0) > count($workspaceSecurityMissingMembers) ? ', ...' : ''; ?>
                </div>
            <?php endif; ?>
            <div class="workspace-security-card__actions">
                <button type="submit" class="btn-premium-secondary">Save Workspace 2FA</button>
            </div>
        </form>

        <form method="POST" class="workspace-security-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
            <input type="hidden" name="tab" value="workspace_governance">
            <input type="hidden" name="workspace_governance_action" value="update_automation_readiness_refresh">
            <div class="workspace-security-card__header">
                <div>
                    <span class="workspace-security-card__eyebrow">Automation</span>
                    <h3>Automation readiness refresh</h3>
                    <p>Automatic checks use server resources; manual refresh is always available from the dashboard.</p>
                </div>
                <div class="workspace-security-card__metric">
                    <strong><?php echo htmlspecialchars($automationReadinessRefreshLabel); ?></strong>
                    <span>current cadence</span>
                </div>
            </div>
            <label style="display:grid;gap:.35rem;max-width:340px;">
                <span style="font-weight:700;color:#0f172a;">Refresh cadence</span>
                <select name="automation_readiness_refresh_cadence" style="padding:.75rem;border:1px solid #bfdbfe;border-radius:10px;background:#fff;color:#0f172a;font-weight:600;">
                    <?php foreach ($automationReadinessRefreshOptions as $cadenceKey => $cadenceOption): ?>
                        <?php $cadenceKey = (string) $cadenceKey; ?>
                        <option value="<?php echo htmlspecialchars($cadenceKey); ?>" <?php echo $automationReadinessRefreshCadence === $cadenceKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) ($cadenceOption['label'] ?? ucwords(str_replace('_', ' ', $cadenceKey)))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="workspace-security-card__actions">
                <button type="submit" class="btn-premium-secondary">Save Refresh Cadence</button>
            </div>
        </form>

        <?php if ($workspaceGovernanceInviteUrl !== ''): ?>
            <div style="padding:1rem;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;display:grid;gap:.5rem;">
                <div style="font-weight:700;">Share this invite link</div>
                <div style="word-break:break-all;"><?php echo htmlspecialchars($workspaceGovernanceInviteUrl); ?></div>
                <?php if ($workspaceGovernanceDelivery !== []): ?>
                    <?php if (!empty($workspaceGovernanceDelivery['success'])): ?>
                        <div style="color:#166534;">Invite delivery succeeded<?php echo !empty($workspaceGovernanceDelivery['method']) ? ' via ' . htmlspecialchars((string) $workspaceGovernanceDelivery['method']) : ''; ?>.</div>
                    <?php else: ?>
                        <div style="color:#b45309;">Invite delivery failed: <?php echo htmlspecialchars((string) ($workspaceGovernanceDelivery['error'] ?? 'Unknown mail error.')); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="governance-invite-layout">
            <form method="POST" class="governance-invite-form" data-governance-invite-form data-function-defaults="<?php echo $workspaceGovernanceFunctionDefaultsJson; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                <input type="hidden" name="tab" value="workspace_governance">
                <input type="hidden" name="workspace_governance_action" value="invite_member">
                <div>
                    <h3 style="margin:0 0 .35rem 0;">Invite Member</h3>
                    <p style="margin:0;color:#64748b;font-size:.92rem;">Create a workspace-scoped invite for a teammate. The invite is persisted even if delivery fails, so you can resend it later.</p>
                </div>
                <label style="display:grid;gap:.35rem;">
                    <span style="font-weight:600;">Email</span>
                    <input type="email" name="invite_email" required style="padding:.75rem;border:1px solid var(--border-color);border-radius:10px;">
                </label>
                <label style="display:grid;gap:.35rem;">
                    <span style="font-weight:600;">Role</span>
                    <select name="invite_role_slug" style="padding:.75rem;border:1px solid var(--border-color);border-radius:10px;">
                        <?php foreach ($workspaceGovernanceAllowedRoles as $roleValue => $roleLabel): ?>
                            <?php if ($roleValue === 'owner' && !$workspaceGovernanceCanManageOwners) { continue; } ?>
                            <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($workspaceGovernanceFunctions !== []): ?>
                    <div class="user-function-field governance-invite-functions">
                        <div class="governance-invite-functions__header">
                            <label class="user-function-label" id="governance-invite-functions-label">Work Ownership *</label>
                            <small>Choose the work this teammate will own or support.</small>
                        </div>
                        <div class="governance-invite-functions__summary" data-invite-functions-summary aria-live="polite">
                            <?php echo count($workspaceGovernanceDefaultInviteFunctionIds); ?> selected for this role.
                        </div>
                        <div class="governance-invite-functions__grid" role="group" aria-labelledby="governance-invite-functions-label">
                            <?php foreach ($workspaceGovernanceFunctions as $function): ?>
                                <?php
                                $functionId = (int) ($function['id'] ?? 0);
                                $checked = in_array($functionId, array_map('intval', $workspaceGovernanceDefaultInviteFunctionIds), true);
                                $primaryChecked = $workspaceGovernanceDefaultInvitePrimaryFunctionId === $functionId;
                                $selectedAssignmentType = (string) (($workspaceGovernanceFunctionDefaults[$workspaceGovernanceDefaultInviteRole]['assignment_types'][$functionId] ?? null) ?: ($primaryChecked ? 'owner' : 'contributor'));
                                $checkboxId = 'invite-function-' . $functionId;
                                $primaryId = 'invite-function-primary-' . $functionId;
                                ?>
                                <article class="governance-invite-function-card<?php echo $checked ? ' is-selected' : ''; ?><?php echo $primaryChecked ? ' is-primary' : ''; ?>" data-invite-function-card>
                                    <label class="governance-invite-function-card__choice">
                                        <input id="<?php echo htmlspecialchars($checkboxId); ?>" type="checkbox" name="invite_function_ids[]" value="<?php echo $functionId; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                        <span class="governance-invite-function-card__copy">
                                            <strong><?php echo htmlspecialchars((string) ($function['name'] ?? '')); ?></strong>
                                            <small><?php echo htmlspecialchars((string) ($function['description'] ?? '')); ?></small>
                                        </span>
                                    </label>
                                    <div class="governance-invite-function-card__controls">
                                        <label class="governance-invite-function-card__assignment">
                                            <span>Assignment</span>
                                            <select name="invite_function_assignment_types[<?php echo $functionId; ?>]" class="governance-invite-function-card__type">
                                                <option value="owner" <?php echo $selectedAssignmentType === 'owner' ? 'selected' : ''; ?>>Owner</option>
                                                <option value="temporary_owner" <?php echo $selectedAssignmentType === 'temporary_owner' ? 'selected' : ''; ?>>Temporary owner</option>
                                                <option value="oversight" <?php echo $selectedAssignmentType === 'oversight' ? 'selected' : ''; ?>>Oversight</option>
                                                <option value="contributor" <?php echo $selectedAssignmentType === 'contributor' ? 'selected' : ''; ?>>Contributor</option>
                                            </select>
                                        </label>
                                        <label class="governance-invite-function-card__primary">
                                            <input id="<?php echo htmlspecialchars($primaryId); ?>" type="radio" name="invite_primary_function_id" value="<?php echo $functionId; ?>" <?php echo $primaryChecked ? 'checked' : ''; ?>>
                                            <span>Primary</span>
                                        </label>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <small class="user-function-help governance-invite-functions__help">
                            Selected work ownership is assigned when the invite is accepted. Mark one selected area as primary.
                        </small>
                    </div>
                <?php endif; ?>
                <div>
                    <button type="submit" class="btn-premium-primary">Create Invite</button>
                </div>
            </form>
        </div>

        <?php if ($workspaceGovernanceCanTransferOwnership): ?>
            <div class="governance-transfer-modal" data-ownership-transfer-modal hidden role="dialog" aria-modal="true" aria-labelledby="governance-transfer-title">
                <div class="governance-transfer-modal__panel">
                    <div class="governance-transfer-modal__header">
                        <div>
                            <h3 id="governance-transfer-title">Transfer Ownership</h3>
                            <p>Promote another active member to owner. Leave "keep my owner access" unchecked to demote yourself to a manager role in the same transaction.</p>
                        </div>
                        <button type="button" class="governance-transfer-modal__close" data-close-ownership-transfer aria-label="Close transfer ownership dialog">&times;</button>
                    </div>
                    <form method="POST" class="governance-transfer-modal__form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                        <input type="hidden" name="tab" value="workspace_governance">
                        <input type="hidden" name="workspace_governance_action" value="transfer_ownership">
                        <label class="governance-transfer-modal__field">
                            <span>New owner</span>
                            <select name="target_membership_id" required>
                                <option value="">Choose a member</option>
                                <?php foreach ($workspaceGovernanceTransferTargets as $membership): ?>
                                    <option value="<?php echo (int) ($membership['id'] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($membership['role_slug'] ?? 'viewer')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="governance-transfer-modal__field">
                            <span>My role after transfer</span>
                            <select name="demoted_actor_role">
                                <?php foreach ($workspaceGovernanceAllowedDemotionRoles as $roleValue => $roleLabel): ?>
                                    <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($roleLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="governance-transfer-modal__checkbox">
                            <input type="checkbox" name="retain_actor_ownership" value="1">
                            <span>Keep my owner access too</span>
                        </label>
                        <div class="governance-transfer-modal__actions">
                            <button type="button" class="btn-premium-secondary" data-close-ownership-transfer>Cancel</button>
                            <button type="submit" class="btn-premium-primary">Transfer Ownership</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $workspaceGovernanceActiveFunctionCount = (int) ($workspaceGovernanceOwnershipCoverage['active_function_count'] ?? 0);
    $workspaceGovernanceAssignedMemberCount = (int) ($workspaceGovernanceOwnershipCoverage['assigned_member_count'] ?? 0);
    $workspaceGovernanceMissingPrimaryCount = (int) ($workspaceGovernanceOwnershipCoverage['missing_primary_count'] ?? 0);
    $workspaceGovernanceCoverageComplete = $workspaceGovernanceMissingPrimaryCount === 0;
    ?>

    <section class="content-card governance-coverage-section">
        <div class="governance-coverage-header">
            <div class="governance-coverage-heading">
                <h3>Work Ownership Coverage</h3>
                <p>Audit who is primary, who supports, and which accountability areas still need an owner. Departments stay optional placement; Work Ownership is the accountability layer.</p>
                <span class="governance-coverage-status<?php echo $workspaceGovernanceCoverageComplete ? ' is-ok' : ' is-warning'; ?>">
                    <?php if ($workspaceGovernanceCoverageComplete): ?>
                        Every active area has a primary owner.
                    <?php else: ?>
                        <?php echo $workspaceGovernanceMissingPrimaryCount; ?> area<?php echo $workspaceGovernanceMissingPrimaryCount === 1 ? '' : 's'; ?> still need a primary owner.
                    <?php endif; ?>
                </span>
            </div>
            <div class="governance-coverage-metrics" aria-label="Work Ownership coverage summary">
                <div class="governance-coverage-metric-card">
                    <span>Areas</span>
                    <strong><?php echo $workspaceGovernanceActiveFunctionCount; ?></strong>
                    <small>active accountability areas</small>
                </div>
                <div class="governance-coverage-metric-card">
                    <span>Assigned members</span>
                    <strong><?php echo $workspaceGovernanceAssignedMemberCount; ?></strong>
                    <small>active owners or support</small>
                </div>
                <div class="governance-coverage-metric-card<?php echo $workspaceGovernanceCoverageComplete ? ' is-ok' : ' is-warning'; ?>">
                    <span>Need primary</span>
                    <strong><?php echo $workspaceGovernanceMissingPrimaryCount; ?></strong>
                    <small><?php echo $workspaceGovernanceCoverageComplete ? 'ready for handoff' : 'ready to assign'; ?></small>
                </div>
            </div>
        </div>

        <?php if ($workspaceGovernanceCoverageRows === []): ?>
            <div style="padding:1rem;border:1px dashed var(--border-color);border-radius:12px;color:#64748b;">No active Work Ownership areas are available yet.</div>
        <?php else: ?>
            <div class="governance-coverage-filterbar">
                <span class="governance-coverage-filterbar__label">Filter view</span>
                <div class="governance-coverage-filters" aria-label="Work Ownership coverage filters">
                    <label>
                        <span>Department</span>
                        <select data-governance-coverage-filter="department">
                            <option value="">All departments</option>
                            <?php foreach ($workspaceGovernanceDepartmentFilterOptions as $filterValue => $filterLabel): ?>
                                <option value="<?php echo htmlspecialchars((string) $filterValue); ?>"><?php echo htmlspecialchars((string) $filterLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Access Profile</span>
                        <select data-governance-coverage-filter="access">
                            <option value="">All access profiles</option>
                            <?php foreach ($workspaceGovernanceAccessFilterOptions as $filterValue => $filterLabel): ?>
                                <option value="<?php echo htmlspecialchars((string) $filterValue); ?>"><?php echo htmlspecialchars((string) $filterLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Status</span>
                        <select data-governance-coverage-filter="status">
                            <option value="">All statuses</option>
                            <option value="missing">Needs primary</option>
                            <option value="active">Has active assignment</option>
                            <option value="invited">Pending invite</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="governance-coverage-grid" data-governance-coverage-list>
                <?php foreach ($workspaceGovernanceCoverageRows as $coverage): ?>
                    <?php
                    $functionId = (int) ($coverage['function_id'] ?? 0);
                    $tokens = $workspaceGovernanceCoverageTokens((array) $coverage);
                    $primaryMembers = (array) ($coverage['primary_members'] ?? []);
                    $supportingMembers = (array) ($coverage['supporting_members'] ?? []);
                    $pendingPrimaryInvites = (array) ($coverage['pending_primary_invites'] ?? []);
                    $pendingSupportingInvites = (array) ($coverage['pending_supporting_invites'] ?? []);
                    $coverageNeedsPrimary = !empty($coverage['missing_primary']);
                    $coverageHasPendingPrimary = $pendingPrimaryInvites !== [];
                    $coverageStatusLabel = $coverageNeedsPrimary ? ($coverageHasPendingPrimary ? 'Invite pending' : 'Needs primary') : 'Covered';
                    $coverageStatusClass = $coverageNeedsPrimary ? ($coverageHasPendingPrimary ? 'pending' : 'warning') : 'ok';
                    ?>
                    <article
                        class="governance-coverage-card<?php echo $coverageNeedsPrimary ? ' is-missing-primary' : ''; ?><?php echo $coverageHasPendingPrimary ? ' has-pending-primary' : ''; ?>"
                        data-governance-coverage-card
                        data-departments="<?php echo htmlspecialchars($tokens['departments']); ?>"
                        data-access-profiles="<?php echo htmlspecialchars($tokens['access_profiles']); ?>"
                        data-statuses="<?php echo htmlspecialchars($tokens['statuses']); ?>"
                    >
                        <div class="governance-coverage-card__header">
                            <div>
                                <strong><?php echo htmlspecialchars((string) ($coverage['name'] ?? 'Work Ownership')); ?></strong>
                                <small><?php echo htmlspecialchars((string) ($coverage['description'] ?? '')); ?></small>
                            </div>
                            <span class="governance-coverage-badge governance-coverage-badge--<?php echo $coverageStatusClass; ?>">
                                <?php echo htmlspecialchars($coverageStatusLabel); ?>
                            </span>
                        </div>

                        <div class="governance-coverage-roles">
                            <div class="governance-coverage-role<?php echo $coverageNeedsPrimary ? ' needs-owner' : ''; ?>">
                                <div class="governance-coverage-role__header">
                                    <span class="governance-coverage-label">Primary owner</span>
                                    <small>Accountable</small>
                                </div>
                                <?php if ($primaryMembers !== []): ?>
                                    <?php foreach ($primaryMembers as $member): ?>
                                        <?php
                                        $memberLabel = trim((string) ($member['display_name'] ?? ''));
                                        $memberLabel = $memberLabel !== '' ? $memberLabel : (string) ($member['email'] ?? 'Member');
                                        $memberInitial = strtoupper(substr(ltrim($memberLabel), 0, 1) ?: 'M');
                                        ?>
                                        <a class="governance-coverage-person" href="user_edit.php?id=<?php echo (int) ($member['user_id'] ?? 0); ?>">
                                            <span class="governance-coverage-avatar" aria-hidden="true"><?php echo htmlspecialchars($memberInitial); ?></span>
                                            <span class="governance-coverage-person__copy">
                                                <strong><?php echo htmlspecialchars($memberLabel); ?></strong>
                                                <small><?php echo htmlspecialchars((string) (($member['department_name'] ?? '') !== '' ? $member['department_name'] : 'Unassigned')); ?></small>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php elseif ($pendingPrimaryInvites !== []): ?>
                                    <?php foreach ($pendingPrimaryInvites as $invite): ?>
                                        <span class="governance-coverage-person governance-coverage-person--pending">
                                            <span class="governance-coverage-avatar" aria-hidden="true">P</span>
                                            <span class="governance-coverage-person__copy">
                                                <strong><?php echo htmlspecialchars((string) ($invite['email'] ?? 'Pending invite')); ?></strong>
                                                <small>Pending invite</small>
                                            </span>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="governance-coverage-empty">
                                        <strong>No primary yet</strong>
                                        <small>Set one active teammate as accountable.</small>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="governance-coverage-role">
                                <div class="governance-coverage-role__header">
                                    <span class="governance-coverage-label">Support</span>
                                    <small>Backup coverage</small>
                                </div>
                                <?php if ($supportingMembers !== []): ?>
                                    <?php foreach (array_slice($supportingMembers, 0, 4) as $member): ?>
                                        <?php
                                        $memberLabel = trim((string) ($member['display_name'] ?? ''));
                                        $memberLabel = $memberLabel !== '' ? $memberLabel : (string) ($member['email'] ?? 'Member');
                                        $memberInitial = strtoupper(substr(ltrim($memberLabel), 0, 1) ?: 'M');
                                        ?>
                                        <a class="governance-coverage-person" href="user_edit.php?id=<?php echo (int) ($member['user_id'] ?? 0); ?>">
                                            <span class="governance-coverage-avatar" aria-hidden="true"><?php echo htmlspecialchars($memberInitial); ?></span>
                                            <span class="governance-coverage-person__copy">
                                                <strong><?php echo htmlspecialchars($memberLabel); ?></strong>
                                                <small><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($member['assignment_type'] ?? 'contributor')))); ?></small>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                    <?php if (count($supportingMembers) > 4): ?>
                                        <span class="governance-coverage-more">+<?php echo count($supportingMembers) - 4; ?> more</span>
                                    <?php endif; ?>
                                <?php elseif ($pendingSupportingInvites !== []): ?>
                                    <?php foreach (array_slice($pendingSupportingInvites, 0, 3) as $invite): ?>
                                        <span class="governance-coverage-person governance-coverage-person--pending">
                                            <span class="governance-coverage-avatar" aria-hidden="true">P</span>
                                            <span class="governance-coverage-person__copy">
                                                <strong><?php echo htmlspecialchars((string) ($invite['email'] ?? 'Pending invite')); ?></strong>
                                                <small>Pending invite</small>
                                            </span>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="governance-coverage-empty">
                                        <strong>No support assigned</strong>
                                        <small>Add a backup when this area needs shared coverage.</small>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($workspaceGovernanceActiveMembers !== []): ?>
                            <div class="governance-coverage-action-panel">
                                <div class="governance-coverage-action-panel__header">
                                    <strong>Assign coverage</strong>
                                </div>
                                <div class="governance-coverage-actions">
                                <form method="POST" class="governance-coverage-action">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="tab" value="workspace_governance">
                                    <input type="hidden" name="workspace_governance_action" value="assign_work_ownership">
                                    <input type="hidden" name="function_id" value="<?php echo $functionId; ?>">
                                    <input type="hidden" name="assignment_type" value="owner">
                                    <input type="hidden" name="make_primary" value="1">
                                    <label class="governance-coverage-action__field">
                                        <span>Primary owner</span>
                                        <select name="membership_id" aria-label="Choose primary owner for <?php echo htmlspecialchars((string) ($coverage['name'] ?? 'Work Ownership')); ?>">
                                            <?php foreach ($workspaceGovernanceActiveMembers as $member): ?>
                                                <option value="<?php echo (int) ($member['id'] ?? 0); ?>"><?php echo htmlspecialchars(trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?: (string) ($member['email'] ?? 'Member')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="btn-premium-secondary btn-premium-sm">Set primary</button>
                                </form>
                                <form method="POST" class="governance-coverage-action">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="tab" value="workspace_governance">
                                    <input type="hidden" name="workspace_governance_action" value="assign_work_ownership">
                                    <input type="hidden" name="function_id" value="<?php echo $functionId; ?>">
                                    <input type="hidden" name="assignment_type" value="contributor">
                                    <label class="governance-coverage-action__field">
                                        <span>Support teammate</span>
                                        <select name="membership_id" aria-label="Choose supporting contributor for <?php echo htmlspecialchars((string) ($coverage['name'] ?? 'Work Ownership')); ?>">
                                            <?php foreach ($workspaceGovernanceActiveMembers as $member): ?>
                                                <option value="<?php echo (int) ($member['id'] ?? 0); ?>"><?php echo htmlspecialchars(trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?: (string) ($member['email'] ?? 'Member')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="btn-premium-secondary btn-premium-sm">Add support</button>
                                </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <div class="governance-coverage-empty-filter" data-governance-coverage-empty hidden>No Work Ownership areas match those filters.</div>
            </div>
        <?php endif; ?>
    </section>

    <section class="content-card" style="display:grid;gap:1rem;">
        <div>
            <h3 style="margin:0 0 .35rem 0;">Members</h3>
            <p style="margin:0;color:#64748b;font-size:.92rem;">Owners and admins can manage roles. Work Ownership shows who owns or supports each accountability area.</p>
        </div>
        <div style="display:grid;gap:.85rem;">
            <?php foreach ($workspaceGovernanceMembers as $membership): ?>
                <?php
                $membershipId = (int) ($membership['id'] ?? 0);
                $membershipUserId = (int) ($membership['user_id'] ?? 0);
                $membershipStatus = (string) ($membership['membership_status'] ?? 'active');
                $membershipRole = (string) ($membership['role_slug'] ?? 'viewer');
                $membershipWorkOwnership = (array) ($workspaceGovernanceMemberWorkOwnership[$membershipUserId] ?? []);
                $canShowOwnerOption = $workspaceGovernanceCanManageOwners || $membershipRole === 'owner';
                ?>
                <div style="padding:1rem;border:1px solid var(--border-color);border-radius:14px;display:grid;gap:.75rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                        <div>
                            <div style="font-weight:700;color:var(--midnight-black);"><?php echo htmlspecialchars((string) (($membership['first_name'] ?? '') !== '' ? trim((string) ($membership['first_name'] ?? '') . ' ' . (string) ($membership['last_name'] ?? '')) : ($membership['email'] ?? ''))); ?></div>
                            <div style="color:#64748b;font-size:.92rem;"><?php echo htmlspecialchars((string) ($membership['email'] ?? '')); ?></div>
                        </div>
                        <div style="text-align:right;color:#64748b;font-size:.92rem;">
                            <div><?php echo htmlspecialchars($membershipRole); ?><?php echo !empty($membership['is_owner']) ? ' &middot; owner' : ''; ?></div>
                            <div><?php echo htmlspecialchars($membershipStatus); ?></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;color:#64748b;font-size:.9rem;">
                        <strong style="color:#334155;">Work Ownership</strong>
                        <?php if ($membershipWorkOwnership !== []): ?>
                            <?php foreach ($membershipWorkOwnership as $assignment): ?>
                                <span class="users-function-chip<?php echo !empty($assignment['is_primary']) ? ' user-view-function-chip-primary' : ''; ?>" title="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($assignment['assignment_type'] ?? 'contributor')))); ?>">
                                    <span><?php echo htmlspecialchars((string) ($assignment['name'] ?? 'Function')); ?><?php echo !empty($assignment['is_primary']) ? ' (Primary)' : ''; ?></span>
                                </span>
                            <?php endforeach; ?>
                        <?php elseif ($membershipStatus === 'active'): ?>
                            <span class="users-function-chip users-function-missing"><span>Missing ownership</span></span>
                        <?php else: ?>
                            <span class="admin-muted">No active ownership</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;">
                        <form method="POST" style="display:grid;gap:.65rem;padding:.85rem;border:1px solid rgba(15,23,42,.08);border-radius:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                            <input type="hidden" name="tab" value="workspace_governance">
                            <input type="hidden" name="workspace_governance_action" value="update_member_role">
                            <input type="hidden" name="membership_id" value="<?php echo $membershipId; ?>">
                            <label style="display:grid;gap:.35rem;">
                                <span style="font-weight:600;">Role</span>
                                <select name="role_slug" style="padding:.7rem;border:1px solid var(--border-color);border-radius:10px;">
                                    <?php foreach ($workspaceGovernanceAllowedRoles as $roleValue => $roleLabel): ?>
                                        <?php if ($roleValue === 'owner' && !$canShowOwnerOption) { continue; } ?>
                                        <option value="<?php echo htmlspecialchars($roleValue); ?>" <?php echo $membershipRole === $roleValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div>
                                <button type="submit" class="btn-premium-secondary">Update Role</button>
                            </div>
                        </form>
                        <form method="POST" style="display:grid;gap:.65rem;padding:.85rem;border:1px solid rgba(15,23,42,.08);border-radius:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                            <input type="hidden" name="tab" value="workspace_governance">
                            <input type="hidden" name="membership_id" value="<?php echo $membershipId; ?>">
                            <?php if ($membershipStatus === 'active'): ?>
                                <input type="hidden" name="workspace_governance_action" value="remove_member">
                                <div style="font-weight:600;">Remove from workspace</div>
                                <div style="color:#64748b;font-size:.92rem;">This keeps the global user account intact and removes workspace access.</div>
                                <div><button type="submit" class="btn-premium-secondary">Remove Member</button></div>
                            <?php else: ?>
                                <input type="hidden" name="workspace_governance_action" value="restore_member">
                                <div style="font-weight:600;">Restore membership</div>
                                <div style="color:#64748b;font-size:.92rem;">Reactivate this membership in the current workspace.</div>
                                <div><button type="submit" class="btn-premium-secondary">Restore Member</button></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="content-card" style="display:grid;gap:1rem;">
        <div>
            <h3 style="margin:0 0 .35rem 0;">Pending Invites</h3>
            <p style="margin:0;color:#64748b;font-size:.92rem;">Pending invites are workspace-local. Resending rotates the acceptance token. Revoking leaves no usable join link behind.</p>
        </div>
        <?php if ($workspaceGovernanceInvites === []): ?>
            <div style="padding:1rem;border:1px dashed var(--border-color);border-radius:12px;color:#64748b;">No pending invites right now.</div>
        <?php else: ?>
            <div style="display:grid;gap:.85rem;">
                <?php foreach ($workspaceGovernanceInvites as $invite): ?>
                    <div style="padding:1rem;border:1px solid var(--border-color);border-radius:14px;display:grid;gap:.75rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:700;color:var(--midnight-black);"><?php echo htmlspecialchars((string) ($invite['email'] ?? '')); ?></div>
                                <div style="color:#64748b;font-size:.92rem;"><?php echo htmlspecialchars((string) ($invite['role_slug'] ?? 'viewer')); ?> &middot; expires <?php echo htmlspecialchars((string) ($invite['expires_at'] ?? '')); ?></div>
                                <?php $inviteFunctionAssignments = (array) ($invite['function_assignments'] ?? []); ?>
                                <?php if ($inviteFunctionAssignments !== []): ?>
                                    <div style="color:#64748b;font-size:.9rem;margin-top:.25rem;">
                                        Work ownership:
                                        <?php echo htmlspecialchars(implode(', ', array_map(static function (array $assignment): string {
                                            return (string) ($assignment['name'] ?? '');
                                        }, $inviteFunctionAssignments))); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="color:#64748b;font-size:.92rem;text-align:right;">
                                <div><?php echo htmlspecialchars((string) ($invite['invite_status'] ?? 'pending')); ?></div>
                                <div>Delivery <?php echo htmlspecialchars((string) ($invite['delivery_status'] ?? 'pending')); ?></div>
                                <div>Updated <?php echo htmlspecialchars((string) ($invite['updated_at'] ?? '')); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($invite['last_delivery_attempt_at']) || !empty($invite['delivery_error'])): ?>
                            <div style="color:#64748b;font-size:.92rem;">
                                <?php if (!empty($invite['last_delivery_attempt_at'])): ?>
                                    Last delivery attempt <?php echo htmlspecialchars((string) $invite['last_delivery_attempt_at']); ?>
                                <?php endif; ?>
                                <?php if (!empty($invite['delivery_error'])): ?>
                                    <?php if (!empty($invite['last_delivery_attempt_at'])): ?>&middot; <?php endif; ?>
                                    Error: <?php echo htmlspecialchars((string) $invite['delivery_error']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                            <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                                <input type="hidden" name="tab" value="workspace_governance">
                                <input type="hidden" name="workspace_governance_action" value="resend_invite">
                                <input type="hidden" name="invite_id" value="<?php echo (int) ($invite['id'] ?? 0); ?>">
                                <button type="submit" class="btn-premium-secondary">Resend Invite</button>
                            </form>
                            <?php if (($invite['delivery_status'] ?? 'pending') === 'failed'): ?>
                                <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="tab" value="workspace_governance">
                                    <input type="hidden" name="workspace_governance_action" value="retry_invite_delivery">
                                    <input type="hidden" name="invite_id" value="<?php echo (int) ($invite['id'] ?? 0); ?>">
                                    <button type="submit" class="btn-premium-secondary">Retry Delivery</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                                <input type="hidden" name="tab" value="workspace_governance">
                                <input type="hidden" name="workspace_governance_action" value="revoke_invite">
                                <input type="hidden" name="invite_id" value="<?php echo (int) ($invite['id'] ?? 0); ?>">
                                <button type="submit" class="btn-premium-secondary">Revoke Invite</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="content-card" style="display:grid;gap:1rem;">
        <div>
            <h3 style="margin:0 0 .35rem 0;">Governance History</h3>
            <p style="margin:0;color:#64748b;font-size:.92rem;">Recent invite, membership, ownership, and slug changes for this workspace. Current filter: <?php echo htmlspecialchars($workspaceGovernanceHistoryFilter); ?>.</p>
        </div>
        <?php if ($workspaceGovernanceHistory === []): ?>
            <div style="padding:1rem;border:1px dashed var(--border-color);border-radius:12px;color:#64748b;">No governance history yet.</div>
        <?php else: ?>
            <div style="display:grid;gap:.75rem;">
                <?php foreach ($workspaceGovernanceHistory as $eventRow): ?>
                    <?php
                    $eventType = (string) ($eventRow['event_type'] ?? '');
                    $eventMetadata = (array) ($eventRow['metadata'] ?? []);
                    $eventActor = trim((string) ($eventRow['actor_display_name'] ?? (($eventRow['actor_email'] ?? '') !== '' ? $eventRow['actor_email'] : 'system')));
                    $eventTarget = trim((string) ($eventRow['target_label'] ?? (($eventRow['target_user_email'] ?? '') ?: ($eventRow['invite_email'] ?? '') ?: ($eventMetadata['new_slug'] ?? ''))));
                    ?>
                    <div style="padding:.95rem;border:1px solid var(--border-color);border-radius:12px;display:grid;gap:.35rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div style="font-weight:700;color:var(--midnight-black);"><?php echo htmlspecialchars((string) ($workspaceGovernanceEventLabels[$eventType] ?? ucwords(str_replace('_', ' ', $eventType)))); ?></div>
                            <div style="color:#64748b;font-size:.92rem;"><?php echo htmlspecialchars((string) ($eventRow['created_at'] ?? '')); ?></div>
                        </div>
                        <div style="color:#64748b;font-size:.92rem;">Category: <?php echo htmlspecialchars((string) ($eventRow['category'] ?? 'workspace')); ?> &middot; Actor: <?php echo htmlspecialchars($eventActor); ?><?php echo $eventTarget !== '' ? ' &middot; Target: ' . htmlspecialchars($eventTarget) : ''; ?></div>
                        <?php if ($eventMetadata !== []): ?>
                            <div style="color:#334155;font-size:.92rem;">
                                <?php if (!empty($eventMetadata['reason'])): ?>
                                    <?php echo htmlspecialchars((string) $eventMetadata['reason']); ?>
                                <?php elseif (!empty($eventMetadata['delivery_status']) || !empty($eventMetadata['error'])): ?>
                                    Delivery <?php echo htmlspecialchars((string) ($eventMetadata['delivery_status'] ?? 'pending')); ?><?php echo !empty($eventMetadata['error']) ? ': ' . htmlspecialchars((string) $eventMetadata['error']) : ''; ?>
                                <?php elseif (!empty($eventMetadata['previous_slug']) || !empty($eventMetadata['new_slug'])): ?>
                                    <?php echo htmlspecialchars((string) ($eventMetadata['previous_slug'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_slug'] ?? '')); ?>
                                <?php elseif (!empty($eventMetadata['previous_role']) || !empty($eventMetadata['new_role'])): ?>
                                    <?php echo htmlspecialchars((string) ($eventMetadata['previous_role'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_role'] ?? '')); ?>
                                <?php elseif (!empty($eventMetadata['previous_status']) || !empty($eventMetadata['new_status'])): ?>
                                    <?php echo htmlspecialchars((string) ($eventMetadata['previous_status'] ?? '')); ?> &rarr; <?php echo htmlspecialchars((string) ($eventMetadata['new_status'] ?? '')); ?>
                                <?php elseif (!empty($eventMetadata['email'])): ?>
                                    <?php echo htmlspecialchars((string) $eventMetadata['email']); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars(json_encode($eventMetadata, JSON_UNESCAPED_SLASHES)); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
        <?php endif; ?>
</div>

<?php if ($workspaceGovernanceFunctions !== [] || $workspaceGovernanceCanTransferOwnership): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-governance-invite-form]');
    const transferModal = document.querySelector('[data-ownership-transfer-modal]');
    const openTransferButtons = document.querySelectorAll('[data-open-ownership-transfer]');
    const coverageCards = Array.prototype.slice.call(document.querySelectorAll('[data-governance-coverage-card]'));
    const coverageEmpty = document.querySelector('[data-governance-coverage-empty]');
    const coverageFilters = {
        department: document.querySelector('[data-governance-coverage-filter="department"]'),
        access: document.querySelector('[data-governance-coverage-filter="access"]'),
        status: document.querySelector('[data-governance-coverage-filter="status"]')
    };
    let transferReturnFocus = null;

    function coverageCardHasToken(card, attribute, token) {
        if (!token) {
            return true;
        }

        return (card.getAttribute(attribute) || '').split(/\s+/).includes(token);
    }

    function applyCoverageFilters() {
        if (!coverageCards.length) {
            return;
        }

        const department = coverageFilters.department ? coverageFilters.department.value : '';
        const access = coverageFilters.access ? coverageFilters.access.value : '';
        const status = coverageFilters.status ? coverageFilters.status.value : '';
        let visibleCount = 0;

        coverageCards.forEach(function (card) {
            const visible = coverageCardHasToken(card, 'data-departments', department)
                && coverageCardHasToken(card, 'data-access-profiles', access)
                && coverageCardHasToken(card, 'data-statuses', status);
            card.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        });

        if (coverageEmpty) {
            coverageEmpty.hidden = visibleCount > 0;
        }
    }

    Object.keys(coverageFilters).forEach(function (key) {
        if (coverageFilters[key]) {
            coverageFilters[key].addEventListener('change', applyCoverageFilters);
        }
    });
    applyCoverageFilters();

    if (form) {
        const roleSelect = form.querySelector('select[name="invite_role_slug"]');
        const summary = form.querySelector('[data-invite-functions-summary]');
        const defaults = JSON.parse(form.getAttribute('data-function-defaults') || '{}');
        const functionCheckboxSelector = 'input[name="invite_function_ids[]"]';
        const assignmentSelectSelector = 'select[name^="invite_function_assignment_types["]';
        const primaryRadioSelector = 'input[name="invite_primary_function_id"]';
        let applyingDefaults = false;

        function roleDefaults(role) {
            return defaults[role] || defaults.viewer || { function_ids: [], primary_function_id: 0, assignment_types: {} };
        }

        function cardForControl(control) {
            return control ? control.closest('[data-invite-function-card]') : null;
        }

        function selectedCheckboxes() {
            return Array.prototype.slice.call(form.querySelectorAll(functionCheckboxSelector)).filter(function (checkbox) {
                return checkbox.checked;
            });
        }

        function cardName(card) {
            const label = card ? card.querySelector('.governance-invite-function-card__copy strong') : null;
            return label ? label.textContent.trim() : '';
        }

        function syncSummary() {
            if (!summary) {
                return;
            }

            const checkedBoxes = selectedCheckboxes();
            const selectedCount = checkedBoxes.length;
            const primaryCard = form.querySelector('[data-invite-function-card].is-primary');
            const primaryName = cardName(primaryCard);
            const countLabel = selectedCount === 1 ? '1 work ownership selected' : selectedCount + ' work ownership selections';

            summary.textContent = primaryName !== ''
                ? countLabel + ' - ' + primaryName + ' is primary.'
                : countLabel + ' - choose one primary area.';
        }

        function syncCardState(card) {
            if (!card) {
                return;
            }

            const checkbox = card.querySelector(functionCheckboxSelector);
            const primaryRadio = card.querySelector(primaryRadioSelector);
            card.classList.toggle('is-selected', Boolean(checkbox && checkbox.checked));
            card.classList.toggle('is-primary', Boolean(primaryRadio && primaryRadio.checked));
        }

        function syncAllCards() {
            form.querySelectorAll('[data-invite-function-card]').forEach(syncCardState);
            syncSummary();
        }

        function ensurePrimarySelection(preferredRadio) {
            if (preferredRadio && preferredRadio.checked) {
                const preferredCard = cardForControl(preferredRadio);
                const preferredCheckbox = preferredCard ? preferredCard.querySelector(functionCheckboxSelector) : null;
                if (preferredCheckbox) {
                    preferredCheckbox.checked = true;
                }
            }

            const checkedBoxes = selectedCheckboxes();
            const hasSelectedPrimary = checkedBoxes.some(function (checkbox) {
                const card = cardForControl(checkbox);
                const primaryRadio = card ? card.querySelector(primaryRadioSelector) : null;
                return Boolean(primaryRadio && primaryRadio.checked);
            });

            if (hasSelectedPrimary) {
                return;
            }

            form.querySelectorAll(primaryRadioSelector).forEach(function (radio) {
                radio.checked = false;
            });

            const fallbackCard = checkedBoxes[0] ? cardForControl(checkedBoxes[0]) : null;
            const fallbackRadio = fallbackCard ? fallbackCard.querySelector(primaryRadioSelector) : null;
            if (fallbackRadio) {
                fallbackRadio.checked = true;
            }
        }

        function applyDefaultsForRole(role) {
            const config = roleDefaults(role);
            const functionIds = (config.function_ids || []).map(function (id) { return String(id); });
            const primaryFunctionId = String(config.primary_function_id || functionIds[0] || '');
            const assignmentTypes = config.assignment_types || {};

            applyingDefaults = true;
            form.querySelectorAll(functionCheckboxSelector).forEach(function (checkbox) {
                checkbox.checked = functionIds.includes(String(checkbox.value));
            });
            form.querySelectorAll(assignmentSelectSelector).forEach(function (select) {
                const functionId = select.name.match(/\[(\d+)\]/);
                const key = functionId ? functionId[1] : '';
                select.value = assignmentTypes[key] || (role === 'owner' ? 'owner' : 'contributor');
            });
            form.querySelectorAll(primaryRadioSelector).forEach(function (radio) {
                radio.checked = String(radio.value) === primaryFunctionId;
            });
            ensurePrimarySelection();
            syncAllCards();
            applyingDefaults = false;
        }

        form.addEventListener('change', function (event) {
            if (applyingDefaults || event.target === roleSelect) {
                return;
            }

            if (event.target.matches(functionCheckboxSelector)) {
                const card = cardForControl(event.target);
                const primaryRadio = card ? card.querySelector(primaryRadioSelector) : null;
                if (!event.target.checked && primaryRadio && primaryRadio.checked) {
                    primaryRadio.checked = false;
                }
                ensurePrimarySelection();
                syncAllCards();
                form.dataset.functionsTouched = '1';
                return;
            }

            if (event.target.matches(primaryRadioSelector)) {
                ensurePrimarySelection(event.target);
                syncAllCards();
                form.dataset.functionsTouched = '1';
                return;
            }

            if (event.target.matches(assignmentSelectSelector)) {
                const card = cardForControl(event.target);
                const checkbox = card ? card.querySelector(functionCheckboxSelector) : null;
                if (checkbox && !checkbox.checked) {
                    checkbox.checked = true;
                }
                ensurePrimarySelection();
                syncAllCards();
                form.dataset.functionsTouched = '1';
            }
        });

        if (roleSelect) {
            roleSelect.addEventListener('change', function () {
                if (form.dataset.functionsTouched === '1') {
                    return;
                }
                applyDefaultsForRole(roleSelect.value);
            });
        }

        ensurePrimarySelection();
        syncAllCards();
    }

    function closeTransferModal() {
        if (!transferModal || transferModal.hasAttribute('hidden')) {
            return;
        }

        transferModal.setAttribute('hidden', '');
        document.body.classList.remove('has-governance-transfer-modal');
        if (transferReturnFocus && typeof transferReturnFocus.focus === 'function') {
            transferReturnFocus.focus();
        }
        transferReturnFocus = null;
    }

    function openTransferModal(trigger) {
        if (!transferModal) {
            return;
        }

        transferReturnFocus = trigger || document.activeElement;
        transferModal.removeAttribute('hidden');
        document.body.classList.add('has-governance-transfer-modal');
        const firstField = transferModal.querySelector('select[name="target_membership_id"]');
        const closeButton = transferModal.querySelector('[data-close-ownership-transfer]');
        window.setTimeout(function () {
            (firstField || closeButton || transferModal).focus();
        }, 0);
    }

    openTransferButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            openTransferModal(button);
        });
    });

    if (transferModal) {
        transferModal.querySelectorAll('[data-close-ownership-transfer]').forEach(function (button) {
            button.addEventListener('click', closeTransferModal);
        });
        transferModal.addEventListener('click', function (event) {
            if (event.target === transferModal) {
                closeTransferModal();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeTransferModal();
            }
        });
    }
});
</script>
<?php endif; ?>
