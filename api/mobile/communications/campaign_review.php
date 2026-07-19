<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Authorization;
use CRM\Database;

function mobileCampaignReviewEmailRows(int $workspaceId, int $limit, int $userId, bool $canViewAllContacts): array
{
    if (!Database::tableExists('communications')) {
        return [];
    }

    $where = ["COALESCE(channel, 'email') = 'email'"];
    $params = [];
    if (Database::columnExists('communications', 'workspace_id')) {
        $where[] = 'cm.workspace_id = ?';
        $params[] = $workspaceId;
    }
    if (Database::columnExists('communications', 'direction')) {
        $where[] = "cm.direction = 'outbound'";
    }
    if (!$canViewAllContacts) {
        $where[] = '(cm.contact_id IS NULL OR c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
        $params[] = $userId;
    }

    $statusSelect = Database::columnExists('communications', 'status') ? 'cm.status' : "'sent'";
    $recipientSelect = Database::columnExists('communications', 'to_email') ? 'cm.to_email' : "''";

    return Database::query(
        "SELECT cm.id, 'email' AS channel, {$statusSelect} AS status, cm.subject,
                LEFT(COALESCE(cm.body, ''), 240) AS preview,
                cm.contact_id,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
                {$recipientSelect} AS recipient,
                cm.created_at AS sent_at,
                cm.created_at
         FROM communications cm
         LEFT JOIN contacts c ON c.id = cm.contact_id" . (Database::columnExists('communications', 'workspace_id') ? ' AND c.workspace_id = cm.workspace_id' : '') . "
         WHERE " . implode(' AND ', $where) . "
         ORDER BY cm.created_at DESC, cm.id DESC
         LIMIT {$limit}",
        $params
    );
}

function mobileCampaignReviewSmsRows(int $workspaceId, int $limit, int $userId, bool $canViewAllContacts): array
{
    if (!Database::tableExists('sms_messages')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    if (Database::columnExists('sms_messages', 'workspace_id')) {
        $where[] = 'sm.workspace_id = ?';
        $params[] = $workspaceId;
    }
    if (Database::columnExists('sms_messages', 'direction')) {
        $where[] = "sm.direction = 'outbound'";
    }
    if (!$canViewAllContacts) {
        $where[] = '(sm.contact_id IS NULL OR c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
        $params[] = $userId;
    }

    return Database::query(
        "SELECT sm.id, 'sms' AS channel, sm.status, 'SMS Message' AS subject,
                LEFT(COALESCE(sm.message_body, ''), 240) AS preview,
                sm.contact_id,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
                sm.to_number AS recipient,
                sm.created_at AS sent_at,
                sm.created_at
         FROM sms_messages sm
         LEFT JOIN contacts c ON c.id = sm.contact_id" . (Database::columnExists('sms_messages', 'workspace_id') ? ' AND c.workspace_id = sm.workspace_id' : '') . "
         WHERE " . implode(' AND ', $where) . "
         ORDER BY sm.created_at DESC, sm.id DESC
         LIMIT {$limit}",
        $params
    );
}

function mobileCampaignReviewWhatsAppRows(int $workspaceId, int $limit, int $userId, bool $canViewAllContacts): array
{
    if (!Database::tableExists('whatsapp_messages')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    if (Database::columnExists('whatsapp_messages', 'workspace_id')) {
        $where[] = 'wm.workspace_id = ?';
        $params[] = $workspaceId;
    }
    if (Database::columnExists('whatsapp_messages', 'direction')) {
        $where[] = "wm.direction = 'outbound'";
    }
    if (!$canViewAllContacts) {
        $where[] = '(wm.contact_id IS NULL OR c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
        $params[] = $userId;
    }
    $bodySelect = Database::columnExists('whatsapp_messages', 'message_body')
        ? 'wm.message_body'
        : (Database::columnExists('whatsapp_messages', 'body') ? 'wm.body' : "''");
    $recipientSelect = Database::columnExists('whatsapp_messages', 'to_number')
        ? 'wm.to_number'
        : (Database::columnExists('whatsapp_messages', 'phone') ? 'wm.phone' : "''");

    return Database::query(
        "SELECT wm.id, 'whatsapp' AS channel, wm.status, 'WhatsApp Message' AS subject,
                LEFT(COALESCE({$bodySelect}, ''), 240) AS preview,
                wm.contact_id,
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
                COALESCE({$recipientSelect}, c.phone, '') AS recipient,
                COALESCE(wm.sent_at, wm.created_at) AS sent_at,
                wm.created_at
         FROM whatsapp_messages wm
         LEFT JOIN contacts c ON c.id = wm.contact_id" . (Database::columnExists('whatsapp_messages', 'workspace_id') ? ' AND c.workspace_id = wm.workspace_id' : '') . "
         WHERE " . implode(' AND ', $where) . "
         ORDER BY wm.created_at DESC, wm.id DESC
         LIMIT {$limit}",
        $params
    );
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$userId = (int) ($auth['user_id'] ?? 0);
$canViewAllContacts = Authorization::can('contacts.view_all', $user);
mobileRequireCommunicationRuntime($workspaceId, $user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

try {
    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 40, 100);
    $channel = strtolower(trim((string) ($_GET['channel'] ?? 'all')));
    $rows = [];
    if ($channel === 'all' || $channel === 'email') {
        $rows = array_merge($rows, mobileCampaignReviewEmailRows($workspaceId, $limit, $userId, $canViewAllContacts));
    }
    if ($channel === 'all' || $channel === 'sms') {
        $rows = array_merge($rows, mobileCampaignReviewSmsRows($workspaceId, $limit, $userId, $canViewAllContacts));
    }
    if ($channel === 'all' || $channel === 'whatsapp') {
        $rows = array_merge($rows, mobileCampaignReviewWhatsAppRows($workspaceId, $limit, $userId, $canViewAllContacts));
    }

    usort($rows, static function (array $left, array $right): int {
        return strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''));
    });
    $rows = array_slice($rows, 0, $limit);

    mobileJson([
        'success' => true,
        'data' => [
            'items' => array_map('mobileCampaignReviewSummary', $rows),
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'campaign_review_failed',
    ], 422);
}
