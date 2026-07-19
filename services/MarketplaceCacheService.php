<?php

namespace CRM\Services;

use CRM\CacheManager;

class MarketplaceCacheService
{
    private const SURFACES = ['marketplace', 'clarity_chat', 'coach'];

    private CacheManager $cache;

    public function __construct(?CacheManager $cache = null)
    {
        $this->cache = $cache ?? new CacheManager();
    }

    public static function catalogStatusKey(int $workspaceId, int $userId): string
    {
        return 'marketplace:v2:catalog_status:' . $workspaceId . ':' . $userId;
    }

    public static function recommendationsKey(int $workspaceId, int $userId, string $surface): string
    {
        return 'marketplace:v2:recommendations:' . $workspaceId . ':' . $userId . ':' . self::normalizeSurface($surface);
    }

    public static function activationBundlesKey(int $workspaceId, int $userId, string $surface): string
    {
        return 'marketplace:v2:activation_bundles:' . $workspaceId . ':' . $userId . ':' . self::normalizeSurface($surface);
    }

    public static function moduleDetailKey(int $workspaceId, int $userId, string $skillKey): string
    {
        return 'marketplace:v2:module_detail:' . $workspaceId . ':' . $userId . ':' . self::normalizeKey($skillKey);
    }

    public static function performanceKey(int $workspaceId, string $skillKey): string
    {
        return 'marketplace:v2:performance:' . $workspaceId . ':' . self::normalizeKey($skillKey);
    }

    public function invalidateAfterMutation(int $workspaceId, int $userId, string $skillKey = ''): void
    {
        if ($workspaceId <= 0) {
            return;
        }

        $this->cache->delete(self::catalogStatusKey($workspaceId, $userId));

        foreach (self::SURFACES as $surface) {
            $this->cache->delete(self::recommendationsKey($workspaceId, $userId, $surface));
            $this->cache->delete(self::activationBundlesKey($workspaceId, $userId, $surface));
        }

        $skillKey = self::normalizeKey($skillKey);
        if ($skillKey !== '') {
            $this->cache->delete(self::moduleDetailKey($workspaceId, $userId, $skillKey));
            $this->cache->delete(self::performanceKey($workspaceId, $skillKey));
        }
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private static function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $surface) ?? ''));
        return in_array($surface, self::SURFACES, true) ? $surface : 'marketplace';
    }
}
