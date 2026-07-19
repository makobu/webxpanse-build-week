<?php

namespace CRM\Services;

class AIExecutionStatusService
{
    /**
     * @param array<string,mixed> $providerStatus
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public function present(array $providerStatus, array $overrides = []): array
    {
        $success = array_key_exists('success', $overrides)
            ? (bool) $overrides['success']
            : !empty($providerStatus['success']);
        $cacheHit = !empty($providerStatus['cache_hit']);
        $fallback = !empty($providerStatus['fallback_used']) || !empty($overrides['fallback']);
        $blockedReason = trim((string) ($providerStatus['blocked_reason'] ?? $overrides['blocked_reason'] ?? ''));
        $mode = trim((string) ($providerStatus['mode'] ?? ''));
        $source = trim((string) ($overrides['source'] ?? $providerStatus['source'] ?? ''));
        if ($source === '') {
            $source = $cacheHit ? 'cache' : ($fallback ? 'deterministic_fallback' : 'provider');
        }

        $state = 'error';
        if ($cacheHit && $success) {
            $state = 'cached';
        } elseif ($success && !$fallback) {
            $state = 'ready';
        } elseif ($blockedReason !== '' || in_array($mode, ['paused', 'diagnostics_only', 'workspace_key_required'], true)) {
            $state = 'blocked';
        } elseif ($fallback) {
            $state = 'fallback';
        }

        $message = trim((string) ($overrides['message'] ?? $providerStatus['message'] ?? ''));
        if ($message === '') {
            $message = match ($state) {
                'ready' => 'AI completed successfully.',
                'cached' => 'A recent AI result was reused.',
                'fallback' => 'Deterministic fallback guidance is being shown.',
                'blocked' => 'AI is currently unavailable for this workspace.',
                default => 'AI could not complete this request.',
            };
        }

        return [
            'state' => $state,
            'success' => $success,
            'source' => $source,
            'provider' => (string) ($providerStatus['provider'] ?? 'none'),
            'provider_source' => (string) ($providerStatus['provider_source'] ?? ''),
            'model' => (string) ($providerStatus['model'] ?? ''),
            'mode' => $mode,
            'surface' => (string) ($providerStatus['surface'] ?? $overrides['surface'] ?? 'global'),
            'workspace_id' => (int) ($providerStatus['workspace_id'] ?? 0),
            'cache_hit' => $cacheHit,
            'fallback_used' => $fallback,
            'contract_valid' => $providerStatus['contract_valid'] ?? null,
            'prompt_truncated' => !empty($providerStatus['prompt_truncated']),
            'blocked_reason' => $blockedReason,
            'retryable' => $this->isRetryable($state, $blockedReason),
            'message' => $message,
        ];
    }

    private function isRetryable(string $state, string $blockedReason): bool
    {
        if (in_array($blockedReason, ['workspace_ai_key_required', 'runtime_paused', 'runtime_diagnostics_only', 'wallet_depleted'], true)) {
            return false;
        }

        return in_array($state, ['error', 'fallback'], true);
    }
}
