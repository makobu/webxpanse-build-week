<?php

namespace CRM\Services;

interface FounderAttentionSignalProviderInterface
{
    /**
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    public function provide(int $workspaceId, int $userId, array $snapshot): array;
}
