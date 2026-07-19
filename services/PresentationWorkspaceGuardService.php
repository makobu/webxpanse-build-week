<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class PresentationWorkspaceGuardService
{
    /**
     * @return array<string,mixed>
     */
    public function settings(?int $workspaceId = null): array
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }

        $row = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?: [];
        $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
        return is_array($settings) ? $settings : [];
    }

    public function isPresentationWorkspace(?int $workspaceId = null): bool
    {
        $settings = $this->settings($workspaceId);
        return !empty($settings['presentation_workspace']);
    }

    public function status(?int $workspaceId = null): string
    {
        $settings = $this->settings($workspaceId);
        return trim((string) ($settings['presentation_status'] ?? ''));
    }

    public function isArchivedOrExpired(?int $workspaceId = null): bool
    {
        return in_array($this->status($workspaceId), ['archived', 'expired'], true);
    }

    public function assertAllowed(string $capability, ?int $workspaceId = null): void
    {
        if (!$this->isBlocked($capability, $workspaceId)) {
            return;
        }

        throw new \RuntimeException($this->message($capability));
    }

    public function isBlocked(string $capability, ?int $workspaceId = null): bool
    {
        if (!$this->isPresentationWorkspace($workspaceId)) {
            return false;
        }

        $settings = $this->settings($workspaceId);
        return match ($capability) {
            'billing' => !empty($settings['billing_disabled']),
            'invites' => !empty($settings['invites_disabled']),
            'exports' => !empty($settings['exports_disabled']),
            'integrations' => !empty($settings['integration_changes_disabled']),
            'tenant_delete' => !empty($settings['tenant_delete_disabled']),
            'user_management' => !empty($settings['permanent_user_management_disabled']),
            default => false,
        };
    }

    public function message(string $capability): string
    {
        return match ($capability) {
            'billing' => 'This is a presentation workspace. Billing is disabled.',
            'invites' => 'This is a presentation workspace. Invites are disabled.',
            'exports' => 'This is a presentation workspace. Exports are disabled.',
            'integrations' => 'Live integrations cannot be changed during a presentation.',
            'tenant_delete' => 'This is a presentation workspace. Tenant deletion is disabled.',
            'user_management' => 'This is a presentation workspace. Permanent user management is disabled.',
            default => 'This action is disabled for presentation workspaces.',
        };
    }
}
