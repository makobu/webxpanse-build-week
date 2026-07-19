-- Marketing Phase 57: relationship graph for cross-system marketing records.

CREATE TABLE IF NOT EXISTS marketing_relationship_graph_edges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    source_type ENUM('campaign','campaign_brief','content_item','landing_page','media_file','persona','context_item','audience_segment','journey','distribution_post','utm_link','email_run','channel_export','asset','form','task','custom') NOT NULL,
    source_id INT NOT NULL,
    target_type ENUM('campaign','campaign_brief','content_item','landing_page','media_file','persona','context_item','audience_segment','journey','distribution_post','utm_link','email_run','channel_export','asset','form','task','custom') NOT NULL,
    target_id INT NOT NULL,
    relationship_type ENUM('uses','depends_on','supports','converts_to','distributed_as','tracks','targets','requires','references','produces','custom') NOT NULL DEFAULT 'references',
    relationship_status ENUM('active','missing','orphaned','archived') NOT NULL DEFAULT 'active',
    label VARCHAR(190) NOT NULL,
    source_title VARCHAR(190) NULL,
    target_title VARCHAR(190) NULL,
    evidence_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_relationship_uuid (uuid),
    UNIQUE KEY uniq_marketing_relationship_edge (workspace_id, source_type, source_id, target_type, target_id, relationship_type),
    KEY idx_marketing_relationship_workspace_source (workspace_id, source_type, source_id, relationship_status),
    KEY idx_marketing_relationship_workspace_target (workspace_id, target_type, target_id, relationship_status),
    KEY idx_marketing_relationship_workspace_type (workspace_id, relationship_type, relationship_status),
    CONSTRAINT fk_marketing_relationship_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_relationship_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'marketing_relationships',
    'Marketing Relationship Graph page guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
