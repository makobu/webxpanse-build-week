<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Authorization;
use CRM\Modules\DemoModeManager;

Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', [
    'profile::',
    'contacts::',
    'tasks::',
    'deals::',
    'notifications::',
    'messages::',
    'admin-email::',
    'admin-password::',
    'reset-admin-password',
    'help',
]);

if (isset($options['help'])) {
    echo "Seed mobile-friendly demo data for the CRM.\n\n";
    echo "Usage:\n";
    echo "  php scripts/seed_mobile_demo_data.php [--profile=interiors_contractor] [--contacts=8 --tasks=8 --deals=6 --notifications=4 --messages=6] [--admin-email=mobile.test@crm.local] [--admin-password=MobileDemo123!] [--reset-admin-password]\n\n";
    echo "Profiles:\n";
    echo "  interiors_contractor (default)\n";
    echo "  whatsapp_heavy_smb\n";
    echo "  agencies\n";
    echo "  distributors_wholesalers\n";
    echo "  full\n";
    echo "\nFocused top-up:\n";
    echo "  --contacts=N   add N contacts only\n";
    echo "  --tasks=N      add N tasks, attaching them to the seeded contacts when possible\n";
    echo "  --deals=N      add N deals spread across pipeline stages\n";
    echo "  --notifications=N add N mobile notifications with mixed read states\n";
    echo "  --messages=N   add N inbox threads with recent seeded messages for unread/open testing\n";
    exit(0);
}

$profile = trim((string) ($options['profile'] ?? 'interiors_contractor'));
$contactsToSeed = max(0, (int) ($options['contacts'] ?? 0));
$tasksToSeed = max(0, (int) ($options['tasks'] ?? 0));
$dealsToSeed = max(0, (int) ($options['deals'] ?? 0));
$notificationsToSeed = max(0, (int) ($options['notifications'] ?? 0));
$messagesToSeed = max(0, (int) ($options['messages'] ?? 0));
$adminEmail = trim((string) ($options['admin-email'] ?? 'mobile.test@crm.local'));
$adminPassword = (string) ($options['admin-password'] ?? 'MobileDemo123!');
$resetAdminPassword = array_key_exists('reset-admin-password', $options);

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function uuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function tableExists(string $tableName): bool
{
    $row = Database::queryOne(
        'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tableName]
    );

    return ((int) ($row['cnt'] ?? 0)) > 0;
}

function ensureAdminUser(string $email, string $password, bool $resetPassword): array
{
    $existing = Database::queryOne('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($existing) {
        if ($resetPassword) {
            Database::execute(
                'UPDATE users SET password_hash = ?, role = ?, updated_at = NOW() WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), 'admin', (int) $existing['id']]
            );
        }

        $rbacAssigned = Authorization::assignUserRoleBySlug((int) $existing['id'], 'superadmin', (int) $existing['id']);

        return [
            'id' => (int) $existing['id'],
            'email' => $email,
            'password' => $resetPassword ? $password : null,
            'created' => false,
            'password_reset' => $resetPassword,
            'rbac_role' => $rbacAssigned ? 'superadmin' : 'legacy admin only',
        ];
    }

    Database::execute(
        'INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [
            uuidV4(),
            'Mobile',
            'Tester',
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            'admin',
        ]
    );
    $userId = (int) Database::lastInsertId();
    $rbacAssigned = Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

    return [
        'id' => $userId,
        'email' => $email,
        'password' => $password,
        'created' => true,
        'password_reset' => false,
        'rbac_role' => $rbacAssigned ? 'superadmin' : 'legacy admin only',
    ];
}

function ensureSeededOwnerUser(string $email = 'owner@crm.local', string $password = 'Owner123!@#$'): array
{
    $existing = Database::queryOne('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($existing) {
        Database::execute(
            'UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?',
            ['owner', (int) $existing['id']]
        );
        $rbacAssigned = Authorization::assignUserRoleBySlug((int) $existing['id'], 'owner', (int) $existing['id']);

        return [
            'id' => (int) $existing['id'],
            'email' => $email,
            'password' => null,
            'created' => false,
            'rbac_role' => $rbacAssigned ? 'owner' : 'legacy owner only',
        ];
    }

    Database::execute(
        'INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [
            uuidV4(),
            'Workspace',
            'Owner',
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            'owner',
        ]
    );

    $userId = (int) Database::lastInsertId();
    $rbacAssigned = Authorization::assignUserRoleBySlug($userId, 'owner', $userId);

    return [
        'id' => $userId,
        'email' => $email,
        'password' => $password,
        'created' => true,
        'rbac_role' => $rbacAssigned ? 'owner' : 'legacy owner only',
    ];
}

function seededUsersForRun(int $runId): array
{
    $users = [];
    if (tableExists('demo_seed_registry')) {
        $users = Database::query(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.role
             FROM demo_seed_registry r
             INNER JOIN users u ON u.id = r.record_id
             WHERE r.run_id = ? AND r.table_name = ?
             ORDER BY u.id ASC',
            [$runId, 'users']
        );
    }

    $fallbackUsers = Database::query(
        'SELECT id, first_name, last_name, email, role
         FROM users
         WHERE email LIKE ?
         ORDER BY id ASC',
        ['demo.%.' . $runId . '@example.test']
    );

    $byEmail = [];
    foreach (array_merge($users, $fallbackUsers) as $user) {
        $email = strtolower((string) ($user['email'] ?? ''));
        if ($email === '') {
            continue;
        }
        $byEmail[$email] = $user;
    }

    return array_values($byEmail);
}

function registryCountsForRun(int $runId): array
{
    if (!tableExists('demo_seed_registry')) {
        return [];
    }

    $rows = Database::query(
        'SELECT table_name, COUNT(*) AS total
         FROM demo_seed_registry
         WHERE run_id = ?
         GROUP BY table_name
         ORDER BY table_name ASC',
        [$runId]
    );

    $counts = [];
    foreach ($rows as $row) {
        $counts[(string) $row['table_name']] = (int) ($row['total'] ?? 0);
    }

    return $counts;
}

function predictableDemoPassword(string $email, int $runId): ?string
{
    $pattern = '/^demo\.[^.]+\.' . preg_quote((string) $runId, '/') . '@example\.test$/';
    if (preg_match($pattern, $email) !== 1) {
        return null;
    }

    return 'DemoMode#' . $runId;
}

function bestMobileUser(array $users, int $runId): ?array
{
    $preferredRoles = ['sales', 'sitelead', 'marketing', 'admin'];
    foreach ($preferredRoles as $role) {
        foreach ($users as $user) {
            if (strtolower((string) ($user['role'] ?? '')) === $role) {
                $user['password'] = predictableDemoPassword((string) $user['email'], $runId);
                return $user;
            }
        }
    }

    if ($users === []) {
        return null;
    }

    $users[0]['password'] = predictableDemoPassword((string) $users[0]['email'], $runId);
    return $users[0];
}

function insertContactRow(array $data): int
{
    Database::execute(
        'INSERT INTO contacts (uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['uuid'],
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'],
            $data['company'],
            $data['lead_source'],
            $data['stage'],
            $data['assigned_to'],
            $data['created_at'],
        ]
    );

    return (int) Database::lastInsertId();
}

function insertTaskRow(array $data): int
{
    Database::execute(
        'INSERT INTO tasks (title, description, contact_id, assigned_to, created_by, status, priority, due_date, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['title'],
            $data['description'],
            $data['contact_id'],
            $data['assigned_to'],
            $data['created_by'],
            $data['status'],
            $data['priority'],
            $data['due_date'],
            $data['created_at'],
        ]
    );

    return (int) Database::lastInsertId();
}

function insertDealRow(array $data): int
{
    Database::execute(
        'INSERT INTO deals (title, description, contact_id, assigned_to, created_by, stage, value, probability, expected_close_date, currency, lead_source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['title'],
            $data['description'],
            $data['contact_id'],
            $data['assigned_to'],
            $data['created_by'],
            $data['stage'],
            $data['value'],
            $data['probability'],
            $data['expected_close_date'],
            $data['currency'],
            $data['lead_source'],
            $data['created_at'],
        ]
    );

    return (int) Database::lastInsertId();
}

function insertNotificationRow(array $data): int
{
    Database::execute(
        'INSERT INTO notifications (user_id, type, title, message, entity_type, entity_id, link, severity, is_read, read_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['user_id'],
            $data['type'],
            $data['title'],
            $data['message'],
            $data['entity_type'],
            $data['entity_id'],
            $data['link'],
            $data['severity'],
            $data['is_read'],
            $data['read_at'],
            $data['created_at'],
        ]
    );

    return (int) Database::lastInsertId();
}

function insertConversationThreadRow(array $data): int
{
    Database::execute(
        'INSERT INTO conversation_threads (
            contact_id, channel, thread_key, last_message_at, status,
            current_owner_id, priority, response_due_at, last_inbound_at,
            last_outbound_at, last_channel, unresolved_item_count,
            escalation_status, resolution_reason, metadata_json,
            message_count, is_resolved, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['contact_id'],
            $data['channel'],
            $data['thread_key'],
            $data['last_message_at'],
            $data['status'],
            $data['current_owner_id'],
            $data['priority'],
            $data['response_due_at'],
            $data['last_inbound_at'],
            $data['last_outbound_at'],
            $data['last_channel'],
            $data['unresolved_item_count'],
            $data['escalation_status'],
            $data['resolution_reason'],
            $data['metadata_json'],
            $data['message_count'],
            $data['is_resolved'],
            $data['created_at'],
        ]
    );

    return (int) Database::lastInsertId();
}

function insertCommunicationRow(array $data): int
{
    Database::execute(
        'INSERT INTO communications (
            uuid, contact_id, thread_key, channel, direction, subject, body,
            metadata, status, read_at, created_at, from_email, to_email
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $data['uuid'],
            $data['contact_id'],
            $data['thread_key'],
            $data['channel'],
            $data['direction'],
            $data['subject'],
            $data['body'],
            $data['metadata'],
            $data['status'],
            $data['read_at'],
            $data['created_at'],
            $data['from_email'],
            $data['to_email'],
        ]
    );

    return (int) Database::lastInsertId();
}

function seedFocusedMobileTopUp(
    int $adminUserId,
    int $contactsToSeed,
    int $tasksToSeed,
    int $dealsToSeed,
    int $notificationsToSeed,
    int $messagesToSeed
): array
{
    $created = [
        'contacts' => 0,
        'tasks' => 0,
        'deals' => 0,
        'notifications' => 0,
        'messages' => 0,
        'conversation_threads' => 0,
    ];
    $contactIds = [];
    $taskIds = [];
    $dealIds = [];
    $seedKey = date('YmdHis');
    $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation'];
    $sources = ['form', 'whatsapp', 'referral', 'social', 'other'];
    $priorities = ['medium', 'high', 'urgent'];
    $statuses = ['pending', 'in_progress', 'pending'];
    $dealStages = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
    $notificationTypes = ['task_due', 'deal_update', 'conversation_alert', 'ai_coach_nudge'];
    $notificationSeverities = ['normal', 'high', 'urgent', 'normal'];

    for ($i = 1; $i <= $contactsToSeed; $i++) {
        $contactId = insertContactRow([
            'uuid' => uuidV4(),
            'first_name' => 'Mobile',
            'last_name' => 'Lead ' . $seedKey . '-' . $i,
            'email' => sprintf('mobile.lead.%s.%d@example.test', $seedKey, $i),
            'phone' => '+254700' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
            'company' => 'Mobile Pipeline ' . (int) ceil($i / 2),
            'lead_source' => $sources[($i - 1) % count($sources)],
            'stage' => $stages[($i - 1) % count($stages)],
            'assigned_to' => $adminUserId,
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . min(14, $i) . ' days')),
        ]);
        $contactIds[] = $contactId;
        $created['contacts']++;
    }

    if (($tasksToSeed > 0 || $dealsToSeed > 0 || $messagesToSeed > 0) && $contactIds === []) {
        $fallbackContacts = Database::query(
            'SELECT id FROM contacts WHERE assigned_to = ? ORDER BY id DESC LIMIT ?',
            [$adminUserId, max(1, max($tasksToSeed, max($dealsToSeed, $messagesToSeed)))]
        );
        foreach ($fallbackContacts as $row) {
            $contactIds[] = (int) ($row['id'] ?? 0);
        }
    }

    for ($i = 1; $i <= $tasksToSeed; $i++) {
        $contactId = $contactIds !== [] ? $contactIds[($i - 1) % count($contactIds)] : null;
        insertTaskRow([
            'title' => 'Mobile follow-up task ' . $seedKey . '-' . $i,
            'description' => 'Seeded for mobile testing. Review the contact, send a follow-up, and update the next action.',
            'contact_id' => $contactId,
            'assigned_to' => $adminUserId,
            'created_by' => $adminUserId,
            'status' => $statuses[($i - 1) % count($statuses)],
            'priority' => $priorities[($i - 1) % count($priorities)],
            'due_date' => date('Y-m-d H:i:s', strtotime(($i % 3 === 0 ? '-' : '+') . max(1, $i) . ' days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . min(10, $i) . ' days')),
        ]);
        $taskIds[] = (int) Database::lastInsertId();
        $created['tasks']++;
    }

    for ($i = 1; $i <= $dealsToSeed; $i++) {
        $contactId = $contactIds !== [] ? $contactIds[($i - 1) % count($contactIds)] : null;
        if ($contactId === null) {
            continue;
        }

        insertDealRow([
            'title' => 'Mobile pipeline deal ' . $seedKey . '-' . $i,
            'description' => 'Seeded for mobile testing. Use this to validate stage chips, deal detail, and pipeline summaries.',
            'contact_id' => $contactId,
            'assigned_to' => $adminUserId,
            'created_by' => $adminUserId,
            'stage' => $dealStages[($i - 1) % count($dealStages)],
            'value' => 4500 + ($i * 1750),
            'probability' => min(95, 15 + ($i * 12)),
            'expected_close_date' => date('Y-m-d', strtotime('+' . max(3, $i * 2) . ' days')),
            'currency' => 'KES',
            'lead_source' => 'mobile_demo_topup',
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . min(12, $i + 1) . ' days')),
        ]);
        $dealIds[] = (int) Database::lastInsertId();
        $created['deals']++;
    }

    for ($i = 1; $i <= $notificationsToSeed; $i++) {
        $notificationType = $notificationTypes[($i - 1) % count($notificationTypes)];
        $isRead = $i % 3 === 0 ? 1 : 0;
        $linkedTaskId = $taskIds !== [] ? $taskIds[($i - 1) % count($taskIds)] : null;
        $linkedDealId = $dealIds !== [] ? $dealIds[($i - 1) % count($dealIds)] : null;
        $entityType = $notificationType === 'task_due'
            ? 'task'
            : ($notificationType === 'deal_update' ? 'deal' : 'conversation');
        $entityId = $entityType === 'task'
            ? $linkedTaskId
            : ($entityType === 'deal' ? $linkedDealId : null);
        $link = $entityType === 'task' && $entityId !== null
            ? 'task_view.php?id=' . $entityId
            : ($entityType === 'deal' && $entityId !== null
                ? 'deal_view.php?id=' . $entityId
                : 'conversation.php?id=' . (($dealIds[0] ?? 1)));

        insertNotificationRow([
            'user_id' => $adminUserId,
            'type' => $notificationType,
            'title' => ucfirst(str_replace('_', ' ', $notificationType)) . ' seed ' . $i,
            'message' => match ($notificationType) {
                'task_due' => 'A seeded follow-up task is due soon and ready for mobile notification testing.',
                'deal_update' => 'A seeded deal changed stage and is ready for pipeline notification testing.',
                'conversation_alert' => 'A seeded inbox thread needs attention and should appear in notifications.',
                default => 'The AI assistant has a seeded suggestion ready for the mobile home and notifications surfaces.',
            },
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'link' => $link,
            'severity' => $notificationSeverities[($i - 1) % count($notificationSeverities)],
            'is_read' => $isRead,
            'read_at' => $isRead ? date('Y-m-d H:i:s', strtotime('-' . $i . ' hours')) : null,
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . max(1, $i) . ' hours')),
        ]);
        $created['notifications']++;
    }

    $messageChannels = ['whatsapp', 'email', 'sms', 'whatsapp', 'email', 'whatsapp'];
    for ($i = 1; $i <= $messagesToSeed; $i++) {
        $contactId = $contactIds !== [] ? $contactIds[($i - 1) % count($contactIds)] : null;
        if ($contactId === null) {
            continue;
        }

        $channel = $messageChannels[($i - 1) % count($messageChannels)];
        $threadKey = sprintf('mobile-seed-thread-%s-%d', $seedKey, $i);
        $createdAt = date('Y-m-d H:i:s', strtotime('-' . max(2, $i + 1) . ' hours'));
        $lastInboundAt = date('Y-m-d H:i:s', strtotime('-' . max(1, $i) . ' hours'));
        $assignedOwnerId = $i % 3 === 0 ? null : $adminUserId;

        insertConversationThreadRow([
            'contact_id' => $contactId,
            'channel' => $channel,
            'thread_key' => $threadKey,
            'last_message_at' => $lastInboundAt,
            'status' => 'open',
            'current_owner_id' => $assignedOwnerId,
            'priority' => $i % 2 === 0 ? 'high' : 'normal',
            'response_due_at' => date('Y-m-d H:i:s', strtotime('+' . max(1, $i) . ' hours')),
            'last_inbound_at' => $lastInboundAt,
            'last_outbound_at' => $createdAt,
            'last_channel' => $channel,
            'unresolved_item_count' => $i % 2 === 0 ? 1 : 0,
            'escalation_status' => null,
            'resolution_reason' => null,
            'metadata_json' => json_encode(['source' => 'mobile_demo_topup']),
            'message_count' => 2,
            'is_resolved' => 0,
            'created_at' => $createdAt,
        ]);
        $created['conversation_threads']++;

        insertCommunicationRow([
            'uuid' => uuidV4(),
            'contact_id' => $contactId,
            'thread_key' => $threadKey,
            'channel' => $channel,
            'direction' => 'outbound',
            'subject' => ucfirst($channel) . ' seeded follow-up',
            'body' => 'Checking in with updated information for your request.',
            'metadata' => json_encode(['source' => 'mobile_demo_topup', 'step' => 'follow_up']),
            'status' => 'sent',
            'read_at' => $createdAt,
            'created_at' => $createdAt,
            'from_email' => 'sales@example.test',
            'to_email' => 'lead.' . $seedKey . '.' . $i . '@example.test',
        ]);
        insertCommunicationRow([
            'uuid' => uuidV4(),
            'contact_id' => $contactId,
            'thread_key' => $threadKey,
            'channel' => $channel,
            'direction' => 'inbound',
            'subject' => ucfirst($channel) . ' customer reply',
            'body' => match ($channel) {
                'whatsapp' => 'Hi, can you confirm the latest pricing and timeline before I proceed?',
                'sms' => 'Please confirm if this can still be done by tomorrow.',
                default => 'Thanks for the update. I still have one question before I approve the next step.',
            },
            'metadata' => json_encode(['source' => 'mobile_demo_topup', 'step' => 'customer_reply']),
            'status' => 'received',
            'read_at' => $i % 5 === 0 ? date('Y-m-d H:i:s', strtotime('-30 minutes')) : null,
            'created_at' => $lastInboundAt,
            'from_email' => 'lead.' . $seedKey . '.' . $i . '@example.test',
            'to_email' => 'sales@example.test',
        ]);
        $created['messages'] += 2;
    }

    return $created;
}

out('Seeding mobile demo data...');
if ($contactsToSeed > 0 || $tasksToSeed > 0 || $dealsToSeed > 0 || $notificationsToSeed > 0 || $messagesToSeed > 0) {
    out(sprintf(
        'Focused seed: contacts=%d tasks=%d deals=%d notifications=%d messages=%d',
        $contactsToSeed,
        $tasksToSeed,
        $dealsToSeed,
        $notificationsToSeed,
        $messagesToSeed
    ));
} else {
    out('Profile: ' . $profile);
}

try {
    $admin = ensureAdminUser($adminEmail, $adminPassword, $resetAdminPassword);
    $owner = ensureSeededOwnerUser();
    $summary = [];
    $counts = [];
    $users = [];
    $primaryMobileUser = null;
    $runId = 0;

    if ($contactsToSeed > 0 || $tasksToSeed > 0 || $dealsToSeed > 0 || $notificationsToSeed > 0 || $messagesToSeed > 0) {
        if ($contactsToSeed > 0 && !tableExists('contacts')) {
            throw new RuntimeException('Contacts table is missing. Run migrations first.');
        }
        if ($tasksToSeed > 0 && !tableExists('tasks')) {
            throw new RuntimeException('Tasks table is missing. Run migrations first.');
        }
        if ($dealsToSeed > 0 && !tableExists('deals')) {
            throw new RuntimeException('Deals table is missing. Run migrations first.');
        }
        if ($notificationsToSeed > 0 && !tableExists('notifications')) {
            throw new RuntimeException('Notifications table is missing. Run migrations first.');
        }
        if ($messagesToSeed > 0 && !tableExists('communications')) {
            throw new RuntimeException('Communications table is missing. Run migrations first.');
        }
        if ($messagesToSeed > 0 && !tableExists('conversation_threads')) {
            throw new RuntimeException('Conversation threads table is missing. Run migrations first.');
        }
        $created = seedFocusedMobileTopUp(
            (int) $admin['id'],
            $contactsToSeed,
            $tasksToSeed,
            $dealsToSeed,
            $notificationsToSeed,
            $messagesToSeed
        );
        $summary = ['created' => $created, 'errors' => []];
    } else {
        $manager = new DemoModeManager();
        $result = $manager->seedScenarioRun((int) $admin['id'], $profile, 'Mobile app test seed');

        if (!($result['success'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'Unknown seed failure'));
        }

        $runId = (int) ($result['run_id'] ?? 0);
        $summary = $result['seed_summary'] ?? [];
        $counts = registryCountsForRun($runId);
        $users = seededUsersForRun($runId);
        $primaryMobileUser = bestMobileUser($users, $runId);
    }

    out();
    out('Seed complete.');
    if ($runId > 0) {
        out('Run ID: ' . $runId);
        out('Scenario: ' . $profile);
    } else {
        out('Scenario: focused mobile top-up');
    }
    out();
    out('Workspace URL for mobile testing:');
    out('  http://10.0.2.2/crm/public/');
    out('  or http://localhost/crm/public/ if testing in desktop browser');
    out();
    out('Admin login:');
    out('  Email: ' . $admin['email']);
    if ($admin['password'] !== null) {
        out('  Password: ' . $admin['password']);
    } else {
        out('  Password: unchanged');
    }
    out('  Access profile: ' . ($admin['rbac_role'] ?? 'superadmin'));
    out();
    out('Owner login:');
    out('  Email: ' . $owner['email']);
    if (($owner['password'] ?? null) !== null) {
        out('  Password: ' . $owner['password']);
    } else {
        out('  Password: unchanged');
    }
    out('  Access profile: ' . ($owner['rbac_role'] ?? 'owner'));
    out();

    if ($primaryMobileUser !== null) {
        out('Recommended mobile operator login:');
        out('  Email: ' . ($primaryMobileUser['email'] ?? 'unknown'));
        out('  Role: ' . ($primaryMobileUser['role'] ?? 'unknown'));
        if (($primaryMobileUser['password'] ?? null) !== null) {
            out('  Password: ' . $primaryMobileUser['password']);
        } else {
            out('  Password: not predictable from seed pattern');
        }
        out();
    }

    if ($users !== []) {
        out('Seeded demo users:');
        foreach ($users as $user) {
            $password = predictableDemoPassword((string) ($user['email'] ?? ''), $runId);
            out(sprintf(
                '  - %s %s <%s> [%s]%s',
                trim((string) ($user['first_name'] ?? '')),
                trim((string) ($user['last_name'] ?? '')),
                (string) ($user['email'] ?? ''),
                (string) ($user['role'] ?? 'unknown'),
                $password !== null ? ' password=' . $password : ''
            ));
        }
        out();
    }

    if ($counts !== []) {
        out('Created records:');
        foreach ($counts as $table => $count) {
            out(sprintf('  - %s: %d', $table, $count));
        }
        out();
    }

    $created = is_array($summary['created'] ?? null) ? $summary['created'] : [];
    $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
    if ($created !== []) {
        out('Seed summary:');
        foreach ($created as $label => $count) {
            out(sprintf('  - %s: %s', $label, (string) $count));
        }
        out();
    }

    if ($errors !== []) {
        out('Seed completed with warnings:');
        foreach ($errors as $error) {
            out('  - ' . (string) $error);
        }
        exit(2);
    }
} catch (Throwable $error) {
    out('Seed failed: ' . $error->getMessage());
    exit(1);
}
