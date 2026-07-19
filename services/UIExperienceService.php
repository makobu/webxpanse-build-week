<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Modules\UserPreferences;

class UIExperienceService
{
    public const PREFERENCE_KEY = 'ui_experience_mode';
    public const MODE_BEGINNER = 'beginner';
    public const MODE_ADVANCED = 'advanced';

    /**
     * @param array<string,mixed>|null $user
     */
    public function modeForUser(?array $user = null, ?int $workspaceId = null): string
    {
        $user = is_array($user) ? $user : [];
        $userId = (int) ($user['id'] ?? 0);

        if ($userId > 0) {
            $preference = (new UserPreferences())->getPreference($userId, self::PREFERENCE_KEY);
            if ($this->isValidMode($preference)) {
                return (string) $preference;
            }
        }

        if (Authorization::isSuperAdmin($user)) {
            return self::MODE_ADVANCED;
        }

        if ($workspaceId !== null && $workspaceId > 0 && WorkspaceContext::isDefaultWorkspace($workspaceId)) {
            return self::MODE_ADVANCED;
        }

        return self::MODE_BEGINNER;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    public function isBeginner(?array $user = null, ?int $workspaceId = null): bool
    {
        return $this->modeForUser($user, $workspaceId) === self::MODE_BEGINNER;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    public function shouldShowAdvancedMarketingNavigation(
        ?array $user,
        ?int $workspaceId,
        string $currentPage,
        bool $canMarketingWrite = false,
        bool $canMarketingManage = false
    ): bool {
        if ($this->modeForUser($user, $workspaceId) === self::MODE_ADVANCED) {
            return true;
        }

        if ($canMarketingWrite || $canMarketingManage) {
            return true;
        }

        return MarketingUi::isInternalPage($currentPage);
    }

    public function marketplaceLabel(string $mode): string
    {
        return $mode === self::MODE_BEGINNER ? 'Add capabilities' : 'Marketplace';
    }

    private function isValidMode(?string $mode): bool
    {
        return in_array($mode, [self::MODE_BEGINNER, self::MODE_ADVANCED], true);
    }
}
