<?php
/**
 * AI Lead Scoring Module
 * Three-Score System: Engagement (rule/data-driven), ML (model-backed), AI (insight-based)
 * Combines persisted component scores into a canonical lead_score.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\EventBus;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MLPredictionService;
use CRM\Services\MLExplainabilityService;
use CRM\Services\WorkspaceScoringConfigService;
use InvalidArgumentException;

class AILeadScoring
{
    public const DEFAULT_WEIGHTS = WorkspaceScoringConfigService::DEFAULT_WEIGHTS;

    private const FALLBACK_POLICY = 'unavailable component weight moves to engagement; if only one component is available it receives weight 1.0';

    private LeadScoring $engagementScoring;
    private MLPredictionService $mlPredictionService;
    private MLExplainabilityService $explainabilityService;
    private AIScoreCalculator $aiScoreCalculator;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private WorkspaceScoringConfigService $workspaceScoringConfig;

    public function __construct(
        ?LeadScoring $engagementScoring = null,
        ?MLPredictionService $mlPredictionService = null,
        ?MLExplainabilityService $explainabilityService = null,
        ?AIScoreCalculator $aiScoreCalculator = null,
        ?AnalyticsWorkspaceService $analyticsWorkspace = null,
        ?WorkspaceScoringConfigService $workspaceScoringConfig = null
    ) {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
        $this->engagementScoring = $engagementScoring ?? new LeadScoring($this->analyticsWorkspace);
        $this->mlPredictionService = $mlPredictionService ?? new MLPredictionService(null, null, null, $this->analyticsWorkspace);
        $this->explainabilityService = $explainabilityService ?? new MLExplainabilityService();
        $this->aiScoreCalculator = $aiScoreCalculator ?? new AIScoreCalculator();
        $this->workspaceScoringConfig = $workspaceScoringConfig ?? new WorkspaceScoringConfigService($this->analyticsWorkspace);
    }

    public static function validateWeights(?array $weights): array
    {
        if ($weights === null) {
            throw new InvalidArgumentException('Scoring weights are required.');
        }

        return WorkspaceScoringConfigService::validateWeights($weights);
    }

    /**
     * Backward-compatible entry point returning the persisted composite score.
     */
    public function calculateScore(int $contactId, string $modelType = 'conversion', ?array $weights = null): int
    {
        $breakdown = $this->recalculateScore($contactId, $modelType, $weights, 'legacy_calculate_score');

        return (int) ($breakdown['consolidated_score'] ?? 0);
    }

    /**
     * Dedicated write path for contact score recalculation.
     *
     * @return array<string,mixed>
     */
    public function recalculateScore(
        int $contactId,
        string $modelType = 'conversion',
        ?array $weights = null,
        string $source = 'manual'
    ): array {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);

        $previousScores = Database::queryOne(
            "SELECT lead_score, ml_score
             FROM contacts
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        ) ?? [];

        $previousComposite = (int) ($previousScores['lead_score'] ?? 0);
        $previousMLScore = $previousScores['ml_score'] === null ? null : (float) $previousScores['ml_score'];

        $engagementResult = $this->calculateEngagementScore($contactId);
        $mlResult = $this->calculateMLScore($contactId, $modelType);
        $aiResult = $this->calculateAIScore($contactId, $workspaceId);

        $engagementScore = $this->capScore((int) ($engagementResult['score'] ?? 0));
        $mlScore = $mlResult['score'] === null ? null : $this->capScore((int) $mlResult['score']);
        $aiScore = $this->capScore((int) ($aiResult['score'] ?? 0));

        $availability = [
            'engagement' => true,
            'ml' => !empty($mlResult['available']) && $mlScore !== null,
            'ai' => !empty($aiResult['available']),
        ];

        $recommendedWeights = $this->getRecommendedWeightsForAvailability($availability);
        $weightResolution = $this->resolveRequestedWeights($contactId, $workspaceId, $weights, $recommendedWeights);
        $requestedWeights = $weightResolution['weights'];
        $effectiveWeights = $this->redistributeUnavailableWeights($requestedWeights, $availability);

        $consolidatedScore = $this->calculateConsolidatedScore(
            $engagementScore,
            $mlScore,
            $aiScore,
            $effectiveWeights
        );

        $metadata = [
            'source' => $source,
            'model_type' => $modelType,
            'last_recalculated_at' => date('c'),
            'component_availability' => $availability,
            'ml_available' => $availability['ml'],
            'ml_fallback_used' => !empty($mlResult['fallback']),
            'requested_weights' => $requestedWeights,
            'requested_weights_source' => $weightResolution['source'],
            'effective_weights' => $effectiveWeights,
            'fallback_policy' => self::FALLBACK_POLICY,
            'components' => [
                'engagement' => $engagementResult,
                'ml' => $mlResult,
                'ai' => $aiResult,
            ],
        ];

        Database::execute(
            "UPDATE contacts
             SET lead_score = ?,
                 engagement_score = ?,
                 ml_score = ?,
                 ai_score = ?,
                 score_weights = ?,
                 recommended_weights = ?,
                 score_recalculated_at = CURRENT_TIMESTAMP,
                 score_metadata_json = ?
             WHERE workspace_id = ? AND id = ?",
            [
                $consolidatedScore,
                $engagementScore,
                $mlScore,
                $aiScore,
                $weightResolution['store_explicit_weights'] ? json_encode($requestedWeights) : null,
                json_encode($recommendedWeights),
                json_encode($metadata),
                $workspaceId,
                $contactId,
            ]
        );

        $this->publishMLScoreEvents($contactId, $workspaceId, $previousMLScore, $mlScore, $mlResult);
        $this->publishCompositeScoreEvent(
            $contactId,
            $workspaceId,
            $previousComposite,
            $consolidatedScore,
            [
                'engagement' => $engagementScore,
                'ml' => $mlScore,
                'ai' => $aiScore,
            ],
            $source,
            $metadata
        );

        return $this->buildBreakdown([
            'id' => $contactId,
            'lead_score' => $consolidatedScore,
            'engagement_score' => $engagementScore,
            'ml_score' => $mlScore,
            'ai_score' => $aiScore,
            'score_weights' => $weightResolution['store_explicit_weights'] ? json_encode($requestedWeights) : null,
            'recommended_weights' => json_encode($recommendedWeights),
            'score_recalculated_at' => date('Y-m-d H:i:s'),
            'score_metadata_json' => json_encode($metadata),
            'ai_context' => null,
            'ml_score_confidence' => $mlResult['confidence'] ?? null,
        ]);
    }

    /**
     * Calculate engagement-based score without mutating the canonical lead_score.
     *
     * @return array<string,mixed>
     */
    public function calculateEngagementScore(int $contactId): array
    {
        return $this->engagementScoring->calculateEngagementScore($contactId);
    }

    /**
     * Calculate ML score when a real active model is available.
     *
     * @return array<string,mixed>
     */
    public function calculateMLScore(int $contactId, string $modelType = 'conversion'): array
    {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);

        try {
            $prediction = $this->mlPredictionService->predictConversion($contactId, $modelType, $workspaceId);

            if (!empty($prediction['fallback']) || empty($prediction['model_id'])) {
                return [
                    'score' => null,
                    'available' => false,
                    'fallback' => true,
                    'confidence' => $prediction['confidence'] ?? 0.0,
                    'probability' => $prediction['probability'] ?? null,
                    'reason' => 'No active ML model is available for this workspace.',
                ];
            }

            return [
                'score' => $this->capScore((int) round((float) ($prediction['prediction_score'] ?? 0))),
                'available' => true,
                'fallback' => false,
                'confidence' => (float) ($prediction['confidence'] ?? 0.0),
                'probability' => $prediction['probability'] ?? null,
                'model_id' => $prediction['model_id'] ?? null,
                'model_version' => $prediction['model_version'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log("ML scoring unavailable for contact {$contactId}: " . $e->getMessage());

            return [
                'score' => null,
                'available' => false,
                'fallback' => true,
                'confidence' => 0.0,
                'probability' => null,
                'reason' => 'ML scoring failed or is unavailable.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate AI score (insight-based) without persisting it directly.
     *
     * @return array<string,mixed>
     */
    public function calculateAIScore(int $contactId, ?int $workspaceId = null): array
    {
        return $this->aiScoreCalculator->calculateAIScore($contactId, $workspaceId, false);
    }

    /**
     * Read-only score breakdown from persisted contact scoring fields.
     *
     * @return array<string,mixed>
     */
    public function getScoreBreakdown(int $contactId, string $modelType = 'conversion'): array
    {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);
        $contact = Database::queryOne(
            "SELECT id, lead_score, engagement_score, ml_score, ai_score,
                    score_weights, recommended_weights, score_recalculated_at,
                    score_metadata_json, ai_context, ml_score_confidence
             FROM contacts
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );

        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        return $this->buildBreakdown($contact);
    }

    /**
     * @return array<int|string,int>
     */
    public function calculateBatchScores(array $contactIds, string $modelType = 'conversion'): array
    {
        $scores = [];

        foreach ($contactIds as $contactId) {
            try {
                $scores[$contactId] = $this->calculateScore((int) $contactId, $modelType);
            } catch (\Throwable $e) {
                error_log("Error calculating score for contact {$contactId}: " . $e->getMessage());
                $scores[$contactId] = 0;
            }
        }

        return $scores;
    }

    /**
     * @return array<string,int>
     */
    public function recalculateAllScores(string $modelType = 'conversion', int $limit = 1000): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contacts = Database::query(
            "SELECT id FROM contacts
             WHERE workspace_id = ?
             ORDER BY updated_at DESC
             LIMIT ?",
            [$workspaceId, $limit]
        );

        $processed = 0;
        $errors = 0;

        foreach ($contacts as $contact) {
            try {
                $this->recalculateScore((int) $contact['id'], $modelType, null, 'bulk_recalculation');
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                error_log("Error recalculating score for contact {$contact['id']}: " . $e->getMessage());
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'total' => count($contacts),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getScoringStatistics(): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $stats = Database::queryOne(
            "SELECT
                COUNT(*) as total_contacts,
                AVG(lead_score) as avg_consolidated_score,
                AVG(engagement_score) as avg_engagement_score,
                AVG(ml_score) as avg_ml_score,
                AVG(ai_score) as avg_ai_score,
                MIN(lead_score) as min_score,
                MAX(lead_score) as max_score,
                COUNT(CASE WHEN lead_score >= 70 THEN 1 END) as high_score_count,
                COUNT(CASE WHEN lead_score >= 50 AND lead_score < 70 THEN 1 END) as medium_score_count,
                COUNT(CASE WHEN lead_score < 50 THEN 1 END) as low_score_count,
                COUNT(CASE WHEN ml_score IS NOT NULL THEN 1 END) as ml_scored_count,
                COUNT(CASE WHEN ai_score > 0 THEN 1 END) as ai_scored_count
             FROM contacts
             WHERE workspace_id = ?",
            [$workspaceId]
        );

        $total = (int) ($stats['total_contacts'] ?? 0);

        return [
            'total_contacts' => $total,
            'average_score' => round((float) ($stats['avg_consolidated_score'] ?? 0), 2),
            'average_consolidated_score' => round((float) ($stats['avg_consolidated_score'] ?? 0), 2),
            'average_engagement_score' => round((float) ($stats['avg_engagement_score'] ?? 0), 2),
            'average_ml_score' => round((float) ($stats['avg_ml_score'] ?? 0), 2),
            'average_ai_score' => round((float) ($stats['avg_ai_score'] ?? 0), 2),
            'min_score' => (int) ($stats['min_score'] ?? 0),
            'max_score' => (int) ($stats['max_score'] ?? 0),
            'high_score_count' => (int) ($stats['high_score_count'] ?? 0),
            'medium_score_count' => (int) ($stats['medium_score_count'] ?? 0),
            'low_score_count' => (int) ($stats['low_score_count'] ?? 0),
            'ml_scored_count' => (int) ($stats['ml_scored_count'] ?? 0),
            'ai_scored_count' => (int) ($stats['ai_scored_count'] ?? 0),
            'ml_coverage' => $total > 0 ? round(((int) ($stats['ml_scored_count'] ?? 0) / $total) * 100, 1) : 0,
            'ai_coverage' => $total > 0 ? round(((int) ($stats['ai_scored_count'] ?? 0) / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get weights for a contact. Explicit contact weights win; otherwise workspace
     * defaults or workspace auto-recommended weights are used.
     *
     * @return array<string,float>
     */
    public function getWeights(int $contactId): array
    {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);
        $contact = Database::queryOne(
            "SELECT score_weights FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );

        if (!empty($contact['score_weights'])) {
            $decoded = json_decode((string) $contact['score_weights'], true);
            if (is_array($decoded)) {
                try {
                    return self::validateWeights($decoded);
                } catch (InvalidArgumentException $e) {
                    // Invalid stored overrides fall through to workspace config.
                }
            }
        }

        $config = $this->workspaceScoringConfig->getConfig($workspaceId);
        if (!empty($config['auto_use_recommended_weights'])) {
            return $this->getRecommendedWeights($contactId);
        }

        return $config['default_score_weights'];
    }

    /**
     * @return array<string,float>
     */
    public function getRecommendedWeights(int $contactId): array
    {
        $workspaceId = $this->assertActiveWorkspaceContact($contactId);
        $contact = Database::queryOne(
            "SELECT engagement_score, ml_score, ai_score, ai_context, recommended_weights, score_metadata_json
             FROM contacts
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );

        if (!empty($contact['recommended_weights'])) {
            $weights = json_decode((string) $contact['recommended_weights'], true);
            if (is_array($weights)) {
                try {
                    return self::validateWeights($weights);
                } catch (InvalidArgumentException $e) {
                    // Recompute below.
                }
            }
        }

        $metadata = $this->decodeJson($contact['score_metadata_json'] ?? null);
        $availability = $metadata['component_availability'] ?? [
            'engagement' => true,
            'ml' => $contact && $contact['ml_score'] !== null,
            'ai' => !empty($contact['ai_context']) || (int) ($contact['ai_score'] ?? 0) > 0,
        ];

        return $this->getRecommendedWeightsForAvailability($availability);
    }

    /**
     * @param array<string,bool> $availability
     * @return array<string,float>
     */
    public function getRecommendedWeightsForAvailability(array $availability): array
    {
        $hasEngagement = (bool) ($availability['engagement'] ?? true);
        $hasML = (bool) ($availability['ml'] ?? false);
        $hasAI = (bool) ($availability['ai'] ?? false);

        if ($hasEngagement && $hasML && $hasAI) {
            return self::DEFAULT_WEIGHTS;
        }
        if ($hasEngagement && $hasML) {
            return ['engagement' => 0.5, 'ml' => 0.5, 'ai' => 0.0];
        }
        if ($hasEngagement && $hasAI) {
            return ['engagement' => 0.6, 'ml' => 0.0, 'ai' => 0.4];
        }
        if ($hasML && $hasAI) {
            return ['engagement' => 0.0, 'ml' => 0.7, 'ai' => 0.3];
        }
        if ($hasML) {
            return ['engagement' => 0.0, 'ml' => 1.0, 'ai' => 0.0];
        }
        if ($hasAI) {
            return ['engagement' => 0.0, 'ml' => 0.0, 'ai' => 1.0];
        }

        return ['engagement' => 1.0, 'ml' => 0.0, 'ai' => 0.0];
    }

    /**
     * @param array<string,float> $weights
     * @param array<string,bool> $availability
     * @return array<string,float>
     */
    public function redistributeUnavailableWeights(array $weights, array $availability): array
    {
        $weights = self::validateWeights($weights);
        $available = [
            'engagement' => (bool) ($availability['engagement'] ?? true),
            'ml' => (bool) ($availability['ml'] ?? false),
            'ai' => (bool) ($availability['ai'] ?? false),
        ];

        $availableKeys = array_keys(array_filter($available));
        if (count($availableKeys) === 1) {
            return [
                'engagement' => $availableKeys[0] === 'engagement' ? 1.0 : 0.0,
                'ml' => $availableKeys[0] === 'ml' ? 1.0 : 0.0,
                'ai' => $availableKeys[0] === 'ai' ? 1.0 : 0.0,
            ];
        }

        $effective = [
            'engagement' => $available['engagement'] ? $weights['engagement'] : 0.0,
            'ml' => $available['ml'] ? $weights['ml'] : 0.0,
            'ai' => $available['ai'] ? $weights['ai'] : 0.0,
        ];

        if (!$available['ml']) {
            $target = $available['engagement'] ? 'engagement' : ($available['ai'] ? 'ai' : null);
            if ($target !== null) {
                $effective[$target] += $weights['ml'];
            }
        }

        if (!$available['ai']) {
            $target = $available['engagement'] ? 'engagement' : ($available['ml'] ? 'ml' : null);
            if ($target !== null) {
                $effective[$target] += $weights['ai'];
            }
        }

        if (!$available['engagement']) {
            $target = $available['ml'] ? 'ml' : ($available['ai'] ? 'ai' : null);
            if ($target !== null) {
                $effective[$target] += $weights['engagement'];
            }
        }

        $sum = array_sum($effective);
        if ($sum <= 0) {
            return ['engagement' => 1.0, 'ml' => 0.0, 'ai' => 0.0];
        }

        return [
            'engagement' => round($effective['engagement'] / $sum, 4),
            'ml' => round($effective['ml'] / $sum, 4),
            'ai' => round($effective['ai'] / $sum, 4),
        ];
    }

    private function calculateConsolidatedScore(int $engagementScore, ?int $mlScore, int $aiScore, array $weights): int
    {
        $weights = self::validateWeights($weights);
        $consolidated = ($this->capScore($engagementScore) * $weights['engagement'])
            + ($this->capScore((int) ($mlScore ?? 0)) * $weights['ml'])
            + ($this->capScore($aiScore) * $weights['ai']);

        return $this->capScore((int) round($consolidated));
    }

    /**
     * @return array{weights:array<string,float>, source:string, store_explicit_weights:bool}
     */
    private function resolveRequestedWeights(int $contactId, int $workspaceId, ?array $weights, array $recommendedWeights): array
    {
        if ($weights !== null) {
            return [
                'weights' => self::validateWeights($weights),
                'source' => 'provided',
                'store_explicit_weights' => true,
            ];
        }

        $contact = Database::queryOne(
            "SELECT score_weights FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        if (!empty($contact['score_weights'])) {
            $decoded = json_decode((string) $contact['score_weights'], true);
            if (is_array($decoded)) {
                try {
                    return [
                        'weights' => self::validateWeights($decoded),
                        'source' => 'contact_custom',
                        'store_explicit_weights' => true,
                    ];
                } catch (InvalidArgumentException $e) {
                    // Invalid custom weights fall back to workspace settings.
                }
            }
        }

        $config = $this->workspaceScoringConfig->getConfig($workspaceId);
        if (!empty($config['auto_use_recommended_weights'])) {
            return [
                'weights' => $recommendedWeights,
                'source' => 'workspace_recommended',
                'store_explicit_weights' => false,
            ];
        }

        return [
            'weights' => $config['default_score_weights'],
            'source' => 'workspace_default',
            'store_explicit_weights' => false,
        ];
    }

    /**
     * @param array<string,mixed> $contact
     * @return array<string,mixed>
     */
    private function buildBreakdown(array $contact): array
    {
        $metadata = $this->decodeJson($contact['score_metadata_json'] ?? null);
        $componentMetadata = $metadata['components'] ?? [];
        $effectiveWeights = $metadata['effective_weights'] ?? null;
        $requestedWeights = $metadata['requested_weights'] ?? null;

        if (!is_array($requestedWeights)) {
            if (!empty($contact['score_weights'])) {
                $requestedWeights = json_decode((string) $contact['score_weights'], true);
            }
            if (!is_array($requestedWeights)) {
                $requestedWeights = self::DEFAULT_WEIGHTS;
            }
        }

        try {
            $requestedWeights = self::validateWeights($requestedWeights);
        } catch (InvalidArgumentException $e) {
            $requestedWeights = self::DEFAULT_WEIGHTS;
        }

        if (!is_array($effectiveWeights)) {
            $availability = $metadata['component_availability'] ?? [
                'engagement' => true,
                'ml' => $contact['ml_score'] !== null,
                'ai' => !empty($contact['ai_context']) || (int) ($contact['ai_score'] ?? 0) > 0,
            ];
            $effectiveWeights = $this->redistributeUnavailableWeights($requestedWeights, $availability);
        } else {
            try {
                $effectiveWeights = self::validateWeights($effectiveWeights);
            } catch (InvalidArgumentException $e) {
                $effectiveWeights = $this->redistributeUnavailableWeights($requestedWeights, [
                    'engagement' => true,
                    'ml' => $contact['ml_score'] !== null,
                    'ai' => !empty($contact['ai_context']) || (int) ($contact['ai_score'] ?? 0) > 0,
                ]);
            }
        }

        $recommendedWeights = [];
        if (!empty($contact['recommended_weights'])) {
            $decoded = json_decode((string) $contact['recommended_weights'], true);
            if (is_array($decoded)) {
                try {
                    $recommendedWeights = self::validateWeights($decoded);
                } catch (InvalidArgumentException $e) {
                    $recommendedWeights = [];
                }
            }
        }
        if ($recommendedWeights === []) {
            $recommendedWeights = $this->getRecommendedWeightsForAvailability($metadata['component_availability'] ?? [
                'engagement' => true,
                'ml' => $contact['ml_score'] !== null,
                'ai' => !empty($contact['ai_context']) || (int) ($contact['ai_score'] ?? 0) > 0,
            ]);
        }

        $engagementScore = $this->capScore((int) ($contact['engagement_score'] ?? 0));
        $mlScore = $contact['ml_score'] === null ? null : $this->capScore((int) $contact['ml_score']);
        $aiScore = $this->capScore((int) ($contact['ai_score'] ?? 0));

        return [
            'contact_id' => (int) ($contact['id'] ?? 0),
            'consolidated_score' => $this->capScore((int) ($contact['lead_score'] ?? 0)),
            'engagement_score' => $engagementScore,
            'ml_score' => $mlScore,
            'ai_score' => $aiScore,
            'weights' => $requestedWeights,
            'effective_weights' => $effectiveWeights,
            'recommended_weights' => $recommendedWeights,
            'contributions' => [
                'engagement_contribution' => round($engagementScore * $effectiveWeights['engagement'], 1),
                'ml_contribution' => round((int) ($mlScore ?? 0) * $effectiveWeights['ml'], 1),
                'ai_contribution' => round($aiScore * $effectiveWeights['ai'], 1),
            ],
            'engagement_metadata' => $componentMetadata['engagement'] ?? [
                'score' => $engagementScore,
            ],
            'ml_metadata' => [
                'available' => (bool) ($metadata['ml_available'] ?? ($mlScore !== null)),
                'fallback_used' => (bool) ($metadata['ml_fallback_used'] ?? false),
                'confidence' => $componentMetadata['ml']['confidence'] ?? $contact['ml_score_confidence'] ?? 0.0,
                'probability' => $componentMetadata['ml']['probability'] ?? null,
                'reason' => $componentMetadata['ml']['reason'] ?? null,
            ],
            'ai_metadata' => [
                'available' => (bool) ($metadata['component_availability']['ai'] ?? (!empty($contact['ai_context']) || $aiScore > 0)),
                'breakdown' => $componentMetadata['ai']['breakdown'] ?? [],
                'confidence' => $componentMetadata['ai']['confidence'] ?? 0.0,
                'reasoning' => $componentMetadata['ai']['reasoning'] ?? null,
            ],
            'ml_details' => !empty($metadata['ml_available']) ? [
                'top_factors' => $componentMetadata['ml']['top_factors'] ?? [],
                'explanation' => $componentMetadata['ml']['explanation'] ?? null,
            ] : null,
            'score_source_metadata' => [
                'source' => $metadata['source'] ?? null,
                'last_recalculated_at' => $contact['score_recalculated_at'] ?? $metadata['last_recalculated_at'] ?? null,
                'component_availability' => $metadata['component_availability'] ?? [],
                'requested_weights_source' => $metadata['requested_weights_source'] ?? null,
                'fallback_policy' => $metadata['fallback_policy'] ?? self::FALLBACK_POLICY,
            ],
        ];
    }

    private function publishMLScoreEvents(
        int $contactId,
        int $workspaceId,
        ?float $previousMLScore,
        ?int $currentMLScore,
        array $mlResult
    ): void {
        if ($previousMLScore === null || $currentMLScore === null || empty($mlResult['available'])) {
            return;
        }

        if ($currentMLScore > $previousMLScore + 5) {
            EventBus::publish('ml.score_increased', [
                'contact_id' => $contactId,
                'workspace_id' => $workspaceId,
                'previous_score' => $previousMLScore,
                'current_score' => $currentMLScore,
                'increase' => $currentMLScore - $previousMLScore,
            ]);
        } elseif ($currentMLScore < $previousMLScore - 5) {
            EventBus::publish('ml.score_decreased', [
                'contact_id' => $contactId,
                'workspace_id' => $workspaceId,
                'previous_score' => $previousMLScore,
                'current_score' => $currentMLScore,
                'decrease' => $previousMLScore - $currentMLScore,
            ]);
        }

        foreach ([30, 50, 70, 90] as $threshold) {
            if ($previousMLScore < $threshold && $currentMLScore >= $threshold) {
                EventBus::publish('ml.score_threshold', [
                    'contact_id' => $contactId,
                    'workspace_id' => $workspaceId,
                    'score' => $currentMLScore,
                    'threshold' => $threshold,
                    'direction' => 'above',
                ]);
            } elseif ($previousMLScore >= $threshold && $currentMLScore < $threshold) {
                EventBus::publish('ml.score_threshold', [
                    'contact_id' => $contactId,
                    'workspace_id' => $workspaceId,
                    'score' => $currentMLScore,
                    'threshold' => $threshold,
                    'direction' => 'below',
                ]);
            }
        }

        if (!empty($mlResult['probability'])) {
            EventBus::publish('ml.conversion_probability', [
                'contact_id' => $contactId,
                'workspace_id' => $workspaceId,
                'probability' => $mlResult['probability'],
                'score' => $currentMLScore,
            ]);
        }
    }

    private function publishCompositeScoreEvent(
        int $contactId,
        int $workspaceId,
        int $previousScore,
        int $currentScore,
        array $componentScores,
        string $source,
        array $metadata
    ): void {
        if ($previousScore === $currentScore) {
            return;
        }

        $payload = [
            'contact_id' => $contactId,
            'workspace_id' => $workspaceId,
            'previous_score' => $previousScore,
            'current_score' => $currentScore,
            'lead_score' => $currentScore,
            'component_scores' => $componentScores,
            'source' => $source,
            'score_metadata' => $metadata,
        ];

        EventBus::publish('contact.score_changed', $payload);
        EventBus::publish('contact.updated', [
            'contact_id' => $contactId,
            'workspace_id' => $workspaceId,
            'changes' => ['lead_score' => ['old' => $previousScore, 'new' => $currentScore]],
            'source' => $source,
        ]);
    }

    private function capScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function assertActiveWorkspaceContact(int $contactId): int
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        );

        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        return $workspaceId;
    }
}
