<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/constants.php';

CRM\Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['workspace:', 'state:', 'actor:', 'rollback-days::']);
$workspaceId = (int) ($options['workspace'] ?? 0);
$state = strtolower(trim((string) ($options['state'] ?? '')));
$actorUserId = (int) ($options['actor'] ?? 0);
$rollbackDays = (int) ($options['rollback-days'] ?? 14);

if ($workspaceId <= 0 || $actorUserId <= 0 || !in_array($state, CRM\Services\OrganizationIntelligenceProfileService::ROLLOUT_STATES, true)) {
    fwrite(STDERR, "Usage: php cli/organization_intelligence_rollout.php --workspace=ID --state=shadow|v2_enabled|rolled_back|v1_retired --actor=SUPERADMIN_ID [--rollback-days=14]\n");
    exit(2);
}

$actor = CRM\Database::queryOne(
    "SELECT u.id, u.role, wm.is_owner
     FROM users u LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
     WHERE u.id = ? LIMIT 1",
    [$workspaceId, $actorUserId]
);
if (!$actor || (strtolower((string) ($actor['role'] ?? '')) !== 'superadmin' && empty($actor['is_owner']))) {
    fwrite(STDERR, "Rollout changes require a superadmin or active workspace owner actor.\n");
    exit(3);
}

$profile = (new CRM\Services\OrganizationIntelligenceProfileService())
    ->setRolloutState($workspaceId, $state, $actorUserId, $rollbackDays);

echo json_encode([
    'success' => true,
    'workspace_id' => $workspaceId,
    'engine_version' => $profile['engine_version'] ?? null,
    'rollout_state' => $profile['rollout_state'] ?? null,
    'rollback_until' => $profile['rollback_until'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
