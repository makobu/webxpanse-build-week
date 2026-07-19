<?php

namespace CRM\Services;

interface PluginCapabilityHandlerInterface
{
    public function supportsCapability(array $capability): bool;
}
