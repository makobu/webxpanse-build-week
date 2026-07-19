<?php

namespace CRM\Services;

use CRM\Authorization;

class WorkspaceHRAnalyticsGateService
{
    public const SETUP_SKILL_KEY = WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP;
    public const SETUP_URL = 'organization_intelligence_setup.php';
    public const RUNTIME_URL = 'hr_analytics.php';

    public function status(int $workspaceId, ?array $user = null): array
    {
        $setup = (new WorkspaceHRAnalyticsSetupService())->status($workspaceId);
        $canManage = $workspaceId > 0 && $this->canManageSetup($user);
        $adminBypass = (new SuperAdminDefaultWorkspaceModuleAccessService())->canBypassModuleAccessGates($workspaceId, $user);
        $setupReady = !empty($setup['ready']);

        return array_merge($setup, [
            'setup_ready' => $setupReady,
            'runtime_ready' => $setupReady || $adminBypass,
            'locked' => !$setupReady && !$adminBypass,
            'admin_bypass' => $adminBypass,
            'admin_bypass_reason' => $adminBypass ? 'Superadmin default workspace access bypasses package and prerequisite gates.' : '',
            'can_manage' => $canManage,
            'setup_skill_key' => self::SETUP_SKILL_KEY,
            'setup_url' => self::SETUP_URL,
            'runtime_url' => self::RUNTIME_URL,
            'owner_message' => (string) ($setup['owner_message'] ?? 'Ask the workspace owner to complete Organization Intelligence setup before using the dashboard.'),
        ]);
    }

    public function isRuntimeReady(int $workspaceId, ?array $user = null): bool
    {
        return !empty($this->status($workspaceId, $user)['runtime_ready']);
    }

    public function enforceWebRuntime(int $workspaceId, ?array $user = null): void
    {
        $status = $this->status($workspaceId, $user);
        if (!empty($status['runtime_ready'])) {
            return;
        }

        if (!empty($status['can_manage'])) {
            header('Location: ' . $this->publicUrl($this->setupRequiredUrl()));
            exit;
        }

        http_response_code(403);
        $product = function_exists('brandProductName') ? brandProductName() : 'CRM';
        $message = htmlspecialchars((string) ($status['owner_message'] ?? 'Workspace Organization Intelligence setup is required.'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Organization Intelligence setup required - ' . htmlspecialchars($product, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem}.box{max-width:620px;margin:10vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}p{color:#475569;line-height:1.55}</style>';
        echo '</head><body><main class="box"><h1>Organization Intelligence setup required</h1><p>' . $message . '</p></main></body></html>';
        exit;
    }

    public function jsonBlockPayload(int $workspaceId, ?array $user = null): array
    {
        $status = $this->status($workspaceId, $user);

        return [
            'success' => false,
            'error' => !empty($status['can_manage']) ? (string) $status['message'] : (string) $status['owner_message'],
            'error_code' => 'hr_analytics_setup_required',
            'setup_required' => true,
            'setup_url' => (string) ($status['setup_url'] ?? self::SETUP_URL),
            'setup_required_url' => $this->setupRequiredUrl(),
            'checks' => (array) ($status['checks'] ?? []),
            'blockers' => (array) ($status['blockers'] ?? []),
            'setup_ready' => !empty($status['setup_ready']),
            'runtime_ready' => !empty($status['runtime_ready']),
            'admin_bypass' => !empty($status['admin_bypass']),
        ];
    }

    public function setupRequiredUrl(): string
    {
        return self::SETUP_URL . '?setup_required=hr_analytics';
    }

    private function canManageSetup(?array $user): bool
    {
        return Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.manage', $user)
            || Authorization::can('hr.analytics.settings', $user)
            || Authorization::can('org.departments.manage', $user)
            || Authorization::can('admin.users.manage', $user);
    }

    private function publicUrl(string $path): string
    {
        return function_exists('publicUrl') ? publicUrl($path) : $path;
    }
}
