<?php

namespace CRM\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\Products;

class OnboardingNudgeDraftService
{
    private AIService $aiService;

    public function __construct(?AIService $aiService = null)
    {
        $this->aiService = $aiService ?: new AIService();
    }

    public function draft(array $context, string $channel = 'email'): array
    {
        $channel = in_array($channel, ['in_app', 'email', 'whatsapp', 'push'], true) ? $channel : 'email';
        $fallback = $this->fallbackDraft($context, $channel);
        $prompt = $this->buildPrompt($context, $channel);

        try {
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('onboarding_lifecycle_nudge', 'owner_setup_nudge', [
                'surface' => 'onboarding_lifecycle_nudge',
                'prompt_key' => 'owner_setup_nudge',
                'blocks' => [
                    ['key' => 'workspace_setup_context', 'content' => $context],
                    ['key' => 'fallback', 'content' => $fallback],
                ],
            ], [
                'legacy_prompt' => $prompt,
                'channel' => $channel,
            ]);
            $raw = trim($this->aiService->processWithPrompt('onboarding_lifecycle_nudge', $resolvedPrompt));
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $subject = trim((string) ($decoded['subject'] ?? $fallback['subject']));
                $body = trim((string) ($decoded['body'] ?? $fallback['body']));
                if ($body !== '') {
                    return [
                        'subject' => $this->bound($subject !== '' ? $subject : $fallback['subject'], 180),
                        'body' => $this->bound($body, $channel === 'whatsapp' ? 700 : 1800),
                        'confidence' => 'medium',
                        'source' => 'ai',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fall back to deterministic copy.
        }

        return $fallback + ['confidence' => 'low', 'source' => 'fallback'];
    }

    public function buildContext(int $workspaceId, int $ownerUserId, array $setupSummary): array
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $ownerUserId, 'owner');
        try {
            $profile = (new CompanyProfile())->get() ?: [];
            $products = (new Products())->list();
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
        $workspace = $setupSummary['workspace'] ?? [];
        $owner = $setupSummary['owner'] ?? [];

        return [
            'workspace_id' => $workspaceId,
            'workspace_name' => (string) ($workspace['name'] ?? $profile['company_name'] ?? 'your workspace'),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'owner_user_id' => $ownerUserId,
            'owner_name' => trim((string) (($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''))),
            'owner_email' => (string) ($owner['email'] ?? ''),
            'onboarding_status' => (string) ($setupSummary['status'] ?? 'in_progress'),
            'current_step' => (int) ($setupSummary['current_step'] ?? 1),
            'operational_score' => (int) ($setupSummary['operational_score'] ?? 0),
            'next_action' => (array) ($setupSummary['next_action'] ?? []),
            'missing_steps' => (array) ($setupSummary['missing_steps'] ?? []),
            'selected_channel' => (string) ($setupSummary['selected_channel'] ?? ''),
            'channel_health' => (array) ($setupSummary['channel_health'] ?? []),
            'company' => [
                'name' => (string) ($profile['company_name'] ?? ''),
                'description' => (string) ($profile['company_description'] ?? ''),
                'website' => (string) ($profile['company_website'] ?? ''),
            ],
            'primary_offer' => (array) ($products[0] ?? []),
        ];
    }

    private function buildPrompt(array $context, string $channel): string
    {
        return "Write a concise lifecycle nudge for a CRM workspace owner who has not finished setup.\n"
            . "Return strict JSON only: {\"subject\":\"...\",\"body\":\"...\"}.\n"
            . "Do not invent facts. Mention one specific next action from the context. Keep the tone helpful and practical.\n"
            . "For WhatsApp or push, keep the body short. For email, include the setup link naturally.\n\n"
            . "CHANNEL: {$channel}\n"
            . "CONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function fallbackDraft(array $context, string $channel): array
    {
        $workspace = trim((string) ($context['workspace_name'] ?? 'your workspace')) ?: 'your workspace';
        $nextAction = (array) ($context['next_action'] ?? []);
        $title = trim((string) ($nextAction['title'] ?? 'Finish workspace setup'));
        $description = trim((string) ($nextAction['description'] ?? 'Complete the next setup step so the CRM can start supporting daily work.'));
        $score = (int) ($context['operational_score'] ?? 0);
        $setupUrl = $this->setupUrl((string) ($nextAction['url'] ?? 'onboarding.php'));

        if ($channel === 'whatsapp' || $channel === 'push' || $channel === 'in_app') {
            return [
                'subject' => $title,
                'body' => "Finish {$title} for {$workspace}. {$description} Open setup: {$setupUrl}",
            ];
        }

        return [
            'subject' => "Finish setup for {$workspace}",
            'body' => implode("\n\n", [
                "Hi,",
                "{$workspace} is about {$score}% operational. The next useful setup step is: {$title}.",
                $description,
                "Open setup here: {$setupUrl}",
                "Once this is done, Clarity can give better guidance and the workspace can start capturing the right work faster.",
            ]),
        ];
    }

    private function setupUrl(string $path): string
    {
        $path = trim($path) !== '' ? trim($path) : 'onboarding.php';
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $basePath = function_exists('getBasePath') ? rtrim((string) getBasePath(), '/') : '';
        return $scheme . '://' . $host . $basePath . '/' . ltrim($path, '/');
    }

    private function bound(string $value, int $max): string
    {
        $value = trim($value);
        if ($max > 0 && mb_strlen($value) > $max) {
            return rtrim(mb_substr($value, 0, $max - 1)) . '...';
        }
        return $value;
    }
}
