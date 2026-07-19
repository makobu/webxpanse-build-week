<?php

namespace CRM\Services;

interface TaskPluginCapabilityInterface extends PluginCapabilityHandlerInterface
{
    public function validateTaskPayload(array $capability, array $taskPayload, array $context = []): array;

    public function enrichTaskPayload(array $capability, array $taskPayload, array $context = []): array;

    public function handleTaskLifecycleEvent(string $eventName, array $capability, array $taskPayload, array $context = []): void;
}
