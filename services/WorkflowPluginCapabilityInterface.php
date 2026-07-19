<?php

namespace CRM\Services;

interface WorkflowPluginCapabilityInterface extends PluginCapabilityHandlerInterface
{
    public function executeWorkflowAction(array $capability, array $action, array $context = []): array;
}
