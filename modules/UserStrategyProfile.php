<?php
/**
 * Per-user GTM strategy profile.
 *
 * Keeps each user's market approach and deal-moving strategy separate from
 * shared company identity and system readiness state.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceLanguageLevelService;

class UserStrategyProfile
{
    private const MAX_FIELD_LENGTH = 2000;
    private const TONE_PRESETS = ['professional', 'warm', 'consultative', 'direct', 'friendly'];
    private const CTA_STYLES = ['soft', 'clear', 'direct'];
    private const FORMALITY_LEVELS = ['formal', 'balanced', 'casual'];
    private const LEAN_CANVAS_FIELD_MAP = [
        'problem' => 'lean_problem',
        'customer_segments' => 'lean_customer_segments',
        'unique_value_proposition' => 'lean_unique_value_proposition',
        'solution' => 'lean_solution',
        'channels' => 'lean_channels',
        'revenue_streams' => 'lean_revenue_streams',
        'cost_structure' => 'lean_cost_structure',
        'key_metrics' => 'lean_key_metrics',
        'unfair_advantage' => 'lean_unfair_advantage',
    ];

    public function get(int $userId): ?array
    {
        $workspaceId = $this->currentWorkspaceId();
        if (!$this->hasWorkspaceColumn()) {
            return $this->getLegacy($userId);
        }

        $row = Database::queryOne(
            "SELECT id, workspace_id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
                    segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                    positioning_notes, market_view, strategy_hypothesis, draft_tone_preset, draft_voice_notes,
                    draft_cta_style, draft_formality_level, draft_reading_level,
                    lean_problem, lean_customer_segments, lean_unique_value_proposition,
                    lean_solution, lean_channels, lean_revenue_streams, lean_cost_structure,
                    lean_key_metrics, lean_unfair_advantage, created_at, updated_at
             FROM user_strategy_profiles
             WHERE user_id = ?
               AND workspace_id IN (?, 0)
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            [$userId, $workspaceId, $workspaceId]
        );

        return $row ?: null;
    }

    private function getLegacy(int $userId): ?array
    {
        $row = Database::queryOne(
            "SELECT id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
                    segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                    positioning_notes, market_view, strategy_hypothesis, draft_tone_preset, draft_voice_notes,
                    draft_cta_style, draft_formality_level, draft_reading_level,
                    lean_problem, lean_customer_segments, lean_unique_value_proposition,
                    lean_solution, lean_channels, lean_revenue_streams, lean_cost_structure,
                    lean_key_metrics, lean_unfair_advantage, created_at, updated_at
             FROM user_strategy_profiles
             WHERE user_id = ?",
            [$userId]
        );

        return $row ?: null;
    }

    public function save(int $userId, array $data): bool
    {
        $payload = [
            'target_market_focus' => $this->sanitizeText($data['target_market_focus'] ?? ''),
            'ideal_customer_profile' => $this->sanitizeText($data['ideal_customer_profile'] ?? ''),
            'offer_angle' => $this->sanitizeText($data['offer_angle'] ?? ''),
            'segment_focus' => $this->sanitizeText($data['segment_focus'] ?? ''),
            'sales_motion' => $this->sanitizeText($data['sales_motion'] ?? ''),
            'deal_movement_strategy' => $this->sanitizeText($data['deal_movement_strategy'] ?? ''),
            'outreach_posture' => $this->sanitizeText($data['outreach_posture'] ?? ''),
            'positioning_notes' => $this->sanitizeText($data['positioning_notes'] ?? ''),
            'market_view' => $this->sanitizeText($data['market_view'] ?? ''),
            'strategy_hypothesis' => $this->sanitizeText($data['strategy_hypothesis'] ?? ''),
            'draft_tone_preset' => $this->normalizeEnum($data['draft_tone_preset'] ?? '', self::TONE_PRESETS),
            'draft_voice_notes' => $this->sanitizeText($data['draft_voice_notes'] ?? ''),
            'draft_cta_style' => $this->normalizeEnum($data['draft_cta_style'] ?? '', self::CTA_STYLES),
            'draft_formality_level' => $this->normalizeEnum($data['draft_formality_level'] ?? '', self::FORMALITY_LEVELS),
            'draft_reading_level' => (new WorkspaceLanguageLevelService())->normalize($data['draft_reading_level'] ?? ''),
            'lean_problem' => $this->sanitizeText($data['lean_problem'] ?? $data['problem'] ?? ''),
            'lean_customer_segments' => $this->sanitizeText($data['lean_customer_segments'] ?? $data['customer_segments'] ?? ''),
            'lean_unique_value_proposition' => $this->sanitizeText($data['lean_unique_value_proposition'] ?? $data['unique_value_proposition'] ?? ''),
            'lean_solution' => $this->sanitizeText($data['lean_solution'] ?? $data['solution'] ?? ''),
            'lean_channels' => $this->sanitizeText($data['lean_channels'] ?? $data['channels'] ?? ''),
            'lean_revenue_streams' => $this->sanitizeText($data['lean_revenue_streams'] ?? $data['revenue_streams'] ?? ''),
            'lean_cost_structure' => $this->sanitizeText($data['lean_cost_structure'] ?? $data['cost_structure'] ?? ''),
            'lean_key_metrics' => $this->sanitizeText($data['lean_key_metrics'] ?? $data['key_metrics'] ?? ''),
            'lean_unfair_advantage' => $this->sanitizeText($data['lean_unfair_advantage'] ?? $data['unfair_advantage'] ?? ''),
        ];

        $workspaceId = $this->currentWorkspaceId();
        if (!$this->hasWorkspaceColumn()) {
            $this->saveLegacy($userId, $payload);
            return true;
        }

        $existing = Database::queryOne(
            "SELECT id FROM user_strategy_profiles WHERE user_id = ? AND workspace_id = ? LIMIT 1",
            [$userId, $workspaceId]
        );

        if ($existing) {
            Database::execute(
                "UPDATE user_strategy_profiles SET
                    target_market_focus = ?,
                    ideal_customer_profile = ?,
                    offer_angle = ?,
                    segment_focus = ?,
                    sales_motion = ?,
                    deal_movement_strategy = ?,
                    outreach_posture = ?,
                    positioning_notes = ?,
                    market_view = ?,
                    strategy_hypothesis = ?,
                    draft_tone_preset = ?,
                    draft_voice_notes = ?,
                    draft_cta_style = ?,
                    draft_formality_level = ?,
                    draft_reading_level = ?,
                    lean_problem = ?,
                    lean_customer_segments = ?,
                    lean_unique_value_proposition = ?,
                    lean_solution = ?,
                    lean_channels = ?,
                    lean_revenue_streams = ?,
                    lean_cost_structure = ?,
                    lean_key_metrics = ?,
                    lean_unfair_advantage = ?
                 WHERE id = ?",
                [
                    $payload['target_market_focus'],
                    $payload['ideal_customer_profile'],
                    $payload['offer_angle'],
                    $payload['segment_focus'],
                    $payload['sales_motion'],
                    $payload['deal_movement_strategy'],
                    $payload['outreach_posture'],
                    $payload['positioning_notes'],
                    $payload['market_view'],
                    $payload['strategy_hypothesis'],
                    $payload['draft_tone_preset'],
                    $payload['draft_voice_notes'],
                    $payload['draft_cta_style'],
                    $payload['draft_formality_level'],
                    $payload['draft_reading_level'],
                    $payload['lean_problem'],
                    $payload['lean_customer_segments'],
                    $payload['lean_unique_value_proposition'],
                    $payload['lean_solution'],
                    $payload['lean_channels'],
                    $payload['lean_revenue_streams'],
                    $payload['lean_cost_structure'],
                    $payload['lean_key_metrics'],
                    $payload['lean_unfair_advantage'],
                    (int) ($existing['id'] ?? 0),
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO user_strategy_profiles (
                    workspace_id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
                    segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                    positioning_notes, market_view, strategy_hypothesis, draft_tone_preset, draft_voice_notes,
                    draft_cta_style, draft_formality_level, draft_reading_level,
                    lean_problem, lean_customer_segments, lean_unique_value_proposition,
                    lean_solution, lean_channels, lean_revenue_streams, lean_cost_structure,
                    lean_key_metrics, lean_unfair_advantage
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    $payload['target_market_focus'],
                    $payload['ideal_customer_profile'],
                    $payload['offer_angle'],
                    $payload['segment_focus'],
                    $payload['sales_motion'],
                    $payload['deal_movement_strategy'],
                    $payload['outreach_posture'],
                    $payload['positioning_notes'],
                    $payload['market_view'],
                    $payload['strategy_hypothesis'],
                    $payload['draft_tone_preset'],
                    $payload['draft_voice_notes'],
                    $payload['draft_cta_style'],
                    $payload['draft_formality_level'],
                    $payload['draft_reading_level'],
                    $payload['lean_problem'],
                    $payload['lean_customer_segments'],
                    $payload['lean_unique_value_proposition'],
                    $payload['lean_solution'],
                    $payload['lean_channels'],
                    $payload['lean_revenue_streams'],
                    $payload['lean_cost_structure'],
                    $payload['lean_key_metrics'],
                    $payload['lean_unfair_advantage'],
                ]
            );
        }

        return true;
    }

    /**
     * @param array<string,string> $payload
     */
    private function saveLegacy(int $userId, array $payload): void
    {
        if ($this->getLegacy($userId)) {
            Database::execute(
                "UPDATE user_strategy_profiles SET
                    target_market_focus = ?,
                    ideal_customer_profile = ?,
                    offer_angle = ?,
                    segment_focus = ?,
                    sales_motion = ?,
                    deal_movement_strategy = ?,
                    outreach_posture = ?,
                    positioning_notes = ?,
                    market_view = ?,
                    strategy_hypothesis = ?,
                    draft_tone_preset = ?,
                    draft_voice_notes = ?,
                    draft_cta_style = ?,
                    draft_formality_level = ?,
                    draft_reading_level = ?,
                    lean_problem = ?,
                    lean_customer_segments = ?,
                    lean_unique_value_proposition = ?,
                    lean_solution = ?,
                    lean_channels = ?,
                    lean_revenue_streams = ?,
                    lean_cost_structure = ?,
                    lean_key_metrics = ?,
                    lean_unfair_advantage = ?
                 WHERE user_id = ?",
                [
                    $payload['target_market_focus'],
                    $payload['ideal_customer_profile'],
                    $payload['offer_angle'],
                    $payload['segment_focus'],
                    $payload['sales_motion'],
                    $payload['deal_movement_strategy'],
                    $payload['outreach_posture'],
                    $payload['positioning_notes'],
                    $payload['market_view'],
                    $payload['strategy_hypothesis'],
                    $payload['draft_tone_preset'],
                    $payload['draft_voice_notes'],
                    $payload['draft_cta_style'],
                    $payload['draft_formality_level'],
                    $payload['draft_reading_level'],
                    $payload['lean_problem'],
                    $payload['lean_customer_segments'],
                    $payload['lean_unique_value_proposition'],
                    $payload['lean_solution'],
                    $payload['lean_channels'],
                    $payload['lean_revenue_streams'],
                    $payload['lean_cost_structure'],
                    $payload['lean_key_metrics'],
                    $payload['lean_unfair_advantage'],
                    $userId,
                ]
            );
            return;
        }

        Database::execute(
            "INSERT INTO user_strategy_profiles (
                user_id, target_market_focus, ideal_customer_profile, offer_angle,
                segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                positioning_notes, market_view, strategy_hypothesis, draft_tone_preset, draft_voice_notes,
                draft_cta_style, draft_formality_level, draft_reading_level,
                lean_problem, lean_customer_segments, lean_unique_value_proposition,
                lean_solution, lean_channels, lean_revenue_streams, lean_cost_structure,
                lean_key_metrics, lean_unfair_advantage
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $payload['target_market_focus'],
                $payload['ideal_customer_profile'],
                $payload['offer_angle'],
                $payload['segment_focus'],
                $payload['sales_motion'],
                $payload['deal_movement_strategy'],
                $payload['outreach_posture'],
                $payload['positioning_notes'],
                $payload['market_view'],
                $payload['strategy_hypothesis'],
                $payload['draft_tone_preset'],
                $payload['draft_voice_notes'],
                $payload['draft_cta_style'],
                $payload['draft_formality_level'],
                $payload['draft_reading_level'],
                $payload['lean_problem'],
                $payload['lean_customer_segments'],
                $payload['lean_unique_value_proposition'],
                $payload['lean_solution'],
                $payload['lean_channels'],
                $payload['lean_revenue_streams'],
                $payload['lean_cost_structure'],
                $payload['lean_key_metrics'],
                $payload['lean_unfair_advantage'],
            ]
        );
    }

    public function getContextForPrompt(int $userId): string
    {
        $strategy = $this->get($userId);
        if (!$strategy) {
            return '';
        }

        $lines = [];
        if (!empty(trim((string) ($strategy['target_market_focus'] ?? '')))) {
            $lines[] = 'Target market focus: ' . trim((string) $strategy['target_market_focus']);
        }
        if (!empty(trim((string) ($strategy['ideal_customer_profile'] ?? '')))) {
            $lines[] = 'Ideal customer profile: ' . trim((string) $strategy['ideal_customer_profile']);
        }
        if (!empty(trim((string) ($strategy['offer_angle'] ?? '')))) {
            $lines[] = 'Offer angle: ' . trim((string) $strategy['offer_angle']);
        }
        if (!empty(trim((string) ($strategy['segment_focus'] ?? '')))) {
            $lines[] = 'Segment focus: ' . trim((string) $strategy['segment_focus']);
        }
        if (!empty(trim((string) ($strategy['sales_motion'] ?? '')))) {
            $lines[] = 'Sales motion: ' . trim((string) $strategy['sales_motion']);
        }
        if (!empty(trim((string) ($strategy['deal_movement_strategy'] ?? '')))) {
            $lines[] = 'Deal movement strategy: ' . trim((string) $strategy['deal_movement_strategy']);
        }
        if (!empty(trim((string) ($strategy['outreach_posture'] ?? '')))) {
            $lines[] = 'Outreach posture: ' . trim((string) $strategy['outreach_posture']);
        }
        if (!empty(trim((string) ($strategy['positioning_notes'] ?? '')))) {
            $lines[] = 'Positioning notes: ' . trim((string) $strategy['positioning_notes']);
        }
        if (!empty(trim((string) ($strategy['market_view'] ?? '')))) {
            $lines[] = 'Market view: ' . trim((string) $strategy['market_view']);
        }
        if (!empty(trim((string) ($strategy['strategy_hypothesis'] ?? '')))) {
            $lines[] = 'Strategy hypothesis: ' . trim((string) $strategy['strategy_hypothesis']);
        }
        if (!empty(trim((string) ($strategy['draft_tone_preset'] ?? '')))) {
            $lines[] = 'Draft tone preset: ' . trim((string) $strategy['draft_tone_preset']);
        }
        if (!empty(trim((string) ($strategy['draft_voice_notes'] ?? '')))) {
            $lines[] = 'Draft voice notes: ' . trim((string) $strategy['draft_voice_notes']);
        }
        if (!empty(trim((string) ($strategy['draft_cta_style'] ?? '')))) {
            $lines[] = 'Draft CTA style: ' . trim((string) $strategy['draft_cta_style']);
        }
        if (!empty(trim((string) ($strategy['draft_formality_level'] ?? '')))) {
            $lines[] = 'Draft formality level: ' . trim((string) $strategy['draft_formality_level']);
        }
        if (!empty(trim((string) ($strategy['draft_reading_level'] ?? '')))) {
            $languageContext = (new WorkspaceLanguageLevelService())->context($strategy['draft_reading_level']);
            $lines[] = 'Language level: ' . $languageContext['label'] . ' - ' . $languageContext['description'];
        }

        return empty($lines) ? '' : "USER GTM STRATEGY:\n" . implode("\n", $lines);
    }

    public function getLeanCanvas(int $userId): array
    {
        $strategy = $this->get($userId) ?? [];
        $canvas = [];

        foreach (self::LEAN_CANVAS_FIELD_MAP as $key => $column) {
            $canvas[$key] = trim((string) ($strategy[$column] ?? ''));
        }

        return $canvas;
    }

    public function getLeanCanvasMissingBlocks(int $userId): array
    {
        return $this->getLeanCanvasMissingBlocksFromCanvas($this->getLeanCanvas($userId));
    }

    public function getLeanCanvasCompleteness(int $userId): int
    {
        return $this->calculateLeanCanvasCompleteness($this->getLeanCanvas($userId));
    }

    public function getLeanCanvasContextForPrompt(int $userId): string
    {
        $canvas = $this->getLeanCanvas($userId);
        $lines = [];

        foreach (self::LEAN_CANVAS_FIELD_MAP as $key => $column) {
            $value = trim((string) ($canvas[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $lines[] = $this->leanCanvasLabel($key) . ': ' . $value;
        }

        $missingBlocks = $this->getLeanCanvasMissingBlocksFromCanvas($canvas);
        $completeness = $this->calculateLeanCanvasCompleteness($canvas);

        if ($lines === [] && $missingBlocks === []) {
            return '';
        }

        $context = "LEAN CANVAS:\n";
        if ($lines !== []) {
            $context .= implode("\n", $lines) . "\n";
        }
        $context .= 'Lean Canvas completeness: ' . $completeness . "%\n";
        $context .= 'Missing blocks: ' . ($missingBlocks === [] ? 'none' : implode(', ', array_map([$this, 'leanCanvasLabel'], $missingBlocks)));

        return $context;
    }

    public function getLeanCanvasStatus(int $userId): array
    {
        $profile = $this->get($userId) ?? [];
        $canvas = $this->mapLeanCanvasFromProfile($profile);
        $hasLegacyCanvas = false;
        foreach ($canvas as $value) {
            if (trim((string) $value) !== '') {
                $hasLegacyCanvas = true;
                break;
            }
        }

        $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
        $installed = false;
        if ($workspaceId > 0) {
            try {
                $installed = (new \CRM\Services\WorkspaceSkillInstallService())->isInstalled(
                    $workspaceId,
                    \CRM\Services\WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
                );
            } catch (\Throwable $e) {
                $installed = false;
            }
        }

        return [
            'enabled' => $installed || $hasLegacyCanvas,
            'completeness' => $this->calculateLeanCanvasCompleteness($canvas),
            'missing_blocks' => $this->getLeanCanvasMissingBlocksFromCanvas($canvas),
            'last_updated_at' => (string) ($profile['updated_at'] ?? ''),
            'source' => $installed ? 'workspace_skill' : ($hasLegacyCanvas ? 'legacy_lean_canvas' : ''),
        ];
    }

    private function mapLeanCanvasFromProfile(array $profile): array
    {
        $canvas = [];

        foreach (self::LEAN_CANVAS_FIELD_MAP as $key => $column) {
            $canvas[$key] = trim((string) ($profile[$column] ?? ''));
        }

        return $canvas;
    }

    private function getLeanCanvasMissingBlocksFromCanvas(array $canvas): array
    {
        $missing = [];

        foreach (array_keys(self::LEAN_CANVAS_FIELD_MAP) as $key) {
            if (trim((string) ($canvas[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function calculateLeanCanvasCompleteness(array $canvas): int
    {
        $total = count(self::LEAN_CANVAS_FIELD_MAP);
        if ($total === 0) {
            return 0;
        }

        $filled = 0;
        foreach (array_keys(self::LEAN_CANVAS_FIELD_MAP) as $key) {
            if (trim((string) ($canvas[$key] ?? '')) !== '') {
                $filled++;
            }
        }

        return (int) round(($filled / $total) * 100);
    }

    private function leanCanvasLabel(string $key): string
    {
        return match ($key) {
            'problem' => 'Problem',
            'customer_segments' => 'Customer segments',
            'unique_value_proposition' => 'Unique value proposition',
            'solution' => 'Solution',
            'channels' => 'Channels',
            'revenue_streams' => 'Revenue streams',
            'cost_structure' => 'Cost structure',
            'key_metrics' => 'Key metrics',
            'unfair_advantage' => 'Unfair advantage',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    private function currentWorkspaceId(): int
    {
        return max(0, (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0));
    }

    private function hasWorkspaceColumn(): bool
    {
        return Database::columnExists('user_strategy_profiles', 'workspace_id');
    }

    private function sanitizeText(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = trim((string) $value);
        if (strlen($trimmed) > self::MAX_FIELD_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_FIELD_LENGTH);
        }

        return $trimmed;
    }

    private function normalizeEnum(?string $value, array $allowed): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, $allowed, true) ? $normalized : '';
    }
}
