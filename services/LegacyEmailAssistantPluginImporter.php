<?php

namespace CRM\Services;

use CRM\Database;

class LegacyEmailAssistantPluginImporter
{
    public function importDefaultWorkspace(): array
    {
        $workspaceId = (new DefaultWorkspaceService())->id();
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Default workspace is unavailable.');
        }

        $configService = new WorkspaceAssistantConfigService();
        $existing = $configService->get($workspaceId, 'email', true);
        $installer = new WorkspaceSkillInstallService();
        $actorUserId = $this->resolveActorUserId($workspaceId);

        if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL)) {
            throw new \RuntimeException('Install and configure the Email plugin before importing Email Assistant.');
        }

        $installedNow = false;
        if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
            $installer->install($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $actorUserId, [
                'source' => 'legacy_email_assistant_import',
            ]);
            $installedNow = true;
        }

        if ($existing !== []) {
            return [
                'workspace_id' => $workspaceId,
                'installed' => true,
                'installed_now' => $installedNow,
                'imported' => false,
                'reason' => 'workspace_config_exists',
            ];
        }

        $settings = $this->legacySettings();
        if (!$this->hasLegacyConfiguration($settings)) {
            return [
                'workspace_id' => $workspaceId,
                'installed' => true,
                'installed_now' => $installedNow,
                'imported' => false,
                'reason' => 'legacy_config_missing',
            ];
        }

        $settings['legacy_imported_at'] = gmdate('c');
        $settings['legacy_import_source'] = 'environment';
        $configService->save(
            $workspaceId,
            'email',
            $settings,
            $this->envBool('EMAIL_ASSISTANT_ENABLED'),
            $actorUserId
        );

        return [
            'workspace_id' => $workspaceId,
            'installed' => true,
            'installed_now' => $installedNow,
            'imported' => true,
            'reason' => 'ok',
            'digest_enabled' => !empty($settings['digest_enabled']),
            'smtp_configured' => $settings['smtp_host'] !== '' && $settings['smtp_username'] !== '' && $settings['smtp_password'] !== '',
            'imap_configured' => !empty($settings['imap_enabled']) && $settings['imap_host'] !== '' && $settings['imap_username'] !== '' && $settings['imap_password'] !== '',
        ];
    }

    private function legacySettings(): array
    {
        return [
            'system_email' => $this->env('EMAIL_ASSISTANT_SYSTEM_EMAIL'),
            'from_email' => $this->env('EMAIL_ASSISTANT_FROM_EMAIL'),
            'from_name' => $this->env('EMAIL_ASSISTANT_FROM_NAME'),
            'smtp_host' => $this->env('EMAIL_ASSISTANT_SMTP_HOST'),
            'smtp_port' => $this->env('EMAIL_ASSISTANT_SMTP_PORT', '587'),
            'smtp_username' => $this->env('EMAIL_ASSISTANT_SMTP_USER'),
            'smtp_password' => $this->env('EMAIL_ASSISTANT_SMTP_PASS'),
            'smtp_encryption' => $this->env('EMAIL_ASSISTANT_SMTP_ENCRYPTION', 'tls'),
            'imap_enabled' => $this->envBool('EMAIL_ASSISTANT_IMAP_ENABLED'),
            'imap_host' => $this->env('EMAIL_ASSISTANT_IMAP_HOST'),
            'imap_port' => $this->env('EMAIL_ASSISTANT_IMAP_PORT', '993'),
            'imap_protocol' => $this->env('EMAIL_ASSISTANT_IMAP_PROTOCOL', 'imap'),
            'imap_encryption' => $this->env('EMAIL_ASSISTANT_IMAP_ENCRYPTION', 'ssl'),
            'imap_username' => $this->env('EMAIL_ASSISTANT_IMAP_USER'),
            'imap_password' => $this->env('EMAIL_ASSISTANT_IMAP_PASS'),
            'imap_folder' => $this->env('EMAIL_ASSISTANT_IMAP_FOLDER', 'INBOX'),
            'allowed_senders' => $this->env('EMAIL_ASSISTANT_ALLOWED_SENDERS'),
            'qa_enabled' => $this->envBool('EMAIL_ASSISTANT_Q&A_ENABLED'),
            'instructions_enabled' => $this->envBool('EMAIL_ASSISTANT_INSTRUCTIONS_ENABLED'),
            'customer_thread_enabled' => $this->envBool('EMAIL_ASSISTANT_CUSTOMER_THREAD_ENABLED'),
            'customer_send_enabled' => $this->envBool('EMAIL_ASSISTANT_CUSTOMER_SEND_ENABLED'),
            'default_tone' => $this->env('EMAIL_ASSISTANT_DEFAULT_TONE', 'professional'),
            'thread_context_window' => $this->env('EMAIL_ASSISTANT_THREAD_CONTEXT_WINDOW', '8'),
            'min_confidence' => $this->env('EMAIL_ASSISTANT_MIN_CONFIDENCE', '0.65'),
            'min_send_confidence' => $this->env('EMAIL_ASSISTANT_MIN_SEND_CONFIDENCE', '0.8'),
            'allow_clarifying_questions' => $this->envBool('EMAIL_ASSISTANT_ALLOW_CLARIFYING_QUESTIONS'),
            'activity_logging' => $this->envBool('EMAIL_ASSISTANT_ACTIVITY_LOGGING'),
            'skill_create_contact' => $this->envBool('EMAIL_ASSISTANT_SKILL_CREATE_CONTACT'),
            'skill_update_contact' => $this->envBool('EMAIL_ASSISTANT_SKILL_UPDATE_CONTACT'),
            'skill_delete_contact' => $this->envBool('EMAIL_ASSISTANT_SKILL_DELETE_CONTACT'),
            'skill_enrich_contact' => $this->envBool('EMAIL_ASSISTANT_SKILL_ENRICH_CONTACT'),
            'skill_verify_email' => $this->envBool('EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL'),
            'skill_add_note' => $this->envBool('EMAIL_ASSISTANT_SKILL_ADD_NOTE'),
            'skill_get_pipeline' => $this->envBool('EMAIL_ASSISTANT_SKILL_GET_PIPELINE'),
            'skill_list_tasks' => $this->envBool('EMAIL_ASSISTANT_SKILL_LIST_TASKS'),
            'skill_schedule_event' => $this->envBool('EMAIL_ASSISTANT_SKILL_SCHEDULE_EVENT'),
            'skill_run_report' => $this->envBool('EMAIL_ASSISTANT_SKILL_RUN_REPORT'),
            'digest_enabled' => $this->envBool('EMAIL_DIGEST_ENABLED'),
            'digest_time' => $this->env('EMAIL_DIGEST_TIME', '07:00'),
            'digest_recipients' => $this->env('EMAIL_DIGEST_RECIPIENTS', 'admins'),
        ];
    }

    private function hasLegacyConfiguration(array $settings): bool
    {
        return trim((string) ($settings['system_email'] ?? '')) !== ''
            || trim((string) ($settings['smtp_host'] ?? '')) !== ''
            || trim((string) ($settings['imap_host'] ?? '')) !== '';
    }

    private function resolveActorUserId(int $workspaceId): int
    {
        $row = Database::queryOne(
            "SELECT wm.user_id
             FROM workspace_memberships wm
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND wm.role_slug IN ('owner', 'superadmin', 'admin')
             ORDER BY FIELD(wm.role_slug, 'owner', 'superadmin', 'admin'), wm.user_id
             LIMIT 1",
            [$workspaceId]
        );
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('No active workspace owner or administrator can own the import.');
        }
        return $userId;
    }

    private function env(string $key, string $default = ''): string
    {
        return trim((string) ($_ENV[$key] ?? getenv($key) ?: $default));
    }

    private function envBool(string $key): bool
    {
        return filter_var($this->env($key, 'false'), FILTER_VALIDATE_BOOL);
    }
}
