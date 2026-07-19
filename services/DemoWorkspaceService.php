<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class DemoWorkspaceService
{
    public const DEMO_WORKSPACE_UUID = '00000000-0000-4000-8000-000000000461';
    public const DEMO_WORKSPACE_SLUG = 'protected-demo';
    public const METRODRIVE_WORKSPACE_UUID = '00000000-0000-4000-8000-000000000475';
    public const METRODRIVE_WORKSPACE_SLUG = 'metrodrive-demo';
    public const SESSION_TTL_HOURS = 24;
    public const PRESENTATION_SESSION_TTL_HOURS = 4;
    public const PRIVATE_PURGE_DAYS = 7;
    public const CONSENT_RETENTION_DAYS = 90;

    public function resolve(?string $slug = null): array
    {
        $slug = trim((string) ($slug ?? self::DEMO_WORKSPACE_SLUG));
        $uuid = $slug === self::METRODRIVE_WORKSPACE_SLUG ? self::METRODRIVE_WORKSPACE_UUID : self::DEMO_WORKSPACE_UUID;
        $workspace = Database::queryOne(
            "SELECT *
             FROM workspaces
             WHERE slug = ? OR uuid = ?
             ORDER BY id ASC
             LIMIT 1",
            [$slug, $uuid]
        );

        if (!$workspace) {
            throw new \RuntimeException('Protected demo workspace is missing. Run migrations.');
        }

        return $workspace;
    }

    public function id(?string $slug = null): int
    {
        return (int) ($this->resolve($slug)['id'] ?? 0);
    }

    public function metroDrive(): array
    {
        return $this->resolve(self::METRODRIVE_WORKSPACE_SLUG);
    }

    public function metroDriveId(): int
    {
        return $this->id(self::METRODRIVE_WORKSPACE_SLUG);
    }

    public function isDemoWorkspace(?int $workspaceId = null): bool
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return false;
        }

        try {
            $workspace = Database::queryOne(
                "SELECT id, slug, settings_json
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            );
            if (!$workspace) {
                return false;
            }

            $slug = (string) ($workspace['slug'] ?? '');
            if (in_array($slug, [self::DEMO_WORKSPACE_SLUG, self::METRODRIVE_WORKSPACE_SLUG], true)) {
                return true;
            }

            $settings = json_decode((string) ($workspace['settings_json'] ?? ''), true);
            return is_array($settings) && (!empty($settings['protected_demo_workspace']) || !empty($settings['demo_workspace']));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isPresentationDemoWorkspace(?int $workspaceId = null): bool
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return false;
        }

        try {
            $workspace = Database::queryOne(
                "SELECT slug, settings_json
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            );
            if (!$workspace) {
                return false;
            }

            if ((string) ($workspace['slug'] ?? '') === self::METRODRIVE_WORKSPACE_SLUG) {
                return true;
            }

            $settings = json_decode((string) ($workspace['settings_json'] ?? ''), true);
            return is_array($settings) && !empty($settings['presentation_demo_workspace']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function profileKey(?int $workspaceId = null): string
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            try {
                $workspace = Database::queryOne("SELECT slug FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]);
                if ((string) ($workspace['slug'] ?? '') === self::METRODRIVE_WORKSPACE_SLUG) {
                    return 'metrodrive';
                }
            } catch (\Throwable $e) {
                return 'riverside';
            }
        }

        return 'riverside';
    }

    public function publicRoute(?string $slug = null): string
    {
        return function_exists('publicUrl') ? publicUrl('dashboard.php?demo=1') : '/dashboard.php?demo=1';
    }

    public function expiresAt(int $ttlHours = self::SESSION_TTL_HOURS): string
    {
        return date('Y-m-d H:i:s', time() + (max(1, $ttlHours) * 3600));
    }

    public function purgeAfter(): string
    {
        return date('Y-m-d H:i:s', time() + (self::PRIVATE_PURGE_DAYS * 86400));
    }

    public function consentRetentionUntil(bool $consented): ?string
    {
        return $consented ? date('Y-m-d H:i:s', time() + (self::CONSENT_RETENTION_DAYS * 86400)) : null;
    }
}
