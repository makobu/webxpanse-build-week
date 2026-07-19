-- Connect Campaign Kit artifacts to controlled distribution and measurable outcomes.

ALTER TABLE marketing_generation_runs
    ADD COLUMN outcome_json JSON NULL AFTER quality_json,
    ADD COLUMN outcome_refreshed_at DATETIME NULL AFTER outcome_json;

ALTER TABLE marketing_generation_artifacts
    ADD COLUMN distribution_post_id INT NULL AFTER visual_request_id,
    ADD COLUMN utm_link_id INT NULL AFTER distribution_post_id,
    ADD COLUMN channel_media_kit_id INT NULL AFTER utm_link_id,
    ADD COLUMN handoff_status ENUM('not_prepared','prepared','exported','scheduled','published') NOT NULL DEFAULT 'not_prepared' AFTER channel_media_kit_id,
    ADD COLUMN handoff_json JSON NULL AFTER handoff_status,
    ADD COLUMN last_outcome_json JSON NULL AFTER handoff_json,
    ADD COLUMN last_outcome_at DATETIME NULL AFTER last_outcome_json,
    ADD KEY idx_marketing_generation_artifact_distribution (workspace_id, distribution_post_id),
    ADD KEY idx_marketing_generation_artifact_utm (workspace_id, utm_link_id),
    ADD KEY idx_marketing_generation_artifact_media_kit (workspace_id, channel_media_kit_id),
    ADD KEY idx_marketing_generation_artifact_handoff (workspace_id, handoff_status, updated_at);

CREATE TABLE IF NOT EXISTS marketing_generation_learning_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    generation_run_id BIGINT UNSIGNED NOT NULL,
    generation_artifact_id BIGINT UNSIGNED NOT NULL,
    content_item_id INT NULL,
    distribution_post_id INT NULL,
    utm_link_id INT NULL,
    signal_type ENUM('insufficient_data','emerging','winning','underperforming','revenue_proven') NOT NULL DEFAULT 'insufficient_data',
    evidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    metrics_json JSON NULL,
    recommendation VARCHAR(1000) NULL,
    first_observed_at DATETIME NULL,
    last_observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_marketing_generation_learning_artifact (workspace_id, generation_artifact_id),
    KEY idx_marketing_generation_learning_run (workspace_id, generation_run_id, signal_type),
    KEY idx_marketing_generation_learning_content (workspace_id, content_item_id, last_observed_at),
    CONSTRAINT fk_marketing_generation_learning_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_generation_learning_run FOREIGN KEY (generation_run_id) REFERENCES marketing_generation_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_generation_learning_artifact FOREIGN KEY (generation_artifact_id) REFERENCES marketing_generation_artifacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
