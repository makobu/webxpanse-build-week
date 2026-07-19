<?php
/**
 * Conversation View Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/helpers.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\UnifiedInbox;
use CRM\Modules\ConversationThreads;
use CRM\Modules\CompanyProfile;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\ConversationEmailReplyService;
use CRM\Services\ConversationIntelligenceService;
use CRM\Services\EmailService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\WhatsAppService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

/**
 * Normalize subject for email threading (e.g. "Re: Re: Subject" => "subject").
 */
function normalizeEmailSubject(string $subject): string
{
    return ConversationEmailReplyService::normalizeEmailSubject($subject);
}

function buildReplySubject(string $subject): string
{
    return ConversationEmailReplyService::buildReplySubject($subject);
}

function decodeHtmlEntitiesDeep(string $text, int $passes = 3): string
{
    $value = $text;
    for ($i = 0; $i < $passes; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }
    return $value;
}

function extractVisibleEmailContent(string $body): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $body);

    // Strip common reply/forward quoted-history markers.
    $patterns = [
        '/\n---\s*Original Message\s*---[\s\S]*$/i',
        '/\nOn .{0,200} wrote:\s*[\s\S]*$/i',
        '/\nFrom:\s.*\nDate:\s.*\nSubject:\s.*[\s\S]*$/i',
    ];
    foreach ($patterns as $p) {
        $text = preg_replace($p, '', $text);
    }

    // Handle inline quoted-history markers (no newline before "On ... wrote:"),
    // including Unicode spaces used by Gmail (e.g. U+202F).
    $quoteMarkerPattern = '/On\s+.{0,260}?wrote:\s*/iu';
    if (preg_match($quoteMarkerPattern, $text, $m, PREG_OFFSET_CAPTURE)) {
        $cutAt = (int) $m[0][1];
        if ($cutAt > 0) {
            $text = substr($text, 0, $cutAt);
        }
    }

    $inlineTailMarkers = [
        '/From:\s+.+<[^>]+>\s*$/iu',
        '/Sent:\s+.+$/iu',
    ];
    foreach ($inlineTailMarkers as $marker) {
        if (preg_match($marker, $text, $m, PREG_OFFSET_CAPTURE)) {
            $cutAt = (int) $m[0][1];
            if ($cutAt > 0) {
                $text = substr($text, 0, $cutAt);
            }
        }
    }

    // Remove quoted lines.
    $lines = explode("\n", $text);
    $cleanLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*>/', $line)) {
            continue;
        }
        $cleanLines[] = $line;
    }

    $text = implode("\n", $cleanLines);
    // Remove common signature blocks from replies to keep thread concise.
    $text = preg_replace('/\n(?:best regards|regards|thanks(?: and regards)?|kind regards|sincerely)[\s\S]*$/i', '', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", trim($text));
    return trim($text);
}

function cleanConversationBody(string $body, string $channel): string
{
    $value = decodeHtmlEntitiesDeep($body);
    if ($channel === 'email') {
        // Normalize HTML email body to readable plain text for thread bubbles.
        $value = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $value);
        $value = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $value);
        $value = preg_replace('/<\s*p[^>]*>/i', '', $value);
        $value = strip_tags($value);
        $value = extractVisibleEmailContent($value);
    }
    return $value;
}

/**
 * Resolve an email thread using participant emails, subject normalization and message-id linkage.
 */
function resolveEmailThreadCommunications(array $communication): array
{
    return ConversationEmailReplyService::resolveEmailThreadCommunications($communication);
}

function communicationColumnExists(string $column): bool
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
    } catch (\Throwable $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

function currentConversationWorkspaceId(): int
{
    static $workspaceId = null;
    if ($workspaceId !== null) {
        return $workspaceId;
    }

    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    return $workspaceId;
}

function resolveConversationReplyEmail(array $communication, array $threadCommunications, array $contact = []): string
{
    return ConversationEmailReplyService::resolveReplyEmail($communication, $threadCommunications, $contact);
}

function insertOutboundCommunicationFallback(int $contactId, string $channel, string $subject, string $body, string $status = 'sent', array $extra = []): int
{
    $workspaceId = currentConversationWorkspaceId();
    $insertUuid = uuid_v4();
    $createdAt = (string) ($extra['created_at'] ?? date('Y-m-d H:i:s'));
    $columns = ['workspace_id', 'uuid', 'contact_id', 'channel', 'direction', 'subject', 'body', 'status', 'created_at'];
    $values = ['?', '?', '?', '?', "'outbound'", '?', '?', '?', '?'];
    $params = [
        $workspaceId,
        $insertUuid,
        $contactId,
        $channel,
        $subject,
        $body,
        $status,
        $createdAt,
    ];

    if (communicationColumnExists('thread_key') && isset($extra['thread_key'])) {
        $columns[] = 'thread_key';
        $values[] = '?';
        $params[] = trim((string) $extra['thread_key']);
    }
    if (communicationColumnExists('metadata')) {
        $columns[] = 'metadata';
        $values[] = '?';
        $meta = $extra['metadata'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!isset($meta['fallback_created_at'])) {
            $meta['fallback_created_at'] = $createdAt;
        }
        $params[] = json_encode($meta);
    }
    if (communicationColumnExists('from_email') && isset($extra['from_email'])) {
        $columns[] = 'from_email';
        $values[] = '?';
        $params[] = (string) $extra['from_email'];
    }
    if (communicationColumnExists('to_email') && isset($extra['to_email'])) {
        $columns[] = 'to_email';
        $values[] = '?';
        $params[] = (string) $extra['to_email'];
    }
    if (communicationColumnExists('message_id') && isset($extra['message_id'])) {
        $columns[] = 'message_id';
        $values[] = '?';
        $params[] = (string) $extra['message_id'];
    }
    if (communicationColumnExists('in_reply_to') && isset($extra['in_reply_to'])) {
        $columns[] = 'in_reply_to';
        $values[] = '?';
        $params[] = (string) $extra['in_reply_to'];
    }
    if (communicationColumnExists('email_id') && isset($extra['email_id']) && (int) $extra['email_id'] > 0) {
        $columns[] = 'email_id';
        $values[] = '?';
        $params[] = (int) $extra['email_id'];
    }

    Database::execute(
        "INSERT INTO communications (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', $values) . ")",
        $params
    );
    $communicationId = (int) Database::lastInsertId();
    if ($communicationId <= 0) {
        $inserted = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ?
               AND uuid = ?
             LIMIT 1",
            [$workspaceId, $insertUuid]
        );
        $communicationId = (int) ($inserted['id'] ?? 0);
    }
    try {
        (new \CRM\Services\ContactIntelligenceService())->computeAndPersist($contactId);
    } catch (\Throwable $e) {
        error_log('conversation contact intelligence refresh failed: ' . $e->getMessage());
    }
    try {
        (new \CRM\Services\TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
            'contact_id' => (int) $contactId,
            'channel' => (string) ($channel ?? 'email'),
        ]);
    } catch (\Throwable $e) {
        error_log('conversation target intelligence refresh failed: ' . $e->getMessage());
    }

    return $communicationId;
}

function isValidTimezone(string $timezone): bool
{
    try {
        new \DateTimeZone($timezone);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function normalizeTimezoneIdentifier(string $rawTimezone): string
{
    $tz = trim($rawTimezone);
    if ((str_starts_with($tz, '"') && str_ends_with($tz, '"')) || (str_starts_with($tz, "'") && str_ends_with($tz, "'"))) {
        $tz = trim($tz, "\"'");
    }
    if ($tz === '') {
        return '';
    }

    if (isValidTimezone($tz)) {
        return $tz;
    }

    $normalized = strtolower($tz);
    $normalized = str_replace(['.', ',', '_'], [' ', ' ', '/'], $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    $aliases = [
        'nairobi' => 'Africa/Nairobi',
        'kenya' => 'Africa/Nairobi',
        'kampala' => 'Africa/Kampala',
        'dar es salaam' => 'Africa/Dar_es_Salaam',
        'lagos' => 'Africa/Lagos',
        'accra' => 'Africa/Accra',
        'london' => 'Europe/London',
        'paris' => 'Europe/Paris',
        'berlin' => 'Europe/Berlin',
        'dubai' => 'Asia/Dubai',
        'kolkata' => 'Asia/Kolkata',
        'india' => 'Asia/Kolkata',
        'new york' => 'America/New_York',
        'los angeles' => 'America/Los_Angeles',
        'chicago' => 'America/Chicago',
        'denver' => 'America/Denver',
    ];
    foreach ($aliases as $needle => $iana) {
        if (strpos($normalized, $needle) !== false) {
            return $iana;
        }
    }

    if (preg_match('/^utc\s*([+-])\s*(\d{1,2})(?::?(\d{2}))?$/i', $normalized, $m)) {
        $sign = $m[1] === '-' ? -1 : 1;
        $hours = (int) $m[2];
        $mins = isset($m[3]) ? (int) $m[3] : 0;
        $offset = ($sign * (($hours * 60) + $mins));
        $name = timezone_name_from_abbr('', $offset * 60, 0);
        if (is_string($name) && $name !== '') {
            return $name;
        }
    }

    return '';
}

function resolveConversationTimezone(array $communication): string
{
    $candidates = [];

    // Prefer app-level configured timezone first.
    $envTz = trim((string) ($_ENV['APP_TIMEZONE'] ?? ''));
    if ($envTz !== '') {
        $candidates[] = $envTz;
    }

    // Company profile timezone should drive conversation display timezone.
    try {
        $companyProfile = (new CompanyProfile())->get();
        $companyTz = trim((string) ($companyProfile['company_timezone'] ?? ''));
        if ($companyTz !== '') {
            $candidates[] = $companyTz;
        }
        // Heuristic fallback from company location when timezone field is blank/free-text.
        $companyLocation = trim((string) ($companyProfile['company_location'] ?? ''));
        if ($companyLocation !== '') {
            $candidates[] = $companyLocation;
        }
    } catch (\Throwable $e) {
        // Ignore company profile errors and continue with defaults.
    }
    $candidates[] = date_default_timezone_get();
    $candidates[] = 'Africa/Nairobi';
    $candidates[] = 'UTC';

    foreach ($candidates as $tzRaw) {
        $tz = normalizeTimezoneIdentifier((string) $tzRaw);
        if (isValidTimezone($tz)) {
            return $tz;
        }
    }
    return 'UTC';
}

function formatConversationDateTime(?string $dateTime, string $displayTimezone, string $format = 'M j, g:i A'): string
{
    $value = trim((string) $dateTime);
    if ($value === '') {
        return '';
    }

    // Source timezone for stored DB timestamps.
    // Prefer explicit DB_TIMEZONE, else APP_TIMEZONE (Nairobi by default bootstrap).
    $sourceTimezone = normalizeTimezoneIdentifier(trim((string) ($_ENV['DB_TIMEZONE'] ?? ($_ENV['APP_TIMEZONE'] ?? $displayTimezone))));
    if (!isValidTimezone($sourceTimezone)) {
        $sourceTimezone = $displayTimezone;
    }
    $displayTimezone = normalizeTimezoneIdentifier($displayTimezone);
    if (!isValidTimezone($displayTimezone)) {
        $displayTimezone = 'UTC';
    }

    try {
        $dt = new \DateTimeImmutable($value, new \DateTimeZone($sourceTimezone));
        return $dt->setTimezone(new \DateTimeZone($displayTimezone))->format($format);
    } catch (\Throwable $e) {
        try {
            return date($format, strtotime($value));
        } catch (\Throwable $e2) {
            return $value;
        }
    }
}

function sortThreadCommunicationsChronological(array $rows, string $channel = ''): array
{
    // Always keep oldest first, newest last by event timestamp.
    usort($rows, function ($a, $b) {
        $aTs = strtotime((string) ($a['_effective_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['_effective_at'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }
        return $aTs <=> $bTs;
    });
    return $rows;
}

function enrichEmailThreadEffectiveTimestamps(array $rows): array
{
    if (empty($rows)) {
        return $rows;
    }

    $emailIds = [];
    $emailUuids = [];
    foreach ($rows as $r) {
        if (($r['channel'] ?? '') !== 'email' || ($r['direction'] ?? '') !== 'outbound') {
            continue;
        }
        $eid = (int) ($r['email_id'] ?? 0);
        if ($eid > 0) {
            $emailIds[$eid] = true;
        } else {
            $meta = is_string($r['metadata'] ?? '') ? json_decode((string) ($r['metadata'] ?? ''), true) : ($r['metadata'] ?? []);
            $meta = is_array($meta) ? $meta : [];
            $uuid = trim((string) ($meta['email_uuid'] ?? ''));
            if ($uuid !== '') {
                $emailUuids[$uuid] = true;
            }
        }
    }

    $sentAtById = [];
    if (!empty($emailIds)) {
        $idList = array_keys($emailIds);
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $rowsById = Database::query("SELECT id, sent_at FROM emails WHERE id IN ($ph)", $idList);
        foreach ($rowsById as $e) {
            if (!empty($e['sent_at'])) {
                $sentAtById[(int) $e['id']] = (string) $e['sent_at'];
            }
        }
    }

    $sentAtByUuid = [];
    if (!empty($emailUuids)) {
        $uuidList = array_keys($emailUuids);
        $ph = implode(',', array_fill(0, count($uuidList), '?'));
        $rowsByUuid = Database::query("SELECT uuid, sent_at FROM emails WHERE uuid IN ($ph)", $uuidList);
        foreach ($rowsByUuid as $e) {
            if (!empty($e['sent_at']) && !empty($e['uuid'])) {
                $sentAtByUuid[(string) $e['uuid']] = (string) $e['sent_at'];
            }
        }
    }

    foreach ($rows as &$r) {
        $effective = (string) ($r['created_at'] ?? '');
        if (($r['channel'] ?? '') === 'email' && ($r['direction'] ?? '') === 'outbound') {
            $eid = (int) ($r['email_id'] ?? 0);
            if ($eid > 0 && !empty($sentAtById[$eid])) {
                $effective = $sentAtById[$eid];
            } else {
                $meta = is_string($r['metadata'] ?? '') ? json_decode((string) ($r['metadata'] ?? ''), true) : ($r['metadata'] ?? []);
                $meta = is_array($meta) ? $meta : [];
                $uuid = trim((string) ($meta['email_uuid'] ?? ''));
                if ($uuid !== '' && !empty($sentAtByUuid[$uuid])) {
                    $effective = $sentAtByUuid[$uuid];
                } elseif (!empty($meta['fallback_created_at'])) {
                    $effective = (string) $meta['fallback_created_at'];
                }
            }
        }
        $r['_effective_at'] = $effective;
    }
    unset($r);

    return $rows;
}

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user();
$csrfToken = Security::getCsrfToken();
Session::closeWrite();

$inbox = new UnifiedInbox();
$threads = new ConversationThreads();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$activeWorkspaceId = currentConversationWorkspaceId();
$canViewAllConversations = Authorization::can('conversations.view_all', $user);
$ownerScope = $inbox->resolveOwnerScope($_GET['owner_scope'] ?? null, $canViewAllConversations);
$communicationId = (int) ($_GET['id'] ?? 0);
$fallbackContactId = (int) ($_GET['contact_id'] ?? 0);
$fallbackChannel = trim((string) ($_GET['channel'] ?? ''));
if (!in_array($fallbackChannel, ['email', 'whatsapp', 'sms'], true)) {
    $fallbackChannel = '';
}

// Get communication by ID first; fallback to latest for contact/channel when ID is missing/invalid.
$communication = null;
if ($communicationId > 0) {
    $communication = Database::queryOne(
        "SELECT c.*, ct.first_name, ct.last_name, ct.email as contact_email
         FROM communications c
         LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
         WHERE c.workspace_id = ?
           AND c.id = ?",
        [$activeWorkspaceId, $communicationId]
    );
}

if (!$communication && $fallbackContactId > 0 && $fallbackChannel !== '') {
    $communication = Database::queryOne(
        "SELECT c.*, ct.first_name, ct.last_name, ct.email as contact_email
         FROM communications c
         LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
         WHERE c.workspace_id = ?
           AND c.contact_id = ?
           AND c.channel = ?
         ORDER BY c.created_at DESC
         LIMIT 1",
        [$activeWorkspaceId, $fallbackContactId, $fallbackChannel]
    );
    if ($communication) {
        $communicationId = (int) ($communication['id'] ?? 0);
    }
}

if (!$communication) {
    header('Location: inbox.php?owner_scope=' . urlencode($ownerScope));
    exit;
}

if (!$inbox->canUserAccessCommunication($communicationId, $userId, $canViewAllConversations, $ownerScope)) {
    header('Location: inbox.php?owner_scope=' . urlencode($ownerScope));
    exit;
}

// Mark as read
$inbox->markAsRead($communicationId);

// Best-effort only; never let page open depend on outbound provider calls.
if (($communication['channel'] ?? '') === 'whatsapp' && ($communication['direction'] ?? '') === 'inbound') {
    $commMeta = json_decode($communication['metadata'] ?? '{}', true);
    $whatsappMsgId = $commMeta['whatsapp_message_id'] ?? null;
    if (!empty($whatsappMsgId) && !empty($_ENV['CONVERSATION_SEND_READ_RECEIPTS'] ?? '')) {
        try {
            $whatsApp = new WhatsAppService();
            $whatsApp->markMessageAsRead($whatsappMsgId);
        } catch (\Throwable $e) {
            error_log("Conversation: Failed to send WhatsApp read receipt: " . $e->getMessage());
        }
    }
}

// Get thread (all communications in this conversation)
try {
    $thread = $threads->findByCommunication($communicationId);
} catch (\Throwable $e) {
    error_log('Conversation: thread lookup failed for communication ' . $communicationId . ': ' . $e->getMessage());
    $thread = null;
}

try {
    if (($communication['channel'] ?? '') === 'email') {
        $threadCommunications = resolveEmailThreadCommunications($communication);
        if (empty($threadCommunications)) {
            $threadCommunications = $inbox->getByContact((int) ($communication['contact_id'] ?? 0), 100);
        }
    } elseif (!$thread) {
        // If no thread exists or thread sync failed, get all communications for this contact.
        $threadCommunications = $inbox->getByContact((int) ($communication['contact_id'] ?? 0), 50);
    } else {
        $threadCommunications = $threads->getCommunications($thread['id']);
    }
} catch (\Throwable $e) {
    error_log('Conversation: thread communications fallback triggered for communication ' . $communicationId . ': ' . $e->getMessage());
    $threadCommunications = $inbox->getByContact((int) ($communication['contact_id'] ?? 0), 50);
}
$threadCommunications = array_values(array_filter((array) $threadCommunications, static function ($row) use ($inbox, $userId, $canViewAllConversations, $ownerScope): bool {
    $messageId = (int) ($row['id'] ?? 0);
    return $messageId > 0 && $inbox->canUserAccessCommunication($messageId, $userId, $canViewAllConversations, $ownerScope);
}));
$threadCommunications = enrichEmailThreadEffectiveTimestamps($threadCommunications);
$threadCommunications = sortThreadCommunicationsChronological($threadCommunications, (string) ($communication['channel'] ?? ''));
$threadCommunications = is_array($threadCommunications) ? array_values($threadCommunications) : [];
if (empty($threadCommunications) && !empty($communication)) {
    $threadCommunications = [$communication];
}
$conversationTimezone = resolveConversationTimezone($communication);
$conversationThreadState = [];
if ($thread && !empty($thread['_state']) && is_array($thread['_state'])) {
    $conversationThreadState = $thread['_state'];
} elseif ($thread) {
    try {
        $conversationThreadState = (new ConversationIntelligenceService())->buildStateForThread($thread);
    } catch (\Throwable $e) {
        $conversationThreadState = [];
    }
}
$conversationThreadState = is_array($conversationThreadState) ? $conversationThreadState : [];
$conversationThreadKey = trim((string) ($communication['thread_key'] ?? ''));
if ($conversationThreadKey === '' && !empty($thread['thread_key'])) {
    $conversationThreadKey = trim((string) $thread['thread_key']);
}

// Optional AI draft created by auto-responder in review mode.
$aiDraft = null;
try {
    $aiDraft = Database::queryOne(
        "SELECT id, reply_text, confidence, reason_code
         FROM ai_autoresponder_logs
         WHERE communication_id = ?
           AND decision = 'draft'
           AND status = 'pending_review'
           AND reply_text IS NOT NULL
           AND reply_text <> ''
         ORDER BY created_at DESC
         LIMIT 1",
        [$communicationId]
    );
} catch (\Throwable $e) {
    $aiDraft = null;
}

// WhatsApp: 24-hour window and contact phone for replies
$whatsApp24hrWindowOpen = false;
$contactPhone = null;
if (($communication['channel'] ?? '') === 'whatsapp') {
    $contactRow = Database::queryOne(
        "SELECT phone FROM contacts WHERE workspace_id = ? AND id = ?",
        [$activeWorkspaceId, $communication['contact_id']]
    );
    try {
        $whatsAppService = new WhatsAppService();
        $contactPhone = !empty($contactRow['phone']) ? $whatsAppService->normalizePhoneNumber((string) $contactRow['phone']) : null;
        $whatsApp24hrWindowOpen = $contactPhone && $whatsAppService->isWithin24HourWindow($communication['contact_id']);
    } catch (\Throwable $e) {
        $whatsApp24hrWindowOpen = false;
    }
}

// Handle reply
$error = null;
$success = null;
if (($_GET['notice'] ?? '') === 'reply_sent') {
    $success = 'Reply sent successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['thread_action'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $threadAction = trim((string) ($_POST['thread_action'] ?? ''));
        $threadReason = trim((string) ($_POST['thread_reason'] ?? ''));
        try {
            $threadService = new ConversationIntelligenceService();
            if ($threadAction === 'resolve_thread') {
                if ($threadService->updateThreadStatusByCommunication($communicationId, 'resolved', $threadReason !== '' ? $threadReason : 'Resolved by user')) {
                    $success = 'Conversation resolved.';
                } else {
                    $error = 'Unable to resolve conversation.';
                }
            } elseif ($threadAction === 'reopen_thread') {
                if ($threadService->updateThreadStatusByCommunication($communicationId, 'waiting_on_us', $threadReason !== '' ? $threadReason : 'Reopened by user')) {
                    $success = 'Conversation reopened.';
                } else {
                    $error = 'Unable to reopen conversation.';
                }
            }

            if ($success !== null) {
                $thread = $threads->findByCommunication($communicationId);
                if ($thread && !empty($thread['_state']) && is_array($thread['_state'])) {
                    $conversationThreadState = $thread['_state'];
                }
            }
        } catch (\Throwable $e) {
            $error = 'Failed to update conversation state.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $replyBody = trim($_POST['body'] ?? '');
            $hasMediaUpload = !empty($_FILES['media']['tmp_name']) && is_uploaded_file($_FILES['media']['tmp_name'])
                && ($_FILES['media']['error'] ?? 0) === UPLOAD_ERR_OK;

            $needsBody = ($communication['channel'] ?? '') !== 'whatsapp' || !$hasMediaUpload;
            if ($needsBody && $replyBody === '') {
                $error = 'Reply message is required.';
            } elseif ($hasMediaUpload && ($communication['channel'] ?? '') === 'whatsapp' && (!$whatsApp24hrWindowOpen || !$contactPhone)) {
                $error = "Custom messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed.";
            } else {
                // Get contact (email for email channel, phone for WhatsApp)
                $contact = Database::queryOne(
                    "SELECT id, email, phone FROM contacts WHERE workspace_id = ? AND id = ?",
                    [$activeWorkspaceId, $communication['contact_id']]
                );
                $replyTargetEmail = resolveConversationReplyEmail($communication, $threadCommunications, is_array($contact) ? $contact : []);

                if (!$contact) {
                    $error = 'Contact not found. Cannot send reply.';
                } elseif ($communication['channel'] === 'email' && $replyTargetEmail === '') {
                    $error = 'Contact email not found. Cannot send email reply.';
                } elseif ($communication['channel'] === 'whatsapp' && empty($contactPhone)) {
                    $error = 'Contact phone number not found. Cannot send WhatsApp reply.';
                } else {
                    $subject = buildReplySubject((string) ($communication['subject'] ?? 'Message'));
                    $redirectCommunicationId = $communicationId;
                    
                    // For email channel, send actual email
                    if ($communication['channel'] === 'email') {
                        $fullReplyBody = $replyBody;

                        try {
                            $replyResult = (new ConversationEmailReplyService())->sendReply(
                                $communicationId,
                                (int) ($currentUser['id'] ?? 0),
                                (string) $fullReplyBody,
                                (string) $subject,
                                'web'
                            );
                            $redirectCommunicationId = (int) ($replyResult['communication_id'] ?? 0) ?: $communicationId;
                            $success = !empty($replyResult['sync_error'])
                                ? 'Reply sent successfully. Audit recorded a post-send sync warning.'
                                : 'Reply sent successfully.';
                        } catch (\Exception $e) {
                            $error = $e->getMessage() ?: 'Failed to send email reply. Please try again.';
                        }
                    } elseif ($communication['channel'] === 'whatsapp') {
                        // WhatsApp: only send custom message if 24-hour window is open
                        if (!$whatsApp24hrWindowOpen || !$contactPhone) {
                            $error = "Custom messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed. Use WhatsApp Compose to send a template message.";
                        } else {
                            try {
                                $whatsApp = new WhatsAppService();
                                $messageType = 'text';
                                $storeBody = $replyBody;
                                $result = null;
                                $storeMediaId = null;
                                $storeMimeType = null;

                                if ($hasMediaUpload) {
                                    $file = $_FILES['media'];
                                    $uploadError = $file['error'] ?? UPLOAD_ERR_OK;
                                    if ($uploadError !== UPLOAD_ERR_OK) {
                                        $error = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                                            ? 'File too large. Images: max 5MB. Videos: max 16MB.'
                                            : 'File upload failed. Please try again.';
                                    } else {
                                    $tmpPath = $file['tmp_name'];
                                    $mimeType = $file['type'] ?? '';
                                    if (($mimeType === '' || $mimeType === 'application/octet-stream') && function_exists('finfo_open')) {
                                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                        if ($finfo) {
                                            $detected = finfo_file($finfo, $tmpPath);
                                            finfo_close($finfo);
                                            if (is_string($detected) && $detected !== '') {
                                                $mimeType = $detected;
                                            }
                                        }
                                    }
                                    if ($mimeType === '') {
                                        $mimeType = 'application/octet-stream';
                                    }
                                    $fileSize = (int) ($file['size'] ?? 0);

                                    $allowedImages = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                                    $allowedVideos = ['video/mp4', 'video/3gpp', 'video/quicktime'];
                                    $maxImageSize = 5 * 1024 * 1024;
                                    $maxVideoSize = 16 * 1024 * 1024;

                                    if (in_array($mimeType, $allowedImages, true)) {
                                        if ($fileSize > $maxImageSize) {
                                            $error = 'Image must be 5MB or less.';
                                        } else {
                                            $mediaId = $whatsApp->uploadMedia($tmpPath, $mimeType);
                                            if ($mediaId) {
                                                $result = $whatsApp->sendImageMessage($contactPhone, $mediaId, $replyBody ?: null);
                                                $messageType = 'image';
                                                $storeBody = $replyBody ?: '[Image]';
                                                $storeMediaId = $mediaId;
                                                $storeMimeType = $mimeType;
                                            } else {
                                                $error = 'Failed to upload image.';
                                            }
                                        }
                                    } elseif (in_array($mimeType, $allowedVideos, true)) {
                                        if ($fileSize > $maxVideoSize) {
                                            $error = 'Video must be 16MB or less.';
                                        } else {
                                            $mediaId = $whatsApp->uploadMedia($tmpPath, $mimeType);
                                            if ($mediaId) {
                                                $result = $whatsApp->sendVideoMessage($contactPhone, $mediaId, $replyBody ?: null);
                                                $messageType = 'video';
                                                $storeBody = $replyBody ?: '[Video]';
                                                $storeMediaId = $mediaId;
                                                $storeMimeType = $mimeType;
                                            } else {
                                                $error = 'Failed to upload video.';
                                            }
                                        }
                                    } else {
                                        $error = 'Only images (JPEG, PNG, GIF, WebP) and videos (MP4, 3GP) are supported.';
                                    }
                                    }
                                } else {
                                    $result = $whatsApp->sendTextMessage($contactPhone, $replyBody);
                                }

                                if ($result && empty($error)) {
                                    $whatsappMessageId = $result['messages'][0]['id'] ?? null;
                                    $storeOptions = [
                            'user_id' => $currentUser['id'] ?? null,
                                        'whatsapp_message_id' => $whatsappMessageId
                                    ];
                                    if ($storeMediaId !== null) {
                                        $storeOptions['media_id'] = $storeMediaId;
                                    }
                                    if ($storeMimeType !== null) {
                                        $storeOptions['mime_type'] = $storeMimeType;
                                    }
                                    $whatsApp->storeMessage(
                                        $communication['contact_id'],
                                        $contactPhone,
                                        $messageType,
                                        $storeBody,
                                        $storeOptions
                                    );
                                    $latestOutbound = Database::queryOne(
                                        "SELECT id
                                         FROM communications
                                         WHERE workspace_id = ?
                                           AND contact_id = ?
                                           AND channel = 'whatsapp'
                                           AND direction = 'outbound'
                                         ORDER BY created_at DESC, id DESC
                                         LIMIT 1",
                                        [$activeWorkspaceId, (int) $communication['contact_id']]
                                    );
                                    if (!empty($latestOutbound['id'])) {
                                        $redirectCommunicationId = (int) $latestOutbound['id'];
                                    } else {
                                        $redirectCommunicationId = insertOutboundCommunicationFallback(
                                            (int) $communication['contact_id'],
                                            'whatsapp',
                                            'WhatsApp Message',
                                            (string) $storeBody,
                                            'sent',
                                            [
                                                'thread_key' => $conversationThreadKey,
                                                'metadata' => [
                                                    'source' => 'conversation_fallback',
                                                    'message_type' => (string) $messageType,
                                                    'whatsapp_message_id' => (string) ($whatsappMessageId ?? '')
                                                ]
                                            ]
                                        );
                                    }
                                    $success = 'Reply sent successfully.';
                                }
                            } catch (\Exception $e) {
                                $error = $e->getMessage();
                                if (strpos($error, '24') !== false || stripos($error, 'window') !== false || stripos($error, 'session') !== false) {
                                    $error = "The 24-hour window has closed. Custom messages can only be sent within 24 hours of the customer's last message. Use WhatsApp Compose to send a template message.";
                                }
                            }
                        }
                    } else {
                        // Other channels (e.g. SMS): just create communication record (communications.uuid is CHAR(36))
                        $uuid = uuid_v4();
                        Database::execute(
                            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status)
                             VALUES (?, ?, ?, ?, 'outbound', ?, ?, 'sent')",
                            [
                                $activeWorkspaceId,
                                $uuid,
                                $communication['contact_id'],
                                $communication['channel'],
                                $subject,
                                $replyBody
                            ]
                        );
                        try {
                            (new \CRM\Services\ContactIntelligenceService())->computeAndPersist((int) $communication['contact_id']);
                        } catch (\Throwable $e) {
                            error_log('conversation direct reply contact intelligence refresh failed: ' . $e->getMessage());
                        }
                        $success = 'Reply sent successfully.';
                    }
                    
                    // Refresh page only on success (keep error visible otherwise)
                    if ($success) {
                        try {
                            Database::execute(
                                "UPDATE ai_autoresponder_logs
                                 SET status = 'sent', updated_at = NOW()
                                 WHERE communication_id = ?
                                   AND decision = 'draft'
                                   AND status = 'pending_review'",
                                [$communicationId]
                            );
                        } catch (\Throwable $e) {
                            // Ignore if table is unavailable.
                        }
                        $finalId = !empty($redirectCommunicationId) ? (int) $redirectCommunicationId : (int) $communicationId;
                        header('Location: conversation.php?id=' . $finalId . '&contact_id=' . (int) $communication['contact_id'] . '&channel=' . urlencode((string) ($communication['channel'] ?? '')) . '&owner_scope=' . urlencode($ownerScope) . '&notice=reply_sent');
                        exit;
                    }
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            error_log("Reply error: " . $e->getMessage());
        }
    }
}

$pageTitle = ($communication['subject'] ?? 'Conversation') . ' - ' . brandProductName();
$conversationExperienceMode = (new UIExperienceService())->modeForUser($user, $activeWorkspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($activeWorkspaceId, $userId, [
    'mode' => $conversationExperienceMode,
    'surface' => 'conversation',
    'current_page' => 'conversation.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'communication' => $communication,
]);
ob_start();
?>

<link rel="stylesheet" href="assets/css/work-surface-guidance.css">

<!-- Compact chat header -->
<div class="chat-header">
    <a href="inbox.php?owner_scope=<?php echo urlencode($ownerScope); ?>" class="chat-header-back" title="Back to Inbox">&larr;</a>
    <a href="contact_view.php?id=<?php echo $communication['contact_id']; ?>" class="chat-header-name">
        <?php echo htmlspecialchars(trim(($communication['first_name'] ?? '') . ' ' . ($communication['last_name'] ?? '')) ?: 'Contact'); ?>
    </a>
    <span class="chat-header-channel"><?php echo htmlspecialchars($communication['channel']); ?></span>
</div>

<?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>

<?php if ($error): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php
$threadStatusLabel = str_replace('_', ' ', (string) ($conversationThreadState['status'] ?? 'open'));
$threadPriorityLabel = strtoupper((string) ($conversationThreadState['priority'] ?? 'medium'));
$threadSlaLabel = (string) ($conversationThreadState['sla']['label'] ?? '');
$threadSummaryText = (string) ($conversationThreadState['thread_summary'] ?? '');
$threadNextAction = (string) ($conversationThreadState['next_best_action'] ?? '');
$threadSentiment = (string) ($conversationThreadState['sentiment_trend'] ?? 'unknown');
$threadConcerns = array_values((array) ($conversationThreadState['recurring_concerns'] ?? []));
$threadOwnerId = (int) ($conversationThreadState['current_owner_id'] ?? 0);
$threadEscalation = (string) ($conversationThreadState['escalation_status'] ?? '');
$threadFocusMessageId = 0;
$threadMessageCount = count($threadCommunications);
for ($threadIndex = $threadMessageCount - 1; $threadIndex >= 0; $threadIndex--) {
    $candidateMessage = $threadCommunications[$threadIndex] ?? [];
    if (($candidateMessage['direction'] ?? '') === 'inbound' && empty($candidateMessage['read_at'])) {
        $threadFocusMessageId = (int) ($candidateMessage['id'] ?? 0);
        break;
    }
}
if ($threadFocusMessageId <= 0) {
    $threadFocusMessageId = (int) $communicationId;
}
if ($threadFocusMessageId <= 0 && $threadMessageCount > 0) {
    $lastThreadMessage = $threadCommunications[$threadMessageCount - 1] ?? [];
    $threadFocusMessageId = (int) ($lastThreadMessage['id'] ?? 0);
}
$communicationMetaForDemo = is_string($communication['metadata'] ?? '')
    ? (json_decode((string) ($communication['metadata'] ?? ''), true) ?: [])
    : (is_array($communication['metadata'] ?? null) ? (array) $communication['metadata'] : []);
$isProtectedDemoRiversideConversation = (string) ($communication['demo_visibility'] ?? '') === 'session_private'
    && (int) ($communication['demo_session_id'] ?? 0) > 0
    && (
        in_array((string) ($communicationMetaForDemo['demo_event_key'] ?? ''), ['whatsapp_lead_received', 'email_inquiry_received', 'procurement_followup_received', 'assistant_draft_typing_started'], true)
        || stripos((string) ($communication['subject'] ?? ''), 'Riverside') !== false
        || stripos((string) (($communication['first_name'] ?? '') . ' ' . ($communication['last_name'] ?? '')), 'Amina') !== false
    );
$protectedDemoDraftPreview = '';
if ($isProtectedDemoRiversideConversation) {
    $draftRow = Database::queryOne(
        "SELECT body
         FROM communications
         WHERE workspace_id = ?
           AND demo_session_id = ?
           AND demo_visibility = 'session_private'
           AND metadata LIKE '%\"demo_event_key\":\"assistant_draft_typing_started\"%'
         ORDER BY id DESC
         LIMIT 1",
        [$activeWorkspaceId, (int) ($communication['demo_session_id'] ?? 0)]
    ) ?: [];
    $protectedDemoDraftPreview = trim((string) ($draftRow['body'] ?? ''));
}
$showProtectedDemoConversationProof = $isProtectedDemoRiversideConversation && $protectedDemoDraftPreview !== '';
$threadOwnerLabel = $showProtectedDemoConversationProof || $isProtectedDemoRiversideConversation
    ? 'You (demo owner)'
    : ($threadOwnerId > 0 ? 'User #' . $threadOwnerId : 'Unassigned');
$latestThreadMessage = $threadMessageCount > 0 ? ($threadCommunications[$threadMessageCount - 1] ?? []) : [];
$latestThreadAt = (string) ($latestThreadMessage['_effective_at'] ?? $latestThreadMessage['created_at'] ?? '');
?>
<div class="conversation-layout">
    <div class="conversation-sticky-bar">
        <div class="conversation-sticky-main">
            <div class="conversation-chip-row">
                <span class="conversation-chip conversation-chip-status"><?php echo htmlspecialchars($threadStatusLabel); ?></span>
                <span class="conversation-chip conversation-chip-priority"><?php echo htmlspecialchars($threadPriorityLabel); ?></span>
                <?php if (!empty($conversationThreadState['is_overdue'])): ?>
                    <span class="conversation-chip conversation-chip-overdue">Overdue</span>
                <?php endif; ?>
                <?php if ($threadEscalation !== '' && $threadEscalation !== 'none'): ?>
                    <span class="conversation-chip conversation-chip-escalation"><?php echo htmlspecialchars(str_replace('_', ' ', $threadEscalation)); ?></span>
                <?php endif; ?>
            </div>
            <div class="conversation-sticky-meta">
                <span><?php echo (int) $threadMessageCount; ?> messages</span>
                <span><?php echo strtoupper(htmlspecialchars((string) ($communication['channel'] ?? 'conversation'))); ?></span>
                <span>Owner: <?php echo htmlspecialchars($threadOwnerLabel); ?></span>
                <span><?php echo htmlspecialchars($threadSlaLabel !== '' ? $threadSlaLabel : 'No reply target'); ?></span>
                <?php if ($latestThreadAt !== ''): ?>
                    <span>Latest: <?php echo htmlspecialchars(formatConversationDateTime($latestThreadAt, $conversationTimezone)); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($threadNextAction !== ''): ?>
                <div class="conversation-sticky-next"><strong>Next:</strong> <?php echo htmlspecialchars($threadNextAction); ?></div>
            <?php elseif ($threadSummaryText !== ''): ?>
                <div class="conversation-sticky-next"><?php echo htmlspecialchars($threadSummaryText); ?></div>
            <?php endif; ?>
        </div>
        <form method="POST" action="" class="conversation-thread-action">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="text" name="thread_reason" value="" placeholder="Optional reason">
            <?php if (($conversationThreadState['status'] ?? '') === 'resolved'): ?>
                <button type="submit" name="thread_action" value="reopen_thread">Reopen</button>
            <?php else: ?>
                <button type="submit" name="thread_action" value="resolve_thread">Resolve</button>
            <?php endif; ?>
        </form>
    </div>

<!-- Chat thread: client left, business right -->
<div class="chat-thread">
    <div class="chat-thread-inner">
        <?php
        foreach ($threadCommunications as $msg):
            $msgMeta = is_string($msg['metadata'] ?? '') ? json_decode($msg['metadata'], true) : ($msg['metadata'] ?? []);
            $msgMeta = is_array($msgMeta) ? $msgMeta : [];
            $msgType = $msgMeta['message_type'] ?? 'text';
            $hasMedia = !empty($msgMeta['media_id']) && in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true);
            $mediaProxyUrl = null;
            if ($hasMedia && ($msg['channel'] ?? '') === 'whatsapp') {
                $mediaProxyUrl = '../api/whatsapp/media_proxy.php?communication_id=' . (int) $msg['id'];
            }
            $isOutbound = ($msg['direction'] ?? '') === 'outbound';
            $status = $msg['status'] ?? '';
            $readAt = $msg['read_at'] ?? null;
            $rowClass = $isOutbound ? 'chat-row chat-row-outbound' : 'chat-row chat-row-inbound';
            $bubbleClass = $isOutbound ? 'chat-bubble chat-bubble-outbound' : 'chat-bubble chat-bubble-inbound';
            $isSelectedMessage = (int) ($msg['id'] ?? 0) === (int) $communicationId;
            $isFocusMessage = (int) ($msg['id'] ?? 0) === (int) $threadFocusMessageId;
        ?>
            <div
                id="msg-<?php echo (int)$msg['id']; ?>"
                class="<?php echo $rowClass; ?>"
                <?php if ($isFocusMessage): ?>data-focus-message="1"<?php endif; ?>
                <?php if ($isSelectedMessage): ?>data-selected-message="1"<?php endif; ?>
            >
                <div class="<?php echo $bubbleClass; ?>">
                    <?php if ($isSelectedMessage): ?>
                        <div class="chat-bubble-context-tag">Opened from inbox</div>
                    <?php elseif ($isFocusMessage): ?>
                        <div class="chat-bubble-context-tag chat-bubble-context-tag-focus">Latest activity</div>
                    <?php endif; ?>
                    <div class="chat-bubble-label"><?php echo $isOutbound ? 'You' : 'Client'; ?></div>
                    <?php if ($msg['subject'] ?? ''): ?>
                        <div class="chat-bubble-subject"><?php echo htmlspecialchars($msg['subject']); ?></div>
                    <?php endif; ?>
                    <?php if ($hasMedia && $mediaProxyUrl): ?>
                        <?php if ($msgType === 'image' || $msgType === 'sticker'): ?>
                            <div class="chat-bubble-media"><img src="<?php echo htmlspecialchars($mediaProxyUrl); ?>" alt="Media"></div>
                        <?php elseif ($msgType === 'video'): ?>
                            <div class="chat-bubble-media"><video src="<?php echo htmlspecialchars($mediaProxyUrl); ?>" controls></video></div>
                        <?php elseif ($msgType === 'audio'): ?>
                            <div class="chat-bubble-media"><audio src="<?php echo htmlspecialchars($mediaProxyUrl); ?>" controls></audio></div>
                        <?php elseif ($msgType === 'document'): ?>
                            <div class="chat-bubble-media"><a href="<?php echo htmlspecialchars($mediaProxyUrl); ?>" target="_blank" rel="noopener" class="chat-bubble-doc">Download document</a></div>
                        <?php endif; ?>
                    <?php elseif ($hasMedia): ?>
                        <div class="chat-bubble-media-placeholder">[<?php echo htmlspecialchars(ucfirst($msgType)); ?>]</div>
                    <?php endif; ?>
                    <?php
                    $cleanBody = cleanConversationBody((string) ($msg['body'] ?? ''), (string) ($msg['channel'] ?? ''));
                    ?>
                    <?php if (trim($cleanBody) !== ''): ?>
                        <div class="chat-bubble-body"><?php echo linkifyUrls($cleanBody); ?></div>
                        <?php if (!$isOutbound && strlen($cleanBody) > 10):
                            $sentiment = $msgMeta['sentiment'] ?? null;
                            $intent = $msgMeta['intent'] ?? null;
                            $sentiment = is_array($sentiment) ? $sentiment : ['sentiment' => 'neutral'];
                            $intent = is_array($intent) ? $intent : ['intent' => 'unknown'];
                            $hasCachedSentiment = isset($msgMeta['sentiment']) && is_array($msgMeta['sentiment']) && !empty($sentiment['sentiment']);
                            $hasCachedIntent = isset($msgMeta['intent']) && is_array($msgMeta['intent']) && !empty($intent['intent']);
                            if (!$hasCachedSentiment || !$hasCachedIntent) {
                                error_log('conversation render skipped inline sentiment/intent enrichment for communication ' . (int) ($msg['id'] ?? 0));
                            }
                            if ($hasCachedSentiment || $hasCachedIntent):
                                $sentColors = ['positive' => '#16a34a', 'negative' => '#dc2626', 'neutral' => '#6b7280'];
                                $sentColor = $sentColors[$sentiment['sentiment'] ?? 'neutral'] ?? '#6b7280';
                        ?>
                        <div class="chat-bubble-badges" style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;">
                            <?php if ($hasCachedSentiment): ?>
                                <span style="font-size:11px;padding:2px 6px;border-radius:4px;background:<?php echo $sentColor; ?>20;color:<?php echo $sentColor; ?>;"><?php echo ucfirst($sentiment['sentiment'] ?? 'neutral'); ?></span>
                            <?php endif; ?>
                            <?php if ($hasCachedIntent): ?>
                                <span style="font-size:11px;padding:2px 6px;border-radius:4px;background:#e5e7eb;color:#374151;"><?php echo ucfirst($intent['intent'] ?? 'unknown'); ?></span>
                            <?php endif; ?>
                        </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="chat-bubble-footer">
                        <span class="chat-bubble-time"><?php echo htmlspecialchars(formatConversationDateTime((string) ($msg['_effective_at'] ?? $msg['created_at'] ?? ''), $conversationTimezone)); ?></span>
                        <?php if ($isOutbound && ($msg['channel'] ?? '') === 'whatsapp'): ?>
                            <span class="chat-bubble-status">
                                <?php
                                $deliveredAt = $msgMeta['whatsapp_delivered_at'] ?? null;
                                $sentAt = $msgMeta['whatsapp_sent_at'] ?? null;
                                if ($readAt) {
                                    echo 'Opened ' . htmlspecialchars(formatConversationDateTime((string) $readAt, $conversationTimezone));
                                } elseif ($status === 'delivered') {
                                    echo $deliveredAt ? 'Delivered ' . htmlspecialchars(formatConversationDateTime((string) $deliveredAt, $conversationTimezone)) : 'Delivered';
                                } elseif ($status === 'sent') {
                                    echo $sentAt ? 'Sent ' . htmlspecialchars(formatConversationDateTime((string) $sentAt, $conversationTimezone)) : 'Sent';
                                } elseif ($status === 'failed') {
                                    echo 'Failed';
                                } else {
                                    echo ucfirst($status ?: 'Pending');
                                }
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<section class="protected-demo-triage-strip <?php echo $showProtectedDemoConversationProof ? 'is-visible' : ''; ?>" data-protected-demo-triage <?php echo $showProtectedDemoConversationProof ? '' : 'hidden'; ?> aria-live="polite">
    <div class="protected-demo-triage-strip__header">
        <span>Clarity triage</span>
        <strong>Riverside is high intent</strong>
    </div>
    <div class="protected-demo-triage-strip__chips">
        <span>High intent</span>
        <span>Proposal revision</span>
        <span>Friday hold</span>
        <span>Payment terms</span>
    </div>
</section>

<?php if ($showProtectedDemoConversationProof): ?>
    <section class="protected-demo-draft-card" data-protected-demo-draft="1" data-demo-draft-preview="<?php echo htmlspecialchars($protectedDemoDraftPreview); ?>" data-demo-draft-complete="1" data-demo-cue-key="draft_panel_visible">
        <div class="protected-demo-draft-card__kicker">Clarity draft</div>
        <div class="protected-demo-draft-card__body" aria-live="polite"><?php echo htmlspecialchars($protectedDemoDraftPreview); ?></div>
        <a class="protected-demo-draft-card__action" href="tasks.php">Open Tasks</a>
    </section>
<?php endif; ?>

<style>
/* Conversation layout */
.conversation-layout { max-width: 820px; }
/* Chat header */
.chat-header { display: flex; align-items: center; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm); padding: var(--spacing-sm) 0; border-bottom: 1px solid var(--border-color); }
.chat-header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; background: var(--light-grey); color: var(--midnight-black); text-decoration: none; font-size: 1.25rem; font-weight: 500; transition: background var(--transition-fast); }
.chat-header-back:hover { background: #e5e7eb; }
.chat-header-name { font-weight: 600; font-size: 1.1rem; color: var(--midnight-black); text-decoration: none; }
.chat-header-name:hover { color: var(--accent-blue); }
.chat-header-channel { font-size: 0.75rem; color: var(--charcoal-grey); background: var(--light-grey); padding: 4px 10px; border-radius: 12px; text-transform: capitalize; margin-left: auto; }
.conversation-sticky-bar { position: sticky; top: 12px; z-index: 10; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px; align-items: start; padding: 14px 16px; margin-bottom: 14px; background: rgba(248, 250, 252, 0.96); border: 1px solid #dbe5f0; border-radius: 16px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06); backdrop-filter: blur(10px); }
.conversation-sticky-main { min-width: 0; display: flex; flex-direction: column; gap: 8px; }
.conversation-chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
.conversation-chip { display: inline-flex; align-items: center; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; }
.conversation-chip-status { background: #dbeafe; color: #1d4ed8; }
.conversation-chip-priority { background: #ede9fe; color: #6d28d9; }
.conversation-chip-overdue { background: #fee2e2; color: #b91c1c; }
.conversation-chip-escalation { background: #fff7ed; color: #9a3412; }
.conversation-sticky-meta { display: flex; flex-wrap: wrap; gap: 8px 14px; color: #64748b; font-size: 12px; }
.conversation-sticky-meta span { position: relative; }
.conversation-sticky-next { font-size: 14px; color: #0f172a; line-height: 1.5; }
.conversation-thread-action { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-end; }
.conversation-thread-action input[type="text"] { width: min(220px, 100%); padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 10px; background: white; }
.conversation-thread-action button { padding: 9px 14px; border: none; border-radius: 10px; background: #0f766e; color: white; cursor: pointer; font-weight: 600; }
/* Chat thread */
.chat-thread { background: linear-gradient(180deg, #f8fbff 0%, #f0f2f5 100%); border: 1px solid #dde7f1; border-radius: 18px; padding: 14px; margin-bottom: 12px; max-width: 720px; }
.chat-thread-inner { display: flex; flex-direction: column; gap: 10px; }
.chat-row { display: flex; margin-bottom: 2px; scroll-margin-top: 108px; }
.chat-row-inbound { justify-content: flex-start; }
.chat-row-outbound { justify-content: flex-end; }
.chat-bubble { max-width: 78%; padding: 12px 14px; border-radius: 18px; box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06); line-height: 1.5; font-size: 0.9375rem; }
.chat-bubble-inbound { background: #ffffff; color: var(--midnight-black); border-bottom-left-radius: 6px; }
.chat-bubble-outbound { background: var(--accent-blue); color: white; border-bottom-right-radius: 6px; }
.chat-bubble-context-tag { display: inline-flex; align-items: center; margin-bottom: 6px; padding: 2px 8px; border-radius: 999px; background: #dbeafe; color: #1d4ed8; font-size: 10px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
.chat-bubble-context-tag-focus { background: #e2e8f0; color: #334155; }
.chat-bubble-label { font-size: 0.68rem; color: var(--charcoal-grey); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.06em; }
.chat-bubble-outbound .chat-bubble-label { color: rgba(255,255,255,0.85); }
.chat-bubble-subject { font-weight: 600; margin-bottom: 6px; }
.chat-bubble-body { white-space: pre-wrap; word-break: break-word; }
.chat-bubble-media { margin-bottom: 6px; }
.chat-bubble-media img, .chat-bubble-media video { max-width: 100%; max-height: 260px; border-radius: 12px; display: block; }
.chat-bubble-media audio { max-width: 100%; }
.chat-bubble-media-placeholder { font-size: 0.8125rem; color: var(--charcoal-grey); margin-bottom: 4px; }
.chat-bubble-outbound .chat-bubble-media-placeholder { color: rgba(255,255,255,0.9); }
.chat-bubble-doc { color: inherit; text-decoration: underline; }
.chat-bubble-outbound .chat-bubble-doc { color: rgba(255,255,255,0.95); }
.chat-bubble-footer { display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 8px; font-size: 0.72rem; color: inherit; opacity: 0.72; }
.chat-bubble-status { font-style: italic; }
.conversation-message-highlight .chat-bubble { box-shadow: 0 0 0 2px var(--accent-blue), 0 10px 24px rgba(37, 99, 235, 0.12); }
.chat-row.conversation-message-highlight .chat-bubble-outbound { box-shadow: 0 0 0 2px white, 0 10px 24px rgba(15, 23, 42, 0.22); }
.protected-demo-triage-strip { max-width: 720px; margin: 0 0 12px; padding: 14px 16px; border-radius: 16px; border: 1px solid rgba(37, 99, 235, 0.22); background: linear-gradient(135deg, rgba(239, 246, 255, 0.96), rgba(236, 253, 245, 0.9)); box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08); opacity: 0; transform: translateY(8px); transition: opacity 260ms ease, transform 260ms ease; }
.protected-demo-triage-strip.is-visible { opacity: 1; transform: translateY(0); }
.protected-demo-triage-strip__header { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: #0f172a; margin-bottom: 10px; }
.protected-demo-triage-strip__header span { color: #2563eb; font-size: 11px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.protected-demo-triage-strip__chips { display: flex; flex-wrap: wrap; gap: 8px; }
.protected-demo-triage-strip__chips span { display: inline-flex; padding: 5px 10px; border-radius: 999px; background: white; color: #0f766e; border: 1px solid rgba(15, 118, 110, 0.18); font-size: 12px; font-weight: 750; }
/* Reply bar */
.chat-reply { background: white; border: 1px solid var(--border-color); border-radius: 24px; padding: var(--spacing-sm) var(--spacing-md); margin-bottom: var(--spacing-lg); max-width: 720px; box-shadow: var(--shadow-sm); }
.chat-reply.chat-reply-email { max-width: 100%; padding: var(--spacing-md) var(--spacing-lg); border-radius: 28px; }
.chat-reply form { display: flex; flex-direction: column; gap: var(--spacing-sm); }
.chat-reply form .chat-reply-row { display: flex; align-items: flex-end; gap: var(--spacing-sm); }
.chat-reply-preview { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; background: var(--light-grey); border-radius: 12px; min-height: 0; }
.chat-reply-preview-item { position: relative; flex-shrink: 0; }
.chat-reply-preview-item img { max-width: 120px; max-height: 100px; border-radius: 8px; display: block; object-fit: cover; }
.chat-reply-preview-tile { padding: 8px 12px; background: white; border: 1px solid var(--border-color); border-radius: 8px; font-size: 12px; color: var(--charcoal-grey); max-width: 180px; }
.chat-reply-preview-tile strong { display: block; color: var(--midnight-black); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-reply-preview-remove { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: rgba(0,0,0,0.6); color: white; border: none; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center; padding: 0; }
.chat-reply-preview-remove:hover { background: #c33; }
.chat-reply-attach { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%; background: var(--light-grey); color: var(--charcoal-grey); cursor: pointer; flex-shrink: 0; }
.chat-reply-attach:hover { background: #e5e7eb; }
.chat-reply-attach input { position: absolute; width: 0; height: 0; opacity: 0; }
.chat-reply-attach-icon { font-size: 1.25rem; line-height: 1; display: block; }
.chat-reply textarea { flex: 1; min-height: 44px; max-height: 120px; padding: 10px 16px; border: none; border-radius: 20px; background: var(--light-grey); font-family: inherit; font-size: 0.9375rem; line-height: 1.55; resize: none; }
.chat-reply.chat-reply-email textarea { min-height: 180px; max-height: 70vh; padding: 16px 18px; font-size: 1rem; resize: vertical; }
.chat-reply textarea:focus { outline: none; background: #ebebeb; }
.chat-reply button[type="submit"] { flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%; background: var(--accent-blue); color: white; border: none; font-weight: 600; cursor: pointer; transition: opacity var(--transition-fast); }
.chat-reply button[type="submit"]:hover { opacity: 0.9; }
.chat-reply button[type="submit"]:disabled { opacity: 0.5; cursor: not-allowed; }
.chat-reply-send-status { display: none; margin-top: 8px; font-size: 12px; color: #475569; }
.chat-reply-send-status.is-active { display: block; }
.chat-reply-send-status.is-success { color: #15803d; }
.chat-reply-send-status.is-error { color: #b91c1c; }
.chat-reply-window-closed { background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: var(--spacing-sm) var(--spacing-md); border-radius: 8px; margin-bottom: var(--spacing-sm); max-width: 720px; font-size: 0.875rem; }
#thread-summary-section { display: flex; flex-direction: column; gap: 10px; }
#thread-summary-section button { align-self: flex-start; padding: 8px 12px; background: var(--light-grey); border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; cursor: pointer; }
#thread-summary-result { padding: 12px 14px; background: #f0f7ff; border-left: 4px solid var(--accent-blue); border-radius: 10px; }
#thread-summary-text { margin: 0; line-height: 1.6; color: var(--midnight-black); white-space: pre-line; }
.conversation-secondary { max-width: 720px; margin-bottom: 20px; }
.conversation-secondary-card { border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: var(--shadow-sm); overflow: hidden; }
.conversation-secondary-card summary { list-style: none; cursor: pointer; padding: 14px 16px; font-weight: 600; color: #0f172a; }
.conversation-secondary-card summary::-webkit-details-marker { display: none; }
.conversation-secondary-body { padding: 0 16px 16px; display: flex; flex-direction: column; gap: 14px; }
.conversation-secondary-meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.conversation-secondary-meta > div { padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
.conversation-secondary-label { display: block; margin-bottom: 4px; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
.conversation-secondary-summary { font-size: 14px; color: #0f172a; line-height: 1.6; }
.conversation-secondary-concerns { display: flex; flex-wrap: wrap; gap: 8px; }
.conversation-secondary-concerns span { font-size: 11px; padding: 4px 8px; border-radius: 999px; background: #e2e8f0; color: #334155; }
@media (max-width: 768px) {
    .conversation-sticky-bar { position: static; grid-template-columns: 1fr; }
    .conversation-thread-action { justify-content: stretch; }
    .conversation-thread-action input[type="text"] { width: 100%; }
    .chat-thread,
    .chat-reply,
    .conversation-secondary,
    .chat-reply-window-closed,
    #quick-replies-container { max-width: 100%; }
    .chat-bubble { max-width: 92%; }
    .conversation-secondary-meta { grid-template-columns: 1fr; }
    .chat-header { flex-wrap: wrap; }
    .chat-header-channel { margin-left: 0; }
}
</style>
<script>
(function() {
    var preferred = document.querySelector('[data-focus-message="1"]');
    var selected = document.querySelector('[data-selected-message="1"]');
    var fallback = preferred || selected;
    var params = new URLSearchParams(window.location.search);
    var id = params.get('id');
    var el = fallback;
    if (!el && id && /^\d+$/.test(id)) {
        el = document.getElementById('msg-' + id);
    }
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('conversation-message-highlight');
    setTimeout(function() { el.classList.remove('conversation-message-highlight'); }, 2200);
})();
</script>
<script>
(function() {
    var serverCommunicationId = <?php echo (int) $communicationId; ?>;
    var serverContactId = <?php echo (int) (($communication['contact_id'] ?? 0) ?: ($fallbackContactId ?? 0)); ?>;
    var serverChannel = <?php echo json_encode((string) (($communication['channel'] ?? '') ?: ($fallbackChannel ?? ''))); ?>;
    function getApiCandidates(relativePath) {
        var clean = String(relativePath || '').replace(/^\/+/, '');
        var candidates = [];
        function add(url) {
            if (url && candidates.indexOf(url) === -1) candidates.push(url);
        }
        add('../api/' + clean);
        add('api/' + clean);
        add('/api/' + clean);
        var p = window.location.pathname || '';
        if (p.indexOf('/public/') >= 0) {
            var base = p.split('/public/')[0];
            add(base + '/api/' + clean);
        }
        if (p.indexOf('/crm/') === 0) {
            add('<?php echo rtrim(apiUrl(''), '/'); ?>/' + clean);
        }
        return candidates;
    }

    async function fetchApiJson(relativePath, fetchOptions) {
        var candidates = getApiCandidates(relativePath);
        var lastError = null;
        for (var i = 0; i < candidates.length; i++) {
            try {
                var opts = Object.assign({ credentials: 'same-origin' }, fetchOptions || {});
                var r = await fetch(candidates[i], opts);
                var text = await r.text();
                var data = null;
                try { data = JSON.parse(text); } catch (e) {}
                if (!r.ok) {
                    var errMsg = (data && data.error) ? data.error : ('HTTP ' + r.status);
                    throw new Error(errMsg);
                }
                if (data === null) {
                    throw new Error('Invalid JSON response');
                }
                return data;
            } catch (e) {
                lastError = e;
            }
        }
        throw lastError || new Error('API request failed');
    }

    function renderAssistantStatus(data, fallbackText) {
        var previewEl = document.getElementById('assistant-commercial-preview');
        if (!previewEl) return;
        var policy = (data && data.policy) ? data.policy : {};
        var draft = (data && data.draft) ? data.draft : {};
        var promptWarnings = [];
        if (draft && draft.context_bundle_quality && Array.isArray(draft.context_bundle_quality.warnings)) {
            promptWarnings = draft.context_bundle_quality.warnings;
        }
        if (window.AIUiConsistency) {
            previewEl.innerHTML = window.AIUiConsistency.renderStatusPanel({
                policy: policy,
                summary: (data && data.summary_text) || fallbackText || '',
                explanation: draft.explanation || '',
                fallback: !!(data && data.fallback),
                qualityScore: draft.context_bundle_quality && typeof draft.context_bundle_quality.context_quality_score === 'number'
                    ? draft.context_bundle_quality.context_quality_score
                    : null,
                promptWarnings: promptWarnings,
                mode: data && data.mode ? data.mode : ''
            });
        } else {
            previewEl.textContent = String((data && data.summary_text) || fallbackText || 'AI status available.');
        }
        previewEl.style.display = 'block';
    }
    window.__crmRenderAssistantStatus = renderAssistantStatus;

    function buildThreadTextFromDom() {
        var rows = document.querySelectorAll('[id^="msg-"]');
        if (!rows || rows.length === 0) return '';
        var parts = [];
        rows.forEach(function(row) {
            var labelEl = row.querySelector('.chat-bubble-label');
            var subjectEl = row.querySelector('.chat-bubble-subject');
            var bodyEl = row.querySelector('.chat-bubble-body');
            var timeEl = row.querySelector('.chat-bubble-time');
            var label = labelEl ? labelEl.textContent.trim() : '';
            var subject = subjectEl ? subjectEl.textContent.trim() : '';
            var body = bodyEl ? bodyEl.textContent.trim() : '';
            var time = timeEl ? timeEl.textContent.trim() : '';
            if (!subject && !body) return;
            parts.push(
                '[' + (time || 'Unknown time') + '] '
                + (label || 'Message')
                + (subject ? ' | ' + subject : '')
                + ': ' + body
            );
        });
        return parts.join("\n");
    }

    window.__crmSummarizeThread = async function(btn, explicitCommunicationId) {
        var button = btn || document.getElementById('summarize-thread-btn');
        var resultDiv = document.getElementById('thread-summary-result');
        var summaryText = document.getElementById('thread-summary-text');
        var statusDiv = document.getElementById('thread-summary-status');
        if (!button || !resultDiv || !summaryText || !statusDiv) return;

        var urlParams = new URLSearchParams(window.location.search || '');
        var communicationId = parseInt(String(explicitCommunicationId || serverCommunicationId || button.getAttribute('data-communication-id') || '0'), 10);
        if (!Number.isFinite(communicationId) || communicationId <= 0) {
            communicationId = parseInt(String(urlParams.get('id') || '0'), 10);
        }
        var contactId = parseInt(String(button.getAttribute('data-contact-id') || serverContactId || urlParams.get('contact_id') || '0'), 10);
        var channel = String(button.getAttribute('data-channel') || serverChannel || urlParams.get('channel') || '');
        if (!Number.isFinite(communicationId) || communicationId < 0) communicationId = 0;
        if (!Number.isFinite(contactId) || contactId < 0) contactId = 0;

        button.disabled = true;
        button.textContent = 'Generating...';
        statusDiv.textContent = 'Generating a workspace-scoped summary...';
        resultDiv.style.display = 'block';
        try {
            var threadText = buildThreadTextFromDom();
            var data = await fetchApiJson('inbox/summarize.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    communication_id: communicationId,
                    contact_id: contactId,
                    channel: channel,
                    thread_text: threadText
                })
            });
            if (data.success && data.summary) {
                summaryText.textContent = data.summary;
                statusDiv.innerHTML = window.AIUiConsistency
                    ? window.AIUiConsistency.renderExecutionStatus(data.ai_status)
                    : '';
            } else {
                summaryText.textContent = '';
                statusDiv.textContent = data.error || 'Failed to generate summary. Please try again.';
            }
        } catch (e) {
            summaryText.textContent = '';
            statusDiv.textContent = 'The summary could not be generated. Please try again.';
        }
        button.disabled = false;
        button.textContent = 'Summarize thread';
    };
})();
</script>
<script>
(function() {
    var serverCommunicationId = <?php echo (int) $communicationId; ?>;
    var serverContactId = <?php echo (int) (($communication['contact_id'] ?? 0) ?: ($fallbackContactId ?? 0)); ?>;
    var serverChannel = <?php echo json_encode((string) (($communication['channel'] ?? '') ?: ($fallbackChannel ?? ''))); ?>;
    function getApiCandidates(relativePath) {
        var clean = String(relativePath || '').replace(/^\/+/, '');
        var candidates = [];
        function add(url) {
            if (url && candidates.indexOf(url) === -1) candidates.push(url);
        }
        add('../api/' + clean);
        add('api/' + clean);
        add('/api/' + clean);
        var p = window.location.pathname || '';
        if (p.indexOf('/public/') >= 0) {
            var base = p.split('/public/')[0];
            add(base + '/api/' + clean);
        }
        if (p.indexOf('/crm/') === 0) {
            add('<?php echo rtrim(apiUrl(''), '/'); ?>/' + clean);
        }
        return candidates;
    }

    async function fetchApiJson(relativePath, fetchOptions) {
        var candidates = getApiCandidates(relativePath);
        var lastError = null;
        for (var i = 0; i < candidates.length; i++) {
            try {
                var opts = Object.assign({ credentials: 'same-origin' }, fetchOptions || {});
                var r = await fetch(candidates[i], opts);
                var text = await r.text();
                var data = null;
                try { data = JSON.parse(text); } catch (e) {}
                if (!r.ok) {
                    var errMsg = (data && data.error) ? data.error : ('HTTP ' + r.status);
                    throw new Error(errMsg);
                }
                if (data === null) {
                    throw new Error('Invalid JSON response');
                }
                return data;
            } catch (e) {
                lastError = e;
            }
        }
        throw lastError || new Error('API request failed');
    }

    function extractAssistantBody(data) {
        var draft = (data && data.draft) ? data.draft : {};
        var plain = String((draft && draft.plain_body) || (data && data.plain_body) || (data && data.body) || '').trim();
        if (plain !== '') {
            return plain;
        }
        var html = String((draft && draft.html_body) || (data && data.html_body) || '').trim();
        if (html !== '') {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            return String(tmp.textContent || tmp.innerText || '').trim();
        }
        return String((draft && draft.message) || '').trim();
    }

    function hasSuccessfulAssistantResult(data, expectedAction) {
        if (!data || data.success !== true) {
            return false;
        }
        var results = Array.isArray(data.results) ? data.results : [];
        var successStatuses = ['sent', 'created', 'revised', 'converted', 'finalized', 'paid', 'approved', 'queued', 'executed'];
        for (var i = 0; i < results.length; i++) {
            var result = results[i] || {};
            var action = String(result.action || '');
            var status = String(result.status || '').toLowerCase();
            if (expectedAction && action !== expectedAction) {
                continue;
            }
            if (successStatuses.indexOf(status) !== -1) {
                return true;
            }
        }
        return String(data.execution_status || '').toLowerCase() === 'executed' && !!data.can_execute;
    }

    window.__crmSuggestReply = async function(btn, explicitCommunicationId) {
        var button = btn || document.getElementById('suggest-reply-btn');
        var bodyEl = document.getElementById('body');
        var previewEl = document.getElementById('assistant-commercial-preview');
        if (!button || !bodyEl) return;
        if (bodyEl.disabled || button.disabled) return;

        var urlParams = new URLSearchParams(window.location.search || '');
        var communicationId = parseInt(String(explicitCommunicationId || serverCommunicationId || button.getAttribute('data-communication-id') || '0'), 10);
        if (!Number.isFinite(communicationId) || communicationId <= 0) {
            communicationId = parseInt(String(urlParams.get('id') || '0'), 10);
        }
        var contactId = parseInt(String(button.getAttribute('data-contact-id') || serverContactId || urlParams.get('contact_id') || '0'), 10);
        var channel = String(button.getAttribute('data-channel') || serverChannel || urlParams.get('channel') || '');
        if (!Number.isFinite(communicationId) || communicationId < 0) communicationId = 0;
        if (!Number.isFinite(contactId) || contactId < 0) contactId = 0;

        button.disabled = true;
        button.textContent = 'Generating...';
        try {
            var data = await fetchApiJson('inbox/suggest_reply.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    communication_id: communicationId,
                    contact_id: contactId,
                    channel: channel
                })
            });
            var suggested = extractAssistantBody(data);
            if (data.success && suggested) {
                bodyEl.value = suggested;
                if (window.__crmRenderAssistantStatus) {
                    window.__crmRenderAssistantStatus(data, 'Reply draft ready.');
                }
                bodyEl.focus();
            } else {
                if (previewEl) {
                    if (window.__crmRenderAssistantStatus) {
                        window.__crmRenderAssistantStatus(data || {}, data.error || 'Failed to generate reply.');
                    }
                }
                alert('Error: ' + (data.error || 'Failed to generate reply'));
            }
        } catch (e) {
            if (previewEl) {
                if (window.__crmRenderAssistantStatus) {
                    window.__crmRenderAssistantStatus({}, e.message || 'Failed to generate reply.');
                }
            }
            alert('Error: ' + e.message);
        }
        button.disabled = false;
        button.textContent = 'Suggest reply';
    };

    window.__crmPreviewCommercialReply = async function(btn, explicitCommunicationId, action) {
        var button = btn;
        var bodyEl = document.getElementById('body');
        var previewEl = document.getElementById('assistant-commercial-preview');
        if (!button || !bodyEl) return;
        var communicationId = parseInt(String(explicitCommunicationId || serverCommunicationId || button.getAttribute('data-communication-id') || '0'), 10);
        button.disabled = true;
        var original = button.textContent;
        button.textContent = 'Working...';
        try {
            var data = await fetchApiJson(
                'email_assistant_preview_reply.php?communication_id='
                + encodeURIComponent(communicationId)
                + '&action=' + encodeURIComponent(action || 'draft_reply')
            );
            var body = extractAssistantBody(data);
            if (data.success && body) {
                bodyEl.value = body;
                if (window.__crmRenderAssistantStatus) {
                    window.__crmRenderAssistantStatus(
                        data,
                        action === 'revise_reply' ? 'Latest quote revised and reply draft prepared.' : 'Assistant draft ready.'
                    );
                }
                bodyEl.focus();
                bodyEl.dispatchEvent(new Event('input'));
            } else {
                if (window.__crmRenderAssistantStatus) {
                    window.__crmRenderAssistantStatus(data || {}, data.error || 'Failed to build assistant draft');
                }
                alert('Error: ' + (data.error || 'Failed to build assistant draft'));
            }
        } catch (e) {
            if (window.__crmRenderAssistantStatus) {
                window.__crmRenderAssistantStatus({}, e.message || 'Failed to build assistant draft');
            }
            alert('Error: ' + e.message);
        }
        button.disabled = false;
        button.textContent = original;
    };

    window.__crmSendCommercialReply = async function(btn, explicitCommunicationId, action) {
        var button = btn;
        var bodyEl = document.getElementById('body');
        var communicationId = parseInt(String(explicitCommunicationId || serverCommunicationId || button.getAttribute('data-communication-id') || '0'), 10);
        button.disabled = true;
        var original = button.textContent;
        button.textContent = 'Sending...';
        try {
            var data = await fetchApiJson('email_assistant_execute.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    communication_id: communicationId,
                    goal: 'send',
                    action: action || 'send_reply'
                })
            });
            var expectedAction = action === 'send_latest_quote' ? 'send_document' : 'send_customer_reply';
            if (hasSuccessfulAssistantResult(data, expectedAction)) {
                window.location.reload();
                return;
            }
            var suggested = extractAssistantBody(data);
            if (suggested && bodyEl) {
                bodyEl.value = suggested;
                bodyEl.focus();
            }
            if (window.__crmRenderAssistantStatus) {
                window.__crmRenderAssistantStatus(
                    data || {},
                    data.summary_text || data.error || (action === 'send_latest_quote' ? 'Failed to send latest quote' : 'Failed to send assistant reply')
                );
            }
            alert((data.summary_text || data.error || (action === 'send_latest_quote' ? 'Failed to send latest quote' : 'Failed to send assistant reply')));
        } catch (e) {
            if (window.__crmRenderAssistantStatus) {
                window.__crmRenderAssistantStatus({}, e.message || (action === 'send_latest_quote' ? 'Failed to send latest quote' : 'Failed to send assistant reply'));
            }
            alert('Error: ' + e.message);
        }
        button.disabled = false;
        button.textContent = original;
    };
})();
</script>

<?php
$lastInboundBody = '';
if (($communication['channel'] ?? '') === 'whatsapp' && $whatsApp24hrWindowOpen) {
    foreach ($threadCommunications as $m) {
        if (($m['direction'] ?? '') === 'inbound') {
            $body = trim($m['body'] ?? '');
            if (strlen($body) > 10 && !in_array($body, ['[Media]', '[Image]', '[Video]', '[Audio]', '[Document]', '[Sticker]'])) {
                $lastInboundBody = $body;
                break;
            }
        }
    }
}
?>
<?php if (($communication['channel'] ?? '') === 'whatsapp' && !$whatsApp24hrWindowOpen): ?>
<div class="chat-reply-window-closed">
    Custom messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed. Use <a href="whatsapp_compose.php?contact_id=<?php echo (int)$communication['contact_id']; ?>" style="color: #856404; font-weight: 600;">WhatsApp Compose</a> to send a template message.
</div>
<?php endif; ?>
<?php if (($communication['channel'] ?? '') === 'whatsapp' && $whatsApp24hrWindowOpen && $lastInboundBody): ?>
<div id="quick-replies-container" style="margin-bottom: var(--spacing-sm); max-width: 720px;">
    <div style="font-size: 11px; color: var(--charcoal-grey); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;">AI Quick Replies</div>
    <div id="quick-replies-chips" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
</div>
<?php endif; ?>
<!-- Reply bar (chat-style) -->
<div class="chat-reply <?php echo (($communication['channel'] ?? '') === 'email') ? 'chat-reply-email' : ''; ?>">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
        <?php if ($aiDraft): ?>
        <button type="button" id="use-ai-draft-btn" style="padding: 4px 10px; font-size: 12px; background: #1d4ed8; color: #fff; border: 1px solid #1e40af; border-radius: 4px; cursor: pointer;">Use AI draft</button>
        <span style="font-size: 11px; color: #64748b;">
            Confidence <?php echo number_format((float) ($aiDraft['confidence'] ?? 0), 2); ?> · <?php echo htmlspecialchars((string) ($aiDraft['reason_code'] ?? 'draft')); ?>
        </span>
        <span id="ai-draft-content" style="display:none;"><?php echo htmlspecialchars((string) ($aiDraft['reply_text'] ?? '')); ?></span>
        <?php endif; ?>
        <button type="button" id="suggest-reply-btn" data-communication-id="<?php echo (int) ($communication['id'] ?? $communicationId); ?>" data-contact-id="<?php echo (int) ($communication['contact_id'] ?? 0); ?>" data-channel="<?php echo htmlspecialchars((string) ($communication['channel'] ?? '')); ?>" onclick="window.__crmSuggestReply && window.__crmSuggestReply(this, <?php echo (int) ($communication['id'] ?? $communicationId); ?>);" style="padding: 4px 10px; font-size: 12px; background: var(--light-grey); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;" <?php if (($communication['channel'] ?? '') === 'whatsapp' && !$whatsApp24hrWindowOpen) echo 'disabled'; ?>>Suggest reply</button>
        <?php if (($communication['channel'] ?? '') === 'email'): ?>
        <button type="button" data-communication-id="<?php echo (int) ($communication['id'] ?? $communicationId); ?>" onclick="window.__crmPreviewCommercialReply && window.__crmPreviewCommercialReply(this, <?php echo (int) ($communication['id'] ?? $communicationId); ?>, 'draft_reply');" style="padding: 4px 10px; font-size: 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; cursor: pointer;">AI Reply</button>
        <button type="button" data-communication-id="<?php echo (int) ($communication['id'] ?? $communicationId); ?>" onclick="window.__crmPreviewCommercialReply && window.__crmPreviewCommercialReply(this, <?php echo (int) ($communication['id'] ?? $communicationId); ?>, 'revise_reply');" style="padding: 4px 10px; font-size: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer;">AI Revise + Reply</button>
        <button type="button" data-communication-id="<?php echo (int) ($communication['id'] ?? $communicationId); ?>" onclick="window.__crmSendCommercialReply && window.__crmSendCommercialReply(this, <?php echo (int) ($communication['id'] ?? $communicationId); ?>, 'send_latest_quote');" style="padding: 4px 10px; font-size: 12px; background: #0f766e; color: #fff; border: 1px solid #115e59; border-radius: 4px; cursor: pointer;">AI Send Latest Quote</button>
        <?php endif; ?>
    </div>
    <div id="assistant-commercial-preview" style="display:none;margin-bottom:8px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#475569;font-size:12px;"></div>
    <form method="POST" action="" enctype="multipart/form-data" id="conversation-reply-form" data-channel="<?php echo htmlspecialchars((string) ($communication['channel'] ?? '')); ?>" data-has-error="<?php echo $error ? '1' : '0'; ?>" data-notice="<?php echo htmlspecialchars((string) ($_GET['notice'] ?? '')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="reply" value="1">
        <?php if (($communication['channel'] ?? '') === 'whatsapp' && $whatsApp24hrWindowOpen): ?>
        <div id="chat-reply-preview" class="chat-reply-preview" style="display: none;"></div>
        <?php endif; ?>
        <div class="chat-reply-row">
        <?php if (($communication['channel'] ?? '') === 'whatsapp' && $whatsApp24hrWindowOpen): ?>
        <label class="chat-reply-attach" title="Attach image or video">
            <input type="file" name="media" id="media-input" accept="image/*,video/*" aria-label="Attach media">
            <span class="chat-reply-attach-icon" aria-hidden="true">&#128206;</span>
        </label>
        <?php endif; ?>
        <textarea id="body" name="body" rows="<?php echo (($communication['channel'] ?? '') === 'email') ? '6' : '3'; ?>" <?php if (($communication['channel'] ?? '') === 'whatsapp' && !$whatsApp24hrWindowOpen) { echo 'disabled'; } elseif (($communication['channel'] ?? '') !== 'whatsapp') { echo 'required'; } ?> placeholder="<?php echo (($communication['channel'] ?? '') === 'whatsapp' && !$whatsApp24hrWindowOpen) ? '24-hour window closed' : 'Type your reply or attach image/video...'; ?>" aria-label="Message"></textarea>
        <button type="submit" id="conversation-reply-submit" title="Send" <?php if (($communication['channel'] ?? '') === 'whatsapp' && !$whatsApp24hrWindowOpen) echo 'disabled'; ?>>Send</button>
        </div>
        <div id="chat-reply-send-status" class="chat-reply-send-status" aria-live="polite"></div>
    </form>
</div>
<script>
(function() {
    var replyForm = document.getElementById('conversation-reply-form');
    var replyTextarea = document.getElementById('body');
    if (!replyTextarea) return;

    function resizeReplyTextarea() {
        replyTextarea.style.height = 'auto';
        var computed = window.getComputedStyle(replyTextarea);
        var maxHeight = parseInt(computed.maxHeight || '0', 10);
        var nextHeight = replyTextarea.scrollHeight;
        if (maxHeight > 0 && nextHeight > maxHeight) {
            nextHeight = maxHeight;
            replyTextarea.style.overflowY = 'auto';
        } else {
            replyTextarea.style.overflowY = 'hidden';
        }
        replyTextarea.style.height = nextHeight + 'px';
    }

    replyTextarea.addEventListener('input', resizeReplyTextarea);
    window.setTimeout(resizeReplyTextarea, 0);

    if (!replyForm) {
        return;
    }

    var replyStatus = document.getElementById('chat-reply-send-status');
    var submitButton = document.getElementById('conversation-reply-submit');
    var isWhatsapp = replyForm.getAttribute('data-channel') === 'whatsapp';

    function setReplyStatus(message, state) {
        if (!replyStatus) {
            return;
        }
        replyStatus.className = 'chat-reply-send-status';
        if (!message) {
            replyStatus.textContent = '';
            return;
        }
        replyStatus.textContent = message;
        replyStatus.classList.add('is-active');
        if (state) {
            replyStatus.classList.add('is-' + state);
        }
    }

    if (isWhatsapp) {
        if (replyForm.getAttribute('data-notice') === 'reply_sent') {
            setReplyStatus('WhatsApp message sent successfully.', 'success');
        } else if (replyForm.getAttribute('data-has-error') === '1') {
            setReplyStatus('WhatsApp message could not be sent. Please review the error above and try again.', 'error');
        }

        replyForm.addEventListener('submit', function() {
            if (!submitButton || submitButton.disabled) {
                return;
            }
            submitButton.disabled = true;
            submitButton.textContent = '...';
            submitButton.setAttribute('aria-busy', 'true');
            setReplyStatus('Sending WhatsApp message...', 'active');
        });
    }
})();
</script>
<div class="conversation-secondary">
    <details class="conversation-secondary-card" <?php echo ($threadSummaryText !== '' || !empty($threadConcerns)) ? 'open' : ''; ?>>
        <summary>Thread intelligence</summary>
        <div class="conversation-secondary-body">
            <div class="conversation-secondary-meta">
                <div>
                    <span class="conversation-secondary-label">Sentiment</span>
                    <strong><?php echo htmlspecialchars($threadSentiment); ?></strong>
                </div>
                <div>
                    <span class="conversation-secondary-label">Open items</span>
                    <strong><?php echo (int) ($conversationThreadState['unresolved_item_count'] ?? 0); ?></strong>
                </div>
                <div>
                    <span class="conversation-secondary-label">Owner</span>
                    <strong><?php echo htmlspecialchars($threadOwnerLabel); ?></strong>
                </div>
            </div>
            <?php if ($threadSummaryText !== ''): ?>
                <div class="conversation-secondary-summary"><?php echo htmlspecialchars($threadSummaryText); ?></div>
            <?php endif; ?>
            <?php if (!empty($threadConcerns)): ?>
                <div class="conversation-secondary-concerns">
                    <?php foreach ($threadConcerns as $concern): ?>
                        <span><?php echo htmlspecialchars((string) $concern); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div id="thread-summary-section">
                <button type="button" id="summarize-thread-btn" data-communication-id="<?php echo (int) ($communication['id'] ?? $communicationId); ?>" data-contact-id="<?php echo (int) ($communication['contact_id'] ?? 0); ?>" data-channel="<?php echo htmlspecialchars((string) ($communication['channel'] ?? '')); ?>" onclick="window.__crmSummarizeThread && window.__crmSummarizeThread(this, <?php echo (int) ($communication['id'] ?? $communicationId); ?>);">
                    Summarize thread
                </button>
                <div id="thread-summary-result" style="display: none;">
                    <div id="thread-summary-status" role="status" aria-live="polite"></div>
                    <p id="thread-summary-text"></p>
                </div>
            </div>
        </div>
    </details>
</div>
<?php if (($communication['channel'] ?? '') === 'whatsapp' && $whatsApp24hrWindowOpen): ?>
<script>
(function() {
    var mediaInput = document.getElementById('media-input');
    var previewDiv = document.getElementById('chat-reply-preview');
    if (!mediaInput || !previewDiv) return;

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function clearPreview() {
        previewDiv.innerHTML = '';
        previewDiv.style.display = 'none';
        mediaInput.value = '';
    }

    function renderPreview(file) {
        previewDiv.innerHTML = '';
        previewDiv.style.display = 'flex';
        var item = document.createElement('div');
        item.className = 'chat-reply-preview-item';
        var isImage = file.type && file.type.indexOf('image/') === 0;
        if (isImage) {
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = file.name;
            item.appendChild(img);
        } else {
            var tile = document.createElement('div');
            tile.className = 'chat-reply-preview-tile';
            tile.innerHTML = '<strong>' + (file.name || 'Video') + '</strong>' + formatSize(file.size || 0) + ' \u2022 ' + (file.type || 'video');
            item.appendChild(tile);
        }
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'chat-reply-preview-remove';
        removeBtn.title = 'Remove';
        removeBtn.innerHTML = '\u00D7';
        removeBtn.onclick = function() { clearPreview(); };
        item.appendChild(removeBtn);
        previewDiv.appendChild(item);
    }

    mediaInput.addEventListener('change', function() {
        var file = this.files && this.files[0];
        if (file) {
            renderPreview(file);
        } else {
            clearPreview();
        }
    });
})();
</script>
<?php endif; ?>
</div>
<script>
(function() {
    var useDraftBtn = document.getElementById('use-ai-draft-btn');
    var aiDraftSource = document.getElementById('ai-draft-content');
    var draftBody = document.getElementById('body');
    if (useDraftBtn && aiDraftSource && draftBody) {
        useDraftBtn.addEventListener('click', function () {
            draftBody.value = (aiDraftSource.textContent || '').trim();
            draftBody.dispatchEvent(new Event('input'));
            draftBody.focus();
        });
    }

    var contactId = <?php echo (int)$communication['contact_id']; ?>;
    var lastInboundBody = <?php echo json_encode($lastInboundBody); ?>;
    var container = document.getElementById('quick-replies-chips');
    if (!container || !lastInboundBody) return;
    var bodyEl = document.getElementById('body');
    if (!bodyEl) return;

    fetch('../api/quick_replies.php?contact_id=' + encodeURIComponent(contactId) + '&message=' + encodeURIComponent(lastInboundBody))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var suggestions = data.suggestions || [];
            if (suggestions.length === 0) {
                document.getElementById('quick-replies-container').style.display = 'none';
                return;
            }
            suggestions.forEach(function(text) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.textContent = text;
                chip.style.cssText = 'padding: 6px 12px; border-radius: 16px; border: 1px solid var(--border-color); background: white; font-size: 13px; cursor: pointer; color: var(--midnight-black); transition: all 0.15s;';
                chip.onmouseover = function() { chip.style.background = '#f0f7ff'; chip.style.borderColor = 'var(--accent-blue)'; };
                chip.onmouseout = function() { chip.style.background = 'white'; chip.style.borderColor = 'var(--border-color)'; };
                chip.onclick = function() {
                    bodyEl.value = (bodyEl.value ? bodyEl.value + ' ' : '') + text;
                    bodyEl.focus();
                };
                container.appendChild(chip);
            });
        })
        .catch(function() {
            document.getElementById('quick-replies-container').style.display = 'none';
        });
})();
</script>
<script>
(function() {
    function revealDemoTriage() {
        var strip = document.querySelector('[data-protected-demo-triage]');
        if (!strip) return;
        strip.hidden = false;
        window.requestAnimationFrame(function() {
            strip.classList.add('is-visible');
        });
        strip.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    window.addEventListener('protected-demo:scene', function(event) {
        var detail = event.detail || {};
        var payload = detail.payload || {};
        var sceneKey = String(detail.scene_key || payload.scene_key || payload.demo_event_key || '');
        if (sceneKey !== 'assistant_draft_typing_started' && sceneKey !== 'assistant_draft_ready') return;
        revealDemoTriage();
        window.setTimeout(function() {
            window.dispatchEvent(new CustomEvent('protected-demo:cue', {
                detail: { cue: 'draft_panel_visible', visibleKey: 'conversation_draft' }
            }));
        }, 900);
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
