<?php
/**
 * Demo Data Seeder — webXpanse Walkthrough
 * Populates localhost DB with realistic demo data for screenshots.
 * Run: php docs/walkthrough/seed_demo_data.php
 */

// Load .env
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#' || strpos($line,'=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v, '"\'');
        $_ENV[trim($k)] = trim($v);
    }
}

require_once __DIR__ . '/../../vendor/autoload.php';

$dbConfig = require __DIR__ . '/../../config/database.php';
$charset  = $dbConfig['charset'] ?? 'utf8mb4';
$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$charset}";
$pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $dbConfig['options']);

// ── Get the demo user ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute(['launch.operator.probe@example.test']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("Demo user not found. Run migrations first.\n");
}
$uid = $user['id'];
echo "Seeding as user ID: $uid\n";

function uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function ago(int $days): string {
    return date('Y-m-d H:i:s', strtotime("-{$days} days"));
}

// ── CONTACTS ──────────────────────────────────────────────────────────────────
echo "Inserting contacts...\n";

$contacts_data = [
    // [first, last, email, phone, company, stage, lead_source, days_ago]
    ['Amara',   'Osei',     'amara.osei@techbridge.ke',     '+254711001001', 'TechBridge Ltd',        'new',          'social',   1],
    ['David',   'Kimani',   'david.kimani@greengrow.co.ke', '+254722002002', 'GreenGrow Farms',       'new',          'form',     2],
    ['Fatima',  'Hassan',   'fatima.hassan@skyline.co',     '+254733003003', 'Skyline Constructions', 'new',          'referral', 1],
    ['Njeri',   'Wambui',   'njeri.w@retailpro.ke',         '+254744004004', 'RetailPro Kenya',       'contacted',    'import',   5],
    ['Samuel',  'Mutua',    'samuel.m@fastlogix.com',       '+254755005005', 'FastLogix Ltd',         'contacted',    'ad',       4],
    ['Grace',   'Otieno',   'grace.otieno@medicalplus.ke',  '+254766006006', 'MedicalPlus Clinics',   'contacted',    'whatsapp', 6],
    ['Brian',   'Mwangi',   'brian.mwangi@solarsave.ke',    '+254777007007', 'SolarSave Africa',      'qualified',    'form',     8],
    ['Lydia',   'Chebet',   'lydia.chebet@edutech.co.ke',   '+254788008008', 'EduTech Solutions',     'qualified',    'social',   7],
    ['Omar',    'Sheikh',   'omar.sheikh@halalpro.com',      '+254799009009', 'HalalPro Foods',        'qualified',    'referral', 10],
    ['Mercy',   'Achieng',  'mercy.a@fashionhub.ke',        '+254700010010', 'FashionHub KE',         'proposal',     'ad',       12],
    ['Peter',   'Njoroge',  'peter.n@buildmart.ke',         '+254711011011', 'BuildMart Kenya',       'proposal',     'form',     9],
    ['Winnie',  'Kamau',    'winnie.kamau@finserve.co.ke',  '+254722012012', 'FinServe Capital',      'proposal',     'import',   11],
    ['John',    'Kariuki',  'john.kariuki@autoplus.ke',      '+254733013013', 'AutoPlus Garage',       'negotiation',  'social',   15],
    ['Aisha',   'Musa',     'aisha.musa@laundryexpress.ke', '+254744014014', 'Laundry Express',       'negotiation',  'whatsapp', 14],
    ['Charles', 'Odhiambo', 'charles.o@events360.co.ke',   '+254755015015', 'Events 360',            'won',          'referral', 20],
];

$contact_ids = [];
$ins = $pdo->prepare("
    INSERT INTO contacts (uuid, first_name, last_name, email, phone, company, stage, lead_source, assigned_to, created_by, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE first_name=VALUES(first_name)
");

foreach ($contacts_data as [$fn, $ln, $em, $ph, $co, $st, $src, $days]) {
    $ins->execute([uuid(), $fn, $ln, $em, $ph, $co, $st, $src, $uid, $uid, ago($days), ago(max(0,$days-1))]);
    $contact_ids[] = $pdo->lastInsertId();
}
echo "  Created " . count($contact_ids) . " contacts.\n";

// ── DEALS ─────────────────────────────────────────────────────────────────────
echo "Inserting deals...\n";

$deals_data = [
    // [title, contact_idx, stage, value, close_date_offset, days_ago]
    ['Window Supply — TechBridge Office',    0,  'prospecting',   45000,  30,  2],
    ['GreenGrow Irrigation System',          1,  'qualification', 120000, 21,  3],
    ['Skyline Phase 2 Windows',              2,  'proposal',      280000, 14,  1],
    ['RetailPro Store Fitout',               3,  'proposal',      95000,  10,  8], // stale
    ['FastLogix Warehouse Doors',            4,  'negotiation',   175000, 7,   1],
    ['MedicalPlus Clinic Renovation',        5,  'negotiation',   220000, 5,   2],
    ['SolarSave Panel Installation',         6,  'closed_won',    310000, -5,  20],
];

$deal_ids = [];
$ins = $pdo->prepare("
    INSERT INTO deals (title, contact_id, assigned_to, created_by, stage, value, currency, probability, expected_close_date, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, 'KES', ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?)
");

$stage_probs = ['prospecting'=>20,'qualification'=>40,'proposal'=>60,'negotiation'=>80,'closed_won'=>100,'closed_lost'=>0];
foreach ($deals_data as [$title, $cidx, $stage, $value, $close, $days]) {
    $cid = isset($contact_ids[$cidx]) && $contact_ids[$cidx] > 0 ? $contact_ids[$cidx] : null;
    $prob = $stage_probs[$stage] ?? 50;
    $ins->execute([$title, $cid, $uid, $uid, $stage, $value, $prob, $close, ago($days), ago(max(0,$days-1))]);
    $deal_ids[] = $pdo->lastInsertId();
}
echo "  Created " . count($deal_ids) . " deals.\n";

// ── TASKS ─────────────────────────────────────────────────────────────────────
echo "Inserting tasks...\n";

$tasks_data = [
    // [title, contact_idx, status, priority, due_days_from_now]
    ['Follow up on Skyline proposal',        2,  'pending',    'high',   1],
    ['Send pricing sheet to RetailPro',      3,  'pending',    'high',   0],
    ['Call FastLogix re: contract terms',    4,  'pending',    'urgent', 0],
    ['Schedule site visit — MedicalPlus',    5,  'pending',    'medium', 3],
    ['Draft proposal for GreenGrow',         1,  'pending',    'medium', 5],
    ['Onboarding call — TechBridge',         0,  'in_progress','high',   2],
    ['Collect deposit — Events 360',        14,  'in_progress','urgent', -1], // overdue
    ['Send contract — AutoPlus',            12,  'pending',    'high',   -2], // overdue
    ['WhatsApp follow-up — Laundry Express',13,  'pending',    'medium', -3], // overdue
    ['Log demo notes — EduTech',             7,  'completed',  'low',    -5],
    ['Send invoice — SolarSave',             6,  'completed',  'medium', -10],
];

$task_ids = [];
$ins = $pdo->prepare("
    INSERT INTO tasks (title, contact_id, assigned_to, created_by, status, priority, due_date, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?)
");

foreach ($tasks_data as [$title, $cidx, $status, $priority, $due]) {
    $cid = isset($contact_ids[$cidx]) && $contact_ids[$cidx] > 0 ? $contact_ids[$cidx] : null;
    $ins->execute([$title, $cid, $uid, $uid, $status, $priority, $due, ago(3), ago(1)]);
    $task_ids[] = $pdo->lastInsertId();
}
echo "  Created " . count($task_ids) . " tasks.\n";

// ── ACTIVITIES ────────────────────────────────────────────────────────────────
echo "Inserting activities...\n";

$activities_data = [
    // [contact_idx, type, description, days_ago]
    [2,  'email',  'Sent proposal for window supply. Awaiting confirmation.',    1],
    [4,  'call',   'Spoke for 15 minutes. They need revised pricing by Friday.', 1],
    [3,  'note',   'Client visiting showroom on Saturday. Prepare samples.',      2],
    [0,  'email',  'Introduction email sent. Awaiting reply.',                    2],
    [5,  'call',   'Initial discovery call — budget confirmed at KSh 200k+.',    3],
    [12, 'email',  'Sent contract. Chasing signature.',                          3],
    [1,  'note',   'Qualified — needs drip irrigation for 50 acres.',            4],
    [6,  'email',  'Invoice sent for deposit. KSh 93,000.',                      4],
    [13, 'call',   'WhatsApp call — agreed on collection schedule.',             5],
    [7,  'email',  'Demo walkthrough completed. Very interested.',               5],
    [8,  'note',   'Referred by Omar from HalalPro. Strong buyer intent.',       6],
    [9,  'call',   'Pricing call. Budget KSh 90k. Proposal due next week.',      7],
    [10, 'email',  'Proposal draft shared for review.',                          8],
    [11, 'note',   'Spoke to finance team. Awaiting internal approval.',         9],
    [14, 'email',  'Contract signed. Kickoff scheduled for next Monday.',        20],
];

$ins = $pdo->prepare("
    INSERT INTO activities (contact_id, user_id, activity_type, description, created_at)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($activities_data as [$cidx, $type, $desc, $days]) {
    $cid = isset($contact_ids[$cidx]) && $contact_ids[$cidx] > 0 ? $contact_ids[$cidx] : null;
    if ($cid) $ins->execute([$cid, $uid, $type, $desc, ago($days)]);
}
echo "  Created activities.\n";

// ── NOTES ─────────────────────────────────────────────────────────────────────
echo "Inserting notes...\n";

$notes_schema = $pdo->query("SHOW TABLES LIKE 'notes'")->fetchColumn();
if ($notes_schema) {
    $ins = $pdo->prepare("
        INSERT INTO notes (entity_type, entity_id, content, created_by, created_at, updated_at)
        VALUES ('contact', ?, ?, ?, ?, ?)
    ");
    $notes = [
        [2,  'Skyline wants double-glazed units for all 40 windows. Check stock levels before confirming.',  2],
        [4,  'FastLogix want installation completed before end of month. Logistics team needs 2 weeks.',     1],
        [0,  'TechBridge referred by Mercy at FashionHub. Warm lead — decision maker is the CTO.',           3],
        [12, 'AutoPlus has 3 garages. This is the first; more contracts possible if this goes well.',       4],
    ];
    foreach ($notes as [$cidx, $content, $days]) {
        $cid = isset($contact_ids[$cidx]) && $contact_ids[$cidx] > 0 ? $contact_ids[$cidx] : null;
        if ($cid) $ins->execute([$cid, $content, $uid, ago($days), ago($days)]);
    }
    echo "  Created notes.\n";
} else {
    echo "  Notes table not found — skipping.\n";
}

// ── EMAIL CONVERSATIONS (conversation_threads) ────────────────────────────────
echo "Inserting inbox conversations...\n";

$threads_check = $pdo->query("SHOW TABLES LIKE 'conversation_threads'")->fetchColumn();
if ($threads_check) {
    // Check columns available
    $cols = $pdo->query("SHOW COLUMNS FROM conversation_threads")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('contact_id', $cols) && in_array('subject', $cols)) {
        $ins = $pdo->prepare("
            INSERT INTO conversation_threads (contact_id, user_id, subject, last_message_at, status, channel, created_at)
            VALUES (?, ?, ?, ?, ?, 'email', ?)
            ON DUPLICATE KEY UPDATE subject=VALUES(subject)
        ");

        $threads = [
            [2,  'RE: Skyline Constructions — Window Supply Proposal',       ago(1),  'open'],
            [4,  'FastLogix — Revised Pricing Request',                       ago(1),  'open'],
            [3,  'RetailPro Store Fitout — Follow Up',                        ago(8),  'open'],   // stale
            [0,  'TechBridge — Introduction & Demo Request',                  ago(2),  'open'],
            [14, 'Events 360 — Contract & Deposit Confirmation',              ago(20), 'resolved'],
        ];
        foreach ($threads as [$cidx, $subj, $last, $status]) {
            $cid = isset($contact_ids[$cidx]) && $contact_ids[$cidx] > 0 ? $contact_ids[$cidx] : null;
            if ($cid) $ins->execute([$cid, $uid, $subj, $last, $status, ago(max(1, (int)substr($last,0,1))+1)]);
        }
        echo "  Created conversation threads.\n";
    } else {
        echo "  conversation_threads columns differ — skipping inbox seed.\n";
    }
} else {
    echo "  conversation_threads table not found — skipping.\n";
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
echo "Inserting notifications...\n";

$notif_check = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn();
if ($notif_check) {
    $ins = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title=VALUES(title)
    ");
    $notifs = [
        [$uid, 'task_due',      'Task Overdue',          'Send contract — AutoPlus is 2 days overdue.',           0, ago(1)],
        [$uid, 'task_due',      'Task Overdue',          'WhatsApp follow-up — Laundry Express is overdue.',      0, ago(1)],
        [$uid, 'deal_update',   'Deal Updated',          'FastLogix Warehouse Doors moved to Negotiation.',       0, ago(2)],
        [$uid, 'new_contact',   'New Contact',           'Amara Osei submitted a form from your website.',        1, ago(2)],
        [$uid, 'task_due',      'Task Due Today',        'Follow up on Skyline proposal is due today.',           0, ago(0)],
        [$uid, 'deal_update',   'Deal Won',              'SolarSave Panel Installation marked as closed won!',    1, ago(20)],
    ];
    foreach ($notifs as $n) {
        $ins->execute($n);
    }
    echo "  Created notifications.\n";
} else {
    echo "  Notifications table not found — skipping.\n";
}

echo "\nDemo data seeded successfully.\n";
echo "Contacts: " . count($contact_ids) . "\n";
echo "Deals: " . count($deal_ids) . "\n";
echo "Tasks: " . count($task_ids) . "\n";
