<?php

namespace CRM\Services;

interface TargetPluginCapabilityInterface extends PluginCapabilityHandlerInterface
{
    public function computeTargetMetric(array $capability, array $target, array $definition, bool $withEvidence = false): ?array;

    public function enrichTargetIntelligence(array $capability, array $target, array $context = []): array;
}
