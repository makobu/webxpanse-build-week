<?php

namespace CRM\Services;

use CRM\Auth;
use RuntimeException;
use Throwable;

class MarketingMarketplaceGateService
{
    public const SKILL_KEY = WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER;
    public const FEATURE_OVERVIEW = 'overview';
    public const FEATURE_SOCIAL_MEDIA = 'social_media';
    public const FEATURE_DESIGN = 'design';
    public const FEATURE_MARKETING_PRO = 'marketing_pro';

    private const VISIBLE_MENU_PLUGIN_KEYS = [
        WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
        WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
        WorkspaceSkillCatalogService::PLUGIN_DESIGN,
    ];

    private const FEATURE_KEYS = [
        self::FEATURE_SOCIAL_MEDIA => [WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA],
        self::FEATURE_DESIGN => [WorkspaceSkillCatalogService::PLUGIN_DESIGN],
        self::FEATURE_MARKETING_PRO => [WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO, self::SKILL_KEY],
        self::FEATURE_OVERVIEW => [
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            self::SKILL_KEY,
        ],
    ];

    private WorkspaceSkillInstallService $installer;

    public function __construct(?WorkspaceSkillInstallService $installer = null)
    {
        $this->installer = $installer ?? new WorkspaceSkillInstallService();
    }

    public function accessForUser(?array $user = null, ?string $feature = null): array
    {
        $user = $user ?? (Auth::user() ?: []);
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        $feature = $this->normalizeFeature($feature);

        if ($workspaceId <= 0) {
            return [
                'can_run' => false,
                'message' => 'Select a workspace before opening Marketing.',
                'next_action_url' => 'workspaces.php',
                'next_action_label' => 'Open workspaces',
            ];
        }

        try {
            $accessService = new WorkspaceMarketplaceAccessService();
            $fallback = null;
            foreach ($this->keysForFeature($feature) as $skillKey) {
                $access = $accessService->accessForModule($workspaceId, $userId, $skillKey);
                if (!empty($access['can_run'])) {
                    $access['marketing_feature'] = $feature;
                    $access['marketing_gate_skill_key'] = $skillKey;
                    return $access;
                }
                $fallback ??= $access;
            }

            return $fallback ?: [
                'can_run' => false,
                'message' => 'Install a Marketing plugin before opening this workspace area.',
                'next_action_url' => $this->marketplaceUrl($feature),
                'next_action_label' => 'Open Marketplace',
            ];
        } catch (Throwable $e) {
            return [
                'can_run' => false,
                'message' => 'Marketing marketplace access could not be verified.',
                'next_action_url' => $this->marketplaceUrl($feature),
                'next_action_label' => 'Open Marketplace',
            ];
        }
    }

    public function canRun(?array $user = null, ?string $feature = null): bool
    {
        $access = $this->accessForUser($user, $feature);
        return !empty($access['can_run']);
    }

    public function hasInstalledVisibleMenuPlugin(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        try {
            foreach (self::VISIBLE_MENU_PLUGIN_KEYS as $skillKey) {
                if ($this->installer->isInstalled($workspaceId, $skillKey)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    public function hasInstalledFeaturePlugin(int $workspaceId, string $feature): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        $feature = $this->normalizeFeature($feature);
        if ($feature === self::FEATURE_OVERVIEW) {
            return $this->hasInstalledVisibleMenuPlugin($workspaceId);
        }

        try {
            return $this->installer->isInstalled($workspaceId, $this->primarySkillKeyForFeature($feature));
        } catch (Throwable $e) {
            return false;
        }
    }

    public function assertCanRun(?array $user = null, ?string $feature = null): void
    {
        $access = $this->accessForUser($user, $feature);
        if (!empty($access['can_run'])) {
            return;
        }

        $message = trim((string) ($access['message'] ?? 'Marketing is not available for this workspace yet.'));
        $nextAction = trim((string) ($access['next_action_label'] ?? ''));
        if ($nextAction !== '') {
            $message .= ' Next action: ' . $nextAction . '.';
        }

        throw new RuntimeException($message);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function navigationStateForUser(?array $user = null): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $features = [
            self::FEATURE_OVERVIEW,
            self::FEATURE_MARKETING_PRO,
            self::FEATURE_SOCIAL_MEDIA,
            self::FEATURE_DESIGN,
        ];
        $state = [];

        foreach ($features as $feature) {
            $access = $this->accessForUser($user, $feature);
            $canRun = !empty($access['can_run']);
            $skillKey = $canRun
                ? (string) ($access['marketing_gate_skill_key'] ?? $this->primarySkillKeyForFeature($feature))
                : $this->primarySkillKeyForFeature($feature);

            $state[$feature] = array_merge($access, [
                'can_run' => $canRun,
                'menu_visible' => $this->hasInstalledFeaturePlugin($workspaceId, $feature),
                'feature' => $feature,
                'skill_key' => $skillKey,
                'setup_url' => $this->marketplaceUrl($feature) . '#setup',
                'setup_label' => 'Set up ' . $this->labelForFeature($feature),
            ]);
        }

        return $state;
    }

    public function marketplaceUrl(?string $feature = null): string
    {
        $feature = $this->normalizeFeature($feature);
        $skillKey = match ($feature) {
            self::FEATURE_SOCIAL_MEDIA => WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            self::FEATURE_DESIGN => WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            self::FEATURE_MARKETING_PRO => WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            default => WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
        };

        return 'workspace_skills.php?module=' . rawurlencode($skillKey);
    }

    public function featureForPage(?string $page): string
    {
        $page = strtolower(trim((string) $page));
        $page = basename(str_replace('\\', '/', $page));
        return match ($page) {
            'design.php',
            'marketing_assets.php',
            'marketing_brand.php',
            'marketing_creative.php',
            'marketing_landing_pages.php',
            'marketing_landing_page_edit.php',
            'marketing_landing_page_preview.php',
            'marketing_landing_page_view.php',
            'marketing_landing_public.php',
            'marketing_seo.php',
            'forms.php',
            'form_edit.php',
            'form_delete.php',
            'form_asset_upload.php',
            'form_submissions.php',
            'email_signatures.php',
            'email_signature_create.php',
            'email_signature_edit.php' => self::FEATURE_DESIGN,

            'social_media.php',
            'marketing_calendar.php',
            'marketing_channel_exports.php',
            'marketing_content.php',
            'marketing_content_edit.php',
            'marketing_content_view.php',
            'marketing_distribution.php',
            'marketing_distribution_bundle.php',
            'marketing_email_runs.php',
            'marketing_operator_export_packs.php',
            'marketing_quality.php',
            'marketing_reviews.php',
            'marketing_utm_links.php' => self::FEATURE_SOCIAL_MEDIA,

            'marketing.php',
            'marketing_campaign_kit.php',
            'marketing_assistants.php' => self::FEATURE_MARKETING_PRO,

            default => self::FEATURE_MARKETING_PRO,
        };
    }

    private function normalizeFeature(?string $feature): string
    {
        $feature = strtolower(trim((string) $feature));
        return in_array($feature, [self::FEATURE_SOCIAL_MEDIA, self::FEATURE_DESIGN, self::FEATURE_MARKETING_PRO, self::FEATURE_OVERVIEW], true)
            ? $feature
            : self::FEATURE_OVERVIEW;
    }

    private function keysForFeature(string $feature): array
    {
        return self::FEATURE_KEYS[$feature] ?? self::FEATURE_KEYS[self::FEATURE_OVERVIEW];
    }

    private function primarySkillKeyForFeature(string $feature): string
    {
        return match ($feature) {
            self::FEATURE_SOCIAL_MEDIA => WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            self::FEATURE_DESIGN => WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            default => WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
        };
    }

    private function labelForFeature(string $feature): string
    {
        return match ($feature) {
            self::FEATURE_SOCIAL_MEDIA => 'Social Media',
            self::FEATURE_DESIGN => 'Design',
            self::FEATURE_MARKETING_PRO => 'Campaign Manager',
            default => 'Campaign Manager',
        };
    }
}
