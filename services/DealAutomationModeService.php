<?php

namespace CRM\Services;

use CRM\Modules\DealAutomationConfig;

class DealAutomationModeService
{
    private const ALLOWED_MODES = ['manual', 'suggest_only', 'auto_safe', 'full_auto'];

    private DealAutomationConfig $config;
    private DealAutomationReadinessService $readiness;

    public function __construct(?DealAutomationConfig $config = null, ?DealAutomationReadinessService $readiness = null)
    {
        $this->config = $config ?? new DealAutomationConfig();
        $this->readiness = $readiness ?? new DealAutomationReadinessService();
    }

    /**
     * @return array{success:bool,message?:string,error?:string,status:int,state:array<string,mixed>}
     */
    public function updateMode(string $mode, ?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $this->assertAllowedMode($mode);

        $existing = $this->config->get($workspaceId);
        $payload = array_merge($existing, [
            'enabled' => $mode !== 'manual',
            'mode' => $mode === 'manual' ? (string) ($existing['mode'] ?? 'suggest_only') : $mode,
        ]);

        return $this->save($payload, $subjectUserId, $workspaceId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{success:bool,message?:string,error?:string,status:int,state:array<string,mixed>}
     */
    public function save(array $payload, ?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $mode = (string) ($payload['mode'] ?? 'suggest_only');
        $this->assertAllowedMode($mode);

        $state = $this->readiness->getState($subjectUserId, $workspaceId);
        if ($mode === 'full_auto' && !$this->readiness->canPromoteToFullAuto($subjectUserId, $workspaceId)) {
            return [
                'success' => false,
                'error' => 'Full auto is not ready yet.',
                'status' => 422,
                'state' => $state,
            ];
        }

        if ($mode === 'manual') {
            $existing = $this->config->get($workspaceId);
            $payload['enabled'] = false;
            $payload['mode'] = (string) ($existing['mode'] ?? 'suggest_only');
        }

        $this->config->save($payload, $workspaceId);

        return [
            'success' => true,
            'message' => 'Deal automation mode updated.',
            'status' => 200,
            'state' => $this->readiness->getState($subjectUserId, $workspaceId),
        ];
    }

    private function assertAllowedMode(string $mode): void
    {
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            throw new \InvalidArgumentException('Invalid mode');
        }
    }
}
