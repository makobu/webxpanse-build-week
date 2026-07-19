<?php

namespace CRM\Services;

use CRM\Database;
use InvalidArgumentException;

class WorkspaceScoringConfigService
{
    public const DEFAULT_WEIGHTS = [
        'engagement' => 0.4,
        'ml' => 0.4,
        'ai' => 0.2,
    ];

    private const REQUIRED_KEYS = ['engagement', 'ml', 'ai'];
    private const WEIGHT_TOLERANCE = 0.01;

    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct(?AnalyticsWorkspaceService $analyticsWorkspace = null)
    {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }

    /**
     * @return array{workspace_id:int, default_score_weights:array<string,float>, auto_use_recommended_weights:bool}
     */
    public function getConfig(?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);

        if (Database::tableExists('workspace_scoring_config')) {
            $row = Database::queryOne(
                "SELECT default_score_weights, auto_use_recommended_weights
                 FROM workspace_scoring_config
                 WHERE workspace_id = ?
                 LIMIT 1",
                [$resolvedWorkspaceId]
            );

            if (!$row) {
                $legacy = $this->legacyDefaults();
                $this->saveConfig($resolvedWorkspaceId, $legacy['default_score_weights'], $legacy['auto_use_recommended_weights']);

                return [
                    'workspace_id' => $resolvedWorkspaceId,
                    'default_score_weights' => $legacy['default_score_weights'],
                    'auto_use_recommended_weights' => $legacy['auto_use_recommended_weights'],
                ];
            }

            return [
                'workspace_id' => $resolvedWorkspaceId,
                'default_score_weights' => $this->decodeWeights((string) ($row['default_score_weights'] ?? '')),
                'auto_use_recommended_weights' => (bool) ($row['auto_use_recommended_weights'] ?? false),
            ];
        }

        $legacy = $this->legacyDefaults();

        return [
            'workspace_id' => $resolvedWorkspaceId,
            'default_score_weights' => $legacy['default_score_weights'],
            'auto_use_recommended_weights' => $legacy['auto_use_recommended_weights'],
        ];
    }

    public function saveConfig(int $workspaceId, array $defaultWeights, bool $autoUseRecommended): void
    {
        $weights = self::validateWeights($defaultWeights);

        if (Database::tableExists('workspace_scoring_config')) {
            Database::execute(
                "INSERT INTO workspace_scoring_config
                    (workspace_id, default_score_weights, auto_use_recommended_weights)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    default_score_weights = VALUES(default_score_weights),
                    auto_use_recommended_weights = VALUES(auto_use_recommended_weights),
                    updated_at = CURRENT_TIMESTAMP",
                [$workspaceId, json_encode($weights), $autoUseRecommended ? 1 : 0]
            );
        }

        if (Database::tableExists('enrichment_config') && Database::columnExists('enrichment_config', 'default_score_weights')) {
            Database::execute(
                "UPDATE enrichment_config SET default_score_weights = ? WHERE id = 1",
                [json_encode($weights)]
            );
        }
    }

    /**
     * @return array<string,float>
     */
    public static function validateWeights(array $weights): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $weights)) {
                throw new InvalidArgumentException("Missing {$key} scoring weight.");
            }

            if (!is_numeric($weights[$key])) {
                throw new InvalidArgumentException("Scoring weight {$key} must be numeric.");
            }

            $weights[$key] = (float) $weights[$key];

            if ($weights[$key] < 0.0 || $weights[$key] > 1.0) {
                throw new InvalidArgumentException("Scoring weight {$key} must be between 0 and 1.");
            }
        }

        $sum = (float) $weights['engagement'] + (float) $weights['ml'] + (float) $weights['ai'];
        if (abs($sum - 1.0) > self::WEIGHT_TOLERANCE) {
            throw new InvalidArgumentException('Scoring weights must sum to 1.0.');
        }

        return [
            'engagement' => round((float) $weights['engagement'], 4),
            'ml' => round((float) $weights['ml'], 4),
            'ai' => round((float) $weights['ai'], 4),
        ];
    }

    /**
     * @return array{default_score_weights:array<string,float>, auto_use_recommended_weights:bool}
     */
    private function legacyDefaults(): array
    {
        $weights = self::DEFAULT_WEIGHTS;

        if (Database::tableExists('enrichment_config') && Database::columnExists('enrichment_config', 'default_score_weights')) {
            $config = Database::queryOne("SELECT default_score_weights FROM enrichment_config WHERE id = 1");
            if (!empty($config['default_score_weights'])) {
                $weights = $this->decodeWeights((string) $config['default_score_weights']);
            }
        }

        return [
            'default_score_weights' => $weights,
            'auto_use_recommended_weights' => $this->envBool('AUTO_USE_RECOMMENDED_WEIGHTS'),
        ];
    }

    /**
     * @return array<string,float>
     */
    private function decodeWeights(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_WEIGHTS;
        }

        try {
            return self::validateWeights($decoded);
        } catch (InvalidArgumentException $e) {
            return self::DEFAULT_WEIGHTS;
        }
    }

    private function envBool(string $key): bool
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
