<?php

require_once __DIR__ . '/../vendor/autoload.php';

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($value);
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['workspace::', 'send-test::']);
$workspaceId = max(1, (int) ($options['workspace'] ?? 1));
$testEmail = trim((string) ($options['send-test'] ?? ''));

try {
    $result = AsyncWorkspaceRunner::runWithWorkspace($workspaceId, static function () use ($workspaceId, $testEmail): array {
        $installer = new WorkspaceSkillInstallService();
        $config = (new WorkspaceAssistantConfigService())->get($workspaceId, 'email', false);
        $service = new EmailAssistantDigestService();
        $output = [
            'workspace_id' => $workspaceId,
            'installed' => $installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT),
            'enabled' => !empty($config['enabled']),
            'readiness' => $service->validateDigestConfig(),
            'recent_attempts' => $service->recentAttempts(5),
        ];

        if ($testEmail !== '') {
            $user = Database::queryOne(
                "SELECT u.id, u.email
                 FROM users u
                 JOIN workspace_memberships wm ON wm.user_id = u.id
                 WHERE wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                   AND LOWER(u.email) = LOWER(?)
                 LIMIT 1",
                [$workspaceId, $testEmail]
            );
            if (!$user) {
                throw new RuntimeException('Test recipient must be an active user in the selected workspace.');
            }
            $output['test_delivery'] = $service->sendDigestToUser((int) $user['id'], (string) $user['email'], true);
            $output['recent_attempts'] = $service->recentAttempts(5);
        }

        return $output;
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Email Assistant diagnostics failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
