<?php

namespace CRM\Services;

class DefaultWorkspacePackageExemptionService
{
    public const REASON = 'Default workspace package exempt.';

    public function __construct(private ?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?? new DefaultWorkspaceService();
    }

    /**
     * @param array<string,mixed>|null $workspace
     */
    public function isExempt(int $workspaceId, ?array $workspace = null): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        try {
            return $this->defaultWorkspace->isDefaultWorkspace($workspaceId, $workspace);
        } catch (\Throwable $e) {
            return $workspaceId === DefaultWorkspaceService::DEFAULT_ID
                || (is_array($workspace) && (string) ($workspace['slug'] ?? $workspace['workspace_slug'] ?? '') === DefaultWorkspaceService::DEFAULT_SLUG);
        }
    }

    public function reason(): string
    {
        return self::REASON;
    }
}
