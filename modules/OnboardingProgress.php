<?php
/**
 * Onboarding Progress Module
 *
 * Tracks and computes first-week checklist progress for Foundation Mode.
 * Progress is computed from existing data (company profile, contacts, tasks, deals, workflows).
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\WorkspaceContext;

class OnboardingProgress
{
    /**
     * Get computed onboarding progress for a user.
     */
    public function getProgress(int $userId): array
    {
        $catalog = new ClarityPackageCatalog();
        $workspaceSettings = new WorkspaceLaunchSettings();
        $launch = $workspaceSettings->get();
        $niche = $catalog->getNicheProfile((string) ($launch['target_niche'] ?? ClarityPackageCatalog::NICHE_INTERIORS_CONTRACTORS));
        $nicheHints = (array) ($niche['onboarding_hints'] ?? []);

        $profile = (new CompanyProfile())->get();
        $hasDescription = $profile && trim($profile['company_description'] ?? '') !== '';
        $hasCompanyName = $profile && trim($profile['company_name'] ?? '') !== '';
        $hasIndustry = $profile && trim($profile['company_industry'] ?? '') !== '';
        $hasLocation = $profile && (trim($profile['company_location'] ?? '') !== '' || trim($profile['company_address'] ?? '') !== '');
        $products = (new Products())->list();
        $hasProductWithPricing = false;
        $productCount = count($products);
        foreach ($products as $p) {
            if (trim($p['pricing_info'] ?? '') !== '') {
                $hasProductWithPricing = true;
                break;
            }
        }
        $profileComplete = $hasCompanyName && $hasDescription && $hasIndustry;
        $serviceCatalogReady = $productCount >= 1 && $hasProductWithPricing;

        $contactCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM contacts")['count'];
        $firstContactAdded = $contactCount >= 1;
        $contactImportReady = $contactCount >= 3;

        $taskCount = (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM tasks WHERE created_by = ? OR assigned_to = ?",
            [$userId, $userId]
        )['count'];
        $firstTaskCreated = $taskCount >= 1;
        $taskOwnershipReady = (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM tasks WHERE assigned_to IS NOT NULL"
        )['count'] > 0;

        $dealCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM deals")['count'];
        $firstDealCreated = $dealCount >= 1;

        $workflowCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM workflows WHERE is_active = 1")['count'];
        $firstWorkflowActivated = $workflowCount >= 1;
        $followUpWorkflowReady = $firstWorkflowActivated || (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM tasks WHERE contact_id IS NOT NULL AND due_date IS NOT NULL"
        )['count'] > 0;

        $channelsReady = $this->hasOutboundEmailConfigured() || $this->hasWhatsAppConfigured();
        $digestReady = $this->hasDigestReadiness();

        $legacyProfileComplete = $profileComplete && $serviceCatalogReady;
        $readinessScore = 0;
        foreach ([
            $profileComplete,
            $serviceCatalogReady,
            $contactImportReady,
            $firstDealCreated,
            $taskOwnershipReady,
            $followUpWorkflowReady,
            $channelsReady,
            $digestReady,
        ] as $flag) {
            if ($flag) {
                $readinessScore++;
            }
        }

        $items = [
            ['key' => 'company_profile_complete', 'label' => 'Business profile basics saved', 'done' => $profileComplete, 'hint' => $nicheHints['company_profile_complete'] ?? 'Company name, description, and industry are required.'],
            ['key' => 'service_catalog_ready', 'label' => 'Services and pricing saved', 'done' => $serviceCatalogReady, 'hint' => $nicheHints['service_catalog_ready'] ?? 'Add at least one service/product with pricing context.'],
            ['key' => 'contact_import_ready', 'label' => 'First leads or customers added', 'done' => $contactImportReady, 'hint' => $nicheHints['contact_import_ready'] ?? 'Import or create at least 3 sample contacts for onboarding.'],
            ['key' => 'first_deal_created', 'label' => 'First opportunity created', 'done' => $firstDealCreated, 'hint' => 'Create one live or sample opportunity.'],
            ['key' => 'task_ownership_ready', 'label' => 'Follow-up owner assigned', 'done' => $taskOwnershipReady, 'hint' => $nicheHints['task_ownership_ready'] ?? 'Assign at least one task to a named owner.'],
            ['key' => 'follow_up_workflow_ready', 'label' => 'Follow-up workflow active', 'done' => $followUpWorkflowReady, 'hint' => $nicheHints['follow_up_workflow_ready'] ?? 'Use either a workflow or due-dated follow-up tasks.'],
            ['key' => 'channels_ready', 'label' => 'Email or WhatsApp setup checked', 'done' => $channelsReady, 'hint' => $nicheHints['channels_ready'] ?? 'One customer communication channel should be configured.'],
            ['key' => 'digest_ready', 'label' => 'Digest / assistant ready', 'done' => $digestReady, 'hint' => $nicheHints['digest_ready'] ?? 'Enable digest delivery when using the Growth package.'],
        ];

        return [
            'profile_complete' => $legacyProfileComplete,
            'first_contact_added' => $firstContactAdded,
            'first_task_created' => $firstTaskCreated,
            'first_deal_created' => $firstDealCreated,
            'first_workflow_activated' => $firstWorkflowActivated,
            'all_complete' => $readinessScore === count($items),
            'readiness_score' => $readinessScore,
            'readiness_total' => count($items),
            'active_package' => (string) ($launch['active_package'] ?? ClarityPackageCatalog::PACKAGE_CORE),
            'target_niche' => $niche['key'],
            'target_niche_label' => $niche['label'],
            'recommended_stage_mapping' => $niche['stage_mapping'],
            'items' => $items,
        ];
    }

    /**
     * Get progress as a formatted string for AI prompt.
     */
    public function getContextForPrompt(int $userId): string
    {
        $p = $this->getProgress($userId);
        $lines = [];
        foreach ($p['items'] as $item) {
            $lines[] = '  - ' . $item['label'] . ': ' . ($item['done'] ? 'done' : 'pending');
        }
        return empty($lines) ? '' : "ONBOARDING PROGRESS:\n" . implode("\n", $lines);
    }

    private function hasOutboundEmailConfigured(): bool
    {
        try {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $email = new EmailIntegrationService();
            return $email->isStrictRoleOutboundReady('outreach', $workspaceId > 0 ? $workspaceId : null)
                || $email->isStrictRoleOutboundReady('nurture', $workspaceId > 0 ? $workspaceId : null);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasWhatsAppConfigured(): bool
    {
        return trim((string) ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '')) !== ''
            && trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')) !== '';
    }

    private function hasDigestReadiness(): bool
    {
        $digestTime = trim((string) ($_ENV['EMAIL_DIGEST_TIME'] ?? ''));
        $customRecipients = trim((string) ($_ENV['EMAIL_DIGEST_RECIPIENTS'] ?? ''));

        return $digestTime !== '' || $customRecipients !== '' || $this->hasOutboundEmailConfigured();
    }
}
