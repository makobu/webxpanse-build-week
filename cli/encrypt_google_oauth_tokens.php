<?php
/**
 * Encrypt legacy plaintext Google OAuth tokens in-place.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

\CRM\Database::init(require __DIR__ . '/../config/database.php');

$vault = new \CRM\Services\OAuthTokenVault();
$updated = [
    'email_integrations' => 0,
    'calendar_integrations' => 0,
];

if (\CRM\Database::tableExists('email_integrations')) {
    $rows = \CRM\Database::query(
        "SELECT id, workspace_id, access_token, refresh_token
         FROM email_integrations
         WHERE provider IN ('gmail_oauth', 'google_workspace')
           AND (token_encrypted = 0 OR token_encrypted IS NULL)"
    );
    foreach ($rows as $row) {
        \CRM\Database::execute(
            "UPDATE email_integrations
             SET access_token = ?,
                 refresh_token = ?,
                 token_encrypted = 1,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $vault->encrypt($vault->decrypt($row['access_token'] ?? null)),
                $vault->encrypt($vault->decrypt($row['refresh_token'] ?? null)),
                (int) $row['id'],
                (int) $row['workspace_id'],
            ]
        );
        $updated['email_integrations']++;
    }
}

if (\CRM\Database::tableExists('calendar_integrations')) {
    $rows = \CRM\Database::query(
        "SELECT id, workspace_id, access_token, refresh_token
         FROM calendar_integrations
         WHERE provider = 'google'
           AND (token_encrypted = 0 OR token_encrypted IS NULL)"
    );
    foreach ($rows as $row) {
        \CRM\Database::execute(
            "UPDATE calendar_integrations
             SET access_token = ?,
                 refresh_token = ?,
                 token_encrypted = 1,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $vault->encrypt($vault->decrypt($row['access_token'] ?? null)),
                $vault->encrypt($vault->decrypt($row['refresh_token'] ?? null)),
                (int) $row['id'],
                (int) $row['workspace_id'],
            ]
        );
        $updated['calendar_integrations']++;
    }
}

echo json_encode(['encrypted' => $updated], JSON_PRETTY_PRINT) . PHP_EOL;
