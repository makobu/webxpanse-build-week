ALTER TABLE ml_models
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_training_data
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_predictions
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_model_features
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_model_metrics
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_feature_importance
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_prediction_outcomes
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ml_model_comparisons
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE ml_models m
LEFT JOIN (
    SELECT model_id, MIN(workspace_id) AS workspace_id
    FROM (
        SELECT c.ml_model_id AS model_id, c.workspace_id
        FROM contacts c
        WHERE c.ml_model_id IS NOT NULL

        UNION ALL

        SELECT p.model_id, c.workspace_id
        FROM ml_predictions p
        INNER JOIN contacts c ON c.id = p.contact_id

        UNION ALL

        SELECT o.model_id, c.workspace_id
        FROM ml_prediction_outcomes o
        INNER JOIN contacts c ON c.id = o.contact_id
    ) model_workspaces
    WHERE model_id IS NOT NULL
    GROUP BY model_id
) ws ON ws.model_id = m.id
SET m.workspace_id = COALESCE(m.workspace_id, ws.workspace_id, @default_workspace_id)
WHERE m.workspace_id IS NULL;

UPDATE ml_training_data td
INNER JOIN contacts c ON c.id = td.contact_id
SET td.workspace_id = c.workspace_id
WHERE td.workspace_id IS NULL;

UPDATE ml_predictions p
INNER JOIN contacts c ON c.id = p.contact_id
SET p.workspace_id = c.workspace_id
WHERE p.workspace_id IS NULL;

UPDATE ml_model_features mf
INNER JOIN ml_models m ON m.id = mf.model_id
SET mf.workspace_id = m.workspace_id
WHERE mf.workspace_id IS NULL;

UPDATE ml_model_metrics mm
INNER JOIN ml_models m ON m.id = mm.model_id
SET mm.workspace_id = m.workspace_id
WHERE mm.workspace_id IS NULL;

UPDATE ml_feature_importance fi
INNER JOIN ml_models m ON m.id = fi.model_id
SET fi.workspace_id = m.workspace_id
WHERE fi.workspace_id IS NULL;

UPDATE ml_prediction_outcomes o
INNER JOIN contacts c ON c.id = o.contact_id
SET o.workspace_id = c.workspace_id
WHERE o.workspace_id IS NULL;

UPDATE ml_model_comparisons mc
INNER JOIN ml_models m ON m.id = mc.model_id_a
SET mc.workspace_id = m.workspace_id
WHERE mc.workspace_id IS NULL;

UPDATE ml_training_data
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_predictions
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_model_features
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_model_metrics
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_feature_importance
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_prediction_outcomes
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ml_model_comparisons
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE contacts c
INNER JOIN ml_models m ON m.id = c.ml_model_id
SET c.ml_model_id = NULL
WHERE c.workspace_id <> m.workspace_id;

UPDATE ml_models m
INNER JOIN (
    SELECT older.id
    FROM ml_models older
    INNER JOIN ml_models newer
        ON older.workspace_id = newer.workspace_id
       AND older.model_type = newer.model_type
       AND older.version = newer.version
       AND (
            older.trained_at < newer.trained_at
            OR (older.trained_at = newer.trained_at AND older.id < newer.id)
       )
) duplicates ON duplicates.id = m.id
SET m.version = CONCAT(m.version, '-legacy-', REPLACE(UUID(), '-', ''));

DELETE older_outcomes
FROM ml_prediction_outcomes older_outcomes
INNER JOIN ml_prediction_outcomes newer_outcomes
    ON older_outcomes.workspace_id = newer_outcomes.workspace_id
   AND older_outcomes.prediction_id = newer_outcomes.prediction_id
   AND older_outcomes.outcome_type = newer_outcomes.outcome_type
   AND (
        COALESCE(older_outcomes.outcome_date, '1970-01-01') < COALESCE(newer_outcomes.outcome_date, '1970-01-01')
        OR (
            COALESCE(older_outcomes.outcome_date, '1970-01-01') = COALESCE(newer_outcomes.outcome_date, '1970-01-01')
            AND older_outcomes.validated_at < newer_outcomes.validated_at
        )
   );

DELETE older_predictions
FROM ml_predictions older_predictions
INNER JOIN ml_predictions newer_predictions
    ON older_predictions.workspace_id = newer_predictions.workspace_id
   AND older_predictions.contact_id = newer_predictions.contact_id
   AND older_predictions.model_id = newer_predictions.model_id
   AND (
        older_predictions.cached_at < newer_predictions.cached_at
        OR (
            older_predictions.cached_at = newer_predictions.cached_at
            AND COALESCE(older_predictions.expires_at, '1970-01-01 00:00:00') < COALESCE(newer_predictions.expires_at, '1970-01-01 00:00:00')
        )
   );

ALTER TABLE ml_models
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_training_data
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_predictions
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_model_features
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_model_metrics
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_feature_importance
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_prediction_outcomes
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ml_model_comparisons
    MODIFY COLUMN workspace_id INT NOT NULL;

SET @ml_models_unique_version_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ml_models'
      AND index_name = 'unique_model_version'
);
SET @ml_models_unique_version_sql := IF(
    @ml_models_unique_version_exists > 0,
    'ALTER TABLE ml_models DROP INDEX unique_model_version',
    'SELECT 1'
);
PREPARE stmt FROM @ml_models_unique_version_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ml_predictions_unique_contact_model_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ml_predictions'
      AND index_name = 'unique_contact_model'
);
SET @ml_predictions_unique_contact_model_sql := IF(
    @ml_predictions_unique_contact_model_exists > 0,
    'ALTER TABLE ml_predictions DROP INDEX unique_contact_model',
    'SELECT 1'
);
PREPARE stmt FROM @ml_predictions_unique_contact_model_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ml_models
    ADD KEY IF NOT EXISTS idx_ml_models_workspace_type_active (workspace_id, model_type, is_active, trained_at),
    ADD UNIQUE KEY IF NOT EXISTS unique_model_workspace_version (workspace_id, model_type, version);

ALTER TABLE ml_training_data
    ADD KEY IF NOT EXISTS idx_ml_training_data_workspace_label_snapshot (workspace_id, label_type, snapshot_date);

ALTER TABLE ml_predictions
    ADD KEY IF NOT EXISTS idx_ml_predictions_workspace_contact_cached (workspace_id, contact_id, cached_at),
    ADD UNIQUE KEY IF NOT EXISTS unique_workspace_contact_model (workspace_id, contact_id, model_id);

ALTER TABLE ml_model_features
    ADD KEY IF NOT EXISTS idx_ml_model_features_workspace_model (workspace_id, model_id);

ALTER TABLE ml_model_metrics
    ADD KEY IF NOT EXISTS idx_ml_model_metrics_workspace_model_time (workspace_id, model_id, calculated_at);

ALTER TABLE ml_feature_importance
    ADD KEY IF NOT EXISTS idx_ml_feature_importance_workspace_model (workspace_id, model_id, importance_score);

ALTER TABLE ml_prediction_outcomes
    ADD KEY IF NOT EXISTS idx_ml_prediction_outcomes_workspace_type_time (workspace_id, outcome_type, validated_at),
    ADD UNIQUE KEY IF NOT EXISTS unique_workspace_prediction_outcome (workspace_id, prediction_id, outcome_type);

ALTER TABLE ml_model_comparisons
    ADD KEY IF NOT EXISTS idx_ml_model_comparisons_workspace_compared (workspace_id, compared_at);
