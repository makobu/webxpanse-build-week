<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class GuidedDemoAccessService
{
    public function __construct(
        private ?GuidedDemoSessionService $sessions = null,
        private ?GuidedDemoStepCatalog $steps = null
    ) {
        $this->sessions = $sessions ?? new GuidedDemoSessionService();
        $this->steps = $steps ?? new GuidedDemoStepCatalog();
    }

    public function redirectForPage(int $workspaceId, array $user, string $currentPage): ?string
    {
        $userId = (int) ($user['id'] ?? 0);
        $currentPage = basename($currentPage);
        if ($workspaceId <= 0 || $userId <= 0 || $currentPage === '') {
            return null;
        }

        if ($this->hasActiveProtectedDemoSession($workspaceId)) {
            return null;
        }

        if ($this->hasPaidOrExemptAccess($workspaceId, $user)) {
            return null;
        }

        if ($this->isAlwaysAllowedPage($currentPage)) {
            return null;
        }

        if ($this->onboardingIncomplete($workspaceId)) {
            return null;
        }

        $active = $this->sessions->activeSession($workspaceId, $userId);
        if ($active !== null) {
            $stepKey = (string) ($active['current_step_key'] ?? $this->steps->firstKey());
            if ($this->canViewActiveDemoInvoice($active, $stepKey, $currentPage)) {
                return null;
            }
            if ($currentPage === $this->steps->pageFor($stepKey)) {
                return null;
            }
            return $this->publicUrl($this->steps->routeFor($stepKey, $active));
        }

        $latest = $this->sessions->latestSession($workspaceId, $userId);
        $status = (string) ($latest['status'] ?? '');
        $cleanupStatus = (string) ($latest['cleanup_status'] ?? '');
        if ($status === 'cleanup_failed' || $cleanupStatus === 'failed') {
            return $this->publicUrl('guided_demo.php?cleanup=failed');
        }
        if (in_array($status, ['completed', 'exited'], true) && $cleanupStatus === 'succeeded') {
            if ($currentPage === 'dashboard.php') {
                return null;
            }
            return $this->dashboardUrl($status === 'exited' ? 'exited' : 'complete');
        }

        if ($currentPage === 'dashboard.php') {
            return null;
        }

        return $this->dashboardUrl();
    }

    public function canViewActiveStepRoute(int $workspaceId, array $user, string $currentPage, array $query = []): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $currentPage = basename($currentPage);
        if ($workspaceId <= 0 || $userId <= 0 || $currentPage === '') {
            return false;
        }

        $active = $this->sessions->activeSession($workspaceId, $userId);
        if ($active === null) {
            return false;
        }

        $stepKey = (string) ($active['current_step_key'] ?? $this->steps->firstKey());
        [$expectedPage, $expectedQuery] = $this->routeParts($this->steps->routeFor($stepKey, $active));
        if ($currentPage !== $expectedPage || $expectedQuery === []) {
            return false;
        }

        foreach ($expectedQuery as $key => $value) {
            if (!array_key_exists($key, $query) || (string) $query[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public function hasPaidOrExemptAccess(int $workspaceId, array $user): bool
    {
        if ($workspaceId <= 0) {
            return true;
        }
        if (Authorization::isSuperAdmin($user)) {
            return true;
        }

        try {
            $workspace = WorkspaceContext::currentWorkspace();
            if (WorkspaceContext::isDefaultWorkspace($workspaceId, $workspace)) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        try {
            $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId, $user);
            $status = (string) ($snapshot['subscription_status'] ?? 'inactive');
            return $status === 'active' && empty($snapshot['billing_blocked']);
        } catch (\Throwable) {
            return true;
        }
    }

    private function hasActiveProtectedDemoSession(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        try {
            return (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function onboardingIncomplete(int $workspaceId): bool
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_onboarding_state')) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT status FROM workspace_onboarding_state WHERE workspace_id = ? LIMIT 1",
            [$workspaceId]
        );

        return $row !== null && (string) ($row['status'] ?? '') !== 'completed';
    }

    private function canViewActiveDemoInvoice(array $session, string $stepKey, string $currentPage): bool
    {
        if ($currentPage !== 'invoice_view.php' || $stepKey !== 'prepare_demo_quote') {
            return false;
        }

        $requestedInvoiceId = (int) ($_GET['id'] ?? 0);
        if ($requestedInvoiceId <= 0) {
            return false;
        }

        $metadata = $this->decodeAssoc($session['metadata_json'] ?? null);
        $demoInvoiceId = (int) ($metadata['simulated_invoice_id'] ?? 0);

        return $demoInvoiceId > 0 && $requestedInvoiceId === $demoInvoiceId;
    }

    private function isAlwaysAllowedPage(string $page): bool
    {
        return in_array($page, [
            'onboarding.php',
            'guided_demo.php',
            'guided_demo_wrap.php',
            'compass_free.php',
            'billing_choose_package.php',
            'billing_payment_required.php',
            'billing_start_payment.php',
            'billing_callback.php',
            'owner_support.php',
            'owner_support_admin.php',
            'owner_help_expert.php',
            'owner_help_experts_admin.php',
            'logout.php',
            'login.php',
        ], true);
    }

    private function publicUrl(string $path): string
    {
        return function_exists('publicUrl') ? publicUrl($path) : '/' . ltrim($path, '/');
    }

    private function dashboardUrl(string $demoStatus = ''): string
    {
        $query = $demoStatus !== '' ? '?demo=' . rawurlencode($demoStatus) : '';
        return $this->publicUrl('dashboard.php' . $query);
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function routeParts(string $route): array
    {
        $parts = parse_url($route);
        $path = (string) ($parts['path'] ?? $route);
        $queryString = (string) ($parts['query'] ?? '');
        $query = [];
        parse_str($queryString, $query);

        return [basename($path), $query];
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
