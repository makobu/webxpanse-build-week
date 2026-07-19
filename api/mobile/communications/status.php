<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

function mobileWhatsAppStatusLatestTimestamp(string $table, string $column, int $workspaceId): ?string
{
    if ($workspaceId <= 0
        || !Database::tableExists($table)
        || !Database::columnExists($table, 'workspace_id')
        || !Database::columnExists($table, $column)) {
        return null;
    }

    $row = Database::queryOne(
        "SELECT MAX({$column}) AS latest_at FROM {$table} WHERE workspace_id = ?",
        [$workspaceId]
    );
    $value = trim((string) ($row['latest_at'] ?? ''));

    return $value !== '' ? $value : null;
}

function mobileWhatsAppStatusNextDigestAt(bool $enabled, string $digestTime): ?string
{
    if (!$enabled) {
        return null;
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($digestTime), $matches)) {
        $matches = [null, '7', '00'];
    }
    $hour = max(0, min(23, (int) $matches[1]));
    $minute = max(0, min(59, (int) $matches[2]));
    $now = new DateTimeImmutable('now');
    $next = $now->setTime($hour, $minute, 0);
    if ($next <= $now) {
        $next = $next->modify('+1 day');
    }

    return $next->format(DATE_ATOM);
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$channel = (new WorkspaceCommunicationGateService())->channelStatus($workspaceId, 'whatsapp', $user);
$catalog = new WorkspaceSkillCatalogService();
$installer = new WorkspaceSkillInstallService($catalog);
$assistantKey = WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT;
$assistantInstalled = !$catalog->isGloballyDeactivated($assistantKey)
    && $installer->isInstalled($workspaceId, $assistantKey);
$assistantReadiness = $assistantInstalled
    ? $installer->buildReadinessForModule($workspaceId, (int) ($user['id'] ?? 0), $assistantKey)
    : [];
$assistantRuntime = $assistantInstalled
    ? (new WorkspaceAssistantConfigService())->whatsappRuntimeConfig($workspaceId)
    : [];
$assistantEnabled = $assistantInstalled && !empty($assistantRuntime['enabled']);
$digestEnabled = $assistantEnabled && !empty($assistantRuntime['digest_enabled']);
$digestTime = trim((string) ($assistantRuntime['digest_time'] ?? '07:00')) ?: '07:00';
$canManageAssistant = Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user);
$lastActivityAt = $assistantInstalled
    ? mobileWhatsAppStatusLatestTimestamp('whatsapp_assistant_messages', 'created_at', $workspaceId)
    : null;
$lastDigestAt = $assistantInstalled
    ? mobileWhatsAppStatusLatestTimestamp('whatsapp_assistant_digest_log', 'sent_at', $workspaceId)
    : null;

mobileJson([
    'success' => true,
    'data' => [
        'whatsapp' => [
            'installed' => !empty($channel['installed']),
            'ready' => !empty($channel['ready']),
            'outbound_ready' => !empty($channel['outbound_ready']),
            'inbound_ready' => !empty($channel['inbound_ready']),
            'status' => !empty($channel['ready']) ? 'ready' : (string) ($channel['reason_code'] ?? 'needs_setup'),
            'message' => (string) ($channel['message'] ?? ''),
            'sender_identity' => (string) ($channel['health']['display_phone_number'] ?? ''),
            'connection_mode' => (string) ($channel['health']['connection_mode'] ?? ''),
            'blockers' => array_values((array) ($channel['blockers'] ?? [])),
            'next_action' => (string) ($channel['next_action'] ?? ''),
            'setup_url' => (string) ($channel['setup_url'] ?? WorkspaceCommunicationGateService::WHATSAPP_SETUP_URL),
            'can_manage_setup' => !empty($channel['can_manage']),
        ],
        'assistant' => [
            'installed' => $assistantInstalled,
            'enabled' => $assistantEnabled,
            'ready' => $assistantInstalled && !empty($assistantReadiness['ready']),
            'status' => $assistantInstalled ? (string) ($assistantReadiness['status'] ?? 'needs_setup') : 'not_installed',
            'message' => $assistantInstalled
                ? (string) ($assistantReadiness['message'] ?? 'Complete optional WhatsApp Assistant setup.')
                : 'Optional. Install only for internal team commands and digests.',
            'sender_mode' => (string) ($assistantRuntime['sender_mode'] ?? ''),
            'sender_identity' => (string) ($assistantRuntime['assistant_phone_number'] ?? ''),
            'authorized_number_count' => (int) ($assistantReadiness['authorized_number_count'] ?? 0),
            'digest_enabled' => $digestEnabled,
            'digest_time' => $digestTime,
            'next_digest_at' => mobileWhatsAppStatusNextDigestAt($digestEnabled, $digestTime),
            'last_digest_at' => $lastDigestAt,
            'last_activity_at' => $lastActivityAt,
            'blockers' => array_values((array) ($assistantReadiness['blockers'] ?? [])),
            'setup_url' => 'workspace_skills.php?module=' . $assistantKey . '#setup',
            'can_manage_setup' => $canManageAssistant,
        ],
        'generated_at' => gmdate('c'),
    ],
]);
