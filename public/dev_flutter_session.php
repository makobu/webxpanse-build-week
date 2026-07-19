<?php
// TEMP SCRIPT - localhost only
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    http_response_code(403); exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Services\MobileTokenAuthService;
Database::init(require __DIR__ . '/../config/database.php');

// Seed read notifications if requested
if (($_GET['seed'] ?? '') === '1') {
    $user = Database::queryOne("SELECT id FROM users WHERE role='admin' LIMIT 1");
    Database::execute("INSERT INTO notifications (user_id,type,title,message,severity,is_read,read_at) VALUES (?,?,?,?,?,1,NOW())",
        [(int)$user['id'], 'test', 'Read Notif A', 'Already read.', 'info']);
    Database::execute("INSERT INTO notifications (user_id,type,title,message,severity,is_read,read_at) VALUES (?,?,?,?,?,1,NOW())",
        [(int)$user['id'], 'test', 'Read Notif B', 'Also read.', 'info']);
    Database::execute("INSERT INTO notifications (user_id,type,title,message,severity,is_read) VALUES (?,?,?,?,?,0)",
        [(int)$user['id'], 'test', 'Unread Notif C', 'Still unread.', 'info']);
    header('Content-Type: application/json');
    echo json_encode(['seeded' => 3]);
    exit;
}

// Get admin user
$u = Database::queryOne("SELECT id,email,first_name,last_name,role FROM users WHERE role='admin' LIMIT 1");

// Issue token
$svc = new MobileTokenAuthService();
$pair = $svc->issueTokenPair($u, [
    'device_id' => 'dev-flutter-inject', 'device_name' => 'Dev Browser',
    'platform' => 'web', 'app_version' => '0.0.0',
]);

// Session JSON (matches AuthSession.toJson())
$session = [
    'access_token'      => $pair['access_token'],
    'refresh_token'     => $pair['refresh_token'],
    'token_type'        => 'Bearer',
    'expires_in'        => $pair['expires_in'],
    'refresh_expires_in'=> $pair['refresh_expires_in'],
    'device_id'         => 'dev-flutter-inject',
    'session_id'        => $pair['token_id'] ?? 0,
    'user' => [
        'id'         => (int)$u['id'],
        'uuid'       => null,
        'email'      => $u['email'],
        'first_name' => $u['first_name'],
        'last_name'  => $u['last_name'],
        'role'       => $u['role'],
        'permissions'=> [],
        'scopes'     => [
            'can_view_all_contacts'      => true,
            'can_view_all_conversations' => true,
            'can_view_all_analytics'     => true,
        ],
    ],
];

// Workspace JSON (matches WorkspaceProfile.toJson())
$baseUrl = 'http://localhost/crm';
$workspace = [
    'base_url'          => $baseUrl,
    'display_name'      => 'Clarity',
    'app_name'          => 'webXpanse',
    'tagline_primary'   => '',
    'tagline_secondary' => '',
    'positioning_line'  => '',
    'outcome_line'      => '',
    'accent_blue'       => '#2563eb',
    'midnight_black'    => '#1a1a1a',
    'charcoal_grey'     => '#4d4d4d',
    'logo_url'          => null,
    'release'           => ['min_supported_version'=>null,'latest_version'=>null,'upgrade_url'=>null,'force_upgrade'=>false],
];

header('Content-Type: application/json');
echo json_encode([
    'session'           => $session,
    'workspace'         => $workspace,
    'active_workspace'  => $baseUrl,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
