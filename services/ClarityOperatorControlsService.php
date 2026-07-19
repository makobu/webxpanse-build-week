<?php

namespace CRM\Services;

class ClarityOperatorControlsService
{
    public const BLOCKED_REASON = 'clarity_operator_controls_default_workspace_only';

    public function enabled(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        return $workspaceId > 0 && (new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId);
    }

    public function assertSurfaceAllowed(string $surface, ?int $workspaceId = null): void
    {
        if ($surface !== 'clarity_chat' || $this->enabled($workspaceId)) {
            return;
        }

        throw new \DomainException(self::BLOCKED_REASON);
    }

    public function isClarityFeedbackTask(array $input, array $metadata): bool
    {
        $surface = (string) ($input['source_surface'] ?? $metadata['source_surface'] ?? '');
        $type = (string) ($input['source_recommendation_type'] ?? $metadata['source_recommendation_type'] ?? '');

        return $surface === 'clarity_chat' && $type === 'clarity_feedback';
    }
}
