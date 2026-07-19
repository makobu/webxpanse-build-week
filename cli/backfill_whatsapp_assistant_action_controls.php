<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
use CRM\Services\AssistantActionRuntimeConfig;
use CRM\Services\EmailAssistantRuntimeConfig;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../config/database.php');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$configService = new WorkspaceAssistantConfigService();
if (!$configService->isAvailable()) {
    fwrite(STDERR, "workspace_assistant_configs is missing. Run migrations first.\n");
    exit(1);
}

$legacyEnvKeys = [
    'skill_create_contact' => 'EMAIL_ASSISTANT_SKILL_CREATE_CONTACT',
    'skill_update_contact' => 'EMAIL_ASSISTANT_SKILL_UPDATE_CONTACT',
    'skill_delete_contact' => 'EMAIL_ASSISTANT_SKILL_DELETE_CONTACT',
    'skill_enrich_contact' => 'EMAIL_ASSISTANT_SKILL_ENRICH_CONTACT',
    'skill_verify_email' => 'EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL',
    'skill_add_note' => 'EMAIL_ASSISTANT_SKILL_ADD_NOTE',
    'skill_get_pipeline' => 'EMAIL_ASSISTANT_SKILL_GET_PIPELINE',
    'skill_list_tasks' => 'EMAIL_ASSISTANT_SKILL_LIST_TASKS',
    'skill_schedule_event' => 'EMAIL_ASSISTANT_SKILL_SCHEDULE_EVENT',
    'skill_run_report' => 'EMAIL_ASSISTANT_SKILL_RUN_REPORT',
    'skill_create_invoice' => 'EMAIL_ASSISTANT_SKILL_CREATE_INVOICE',
    'skill_update_invoice' => 'EMAIL_ASSISTANT_SKILL_UPDATE_INVOICE',
    'skill_list_invoices' => 'EMAIL_ASSISTANT_SKILL_LIST_INVOICES',
    'skill_send_invoice' => 'EMAIL_ASSISTANT_SKILL_SEND_INVOICE',
    'skill_finalize_invoice' => 'EMAIL_ASSISTANT_SKILL_FINALIZE_INVOICE',
    'skill_mark_invoice_paid' => 'EMAIL_ASSISTANT_SKILL_MARK_INVOICE_PAID',
    'skill_convert_invoice' => 'EMAIL_ASSISTANT_SKILL_CONVERT_INVOICE',
];
$defaults = [
    'qa_enabled' => false,
    'instructions_enabled' => false,
    'customer_thread_enabled' => true,
    'customer_send_enabled' => true,
    'skill_create_contact' => false,
    'skill_update_contact' => false,
    'skill_delete_contact' => false,
    'skill_enrich_contact' => false,
    'skill_verify_email' => false,
    'skill_add_note' => false,
    'skill_get_pipeline' => false,
    'skill_list_tasks' => false,
    'skill_schedule_event' => false,
    'skill_run_report' => false,
    'skill_create_invoice' => true,
    'skill_update_invoice' => true,
    'skill_list_invoices' => true,
    'skill_send_invoice' => true,
    'skill_finalize_invoice' => true,
    'skill_mark_invoice_paid' => true,
    'skill_convert_invoice' => true,
];

$rows = Database::query(
    "SELECT DISTINCT w.id AS workspace_id
     FROM workspaces w
     LEFT JOIN workspace_skill_installs i
        ON i.workspace_id = w.id
       AND i.skill_key = ?
       AND i.status = 'installed'
     LEFT JOIN workspace_assistant_configs wc
        ON wc.workspace_id = w.id
       AND wc.assistant_type = 'whatsapp'
     WHERE COALESCE(w.status, 'active') <> 'archived'
       AND (i.id IS NOT NULL OR wc.id IS NOT NULL)
     ORDER BY w.id ASC",
    [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT]
);

$emailRuntimeConfig = new EmailAssistantRuntimeConfig('email');
$seeded = 0;
$unchanged = 0;

foreach ($rows as $row) {
    $workspaceId = (int) ($row['workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        continue;
    }

    WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    $whatsAppConfig = $configService->get($workspaceId, 'whatsapp', true);
    $whatsAppSettings = (array) ($whatsAppConfig['settings'] ?? []);
    $emailConfig = $configService->get($workspaceId, 'email', false);
    $emailSettings = (array) ($emailConfig['settings'] ?? []);
    $emailRuntime = $emailRuntimeConfig->forWorkspace($workspaceId);

    $changed = false;
    foreach (AssistantActionRuntimeConfig::ACTION_SETTING_KEYS as $key) {
        if (array_key_exists($key, $whatsAppSettings)) {
            continue;
        }

        if (array_key_exists($key, $emailSettings)) {
            $whatsAppSettings[$key] = !empty($emailSettings[$key]);
        } elseif (array_key_exists($key, $emailRuntime) && is_bool($emailRuntime[$key])) {
            $whatsAppSettings[$key] = (bool) $emailRuntime[$key];
        } else {
            $whatsAppSettings[$key] = $emailRuntimeConfig->actionEnabled(
                $emailRuntime,
                $key,
                (string) ($legacyEnvKeys[$key] ?? ''),
                (bool) ($defaults[$key] ?? false)
            );
        }
        $changed = true;
    }

    if (!$changed) {
        $unchanged++;
        continue;
    }

    if (!$dryRun) {
        $enabled = $whatsAppConfig !== []
            ? !empty($whatsAppConfig['enabled'])
            : filter_var($_ENV['WHATSAPP_ASSISTANT_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
        $configService->save($workspaceId, 'whatsapp', $whatsAppSettings, $enabled, 0);
    }
    $seeded++;
}

WorkspaceContext::clear();

echo ($dryRun ? 'Dry run: ' : '') . "WhatsApp Assistant action settings seeded for {$seeded} workspace(s); {$unchanged} already had settings.\n";
