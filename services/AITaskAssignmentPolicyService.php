<?php

namespace CRM\Services;

class AITaskAssignmentPolicyService
{
    /**
     * @param array<string,mixed> $taskContext
     * @return array<string,mixed>
     */
    public function resolve(array $taskContext): array
    {
        $normalized = $this->normalizeContext($taskContext);
        $taskIntent = $this->resolveIntent($normalized);

        $policy = [
            'task_intent' => $taskIntent,
            'source_surface' => (string) ($normalized['source_surface'] ?? ''),
            'domain_key' => null,
            'match_mode' => 'any',
            'required_permissions' => [],
            'is_known_domain' => false,
        ];

        switch ($taskIntent) {
            case 'follow_up':
                $policy['domain_key'] = 'follow_up';
                $policy['required_permissions'] = ['ai.tasks.follow_up'];
                $policy['is_known_domain'] = true;
                break;
            case 'billing':
                $policy['domain_key'] = 'billing';
                $policy['required_permissions'] = [
                    'invoices.create',
                    'invoices.edit',
                    'invoices.finalize',
                    'invoices.mark_paid',
                    'invoices.settings',
                    'settings.invoicing',
                    'ai.invoices.execute',
                ];
                $policy['is_known_domain'] = true;
                break;
            case 'ops':
                $policy['domain_key'] = 'ops';
                $policy['required_permissions'] = [
                    'workflows.manage',
                    'settings.deal_automation',
                    'settings.ai',
                    'settings.general',
                ];
                $policy['is_known_domain'] = true;
                break;
            case 'pricing':
                $policy['domain_key'] = 'pricing';
                $policy['required_permissions'] = ['ai.tasks.pricing'];
                $policy['is_known_domain'] = true;
                break;
            case 'company_profile':
                $policy['domain_key'] = 'company_profile';
                $policy['required_permissions'] = ['settings.company'];
                $policy['is_known_domain'] = true;
                break;
            case 'sales_process':
                $policy['domain_key'] = 'sales_process';
                $policy['required_permissions'] = ['settings.deal_automation', 'workflows.manage'];
                $policy['is_known_domain'] = true;
                break;
            case 'segmentation':
                $policy['domain_key'] = 'segmentation';
                $policy['required_permissions'] = ['ai.tasks.segmentation'];
                $policy['is_known_domain'] = true;
                break;
            case 'goal_progress':
                $policy['domain_key'] = 'goal_progress';
                $policy['required_permissions'] = ['targets.manage_all'];
                $policy['is_known_domain'] = true;
                break;
            case 'review':
                $policy['domain_key'] = 'review';
                $policy['required_permissions'] = ['ai.tasks.review'];
                $policy['is_known_domain'] = true;
                break;
        }

        return $policy;
    }

    /**
     * @param array<string,mixed> $taskContext
     * @return array<string,mixed>
     */
    private function normalizeContext(array $taskContext): array
    {
        $metadata = $taskContext['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'title' => trim((string) ($taskContext['title'] ?? '')),
            'description' => trim((string) ($taskContext['description'] ?? '')),
            'task_intent' => trim((string) ($taskContext['task_intent'] ?? $metadata['task_intent'] ?? '')),
            'source_surface' => trim((string) ($taskContext['source_surface'] ?? $metadata['source_surface'] ?? '')),
            'source_recommendation_type' => trim((string) ($taskContext['source_recommendation_type'] ?? $metadata['source_recommendation_type'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function resolveIntent(array $normalized): string
    {
        $taskIntent = strtolower(trim((string) ($normalized['task_intent'] ?? '')));
        if ($taskIntent !== '') {
            return $taskIntent;
        }

        $text = strtolower(trim(implode(' ', [
            (string) ($normalized['title'] ?? ''),
            (string) ($normalized['description'] ?? ''),
            (string) ($normalized['source_recommendation_type'] ?? ''),
            (string) ($normalized['source_surface'] ?? ''),
        ])));

        if ($text === '') {
            return '';
        }

        if (str_contains($text, 'follow') || str_contains($text, 'reply') || str_contains($text, 'check-in')) {
            return 'follow_up';
        }
        if (str_contains($text, 'invoice') || str_contains($text, 'invoicing') || str_contains($text, 'billing')) {
            return 'billing';
        }
        if (str_contains($text, 'workflow') || str_contains($text, 'automation') || str_contains($text, 'ops')) {
            return 'ops';
        }
        if (
            str_contains($text, 'pricing')
            || str_contains($text, 'price ')
            || str_contains($text, 'product')
            || str_contains($text, 'offer')
            || str_contains($text, 'package')
            || str_contains($text, 'plan')
        ) {
            return 'pricing';
        }
        if (
            str_contains($text, 'profile')
            || str_contains($text, 'company')
            || str_contains($text, 'branding')
            || str_contains($text, 'brand')
        ) {
            return 'company_profile';
        }
        if (
            str_contains($text, 'pipeline')
            || str_contains($text, 'stage')
            || str_contains($text, 'sales process')
            || str_contains($text, 'deal flow')
            || str_contains($text, 'deal stage')
        ) {
            return 'sales_process';
        }
        if (
            str_contains($text, 'segment')
            || str_contains($text, 'audience')
            || str_contains($text, 'customer group')
            || str_contains($text, 'ideal customer')
            || str_contains($text, 'icp')
        ) {
            return 'segmentation';
        }
        if (str_contains($text, 'goal') || str_contains($text, 'target')) {
            return 'goal_progress';
        }
        if (
            str_contains($text, 'review')
            || str_contains($text, 'exception')
            || str_contains($text, 'check')
            || str_contains($text, 'audit')
        ) {
            return 'review';
        }

        return '';
    }
}
