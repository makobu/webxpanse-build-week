<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;

class WorkspaceOperatingBriefService
{
    public function generate(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Workspace and user are required.');
        }

        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, WorkspaceContext::currentRoleSlug() ?? 'admin');

        try {
            $brief = $this->build($workspaceId, $userId);
            $this->saveLatestBrief($workspaceId, $brief);
            return $brief;
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    public function latest(int $workspaceId): ?array
    {
        $row = $this->loadOnboardingRow($workspaceId);
        $summary = $this->decodeAssoc($row['launch_summary_json'] ?? null);
        $brief = $summary['operating_brief'] ?? null;
        return is_array($brief) ? $brief : null;
    }

    public function buildOnboardingState(int $workspaceId, int $userId): array
    {
        $service = new WorkspaceOnboardingService();
        if (!$service->tableReady() || $workspaceId <= 0) {
            return [
                'status' => 'not_started',
                'quick_start' => false,
                'readiness_score' => 0,
                'operational_score' => 0,
                'is_operational' => false,
                'completed_steps' => [],
                'missing_steps' => [],
                'setup_actions' => [],
                'launch_summary' => [],
            ];
        }

        $state = $service->getState($workspaceId, $userId);
        $impact = $service->getDashboardSetupImpact($workspaceId, $userId);
        $completed = array_values((array) ($state['completed_steps'] ?? []));
        $required = array_values((array) ($state['required_steps'] ?? []));
        $missing = array_values(array_diff($required, $completed));
        $skipped = array_values((array) ($state['skipped_optional'] ?? []));
        $status = (string) ($state['status'] ?? 'not_started');
        $operationalScore = (int) ($impact['score'] ?? 0);

        return [
            'status' => $status,
            'quick_start' => in_array('quick_start', $skipped, true) || !empty($impact['quick_start']),
            'readiness_score' => (int) (($state['readiness']['readiness_score'] ?? 0)),
            'operational_score' => $operationalScore,
            'is_operational' => $status === 'completed' && $operationalScore >= 80,
            'completed_steps' => $completed,
            'missing_steps' => $missing,
            'setup_actions' => array_values((array) ($impact['actions'] ?? [])),
            'launch_summary' => (array) (($state['readiness']['launch_summary'] ?? [])),
        ];
    }

    private function build(int $workspaceId, int $userId): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $primaryProduct = (array) ($products[0] ?? []);
        $strategy = (new UserStrategyProfile())->get($userId) ?: [];
        $invoice = (new InvoiceSettings())->get();
        $row = $this->loadOnboardingRow($workspaceId);
        $onboarding = $this->buildOnboardingState($workspaceId, $userId);
        $channels = (new WorkspaceChannelHealthService())->summarize($workspaceId, \CRM\Auth::user() ?: null);
        $dealAutomation = (new DealAutomationConfig())->get();
        $commercialAutomation = (new CommercialAutomationConfig())->get();
        $assistant = new WorkspaceAssistantConfigService();
        $emailAssistant = $assistant->get($workspaceId, 'email');
        $whatsAppAssistant = $assistant->get($workspaceId, 'whatsapp');
        $optional = (array) (($onboarding['launch_summary']['optional_setup'] ?? []) ?: []);
        $missingContext = $this->missingContext($profile, $primaryProduct, $strategy, $invoice, $row, $onboarding, $channels);
        $deferredSetup = $this->deferredSetup($onboarding, $channels, $invoice);

        $sections = [
            'company' => [
                'name' => $this->value($profile['company_name'] ?? null),
                'industry' => $this->value($profile['company_industry'] ?? null),
                'location' => $this->value($profile['company_location'] ?? null),
                'website' => $this->value($profile['company_website'] ?? null),
                'description' => $this->value($profile['company_description'] ?? null),
            ],
            'customer_and_offer' => [
                'ideal_customer' => $this->value($strategy['ideal_customer_profile'] ?? $profile['icp_job_titles'] ?? $primaryProduct['target_audience'] ?? null),
                'pain_points' => $this->value($profile['icp_pain_points'] ?? null),
                'offer_name' => $this->value($primaryProduct['name'] ?? null),
                'offer_description' => $this->value($primaryProduct['description'] ?? null),
                'positioning' => $this->value($strategy['positioning_notes'] ?? $strategy['offer_angle'] ?? null),
                'pricing' => $this->value($primaryProduct['pricing_info'] ?? null),
            ],
            'voice_and_tone' => [
                'tone' => $this->value($strategy['draft_tone_preset'] ?? null),
                'formality' => $this->value($strategy['draft_formality_level'] ?? null),
                'language_level' => $this->languageLevelSummary($strategy['draft_reading_level'] ?? null),
                'cta_style' => $this->value($strategy['draft_cta_style'] ?? null),
                'voice_notes' => $this->value($strategy['draft_voice_notes'] ?? null),
                'words_to_avoid' => $this->value($strategy['words_to_avoid'] ?? $this->decodeAssoc($row['tone_json'] ?? null)['words_to_avoid'] ?? null),
                'escalation_preference' => $this->value($strategy['escalation_preference'] ?? $this->decodeAssoc($row['tone_json'] ?? null)['escalation_preference'] ?? null),
            ],
            'automation_preferences' => [
                'technical_level' => $this->value($row['technical_level'] ?? null),
                'launch_mode_preference' => $this->value($row['automation_launch_mode'] ?? null),
                'autoresponder_preference' => $this->value($row['ai_autoresponder_mode'] ?? null),
                'best_practices' => !empty($row['ai_best_practices_enabled']) ? 'Yes' : 'No',
                'deal_automation_requested' => !empty($row['deal_automation_enabled']) ? 'Yes' : 'No',
                'commercial_layer_requested' => !empty($row['commercial_layer_enabled']) ? 'Yes' : 'No',
                'live_automation_enabled_here' => 'No',
            ],
            'channels' => [
                'selected_channel' => $this->value($row['communication_channel'] ?? null),
                'main_email' => (string) ($channels['main_email']['label'] ?? 'Email not connected'),
                'assistant_email' => (string) ($channels['assistant_email']['label'] ?? 'Assistant email not connected'),
                'whatsapp' => (string) ($channels['whatsapp']['label'] ?? 'WhatsApp not connected'),
                'email_assistant_enabled' => !empty($emailAssistant['enabled']) ? 'Yes' : 'No',
                'whatsapp_assistant_enabled' => !empty($whatsAppAssistant['enabled']) ? 'Yes' : 'No',
            ],
            'automation' => [
                'technical_level' => $this->value($row['technical_level'] ?? null),
                'autoresponder_mode' => $this->value($row['ai_autoresponder_mode'] ?? null),
                'deal_automation' => (!empty($dealAutomation['enabled']) ? 'Enabled' : 'Not enabled') . ' (' . (string) ($dealAutomation['mode'] ?? 'suggest_only') . ')',
                'commercial_automation' => (!empty($commercialAutomation['enabled']) ? 'Enabled' : 'Not enabled') . ' (' . (string) ($commercialAutomation['mode'] ?? 'auto_safe') . ')',
            ],
            'deferred_operational_setup' => $deferredSetup,
            'billing_readiness' => [
                'invoice_enabled' => !empty($invoice['enabled']) ? 'Yes' : 'No',
                'currency' => $this->value($invoice['default_currency'] ?? null),
                'payment_terms_days' => (string) ((int) ($invoice['default_payment_terms_days'] ?? 14)),
                'payment_instructions' => trim((string) ($invoice['bank_instructions'] ?? $invoice['bank_account_number'] ?? '')) !== '' ? 'Present' : 'Missing',
            ],
            'setup_status' => [
                'onboarding_status' => (string) ($onboarding['status'] ?? 'not_started'),
                'quick_start' => !empty($onboarding['quick_start']) ? 'Yes' : 'No',
                'readiness_score' => (string) ($onboarding['readiness_score'] ?? 0) . '%',
                'operational_score' => (string) ($onboarding['operational_score'] ?? 0) . '%',
                'is_operational' => !empty($onboarding['is_operational']) ? 'Yes' : 'No',
            ],
        ];

        $brief = [
            'sections' => $sections,
            'missing_context' => $missingContext,
            'markdown' => $this->toMarkdown($sections, $missingContext),
            'generated_at' => gmdate('c'),
            'readiness' => [
                'readiness_score' => (int) ($onboarding['readiness_score'] ?? 0),
                'operational_score' => (int) ($onboarding['operational_score'] ?? 0),
                'is_operational' => !empty($onboarding['is_operational']),
                'setup_actions' => (array) ($onboarding['setup_actions'] ?? []),
            ],
            'source_summary' => [
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'product_count' => count($products),
                'channel_statuses' => [
                    'main_email' => (string) ($channels['main_email']['status'] ?? ''),
                    'assistant_email' => (string) ($channels['assistant_email']['status'] ?? ''),
                    'whatsapp' => (string) ($channels['whatsapp']['status'] ?? ''),
                ],
                'optional_setup' => $optional,
                'deferred_operational_setup' => $deferredSetup,
            ],
        ];

        return $brief;
    }

    private function missingContext(array $profile, array $product, array $strategy, array $invoice, array $row, array $onboarding, array $channels): array
    {
        $missing = [];
        $checks = [
            'Company description' => $profile['company_description'] ?? '',
            'Ideal customer' => $strategy['ideal_customer_profile'] ?? $product['target_audience'] ?? '',
            'Primary offer' => $product['name'] ?? '',
            'Offer pricing' => $product['pricing_info'] ?? '',
            'Tone preset' => $strategy['draft_tone_preset'] ?? '',
            'Selected channel' => $row['communication_channel'] ?? '',
        ];
        foreach ($checks as $label => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $label;
            }
        }
        foreach ((array) ($onboarding['missing_steps'] ?? []) as $step) {
            $missing[] = 'Onboarding: ' . str_replace('_', ' ', (string) $step);
        }

        return array_values(array_unique($missing));
    }

    private function languageLevelSummary(mixed $value): string
    {
        $context = (new WorkspaceLanguageLevelService())->context($value);

        return $context['label'] . ' - ' . $context['description'];
    }

    private function deferredSetup(array $onboarding, array $channels, array $invoice): array
    {
        $items = [];
        if (($channels['main_email']['status'] ?? '') !== 'ready' && ($channels['whatsapp']['status'] ?? '') !== 'ready') {
            $items['channels'] = 'Connect Email or WhatsApp later from Settings.';
        }
        if (empty($invoice['enabled']) || trim((string) ($invoice['bank_instructions'] ?? $invoice['bank_account_number'] ?? '')) === '') {
            $items['billing'] = 'Add invoice defaults and payment instructions later.';
        }
        foreach ((array) ($onboarding['setup_actions'] ?? []) as $action) {
            $key = trim((string) ($action['key'] ?? ''));
            $title = trim((string) ($action['title'] ?? ''));
            if ($key !== '' && $title !== '') {
                $items[$key] = $title;
            }
        }

        return $items === [] ? ['status' => 'No deferred setup actions detected.'] : $items;
    }

    private function toMarkdown(array $sections, array $missingContext): string
    {
        $labels = [
            'company' => 'Company',
            'customer_and_offer' => 'Ideal Customer and Offer',
            'voice_and_tone' => 'Tone',
            'automation_preferences' => 'Automation Preferences',
            'channels' => 'Channels',
            'automation' => 'Automation',
            'deferred_operational_setup' => 'Deferred Operational Setup',
            'billing_readiness' => 'Billing Readiness',
            'setup_status' => 'Setup Status',
        ];
        $lines = ['## Workspace Operating Brief'];
        foreach ($sections as $key => $values) {
            $lines[] = '';
            $lines[] = '### ' . ($labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key)));
            foreach ((array) $values as $field => $value) {
                $lines[] = '- ' . ucwords(str_replace('_', ' ', (string) $field)) . ': ' . (string) $value;
            }
        }
        $lines[] = '';
        $lines[] = '### Missing Context';
        if ($missingContext === []) {
            $lines[] = '- No major missing context detected.';
        } else {
            foreach ($missingContext as $item) {
                $lines[] = '- ' . (string) $item;
            }
        }
        return implode("\n", $lines);
    }

    private function saveLatestBrief(int $workspaceId, array $brief): void
    {
        $row = $this->loadOnboardingRow($workspaceId);
        if (!$row) {
            return;
        }
        $summary = $this->decodeAssoc($row['launch_summary_json'] ?? null);
        $summary['operating_brief'] = $brief;
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET launch_summary_json = ?, updated_at = NOW()
             WHERE workspace_id = ?",
            [json_encode($summary, JSON_UNESCAPED_SLASHES), $workspaceId]
        );
    }

    private function loadOnboardingRow(int $workspaceId): array
    {
        return Database::queryOne(
            "SELECT *
             FROM workspace_onboarding_state
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function value(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : 'Missing';
    }
}
