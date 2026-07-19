<?php

namespace CRM\Services;

interface AIContextPluginCapabilityInterface extends PluginCapabilityHandlerInterface
{
    public function provideAIContext(array $capability, int $workspaceId, int $userId, array $context = []): array;
}
