<?php

namespace CRM\Services;

class AssistantActionRuntimeConfig
{
    public const ASSISTANT_EMAIL = 'email';
    public const ASSISTANT_WHATSAPP = 'whatsapp';

    public const ACTION_SETTING_KEYS = [
        'qa_enabled',
        'instructions_enabled',
        'customer_thread_enabled',
        'customer_send_enabled',
        'skill_create_contact',
        'skill_update_contact',
        'skill_delete_contact',
        'skill_enrich_contact',
        'skill_verify_email',
        'skill_add_note',
        'skill_get_pipeline',
        'skill_list_tasks',
        'skill_schedule_event',
        'skill_run_report',
        'skill_create_invoice',
        'skill_update_invoice',
        'skill_list_invoices',
        'skill_send_invoice',
        'skill_finalize_invoice',
        'skill_mark_invoice_paid',
        'skill_convert_invoice',
    ];

    private const DEFAULTS = [
        self::ASSISTANT_EMAIL => [
            'enabled' => true,
            'qa_enabled' => false,
            'instructions_enabled' => false,
            'customer_thread_enabled' => true,
            'customer_send_enabled' => true,
            'min_confidence' => 0.65,
            'min_send_confidence' => 0.8,
        ],
        self::ASSISTANT_WHATSAPP => [
            'enabled' => true,
            'qa_enabled' => false,
            'instructions_enabled' => false,
            'customer_thread_enabled' => true,
            'customer_send_enabled' => true,
            'min_confidence' => 0.65,
            'min_send_confidence' => 0.8,
        ],
    ];

    private const ENV_KEYS = [
        self::ASSISTANT_EMAIL => [
            'enabled' => 'EMAIL_ASSISTANT_ENABLED',
            'qa_enabled' => 'EMAIL_ASSISTANT_Q&A_ENABLED',
            'instructions_enabled' => 'EMAIL_ASSISTANT_INSTRUCTIONS_ENABLED',
            'customer_thread_enabled' => 'EMAIL_ASSISTANT_CUSTOMER_THREAD_ENABLED',
            'customer_send_enabled' => 'EMAIL_ASSISTANT_CUSTOMER_SEND_ENABLED',
            'allowed_senders' => 'EMAIL_ASSISTANT_ALLOWED_SENDERS',
            'min_confidence' => 'EMAIL_ASSISTANT_MIN_CONFIDENCE',
            'min_send_confidence' => 'EMAIL_ASSISTANT_MIN_SEND_CONFIDENCE',
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
        ],
        self::ASSISTANT_WHATSAPP => [
            'enabled' => 'WHATSAPP_ASSISTANT_ENABLED',
            'qa_enabled' => 'WHATSAPP_ASSISTANT_QA_ENABLED',
            'instructions_enabled' => 'WHATSAPP_ASSISTANT_INSTRUCTIONS_ENABLED',
            'customer_thread_enabled' => 'WHATSAPP_ASSISTANT_CUSTOMER_THREAD_ENABLED',
            'customer_send_enabled' => 'WHATSAPP_ASSISTANT_CUSTOMER_SEND_ENABLED',
            'allowed_senders' => 'WHATSAPP_ASSISTANT_ALLOWED_SENDERS',
            'min_confidence' => 'WHATSAPP_ASSISTANT_MIN_CONFIDENCE',
            'min_send_confidence' => 'WHATSAPP_ASSISTANT_MIN_SEND_CONFIDENCE',
            'skill_create_contact' => 'WHATSAPP_ASSISTANT_SKILL_CREATE_CONTACT',
            'skill_update_contact' => 'WHATSAPP_ASSISTANT_SKILL_UPDATE_CONTACT',
            'skill_delete_contact' => 'WHATSAPP_ASSISTANT_SKILL_DELETE_CONTACT',
            'skill_enrich_contact' => 'WHATSAPP_ASSISTANT_SKILL_ENRICH_CONTACT',
            'skill_verify_email' => 'WHATSAPP_ASSISTANT_SKILL_VERIFY_EMAIL',
            'skill_add_note' => 'WHATSAPP_ASSISTANT_SKILL_ADD_NOTE',
            'skill_get_pipeline' => 'WHATSAPP_ASSISTANT_SKILL_GET_PIPELINE',
            'skill_list_tasks' => 'WHATSAPP_ASSISTANT_SKILL_LIST_TASKS',
            'skill_schedule_event' => 'WHATSAPP_ASSISTANT_SKILL_SCHEDULE_EVENT',
            'skill_run_report' => 'WHATSAPP_ASSISTANT_SKILL_RUN_REPORT',
            'skill_create_invoice' => 'WHATSAPP_ASSISTANT_SKILL_CREATE_INVOICE',
            'skill_update_invoice' => 'WHATSAPP_ASSISTANT_SKILL_UPDATE_INVOICE',
            'skill_list_invoices' => 'WHATSAPP_ASSISTANT_SKILL_LIST_INVOICES',
            'skill_send_invoice' => 'WHATSAPP_ASSISTANT_SKILL_SEND_INVOICE',
            'skill_finalize_invoice' => 'WHATSAPP_ASSISTANT_SKILL_FINALIZE_INVOICE',
            'skill_mark_invoice_paid' => 'WHATSAPP_ASSISTANT_SKILL_MARK_INVOICE_PAID',
            'skill_convert_invoice' => 'WHATSAPP_ASSISTANT_SKILL_CONVERT_INVOICE',
        ],
    ];

    /**
     * @return array{
     *     assistant_type:string,
     *     has_workspace_config:bool,
     *     enabled:bool,
     *     qa_enabled:bool,
     *     instructions_enabled:bool,
     *     customer_thread_enabled:bool,
     *     customer_send_enabled:bool,
     *     allowed_senders:string[],
     *     min_confidence:float,
     *     min_send_confidence:float,
     *     settings:array<string,mixed>
     * }
     */
    public function forWorkspace(string $assistantType, ?int $workspaceId = null): array
    {
        $assistantType = self::normalizeType($assistantType);
        $workspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $config = [];
        if ($workspaceId > 0) {
            try {
                $config = (new WorkspaceAssistantConfigService())->get($workspaceId, $assistantType, false);
            } catch (\Throwable $e) {
                $config = [];
            }
        }

        $hasWorkspaceConfig = $config !== [];
        $settings = (array) ($config['settings'] ?? []);
        $pluginOnly = $assistantType === self::ASSISTANT_EMAIL;

        return [
            'assistant_type' => $assistantType,
            'has_workspace_config' => $hasWorkspaceConfig,
            'enabled' => $hasWorkspaceConfig
                ? !empty($config['enabled'])
                : ($pluginOnly ? false : $this->envBool($this->envKey($assistantType, 'enabled'), (bool) self::DEFAULTS[$assistantType]['enabled'])),
            'qa_enabled' => $this->boolSetting($assistantType, $settings, 'qa_enabled', $hasWorkspaceConfig || $pluginOnly),
            'instructions_enabled' => $this->boolSetting($assistantType, $settings, 'instructions_enabled', $hasWorkspaceConfig || $pluginOnly),
            'customer_thread_enabled' => $this->boolSetting($assistantType, $settings, 'customer_thread_enabled', $hasWorkspaceConfig || $pluginOnly),
            'customer_send_enabled' => $this->boolSetting($assistantType, $settings, 'customer_send_enabled', $hasWorkspaceConfig || $pluginOnly),
            'allowed_senders' => $this->parseAllowedSenders(
                $hasWorkspaceConfig
                    ? (string) ($settings['allowed_senders'] ?? '')
                    : ($pluginOnly ? '' : (string) ($_ENV[$this->envKey($assistantType, 'allowed_senders')] ?? ''))
            ),
            'min_confidence' => $this->floatSetting($assistantType, $settings, 'min_confidence', $hasWorkspaceConfig || $pluginOnly),
            'min_send_confidence' => $this->floatSetting($assistantType, $settings, 'min_send_confidence', $hasWorkspaceConfig || $pluginOnly),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $runtime
     */
    public function actionEnabled(array $runtime, string $settingKey, string $legacyEmailEnvKey, bool $default): bool
    {
        if (empty($runtime['enabled'])) {
            return false;
        }

        $settings = (array) ($runtime['settings'] ?? []);
        if (!empty($runtime['has_workspace_config'])) {
            return !empty($settings[$settingKey]);
        }

        $assistantType = self::normalizeType((string) ($runtime['assistant_type'] ?? self::ASSISTANT_EMAIL));
        if ($assistantType === self::ASSISTANT_EMAIL) {
            return false;
        }
        $envKey = $this->envKey($assistantType, $settingKey);
        if ($envKey === '') {
            $envKey = $assistantType === self::ASSISTANT_EMAIL
                ? $legacyEmailEnvKey
                : $this->deriveWhatsAppEnvKey($legacyEmailEnvKey);
        }

        return $this->envBool($envKey, $default);
    }

    public static function normalizeType(string $assistantType): string
    {
        return strtolower(trim($assistantType)) === self::ASSISTANT_WHATSAPP
            ? self::ASSISTANT_WHATSAPP
            : self::ASSISTANT_EMAIL;
    }

    public static function labelFor(string $assistantType): string
    {
        return self::normalizeType($assistantType) === self::ASSISTANT_WHATSAPP
            ? 'WhatsApp Assistant'
            : 'Email Assistant';
    }

    private function boolSetting(string $assistantType, array $settings, string $key, bool $hasWorkspaceConfig): bool
    {
        if ($hasWorkspaceConfig) {
            return !empty($settings[$key]);
        }

        return $this->envBool(
            $this->envKey($assistantType, $key),
            (bool) (self::DEFAULTS[$assistantType][$key] ?? false)
        );
    }

    private function floatSetting(string $assistantType, array $settings, string $key, bool $hasWorkspaceConfig): float
    {
        $value = $hasWorkspaceConfig
            ? ($settings[$key] ?? (self::DEFAULTS[$assistantType][$key] ?? 0.0))
            : ($_ENV[$this->envKey($assistantType, $key)] ?? (self::DEFAULTS[$assistantType][$key] ?? 0.0));

        return max(0.0, min(1.0, (float) $value));
    }

    private function envKey(string $assistantType, string $settingKey): string
    {
        return (string) (self::ENV_KEYS[$assistantType][$settingKey] ?? '');
    }

    private function deriveWhatsAppEnvKey(string $legacyEmailEnvKey): string
    {
        if (str_starts_with($legacyEmailEnvKey, 'EMAIL_ASSISTANT_')) {
            return 'WHATSAPP_ASSISTANT_' . str_replace('Q&A', 'QA', substr($legacyEmailEnvKey, strlen('EMAIL_ASSISTANT_')));
        }

        return $legacyEmailEnvKey;
    }

    private function envBool(string $key, bool $default): bool
    {
        if ($key === '' || !array_key_exists($key, $_ENV)) {
            return $default;
        }

        return filter_var($_ENV[$key], FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<int,string>
     */
    private function parseAllowedSenders(string $raw): array
    {
        $items = preg_split('/[\s,;]+/', strtolower(trim($raw)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }
}
