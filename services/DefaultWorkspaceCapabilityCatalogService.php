<?php

namespace CRM\Services;

/**
 * Canonical internal operating capabilities for the protected Platform Ops workspace.
 *
 * These are context and readiness definitions, not products that can be quoted or billed.
 */
class DefaultWorkspaceCapabilityCatalogService
{
    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return [
            ['key' => 'workspace_provisioning_and_recovery', 'name' => 'Workspace Provisioning and Recovery', 'description' => 'Create, inspect, repair, reset, and recover tenant workspaces with audit-friendly operator actions.', 'category' => 'Platform Operations', 'features' => ['Workspace directory', 'Setup links', 'Onboarding reset', 'Deletion guardrails'], 'target_audience' => 'Super Admin and platform support', 'use_cases' => 'Provision workspaces, recover stuck owners, review deletion risk.', 'benefits' => 'Faster support turnaround and safer tenant lifecycle management.'],
            ['key' => 'tenant_billing_and_ai_credit_operations', 'name' => 'Tenant Billing and AI Credit Operations', 'description' => 'Monitor subscription status, trial ends, AI Credit balances, checkout health, and failed provider events.', 'category' => 'Billing Operations', 'features' => ['Billing snapshots', 'AI Credit wallet review', 'Trial risk review', 'Payment failure tasks'], 'target_audience' => 'Billing operator and Super Admin', 'use_cases' => 'Review low wallet workspaces, failed payments, and overdue trial conversions.', 'benefits' => 'Protect revenue and reduce billing surprises.'],
            ['key' => 'platform_dashboard_and_reporting', 'name' => 'Platform Dashboard and Reporting', 'description' => 'Summarize operational score, stuck onboarding, billing risk, token wallet exposure, and recent operator activity for Super Admin review.', 'category' => 'Reporting Operations', 'features' => ['Operational score', 'Risk filters', 'Audit summaries', 'Workspace directory reporting'], 'target_audience' => 'Super Admin and platform operator', 'use_cases' => 'Run daily platform standups and see what needs attention.', 'benefits' => 'Turns scattered platform signals into one operating rhythm.'],
            ['key' => 'onboarding_lifecycle_management', 'name' => 'Onboarding Lifecycle Management', 'description' => 'Find stuck onboarding workspaces, draft context-aware nudges, send setup links, and track recovery actions.', 'category' => 'Tenant Success', 'features' => ['Lifecycle nudges', 'AI drafts', 'Recovery panel', 'Setup prompts'], 'target_audience' => 'Tenant success and platform support', 'use_cases' => 'Recover incomplete onboarding and improve activation.', 'benefits' => 'More workspaces become operational without manual guesswork.'],
            ['key' => 'channel_health_and_deliverability', 'name' => 'Channel Health and Deliverability', 'description' => 'Review email, assistant Gmail, IMAP, SMTP, and WhatsApp readiness signals for each workspace.', 'category' => 'Channel Operations', 'features' => ['Health badges', 'Safe checks', 'Assistant mailbox review', 'WhatsApp signup status'], 'target_audience' => 'Platform support and implementation admin', 'use_cases' => 'Diagnose missing inbox, OAuth, SMTP, IMAP, or WhatsApp setup.', 'benefits' => 'Clearer setup support and fewer silent channel failures.'],
            ['key' => 'automation_governance_and_security_review', 'name' => 'Automation Governance and Security Review', 'description' => 'Manage Super Admin 2FA, operator audit reviews, automation safety, incident follow-up, and policy settings.', 'category' => 'Governance', 'features' => ['2FA review', 'Operator audit', 'Automation safety', 'Security setting tasks'], 'target_audience' => 'Super Admin', 'use_cases' => 'Review risky actions, deletion attempts, impersonation, and automation changes.', 'benefits' => 'Safer platform operations with traceable decisions.'],
            ['key' => 'workspace_deletion_review_and_compliance', 'name' => 'Workspace Deletion Review and Compliance', 'description' => 'Review destructive delete requests, confirm guardrails, preserve audit context, and document operator reasons.', 'category' => 'Governance', 'features' => ['Delete guardrails', '2FA checkpoint', 'Audit metadata', 'Reason review'], 'target_audience' => 'Super Admin', 'use_cases' => 'Verify deletions are intentional, compliant, and never target protected workspaces.', 'benefits' => 'Reduces irreversible deletion mistakes and strengthens accountability.'],
            ['key' => 'platform_support_escalation_desk', 'name' => 'Platform Support Escalation Desk', 'description' => 'Coordinate owner recovery, billing disputes, setup blockers, failed provider events, and support escalations from one operating workspace.', 'category' => 'Support Operations', 'features' => ['Escalation templates', 'Support tasks', 'Owner recovery', 'Provider follow-up'], 'target_audience' => 'Platform support and Super Admin', 'use_cases' => 'Move tenant issues from signal to owner contact to resolution.', 'benefits' => 'Gives support a consistent path for high-risk platform issues.'],
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_map(static fn(array $item): string => (string) $item['name'], $this->all()));
    }

    /** @return list<string> */
    public function validationErrors(): array
    {
        $errors = [];
        $keys = [];
        foreach ($this->all() as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                $errors[] = $key === '' ? 'missing_key' : 'duplicate_key:' . $key;
            }
            $keys[$key] = true;
            foreach (['name', 'description', 'category', 'target_audience', 'use_cases', 'benefits'] as $field) {
                if (trim((string) ($item[$field] ?? '')) === '') {
                    $errors[] = $key . ':' . $field;
                }
            }
        }
        return $errors;
    }
}
