<?php

namespace CRM\Services;

class EmailAssistantRuntimeConfig
{
    private string $assistantType;
    private AssistantActionRuntimeConfig $resolver;

    public function __construct(string $assistantType = AssistantActionRuntimeConfig::ASSISTANT_EMAIL, ?AssistantActionRuntimeConfig $resolver = null)
    {
        $this->assistantType = AssistantActionRuntimeConfig::normalizeType($assistantType);
        $this->resolver = $resolver ?: new AssistantActionRuntimeConfig();
    }

    /**
     * @return array{
     *     has_workspace_config:bool,
     *     assistant_type:string,
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
    public function forWorkspace(?int $workspaceId = null): array
    {
        return $this->resolver->forWorkspace($this->assistantType, $workspaceId);
    }

    public function isSenderAllowed(string $fromEmail, ?int $workspaceId = null): bool
    {
        $fromEmail = strtolower(trim($fromEmail));
        if ($fromEmail === '') {
            return false;
        }

        $allowed = $this->forWorkspace($workspaceId)['allowed_senders'];
        if ($allowed === []) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($fromEmail, '@') ?: '', 1));
        foreach ($allowed as $entry) {
            if ($entry === $fromEmail) {
                return true;
            }
            $entryDomain = ltrim($entry, '@');
            if ($domain !== '' && $entryDomain !== '' && $entryDomain === $domain) {
                return true;
            }
        }

        return false;
    }

    public function instructionsEnabled(?int $workspaceId = null): bool
    {
        $config = $this->forWorkspace($workspaceId);
        return $config['enabled'] && $config['instructions_enabled'];
    }

    public function qaEnabled(?int $workspaceId = null): bool
    {
        $config = $this->forWorkspace($workspaceId);
        return $config['enabled'] && $config['qa_enabled'];
    }

    public function customerThreadEnabled(?int $workspaceId = null): bool
    {
        $config = $this->forWorkspace($workspaceId);
        return $config['enabled'] && $config['customer_thread_enabled'];
    }

    public function customerSendEnabled(?int $workspaceId = null): bool
    {
        $config = $this->forWorkspace($workspaceId);
        return $config['enabled'] && $config['customer_send_enabled'];
    }

    /**
     * @param array<string,mixed> $runtime
     */
    public function actionEnabled(array $runtime, string $settingKey, string $legacyEmailEnvKey, bool $default): bool
    {
        return $this->resolver->actionEnabled($runtime, $settingKey, $legacyEmailEnvKey, $default);
    }

    public function assistantType(): string
    {
        return $this->assistantType;
    }
}
