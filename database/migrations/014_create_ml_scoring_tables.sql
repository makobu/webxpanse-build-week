-- ML-Powered Lead Scoring System Tables
-- Migration 014: Machine Learning Model Infrastructure

-- ML Models Table - Store trained model metadata, versions, and performance metrics
CREATE TABLE IF NOT EXISTS ml_models (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_type ENUM('conversion', 'churn', 'engagement') NOT NULL,
    version VARCHAR(50) NOT NULL,
    algorithm VARCHAR(50) NOT NULL,
    model_data LONGBLOB NOT NULL,
    feature_list JSON NOT NULL,
    hyperparameters JSON,
    is_active BOOLEAN DEFAULT FALSE,
    accuracy DECIMAL(5,4),
    precision_score DECIMAL(5,4),
    recall_score DECIMAL(5,4),
    f1_score DECIMAL(5,4),
    auc_roc DECIMAL(5,4),
    log_loss DECIMAL(10,6),
    training_samples INT,
    validation_samples INT,
    test_samples INT,
    trained_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    trained_by INT,
    notes TEXT,
    INDEX idx_model_type (model_type),
    INDEX idx_is_active (is_active),
    INDEX idx_version (version),
    INDEX idx_trained_at (trained_at),
    FOREIGN KEY (trained_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_model_version (model_type, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Training Data Table - Store prepared training datasets
CREATE TABLE IF NOT EXISTS ml_training_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    features JSON NOT NULL,
    label BOOLEAN NOT NULL,
    label_type ENUM('conversion', 'churn', 'engagement') NOT NULL,
    snapshot_date DATE NOT NULL,
    outcome_date DATE,
    outcome_value DECIMAL(12,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_label_type (label_type),
    INDEX idx_snapshot_date (snapshot_date),
    INDEX idx_contact_id (contact_id),
    INDEX idx_label (label, label_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Predictions Table - Cache predictions with timestamps
CREATE TABLE IF NOT EXISTS ml_predictions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    model_id INT NOT NULL,
    prediction_score DECIMAL(5,2) NOT NULL,
    probability DECIMAL(5,4) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    top_factors JSON,
    feature_values JSON,
    explanation TEXT,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_contact_id (contact_id),
    INDEX idx_model_id (model_id),
    INDEX idx_cached_at (cached_at),
    INDEX idx_expires_at (expires_at),
    INDEX idx_prediction_score (prediction_score),
    UNIQUE KEY unique_contact_model (contact_id, model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Model Features Table - Track which features each model uses
CREATE TABLE IF NOT EXISTS ml_model_features (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_id INT NOT NULL,
    feature_name VARCHAR(100) NOT NULL,
    feature_type ENUM('behavioral', 'engagement', 'deal', 'temporal', 'demographic', 'enrichment') NOT NULL,
    importance_score DECIMAL(10,6),
    is_used BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_model_id (model_id),
    INDEX idx_feature_name (feature_name),
    INDEX idx_importance (importance_score DESC),
    UNIQUE KEY unique_model_feature (model_id, feature_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Model Metrics Table - Store evaluation metrics over time
CREATE TABLE IF NOT EXISTS ml_model_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_id INT NOT NULL,
    metric_name VARCHAR(50) NOT NULL,
    metric_value DECIMAL(10,4) NOT NULL,
    metric_type ENUM('classification', 'probability', 'business') NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_model_id (model_id),
    INDEX idx_metric_name (metric_name),
    INDEX idx_calculated_at (calculated_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Feature Importance Table - Store feature importance scores for explainability
CREATE TABLE IF NOT EXISTS ml_feature_importance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_id INT NOT NULL,
    feature_name VARCHAR(100) NOT NULL,
    importance_score DECIMAL(10,6) NOT NULL,
    contribution_positive DECIMAL(10,6),
    contribution_negative DECIMAL(10,6),
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_model_id (model_id),
    INDEX idx_importance_score (importance_score DESC),
    INDEX idx_feature_name (feature_name),
    UNIQUE KEY unique_model_feature_importance (model_id, feature_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Prediction Outcomes Table - Track actual outcomes vs predictions
CREATE TABLE IF NOT EXISTS ml_prediction_outcomes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    prediction_id INT NOT NULL,
    contact_id INT NOT NULL,
    model_id INT NOT NULL,
    predicted_probability DECIMAL(5,4) NOT NULL,
    actual_outcome BOOLEAN,
    outcome_type ENUM('conversion', 'churn', 'engagement') NOT NULL,
    outcome_date DATE,
    outcome_value DECIMAL(12,2),
    prediction_error DECIMAL(10,6),
    validated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prediction_id) REFERENCES ml_predictions(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_prediction_id (prediction_id),
    INDEX idx_contact_id (contact_id),
    INDEX idx_model_id (model_id),
    INDEX idx_outcome_type (outcome_type),
    INDEX idx_validated_at (validated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ML Model Comparison Table - Track A/B test results
CREATE TABLE IF NOT EXISTS ml_model_comparisons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_id_a INT NOT NULL,
    model_id_b INT NOT NULL,
    comparison_type ENUM('performance', 'ab_test') NOT NULL,
    metric_name VARCHAR(50) NOT NULL,
    value_a DECIMAL(10,4),
    value_b DECIMAL(10,4),
    improvement DECIMAL(10,4),
    is_significant BOOLEAN DEFAULT FALSE,
    p_value DECIMAL(10,6),
    compared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id_a) REFERENCES ml_models(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id_b) REFERENCES ml_models(id) ON DELETE CASCADE,
    INDEX idx_model_a (model_id_a),
    INDEX idx_model_b (model_id_b),
    INDEX idx_compared_at (compared_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add ML score fields to contacts table (one column at a time for better error handling)
ALTER TABLE contacts ADD COLUMN ml_score DECIMAL(5,2) NULL;

ALTER TABLE contacts ADD COLUMN ml_score_confidence DECIMAL(5,4) NULL;

ALTER TABLE contacts ADD COLUMN ml_conversion_probability DECIMAL(5,4) NULL;

ALTER TABLE contacts ADD COLUMN ml_churn_probability DECIMAL(5,4) NULL;

ALTER TABLE contacts ADD COLUMN ml_score_updated_at TIMESTAMP NULL;

ALTER TABLE contacts ADD COLUMN ml_model_id INT NULL;

-- Add indexes for ML score fields
CREATE INDEX idx_ml_score ON contacts(ml_score);

CREATE INDEX idx_ml_conversion_probability ON contacts(ml_conversion_probability);

CREATE INDEX idx_ml_churn_probability ON contacts(ml_churn_probability);

CREATE INDEX idx_ml_score_updated_at ON contacts(ml_score_updated_at);

-- Add foreign key for ml_model_id
ALTER TABLE contacts ADD CONSTRAINT fk_contacts_ml_model FOREIGN KEY (ml_model_id) REFERENCES ml_models(id) ON DELETE SET NULL;
