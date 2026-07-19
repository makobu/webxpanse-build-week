<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (($value !== '') && (
            (substr($value, 0, 1) === '"' && substr($value, -1) === '"')
            || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\UserPreferences;

Database::init(require __DIR__ . '/../config/database.php');

$oldExists = Database::queryOne("SHOW TABLES LIKE 'user_preferences'") !== false;
if (!$oldExists) {
    echo "user_preferences table not found." . PHP_EOL;
    exit(1);
}

$backupExists = Database::queryOne("SHOW TABLES LIKE 'user_preferences_backup_broken'") !== false;
if ($backupExists) {
    Database::execute("DROP TABLE user_preferences_backup_broken");
}

Database::execute("DROP TABLE IF EXISTS user_preferences_rebuilt");
Database::execute(
    "CREATE TABLE user_preferences_rebuilt (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        preference_key VARCHAR(100) NOT NULL,
        preference_value TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_user_preference (user_id, preference_key),
        KEY idx_user_preferences_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

Database::execute(
    "INSERT INTO user_preferences_rebuilt (user_id, preference_key, preference_value, created_at, updated_at)
     SELECT
         user_id,
         preference_key,
         SUBSTRING_INDEX(
             GROUP_CONCAT(preference_value ORDER BY updated_at DESC, created_at DESC SEPARATOR '\n'),
             '\n',
             1
         ) AS preference_value,
         MIN(created_at) AS created_at,
         MAX(updated_at) AS updated_at
     FROM user_preferences
     GROUP BY user_id, preference_key"
);

Database::execute("RENAME TABLE user_preferences TO user_preferences_backup_broken, user_preferences_rebuilt TO user_preferences");

$prefs = new UserPreferences();
$prefs->setAICoachEnabled(1, true);
$prefs->setAIGuidanceMode(1, '1');
$prefs->setPreference(1, 'ai_task_auto_sync_date', date('Y-m-d', strtotime('-1 day')));

$groupCount = Database::queryOne("SELECT COUNT(*) AS count FROM user_preferences_backup_broken")['count'] ?? 0;
$newCount = Database::queryOne("SELECT COUNT(*) AS count FROM user_preferences")['count'] ?? 0;

echo "Rebuilt user_preferences." . PHP_EOL;
echo "Old rows: " . (int) $groupCount . PHP_EOL;
echo "New rows: " . (int) $newCount . PHP_EOL;
echo "User 1 AI Coach enabled, Foundation mode set, and sync date rewound to yesterday." . PHP_EOL;
