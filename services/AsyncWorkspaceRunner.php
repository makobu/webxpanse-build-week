<?php

namespace CRM\Services;

class AsyncWorkspaceRunner
{
    /**
     * @template TResult
     * @param callable(): TResult $callback
     * @return TResult
     */
    public static function runWithWorkspace(
        int $workspaceId,
        callable $callback,
        ?int $userId = null,
        ?string $missingWorkspaceMessage = null
    ) {
        $previousRuntimeWorkspace = WorkspaceContext::runtimeSnapshot();

        if ($workspaceId <= 0 || WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId) === null) {
            throw new \RuntimeException($missingWorkspaceMessage ?: 'Async operation is missing a valid workspace.');
        }

        try {
            return $callback();
        } finally {
            if ($previousRuntimeWorkspace !== null) {
                WorkspaceContext::restoreRuntimeWorkspace($previousRuntimeWorkspace);
            } else {
                WorkspaceContext::clearRuntimeWorkspace();
            }
        }
    }
}
