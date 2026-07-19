<?php

namespace CRM\Services;

use CRM\Authorization;

class WorkspaceCommunicationGateService
{
    public const SETUP_SKILL_KEY = WorkspaceSkillCatalogService::PLUGIN_EMAIL;
    public const SETUP_URL = 'workspace_skills.php?module=' . WorkspaceSkillCatalogService::PLUGIN_EMAIL . '&setup_required=communication';
    public const WHATSAPP_SETUP_URL = 'workspace_skills.php?module=' . WorkspaceSkillCatalogService::PLUGIN_WHATSAPP . '&setup_required=communication#setup';

    public function status(int $workspaceId, ?array $user = null): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId > 0 && (new DemoWorkspaceService())->isDemoWorkspace($workspaceId)) {
            $activeDemoSession = (new DemoSessionScopeService())->activeSession($workspaceId);
            if ($activeDemoSession !== null) {
                $livePresentation = !empty($activeDemoSession['presentation_pitch'])
                    && !empty($activeDemoSession['live_presentation'])
                    && !empty($activeDemoSession['live_armed_at']);
                $runtime = $livePresentation ? 'live_presentation' : 'simulated';
                $message = $livePresentation
                    ? 'Presentation communication runtime is armed for approved live recipients.'
                    : 'Demo communication runtime is simulated and ready.';
                $channels = [
                    'main_email' => [
                        'status' => 'ready',
                        'runtime' => $runtime,
                        'label' => $livePresentation ? 'Presentation Email' : 'Demo Email',
                        'message' => $livePresentation ? 'Email can be sent only to approved presentation recipients.' : 'Demo email is simulated inside this sandbox.',
                    ],
                    'outreach_email' => [
                        'status' => 'ready',
                        'runtime' => $runtime,
                        'label' => $livePresentation ? 'Presentation Email' : 'Demo Email',
                        'message' => $livePresentation ? 'Outbound email is gated by the presentation allowlist.' : 'Demo outbound email is simulated inside this sandbox.',
                    ],
                    'whatsapp' => [
                        'status' => 'ready',
                        'runtime' => $runtime,
                        'label' => $livePresentation ? 'Presentation WhatsApp' : 'Demo WhatsApp',
                        'inbound_ready' => true,
                        'outbound_ready' => true,
                        'message' => $livePresentation ? 'WhatsApp can be sent only to approved presentation recipients.' : 'Demo WhatsApp is simulated inside this sandbox.',
                    ],
                ];

                return [
                    'ready' => true,
                    'locked' => false,
                    'can_manage' => false,
                    'setup_skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                    'setup_url' => self::SETUP_URL,
                    'message' => $message,
                    'owner_message' => $message,
                    'channels' => $channels,
                    'email_ready' => true,
                    'main_email_ready' => true,
                    'outreach_email_ready' => true,
                    'nurture_email_ready' => false,
                    'assistant_email_ready' => false,
                    'whatsapp_ready' => true,
                ];
            }
        }

        $connectService = new WorkspaceConnectService();
        $canManage = $workspaceId > 0 && $connectService->canManageConnections($user, $workspaceId);

        try {
            $channels = (new WorkspaceChannelHealthService(null, $connectService))->summarize($workspaceId, $user);
        } catch (\Throwable $e) {
            $channels = [];
        }

        $mainEmail = (array) ($channels['main_email'] ?? []);
        $outreachEmail = (array) ($channels['outreach_email'] ?? []);
        $nurtureEmail = (array) ($channels['nurture_email'] ?? []);
        $assistantEmail = (array) ($channels['assistant_email'] ?? []);
        $whatsapp = (array) ($channels['whatsapp'] ?? []);

        $mainEmailReady = $this->emailRuntimeReady($mainEmail);
        $outreachEmailReady = $this->emailRuntimeReady($outreachEmail);
        $nurtureEmailReady = $this->emailRuntimeReady($nurtureEmail);
        $assistantEmailReady = $this->emailRuntimeReady($assistantEmail);
        $whatsappReady = $this->whatsappRuntimeReady($whatsapp);
        $customerEmailReady = $outreachEmailReady || $nurtureEmailReady;
        $ready = $customerEmailReady || $whatsappReady;

        return [
            'ready' => $ready,
            'locked' => !$ready,
            'can_manage' => $canManage,
            'setup_skill_key' => WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            'setup_url' => self::SETUP_URL,
            'message' => $ready
                ? 'Communication runtime is ready.'
                : 'Set up Email or WhatsApp in Marketplace before using Inbox and sending tools.',
            'owner_message' => 'Ask the workspace owner to connect Email or WhatsApp before using Inbox and sending tools.',
            'channels' => $channels,
            'email_ready' => $customerEmailReady,
            'main_email_ready' => $mainEmailReady,
            'outreach_email_ready' => $outreachEmailReady,
            'nurture_email_ready' => $nurtureEmailReady,
            'assistant_email_ready' => $assistantEmailReady,
            'whatsapp_ready' => $whatsappReady,
        ];
    }

    public function isRuntimeReady(int $workspaceId, ?array $user = null): bool
    {
        return !empty($this->status($workspaceId, $user)['ready']);
    }

    public function channelStatus(int $workspaceId, string $channel, ?array $user = null): array
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            throw new \InvalidArgumentException('Unsupported communication channel: ' . $channel);
        }

        $overall = $this->status($workspaceId, $user);
        $skillKey = $channel === 'whatsapp'
            ? WorkspaceSkillCatalogService::PLUGIN_WHATSAPP
            : WorkspaceSkillCatalogService::PLUGIN_EMAIL;
        $setupUrl = $channel === 'whatsapp' ? self::WHATSAPP_SETUP_URL : self::SETUP_URL;
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $globallyActive = !$catalog->isGloballyDeactivated($skillKey);
        $channelHealth = $channel === 'whatsapp'
            ? (array) ($overall['channels']['whatsapp'] ?? [])
            : [
                'outreach_email' => (array) ($overall['channels']['outreach_email'] ?? []),
                'nurture_email' => (array) ($overall['channels']['nurture_email'] ?? []),
            ];
        $syntheticRuntime = $channel === 'whatsapp'
            && in_array((string) ($channelHealth['runtime'] ?? ''), ['simulated', 'live_presentation'], true);
        $installed = $syntheticRuntime || ($workspaceId > 0 && $installer->isInstalled($workspaceId, $skillKey));
        $channelReady = $channel === 'whatsapp'
            ? !empty($overall['whatsapp_ready'])
            : !empty($overall['email_ready']);
        $ready = $globallyActive && $installed && $channelReady;
        $canManage = !empty($overall['can_manage'])
            || Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.manage', $user);

        $readiness = [];
        if ($workspaceId > 0 && !$syntheticRuntime) {
            try {
                $readiness = $installer->buildReadinessForModule(
                    $workspaceId,
                    (int) (($user ?? [])['id'] ?? 0),
                    $skillKey
                );
            } catch (\Throwable $e) {
                $readiness = [];
            }
        }

        if (!$globallyActive) {
            $reasonCode = $channel . '_disabled';
            $message = ucfirst($channel) . ' is currently disabled.';
        } elseif (!$installed) {
            $reasonCode = $channel . '_not_installed';
            $message = 'Install ' . ucfirst($channel) . ' before using customer ' . ucfirst($channel) . ' messaging.';
        } elseif (!$channelReady) {
            $reasonCode = $channel . '_setup_required';
            $message = $channel === 'whatsapp'
                ? 'Complete WhatsApp Business channel setup before sending or receiving customer WhatsApp messages.'
                : 'Complete Outreach or Nurture Email setup before using customer email.';
        } else {
            $reasonCode = null;
            $message = ucfirst($channel) . ' customer messaging is ready.';
        }

        return [
            'channel' => $channel,
            'skill_key' => $skillKey,
            'installed' => $installed,
            'globally_active' => $globallyActive,
            'ready' => $ready,
            'outbound_ready' => $ready && ($channel !== 'whatsapp' || !empty($channelHealth['outbound_ready']) || (string) ($channelHealth['status'] ?? '') === 'ready'),
            'inbound_ready' => $ready && ($channel !== 'whatsapp' || !empty($channelHealth['inbound_ready']) || (string) ($channelHealth['status'] ?? '') === 'ready'),
            'can_manage' => $canManage,
            'reason_code' => $reasonCode,
            'message' => $message,
            'owner_message' => $ready || $canManage
                ? $message
                : 'Ask the workspace owner to complete ' . ucfirst($channel) . ' setup before using this channel.',
            'setup_url' => $setupUrl,
            'health' => $channelHealth,
            'readiness' => $readiness,
            'blockers' => array_values((array) ($readiness['blockers'] ?? [])),
            'next_action' => (string) ($readiness['next_action'] ?? ''),
        ];
    }

    public function isChannelRuntimeReady(int $workspaceId, string $channel, ?array $user = null): bool
    {
        return !empty($this->channelStatus($workspaceId, $channel, $user)['ready']);
    }

    public function jsonChannelBlockPayload(int $workspaceId, string $channel, ?array $user = null): array
    {
        $status = $this->channelStatus($workspaceId, $channel, $user);

        return [
            'success' => false,
            'error' => !empty($status['can_manage']) ? (string) $status['message'] : (string) $status['owner_message'],
            'error_code' => (string) ($status['reason_code'] ?? ($channel . '_setup_required')),
            'channel' => (string) ($status['channel'] ?? $channel),
            'skill_key' => (string) ($status['skill_key'] ?? ''),
            'setup_required' => true,
            'setup_url' => (string) ($status['setup_url'] ?? self::SETUP_URL),
            'installed' => !empty($status['installed']),
            'ready' => !empty($status['ready']),
            'blockers' => array_values((array) ($status['blockers'] ?? [])),
        ];
    }

    public function enforceWebChannelRuntime(int $workspaceId, string $channel, ?array $user = null): void
    {
        $status = $this->channelStatus($workspaceId, $channel, $user);
        if (!empty($status['ready'])) {
            return;
        }

        if (!empty($status['can_manage'])) {
            $target = $this->publicUrl((string) ($status['setup_url'] ?? self::SETUP_URL));
            header('Location: ' . $target);
            exit;
        }

        http_response_code(403);
        $product = function_exists('brandProductName') ? brandProductName() : 'CRM';
        $message = htmlspecialchars((string) ($status['owner_message'] ?? 'Workspace communication setup is required.'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Communication setup required - ' . htmlspecialchars($product, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem}.box{max-width:620px;margin:10vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}p{color:#475569;line-height:1.55}</style>';
        echo '</head><body><main class="box"><h1>Communication setup required</h1><p>' . $message . '</p></main></body></html>';
        exit;
    }

    public function enforceWebRuntime(int $workspaceId, ?array $user = null): void
    {
        $status = $this->status($workspaceId, $user);
        if (!empty($status['ready'])) {
            return;
        }

        if (!empty($status['can_manage'])) {
            $target = $this->publicUrl((string) ($status['setup_url'] ?? self::SETUP_URL));
            header('Location: ' . $target);
            exit;
        }

        http_response_code(403);
        $product = function_exists('brandProductName') ? brandProductName() : 'CRM';
        $message = htmlspecialchars((string) ($status['owner_message'] ?? 'Workspace communication setup is required.'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Communication setup required - ' . htmlspecialchars($product, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem}.box{max-width:620px;margin:10vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}p{color:#475569;line-height:1.55}</style>';
        echo '</head><body><main class="box"><h1>Communication setup required</h1><p>' . $message . '</p></main></body></html>';
        exit;
    }

    public function jsonBlockPayload(int $workspaceId, ?array $user = null): array
    {
        $status = $this->status($workspaceId, $user);

        return [
            'success' => false,
            'error' => !empty($status['can_manage']) ? (string) $status['message'] : (string) $status['owner_message'],
            'error_code' => 'communication_setup_required',
            'setup_required' => true,
            'setup_url' => (string) ($status['setup_url'] ?? self::SETUP_URL),
            'channels' => (array) ($status['channels'] ?? []),
        ];
    }

    private function emailRuntimeReady(array $health): bool
    {
        return (string) ($health['status'] ?? '') === 'ready';
    }

    private function whatsappRuntimeReady(array $health): bool
    {
        return (string) ($health['status'] ?? '') === 'ready';
    }

    private function publicUrl(string $path): string
    {
        return function_exists('publicUrl') ? publicUrl($path) : $path;
    }
}
