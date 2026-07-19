<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_conversation_resolver.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Database;
use CRM\Authorization;
use CRM\Modules\Contacts;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\EmailService;
use CRM\Services\SMSService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\WhatsAppService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceSmsChannelConfigService;

function mobileComposeCommunicationsColumnExists(string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'communications'
               AND COLUMN_NAME = ?",
            [$column]
        );
        $cache[$column] = ((int) ($row['count'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function mobileComposeStoreSmsCommunication(
    int $contactId,
    string $to,
    string $body,
    int $userId,
    array $providerResult
): int {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        throw new RuntimeException('An active workspace is required to store SMS communications.');
    }

    $uuidBytes = random_bytes(16);
    $uuidBytes[6] = chr(ord($uuidBytes[6]) & 0x0f | 0x40);
    $uuidBytes[8] = chr(ord($uuidBytes[8]) & 0x3f | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuidBytes), 4));

    $columns = ['workspace_id', 'uuid', 'contact_id', 'channel', 'direction', 'subject', 'body', 'status', 'created_at'];
    $values = ['?', '?', '?', "'sms'", "'outbound'", '?', '?', "'sent'", 'NOW()'];
    $params = [$workspaceId, $uuid, $contactId, 'SMS Message', $body];

    if (mobileComposeCommunicationsColumnExists('metadata')) {
        $columns[] = 'metadata';
        $values[] = '?';
        $params[] = json_encode([
            'source' => 'mobile_compose',
            'provider' => 'twilio',
            'provider_sid' => $providerResult['sid'] ?? null,
            'to_number' => $to,
        ]);
    }
    if (mobileComposeCommunicationsColumnExists('from_email')) {
        $columns[] = 'from_email';
        $values[] = '?';
        $params[] = '';
    }
    if (mobileComposeCommunicationsColumnExists('to_email')) {
        $columns[] = 'to_email';
        $values[] = '?';
        $params[] = $to;
    }

    Database::execute(
        "INSERT INTO communications (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', $values) . ")",
        $params
    );

    $communicationId = (int) Database::lastInsertId();
    try {
        (new ContactIntelligenceService())->computeAndPersist($contactId);
    } catch (Throwable $e) {
        error_log('mobile compose SMS contact intelligence refresh failed: ' . $e->getMessage());
    }
    try {
        (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
            'contact_id' => $contactId,
            'channel' => 'sms',
            'user_id' => $userId,
        ]);
    } catch (Throwable $e) {
        error_log('mobile compose SMS target refresh failed: ' . $e->getMessage());
    }

    return $communicationId;
}

function mobileComposeStoreSmsMessage(
    int $contactId,
    string $to,
    string $body,
    int $userId,
    string $fromNumber,
    array $providerResult
): int {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0) {
        throw new RuntimeException('An active workspace is required to store SMS messages.');
    }

    $uuidBytes = random_bytes(16);
    $uuidBytes[6] = chr(ord($uuidBytes[6]) & 0x0f | 0x40);
    $uuidBytes[8] = chr(ord($uuidBytes[8]) & 0x3f | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuidBytes), 4));

    Database::execute(
        "INSERT INTO sms_messages (
            workspace_id,
            uuid,
            contact_id,
            user_id,
            to_number,
            from_number,
            message_body,
            message_type,
            status,
            direction,
            provider,
            provider_message_id,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'text', 'sent', 'outbound', 'twilio', ?, NOW())",
        [
            $workspaceId,
            $uuid,
            $contactId,
            $userId,
            (string) ($providerResult['to'] ?? $to),
            $fromNumber,
            $body,
            (string) ($providerResult['sid'] ?? ''),
        ]
    );

    return (int) Database::lastInsertId();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$input = mobileRequestBody();
$channel = strtolower(trim((string) ($input['channel'] ?? '')));
$contactId = (int) ($input['contact_id'] ?? 0);
$body = trim((string) ($input['body'] ?? ''));
$subject = trim((string) ($input['subject'] ?? ''));

if ($contactId <= 0) {
    mobileJson(['error' => 'contact_id is required.'], 422);
}
if ($body === '') {
    mobileJson(['error' => 'Message body is required.'], 422);
}
if (!in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
    mobileJson(['error' => 'Unsupported compose channel.'], 422);
}
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

$contact = (new Contacts())->getById($contactId);
if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
    mobileJson(['error' => 'Contact not found or not accessible.'], 404);
}

$communicationGate = new WorkspaceCommunicationGateService();
if (in_array($channel, ['email', 'whatsapp'], true) && !$communicationGate->isChannelRuntimeReady($workspaceId, $channel, $user)) {
    mobileJson($communicationGate->jsonChannelBlockPayload($workspaceId, $channel, $user), 403);
}
$installer = new WorkspaceSkillInstallService();
$catalog = new WorkspaceSkillCatalogService();
if ($channel === 'sms'
    && ($catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)
        || (!Authorization::isSuperAdmin($user) && !$installer->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)))) {
    mobileJson(['error' => 'SMS Channel is not installed for this workspace.'], 403);
}
if ($channel === 'sms' && !Authorization::can('sms.send', $user)) {
    mobileJson(['error' => 'SMS send permission is required.'], 403);
}
try {
    $communicationId = 0;

    if ($channel === 'email') {
        $to = trim((string) ($input['to'] ?? $contact['email'] ?? ''));
        if ($to === '') {
            mobileJson(['error' => 'A contact email is required to send this email.'], 422);
        }
        if ($subject === '') {
            mobileJson(['error' => 'Email subject is required.'], 422);
        }

        $result = (new EmailService())->sendImmediateDetailed($contactId, $to, $subject, $body, [
            'user_id' => $userId,
            'sender_profile' => 'outreach',
        ]);
        if (empty($result['success'])) {
            mobileJson(['error' => trim((string) ($result['error'] ?? 'Failed to send email.'))], 422);
        }
        $communicationId = (int) ($result['communication_id'] ?? 0);
    } elseif ($channel === 'whatsapp') {
        $phone = trim((string) ($input['to'] ?? $contact['phone'] ?? ''));
        if ($phone === '') {
            mobileJson(['error' => 'A contact phone number is required for WhatsApp.'], 422);
        }

        $whatsApp = new WhatsAppService();
        if (!$whatsApp->isWithin24HourWindow($contactId)) {
            mobileJson([
                'error' => "Custom WhatsApp messages can only be sent within 24 hours of the customer's last message.",
            ], 422);
        }

        $to = $whatsApp->normalizePhoneNumber($phone);
        $result = $whatsApp->sendTextMessage($to, $body);
        $whatsApp->storeMessage($contactId, $to, 'text', $body, [
            'user_id' => $userId,
            'whatsapp_message_id' => $result['messages'][0]['id'] ?? null,
        ]);
        $row = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ?
               AND contact_id = ?
               AND channel = 'whatsapp'
               AND direction = 'outbound'
             ORDER BY id DESC
             LIMIT 1",
            [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $contactId]
        );
        $communicationId = (int) ($row['id'] ?? 0);
    } else {
        $phone = trim((string) ($input['to'] ?? $contact['phone'] ?? ''));
        if ($phone === '') {
            mobileJson(['error' => 'A contact phone number is required for SMS.'], 422);
        }

        $smsConfig = new WorkspaceSmsChannelConfigService();
        $sms = new SMSService($smsConfig);
        $result = $sms->sendSMS($phone, $body, ['workspace_id' => $workspaceId]);
        $runtimeConfig = $smsConfig->runtimeConfig($workspaceId);
        mobileComposeStoreSmsMessage($contactId, $phone, $body, $userId, (string) ($runtimeConfig['from_number'] ?? ''), $result);
        $communicationId = mobileComposeStoreSmsCommunication($contactId, $phone, $body, $userId, $result);
    }

    mobileJson([
        'success' => true,
        'data' => [
            'channel' => $channel,
            'contact_id' => $contactId,
            'communication_id' => $communicationId > 0 ? $communicationId : null,
            'route' => $communicationId > 0 ? '/inbox/' . $communicationId : null,
            'message' => ucfirst($channel) . ' sent successfully.',
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile compose failed for contact_id=' . $contactId . ' user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => trim((string) $e->getMessage()) ?: 'Could not send message.'], 422);
}
