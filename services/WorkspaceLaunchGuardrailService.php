<?php

namespace CRM\Services;

use CRM\Modules\RateLimiter;

class WorkspaceLaunchGuardrailService
{
    public function enforceCheckoutCreation(int $workspaceId): void
    {
        $this->enforce(
            'workspace_checkout_create_' . max(0, $workspaceId),
            $this->envInt('LAUNCH_GUARDRAIL_CHECKOUT_ATTEMPTS', 6),
            $this->envInt('LAUNCH_GUARDRAIL_CHECKOUT_WINDOW', 600),
            'Too many checkout attempts were made. Please wait a moment before trying again.'
        );
    }

    public function enforceGovernanceAction(int $workspaceId, string $action): void
    {
        $normalizedAction = preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim($action))) ?: 'workspace_governance';

        $this->enforce(
            'workspace_governance_' . $normalizedAction . '_' . max(0, $workspaceId),
            $this->envInt('LAUNCH_GUARDRAIL_GOVERNANCE_ATTEMPTS', 10),
            $this->envInt('LAUNCH_GUARDRAIL_GOVERNANCE_WINDOW', 600),
            'Too many workspace team changes were attempted. Please wait a moment before trying again.'
        );
    }

    public function enforceInviteAcceptance(string $token): void
    {
        $token = trim($token);
        $suffix = $token !== '' ? substr(hash('sha256', $token), 0, 20) : 'missing';

        $this->enforce(
            'workspace_invite_accept_' . $suffix,
            $this->envInt('LAUNCH_GUARDRAIL_INVITE_ACCEPT_ATTEMPTS', 10),
            $this->envInt('LAUNCH_GUARDRAIL_INVITE_ACCEPT_WINDOW', 900),
            'Too many invite acceptance attempts were made. Please wait a moment before trying again.'
        );
    }

    public function enforceBillingCallback(?string $reference): void
    {
        $reference = trim((string) $reference);
        $suffix = $reference !== '' ? substr(hash('sha256', $reference), 0, 20) : 'missing';

        $this->enforce(
            'workspace_billing_callback_' . $suffix,
            $this->envInt('LAUNCH_GUARDRAIL_BILLING_CALLBACK_ATTEMPTS', 20),
            $this->envInt('LAUNCH_GUARDRAIL_BILLING_CALLBACK_WINDOW', 300),
            'Too many payment confirmation attempts were made. Please wait a moment before retrying.'
        );
    }

    public function enforcePaystackWebhook(?string $signature, ?string $reference = null): void
    {
        $signature = trim((string) $signature);
        $reference = trim((string) $reference);
        $suffixSource = $reference !== '' ? $reference : ($signature !== '' ? $signature : 'missing');
        $suffix = substr(hash('sha256', $suffixSource), 0, 20);

        $this->enforce(
            'workspace_paystack_webhook_' . $suffix,
            $this->envInt('LAUNCH_GUARDRAIL_PAYSTACK_WEBHOOK_ATTEMPTS', 60),
            $this->envInt('LAUNCH_GUARDRAIL_PAYSTACK_WEBHOOK_WINDOW', 300),
            'Too many billing webhook attempts were received. Please retry shortly.'
        );
    }

    private function enforce(string $identifier, int $maxAttempts, int $windowSeconds, string $message): void
    {
        $limiter = new RateLimiter($identifier, max(1, $maxAttempts), max(1, $windowSeconds));
        if ($limiter->isLimited()) {
            throw new WorkspaceLaunchThrottleException($message, max(1, $limiter->getRetryAfterSeconds()));
        }

        $limiter->recordAttempt();
    }

    private function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }

        return max(1, (int) $value);
    }
}
