<?php
/**
 * User Preferences Module
 *
 * Manages per-user preferences including AI Coach toggle.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;

class UserPreferences
{
    private const KEY_AI_COACH_ENABLED = 'ai_coach_enabled';
    private const KEY_AI_GUIDANCE_MODE = 'ai_guidance_mode';
    private const KEY_AI_LEAN_CANVAS_MODE_ENABLED = 'ai_lean_canvas_mode_enabled';
    private const KEY_HOURS_PER_WEEK_SALES = 'hours_per_week_sales';
    private const KEY_INBOX_TRIAGE_OPT_OUT = 'inbox_triage_opt_out';
    private const KEY_AI_CONTEXT_STRICTNESS = 'ai_context_strictness';
    private const KEY_AI_ADVICE_MIN_CONFIDENCE = 'ai_advice_min_confidence';
    private const KEY_AI_ACTION_MIN_CONFIDENCE = 'ai_action_min_confidence';
    private const KEY_AI_GOAL_RELEVANCE_MIN_SCORE = 'ai_goal_relevance_min_score';
    private const KEY_AI_AUTO_TASK_COMPLETION_ENABLED = 'ai_auto_task_completion_enabled';
    private const KEY_AI_AUTO_TASK_COMPLETION_MIN_CONFIDENCE = 'ai_auto_task_completion_min_confidence';
    private const KEY_AI_MISSING_CONTEXT_BEHAVIOR = 'ai_missing_context_behavior';
    private const KEY_AI_MODE_LOCK = 'ai_mode_lock';
    private const KEY_AI_AUTONOMOUS_THRESHOLD_TUNING_ENABLED = 'ai_autonomous_threshold_tuning_enabled';
    private const KEY_AI_CALIBRATION_LAST_RUN_AT = 'ai_calibration_last_run_at';
    private const KEY_AI_CALIBRATION_MIN_SAMPLE_SIZE = 'ai_calibration_min_sample_size';
    private const KEY_AI_CALIBRATION_DAILY_CHANGE_CAP = 'ai_calibration_daily_change_cap';
    private const KEY_AI_CALIBRATION_ROLLING_CHANGE_CAP = 'ai_calibration_rolling_change_cap';
    private const KEY_AI_INCIDENT_ALERTS_ENABLED = 'ai_incident_alerts_enabled';
    private const KEY_AI_INCIDENT_CHECK_ENABLED = 'ai_incident_check_enabled';
    private const KEY_AI_INCIDENT_MEDIUM_COOLDOWN_MINUTES = 'ai_incident_medium_cooldown_minutes';
    private const KEY_AI_INCIDENT_HIGH_COOLDOWN_MINUTES = 'ai_incident_high_cooldown_minutes';
    private const KEY_AI_INCIDENT_CRITICAL_COOLDOWN_MINUTES = 'ai_incident_critical_cooldown_minutes';
    private const KEY_AUTO_ADMIN_ENABLED = 'auto_admin_enabled';
    private const KEY_CONTACTS_LAST_OPENED_AT = 'last_contacts_page_opened_at';
    private const KEY_TASKS_LAST_OPENED_AT = 'last_tasks_page_opened_at';
    private const KEY_NOTIFICATIONS_LAST_OPENED_AT = 'last_notifications_page_opened_at';
    private const DEFAULT_AI_COACH_ENABLED = true;
    private const DEFAULT_AI_GUIDANCE_MODE = '2'; // Operations Mode for existing users
    private const DEFAULT_AI_LEAN_CANVAS_MODE_ENABLED = false;

    /**
     * Get a preference value for a user
     * Returns null if table does not exist (e.g. before migration 056).
     */
    public function getPreference(int $userId, string $key): ?string
    {
        try {
            $row = Database::queryOne(
                "SELECT preference_value
                 FROM user_preferences
                 WHERE user_id = ? AND preference_key = ?
                 ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
                 LIMIT 1",
                [$userId, $key]
            );
            return $row ? $row['preference_value'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Set a preference value for a user
     * No-op if user_preferences table does not exist.
     */
    public function setPreference(int $userId, string $key, string $value): void
    {
        try {
            $value = Security::sanitizeInput($value, 'string');
            $existing = Database::query(
                "SELECT id
                 FROM user_preferences
                 WHERE user_id = ? AND preference_key = ?
                 ORDER BY COALESCE(updated_at, created_at) DESC, id DESC",
                [$userId, $key]
            );

            if (!empty($existing)) {
                $keepId = (int) ($existing[0]['id'] ?? 0);
                Database::execute(
                    "UPDATE user_preferences
                     SET preference_value = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$value, $keepId]
                );

                $duplicateIds = array_values(array_filter(array_map(
                    static fn(array $row): int => (int) ($row['id'] ?? 0),
                    array_slice($existing, 1)
                )));

                if (!empty($duplicateIds)) {
                    $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
                    Database::execute(
                        "DELETE FROM user_preferences WHERE id IN ($placeholders)",
                        $duplicateIds
                    );
                }
            } else {
                Database::execute(
                    "INSERT INTO user_preferences (user_id, preference_key, preference_value)
                     VALUES (?, ?, ?)",
                    [$userId, $key, $value]
                );
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }
    }

    public function getContactsLastOpenedAt(int $userId): ?string
    {
        return $this->getPreference($userId, self::KEY_CONTACTS_LAST_OPENED_AT);
    }

    public function markContactsPageOpened(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $openedAt = $this->currentDatabaseTimestamp();

        if ($openedAt !== '') {
            $this->setPreference($userId, self::KEY_CONTACTS_LAST_OPENED_AT, $openedAt);
        }
    }

    public function getTasksLastOpenedAt(int $userId): ?string
    {
        return $this->getPreference($userId, self::KEY_TASKS_LAST_OPENED_AT);
    }

    public function markTasksPageOpened(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $openedAt = $this->currentDatabaseTimestamp();

        if ($openedAt !== '') {
            $this->setPreference($userId, self::KEY_TASKS_LAST_OPENED_AT, $openedAt);
        }
    }

    public function getNotificationsLastOpenedAt(int $userId): ?string
    {
        return $this->getPreference($userId, self::KEY_NOTIFICATIONS_LAST_OPENED_AT);
    }

    public function markNotificationsPageOpened(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $openedAt = $this->currentDatabaseTimestamp();

        if ($openedAt !== '') {
            $this->setPreference($userId, self::KEY_NOTIFICATIONS_LAST_OPENED_AT, $openedAt);
        }
    }

    private function currentDatabaseTimestamp(): string
    {
        try {
            $row = Database::queryOne("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS opened_at");
            return (string) ($row['opened_at'] ?? '');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    /**
     * Check if AI Coach is enabled for the user (default: enabled)
     */
    public function isAICoachEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_COACH_ENABLED);
        if ($value === null) {
            return self::DEFAULT_AI_COACH_ENABLED;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Set AI Coach enabled state
     */
    public function setAICoachEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_COACH_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Get AI guidance mode: '1' (Foundation), '2' (Operations), '3' (Guardian), 'auto'
     * Default for new users (created in last 30 days): '1' (Foundation).
     * Default for existing users: '2' (Operations).
     */
    public function getAIGuidanceMode(int $userId): string
    {
        $value = $this->getPreference($userId, self::KEY_AI_GUIDANCE_MODE);
        if ($value !== null && in_array($value, ['1', '2', '3', 'auto'], true)) {
            return $value;
        }
        // Never set: default new users to Foundation, existing to Operations
        try {
            $user = Database::queryOne("SELECT created_at FROM users WHERE id = ?", [$userId]);
            if ($user && strtotime($user['created_at'] ?? '') >= strtotime('-30 days')) {
                return '1';
            }
        } catch (\Throwable $e) {
            // Fall through
        }
        return self::DEFAULT_AI_GUIDANCE_MODE;
    }

    /**
     * Set AI guidance mode
     */
    public function setAIGuidanceMode(int $userId, string $mode): void
    {
        $allowed = ['1', '2', '3', 'auto'];
        if (in_array($mode, $allowed, true)) {
            $this->setPreference($userId, self::KEY_AI_GUIDANCE_MODE, $mode);
        }
    }

    /**
     * Check if Lean Canvas mode is enabled for the user (default: disabled)
     */
    public function isLeanCanvasModeEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_LEAN_CANVAS_MODE_ENABLED);
        if ($value === null) {
            return self::DEFAULT_AI_LEAN_CANVAS_MODE_ENABLED;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Set Lean Canvas mode enabled state
     */
    public function setLeanCanvasModeEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_LEAN_CANVAS_MODE_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Get effective AI guidance mode (resolves 'auto' to '1' or '2' based on business maturity).
     * For explicit modes ('1', '2', '3'), returns as-is.
     */
    public function getEffectiveAIGuidanceMode(int $userId): string
    {
        $mode = $this->getAIGuidanceMode($userId);
        if ($mode !== 'auto') {
            return $mode;
        }
        if (class_exists(\CRM\Modules\AIGuidanceEvaluator::class)) {
            try {
                $evaluator = new AIGuidanceEvaluator();
                return $evaluator->evaluateMode($userId);
            } catch (\Throwable $e) {
                // Fall through to default
            }
        }
        return '1'; // Default to Foundation when auto or evaluator unavailable
    }

    /**
     * Get hours per week dedicated to sales/marketing (1-80).
     * Stored as range: '1-5', '6-10', '11-20', '21+'
     */
    public function getHoursPerWeekSales(int $userId): ?string
    {
        return $this->getPreference($userId, self::KEY_HOURS_PER_WEEK_SALES);
    }

    /**
     * Set hours per week for sales/marketing
     */
    public function setHoursPerWeekSales(int $userId, string $value): void
    {
        $allowed = ['1-5', '6-10', '11-20', '21+'];
        if (in_array($value, $allowed, true)) {
            $this->setPreference($userId, self::KEY_HOURS_PER_WEEK_SALES, $value);
        }
    }

    public function isInboxTriageOptOut(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_INBOX_TRIAGE_OPT_OUT);
        if ($value === null) {
            return false;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setInboxTriageOptOut(int $userId, bool $optOut): void
    {
        $this->setPreference($userId, self::KEY_INBOX_TRIAGE_OPT_OUT, $optOut ? '1' : '0');
    }

    public function getAIContextStrictness(int $userId): ?string
    {
        $value = $this->getPreference($userId, self::KEY_AI_CONTEXT_STRICTNESS);
        return in_array($value, ['balanced', 'strict', 'maximum'], true) ? $value : 'strict';
    }

    public function setAIContextStrictness(int $userId, string $value): void
    {
        if (in_array($value, ['balanced', 'strict', 'maximum'], true)) {
            $this->setPreference($userId, self::KEY_AI_CONTEXT_STRICTNESS, $value);
        }
    }

    public function getAIAdviceMinConfidence(int $userId): ?float
    {
        $value = $this->getPreference($userId, self::KEY_AI_ADVICE_MIN_CONFIDENCE);
        return $value !== null ? (float) $value : 0.88;
    }

    public function setAIAdviceMinConfidence(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_ADVICE_MIN_CONFIDENCE, (string) max(0, min(1, $value)));
    }

    public function getAIActionMinConfidence(int $userId): ?float
    {
        $value = $this->getPreference($userId, self::KEY_AI_ACTION_MIN_CONFIDENCE);
        return $value !== null ? (float) $value : 0.92;
    }

    public function setAIActionMinConfidence(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_ACTION_MIN_CONFIDENCE, (string) max(0, min(1, $value)));
    }

    public function getAIGoalRelevanceMinScore(int $userId): ?float
    {
        $value = $this->getPreference($userId, self::KEY_AI_GOAL_RELEVANCE_MIN_SCORE);
        return $value !== null ? (float) $value : 0.70;
    }

    public function setAIGoalRelevanceMinScore(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_GOAL_RELEVANCE_MIN_SCORE, (string) max(0, min(1, $value)));
    }

    public function isAIAutoTaskCompletionEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_AUTO_TASK_COMPLETION_ENABLED);
        if ($value === null) {
            return true;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setAIAutoTaskCompletionEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_AUTO_TASK_COMPLETION_ENABLED, $enabled ? '1' : '0');
    }

    public function getAIAutoTaskCompletionMinConfidence(int $userId): ?float
    {
        $value = $this->getPreference($userId, self::KEY_AI_AUTO_TASK_COMPLETION_MIN_CONFIDENCE);
        return $value !== null ? (float) $value : 0.95;
    }

    public function setAIAutoTaskCompletionMinConfidence(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_AUTO_TASK_COMPLETION_MIN_CONFIDENCE, (string) max(0, min(1, $value)));
    }

    public function isAIIncidentAlertsEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_INCIDENT_ALERTS_ENABLED);
        if ($value === null) {
            return true;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setAIIncidentAlertsEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_INCIDENT_ALERTS_ENABLED, $enabled ? '1' : '0');
    }

    public function isAIIncidentCheckEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_INCIDENT_CHECK_ENABLED);
        if ($value === null) {
            return true;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setAIIncidentCheckEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_INCIDENT_CHECK_ENABLED, $enabled ? '1' : '0');
    }

    public function getAIIncidentMediumCooldownMinutes(int $userId): int
    {
        $value = $this->getPreference($userId, self::KEY_AI_INCIDENT_MEDIUM_COOLDOWN_MINUTES);
        return $value !== null ? max(5, (int) $value) : 360;
    }

    public function setAIIncidentMediumCooldownMinutes(int $userId, int $minutes): void
    {
        $this->setPreference($userId, self::KEY_AI_INCIDENT_MEDIUM_COOLDOWN_MINUTES, (string) max(5, $minutes));
    }

    public function getAIIncidentHighCooldownMinutes(int $userId): int
    {
        $value = $this->getPreference($userId, self::KEY_AI_INCIDENT_HIGH_COOLDOWN_MINUTES);
        return $value !== null ? max(5, (int) $value) : 240;
    }

    public function setAIIncidentHighCooldownMinutes(int $userId, int $minutes): void
    {
        $this->setPreference($userId, self::KEY_AI_INCIDENT_HIGH_COOLDOWN_MINUTES, (string) max(5, $minutes));
    }

    public function getAIIncidentCriticalCooldownMinutes(int $userId): int
    {
        $value = $this->getPreference($userId, self::KEY_AI_INCIDENT_CRITICAL_COOLDOWN_MINUTES);
        return $value !== null ? max(5, (int) $value) : 120;
    }

    public function setAIIncidentCriticalCooldownMinutes(int $userId, int $minutes): void
    {
        $this->setPreference($userId, self::KEY_AI_INCIDENT_CRITICAL_COOLDOWN_MINUTES, (string) max(5, $minutes));
    }

    public function getAIMissingContextBehavior(int $userId): ?string
    {
        $value = $this->getPreference($userId, self::KEY_AI_MISSING_CONTEXT_BEHAVIOR);
        return in_array($value, ['warn', 'degrade', 'block_high_risk'], true) ? $value : 'warn';
    }

    public function setAIMissingContextBehavior(int $userId, string $value): void
    {
        if (in_array($value, ['warn', 'degrade', 'block_high_risk'], true)) {
            $this->setPreference($userId, self::KEY_AI_MISSING_CONTEXT_BEHAVIOR, $value);
        }
    }

    public function getAIModeLock(int $userId): ?string
    {
        $value = $this->getPreference($userId, self::KEY_AI_MODE_LOCK);
        return in_array($value, ['auto', 'foundation', 'operations', 'guardian'], true) ? $value : 'auto';
    }

    public function setAIModeLock(int $userId, string $value): void
    {
        if (in_array($value, ['auto', 'foundation', 'operations', 'guardian'], true)) {
            $this->setPreference($userId, self::KEY_AI_MODE_LOCK, $value);
        }
    }

    public function isAIAutonomousThresholdTuningEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AI_AUTONOMOUS_THRESHOLD_TUNING_ENABLED);
        if ($value === null) {
            return true;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setAIAutonomousThresholdTuningEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AI_AUTONOMOUS_THRESHOLD_TUNING_ENABLED, $enabled ? '1' : '0');
    }

    public function getAICalibrationLastRunAt(int $userId): ?string
    {
        return $this->getPreference($userId, self::KEY_AI_CALIBRATION_LAST_RUN_AT);
    }

    public function setAICalibrationLastRunAt(int $userId, string $value): void
    {
        $this->setPreference($userId, self::KEY_AI_CALIBRATION_LAST_RUN_AT, $value);
    }

    public function getAICalibrationMinSampleSize(int $userId): int
    {
        return (int) ($this->getPreference($userId, self::KEY_AI_CALIBRATION_MIN_SAMPLE_SIZE) ?? 30);
    }

    public function setAICalibrationMinSampleSize(int $userId, int $value): void
    {
        $this->setPreference($userId, self::KEY_AI_CALIBRATION_MIN_SAMPLE_SIZE, (string) max(1, $value));
    }

    public function getAICalibrationDailyChangeCap(int $userId): float
    {
        return (float) ($this->getPreference($userId, self::KEY_AI_CALIBRATION_DAILY_CHANGE_CAP) ?? 0.02);
    }

    public function setAICalibrationDailyChangeCap(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_CALIBRATION_DAILY_CHANGE_CAP, (string) max(0, min(1, $value)));
    }

    public function getAICalibrationRollingChangeCap(int $userId): float
    {
        return (float) ($this->getPreference($userId, self::KEY_AI_CALIBRATION_ROLLING_CHANGE_CAP) ?? 0.05);
    }

    public function setAICalibrationRollingChangeCap(int $userId, float $value): void
    {
        $this->setPreference($userId, self::KEY_AI_CALIBRATION_ROLLING_CHANGE_CAP, (string) max(0, min(1, $value)));
    }

    public function isAutoAdminEnabled(int $userId): bool
    {
        $value = $this->getPreference($userId, self::KEY_AUTO_ADMIN_ENABLED);
        if ($value === null) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public function setAutoAdminEnabled(int $userId, bool $enabled): void
    {
        $this->setPreference($userId, self::KEY_AUTO_ADMIN_ENABLED, $enabled ? '1' : '0');
    }
}
