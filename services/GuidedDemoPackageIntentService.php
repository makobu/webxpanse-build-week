<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class GuidedDemoPackageIntentService
{
    public const PACKAGE_COMPASS_FREE = 'compass-free';
    public const PACKAGE_SOLO_LAUNCH = 'solo-launch';
    public const PACKAGE_FOUNDER_PLUS = 'founder-plus';
    public const PACKAGE_GROWTH_STUDIO = 'growth-studio';
    public const PACKAGE_SCALE_CUSTOM = 'scale-custom';

    /**
     * @return list<array<string,mixed>>
     */
    public function packages(int $workspaceId): array
    {
        return (new LaunchPackageCatalogService())->cardsFromSubscriptionPrices(array_values($this->launchPricesByCode($workspaceId)), true);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function launchPricesByCode(int $workspaceId): array
    {
        $prices = [];
        try {
            foreach ((new SaaSBillingService())->listSubscriptionPrices($workspaceId > 0 ? $workspaceId : null) as $price) {
                $code = (string) ($price['price_code'] ?? '');
                if ($code !== '') {
                    $prices[$code] = $price;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $prices;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function recordIntent(
        int $workspaceId,
        int $userId,
        ?int $sessionId,
        string $packageCode,
        string $status = 'requested',
        string $contactMethod = '',
        string $notes = '',
        array $metadata = []
    ): int {
        if (!$this->isAllowedPackageCode($packageCode)) {
            throw new \InvalidArgumentException('Unknown package selection.');
        }
        if (!in_array($status, ['requested', 'checkout_started', 'completed', 'cancelled'], true)) {
            $status = 'requested';
        }

        Database::execute(
            "INSERT INTO guided_demo_package_intents
                (workspace_id, user_id, session_id, package_code, status, contact_method, notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId,
                $sessionId,
                $packageCode,
                $status,
                trim($contactMethod) !== '' ? trim($contactMethod) : null,
                trim($notes) !== '' ? trim($notes) : null,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function isAllowedPackageCode(string $packageCode): bool
    {
        if (in_array($packageCode, [
            self::PACKAGE_COMPASS_FREE,
            self::PACKAGE_SOLO_LAUNCH,
            self::PACKAGE_FOUNDER_PLUS,
            self::PACKAGE_GROWTH_STUDIO,
            self::PACKAGE_SCALE_CUSTOM,
        ], true)) {
            return true;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $packageCode);
    }

    /**
     * @param list<string> $completedActions
     * @return array<string,mixed>
     */
    public function recommendationForSession(?array $session, array $completedActions = []): array
    {
        $status = (string) (($session['status'] ?? '') ?: '');
        $cleanupStatus = (string) (($session['cleanup_status'] ?? '') ?: '');

        $code = self::PACKAGE_SOLO_LAUNCH;
        $reason = 'Solo Launch is the cleanest paid starting point for a one-person founder who wants structure and AI help.';

        if ($status === 'completed' && $cleanupStatus === 'succeeded') {
            $code = self::PACKAGE_FOUNDER_PLUS;
            $reason = 'You completed the founder launch journey, so Founder Plus is the strongest next step for a serious operating rhythm.';
        } elseif ($status === 'active' && count($completedActions) >= 4) {
            $code = self::PACKAGE_FOUNDER_PLUS;
            $reason = 'You have already worked through most of the launch loop, so Founder Plus fits better than the entry tier.';
        }

        return [
            'package_code' => $code,
            'label' => 'Recommended: ' . match ($code) {
                self::PACKAGE_FOUNDER_PLUS => 'Founder Plus',
                self::PACKAGE_GROWTH_STUDIO => 'Growth Studio',
                default => 'Solo Launch',
            },
            'reason' => $reason,
            'operator_available' => false,
            'completed_action_count' => count($completedActions),
        ];
    }
}
