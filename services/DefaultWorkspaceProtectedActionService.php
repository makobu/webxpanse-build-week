<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class DefaultWorkspaceProtectedActionService
{
    private DefaultWorkspaceService $defaultWorkspace;
    private OperatorAuditService $audit;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?OperatorAuditService $audit = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    /**
     * @param array<string,mixed>|null $actor
     * @param array<string,mixed> $metadata
     */
    public function authorize(
        string $actionType,
        ?array $actor,
        ?int $targetWorkspaceId = null,
        ?string $reason = null,
        array $metadata = [],
        bool $requireReason = true
    ): void {
        $targetWorkspaceId = $targetWorkspaceId ?: $this->defaultWorkspace->id();
        $actorUserId = (int) ($actor['id'] ?? 0);
        $reason = trim((string) $reason);

        if ($requireReason && $reason === '') {
            $this->auditBlocked($actionType, $actorUserId, $targetWorkspaceId, 'Protected default workspace action requires a reason.', $metadata);
            throw new \RuntimeException('Protected default workspace action requires a reason.');
        }

        if (!Authorization::isSuperAdmin($actor)) {
            $this->auditBlocked($actionType, $actorUserId, $targetWorkspaceId, $reason ?: 'Only Super Admin can run this default workspace action.', $metadata);
            throw new \RuntimeException('Only Super Admin can run this default workspace action.');
        }

        $this->defaultWorkspace->assertDefaultWorkspace($targetWorkspaceId);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $metadata
     */
    public function auditSuccess(
        string $actionType,
        int $actorUserId,
        ?int $targetWorkspaceId,
        string $reason,
        array $before = [],
        array $after = [],
        array $metadata = []
    ): int {
        $targetWorkspaceId = $targetWorkspaceId ?: $this->defaultWorkspace->id();
        return $this->audit->log(
            $actionType,
            $actorUserId,
            $targetWorkspaceId,
            $reason,
            array_filter([
                'before_summary' => $before,
                'after_summary' => $after,
                'metadata' => $metadata,
            ], static fn(mixed $value): bool => $value !== [] && $value !== null)
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function auditBlocked(string $actionType, int $actorUserId, int $targetWorkspaceId, string $reason, array $metadata): void
    {
        try {
            if (!Database::tableExists('operator_audit_log')) {
                return;
            }
            $this->audit->log($actionType . '_blocked', $actorUserId ?: null, $targetWorkspaceId, $reason, $metadata);
        } catch (\Throwable $ignored) {
        }
    }
}
