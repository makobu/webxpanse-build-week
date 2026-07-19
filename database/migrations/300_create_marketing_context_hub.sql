-- Marketing context hub: offers, competitors, differentiators, proof, pillars, compliance terms, and CTAs.

CREATE TABLE IF NOT EXISTS marketing_context_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    item_type ENUM('offer','competitor','differentiator','proof_point','content_pillar','compliance_term','default_cta') NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    channel VARCHAR(80) NULL,
    persona_id INT NULL,
    status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_context_workspace_type (workspace_id, item_type, status),
    INDEX idx_marketing_context_workspace_persona (workspace_id, persona_id),
    CONSTRAINT fk_marketing_context_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_context_persona FOREIGN KEY (persona_id) REFERENCES marketing_personas(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_context_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_campaign_briefs ADD COLUMN context_score INT NULL AFTER metadata_json;
